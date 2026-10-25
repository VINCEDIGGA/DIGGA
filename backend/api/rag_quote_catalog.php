<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_compat.php';

/** Candidate boundaries always refer to the original UTF-8 bytes, never rewritten text. */
function rag_quote_span(string $content, int $start, int $end): ?array
{
    $raw = substr($content, $start, $end - $start);
    if (!preg_match('/\A[\s\p{Z}]*(.*?)[\s\p{Z}]*\z/su', $raw, $match, PREG_OFFSET_CAPTURE)) { return null; }
    $quote = $match[1][0];
    $length = mb_strlen($quote, 'UTF-8');
    $plain = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $quote));
    if ($length < 35 || $length > 650 || mb_strlen($plain, 'UTF-8') < 35 || !preg_match('/\p{L}/u', $quote)) { return null; }
    $start += $match[1][1];
    return ['quote' => $quote, 'start' => $start, 'end' => $start + strlen($quote)];
}

/** Rank factual body text above navigation, metadata and standalone section labels. */
function rag_quote_score(string $quote): int
{
    $plain = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $quote));
    $score = 0;
    if (preg_match('/[.!?][”»"\')\]]*$/u', $plain)) { $score += 3; }
    if (preg_match('/\b(?:är|innebär|använd\p{L}*|ansvara\p{L}*|ska|skall|måste|behöver|kräv\p{L}*|registr\p{L}*|administr\p{L}*|tilldela\p{L}*|tillhandahåll\p{L}*|lämna\p{L}*|publicer\p{L}*|skicka\p{L}*|hanter\p{L}*|identifier\p{L}*|har\s+rätt|tar\s+emot|is|means|must|shall)\b/iu', $plain)) { $score += 7; }
    if (preg_match('/\b(?:(?:organisations|adress|användar)administratör\p{L}*|accesspunktsoperatör\p{L}*|deltagarorganisation\p{L}*|leverantör\p{L}*|Digg)\b/iu', $plain)) { $score += 4; }
    if (preg_match('/\b(?:O2O|AS4|SMP|funktionsadress\p{L}*|deltagarident\p{L}*|certifikat\p{L}*|conversationId|messageId)\b/iu', $plain)) { $score += 2; }
    if (preg_match('/^(?:https?:\/\/|www\.|(?:Källa|URL|Version|Senast uppdaterad|Läs mer|Se även|Innehållsförteckning)\s*:?)/iu', $plain)) { $score -= 16; }
    if (!preg_match('/\s/u', $plain) || preg_match('/^(?:#{1,6}\s|\d+(?:\.\d+)*\.?\s)/u', $plain) && $score < 7) { $score -= 10; }
    return $score;
}

