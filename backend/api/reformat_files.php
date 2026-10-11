<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'templates') {
        respond(['templates' => array_values(reformat_templates())]);
    }

    ensure_reformat_schema();

    if ($method === 'GET') {
        handle_get_reformat();
    }
    if ($method === 'POST') {
        handle_post_reformat();
    }
    if ($method === 'DELETE') {
        handle_delete_reformat_jobs();
    }

    respond(['error' => 'Metoden stöds inte.'], 405);
} catch (Throwable $error) {
    respond([
        'error' => 'Kunde inte hantera reformatering.',
        'detail' => $error->getMessage(),
    ], 500);
}

function ensure_reformat_schema()
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS reformat_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(120) NOT NULL,
            attachment_id BIGINT UNSIGNED NULL,
            template_key VARCHAR(80) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            detected_format VARCHAR(40) NOT NULL,
            source_preview MEDIUMTEXT NULL,
            reformatted_name VARCHAR(255) NOT NULL,
            reformatted_text MEDIUMTEXT NOT NULL,
            issues_json JSON NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_reformat_jobs_case (case_id),
            INDEX idx_reformat_jobs_attachment (attachment_id),
            INDEX idx_reformat_jobs_status (status),
            INDEX idx_reformat_jobs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function handle_get_reformat()
{
    $caseId = trim((string) ($_GET['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas. Använd action=templates för mallar.'], 422);
    }

    $stmt = db()->prepare(
        'SELECT id, case_id, attachment_id, template_key, original_name, detected_format,
                reformatted_name, reformatted_text, issues_json, status, created_at, updated_at
         FROM reformat_jobs
         WHERE case_id = :case_id
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':case_id' => $caseId]);
    respond(['jobs' => array_map('normalize_reformat_job_row', $stmt->fetchAll())]);
}

