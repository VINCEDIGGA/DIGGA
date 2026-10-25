<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_compat.php';
require_once __DIR__ . '/rag_context.php';

/** Bounded quote provenance and critical SDK invariants; this is not a general entailment judge. */
function rag_evidence_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function rag_evidence_slice(string $text, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}

function rag_evidence_space(string $text): string
{
    return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
}

function rag_evidence_url(string $url): string
{
    if (preg_match('/[\x00-\x20\x7f]/', $url) || preg_match('/%0[ad]/i', $url)) { return ''; }
    $parts = parse_url($url);
    return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : '';
}

/** Stable IDs and metadata are derived only from selected records, never from model output. */
function rag_prepare_sources(array $chunks): array
{
    $prepared = []; $seen = [];
    foreach (array_slice($chunks, 0, 30) as $chunk) {
        if (!is_array($chunk) || !is_string($chunk['content'] ?? null)) { continue; }
        $content = rag_evidence_slice($chunk['content'], 5000);
        if (trim($content) === '') { continue; }
        $metadata = $chunk['metadata_json'] ?? [];
        if (is_string($metadata)) { $metadata = json_decode($metadata, true) ?: []; }
        if (!is_array($metadata)) { $metadata = []; }
        $origin = (string) ($chunk['origin'] ?? $metadata['origin'] ?? 'imported_source');
        $url = rag_evidence_url((string) ($chunk['source_url'] ?? $chunk['url'] ?? $metadata['url'] ?? ''));
        $sourceId = (string) ($chunk['source_id'] ?? $metadata['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}$/D', $sourceId)) {
            $sourceId = 'source-' . substr(hash('sha256', $url . '|' . ($chunk['title'] ?? '')), 0, 20);
        }
        $isCase = $origin === 'case_context' && $sourceId === 'case-local';
        $id = (string) ($chunk['chunk_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,159}$/D', $id)) {
            $id = $sourceId . '-' . substr(hash('sha256', $content), 0, 18);
        }
        if (isset($seen[$id])) {
            if ($seen[$id] === $content) { continue; }
            $id .= '-' . substr(hash('sha256', $content), 0, 10);
        }
        $seen[$id] = $content;
        $chunk['content'] = $content; $chunk['source_id'] = $sourceId; $chunk['chunk_id'] = $id;
        $chunk['title'] = rag_evidence_slice((string) ($chunk['title'] ?? $metadata['title'] ?? 'Importerad källa'), 255);
        $chunk['source_url'] = $isCase ? '' : $url; $chunk['origin'] = $origin;
        foreach (['version', 'last_checked', 'environment', 'role'] as $key) {
            $value = $chunk[$key] ?? $metadata[$key] ?? '';
            $chunk[$key] = is_string($value) || is_numeric($value) ? rag_evidence_slice((string) $value, 200) : '';
        }
        $prepared[] = $chunk;
    }
    return $prepared;
}

/** These lexical checks are deliberately bounded; ordinary entailment still needs review. */
function rag_evidence_predicate(): string
{
    return '(?:är|is|are|=|ersätts|ersätter|motsvarar|består|registr\p{L}*|lägg\p{L}*|publicer\p{L}*|administr\p{L}*|skapa\p{L}*|generer\p{L}*|hanter\p{L}*|lämna\p{L}*|laddar|skicka\p{L}*|submit\p{L}*|tilldela\p{L}*|ger|utför\p{L}*|genomför\p{L}*|kör\p{L}*|sker|gör\p{L}*|innebär|bekräftar|visar|garanterar|finns|omfattar|krävs|heter|anges|använd\p{L}*|use)';
}

