<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../api/db.php';
require_once __DIR__ . '/../../api/sdk_lib.php';

/** Idempotent local import. One original document per URL; chunks have their own stable key. */
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS sdk_source_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id VARCHAR(80) NOT NULL,
    chunk_number INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    service VARCHAR(80) NOT NULL,
    source_url VARCHAR(700) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    metadata_json JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sdk_chunk (source_id, chunk_number),
    FULLTEXT KEY ft_sdk_chunk (title,service,content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$manifest = sdk_manifest(); $count = 0; $sources = 0;
$documentStmt = $pdo->prepare('INSERT INTO reference_documents (title, service, source_url, content) VALUES (:title,:service,:url,:content) ON DUPLICATE KEY UPDATE title=VALUES(title),service=VALUES(service),content=VALUES(content)');
$chunkStmt = $pdo->prepare('INSERT INTO sdk_source_chunks (source_id,chunk_number,title,service,source_url,content,metadata_json,content_hash) VALUES (:sid,:n,:title,:service,:url,:content,:metadata,:hash) ON DUPLICATE KEY UPDATE title=VALUES(title),service=VALUES(service),source_url=VALUES(source_url),content=VALUES(content),metadata_json=VALUES(metadata_json),content_hash=VALUES(content_hash)');
$pdo->beginTransaction();
try {
    foreach ($manifest['sources'] as $s) {
        if (($s['status'] ?? '') !== 'downloaded') { continue; }
        $path = __DIR__ . '/snapshots/' . $s['id'] . '.txt';
        if (!is_file($path)) { continue; }
        $snapshot = trim((string) file_get_contents($path));
        $header = $s['title'] . "\nTjänst: " . $s['service'] . ' | Roll: ' . $s['role'] . ' | Miljö: ' . $s['environment'] . ' | Version: ' . ($s['version'] ?? 'ej angiven') . ' | Källkontroll: ' . $s['last_checked'] . "\n";
        $points = "Verifierade huvudpunkter:\n- " . implode("\n- ", $s['key_points']) . "\n";
        $documentStmt->execute([':title'=>$s['title'],':service'=>$s['service'],':url'=>$s['url'],':content'=>$header.$points."\nOfficiellt sidinnehåll:\n".$snapshot]);
        $chunks = [$points]; $current = '';
        foreach (explode("\n", $snapshot) as $line) {
            if (mb_strlen($current . $line) > 2600 && $current !== '') { $chunks[] = $current; $current = ''; }
            if (mb_strlen($line) > 2600) {
                if ($current !== '') { $chunks[]=$current; $current=''; }
                for ($i=0;$i<mb_strlen($line);$i+=2400) { $chunks[]=mb_substr($line,$i,2600); }
            } else { $current .= $line . "\n"; }
        }
        if (trim($current) !== '') { $chunks[] = $current; }
        foreach ($chunks as $n => $chunk) {
            $content = $header . trim($chunk);
            $chunkStmt->execute([':sid'=>$s['id'],':n'=>$n,':title'=>$s['title'],':service'=>$s['service'],':url'=>$s['url'],':content'=>$content,':metadata'=>json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':hash'=>hash('sha256',$content)]);
            $count++;
        }
        $delete = $pdo->prepare('DELETE FROM sdk_source_chunks WHERE source_id=:sid AND chunk_number>=:n');
        $delete->execute([':sid'=>$s['id'],':n'=>count($chunks)]); $sources++;
    }
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
$status = ['indexed_sources'=>$sources,'indexed_chunks'=>$count,'imported_at'=>gmdate('c'),'retrieval'=>'Local relevant passage and source ranking from MySQL chunks; no external embedding calls'];
file_put_contents(__DIR__.'/index_status.json',json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
