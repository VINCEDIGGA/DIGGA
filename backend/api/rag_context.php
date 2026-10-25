<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_compat.php';

/** Quote-local provenance only: these labels describe supplied text, not external rules. */
function rag_context_space(string $text): string
{
    return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
}

function rag_context_lower(string $text): string
{
    return mb_strtolower(rag_context_space($text), 'UTF-8');
}

function rag_context_kind(string $text): string
{
    $as4 = preg_match('/\bAS4\b/iu', $text) === 1;
    $o2o = preg_match('/\bO2O\b|organisation\s+till\s+organisation/iu', $text) === 1;
    return $as4 && $o2o ? 'mixed' : ($as4 ? 'AS4' : ($o2o ? 'O2O' : 'unknown'));
}

/** Retain actual section labels, including a distant enclosing certificate heading. */
function rag_context_headings(string $content): array
{
    $headings = [];
    $lines = preg_split('/\R/u', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
    foreach ($lines as $index => [$line, $offset]) {
        $text = trim($line);
        if ($text === '' || mb_strlen($text, 'UTF-8') > 200) { continue; }
        $level = null;
        if (preg_match('/^(#{1,6})\s+\S/u', $text, $match)) {
            $level = strlen($match[1]);
        } elseif (preg_match('/^(\d+(?:\.\d+)*)(?:\.)?\s+\S/u', $text, $match)
            && (str_contains($match[1], '.') || (int) $match[1] < 100)) {
            $level = substr_count($match[1], '.') + 1;
        } elseif (preg_match('/^(?:AS4|O2O)(?:[\s\-(]|$)/iu', $text)
            && !preg_match('/\b(?:används|ska|skall|måste|behöver|kräver|är|gäller)\b/iu', $text)) {
            $level = 1;
        } elseif (preg_match('/^(?:certifikats?begäran|certificate request|CSR)(?:\s*[:(]|$)/iu', $text)) {
            $level = 6;
        }
        if ($level !== null) {
            $headings[] = ['text' => $text, 'line' => $index + 1, 'offset_bytes' => $offset, 'level' => $level];
        }
    }
    return $headings;
}

function rag_context_line(string $content, int $offset): int
{
    return 1 + (preg_match_all('/\R/u', substr($content, 0, $offset)) ?: 0);
}

function rag_context_request(string $text): bool
{
    return preg_match('/certifikats?begäran|certificate\s+request|\bCSR\b|PKCS\s*#?\s*10/iu', $text) === 1;
}

/** A single occurrence's section frame must not inherit an unrelated later section. */
function rag_context_occurrence(array $chunk, string $content, string $matched, int $offset, array $headings): array
{
    $end = $offset + strlen($matched);
    $stack = [];
    foreach ($headings as $heading) {
        if ($heading['offset_bytes'] > $offset) { break; }
        while ($stack && end($stack)['level'] >= $heading['level']) { array_pop($stack); }
        $stack[] = $heading;
    }
    $start = $stack ? end($stack)['offset_bytes'] : 0;
    $sectionEnd = strlen($content);
    $currentLevel = $stack ? end($stack)['level'] : 0;
    foreach ($headings as $heading) {
        if ($heading['offset_bytes'] >= $end && ($currentLevel === 0 || $heading['level'] <= $currentLevel)) {
            $sectionEnd = $heading['offset_bytes'];
            break;
        }
    }
    $localBefore = substr($content, $start, $offset - $start);
    $localBefore = mb_substr($localBefore, -1500, null, 'UTF-8');
    $quoteKind = rag_context_kind($matched);
    $kind = $quoteKind;
    if ($kind === 'unknown') {
        foreach (array_reverse($stack) as $heading) {
            $candidate = rag_context_kind($heading['text']);
            if ($candidate !== 'unknown') { $kind = $candidate; break; }
        }
    }
    if ($kind === 'unknown') {
        // Read backwards so a nearby AS4 introduction wins over an earlier O2O paragraph.
        foreach (array_reverse(preg_split('/\R|(?<=[.!?])\s+/u', $localBefore) ?: []) as $part) {
            $candidate = rag_context_kind($part);
            if ($candidate !== 'unknown') { $kind = $candidate; break; }
        }
    }
    if ($kind === 'unknown') { $kind = rag_context_kind((string) ($chunk['title'] ?? '')); }
    $scope = $localBefore . "\n" . $matched;
    $headingText = implode("\n", array_column($stack, 'text'));
    $request = rag_context_request($matched) || rag_context_request($headingText)
        || preg_match('/(?:certifikats?begäran|certificate\s+request|\bCSR\b)[^.!?]{0,180}(?:ska|skall|krav|uppfyll|format)/iu', $localBefore) === 1;
    $actors = [];
    foreach (['accesspunktsoperatör', 'deltagarorganisation', 'organisationsadministratör', 'adressadministratör', 'användaradministratör', 'leverantör', 'Digg'] as $actor) {
        if (preg_match('/\b' . preg_quote($actor, '/') . '\p{L}*\b/iu', $scope)) { $actors[] = $actor; }
    }
    $quoteChar = mb_strlen(substr($content, 0, $offset), 'UTF-8');
    $endChar = mb_strlen(substr($content, 0, $end), 'UTF-8');
    $excerptStartChar = max(mb_strlen(substr($content, 0, $start), 'UTF-8'), $quoteChar - 1000);
    $excerptEndChar = min(mb_strlen(substr($content, 0, $sectionEnd), 'UTF-8'), $endChar + 1000);
    $excerptStart = strlen(mb_substr($content, 0, $excerptStartChar, 'UTF-8'));
    $excerptEnd = strlen(mb_substr($content, 0, $excerptEndChar, 'UTF-8'));
    return [
        'quote_offset_bytes' => $offset, 'quote_end_offset_bytes' => $end,
        'quote_line_start' => rag_context_line($content, $offset), 'quote_line_end' => rag_context_line($content, max($offset, $end - 1)),
        'excerpt_line_start' => rag_context_line($content, $excerptStart), 'excerpt_line_end' => rag_context_line($content, max($excerptStart, $excerptEnd - 1)),
        'excerpt' => substr($content, $excerptStart, $excerptEnd - $excerptStart),
        'section_headings' => array_values(array_map(static fn(array $h): array => ['text' => $h['text'], 'line' => $h['line']], $stack)),
        'certificate_kind' => $kind, 'request_type' => $request ? 'certificate_request' : ($kind !== 'unknown' ? 'certificate' : 'unknown'),
        'actor' => count($actors) === 1 ? $actors[0] : ($actors ? 'mixed' : 'unknown'), 'actors' => $actors,
    ];
}

/** Exact quote lookup permits whitespace differences and preserves byte/line provenance. */
function rag_evidence_context(array $chunk, string $quote): array
{
    $metadata = $chunk['metadata_json'] ?? [];
    if (is_string($metadata)) { $metadata = json_decode($metadata, true) ?: []; }
    if (!is_array($metadata)) { $metadata = []; }
    $result = ['context_version' => 1, 'matched' => false];
    foreach (['origin', 'environment', 'role', 'service'] as $key) {
        $value = $chunk[$key] ?? $metadata[$key] ?? '';
        $result[$key] = is_string($value) ? $value : '';
    }
    $content = $chunk['content'] ?? '';
    if (!is_string($content) || rag_context_space($quote) === '') { return $result; }
    $words = preg_split('/[\s\p{Z}]+/u', trim($quote), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $pattern = '/' . implode('[\s\p{Z}]+', array_map(static fn(string $part): string => preg_quote($part, '/'), $words)) . '/u';
    if (!$words || !preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) { return $result; }
    $headings = rag_context_headings($content);
    $occurrences = [];
    foreach (array_slice($matches[0], 0, 8) as [$matched, $offset]) {
        $occurrences[] = rag_context_occurrence($chunk, $content, $matched, $offset, $headings);
    }
    return array_merge($result, $occurrences[0], ['matched' => true, 'occurrence_count' => count($matches[0]), 'occurrences' => $occurrences]);
}

function rag_context_clauses(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|[;\n]+|\s+(?:men|däremot|dock)\s+/u', rag_context_lower($text)) ?: [])));
}

/** Negative source-limit statements are different from categorical negative rules. */
function rag_context_source_limit(string $clause): bool
{
    return preg_match('/^(?:(?:det\s+)?(?:framgår|går)\s+inte\b|(?:i\s+)?(?:den\s+)?(?:här\s+)?(?:filen|materialet|underlaget|käll(?:an|orna|texten|utdragen))\b.{0,70}(?:saknar|otillräcklig|oklar|inte\s+(?:anger|visar|fastställer|styrker|beskriver)|(?:anger|visar|fastställer|styrker|beskriver|ger|säger|innehåller)\s+inte))/u', $clause) === 1;
}

function rag_context_format_features(string $text): array
{
    $features=[];
    foreach(['pem'=>'/\bpem\b/iu','der'=>'/\bder\b/iu','pkcs10'=>'/pkcs\s*#?\s*10\b/iu','pkcs12'=>'/pkcs\s*#?\s*12\b/iu','pfx'=>'/\bpfx\b/iu','p12'=>'/\bp12\b/iu','rsa'=>'/\brsa\b/iu','ecc'=>'/\becc\b/iu'] as $key=>$pattern){
        if(preg_match($pattern,$text))$features[$key]=$pattern;
    }
    if(preg_match_all('/\b(\d{3,5})\s*(?:bit(?:ar|s)?|[- ]?bitars)\b/iu',$text,$sizes)){
        foreach($sizes[1] as $size)$features['bits:'.$size]='/\b'.preg_quote($size,'/').'\b/u';
    }
    return $features;
}

function rag_context_positive_format(string $clause): bool
{
    $clause = (string) preg_replace('/\binte\s+(?:bara|endast|enbart)\b/u', '', $clause);
    if (!preg_match('/\bo2o\b/u', $clause) || !rag_context_format_features($clause) || rag_context_source_limit($clause)) { return false; }
    // A negative relation before either endpoint also covers "PEM gäller AS4, inte O2O".
    if (preg_match('/\b(?:inte|ej|aldrig|varken)\b.{0,65}\bo2o\b|\bo2o\b.{0,100}\b(?:inte|ej|aldrig|okänt|oklart)\b.{0,55}(?:pem|der|pkcs|pfx|p12|rsa|ecc|\d{3,5})/u', $clause)) { return false; }
    return preg_match('/\b(?:ska|skall|måste|krävs|kräver|behöver|format|nyckellängd|konvertera|exportera|använd|välj|minst)\p{L}*\b/u', $clause) === 1;
}

function rag_context_absence(string $clause): bool
{
    return preg_match('/\b(?:finns|ställer|har)\s+(?:inget|inga|inte\s+något|inte\s+några)\s+(?:\p{L}+\s+){0,3}krav\b|\b(?:inget|inga)\s+(?:\p{L}+\s+){0,3}krav\s+(?:finns|gäller)\b|\b(?:krävs|kräver)\s+inte\b|\b(?:saknas)\s+(?:\p{L}+\s+){0,2}krav\b/u', $clause) === 1;
}

function rag_context_topics(string $text): array
{
    $stop = ['finns','ställer','kräver','krävs','krav','inget','inga','inte','något','några','alltid','endast','enbart','bara','enligt','eller','under','måste','skall','behöver','skulle','detta','dessa','denna','sådan','sådana','sådant','faktiskt','direkt','specifik','specifika','specifikt','generell','generella','generellt','regelverk','regelverket','materialet','underlaget'];
    $terms = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', rag_context_lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
        if (mb_strlen($term, 'UTF-8') < 4 || in_array($term, $stop, true)) { continue; }
        $terms[] = mb_substr($term, 0, 6, 'UTF-8');
    }
    return array_values(array_unique($terms));
}

function rag_context_benefits(string $text): array
{
    $kinds = [];
    foreach (rag_context_clauses($text) as $clause) {
        if (rag_context_source_limit($clause)) { continue; }
        $plain = (string) preg_replace('/\binte\s+(?:bara|endast|enbart)\b/u', '', $clause);
        if (preg_match('/\b(?:inte|ej|aldrig|ingen|inget|inga)\b.{0,60}(?:papper|effektiv)|(?:minskar|reducerar|effektiviserar)\s+inte\b/u', $plain)) { continue; }
        if (preg_match('/(?:minska|reducera|slipp|färre|mindre|spara)\p{L}*.{0,65}(?:papper|pappers)|(?:papper|pappers)\p{L}*.{0,45}(?:minska|reducera)/u', $plain)) { $kinds[] = 'paper'; }
        if (preg_match('/\beffektiviser\p{L}*\b|\beffektiv(?:are|t)?\s+(?:kommunikation|hantering|arbete|process|utbyte)\p{L}*\b|\b(?:ökar|förbättrar)\b.{0,40}\beffektivitet\p{L}*\b/u', $plain)) { $kinds[] = 'efficiency'; }
    }
    return array_values(array_unique($kinds));
}

/** Bounded checks for high-risk scope transfers; ordinary entailment remains a separate check. */
function rag_context_claim_reason(string $text, array $evidence): ?string
{
    $quotes = [];
    foreach ($evidence as $item) { if (is_array($item) && is_string($item['quote'] ?? null)) { $quotes[] = $item['quote']; } }
    foreach (rag_context_clauses($text) as $clause) {
        // A generic data-protection duty does not identify a particular legal act.
        if(preg_match('/\bgdpr\b|dataskyddsförordningen/u',$clause)){
            $named=false;
            foreach($evidence as $item){
                $namedText=rag_context_lower((string)($item['quote']??'').' '.(string)($item['title']??''));
                if(preg_match('/\bgdpr\b|dataskyddsförordningen/u',$namedText)){$named=true;break;}
            }
            if(!$named)return 'Namnet på en viss lag eller förordning måste ha stöd i belägget. Allmänna krav på dataskydd eller sekretess räcker inte för att ange GDPR som rättskälla.';
        }
        $digitalPost=false;
        foreach($evidence as $item){if(($item['service']??'')==='Mina meddelanden'||str_contains((string)($item['source_url']??''),'/digital-post')){$digitalPost=true;break;}}
        $reverse='/till\s+och\s+från\s+(?:invånar|medborgar)\p{L}*|(?:kommun\p{L}*|offentliga aktör\p{L}*).{0,100}(?:ta\s+emot|tar\s+emot|motta|mottar).{0,80}från\s+(?:invånar|medborgar)\p{L}*/u';
        if($digitalPost&&preg_match($reverse,$clause)&&!rag_context_source_limit($clause)
            &&!preg_match('/\b(?:inte|ej|aldrig)\b.{0,50}(?:ta\s+emot|tar\s+emot|till\s+och\s+från|motta)/u',$clause)){
            $reply=false;
            foreach($quotes as $quote){if(preg_match($reverse,rag_context_lower($quote))){$reply=true;break;}}
            if(!$reply)return 'Att kommunen kan skicka digital post till invånare visar inte att samma tjänst tar emot meddelanden eller svar från invånare tillbaka till kommunen.';
        }
        if(preg_match('/certifikat\p{L}*.{0,80}förvaltas\s+av\s+(?:kommun\p{L}*|deltagarorganisation\p{L}*)/u',$clause)
            && !preg_match('/\b(?:inte|kan|ibland|om)\b/u',$clause)){
            $management=false;
            foreach($quotes as $quote){
                $q=rag_context_lower($quote);
                if(preg_match('/förvaltas\s+av\s+(?:kommun\p{L}*|deltagarorganisation\p{L}*)|(?:kommun\p{L}*|deltagarorganisation\p{L}*).{0,50}förvaltar/u',$q)
                    && !preg_match('/\b(?:kan|ibland|vissa fall|om)\b/u',$q)){$management=true;break;}
            }
            if(!$management)return 'Ägande eller ansvar att säkerställa rätt certifikat visar inte att kommunen alltid sköter den praktiska förvaltningen. Belägg måste stödja vem som faktiskt förvaltar certifikatet.';
        }
        if (rag_context_positive_format($clause)) {
            $features = rag_context_format_features($clause);
            $supported = [];
            foreach ($evidence as $item) {
                $quote = (string) ($item['quote'] ?? '');
                $context = $item['context'] ?? rag_evidence_context(['content' => $quote, 'title' => $item['title'] ?? ''], $quote);
                if (!is_array($context) || empty($context['matched'])) { continue; }
                $frames = $context['occurrences'] ?? [$context];
                foreach ($frames as $frame) {
                    if (($frame['certificate_kind'] ?? '') !== 'O2O') { continue; }
                    if (($frame['request_type'] ?? '') === 'certificate_request' && !rag_context_request($clause)) { continue; }
                    foreach ($features as $feature=>$pattern) {
                        if (preg_match($pattern, $quote)) { $supported[$feature] = true; }
                    }
                }
            }
            if (count($supported) < count($features)) {
                return 'Format- eller nyckellängdskravet för O2O saknar stöd i ett O2O-avsnitt. Krav på AS4-certifikatsbegäran från en accesspunktsoperatör får inte överföras till O2O-certifikatet.';
            }
        }
        if (rag_context_absence($clause) && !rag_context_source_limit($clause)) {
            $supported = false;
            foreach ($quotes as $quote) {
                foreach (rag_context_clauses($quote) as $quotedClause) {
                    $negativeRule = rag_context_absence($quotedClause) || preg_match('/\breglerar\s+inte\b|\bvalfria?\s+leverantör/u', $quotedClause);
                    if ($negativeRule && array_intersect(rag_context_topics($clause), rag_context_topics($quotedClause))) { $supported = true; break 2; }
                }
            }
            if (!$supported) { return 'Att källan inte beskriver ett krav bevisar inte att kravet saknas. Avgränsa vad underlaget visar, eller citera ett uttryckligt stöd för det påstådda undantaget.'; }
        }
        if (preg_match('/\b(?:nuvarande|befintliga?)\s+leverantör\p{L}*\b/u', $clause)
            && preg_match('/\b(?:kräva|måste|skyldig|ska|skall)\b/u', $clause)
            && !preg_match('/\b(?:inte|ej)\b.{0,25}\b(?:kräva|måste|skyldig|ska|skall)\b/u', $clause)) {
            $contractSupport = false;
            foreach ($quotes as $quote) {
                $q = rag_context_lower($quote);
                if (preg_match('/\b(?:avtal|kontrakt|nuvarande|befintliga?)\p{L}*\b/u', $q)
                    && preg_match('/\bleverantör\p{L}*\b/u', $q)
                    && preg_match('/\b(?:skyldig|måste|åtar|förbinder|ska|skall)\b/u', $q)
                    && !preg_match('/\b(?:kan|bör|tänkas|vid upphandling)\b/u', $q)) { $contractSupport = true; break; }
            }
            if (!$contractSupport) { return 'Allmänna råd om kravställning eller integration visar inte vad den befintliga leverantören är skyldig att leverera enligt kommunens avtal.'; }
        }
    }
    $claimedBenefits = rag_context_benefits($text);
    if ($claimedBenefits) {
        $sourceBenefits = [];
        foreach ($quotes as $quote) { $sourceBenefits = array_merge($sourceBenefits, rag_context_benefits($quote)); }
        if (array_diff($claimedBenefits, $sourceBenefits)) { return 'Påståendet om mindre pappershantering eller effektivare arbete behöver ett källutdrag som beskriver just nyttan; en allmän tjänstebeskrivning eller enkel anslutningsprocess räcker inte.'; }
    }
    return null;
}
