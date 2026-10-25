<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Validate network and cached responses alike. Invalid JSON must not poison the cache.
function sdk_addressbook_decode($raw): ?array
{
    if (!is_string($raw) || strlen($raw) > 1500000) { return null; }
    // Associative decoding alone turns both [] and {} into an empty PHP array.
    $document = json_decode($raw);
    if (!is_object($document) || !isset($document->data) || !is_array($document->data)) { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || !array_is_list($data['data'])) { return null; }
    foreach ($data['data'] as $row) {
        if (!is_array($row) || !isset($row['id'], $row['attributes']) || !is_string($row['id']) || $row['id'] === '' || !is_array($row['attributes'])) { return null; }
    }
    return $data;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); respond(['error' => 'Endast GET stöds.'], 405); }
    foreach (['environment', 'type', 'q', 'organization_id'] as $field) {
        if (isset($_GET[$field]) && !is_string($_GET[$field])) { respond(['error' => 'Sökparametrarna måste vara text.'], 422); }
    }
    $environment = $_GET['environment'] ?? 'open-test';
    if ($environment !== 'open-test') { respond(['error' => 'Prototypen använder endast den publika OPEN-TEST-adressboken.'], 422); }
    $type = $_GET['type'] ?? 'organizations';
    $q = trim($_GET['q'] ?? '');
    if (!mb_check_encoding($q, 'UTF-8') || mb_strlen($q) > 100 || preg_match('/[\x00-\x1f\x7f]/', $q)) { respond(['error' => 'Söktexten är ogiltig eller längre än 100 tecken.'], 422); }
    $params = ['page[size]' => 20];
    if ($type === 'organizations') {
        $path = '/organizations/search';
        if ($q !== '') { $params['q'] = $q; }
    } elseif ($type === 'addresses') {
        $id = $_GET['organization_id'] ?? '';
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) !== 1) { respond(['error' => 'Välj en organisation med ett giltigt adressboks-ID.'], 422); }
        $path = '/organizations/' . rawurlencode($id) . '/addresses';
    } else { respond(['error' => 'Välj organizations eller addresses.'], 422); }
    // Only these read-only routes on this fixed HTTPS host are permitted.
    $url = 'https://open-test.digg.se/addressbook/api' . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $cacheDir = sys_get_temp_dir() . '/digga-sdk-addressbook';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0700, true); }
    $cache = $cacheDir . '/' . hash('sha256', $url) . '.json';
    $modified = is_file($cache) ? filemtime($cache) : false;
    $age = $modified !== false ? time() - $modified : PHP_INT_MAX;
    $saved = $age >= 0 && $age < 3600 && @filesize($cache) <= 1500000 ? sdk_addressbook_decode(@file_get_contents($cache)) : null;
    $cached = $saved !== null && $age < 300;
    $stale = false;
    $result = $cached ? $saved : null;
    $fetchedAt = $cached ? (int) $modified : time();
    if (!$cached) {
        $errorCode = 'upstream_unavailable'; $status = 0;
        $trust = __DIR__ . '/../data/sdk/vendor/trust/open-test-ca.pem';
        if (!function_exists('curl_init')) { $errorCode = 'missing_curl'; }
        elseif (!is_readable($trust)) { $errorCode = 'missing_trust_bundle'; }
        else {
            $ch = curl_init($url); $body = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
                // OPEN-TEST currently omits its intermediate certificate. Supply its verified
                // public CA chain locally, while retaining peer and hostname verification.
                CURLOPT_CAINFO => $trust, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: sv-SE'],
                CURLOPT_USERAGENT => 'DIGGA student PoC public read',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1500000) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $errno = curl_errno($ch);
            curl_close($ch);
            if ($ok !== false && ($status === 200 || $status === 204)) {
                $raw = $status === 204 ? '{"data":[],"meta":{}}' : $body;
                $result = sdk_addressbook_decode($raw);
                if ($result !== null) { @file_put_contents($cache, $raw, LOCK_EX); }
                else { $errorCode = 'invalid_upstream_response'; }
            } elseif ($errno === 60 || $errno === 51 || $errno === 77) { $errorCode = 'tls_verification_failed'; }
            elseif ($errno === 28) { $errorCode = 'upstream_timeout'; }
            elseif ($errno === 6) { $errorCode = 'upstream_dns_failed'; }
            elseif ($status !== 0) { $errorCode = 'upstream_http_error'; }
        }
        if ($result === null && $saved !== null) {
            $result = $saved; $cached = true; $stale = true; $fetchedAt = (int) $modified;
        } elseif ($result === null) {
            $messages = [
                'missing_curl' => 'Sökningen behöver PHP cURL på servern.',
                'missing_trust_bundle' => 'Sökningens certifikatfil saknas. Ladda upp hela adressboksuppdateringen.',
                'tls_verification_failed' => 'Den säkra anslutningen till Diggs testadressbok kunde inte verifieras. Kontrollera adressboksuppdateringen på servern.',
                'upstream_timeout' => 'Diggs testadressbok svarade inte i tid. Försök igen.',
                'invalid_upstream_response' => 'Diggs testadressbok gav ett svar som inte kunde läsas. Försök igen senare.',
            ];
            respond(['error' => $messages[$errorCode] ?? 'Diggs publika testadressbok kan inte nås just nu. Försök igen senare.', 'error_code' => $errorCode, 'upstream_status' => $status, 'source_url' => $url, 'environment' => $environment], 502);
        }
    }
    $result['source_url'] = $url; $result['environment'] = $environment; $result['cached'] = $cached; $result['stale'] = $stale;
    $result['fetched_at'] = gmdate('c', $fetchedAt);
    $result['notice'] = 'OPEN-TEST innehåller testdata. Detta är en publik läsning; ingen adress registreras och inget meddelande skickas.';
    respond($result);
} catch (Throwable $e) { respond(['error' => 'Adressboksuppslaget kunde inte slutföras.', 'error_code' => 'internal_error'], 500); }
