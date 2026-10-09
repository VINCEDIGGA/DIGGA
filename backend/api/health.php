<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
$database = [
    'configured' => !empty($config['db']['name']) && !empty($config['db']['user']),
    'connected' => false,
    'tables' => [
        'reference_documents' => false,
        'document_embeddings' => false,
        'guide_step_context' => false,
        'case_attachments' => false,
    ],
];

try {
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'],
        $db['name']
    );
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->query('SELECT 1');
    $database['connected'] = true;
    foreach (array_keys($database['tables']) as $tableName) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = :schema_name AND table_name = :table_name'
        );
        $stmt->execute([
            ':schema_name' => $db['name'],
            ':table_name' => $tableName,
        ]);
        $database['tables'][$tableName] = ((int) $stmt->fetchColumn()) > 0;
    }
} catch (Throwable $error) {
    $database['error'] = 'Database connection failed.';
    $database['detail'] = $error->getMessage();
}

respond([
    'ok' => $database['connected']
        && $database['tables']['reference_documents'],
    'php' => [
        'version' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
    ],
    'database' => $database,
    'llm' => [
        'configured' => !empty($config['llm']['api_key'])
            && $config['llm']['api_key'] !== 'PASTE_YOUR_MISTRAL_KEY_HERE',
        'model' => $config['llm']['model'] ?? null,
    ],
]);
