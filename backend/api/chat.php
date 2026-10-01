<?php
declare(strict_types=1);

/**
 * @file chat.php
 * @brief Fråge-API för den källbaserade guiden.
 *
 * Endpointen söker i importerade källor och använder LLM om API-nyckel finns.
 * Om LLM saknas, är rate-limited eller misslyckas får användaren ett enklare
 * svar direkt från databasen.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/embedding_lib.php';

try {
    require_post();
    $input = json_input();
    $question = trim((string) ($input['question'] ?? ''));
    if ($question === '') {
        respond(['error' => 'Frågan får inte vara tom.'], 422);
    }
    $caseId = trim((string) ($input['case_id'] ?? ''));
    $profile = classify_question($question);

    if (!($profile['in_scope'] ?? true)) {
        respond([
            'mode' => 'guardrail',
            'answer' => out_of_scope_answer($question),
            'sources' => [],
        ]);
    }

    $config = require __DIR__ . '/config.php';
    $chunks = retrieve_chunks($question, $config, $profile);
    $caseContext = $caseId !== '' ? retrieve_case_context($caseId) : '';
    $sources = array_map(static function (array $row): array {
        return [
            'title' => $row['title'],
            'service' => $row['service'],
            'source_url' => $row['source_url'],
        ];
    }, $chunks);

    if (!$chunks) {
        respond([
            'mode' => 'database',
            'answer' => 'Jag hittar inget stöd i den importerade kunskapsbasen. Importera fler DIGG-källor och försök igen.',
            'sources' => [],
        ]);
    }

    if (!empty($config['llm']['api_key'])) {
        try {
            respond([
                'mode' => 'llm',
                'answer' => call_llm($question, $chunks, $config['llm'], $caseContext, $profile),
                'sources' => $sources,
            ]);
        } catch (Throwable $error) {
            respond([
                'mode' => 'database',
                'answer' => fallback_answer($question, $chunks, $caseContext, $profile),
                'warning' => friendly_llm_warning($error),
                'sources' => $sources,
            ]);
        }
    }

    respond([
        'mode' => 'database',
        'answer' => fallback_answer($question, $chunks, $caseContext, $profile),
        'warning' => 'LLM är inte konfigurerad, så svaret bygger direkt på importerade källor.',
        'sources' => $sources,
    ]);
} catch (Throwable $error) {
    respond([
        'error' => friendly_setup_error($error),
        'detail' => $error->getMessage(),
        'health_check' => 'Öppna backend/api/health.php för att kontrollera PHP, databas, tabeller och LLM-konfiguration.',
    ], 500);
}

/**
 * Avgör om frågan hör hemma i prototypens område och vilket tjänstespår
 * källsökningen ska prioritera.
 *
 * @return array<string, mixed>
 */
