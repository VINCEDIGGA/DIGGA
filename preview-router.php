<?php
declare(strict_types=1);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.git[^/]*|\.env[^/]*|local\.secrets\.php|config\.php|.*\.log)(?:/|$)~i', $path)
    || strpos($path, '/backend/scripts/') === 0) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
header('X-Content-Type-Options: nosniff');
if (preg_match('/\.(?:html|js|css)$/', $path) || $path === '/') {
    header('Cache-Control: no-cache');
}
return false;
