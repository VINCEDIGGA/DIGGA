<?php
declare(strict_types=1);

/** Offline phpMyAdmin package: only curated public sources, never a runtime database dump. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../api/sdk_lib.php';

function student_sql_text(string $text): string
{
    // Hex literals do not depend on NO_BACKSLASH_ESCAPES or client quote handling.
    return "CONVERT(0x" . bin2hex($text) . " USING utf8mb4)";
}

$target = $argv[1] ?? (__DIR__ . '/../sdk_sources_student.sql');
$lines = [
    '-- DIGGA public SDK source catalogue. Generated from checked-in snapshots, not a database dump.',
    '-- Import schema_upgrade_student.sql first in your selected course database.',
    '-- 27 public source snapshots and curated metadata; no private cases, uploads, keys or certificates.',
    '-- Idempotent upserts update only these official catalogue URLs and stable SDK chunk keys.',
    'SET NAMES utf8mb4;', 'START TRANSACTION;',
];
$sources = 0; $count = 0;
foreach (sdk_manifest()['sources'] as $source) {
    if (($source['status'] ?? '') !== 'downloaded') { continue; }
    if (!in_array(parse_url($source['url'], PHP_URL_HOST), ['www.digg.se', 'open-test.digg.se'], true)
        || parse_url($source['url'], PHP_URL_SCHEME) !== 'https'
        || !preg_match('/^[a-z0-9-]+$/D', $source['id'])) {
        throw new RuntimeException('Unexpected source identifier or non-public source URL.');
    }
    $snapshot = trim((string) file_get_contents(__DIR__ . '/snapshots/' . $source['id'] . '.txt'));
    $header = $source['title'] . "\nTjänst: " . $source['service'] . ' | Roll: ' . $source['role'] . ' | Miljö: ' . $source['environment'] . ' | Version: ' . ($source['version'] ?? 'ej angiven') . ' | Källkontroll: ' . $source['last_checked'] . "\n";
    $points = "Verifierade huvudpunkter:\n- " . implode("\n- ", $source['key_points']) . "\n";
    $values = array_map('student_sql_text', [$source['title'], $source['service'], $source['url'], $header . $points . "\nOfficiellt sidinnehåll:\n" . $snapshot]);
    $lines[] = 'INSERT INTO reference_documents (title,service,source_url,content) VALUES (' . implode(',', $values) . ') ON DUPLICATE KEY UPDATE title=VALUES(title),service=VALUES(service),content=VALUES(content);';
    $chunks = [$points]; $current = '';
    foreach (explode("\n", $snapshot) as $line) {
        if (mb_strlen($current . $line) > 2600 && $current !== '') { $chunks[] = $current; $current = ''; }
        if (mb_strlen($line) > 2600) {
            if ($current !== '') { $chunks[] = $current; $current = ''; }
            for ($i = 0; $i < mb_strlen($line); $i += 2400) { $chunks[] = mb_substr($line, $i, 2600); }
        } else { $current .= $line . "\n"; }
    }
    if (trim($current) !== '') { $chunks[] = $current; }
    foreach ($chunks as $number => $chunk) {
        $content = $header . trim($chunk);
        $values = [student_sql_text($source['id']), (string) $number];
        foreach ([$source['title'], $source['service'], $source['url'], $content, json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), hash('sha256', $content)] as $value) { $values[] = student_sql_text($value); }
        $lines[] = 'INSERT INTO sdk_source_chunks (source_id,chunk_number,title,service,source_url,content,metadata_json,content_hash) VALUES (' . implode(',', $values) . ') ON DUPLICATE KEY UPDATE title=VALUES(title),service=VALUES(service),source_url=VALUES(source_url),content=VALUES(content),metadata_json=VALUES(metadata_json),content_hash=VALUES(content_hash);';
        $count++;
    }
    $sources++;
}
$lines[] = 'COMMIT;';
$lines[] = '-- Expected for this packaged revision: ' . $sources . ' distinct SDK sources, ' . $count . ' SDK chunks.';
$sql = implode("\n", $lines) . "\n";
if (file_put_contents($target, $sql) === false) { throw new RuntimeException('Could not write SQL export.'); }
if (function_exists('gzencode')) {
    if (file_put_contents($target . '.gz', gzencode($sql, 9)) === false) { throw new RuntimeException('Could not write compressed SQL export.'); }
}
echo json_encode(['sources' => $sources, 'chunks' => $count, 'output' => $target, 'gzip' => function_exists('gzencode')], JSON_UNESCAPED_SLASHES) . "\n";