function classify_question(string $question): array
{
    $mentionsSdk = (bool) preg_match(
        '/\bsdk\b|s[äa]ker digital kommunikation|o2o|organisation till organisation|funktionsadress|accesspunkt|meddelandesystem|deltagarorganisation|x\.?509|pem|certifikat/iu',
        $question
    );
    $mentionsMina = (bool) preg_match(
        '/mina meddelanden|digital post|brevl[åa]d|f[öo]rs[äa]ndelse|recipient|message service|auktorisationssystem/iu',
        $question
    );
    $mentionsDataSpecific = (bool) preg_match(
        '/openapi|api-dokumentation|datadelning|nationella grunddata|dataportal|rest api|rest-api|rest api-profil|rest-api-profil|rap-lp|raplp|f[äa]ltmapp|begreppsmodell|informationsmodell/iu',
        $question
    );
    $mentionsPublicApiContext = (bool) preg_match(
        '/\bapi\b/i',
        $question
    ) && (bool) preg_match(
        '/kommun|digg|anslut|leverant[öo]r|k[äa]llsystem|m[åa]lsystem|integration|underlag|offentlig|datadelning|grunddata/i',
        $question
    );
    $mentionsData = $mentionsDataSpecific || $mentionsPublicApiContext;
    $mentionsTool = (bool) preg_match(
        '/anslutningsagent|systemkoppling|dry-run|poc|verktyg|specifik anslutningsguide|kommunens behov:|checklistpunkten|steget "/iu',
        $question
    );
    $outOfScopeCitizenService = (bool) preg_match(
        '/bygglov|v[åa]rdcentral|boka tid|l[äa]kartid|tandv[åa]rd|pass\b|f[öo]rskoleplats|skolmat|soph[äa]mtning|bibliotek|parkeringstillst[åa]nd/iu',
        $question
    );
    $outOfScopeGeneral = (bool) preg_match(
        '/huvudstad|frankrike|pizza|pizzadeg|recept|matlag|v[äa]der|sport|fotboll|aktie|bitcoin|online-spel|spel\b|leaderboard|minecraft|film|musik|l[åa]t|s[åa]ng|dikt|poem|haiku|rim|sk[äa]mt|[öo]vers[äa]tt|software development kit|javascript|java script|python|npm|react|node\.?js|programmering|kodbibliotek/iu',
        $question
    );
    $citizenTransaction = (bool) preg_match(
        '/ans[öo]k|ans[öo]kan|ans[öo]kningar|ta emot|boka|best[äa]ll|f[åa]r jag|hur (f[åa]r|g[öo]r) jag/iu',
        $question
    );
    $domainAnchor = $mentionsSdk || $mentionsMina || $mentionsData || $mentionsTool
        || (bool) preg_match('/digg|anslut|leverant[öo]r|kommunikation|kommunens underlag|qa|testdata|integration/iu', $question);

    if (($outOfScopeCitizenService && (!$domainAnchor || $citizenTransaction))
        || $outOfScopeGeneral
        || (!$domainAnchor && !$mentionsData)) {
        return [
            'in_scope' => false,
            'service' => 'out_of_scope',
            'allowed_services' => [],
        ];
    }

    $service = 'general';
    $allowedServices = [];
    if ($mentionsSdk && !$mentionsMina) {
        $service = 'sdk';
        $allowedServices = ['SDK'];
    } elseif ($mentionsMina && !$mentionsSdk) {
        $service = 'mina_meddelanden';
        $allowedServices = ['Mina meddelanden'];
    } elseif ($mentionsSdk && $mentionsMina) {
        $service = 'mixed';
        $allowedServices = ['SDK', 'Mina meddelanden'];
    } elseif ($mentionsData) {
        $service = 'data_api';
        $allowedServices = ['Dataportal', 'Nationella grunddata', 'diggsweden GitHub', 'DIGG'];
    } elseif ($mentionsTool) {
        $service = 'poc';
        $allowedServices = ['PoC guide', 'SDK', 'Mina meddelanden', 'Dataportal', 'Nationella grunddata'];
    }

    if (preg_match('/checklistpunkten/i', $question)
        && !preg_match('/checklistpunkten "[^"]*(mina meddelanden|digital post|brevl[åa]d)/iu', $question)) {
        $service = 'sdk';
        $allowedServices = ['SDK'];
    }

    return [
        'in_scope' => true,
        'service' => $service,
        'allowed_services' => $allowedServices,
    ];
}

function out_of_scope_answer(string $question): string
{
    if (preg_match('/software development kit|javascript|java script|python|npm|react|node\.?js|programmering|kodbibliotek/iu', $question)) {
        return implode("\n", [
            'Det ligger utanför mitt område.',
            '- I den här prototypen betyder SDK Säker digital kommunikation, inte Software Development Kit.',
            '- Jag kan hjälpa till med SDK-anslutning, Mina meddelanden, DIGG-anslutning, API-underlag, fältmappning och kommunens anslutningsresa.',
            '- Om du menar Säker digital kommunikation: skriv frågan som en anslutningsfråga, till exempel vilken leverantör, vilket källsystem eller vilket certifikat/O2O-underlag kommunen behöver.',
        ]);
    }

    if (preg_match('/bygglov/iu', $question)) {
        return implode("\n", [
            'Det ligger utanför mitt område.',
            '- Jag kan hjälpa till med SDK, Mina meddelanden, DIGG-anslutning, API-underlag och kommunens anslutningsarbete.',
            '- För bygglov ska invånaren använda kommunens egen bygglovssida eller e-tjänst, eller kontakta kommunens bygglovshandläggare.',
            '- Om frågan egentligen gäller hur kommunen ska skicka bygglovsbeslut eller meddelanden säkert via SDK eller digital post, skriv det så hjälper jag med anslutningsdelen.',
        ]);
    }

    if (preg_match('/tandv[åa]rd/iu', $question)) {
        return implode("\n", [
            'Det ligger utanför mitt område.',
            '- Jag kan hjälpa till med SDK, Mina meddelanden, DIGG-anslutning, API-underlag och kommunens anslutningsarbete.',
            '- För att boka eller hantera tandvård ska användaren gå via tandvårdens eller regionens egen bokningstjänst.',
            '- Om frågan gäller en teknisk integration mellan kommunala system och en vårdaktör kan du beskriva integrationsbehovet, så hjälper jag med anslutningsdelen.',
        ]);
    }

    if (preg_match('/v[åa]rdcentral|boka tid|l[äa]kartid/iu', $question)) {
        return implode("\n", [
            'Det ligger utanför mitt område.',
            '- Jag kan hjälpa till med SDK, Mina meddelanden, DIGG-anslutning, API-underlag och kommunens anslutningsarbete.',
            '- För att boka tid på vårdcentral ska användaren gå via regionens eller vårdcentralens bokningstjänst, till exempel 1177 där det används.',
            '- Om frågan gäller en teknisk integration mellan kommunala system och en vårdaktör kan du beskriva integrationsbehovet, så hjälper jag med anslutningsdelen.',
        ]);
    }

    return implode("\n", [
        'Det ligger utanför mitt område.',
        '- Jag svarar främst på frågor om SDK, Mina meddelanden, DIGG-anslutning, API-underlag, fältmappning och kommunens anslutningsresa.',
        '- Ställ gärna om frågan så att den handlar om anslutningen, underlaget eller leverantörens tekniska ansvar.',
    ]);
}