/** Split a new actor or a negative correction without splitting a list of actors. */
function rag_evidence_clauses(string $text): array
{
    $result = [];
    foreach (preg_split('/(?<=[.!?])\s+|[;\n]+/u', $text) ?: [$text] as $sentence) {
        $start = 0;
        preg_match_all('/\s+(?:och|men|utan(?:\s+(?:även|också))?|and|but)\s+|,\s*(?=(?:inte|ej|aldrig|not|never)\b)/u', $sentence, $joins, PREG_OFFSET_CAPTURE);
        foreach ($joins[0] as [$join, $offset]) {
            $before = substr($sentence, $start, $offset - $start);
            $after = substr($sentence, $offset + strlen($join));
            $hasPredicate = preg_match('/\b' . rag_evidence_predicate() . '\b/u', $before);
            $negativeCorrection = preg_match('/^(?:inte(?!\s+(?:bara|endast))|ej|aldrig|not(?!\s+only)|never)\b/u', $after);
            $newActor = preg_match('/^(?:digg|(?:organisations|adress|användar)administratör\p{L}*|accesspunktsoperatör\p{L}*|kommunen|deltagarorganisation\p{L}*|(?:sdk\s+)?testklient\p{L}*|funktionsadress\p{L}*|deltagarident\p{L}*).{0,75}\b' . rag_evidence_predicate() . '\b/u', $after);
            $contrast = preg_match('/\butan\b/u', $join) && !preg_match('/\binte\s+(?:bara|endast|enbart)\b/u', $before);
            if ($hasPredicate && ($negativeCorrection || $newActor || $contrast)) {
                $result[] = trim($before);
                $start = $offset + strlen($join);
            }
        }
        $result[] = trim(substr($sentence, $start));
    }
    return array_values(array_filter($result, static fn(string $part): bool => $part !== ''));
}

/** Negation must qualify the matched relation, not an unrelated fact later in a sentence. */
function rag_evidence_negative(string $relation, string $prefix = ''): bool
{
    $relation = (string) preg_replace('/\b(?:inte\s+(?:bara|endast|enbart)|not\s+only)\b/u', '', $relation);
    $prefix = (string) preg_replace('/\b(?:inte\s+(?:bara|endast|enbart)|not\s+only)\b/u', '', $prefix);
    $negative = '(?:inte|ej|aldrig|not|never|ingen|inget|inga|varken)';
    $predicate = rag_evidence_predicate();
    return preg_match('/\b' . $negative . '\s+(?:(?:just|alls|längre|själv|själva|enbart|bara)\s+)*(?:så\s+att\s+)?$/u', $prefix) === 1
        || preg_match('/\b' . $predicate . '\s+(?:ju\s+|alltså\s+|heller\s+)?' . $negative . '\b|\b' . $negative . '\s+(?:(?:ska|skall|får|kan|behöver|bör|måste|do|does|should|must)\s+)?' . $predicate . '\b/u', $relation) === 1;
}

function rag_evidence_positive_match(string $pattern, string $clause): bool
{
    preg_match_all($pattern, $clause, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$relation, $offset]) {
        $prefix = substr($clause, max(0, $offset - 45), min(45, $offset));
        if (!rag_evidence_negative($relation, $prefix)) { return true; }
    }
    return false;
}

