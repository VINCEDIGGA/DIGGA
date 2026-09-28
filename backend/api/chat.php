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

require_post();
$input = json_input();
$question = trim((string) ($input['question'] ?? ''));
if ($question === '') {
    respond(['error' => 'Frågan får inte vara tom.'], 422);
}

$config = require __DIR__ . '/config.php';
$chunks = retrieve_chunks($question, $config);
$sources = array_map(static fn(array $row): array => [
    'title' => $row['title'],
    'service' => $row['service'],
    'source_url' => $row['source_url'],
], $chunks);

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
            'answer' => call_llm($question, $chunks, $config['llm']),
            'sources' => $sources,
        ]);
    } catch (Throwable $error) {
        respond([
            'mode' => 'database',
            'answer' => fallback_answer($question, $chunks),
            'warning' => friendly_llm_warning($error),
            'sources' => $sources,
        ]);
    }
}

respond([
    'mode' => 'database',
    'answer' => fallback_answer($question, $chunks),
    'warning' => 'LLM är inte konfigurerad, så svaret bygger direkt på importerade källor.',
    'sources' => $sources,
]);

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
    $words = array_values(array_filter(preg_split('/\s+/u', $terms) ?: [], static fn(string $word): bool => text_length($word) > 2));
    $words = array_slice(array_unique($words), 0, 8);
    $boolean = implode(' ', array_map(static fn(string $word): string => $word . '*', $words));

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
        } catch (Throwable) {
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

/**
 * Skickar fråga och källutdrag till en OpenAI-kompatibel chat endpoint.
 */
function call_llm(string $question, array $chunks, array $llm): string
{
    $context = build_context($chunks);
    $payload = [
        'model' => $llm['model'],
        'temperature' => 0.1,
        'max_tokens' => $llm['max_tokens'] ?? 600,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Du är en onboardingassistent för svenska kommuner. Svara endast med stöd av hämtade källor från den importerade källbasen: officiella DIGG/dataportal/diggsweden-källor och PoC:ns egna användarguider när frågan gäller hur verktyget används. Om källorna inte räcker, säg det tydligt. Svara enkelt nog för en icke-teknisk användare, kort, konkret och praktiskt. Använd rubrik plus 4-7 punktlistor. Använd inte markdown-tabeller. Vid mappningsfrågor: ge källfält -> målfält, kontroll/risk och relevant källtitel. Vid produktionsfrågor: skilj alltid mellan dry-run, QA och verklig produktion.',
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

function format_llm_error(int $status, mixed $data): string
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
    if (str_contains($message, 'HTTP 429')) {
        return 'Mistral är rate-limited just nu. Jag visar därför ett källbaserat svar från databasen.';
    }
    return 'LLM-svaret kunde inte hämtas just nu. Jag visar därför ett källbaserat svar från databasen.';
}

/**
 * Skapar ett enkelt källbaserat svar när LLM inte används.
 */
function fallback_answer(string $question, array $chunks): string
{
    $isMapping = (bool) preg_match('/mapp|schema|fält|regel|sdk-meddelande|json/i', $question);
    $isHowTo = (bool) preg_match('/anslutningsagent|steg|koppla två system|systemkoppling|hur använder|kom igång/i', $question);
    $isQaPrep = (bool) preg_match('/dry-run|riktig qa|qa-miljö|qa för sdk|gå från/i', $question);
    $guideChunk = null;
    $qaChunk = null;
    foreach ($chunks as $row) {
        if (($row['service'] ?? '') === 'PoC guide') {
            $guideChunk ??= $row;
            if (str_contains((string) ($row['source_url'] ?? ''), 'dry-run-to-qa')) {
                $qaChunk = $row;
            }
        }
    }
    $lines = [];

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
