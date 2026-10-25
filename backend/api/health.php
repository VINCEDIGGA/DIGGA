<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
$compatibility = digga_runtime_compatibility();
$textAvailable = function_exists('mb_strlen') && function_exists('mb_substr')
    && function_exists('mb_strtolower') && function_exists('mb_strpos');
$phpSupported = PHP_VERSION_ID >= 80100;
$capabilities = [
    'text_processing' => $textAvailable,
    'json_validation' => $textAvailable && function_exists('json_decode'),
    'xml_validation' => $textAvailable && class_exists('DOMDocument')
        && class_exists('DOMXPath') && function_exists('libxml_use_internal_errors'),
    'openapi_validation' => function_exists('ctype_digit') && function_exists('ctype_alpha'),
    'certificate_validation' => function_exists('openssl_x509_read')
        && function_exists('openssl_pkey_get_details'),
];
$warnings = [];
if (!$phpSupported) $warnings[] = 'PHP 8.1 or newer is required.';
if (!$textAvailable) $warnings[] = 'UTF-8 text support is unavailable. Upload the complete bundled compatibility files.';
if (!$capabilities['xml_validation']) $warnings[] = 'XML validation needs PHP DOM/libxml on the host. Other file types can still be checked.';
if (!$capabilities['certificate_validation']) $warnings[] = 'Public certificate checks need the PHP OpenSSL extension.';
if (!function_exists('curl_init')) $warnings[] = 'External AI requests and address-book lookup need the PHP cURL extension.';
$database = [
    'configured' => !empty($config['db']['name']) && !empty($config['db']['user']),
    'connected' => false,
    'tables' => [
        'reference_documents' => false,
        'document_embeddings' => false,
        'guide_step_context' => false,
        'case_attachments' => false,
        'reformat_jobs' => false,
        'sdk_source_chunks' => false,
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
        && $database['tables']['reference_documents']
        && $phpSupported && $textAvailable,
    'php' => [
        'version' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'minimum_version' => '8.1',
        'supported' => $phpSupported,
        'mbstring' => extension_loaded('mbstring'),
        'mbstring_mode' => $compatibility['mbstring']['native'] ? 'native' : ($textAvailable ? 'bundled_polyfill' : 'unavailable'),
        'dom' => class_exists('DOMDocument') && class_exists('DOMXPath'),
        'openssl' => extension_loaded('openssl'),
        'compatibility' => $compatibility,
    ],
    'capabilities' => $capabilities,
    'warnings' => $warnings,
    'database' => $database,
    'llm' => [
        'configured' => !empty($config['llm']['api_key'])
            && $config['llm']['api_key'] !== 'PASTE_YOUR_MISTRAL_KEY_HERE',
        'model' => $config['llm']['grounded_model'] ?? $config['llm']['model'] ?? null,
        'base_model' => $config['llm']['model'] ?? null,
    ],
]);