/** Returns a specific rejection reason for known dangerous factual substitutions. */
function rag_sdk_critical_reason(string $text, array $evidence): ?string
{
    $q = function_exists('mb_strtolower') ? mb_strtolower(rag_evidence_space($text), 'UTF-8') : strtolower(rag_evidence_space($text));
    $quoted = implode("\n", array_column($evidence, 'quote'));
    $quotedLower = function_exists('mb_strtolower') ? mb_strtolower(rag_evidence_space($quoted), 'UTF-8') : strtolower(rag_evidence_space($quoted));
    // A conditional statement does not by itself establish an exclusive rule.
    $exclusivePattern = '/\b(?:endast|enbart|bara|only|uteslutande|exklusivt)\b/u';
    $notOnlyPattern = '/\b(?:inte\s+(?:bara|endast|enbart)|not\s+only)\b/u';
    if (preg_match($exclusivePattern, (string) preg_replace($notOnlyPattern, '', $q))
        && !preg_match($exclusivePattern, (string) preg_replace($notOnlyPattern, '', $quotedLower))) {
        return 'Ett exklusivt villkor som ”endast” behöver uttryckligt stöd i källutdraget; att något gäller i ett visst fall visar inte att det bara gäller då.';
    }
    // Reusing conversationId does not establish whether messageId is new or unique.
    $otherId = '\b(?:conversation|reftomessage)[ _-]?id\b';
    $newMessageId = '/\bmessage[ _-]?id\b(?:(?!' . $otherId . ').){0,60}\b(?:nytt|nya?|unik[ta]?|unique|new)\b|\b(?:nytt|nya?|unik[ta]?|unique|new)\b(?:(?!' . $otherId . ').){0,40}\bmessage[ _-]?id\b/u';
    $messageIdSupported = false;
    foreach ($evidence as $item) {
        $quoteLower = function_exists('mb_strtolower') ? mb_strtolower(rag_evidence_space($item['quote']), 'UTF-8') : strtolower(rag_evidence_space($item['quote']));
        if (rag_evidence_positive_match($newMessageId, $quoteLower)) { $messageIdSupported = true; break; }
    }
    if (rag_evidence_positive_match($newMessageId, $q) && !$messageIdSupported) {
        return 'Påståendet om ett nytt eller unikt messageId saknar ett uttryckligt källutdrag om just messageId; återanvänt conversationId räcker inte som belägg.';
    }
    $caseEvidence = array_filter($evidence, static fn(array $e): bool => $e['origin'] === 'case_context');
    $allCase = count($caseEvidence) === count($evidence);
    if ($caseEvidence && !preg_match('/^(?:enligt|utifrån|i)\s+(?:(?:ert|ditt|det|kommunens|organisationens)\s+)?(?:(?:sparade|lokala)\s+)+(?:kommun)?underlag\b/u', $q)) {
        return 'Uppgifter från ärendet måste tydligt märkas, till exempel med ”Enligt ert sparade underlag”, så att de inte förväxlas med officiella källor.';
    }
    $sdk = preg_match('/sdk|funktionsadress|funktionsbrevlåda|smp|certpub|deltagarident|organisationsadministr|användaradministr|adressadministr|open.?test|federationsgodkän|\btf\s*\d+\.\d+\.\d+/u', $q) === 1;
    foreach ($evidence as $e) {
        if (str_contains($e['source_url'], '/saker-digital-kommunikation') || str_contains($e['source_url'], '/sdk-docs')) { $sdk = true; }
    }
    if ($allCase && $sdk && preg_match('/\b(?:ska|måste|krävs|regelverket|obligatorisk|enligt digg)\b/u', $q)) {
        return 'Lokalt kommununderlag kan stödja en observation om ärendet, men inte ensamt styrka en officiell SDK-regel.';
    }
    $approvedTests = ['2.0.1','2.0.2','2.1.2','3.0.1','3.0.2','3.1.2'];
    $clauses = rag_evidence_clauses($q);
    foreach ($clauses as $clause) {
        // A normal contact email is allowed. An email assigned as a functional address is not.
        if (preg_match('/funktionsadress|funktionsbrevlåda|functional address/u', $clause)
            && preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $clause, $emails, PREG_OFFSET_CAPTURE)) {
            foreach ($emails[0] as [$email, $offset]) {
                $prefix = substr($clause, max(0, $offset - 90), min(90, $offset));
                $contact = preg_match('/(?:kontakta|mejla|e-post|e\.post|email|contact|support|skicka mejl)[^@]{0,65}$/u', $prefix);
                $assignments = [
                    '/(?:funktionsadress|funktionsbrevlåda|functional address).{0,65}(?:är|=|:|heter|exempel|använd|anges|use|is).{0,40}' . preg_quote($email, '/') . '/u',
                    '/' . preg_quote($email, '/') . '.{0,45}(?:är|som|is|as).{0,25}(?:funktionsadress|funktionsbrevlåda|functional address)/u',
                ];
                $assigned = false; $negatedAssignment = false;
                foreach ($assignments as $assignment) {
                    $positive = rag_evidence_positive_match($assignment, $clause);
                    $assigned = $assigned || $positive;
                    $negatedAssignment = $negatedAssignment || (!$positive && preg_match($assignment, $clause));
                }
                if ($assigned || (!$contact && !$negatedAssignment)) { return 'En funktionsadress får inte ersättas med ett påhittat e-postformat. Vanliga kontaktadresser är separata uppgifter.'; }
            }
        }
        if (!$sdk) { continue; }
        if (rag_evidence_positive_match('/(?:deltagarident\w*|participant identifier).{0,55}(?:är|=|ersätts|motsvarar|samma som|består av|som).{0,35}(?:organisationsnum|organisationsnamn)/u', $clause)) {
            return 'Deltagaridentiteten är separat från organisationsnummer och organisationsnamn.';
        }
        if (rag_evidence_positive_match('/(?:funktionsadress|funktionsbrevlåda).{0,100}(?:registr|lägg|läggs|publicer|administr|skapa).{0,65}\b(?:smp|certpub)\b/u', $clause)
            || rag_evidence_positive_match('/\b(?:smp|certpub)\b.{0,55}(?:registr|administr|hanter|skapa).{0,65}(?:funktionsadress|funktionsbrevlåda)/u', $clause)) {
            return 'Funktionsadresser administreras i SDK adressbok, inte som adressregistrering i SMP.';
        }
        $wrongAddressActor = '(?:digg|organisationsadministratör\w*|användaradministratör\w*|accesspunktsoperatör\w*)';
        if (rag_evidence_positive_match('/' . $wrongAddressActor . '.{0,40}(?:registrerar|skapar|lägger upp|administrerar).{0,55}(?:funktionsadress|funktionsbrevlåda)/u', $clause)
            || rag_evidence_positive_match('/(?:funktionsadress|funktionsbrevlåda).{0,45}(?:registreras|administreras|skapas).{0,25}\bav\b.{0,15}' . $wrongAddressActor . '/u', $clause)) {
            return 'Adressadministratörens ansvar för funktionsadresser har tilldelats fel roll.';
        }
        if (rag_evidence_positive_match('/(?:digg|organisationsadministratör\w*|användaradministratör\w*|adressadministratör\w*).{0,50}(?:registrerar|lägger upp).{0,60}(?:deltagarident|participant).{0,50}\bsmp\b/u', $clause)) {
            return 'Accesspunktsoperatören registrerar deltagaridentiteten som Participant Identifier i SMP.';
        }
        if (rag_evidence_positive_match('/(?:adressadministratör\w*|användaradministratör\w*).{0,40}(?:lämnar|laddar upp|skickar|submit).{0,45}(?:o2o|publik\w* certifikat|certifikat)/u', $clause)) {
            return 'Organisationsadministratören lämnar det publika O2O-certifikatet; användaradministratören tilldelar rollen.';
        }
        if (rag_evidence_positive_match('/(?:organisationsadministratör\w*|adressadministratör\w*|accesspunktsoperatör\w*|kommunen).{0,45}(?:publicerar|publicering).{0,65}(?:o2o|certifikat).{0,60}(?:smp|certpub)/u', $clause)) {
            return 'Digg publicerar det inlämnade publika O2O-certifikatet i CertPub/SMP.';
        }
        if (rag_evidence_positive_match('/(?:organisationsadministratör\w*|adressadministratör\w*).{0,35}(?:tilldelar|ger).{0,35}(?:organisationsadministratör|adressadministratör|testklient|behörighet|roller)/u', $clause)) {
            return 'Användaradministratören tilldelar organisationens administratörs- och testklientroller.';
        }
        if (rag_evidence_positive_match('/(?:qa.{0,35}(?:är samma|likvärdig|identisk|utbytbar|=|ersätts av).{0,35}open.?test|open.?test.{0,35}(?:är samma|likvärdig|identisk|utbytbar|=|ersätter).{0,35}qa)/u', $clause)) {
            return 'SDK QA och OPEN-TEST är olika miljöer och kan inte likställas.';
        }
        $participantTest = preg_match('/(?:obligatorisk|federationsgodkän|deltagarorganisation).{0,50}(?:test|prova)|testfall.{0,45}(?:obligatorisk|federationsgodkän)/u', $clause);
        if ($participantTest && preg_match('/open.?test/u', $clause) && rag_evidence_positive_match('/(?:utför|genomför|kör|sker|görs).{0,35}open.?test/u', $clause)) {
            return 'Deltagarorganisationens obligatoriska anslutningstester genomförs i SDK QA, inte OPEN-TEST.';
        }
        if (rag_evidence_positive_match('/(?:lokal\w*|xsd|formatkontroll|xml-kontroll|grön kontroll|poc).{0,100}(?:ger|innebär|bekräftar|visar|garanterar|gör).{0,70}(?:federationsgodkän|digg.{0,20}godkän|godkänd.{0,30}produktion|ansluten.{0,20}produktion)/u', $clause)) {
            return 'En lokal formatkontroll ger inte federationsgodkännande eller godkänd produktionsanslutning.';
        }
        if (preg_match_all('/\btf\s*(\d+\.\d+\.\d+)\b/u', $clause, $testMatches)) {
            foreach ($testMatches[1] as $test) {
                if (!in_array($test, $approvedTests, true) && $participantTest && !rag_evidence_negative($clause)) { return 'Test-ID:t ingår inte bland de sex obligatoriska testfallen för deltagarorganisationer.'; }
                if (in_array($test, $approvedTests, true) && rag_evidence_positive_match('/tf\s*' . preg_quote($test, '/') . '.{0,30}(?:är|gäller som|klassas som).{0,15}(?:frivillig\w*|rekommendera\w*)/u', $clause)) { return 'Ett obligatoriskt deltagartest har felaktigt beskrivits som rekommenderat eller frivilligt.'; }
                $receive = in_array($test, ['2.0.1','2.1.2','3.0.1','3.1.2'], true);
                if ($receive && rag_evidence_positive_match('/(?:deltagarorganisation\w*|egen meddelandetjänst|egna klienten).{0,45}skickar.{0,45}(?:till )?(?:sdk )?testklient/u', $clause)) { return 'Test-ID och meddelandets riktning stämmer inte överens.'; }
                if (!$receive && rag_evidence_positive_match('/testklient\w*.{0,35}skickar.{0,50}(?:till )?(?:deltagarorganisation|egna klienten|egen meddelandetjänst)/u', $clause)) { return 'Test-ID och meddelandets riktning stämmer inte överens.'; }
                if (in_array($test, ['2.0.1','2.0.2','3.0.1','3.0.2'], true) && rag_evidence_positive_match('/tf\s*' . preg_quote($test, '/') . '.{0,45}(?:testar|gäller|avser).{0,20}(?:maximal|maxstorlek|stora bilagor)/u', $clause)) { return 'Ett normalt meddelandetest har felaktigt beskrivits som testet för maximal storlek.'; }
            }
        }
        if (preg_match('/(?:det finns|omfattar|krävs|är)\s*(\d+|fem|sju|åtta)\s+(?:obligatoriska\s+)?(?:anslutnings)?test/u', $clause, $count) && !in_array($count[1], ['6'], true) && rag_evidence_positive_match('/(?:det finns|omfattar|krävs|är)\s*(?:\d+|fem|sju|åtta)\s+(?:obligatoriska\s+)?(?:anslutnings)?test/u', $clause)) { return 'Antalet obligatoriska deltagartester stämmer inte med de sex dokumenterade testfallen.'; }
    }
    // Exact values may come from supplied evidence; only these narrow published examples are reusable.
    $catalogue = ['0203:test.organisationsnamn.se','barnoungdom.0203:testkommun.se'];
    foreach ($approvedTests as $test) { $catalogue[] = $test; $catalogue[] = 'TF ' . $test; }
    preg_match_all('/\b[a-z0-9][a-z0-9_.-]*\.0203:[a-z0-9.-]+\b|\b0203:[a-z0-9.-]+\b|\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b|[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $text, $identifiers);
    foreach (array_unique($identifiers[0]) as $identifier) {
        if (!str_contains($quoted, $identifier) && !in_array($identifier, $catalogue, true)) { return 'En identifierare eller kontaktadress saknar stöd i det citerade underlaget eller godkända exempel.'; }
    }
    if ($sdk && preg_match('/exempel|till exempel|t\.ex\.|obligatorisk|\bmb\b|månader|dagar|\btf\s*\d/u', $q)) {
        $withoutIds = $text;
        foreach ($identifiers[0] as $identifier) { $withoutIds = str_replace($identifier, '', $withoutIds); }
        $withoutIds = preg_replace('/\bTF\s*\d+\.\d+\.\d+\b/iu', '', $withoutIds);
        preg_match_all('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)*(?![\p{L}\p{N}])/u', $withoutIds, $numbers);
        foreach (array_unique($numbers[0]) as $number) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($number, '/') . '(?![\p{L}\p{N}])/u', $quoted)) { continue; }
            if ($number === '30' && preg_match('/\b30\s*mb\b/u', $q)) { continue; }
            if ($number === '6' && preg_match('/\b6\s*(?:månader|obligatoriska test|testfall|tester)/u', $q)) { continue; }
            return 'Ett tal i exemplet eller det kritiska SDK-påståendet saknar stöd i citatet.';
        }
    }
    return null;
}