/**
 * Hämtar relevanta källrader från `reference_documents`.
 *
 * Först används MySQL FULLTEXT. Om det inte fungerar används LIKE-sökning.
 *
 * @return array<int, array<string, mixed>>
 */
function retrieve_chunks(string $question, array $config, array $profile = []): array
{
    $limit = (int) ($config['rag']['max_chunks'] ?? 5);
    $allowedServices = array_values(array_filter((array) ($profile['allowed_services'] ?? [])));
    $embeddingRows = embedding_retrieve_chunks($question, $config, max($limit * 4, 16));
    $embeddingRows = filter_chunks_by_services($embeddingRows, $allowedServices);

    $pdo = db();
    $terms = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $question) ?: '';
    $words = array_values(array_filter(preg_split('/\s+/u', $terms) ?: [], static function (string $word): bool {
        return text_length($word) > 2;
    }));
    $words = array_slice(array_unique($words), 0, 8);
    $boolean = implode(' ', array_map(static function (string $word): string {
        return $word . '*';
    }, $words));
    $servicePlaceholders = [];
    $serviceParams = [];
    foreach ($allowedServices as $index => $service) {
        $placeholder = ':service' . $index;
        $servicePlaceholders[] = $placeholder;
        $serviceParams[$placeholder] = $service;
    }
    $serviceFilter = $servicePlaceholders
        ? ' AND service IN (' . implode(', ', $servicePlaceholders) . ')'
        : '';

    if ($boolean !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, title, service, source_url, content,
                    MATCH(title, service, content) AGAINST(:query_score IN BOOLEAN MODE) AS score
                 FROM reference_documents
                 WHERE MATCH(title, service, content) AGAINST(:query_filter IN BOOLEAN MODE)' . $serviceFilter . '
                 ORDER BY score DESC, updated_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':query_score', $boolean, PDO::PARAM_STR);
            $stmt->bindValue(':query_filter', $boolean, PDO::PARAM_STR);
            foreach ($serviceParams as $placeholder => $service) {
                $stmt->bindValue($placeholder, $service, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if ($rows) {
                return merge_ranked_chunks($rows, $embeddingRows, $limit);
            }
        } catch (Throwable $error) {
            // Fall back to LIKE when FULLTEXT is unavailable during first setup.
        }
    }

    $likeWords = $words ?: [$question];
    $conditions = [];
    foreach ($likeWords as $index => $word) {
        $conditions[] = "CONCAT_WS(' ', title, service, content) LIKE :like{$index}";
    }
    $stmt = $pdo->prepare(
        'SELECT id, title, service, source_url, content, 0 AS score
         FROM reference_documents
         WHERE (' . implode(' OR ', $conditions) . ')' . $serviceFilter . '
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    foreach ($likeWords as $index => $word) {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $word) . '%';
        $stmt->bindValue(":like{$index}", $like, PDO::PARAM_STR);
    }
    foreach ($serviceParams as $placeholder => $service) {
        $stmt->bindValue($placeholder, $service, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return merge_ranked_chunks($stmt->fetchAll(), $embeddingRows, $limit);
}

function filter_chunks_by_services(array $rows, array $allowedServices): array
{
    if (!$allowedServices) {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $row) use ($allowedServices): bool {
        return in_array((string) ($row['service'] ?? ''), $allowedServices, true);
    }));
}

