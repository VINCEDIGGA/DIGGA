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

    $config = require __DIR__ . '/config.php';
    $chunks = retrieve_chunks($question, $config);
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
                'answer' => call_llm($question, $chunks, $config['llm'], $caseContext),
                'sources' => $sources,
            ]);
        } catch (Throwable $error) {
            respond([
                'mode' => 'database',
                'answer' => fallback_answer($question, $chunks, $caseContext),
                'warning' => friendly_llm_warning($error),
                'sources' => $sources,
            ]);
        }
    }

    respond([
        'mode' => 'database',
        'answer' => fallback_answer($question, $chunks, $caseContext),
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
 * Hämtar relevanta källrader från `reference_documents`.
 *
 * Först används MySQL FULLTEXT. Om det inte fungerar används LIKE-sökning.
 *
 * @return array<int, array<string, mixed>>
 */
function retrieve_chunks(string $question, array $config): array
{
    $limit = (int) ($config['rag']['max_chunks'] ?? 5);
    $embeddingRows = embedding_retrieve_chunks($question, $config, $limit);
    if ($embeddingRows) {
        return $embeddingRows;
    }

    $pdo = db();
    $terms = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $question) ?: '';
    $words = array_values(array_filter(preg_split('/\s+/u', $terms) ?: [], static function (string $word): bool {
        return text_length($word) > 2;
    }));
    $words = array_slice(array_unique($words), 0, 8);
    $boolean = implode(' ', array_map(static function (string $word): string {
        return $word . '*';
    }, $words));

    if ($boolean !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, title, service, source_url, content,
                    MATCH(title, service, content) AGAINST(:query_score IN BOOLEAN MODE) AS score
                 FROM reference_documents
                 WHERE MATCH(title, service, content) AGAINST(:query_filter IN BOOLEAN MODE)
                 ORDER BY score DESC, updated_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':query_score', $boolean, PDO::PARAM_STR);
            $stmt->bindValue(':query_filter', $boolean, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if ($rows) {
                return $rows;
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
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    foreach ($likeWords as $index => $word) {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $word) . '%';
        $stmt->bindValue(":like{$index}", $like, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
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
function call_llm(string $question, array $chunks, array $llm, string $caseContext = ''): string
{
    $context = build_context($chunks);
    if ($caseContext !== '') {
        $context .= "\n\nSparat kommununderlag för den här chatten:\n" . $caseContext;
    }
    $payload = [
        'model' => $llm['model'],
        'temperature' => 0.1,
        'max_tokens' => $llm['max_tokens'] ?? 600,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Du är en onboardingassistent för svenska kommuner. Svara endast med stöd av hämtade källor från den importerade källbasen: officiella DIGG/dataportal/diggsweden-källor, importerade PDF-källor om nationella grunddata och PoC:ns egna användarguider när frågan gäller hur verktyget används. Om källorna inte räcker, säg det tydligt. När användaren beskriver kommunens behov och bifogat underlag ska du skapa en specifik anslutningsresa med korta steg, konkreta insamlingspunkter och tydliga frågor till leverantör eller DIGG. Svara enkelt nog för en icke-teknisk användare, kort, konkret och praktiskt. Använd rubrik plus 4-7 punktlistor. Använd inte markdown-tabeller. Vid mappningsfrågor: ge källfält -> målfält, kontroll/risk och relevant källtitel. Vid produktionsfrågor: skilj alltid mellan dry-run, QA och verklig produktion.',
            ],
            [
                'role' => 'user',
                'content' => "Fråga:\n{$question}\n\nHämtade källor:\n{$context}",
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
function fallback_answer(string $question, array $chunks, string $caseContext = ''): string
{
    $isMapping = (bool) preg_match('/mapp|schema|fält|regel|sdk-meddelande|json/i', $question);
    $isHowTo = (bool) preg_match('/anslutningsagent|steg|koppla två system|systemkoppling|hur använder|kom igång/i', $question);
    $isQaPrep = (bool) preg_match('/dry-run|riktig qa|qa-miljö|qa för sdk|gå från/i', $question);
    $isNeedGuide = (bool) preg_match('/specifik anslutningsguide|Kommunens behov:/i', $question);
    $isChecklistQuestion = (bool) preg_match('/checklistpunkten/i', $question);
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
        $lines[] = '- Välj spår: SDK för säker digital kommunikation, Mina meddelanden för digital post eller datadelning/API om behovet främst gäller nationella grunddata.';
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
        if ($need) {
            $lines[] = '- Koppla svaret till behovet: ' . $need;
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
        $lines[] = '- SDK: funktionsadresser, accesspunkt eller meddelandesystem, certifikat/O2O-plan, testfall och kvitto/felhantering.';
        $lines[] = '- Mina meddelanden: tekniskt vägval, avtal för infrastrukturen, testdata, mottagarkontroll och tjänstekontrakt.';
        $lines[] = '- Säkerhet: testdata utan skarpa personuppgifter, behörighet, loggning, säkerhetsklassning och HITL-godkännande.';
        $lines[] = '- Live-sändning ska vara avstängd tills QA-miljö, credentials, endpoint, avtal och testdatapolicy är klara.';
        $lines[] = 'Källa: ' . (($qaChunk['title'] ?? null) ?: 'PoC-guide och importerade DIGG-källor') . '.';
        return implode("\n", $lines);
    }

    if ($isHowTo && $guideChunk) {
        $lines[] = 'Så använder du verktyget steg för steg:';
        $lines[] = '- Fyll i mockorganisationen så ansöknings- och evidence-data finns.';
        $lines[] = '- Importera DIGG-källor, API-dokumentation, OpenAPI-filer och mappningsanteckningar.';
        $lines[] = '- Kör Anslutningsagenten för att se vad som saknas för Mina meddelanden och SDK.';
        $lines[] = '- Kör Systemkoppling som dry-run från källpayload till SDK- eller Mina meddelanden-stil payload.';
        $lines[] = '- Skicka osäkra antaganden till Granskning > HITL och låt ansvarig människa godkänna.';
        $lines[] = '- Exportera evidence pack när dry-run, validering och granskning är klara.';
        $lines[] = '- För riktig QA/produktion behövs verkliga avtal, ansökan, QA-miljö, testdata, API-nycklar, certifikat/O2O, accesspunkt eller meddelandesystem, loggning och behöriga beslut.';
        $lines[] = "Källa: {$guideChunk['title']}.";
        return implode("\n", $lines);
    }

    if ($isMapping) {
        $lines[] = 'Källbaserad vägledning: använd AI-förslaget som ett granskningsbart utkast, inte som en färdig integrationsregel. SDK-materialet betonar validering, funktionsadresser och kontrollerade meddelandeflöden.';
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

    $lines[] = 'För mer specifika svar behöver PoC:n fler importerade källor från DIGG/dataportal/diggsweden och ett LLM-anrop som inte är rate-limited.';
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
