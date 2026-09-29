<?php
declare(strict_types=1);

/**
 * @file embedding_lib.php
 * @brief Mistral-baserad embeddingindexering för RAG-sökning.
 *
 * Biblioteket lagrar textvektorer i MySQL och använder cosine similarity för
 * att hitta källor som liknar användarens fråga i betydelse.
 */

require_once __DIR__ . '/db.php';

/**
 * Skapar embeddingtabellen om den saknas.
 */
function embedding_ensure_schema()
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS document_embeddings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            model VARCHAR(80) NOT NULL,
            text_hash CHAR(64) NOT NULL,
            embedding JSON NOT NULL,
            vector_norm DOUBLE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_document_embedding (document_id, model),
            INDEX idx_embedding_model (model),
            INDEX idx_embedding_hash (text_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Returnerar status för embeddingindexet.
 *
 * @return array<string, mixed>
 */
function embedding_status(array $config): array
{
    embedding_ensure_schema();

    $model = embedding_model($config);
    $totalDocuments = (int) db()->query('SELECT COUNT(*) FROM reference_documents')->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM document_embeddings WHERE model = :model');
    $stmt->execute([':model' => $model]);
    $indexed = (int) $stmt->fetchColumn();

    return [
        'enabled' => embedding_enabled($config),
        'configured' => embedding_api_key($config) !== '',
        'model' => $model,
        'totalDocuments' => $totalDocuments,
        'indexedDocuments' => $indexed,
        'missingDocuments' => max(0, $totalDocuments - $indexed),
    ];
}

/**
 * Indexerar dokument som saknar embedding eller har ändrats.
 *
 * @return array<string, mixed>
 */
function embedding_index_documents(array $config, int $limit = 80): array
{
    embedding_ensure_schema();
    if (!embedding_enabled($config) || embedding_api_key($config) === '') {
        return [
            'indexed' => 0,
            'skipped' => 0,
            'error' => 'Embeddings är inte konfigurerade. Lägg Mistral-nyckel i api/local.secrets.php.',
            'status' => embedding_status($config),
        ];
    }

    $model = embedding_model($config);
    $maxChars = max(500, (int) ($config['embeddings']['max_text_chars'] ?? 3500));
    $batchSize = max(1, min(16, (int) ($config['embeddings']['batch_size'] ?? 8)));
    $candidates = embedding_documents_to_index($model, max(1, $limit), $maxChars);
    $indexed = 0;
    $skipped = 0;

    foreach (array_chunk($candidates, $batchSize) as $batch) {
        $texts = array_map(static function (array $row): string {
            return $row['embedding_text'];
        }, $batch);
        $vectors = embedding_create_vectors($texts, $config);
        foreach ($batch as $index => $row) {
            $vector = $vectors[$index] ?? null;
            if (!is_array($vector) || !$vector) {
                $skipped++;
                continue;
            }
            embedding_store_vector((int) $row['id'], $model, $row['text_hash'], $vector);
            $indexed++;
        }
    }

    return [
        'indexed' => $indexed,
        'skipped' => $skipped,
        'model' => $model,
        'status' => embedding_status($config),
    ];
}

/**
 * Hämtar dokument med högst semantisk likhet mot frågan.
 *
 * @return array<int, array<string, mixed>>
 */
function embedding_retrieve_chunks(string $question, array $config, int $limit): array
{
    try {
        embedding_ensure_schema();
    } catch (Throwable $error) {
        return [];
    }

    if (!embedding_enabled($config) || embedding_api_key($config) === '') {
        return [];
    }

    try {
        $status = embedding_status($config);
    } catch (Throwable $error) {
        return [];
    }
    if (($status['indexedDocuments'] ?? 0) <= 0) {
        return [];
    }

    try {
        $queryVector = embedding_create_vectors([$question], $config)[0] ?? [];
    } catch (Throwable $error) {
        return [];
    }
    if (!$queryVector) {
        return [];
    }

    $queryNorm = embedding_vector_norm($queryVector);
    if ($queryNorm <= 0) {
        return [];
    }

    $model = embedding_model($config);
    $stmt = db()->prepare(
        'SELECT d.id, d.title, d.service, d.source_url, d.content, e.embedding, e.vector_norm
         FROM document_embeddings e
         JOIN reference_documents d ON d.id = e.document_id
         WHERE e.model = :model'
    );
    $stmt->execute([':model' => $model]);

    $scored = [];
    foreach ($stmt->fetchAll() as $row) {
        $vector = json_decode((string) $row['embedding'], true);
        if (!is_array($vector) || !$vector) {
            continue;
        }
        $score = embedding_cosine_similarity($queryVector, $queryNorm, $vector, (float) $row['vector_norm']);
        $row['score'] = $score;
        $row['retrieval_mode'] = 'embedding';
        unset($row['embedding'], $row['vector_norm']);
        $scored[] = $row;
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return 0;
        }
        return ($a['score'] < $b['score']) ? 1 : -1;
    });
    return array_slice($scored, 0, max(1, $limit));
}

