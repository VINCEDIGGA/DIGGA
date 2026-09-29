<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    ensure_step_context_schema();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        handle_get_step_context();
    }
    if ($method === 'POST') {
        handle_save_step_context();
    }
    if ($method === 'DELETE') {
        handle_delete_case_context();
    }

    respond(['error' => 'Metoden stöds inte.'], 405);
} catch (Throwable $error) {
    respond([
        'error' => 'Kunde inte hantera stegets underlag.',
        'detail' => $error->getMessage(),
    ], 500);
}

function ensure_step_context_schema()
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS guide_step_context (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(120) NOT NULL,
            step_index INT UNSIGNED NOT NULL,
            check_index INT UNSIGNED NOT NULL,
            step_title VARCHAR(255) NOT NULL,
            check_text VARCHAR(500) NOT NULL,
            organization_name VARCHAR(255) NOT NULL DEFAULT '',
            organization_number VARCHAR(80) NOT NULL DEFAULT '',
            contact_name VARCHAR(255) NOT NULL DEFAULT '',
            contact_email VARCHAR(255) NOT NULL DEFAULT '',
            contact_role VARCHAR(160) NOT NULL DEFAULT '',
            short_description TEXT NULL,
            need_text TEXT NULL,
            application_json MEDIUMTEXT NULL,
            files_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_guide_step_context (case_id, step_index, check_index),
            INDEX idx_guide_step_context_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    ensure_step_context_column('application_json', 'MEDIUMTEXT NULL');
}

function ensure_step_context_column(string $column, string $definition)
{
    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'guide_step_context'
           AND COLUMN_NAME = :column"
    );
    $stmt->execute([':column' => $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE guide_step_context ADD COLUMN {$column} {$definition}");
    }
}

function handle_get_step_context()
{
    $caseId = trim((string) ($_GET['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $stepIndex = isset($_GET['step_index']) ? (int) $_GET['step_index'] : null;
    $checkIndex = isset($_GET['check_index']) ? (int) $_GET['check_index'] : null;

    if ($stepIndex !== null && $checkIndex !== null) {
        $stmt = db()->prepare(
            'SELECT *
             FROM guide_step_context
             WHERE case_id = :case_id
               AND step_index = :step_index
               AND check_index = :check_index
             LIMIT 1'
        );
        $stmt->execute([
            ':case_id' => $caseId,
            ':step_index' => $stepIndex,
            ':check_index' => $checkIndex,
        ]);
        $row = $stmt->fetch();
        respond(['context' => $row ? normalize_step_context_row($row) : null]);
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM guide_step_context
         WHERE case_id = :case_id
         ORDER BY step_index ASC, check_index ASC'
    );
    $stmt->execute([':case_id' => $caseId]);
    $rows = array_map('normalize_step_context_row', $stmt->fetchAll());
    respond(['contexts' => $rows]);
}

function handle_save_step_context()
{
    $input = json_input();
    $caseId = trim((string) ($input['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $stepIndex = max(0, (int) ($input['step_index'] ?? 0));
    $checkIndex = max(0, (int) ($input['check_index'] ?? 0));
    $files = normalize_files($input['files'] ?? []);
    $application = normalize_application($input['application'] ?? []);

    $payload = [
        ':case_id' => $caseId,
        ':step_index' => $stepIndex,
        ':check_index' => $checkIndex,
        ':step_title' => text_slice(trim((string) ($input['step_title'] ?? '')), 0, 255),
        ':check_text' => text_slice(trim((string) ($input['check_text'] ?? '')), 0, 500),
        ':organization_name' => text_slice(trim((string) ($input['organization_name'] ?? '')), 0, 255),
        ':organization_number' => text_slice(trim((string) ($input['organization_number'] ?? '')), 0, 80),
        ':contact_name' => text_slice(trim((string) ($input['contact_name'] ?? '')), 0, 255),
        ':contact_email' => text_slice(trim((string) ($input['contact_email'] ?? '')), 0, 255),
        ':contact_role' => text_slice(trim((string) ($input['contact_role'] ?? '')), 0, 160),
        ':short_description' => trim((string) ($input['short_description'] ?? '')),
        ':need_text' => trim((string) ($input['need_text'] ?? '')),
        ':application_json' => json_encode($application, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':files_json' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $stmt = db()->prepare(
        "INSERT INTO guide_step_context
            (case_id, step_index, check_index, step_title, check_text,
             organization_name, organization_number, contact_name, contact_email,
             contact_role, short_description, need_text, application_json, files_json)
         VALUES
            (:case_id, :step_index, :check_index, :step_title, :check_text,
             :organization_name, :organization_number, :contact_name, :contact_email,
             :contact_role, :short_description, :need_text, :application_json, :files_json)
         ON DUPLICATE KEY UPDATE
            step_title = VALUES(step_title),
            check_text = VALUES(check_text),
            organization_name = VALUES(organization_name),
            organization_number = VALUES(organization_number),
            contact_name = VALUES(contact_name),
            contact_email = VALUES(contact_email),
            contact_role = VALUES(contact_role),
            short_description = VALUES(short_description),
            need_text = VALUES(need_text),
            application_json = VALUES(application_json),
            files_json = VALUES(files_json),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute($payload);

    respond(['ok' => true]);
}

function handle_delete_case_context()
{
    $input = json_input();
    $caseId = trim((string) ($input['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $stmt = db()->prepare('DELETE FROM guide_step_context WHERE case_id = :case_id');
    $stmt->execute([':case_id' => $caseId]);
    respond(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

function normalize_step_context_row(array $row): array
{
    $row['step_index'] = (int) $row['step_index'];
    $row['check_index'] = (int) $row['check_index'];
    $row['files'] = json_decode((string) ($row['files_json'] ?? '[]'), true) ?: [];
    $row['application'] = json_decode((string) ($row['application_json'] ?? '{}'), true) ?: [];
    unset($row['application_json']);
    unset($row['files_json']);
    return $row;
}

function normalize_files($files): array
{
    if (!is_array($files)) {
        return [];
    }

    $normalized = [];
    foreach (array_slice($files, 0, 8) as $file) {
        if (!is_array($file)) {
            continue;
        }
        $normalized[] = [
            'name' => text_slice(trim((string) ($file['name'] ?? '')), 0, 255),
            'type' => text_slice(trim((string) ($file['type'] ?? '')), 0, 120),
            'size' => (int) ($file['size'] ?? 0),
            'preview' => text_slice(trim((string) ($file['preview'] ?? '')), 0, 3500),
        ];
    }
    return $normalized;
}

function normalize_application($application): array
{
    if (!is_array($application)) {
        return [];
    }

    $normalized = [];
    foreach ($application as $key => $value) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key);
        if ($safeKey === '') {
            continue;
        }
        if (is_bool($value)) {
            $normalized[$safeKey] = $value;
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $normalized[$safeKey] = text_slice(trim((string) $value), 0, 500);
        }
    }
    return $normalized;
}