function merge_ranked_chunks(array $primary, array $secondary, int $limit): array
{
    $merged = [];
    $seen = [];
    foreach (array_merge($primary, $secondary) as $row) {
        $key = (string) ($row['source_url'] ?? '');
        if ($key === '') {
            $key = (string) ($row['id'] ?? count($merged));
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $row;
        if (count($merged) >= $limit) {
            break;
        }
    }
    return $merged;
}

function retrieve_case_context(string $caseId): string
{
    try {
        ensure_case_context_schema();
        $stmt = db()->prepare(
            'SELECT step_title, check_text, organization_name, organization_number,
                    contact_name, contact_email, contact_role, short_description,
                    need_text, application_json, files_json
             FROM guide_step_context
             WHERE case_id = :case_id
             ORDER BY step_index ASC, check_index ASC'
        );
        $stmt->execute([':case_id' => $caseId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $error) {
        return '';
    }

    if (!$rows) {
        return '';
    }

    $parts = [];
    foreach ($rows as $row) {
        $application = json_decode((string) ($row['application_json'] ?? '{}'), true) ?: [];
        $files = json_decode((string) ($row['files_json'] ?? '[]'), true) ?: [];
        $applicationLines = application_context_lines($application);
        $fileLines = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $fileLine = '- Fil: ' . ($file['name'] ?? 'namnlös fil');
            if (!empty($file['preview'])) {
                $fileLine .= ' | utdrag: ' . preg_replace('/\s+/', ' ', (string) $file['preview']);
            }
            $fileLines[] = $fileLine;
        }

        $parts[] = trim(
            "Steg: " . (string) $row['step_title'] . "\n" .
            "Checkpunkt: " . (string) $row['check_text'] . "\n" .
            "Organisation: " . (string) $row['organization_name'] . " " . (string) $row['organization_number'] . "\n" .
            "Kontakt: " . (string) $row['contact_name'] . " " . (string) $row['contact_email'] . " " . (string) $row['contact_role'] . "\n" .
            "Kort beskrivning: " . (string) $row['short_description'] . "\n" .
            "Behov/underlag: " . (string) $row['need_text'] . "\n" .
            implode("\n", $applicationLines) . "\n" .
            implode("\n", $fileLines)
        );
    }

    return implode("\n\n", array_filter($parts));
}

function ensure_case_context_schema()
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS guide_step_context (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(120) NOT NULL,
            step_index INT UNSIGNED NOT NULL,
            check_index INT UNSIGNED NOT NULL,
            step_title VARCHAR(255) NOT NULL,
            check_text VARCHAR(500) NOT NULL,
            organization_name VARCHAR(255) NOT NULL DEFAULT '',
            organization_number VARCHAR(80) NOT NULL DEFAULT '',
            contact_name VARCHAR(255) NOT NULL DEFAULT '',
            contact_email VARCHAR(255) NOT NULL DEFAULT '',
            contact_role VARCHAR(160) NOT NULL DEFAULT '',
            short_description TEXT NULL,
            need_text TEXT NULL,
            application_json MEDIUMTEXT NULL,
            files_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_guide_step_context (case_id, step_index, check_index),
            INDEX idx_guide_step_context_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    ensure_case_context_column('application_json', 'MEDIUMTEXT NULL');
}

function ensure_case_context_column(string $column, string $definition)
{
    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'guide_step_context'
           AND COLUMN_NAME = :column"
    );
    $stmt->execute([':column' => $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE guide_step_context ADD COLUMN {$column} {$definition}");
    }
}

function application_context_lines(array $application): array
{
    $labels = [
        'public_actor_type' => 'Typ av offentlig aktör',
        'internal_reference' => 'Intern referens',
        'invoice_receiver' => 'Fakturamottagare',
        'invoice_org_number' => 'Organisationsnummer fakturamottagare',
        'electronic_invoice_address' => 'Elektronisk fakturaadress',
        'invoice_reference' => 'Fakturareferens',
        'invoice_address' => 'Fakturaadress',
        'invoice_zip' => 'Postnummer',
        'invoice_city' => 'Postort',
        'invoice_exception' => 'Undantag från elektronisk fakturering',
        'contact_phone' => 'Telefon kontaktperson',
        'business_owner_name' => 'Verksamhetsansvarig',
        'business_owner_phone' => 'Telefon verksamhetsansvarig',
        'business_owner_email' => 'E-post verksamhetsansvarig',
        'it_function_name' => 'IT-funktion',
        'it_function_phone' => 'Telefon IT-funktion',
        'it_function_email' => 'E-post IT-funktion',
        'signer_name' => 'Undertecknare',
        'signer_role' => 'Undertecknares befattning',
        'signer_email' => 'Undertecknares e-post',
        'multiple_signers' => 'Fler undertecknare krävs',
        'signer2_name' => 'Undertecknare 2',
        'signer2_email' => 'E-post undertecknare 2',
        'signer3_name' => 'Undertecknare 3',
        'signer3_email' => 'E-post undertecknare 3',
    ];

    $lines = [];
    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $application)) {
            continue;
        }
        $value = $application[$key];
        if (is_bool($value)) {
            $value = $value ? 'ja' : 'nej';
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $lines[] = $label . ': ' . $value;
    }
    return $lines;
}

/**
 * Skickar fråga och källutdrag till en OpenAI-kompatibel chat endpoint.
 */