/**
 * Hittar dokument som behöver indexeras.
 *
 * @return array<int, array<string, mixed>>
 */
function embedding_documents_to_index(string $model, int $limit, int $maxChars): array
{
    $stmt = db()->prepare(
        'SELECT d.id, d.title, d.service, d.source_url, d.content, e.text_hash AS existing_hash
         FROM reference_documents d
         LEFT JOIN document_embeddings e ON e.document_id = d.id AND e.model = :model
         ORDER BY d.updated_at DESC, d.id DESC'
    );
    $stmt->execute([':model' => $model]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $text = embedding_document_text($row, $maxChars);
        $hash = hash('sha256', $text);
        if (($row['existing_hash'] ?? '') === $hash) {
            continue;
        }
        $row['embedding_text'] = $text;
        $row['text_hash'] = $hash;
        $rows[] = $row;
        if (count($rows) >= $limit) {
            break;
        }
    }
    return $rows;
}

/**
 * Skickar text till Mistrals embeddings-endpoint.
 *
 * @return array<int, array<int, float>>
 */
function embedding_create_vectors(array $texts, array $config): array
{
    $apiKey = embedding_api_key($config);
    if ($apiKey === '') {
        throw new RuntimeException('Mistral API-nyckel saknas för embeddings.');
    }

    $payload = [
        'model' => embedding_model($config),
        'input' => array_values($texts),
        'encoding_format' => 'float',
    ];

    $ch = curl_init(embedding_base_url($config) . '/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['embeddings']['timeout'] ?? 30),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($status >= 400 || !is_array($data)) {
        $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? 'Okänt fel') : 'Ogiltigt svar';
        throw new RuntimeException("Mistral embeddings svarade HTTP {$status}: {$message}");
    }

    $vectors = [];
    foreach (($data['data'] ?? []) as $item) {
        if (!is_array($item) || !isset($item['embedding']) || !is_array($item['embedding'])) {
            continue;
        }
        $index = (int) ($item['index'] ?? count($vectors));
        $vectors[$index] = array_map('floatval', $item['embedding']);
    }
    ksort($vectors);
    return array_values($vectors);
}

/**
 * Sparar en vektor för ett dokument.
 */
function embedding_store_vector(int $documentId, string $model, string $hash, array $vector)
{
    $stmt = db()->prepare(
        "INSERT INTO document_embeddings (document_id, model, text_hash, embedding, vector_norm)
         VALUES (:document_id, :model, :text_hash, :embedding, :vector_norm)
         ON DUPLICATE KEY UPDATE
            text_hash = VALUES(text_hash),
            embedding = VALUES(embedding),
            vector_norm = VALUES(vector_norm),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':document_id' => $documentId,
        ':model' => $model,
        ':text_hash' => $hash,
        ':embedding' => json_encode(array_values($vector), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':vector_norm' => embedding_vector_norm($vector),
    ]);
}

/**
 * Bygger den text som skickas till embeddingmodellen.
 */
function embedding_document_text(array $row, int $maxChars): string
{
    $text = trim((string) ($row['title'] ?? '') . "\n" . (string) ($row['service'] ?? '') . "\n" . (string) ($row['content'] ?? ''));
    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
    return text_slice($text, 0, $maxChars);
}

function embedding_cosine_similarity(array $a, float $aNorm, array $b, float $bNorm): float
{
    if ($aNorm <= 0 || $bNorm <= 0) {
        return 0.0;
    }

    $dot = 0.0;
    $count = min(count($a), count($b));
    for ($i = 0; $i < $count; $i++) {
        $dot += (float) $a[$i] * (float) $b[$i];
    }
    return $dot / ($aNorm * $bNorm);
}

function embedding_vector_norm(array $vector): float
{
    $sum = 0.0;
    foreach ($vector as $value) {
        $sum += (float) $value * (float) $value;
    }
    return sqrt($sum);
}

function embedding_enabled(array $config): bool
{
    return (bool) ($config['embeddings']['enabled'] ?? true);
}

function embedding_model(array $config): string
{
    return (string) ($config['embeddings']['model'] ?? 'mistral-embed');
}

function embedding_base_url(array $config): string
{
    return rtrim((string) ($config['embeddings']['base_url'] ?? $config['llm']['base_url'] ?? 'https://api.mistral.ai/v1'), '/');
}

function embedding_api_key(array $config): string
{
    return (string) ($config['embeddings']['api_key'] ?? $config['llm']['api_key'] ?? '');
}

if (!function_exists('text_slice')) {
    function text_slice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }
}