/** Build bounded choices from sentences, source lines and adjoining source spans. */
function rag_quote_candidates(string $content): array
{
    $candidates = [];
    $add = static function (int $start, int $end, int $bonus = 0) use ($content, &$candidates): void {
        $span = rag_quote_span($content, $start, $end);
        if ($span === null) { return; }
        $key = $span['start'] . ':' . $span['end'];
        $score = rag_quote_score($span['quote']) + $bonus;
        if (!isset($candidates[$key]) || $score > $candidates[$key]['score']) {
            $candidates[$key] = $span + ['score' => $score];
        }
    };
    $lines = preg_split('/\R/u', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
    $nonempty = [];
    foreach ($lines as [$line, $offset]) {
        if (trim($line) === '') { continue; }
        $nonempty[] = ['text' => $line, 'start' => $offset, 'end' => $offset + strlen($line)];
        $add($offset, $offset + strlen($line), 1);
        // A semicolon is part of its sentence. Decimal IDs and URLs are not stops.
        $parts = preg_split('/(?<=[.!?])(?:[\s\p{Z}]+)(?=[\p{Lu}\p{N}“„”«"\'])/u', $line, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        foreach ($parts as $index => [$sentence, $relative]) {
            $add($offset + $relative, $offset + $relative + strlen($sentence), 4);
            if (isset($parts[$index + 1])) {
                [$next, $nextOffset] = $parts[$index + 1];
                $add($offset + $relative, $offset + $nextOffset + strlen($next), 2);
            }
        }
    }
    foreach ($nonempty as $index => $line) {
        // Keep original separators and bullet markers when adjoining list items.
        $start = $line['start'];
        for ($nextIndex = $index + 1; $nextIndex < min(count($nonempty), $index + 4); $nextIndex++) {
            $next = $nonempty[$nextIndex];
            $raw = substr($content, $start, $next['end'] - $start);
            if (mb_strlen($raw, 'UTF-8') > 650 || preg_match('/\R[\s\p{Z}]*\R/u', substr($content, $nonempty[$nextIndex - 1]['end'], $next['start'] - $nonempty[$nextIndex - 1]['end']))) { break; }
            $label = mb_strlen(trim($line['text']), 'UTF-8') <= 100
                && !preg_match('/[.!?]$/u', trim($line['text'])) && rag_quote_score($line['text']) < 7;
            $add($start, $next['end'], $label && $nextIndex === $index + 1 ? 5 : -1);
        }
    }
    // Preserve whole small paragraphs as additional context, without concatenating text.
    foreach (preg_split('/\R[\s\p{Z}]*\R/u', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$paragraph, $offset]) {
        $add($offset, $offset + strlen($paragraph));
    }
    $ranked = array_values($candidates);
    usort($ranked, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
    $selected = []; $seenText = [];
    $choose = static function (array $candidate) use (&$selected, &$seenText): void {
        $textKey = hash('sha256', $candidate['quote']);
        if (isset($seenText[$textKey])) { return; }
        $seenText[$textKey] = true;
        $selected[$candidate['start'] . ':' . $candidate['end']] = $candidate;
    };
    $last = null;
    foreach ($ranked as $candidate) {
        if ($candidate['score'] < 3) { continue; }
        if ($last === null || $candidate['end'] > $last['end'] || $candidate['end'] === $last['end'] && strlen($candidate['quote']) < strlen($last['quote'])) { $last = $candidate; }
    }
    if ($last !== null) { $choose($last); }
    // Reserve body coverage before filling by rank; late definitions must remain selectable.
    $bytes = max(1, strlen($content));
    for ($region = 0; $region < 8; $region++) {
        foreach ($ranked as $candidate) {
            if ($candidate['score'] < 0) { continue; }
            if (min(7, (int) floor($candidate['start'] * 8 / $bytes)) === $region) { $choose($candidate); break; }
        }
    }
    foreach ($ranked as $candidate) {
        if (count($selected) >= 24) { break; }
        if ($candidate['score'] >= 0) { $choose($candidate); }
    }
    $selected = array_values($selected);
    usort($selected, static fn(array $a, array $b): int => ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
    return $selected;
}

/** Catalogs use only selected source IDs and at most 5000 original Unicode characters. */
function rag_quote_catalog(array $chunks): array
{
    $catalog = [];
    foreach ($chunks as $chunk) {
        if (!is_array($chunk) || !is_string($chunk['chunk_id'] ?? null) || $chunk['chunk_id'] === '' || !is_string($chunk['content'] ?? null)) { continue; }
        $id = $chunk['chunk_id'];
        if (array_key_exists($id, $catalog)) { continue; }
        $catalog[$id] = [];
        $content = mb_substr($chunk['content'], 0, 5000, 'UTF-8');
        foreach (rag_quote_candidates($content) as $candidate) {
            $quoteId = 'q-' . substr(hash('sha256', $id . "\0" . $candidate['start'] . "\0" . $candidate['quote']), 0, 24);
            $catalog[$id][] = [
                'quote_id' => $quoteId, 'quote' => $candidate['quote'],
                'context' => ['offset_bytes' => $candidate['start'], 'end_offset_bytes' => $candidate['end']],
            ];
        }
    }
    return $catalog;
}

/** Supply the full prepared context as well as server-owned quote choices to the model. */
function rag_quote_source_context(array $chunks): array
{
    $catalog = rag_quote_catalog($chunks);
    $sources = []; $seen = [];
    foreach ($chunks as $chunk) {
        if (!is_array($chunk) || !is_string($chunk['chunk_id'] ?? null) || !isset($catalog[$chunk['chunk_id']]) || isset($seen[$chunk['chunk_id']])) { continue; }
        $id = $chunk['chunk_id']; $seen[$id] = true;
        $sources[] = [
            'chunk_id' => $id, 'source_id' => $chunk['source_id'] ?? '', 'title' => $chunk['title'] ?? '',
            'service' => $chunk['service'] ?? '', 'origin' => $chunk['origin'] ?? 'imported_source',
            'source_url' => $chunk['source_url'] ?? '', 'content' => mb_substr($chunk['content'], 0, 5000, 'UTF-8'),
            'quote_catalog' => $catalog[$id],
        ];
    }
    return $sources;
}

/** Resolve IDs before provenance validation. An invalid ID can never fall back to supplied prose. */
function rag_resolve_quote_references(array $draft, array $chunks): array
{
    $lookup = [];
    foreach (rag_quote_catalog($chunks) as $chunkId => $items) {
        foreach ($items as $item) { $lookup[$chunkId][$item['quote_id']] = $item['quote']; }
    }
    if (!is_array($draft['claims'] ?? null)) { return $draft; }
    foreach ($draft['claims'] as &$claim) {
        if (!is_array($claim) || !is_array($claim['evidence'] ?? null)) { continue; }
        foreach ($claim['evidence'] as &$evidence) {
            if (!is_array($evidence) || !array_key_exists('quote_id', $evidence)) { continue; }
            $chunkId = $evidence['chunk_id'] ?? null;
            $quoteId = $evidence['quote_id'];
            $quote = is_string($chunkId) && is_string($quoteId) ? ($lookup[$chunkId][$quoteId] ?? '') : '';
            $evidence = ['chunk_id' => $chunkId, 'quote_id' => $quoteId, 'quote' => $quote];
        }
        unset($evidence);
    }
    unset($claim);
    return $draft;
}