function handle_post_reformat()
{
    $input = json_input();
    $action = trim((string) ($input['action'] ?? 'reformat'));

    if ($action === 'approve' || $action === 'reject') {
        handle_reformat_status_change($input, $action);
    }

    $caseId = trim((string) ($input['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $attachmentId = (int) ($input['attachment_id'] ?? 0);
    $file = $attachmentId > 0
        ? load_reformat_file_from_attachment($caseId, $attachmentId)
        : normalize_reformat_file($input['file'] ?? []);
    if ($file['name'] === '') {
        respond(['error' => 'Filnamn saknas. Skicka file eller attachment_id.'], 422);
    }

    $detectedFormat = detect_file_format($file);
    $templateKey = trim((string) ($input['template_key'] ?? ''));
    if ($templateKey === '') {
        $templateKey = default_template_for_format($detectedFormat);
    }

    $templates = reformat_templates();
    if (!isset($templates[$templateKey])) {
        respond(['error' => 'Okänd mall.', 'template_key' => $templateKey], 422);
    }

    $result = build_reformat_result($file, $detectedFormat, $templates[$templateKey]);
    $sourcePreview = should_store_source_preview($detectedFormat)
        ? text_slice($file['preview'], 0, 60000)
        : '[binary/sensitive content intentionally not stored]';

    $stmt = db()->prepare(
        'INSERT INTO reformat_jobs
            (case_id, attachment_id, template_key, original_name, detected_format, source_preview,
             reformatted_name, reformatted_text, issues_json, status)
         VALUES
            (:case_id, :attachment_id, :template_key, :original_name, :detected_format, :source_preview,
             :reformatted_name, :reformatted_text, :issues_json, :status)'
    );
    $stmt->execute([
        ':case_id' => $caseId,
        ':attachment_id' => $attachmentId > 0 ? $attachmentId : null,
        ':template_key' => $templateKey,
        ':original_name' => $file['name'],
        ':detected_format' => $detectedFormat,
        ':source_preview' => $sourcePreview,
        ':reformatted_name' => $result['name'],
        ':reformatted_text' => $result['text'],
        ':issues_json' => json_encode($result['issues'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':status' => 'draft',
    ]);

    respond([
        'ok' => true,
        'mode' => 'draft',
        'human_review_required' => true,
        'job' => [
            'id' => (int) db()->lastInsertId(),
            'case_id' => $caseId,
            'attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'template_key' => $templateKey,
            'template_title' => $templates[$templateKey]['title'],
            'original_name' => $file['name'],
            'detected_format' => $detectedFormat,
            'reformatted_name' => $result['name'],
            'reformatted_text' => $result['text'],
            'issues' => $result['issues'],
            'status' => 'draft',
        ],
    ]);
}

function handle_reformat_status_change(array $input, string $action)
{
    $jobId = (int) ($input['job_id'] ?? 0);
    if ($jobId <= 0) {
        respond(['error' => 'job_id saknas.'], 422);
    }

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = db()->prepare('UPDATE reformat_jobs SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $jobId]);

    respond([
        'ok' => true,
        'job_id' => $jobId,
        'status' => $status,
        'human_review_required' => false,
    ]);
}

function handle_delete_reformat_jobs()
{
    $input = json_input();
    $caseId = trim((string) ($input['case_id'] ?? ''));
    $jobId = (int) ($input['job_id'] ?? 0);

    if ($jobId > 0) {
        $stmt = db()->prepare('DELETE FROM reformat_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        respond(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    if ($caseId === '') {
        respond(['error' => 'case_id eller job_id saknas.'], 422);
    }

    $stmt = db()->prepare('DELETE FROM reformat_jobs WHERE case_id = :case_id');
    $stmt->execute([':case_id' => $caseId]);
    respond(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

function reformat_templates(): array
{
    return [
        'openapi_yaml' => [
            'key' => 'openapi_yaml',
            'title' => 'DIGG API-underlag: OpenAPI',
            'format' => 'openapi.yaml',
            'description' => 'Mall för att kontrollera att en leverantörs OpenAPI-fil har rätt grundstruktur.',
        ],
        'json_payload' => [
            'key' => 'json_payload',
            'title' => 'DIGG API-underlag: JSON-exempel',
            'format' => 'json',
            'description' => 'Mall för att snygga till och granska ett JSON-exempel innan det används som AI-kontext.',
        ],
        'xml_payload' => [
            'key' => 'xml_payload',
            'title' => 'DIGG API-underlag: XML-exempel',
            'format' => 'xml',
            'description' => 'Mall för att snygga till och granska ett XML-exempel innan det används som AI-kontext.',
        ],
        'certificate_metadata' => [
            'key' => 'certificate_metadata',
            'title' => 'DIGG/SDK-underlag: certifikatmetadata',
            'format' => 'p12/pfx/cer/pem',
            'description' => 'Mall för att dokumentera certifikat utan att lagra hemligt certifikatinnehåll.',
        ],
    ];
}

function normalize_reformat_file($file): array
{
    if (!is_array($file)) {
        $file = [];
    }

    return [
        'name' => text_slice(trim((string) ($file['name'] ?? '')), 0, 255),
        'type' => text_slice(trim((string) ($file['type'] ?? '')), 0, 120),
        'size' => max(0, (int) ($file['size'] ?? 0)),
        'preview' => text_slice((string) ($file['preview'] ?? ''), 0, 60000),
    ];
}

function load_reformat_file_from_attachment(string $caseId, int $attachmentId): array
{
    $stmt = db()->prepare(
        'SELECT name, mime_type, size_bytes, preview
         FROM case_attachments
         WHERE id = :id AND case_id = :case_id
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $attachmentId,
        ':case_id' => $caseId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(['error' => 'Hittar inte uppladdat underlag för angiven attachment_id.'], 404);
    }

    return [
        'name' => text_slice(trim((string) ($row['name'] ?? '')), 0, 255),
        'type' => text_slice(trim((string) ($row['mime_type'] ?? '')), 0, 120),
        'size' => max(0, (int) ($row['size_bytes'] ?? 0)),
        'preview' => text_slice((string) ($row['preview'] ?? ''), 0, 60000),
    ];
}

function detect_file_format(array $file): string
{
    $name = strtolower($file['name']);
    $type = strtolower($file['type']);
    $preview = ltrim($file['preview']);

    if (preg_match('/\.(ya?ml)$/i', $name)) {
        return preg_match('/(^|\n)\s*openapi\s*:/i', $preview) ? 'openapi_yaml' : 'yaml';
    }
    if (preg_match('/\.json$/i', $name) || contains_text($type, 'json') || starts_with($preview, '{') || starts_with($preview, '[')) {
        return 'json';
    }
    if (preg_match('/\.xml$/i', $name) || contains_text($type, 'xml') || starts_with($preview, '<')) {
        return 'xml';
    }
    if (preg_match('/\.(p12|pfx)$/i', $name)) {
        return 'certificate_bundle';
    }
    if (preg_match('/\.(cer|crt|pem)$/i', $name)) {
        return 'certificate_public';
    }
    return 'text';
}

function default_template_for_format(string $detectedFormat): string
{
    if ($detectedFormat === 'openapi_yaml' || $detectedFormat === 'yaml') {
        return 'openapi_yaml';
    }
    if ($detectedFormat === 'json') {
        return 'json_payload';
    }
    if ($detectedFormat === 'xml') {
        return 'xml_payload';
    }
    if ($detectedFormat === 'certificate_bundle' || $detectedFormat === 'certificate_public') {
        return 'certificate_metadata';
    }
    return 'json_payload';
}

function build_reformat_result(array $file, string $detectedFormat, array $template): array
{
    $key = $template['key'];
    if ($key === 'json_payload') {
        return reformat_json_payload($file);
    }
    if ($key === 'xml_payload') {
        return reformat_xml_payload($file);
    }
    if ($key === 'openapi_yaml') {
        return reformat_openapi_yaml($file);
    }
    if ($key === 'certificate_metadata') {
        return reformat_certificate_metadata($file, $detectedFormat);
    }

    return [
        'name' => suggested_output_name($file['name'], 'txt'),
        'text' => $file['preview'],
        'issues' => ['Ingen specifik mall matchade filen.'],
    ];
}

function reformat_json_payload(array $file): array
{
    $issues = [];
    $decoded = json_decode($file['preview'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $issues[] = 'JSON kunde inte parsas: ' . json_last_error_msg();
        $text = $file['preview'];
    } else {
        $text = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($text === false) {
            $text = $file['preview'];
            $issues[] = 'JSON kunde läsas men inte skrivas om.';
        }
    }

    return [
        'name' => suggested_output_name($file['name'], 'json'),
        'text' => $text,
        'issues' => $issues,
    ];
}

function reformat_xml_payload(array $file): array
{
    $issues = [];
    $text = $file['preview'];

    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if ($dom->loadXML($file['preview'])) {
            $text = $dom->saveXML() ?: $file['preview'];
        } else {
            foreach (libxml_get_errors() as $error) {
                $issues[] = trim($error->message);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    } else {
        $issues[] = 'PHP DOMDocument saknas, XML kunde inte formateras automatiskt.';
    }

    return [
        'name' => suggested_output_name($file['name'], 'xml'),
        'text' => $text,
        'issues' => array_values(array_unique(array_filter($issues))),
    ];
}

function reformat_openapi_yaml(array $file): array
{
    $source = trim($file['preview']);
    $issues = [];
    $required = [
        'openapi' => '/(^|\n)\s*openapi\s*:/i',
        'info' => '/(^|\n)\s*info\s*:/i',
        'paths' => '/(^|\n)\s*paths\s*:/i',
        'components' => '/(^|\n)\s*components\s*:/i',
    ];

    foreach ($required as $label => $pattern) {
        if (!preg_match($pattern, $source)) {
            $issues[] = 'Saknar eller kan inte hitta sektionen: ' . $label;
        }
    }

    $text = implode("\n", [
        '# DIGG review draft for openapi.yaml',
        '# Human review required before this is used as real supplier documentation.',
        '# Kontrollera särskilt: endpoints, auth, schemas, examples, felkoder och kontaktväg.',
        '',
        '# Municipality/supplier source below',
        $source !== '' ? $source : '# Ingen textförhandsvisning fanns i uppladdningen.',
        '',
    ]);

    return [
        'name' => suggested_output_name($file['name'], 'openapi.yaml'),
        'text' => $text,
        'issues' => $issues,
    ];
}

function reformat_certificate_metadata(array $file, string $detectedFormat): array
{
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $issues = [];
    if ($detectedFormat === 'certificate_bundle') {
        $issues[] = 'Certifikatpaket kan innehålla hemligheter. PoC:n lagrar inte filinnehållet, bara metadata.';
    }

    $text = implode("\n", [
        '# Certifikatunderlag för mänsklig granskning',
        '',
        'Filnamn: ' . $file['name'],
        'Upptäckt format: ' . ($extension !== '' ? '.' . $extension : $detectedFormat),
        'Storlek i byte: ' . (string) $file['size'],
        '',
        'Fyll i innan kommunen går vidare:',
        '- Miljö: test / QA / produktion',
        '- Ägare hos kommunen:',
        '- Teknisk kontakt hos leverantör:',
        '- Vem skapade certifikatet:',
        '- Vem installerar certifikatet:',
        '- Giltig från:',
        '- Giltig till:',
        '- Var används certifikatet:',
        '- Hur sker förnyelse och byte:',
        '',
        'Notering:',
        'Lägg inte privat nyckel, lösenord eller certifikatinnehåll i AI-chatten.',
        '',
    ]);

    return [
        'name' => suggested_output_name($file['name'], 'certifikat-underlag.md'),
        'text' => $text,
        'issues' => $issues,
    ];
}

function suggested_output_name(string $originalName, string $targetExtension): string
{
    $base = preg_replace('/\.[^.]+$/', '', $originalName);
    $base = trim((string) $base);
    if ($base === '') {
        $base = 'underlag';
    }

    return $base . '.reformatted.' . ltrim($targetExtension, '.');
}

function should_store_source_preview(string $detectedFormat): bool
{
    return !in_array($detectedFormat, ['certificate_bundle', 'certificate_public'], true);
}

function starts_with(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function normalize_reformat_job_row(array $row): array
{
    $issues = json_decode((string) ($row['issues_json'] ?? '[]'), true);
    if (!is_array($issues)) {
        $issues = [];
    }

    return [
        'id' => (int) $row['id'],
        'case_id' => (string) $row['case_id'],
        'attachment_id' => $row['attachment_id'] === null ? null : (int) $row['attachment_id'],
        'template_key' => (string) $row['template_key'],
        'original_name' => (string) $row['original_name'],
        'detected_format' => (string) $row['detected_format'],
        'reformatted_name' => (string) $row['reformatted_name'],
        'reformatted_text' => (string) $row['reformatted_text'],
        'issues' => $issues,
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}