/** A matched quote is provenance only. The caller must still judge ordinary semantic support. */
function rag_validate_draft(array $draft, array $chunks): array
{
    $result = ['accepted' => [], 'rejected' => [], 'missing_information' => []];
    $selected = []; foreach (rag_prepare_sources($chunks) as $chunk) { $selected[$chunk['chunk_id']] = $chunk; }
    $claims = $draft['claims'] ?? null;
    if (!is_array($claims) || !array_is_list($claims) || !$claims) {
        $result['rejected'][] = ['reason' => 'Svaret saknar en giltig lista med påståenden.', 'code' => 'draft_structure'];
        return $result;
    }
    foreach ($claims as $index => $claim) {
        $reason = null; $code = 'claim_structure'; $enriched = [];
        if ($index >= 16) { $reason = 'Svaret innehåller fler än 16 påståenden.'; }
        elseif (!is_array($claim) || !is_string($claim['text'] ?? null) || trim($claim['text']) === '' || rag_evidence_length($claim['text']) > 700) { $reason = 'Påståendet måste ha 1–700 tecken.'; }
        elseif (!is_array($claim['evidence'] ?? null) || !array_is_list($claim['evidence']) || count($claim['evidence']) < 1 || count($claim['evidence']) > 4) { $reason = 'Varje påstående behöver 1–4 källcitat.'; }
        else {
            foreach ($claim['evidence'] as $e) {
                if (!is_array($e) || !is_string($e['chunk_id'] ?? null) || !is_string($e['quote'] ?? null)) { $reason = 'Källcitatet har ogiltig struktur.'; $code = 'evidence_structure'; break; }
                $chunk = $selected[$e['chunk_id']] ?? null;
                if (!$chunk) { $reason = 'Citatet hänvisar till ett utdrag som inte gavs till modellen.'; $code = 'unknown_chunk'; break; }
                $isCase = $chunk['origin'] === 'case_context' && $chunk['source_id'] === 'case-local';
                if ($chunk['source_url'] === '' && !$isCase) { $reason = 'Källan saknar en säker ursprungslänk.'; $code = 'unsafe_source_url'; break; }
                $quote = rag_evidence_space($e['quote']);
                if (rag_evidence_length($quote) < 35 || rag_evidence_length($e['quote']) > 1000) { $reason = 'Ett citat måste innehålla 35–1000 tecken.'; $code = 'quote_bounds'; break; }
                if (!str_contains(rag_evidence_space($chunk['content']), $quote)) { $reason = 'Citatet finns inte ordagrant i det angivna utdraget. Endast blanksteg får skilja.'; $code = 'quote_not_found'; break; }
                $item = ['chunk_id' => $chunk['chunk_id'], 'source_id' => $chunk['source_id'], 'title' => $chunk['title'], 'source_url' => $chunk['source_url'], 'quote' => trim($e['quote']), 'origin' => $chunk['origin']];
                if(isset($e['quote_id'])&&is_string($e['quote_id']))$item['quote_id']=$e['quote_id'];
                foreach (['version','last_checked','environment','role'] as $key) { $item[$key] = $chunk[$key]; }
                $item['service']=$chunk['service']??'';
                $item['context']=rag_evidence_context($chunk,$e['quote']);
                $enriched[] = $item;
            }
            if ($reason === null) { $reason = rag_sdk_critical_reason($claim['text'], $enriched); $code = 'critical_sdk_fact'; }
            if ($reason === null) { $reason = rag_context_claim_reason($claim['text'], $enriched); $code = 'source_context'; }
        }
        if ($reason !== null) {
            $result['rejected'][] = ['claim_index' => $index, 'reason' => $reason, 'code' => $code];
        } else {
            $result['accepted'][] = ['text' => trim($claim['text']), 'evidence' => $enriched, 'kind' => 'summary', 'support_check' => 'quote_matched_semantics_pending'];
        }
    }
    if ($result['rejected']) { $result['missing_information'][] = 'En eller flera uppgifter kunde inte styrkas med de valda källutdragen och de kritiska SDK-reglerna.'; }
    return $result;
}