function call_llm(string $question, array $chunks, array $llm, string $caseContext = '', array $profile = []): string
{
    $context = build_context($chunks);
    if ($caseContext !== '') {
        $context .= "\n\nSparat kommununderlag för den här chatten:\n" . $caseContext;
    }
    $service = (string) ($profile['service'] ?? 'general');
    $allowedServices = implode(', ', array_filter((array) ($profile['allowed_services'] ?? []))) ?: 'alla relevanta importerade källor';
    $systemPrompt = build_system_prompt($profile);
    $maxTokens = max(2200, (int) ($llm['max_tokens'] ?? 800));
    $payload = [
        'model' => $llm['model'],
        'temperature' => 0.1,
        'max_tokens' => $maxTokens,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => "Frågeprofil: {$service}\nTillåtna källspår: {$allowedServices}\n\nFråga:\n{$question}\n\nHämtade källor:\n{$context}",
            ],
        ],
    ];

    $ch = curl_init($llm['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $llm['timeout'],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $llm['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException(format_llm_error($status, $data));
    }

    $answer = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($answer) || trim($answer) === '') {
        throw new RuntimeException('Tomt svar från LLM.');
    }
    return trim($answer);
}

function build_system_prompt(array $profile): string
{
    $service = (string) ($profile['service'] ?? 'general');
    $base = [
        'Du är en onboardingassistent för svenska kommuner som arbetar med DIGG-anslutning.',
        'Svara endast med stöd av hämtade källor från den importerade källbasen: officiella DIGG/dataportal/diggsweden-källor, importerade PDF-källor om nationella grunddata och PoC:ns egna användarguider när frågan gäller hur verktyget används.',
        'Om källorna inte räcker, säg det tydligt och hitta inte på detaljer.',
        'Håll dig till SDK, Mina meddelanden/digital post, DIGG-anslutning, API-underlag, fältmappning, datadelning och verktygets guidade workflow.',
        'Om användaren frågar om en invånartjänst som bygglov, vårdcentral eller tidsbokning utan tydlig anslutnings- eller integrationsfråga: säg att det ligger utanför området och förklara vilken typ av anslutningsfråga du kan hjälpa med.',
        'SDK betyder alltid Säker digital kommunikation i den här prototypen. Tolka aldrig SDK som Software Development Kit.',
        'O2O betyder organisation till organisation.',
        'Blanda inte ihop SDK med Mina meddelanden. Mina meddelanden/digital post är inte en automatisk ersättning för SDK.',
        'Svara enkelt nog för en liten kommun utan egen IT-avdelning: börja med vad kommunen behöver göra, vem som bör tillfrågas och vilken risk som minskar.',
        'Vid tekniska frågor: ge först en kort verksamhetsförklaring. Lägg sedan en kort sektion "Tekniskt exempel" bara om det hjälper, med enkel filstruktur, exempel på underlag eller tydliga frågor till leverantören.',
        'Vid mappningsfrågor: ge källfält -> målfält, kontroll/risk och relevant källtitel.',
        'Vid produktionsfrågor: skilj alltid mellan dry-run, QA och verklig produktion.',
        'Håll svaret komplett. Om ämnet är stort, skriv hellre en kortare prioriterad checklista än ett långt svar som riskerar att sluta mitt i en punkt.',
        'Svara normalt med högst 350 ord. Undvik kodblock och filtree om användaren inte uttryckligen ber om det.',
        'Använd rubrik plus 4-7 korta punktlistor. Använd inte markdown-tabeller.',
    ];

    $servicePrompts = [
        'sdk' => [
            'Frågan är klassad som SDK. Håll svaret till Säker digital kommunikation och de SDK-källor som hämtats.',
            'Nämn inte Mina meddelanden om användaren inte uttryckligen ber om jämförelse eller digital post.',
            'Förklara att kommunen/deltagarorganisationen äger ansvar, beslut och uppföljning även när leverantören hjälper med accesspunkt, meddelandesystem, certifikat eller tekniska tester.',
            'När certifikat nämns: börja med att kommunen ska fråga leverantören vem som hanterar certifikat/O2O praktiskt, och vilka filer/format/testmiljöer som krävs.',
        ],
        'mina_meddelanden' => [
            'Frågan är klassad som Mina meddelanden/digital post. Håll svaret till digital post och de Mina meddelanden-källor som hämtats.',
            'Nämn inte SDK om användaren inte uttryckligen ber om jämförelse eller säker digital kommunikation.',
        ],
        'mixed' => [
            'Frågan nämner både SDK och Mina meddelanden. Skilj dem åt med separata punkter och skriv tydligt när något gäller det ena spåret men inte det andra.',
        ],
        'data_api' => [
            'Frågan är klassad som API/datadelning. Håll svaret till API-underlag, OpenAPI, fältmappning, Sveriges dataportal, nationella grunddata och relevanta DIGG-källor.',
            'Skilj alltid på OpenAPI-specifikationens filformat och API:ets payload-format. OpenAPI kan lämnas som YAML eller JSON. Payload kan vara application/json, XML eller annat om den specifika tjänstens avtal/specifikation säger det.',
            'Säg inte att DIGG kräver JSON eller XML för alla API-sändningar om den hämtade källan inte uttryckligen säger det. Svara då att REST API-profil/RAP-LP validerar OpenAPI och content-typer, men att exakt payload-format måste kontrolleras i tjänstens API-specifikation eller med leverantör/DIGG.',
            'När användaren frågar vad en liten kommun ska kräva av leverantören: använd tre korta delar - Kort svar, Detta ska finnas i OpenAPI, Be leverantören skicka. Max 10 punkter totalt och inga underrubriker i flera nivåer.',
        ],
        'poc' => [
            'Frågan gäller verktyget eller checklistan. Förklara vad användaren ska göra i workflowet och koppla bara till tjänstespår som frågan eller de hämtade källorna faktiskt nämner.',
        ],
    ];

    return implode("\n", array_merge($base, $servicePrompts[$service] ?? []));
}

