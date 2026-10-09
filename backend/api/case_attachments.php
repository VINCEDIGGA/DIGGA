<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    ensure_case_attachments_schema();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        handle_get_case_attachments();
    }
    if ($method === 'POST') {
        handle_save_case_attachment();
    }
    if ($method === 'DELETE') {
        handle_delete_case_attachments();
    }

    respond(['error' => 'Metoden stöds inte.'], 405);
} catch (Throwable $error) {
    respond([
        'error' => 'Kunde inte hantera uppladdat underlag.',
        'detail' => $error->getMessage(),
    ], 500);
}

function ensure_case_attachments_schema()
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS case_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(120) NOT NULL,
            message_text TEXT NULL,
            name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL DEFAULT '',
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            preview MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_case_attachments_case (case_id),
            INDEX idx_case_attachments_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function handle_get_case_attachments()
{
    $caseId = trim((string) ($_GET['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $stmt = db()->prepare(
        'SELECT id, case_id, message_text, name, mime_type, size_bytes, preview, created_at
         FROM case_attachments
         WHERE case_id = :case_id
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([':case_id' => $caseId]);
    respond(['attachments' => array_map('normalize_attachment_row', $stmt->fetchAll())]);
}

function handle_save_case_attachment()
{
    $input = json_input();
    $caseId = trim((string) ($input['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $attachment = normalize_attachment_payload($input['attachment'] ?? []);
    if ($attachment['name'] === '') {
        respond(['error' => 'Filnamn saknas.'], 422);
    }

    $stmt = db()->prepare(
        'INSERT INTO case_attachments
            (case_id, message_text, name, mime_type, size_bytes, preview)
         VALUES
            (:case_id, :message_text, :name, :mime_type, :size_bytes, :preview)'
    );
    $stmt->execute([
        ':case_id' => $caseId,
        ':message_text' => text_slice(trim((string) ($input['message_text'] ?? '')), 0, 2000),
        ':name' => $attachment['name'],
        ':mime_type' => $attachment['type'],
        ':size_bytes' => $attachment['size'],
        ':preview' => $attachment['preview'],
    ]);

    respond([
        'ok' => true,
        'attachment' => [
            'id' => (int) db()->lastInsertId(),
            'case_id' => $caseId,
            'message_text' => text_slice(trim((string) ($input['message_text'] ?? '')), 0, 2000),
            'name' => $attachment['name'],
            'type' => $attachment['type'],
            'size' => $attachment['size'],
            'preview' => $attachment['preview'],
        ],
    ]);
}

function handle_delete_case_attachments()
{
    $input = json_input();
    $caseId = trim((string) ($input['case_id'] ?? ''));
    if ($caseId === '') {
        respond(['error' => 'case_id saknas.'], 422);
    }

    $stmt = db()->prepare('DELETE FROM case_attachments WHERE case_id = :case_id');
    $stmt->execute([':case_id' => $caseId]);
    respond(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

function normalize_attachment_payload($attachment): array
{
    if (!is_array($attachment)) {
        $attachment = [];
    }

    return [
        'name' => text_slice(trim((string) ($attachment['name'] ?? '')), 0, 255),
        'type' => text_slice(trim((string) ($attachment['type'] ?? '')), 0, 120),
        'size' => max(0, (int) ($attachment['size'] ?? 0)),
        'preview' => text_slice(trim((string) ($attachment['preview'] ?? '')), 0, 6000),
    ];
}

function normalize_attachment_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'case_id' => (string) $row['case_id'],
        'message_text' => (string) ($row['message_text'] ?? ''),
        'name' => (string) $row['name'],
        'type' => (string) $row['mime_type'],
        'size' => (int) $row['size_bytes'],
        'preview' => (string) ($row['preview'] ?? ''),
        'created_at' => (string) $row['created_at'],
    ];
}
