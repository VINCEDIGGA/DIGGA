<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sdk_lib.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { respond(['error' => 'Endast GET stöds.'], 405); }
    $m = sdk_manifest();
    if (!empty($_GET['asset_id'])) {
        $asset = sdk_asset((string) $_GET['asset_id']);
        if (!$asset) { respond(['error' => 'Exemplet finns inte.'], 404); }
        respond(['asset' => $asset, 'content' => sdk_asset_content($asset)]);
    }
    // phpMyAdmin imports cannot create the local index_status.json marker. Read actual DB state.
    $index = [];
    try {
        $config = require __DIR__ . '/config.php';
        $settings = $config['db'];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $settings['host'], $settings['port'], $settings['name']);
        $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $row = $pdo->query('SELECT COUNT(DISTINCT source_id) AS indexed_sources, COUNT(*) AS indexed_chunks, MAX(updated_at) AS imported_at FROM sdk_source_chunks')->fetch();
        if ($row && (int) $row['indexed_chunks'] > 0) { $index = $row; }
    } catch (Throwable $ignored) { /* The public file catalogue remains usable before DB setup. */ }
    $m['stats'] = ['sources' => count($m['sources']), 'assets' => count($m['assets']), 'snapshots' => count(array_filter($m['sources'], static fn(array $s): bool => $s['status'] === 'downloaded')), 'indexed_chunks' => (int) ($index['indexed_chunks'] ?? 0), 'indexed_sources' => (int) ($index['indexed_sources'] ?? 0), 'imported_at' => $index['imported_at'] ?? null, 'index_status' => $index ? 'imported' : 'not_imported'];
    respond($m);
} catch (Throwable $e) { respond(['error' => 'Källkatalogen kunde inte läsas.'], 500); }