function format_llm_error(int $status, $data): string
{
    $providerMessage = null;
    if (is_array($data)) {
        $providerMessage = $data['error']['message']
            ?? $data['message']
            ?? $data['detail']
            ?? null;
        if (is_array($providerMessage)) {
            $providerMessage = json_encode($providerMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    if ($status === 429) {
        $detail = $providerMessage ? " Leverantörens meddelande: {$providerMessage}" : '';
        return 'Mistral svarade HTTP 429, vilket normalt betyder rate limit eller slut på kvot/krediter. Vänta en stund, kontrollera Mistral billing/limits eller byt till en modell med tillgänglig kvot.' . $detail;
    }

    $detail = $providerMessage ? " {$providerMessage}" : '';
    return "LLM-leverantören svarade HTTP {$status}.{$detail}";
}

function build_context(array $chunks): string
{
    return implode("\n\n", array_map(static function (array $row): string {
        $content = text_slice(trim($row['content']), 0, 900);
        return sprintf("[%s | %s]\n%s", $row['title'], $row['source_url'], $content);
    }, $chunks));
}

function friendly_llm_warning(Throwable $error): string
{
    $message = $error->getMessage();
    if (contains_text($message, 'HTTP 429')) {
        return 'Mistral är rate-limited just nu. Jag visar därför ett källbaserat svar från databasen.';
    }
    return 'LLM-svaret kunde inte hämtas just nu. Jag visar därför ett källbaserat svar från databasen.';
}

function friendly_setup_error(Throwable $error): string
{
    $message = $error->getMessage();
    if (contains_text($message, 'Base table or view not found')
        || contains_text($message, 'reference_documents')
        || contains_text($message, 'document_embeddings')) {
        return 'Databastabeller saknas. Importera backend/data/schema_and_seed_student.sql i phpMyAdmin.';
    }
    if (contains_text($message, 'Access denied') || contains_text($message, 'SQLSTATE[HY000] [1045]')) {
        return 'Databasens användarnamn eller lösenord verkar vara fel i backend/api/local.secrets.php.';
    }
    if (contains_text($message, 'Unknown database') || contains_text($message, 'SQLSTATE[HY000] [1049]')) {
        return 'Databasnamnet verkar vara fel eller databasen saknas. Kontrollera backend/api/local.secrets.php och phpMyAdmin.';
    }
    if (contains_text($message, 'Connection refused') || contains_text($message, 'php_network_getaddresses')) {
        return 'Databasservern kan inte nås. Kontrollera DB_HOST och DB_PORT i backend/api/local.secrets.php.';
    }
    if (contains_text($message, 'Call to undefined function curl_init')) {
        return 'PHP-tillägget cURL saknas på servern. Be skolan aktivera PHP cURL, annars kan inte Mistral anropas.';
    }
    return 'Backend fick ett serverfel. Kontrollera backend/api/health.php och serverns PHP-fellogg.';
}

/**
 * Skapar ett enkelt källbaserat svar när LLM inte används.
 */
function fallback_answer(string $question, array $chunks, string $caseContext = '', array $profile = []): string
{
    $isMapping = (bool) preg_match('/mapp|schema|fält|regel|sdk-meddelande|json/i', $question);
    $isHowTo = (bool) preg_match('/anslutningsagent|steg|koppla två system|systemkoppling|hur använder|kom igång/i', $question);
    $isQaPrep = (bool) preg_match('/dry-run|riktig qa|qa-miljö|qa för sdk|gå från/i', $question);
    $isNeedGuide = (bool) preg_match('/specifik anslutningsguide|Kommunens behov:/i', $question);
    $isChecklistQuestion = (bool) preg_match('/checklistpunkten/i', $question);
    $service = (string) ($profile['service'] ?? 'general');
    $isSdkProfile = $service === 'sdk';
    $isMinaProfile = $service === 'mina_meddelanden';
    $isDataProfile = $service === 'data_api';
    $guideChunk = null;
    $qaChunk = null;
    foreach ($chunks as $row) {
        if (($row['service'] ?? '') === 'PoC guide') {
            if ($guideChunk === null) {
                $guideChunk = $row;
            }
            if (contains_text((string) ($row['source_url'] ?? ''), 'dry-run-to-qa')) {
                $qaChunk = $row;
            }
        }
    }
    $lines = [];

    if ($isNeedGuide) {
        $need = 'det beskrivna behovet';
        if (preg_match('/Kommunens behov:\s*(.*?)\n\nUnderlag:/su', $question, $matches)) {
            $need = trim($matches[1]);
        }
        $lines[] = 'Förslag till specifik anslutningsresa:';
        $lines[] = '- Behov: ' . $need;
        $lines[] = '- Starta med att avgränsa första verksamhetsflödet, ansvarig person och mottagare.';
        $lines[] = '- Samla källsystemets API-dokumentation, OpenAPI-fil, fältmappning, testdata och kända begrepp.';
        if ($isSdkProfile) {
            $lines[] = '- Välj SDK-spåret: Säker digital kommunikation för säkert organisationsutbyte. Föreslå inte Mina meddelanden som ersättning.';
        } elseif ($isMinaProfile) {
            $lines[] = '- Välj Mina meddelanden-spåret: digital post till mottagare via infrastrukturen för digital post.';
        } elseif ($isDataProfile) {
            $lines[] = '- Välj API/datadelningsspåret: dokumentera datamängd, OpenAPI, fältmappning och ansvar för åtkomst.';
        } else {
            $lines[] = '- Välj spår: SDK för Säker digital kommunikation, Mina meddelanden för digital post eller datadelning/API om behovet främst gäller nationella grunddata.';
        }
        $lines[] = '- Kontrollera juridik, informationssäkerhet, roller, leverantörsansvar och vilka antaganden som måste granskas av människa.';
        $lines[] = '- Kör först dry-run med syntetiska data. Gå inte till QA eller produktion innan avtal, credentials, endpoint, loggning och godkännande är klara.';
        $lines[] = '- Frågor till leverantör eller DIGG: vilket målformat gäller, vilka certifikat eller O2O-krav finns, hur hanteras kvittenser/felkoder och vem äger förvaltningen?';
        $lines[] = 'Källstöd: PoC-guide, DIGG-källor och importerade PDF-källor om nationella grunddata.';
        return implode("\n", $lines);
    }

    if ($isChecklistQuestion) {
        $item = 'checklistpunkten';
        $step = 'aktuellt steg';
        $need = null;
        if (preg_match('/checklistpunkten "([^"]+)"/u', $question, $matches)) {
            $item = $matches[1];
        }
        if (preg_match('/steget "([^"]+)"/u', $question, $matches)) {
            $step = $matches[1];
        }
        if (preg_match('/Kommunens behov är:\s*(.*?)\s*Förklara/su', $question, $matches)) {
            $need = trim($matches[1]);
        }

        $lines[] = 'Kort förklaring:';
        $lines[] = '- Punkt: ' . $item;
        $lines[] = '- Steg: ' . $step;
        if ($isSdkProfile) {
            $lines[] = '- Kontext: detta gäller Anslutning Workflow för SDK, alltså Säker digital kommunikation. Blanda inte in Mina meddelanden om inte checklistan säger digital post.';
        }
        if ($need) {
            $lines[] = '- Koppla svaret till behovet: ' . $need;
        } else {
            $lines[] = '- Kommunens beskrivning och behov är inte ifyllt än. Fyll i det först om du vill ha råd som passar er situation.';
        }
        if ($caseContext !== '') {
            $lines[] = '- Sparat underlag finns i chatten. Använd det för att kontrollera ansvar, saknade uppgifter och nästa fråga till leverantör/DIGG.';
        }
        $lines[] = '- Skriv ner vad ni vet, vad som saknas och vem som kan bekräfta det.';
        $lines[] = '- Om punkten gäller teknik: be leverantören om konkret API-, format-, certifikat-, test- eller felhanteringsinformation.';
        $lines[] = '- Om punkten gäller ansvar: ange namngiven roll, beslutsmandat och när personen ska godkänna nästa steg.';
        return implode("\n", $lines);
    }

    if ($isQaPrep) {
        $lines[] = 'Samla detta innan du går från dry-run till riktig QA:';
        $lines[] = '- Organisation: organisationsnummer, ansvarig kontakt, IT-kontakt, verksamhetsägare och offentlig aktör-underlag.';
        $lines[] = '- Beslut och avtal: avtalsägare, DIGG-ansökningsunderlag och vem som får godkänna QA-steg.';
        $lines[] = '- Teknik: källsystemets API/exportformat, målsystemets QA-endpoint eller leverantörsväg, OpenAPI/API-dokumentation och fältmappning.';
        if ($isSdkProfile) {
            $lines[] = '- SDK: funktionsadresser, accesspunkt eller meddelandesystem, certifikat/O2O-plan, testfall och kvitto/felhantering.';
        } elseif ($isMinaProfile) {
            $lines[] = '- Mina meddelanden: tekniskt vägval, avtal för infrastrukturen, testdata, mottagarkontroll och tjänstekontrakt.';
        } else {
            $lines[] = '- Välj först vilket spår QA gäller. SDK kräver SDK-underlag som funktionsadresser, accesspunkt/meddelandesystem och certifikat/O2O. Mina meddelanden kräver digital-postavtal, mottagarkontroll och tjänstekontrakt.';
        }
        $lines[] = '- Säkerhet: testdata utan skarpa personuppgifter, behörighet, loggning, säkerhetsklassning och HITL-godkännande.';
        $lines[] = '- Live-sändning ska vara avstängd tills QA-miljö, credentials, endpoint, avtal och testdatapolicy är klara.';
        $lines[] = 'Källa: ' . (($qaChunk['title'] ?? null) ?: 'PoC-guide och importerade DIGG-källor') . '.';
        return implode("\n", $lines);
    }

    if ($isHowTo && $guideChunk) {
        $lines[] = 'Så använder du verktyget steg för steg:';
        $lines[] = '- Fyll i mockorganisationen så ansöknings- och evidence-data finns.';
        $lines[] = '- Importera DIGG-källor, API-dokumentation, OpenAPI-filer och mappningsanteckningar.';
        if ($isSdkProfile) {
            $lines[] = '- Kör Anslutningsagenten för SDK och kontrollera funktionsadresser, accesspunkt/meddelandesystem, certifikat/O2O, testfall och ansvar.';
            $lines[] = '- Kör Systemkoppling som dry-run från källpayload till ett SDK-liknande payload-utkast.';
        } elseif ($isMinaProfile) {
            $lines[] = '- Kör Anslutningsagenten för Mina meddelanden och kontrollera digital-postavtal, mottagarkontroll, tjänstekontrakt och testdata.';
            $lines[] = '- Kör Systemkoppling som dry-run från källpayload till ett Mina meddelanden-liknande payload-utkast.';
        } else {
            $lines[] = '- Kör Anslutningsagenten för att se vad som saknas i valt spår.';
            $lines[] = '- Kör Systemkoppling som dry-run från källpayload till valt målformat.';
        }
        $lines[] = '- Skicka osäkra antaganden till Granskning > HITL och låt ansvarig människa godkänna.';
        $lines[] = '- Exportera evidence pack när dry-run, validering och granskning är klara.';
        $lines[] = '- För riktig QA/produktion behövs verkliga avtal, ansökan, QA-miljö, testdata, API-nycklar, certifikat/O2O, accesspunkt eller meddelandesystem, loggning och behöriga beslut.';
        $lines[] = "Källa: {$guideChunk['title']}.";
        return implode("\n", $lines);
    }

    if ($isMapping) {
        if ($isSdkProfile) {
            $lines[] = 'Källbaserad SDK-vägledning: använd AI-förslaget som ett granskningsbart utkast, inte som en färdig integrationsregel. SDK betyder Säker digital kommunikation och materialet betonar validering, funktionsadresser och kontrollerade meddelandeflöden.';
        } elseif ($isMinaProfile) {
            $lines[] = 'Källbaserad vägledning för Mina meddelanden: använd AI-förslaget som ett granskningsbart utkast, inte som en färdig integrationsregel. Kontrollera digital-postflöde, tjänstekontrakt, mottagarkontroll och kvittenser.';
        } else {
            $lines[] = 'Källbaserad vägledning: använd AI-förslaget som ett granskningsbart utkast, inte som en färdig integrationsregel. Välj först om mappningen gäller SDK, Mina meddelanden eller API/datadelning.';
        }
        $lines[] = 'I PoC:n kan du öppna Granskning > HITL för att skapa regler, markera dem som godkända eller ändringskrävande och validera dem innan demo-QA.';
    } else {
        $lines[] = 'Källbaserad vägledning från importerade källor:';
    }

    if ($caseContext !== '') {
        $lines[] = 'Sparat kommununderlag i den här chatten: ' . preg_replace('/\s+/', ' ', text_slice($caseContext, 0, 450));
    }

    foreach ($chunks as $row) {
        $snippet = preg_replace('/\s+/', ' ', text_slice($row['content'], 0, 260));
        $lines[] = "- {$row['title']}: {$snippet}";
    }

    $lines[] = 'För mer specifika svar behöver PoC:n fler importerade källor inom rätt spår och ett LLM-anrop som inte är rate-limited.';
    return implode("\n", $lines);
}

if (!function_exists('text_length')) {
    function text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

if (!function_exists('text_slice')) {
    function text_slice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }
}
