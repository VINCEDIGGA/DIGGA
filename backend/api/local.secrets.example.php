<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => 'studentmysql.miun.se',
        'port' => '3306',
        'name' => 'wisi2403',
        'user' => 'YOUR_STUDENT_DB_USER',
        'pass' => 'YOUR_STUDENT_DB_PASSWORD',
    ],
    'llm' => [
        'api_key' => 'PASTE_YOUR_MISTRAL_KEY_HERE',
        'base_url' => 'https://api.mistral.ai/v1',
        'model' => 'mistral-small-latest',
    ],
    'embeddings' => [
        'enabled' => true,
        'api_key' => '', // Empty means: reuse llm.api_key.
        'base_url' => 'https://api.mistral.ai/v1',
        'model' => 'mistral-embed',
        'batch_size' => 8,
        'max_text_chars' => 3500,
    ],
    'connectors' => [
        'allow_live_send' => false,
        'source_api' => [
            'name' => 'Your case system API',
            'endpoint' => 'https://example.se/api/cases/demo',
            'api_key' => 'PASTE_SOURCE_API_KEY_HERE',
        ],
        'target_api' => [
            'name' => 'Your SDK or Mina test adapter',
            'endpoint' => 'https://example.se/api/messages',
            'api_key' => 'PASTE_TARGET_API_KEY_HERE',
        ],
    ],
    'crawler' => [
        'admin_token' => 'PASTE_A_PRIVATE_CRAWLER_TOKEN_HERE',
        'github_token' => '',
        'max_pages' => 35,
        'max_github_repos' => 24,
        'max_github_files_per_repo' => 12,
        'local_paths' => [
            __DIR__ . '/../data',
        ],
    ],
];
