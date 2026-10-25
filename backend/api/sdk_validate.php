<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sdk_lib.php';
try {
    require_post();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2200000) { respond(['error' => 'Begäran är för stor. Maximal textstorlek är 2 MB.'], 413); }
    $input = json_input(); $content = (string) ($input['content'] ?? '');
    $format = (string) ($input['format'] ?? '');
    if ($format === 'xml' && (!class_exists('DOMDocument') || !class_exists('DOMXPath') || !function_exists('libxml_use_internal_errors'))) {
        respond(['error' => 'XML-kontrollen behöver PHP-tillägget DOM/libxml på skolservern. Be serveransvarig aktivera det. JSON och OpenAPI kan fortfarande kontrolleras.', 'missing_capability' => 'dom_libxml'], 503);
    }
    if ($format === 'certificate' && (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_details'))) {
        respond(['error' => 'Certifikatkontrollen behöver PHP-tillägget OpenSSL på servern.', 'missing_capability' => 'openssl'], 503);
    }
    if (isset($input['asset_id']) && $content === '') {
        $asset = sdk_asset((string) $input['asset_id']);
        if (!$asset) { respond(['error' => 'Exemplet finns inte.'], 404); }
        $content = sdk_asset_content($asset);
    }
    // Validation is intentionally stateless. In particular no certificate or key is logged/persisted.
    respond(sdk_validate($format, $content));
} catch (InvalidArgumentException $e) { respond(['error' => $e->getMessage()], 422); }
catch (Throwable $e) { respond(['error' => 'Förhandskontrollen kunde inte slutföras. Kontrollera format och serverns DOM/OpenSSL-stöd.'], 500); }