/** No generated answer is used here. Stored editorial summaries retain their separate label. */
function rag_excerpt_response(array $chunks, string $reason, array $options = []): array
{
    $limit = max(1, min(4, (int) ($options['max_sources'] ?? 3)));
    $chars = max(80, min(1000, (int) ($options['excerpt_chars'] ?? 650)));
    $claims = []; $sources = []; $seen = []; $hasSummaries = false;
    foreach (rag_prepare_sources($chunks) as $chunk) {
        $isCase = $chunk['origin'] === 'case_context' && $chunk['source_id'] === 'case-local';
        if (($chunk['source_url'] === '' && !$isCase) || isset($seen[$chunk['source_id']])) { continue; }
        $excerpt = trim(rag_evidence_slice($chunk['content'], $chars));
        if (rag_evidence_length(rag_evidence_space($excerpt)) < 35) { continue; }
        $seen[$chunk['source_id']] = true;
        $e = ['chunk_id'=>$chunk['chunk_id'],'source_id'=>$chunk['source_id'],'title'=>$chunk['title'],'source_url'=>$chunk['source_url'],'quote'=>$excerpt,'origin'=>$chunk['origin']];
        foreach (['version','last_checked','environment','role'] as $key) { $e[$key]=$chunk[$key]; }
        $e['service']=$chunk['service']??'';
        $isSummary = $chunk['origin'] === 'reviewed_manifest_key_point';
        $hasSummaries = $hasSummaries || $isSummary;
        $claims[] = ['text'=>$excerpt,'evidence'=>[$e],'kind'=>$isSummary?'summary':'excerpt','support_check'=>$isSummary?'stored_summary':'exact_excerpt'];
        $sources[] = ['title'=>$chunk['title'],'service'=>$chunk['service']??'','source_url'=>$chunk['source_url'],'source_id'=>$chunk['source_id'],'origin'=>$chunk['origin'],'version'=>$chunk['version'],'last_checked'=>$chunk['last_checked']];
        if (count($claims)>=$limit) { break; }
    }
    $warning = 'Sammanfattningen kunde inte verifieras. Här visas endast ordagranna källutdrag; de är ingen individuell prövning eller ett godkännande.';
    if ($hasSummaries) { $warning = 'AI-sammanfattningen kunde inte verifieras. Här visas sparat källstöd. Granskade sammanfattningar är märkta och är inte ordagranna utdrag ur originalkällan. Underlaget är ingen individuell prövning eller ett godkännande.'; }
    $parts = [$claims ? $warning : 'Jag hittar inget tillräckligt källutdrag för ett verifierbart svar. Ange gärna tjänst, miljö och vilken uppgift du vill kontrollera.'];
    foreach ($claims as $claim) {
        $e=$claim['evidence'][0];
        $parts[]=$e['title'].($e['origin']==='case_context'?' (lokalt kommununderlag)':($e['origin']==='reviewed_manifest_key_point'?' (granskad sammanfattning)':'')).":\n\n".$claim['text'].($e['source_url']!==''?"\n\nKälla: ".$e['source_url']:'');
    }
    return ['mode'=>'source_excerpts','answer'=>implode("\n\n",$parts),'claims'=>$claims,'sources'=>$sources,'warning'=>$warning,'verification_status'=>$claims?'excerpts':'no_support','verification'=>['status'=>$claims?'excerpts':'no_support','checked_claims'=>count($claims),'omitted_claims'=>max(0,(int)($options['omitted_claims']??0)),'semantic_check'=>$options['semantic_check']??'not_used','notice'=>rag_evidence_slice($reason,250),'method'=>'exact selected-source substrings; no generated summary'],'missing_information'=>$claims?['Ett fullständigt svar på frågan kunde inte styrkas. Utdragen visar relevant bakgrund men kan lämna delar obesvarade.']:['Tillräckligt relevant och citerbart källunderlag saknas.']];
}
