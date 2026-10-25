<?php
declare(strict_types=1);

// Copy to local.secrets.php and fill in local values. Never commit that file.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'digg_ena_assistant',
        'user' => 'root',
        'pass' => '',
    ],
    'llm' => [
        'api_key' => '',
        'base_url' => 'https://api.mistral.ai/v1',
        'model' => 'mistral-small-latest',
        'timeout' => 30,
        'max_tokens' => 800,
    ],
    'embeddings' => [
        'enabled' => true,
        'model' => 'mistral-embed',
    ],
    'connectors' => [
        'allow_live_send' => false,
        'source_api' => [
            'name' => 'Mock case system',
            'endpoint' => '',
            'api_key' => '',
        ],
        'target_api' => [
            'name' => 'Mock receiver',
            'endpoint' => '',
            'api_key' => '',
        ],
    ],
    'crawler' => [
        'admin_token' => '',
        'github_token' => '',
    ],
];
