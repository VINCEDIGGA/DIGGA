<?php
declare(strict_types=1);

$localSecrets = is_file(__DIR__ . '/local.secrets.php')
    ? require __DIR__ . '/local.secrets.php'
    : [];

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null)
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }
}

return [
    'db' => [
        'host' => env_value('DB_HOST', $localSecrets['db']['host'] ?? '127.0.0.1'),
        'port' => env_value('DB_PORT', $localSecrets['db']['port'] ?? '3306'),
        'name' => env_value('DB_NAME', $localSecrets['db']['name'] ?? 'digg_ena_assistant'),
        'user' => env_value('DB_USER', $localSecrets['db']['user'] ?? 'root'),
        'pass' => env_value('DB_PASS', $localSecrets['db']['pass'] ?? ''),
    ],
    'llm' => [
        'api_key' => env_value('LLM_API_KEY', $localSecrets['llm']['api_key'] ?? null),
        'base_url' => rtrim(env_value('LLM_BASE_URL', $localSecrets['llm']['base_url'] ?? 'https://api.mistral.ai/v1'), '/'),
        'model' => env_value('LLM_MODEL', $localSecrets['llm']['model'] ?? 'mistral-small-latest'),
        'timeout' => (int) env_value('LLM_TIMEOUT', (string) ($localSecrets['llm']['timeout'] ?? '30')),
        'max_tokens' => (int) env_value('LLM_MAX_TOKENS', (string) ($localSecrets['llm']['max_tokens'] ?? '800')),
    ],
    'rag' => [
        'max_chunks' => (int) env_value('RAG_MAX_CHUNKS', '5'),
    ],
    'embeddings' => [
        'enabled' => filter_var(env_value('EMBEDDINGS_ENABLED', (string) ($localSecrets['embeddings']['enabled'] ?? 'true')), FILTER_VALIDATE_BOOLEAN),
        'api_key' => env_value('EMBEDDINGS_API_KEY', env_value('LLM_API_KEY', $localSecrets['embeddings']['api_key'] ?? ($localSecrets['llm']['api_key'] ?? null))),
        'base_url' => rtrim(env_value('EMBEDDINGS_BASE_URL', $localSecrets['embeddings']['base_url'] ?? ($localSecrets['llm']['base_url'] ?? 'https://api.mistral.ai/v1')), '/'),
        'model' => env_value('EMBEDDINGS_MODEL', $localSecrets['embeddings']['model'] ?? 'mistral-embed'),
        'timeout' => (int) env_value('EMBEDDINGS_TIMEOUT', (string) ($localSecrets['embeddings']['timeout'] ?? '30')),
        'batch_size' => (int) env_value('EMBEDDINGS_BATCH_SIZE', (string) ($localSecrets['embeddings']['batch_size'] ?? '8')),
        'max_text_chars' => (int) env_value('EMBEDDINGS_MAX_TEXT_CHARS', (string) ($localSecrets['embeddings']['max_text_chars'] ?? '3500')),
    ],
    'connectors' => [
        'allow_live_send' => filter_var(env_value('CONNECTOR_ALLOW_LIVE_SEND', (string) ($localSecrets['connectors']['allow_live_send'] ?? 'false')), FILTER_VALIDATE_BOOLEAN),
        'source_api' => [
            'name' => env_value('SOURCE_API_NAME', $localSecrets['connectors']['source_api']['name'] ?? 'Mock case system'),
            'endpoint' => env_value('SOURCE_API_ENDPOINT', $localSecrets['connectors']['source_api']['endpoint'] ?? ''),
            'api_key' => env_value('SOURCE_API_KEY', $localSecrets['connectors']['source_api']['api_key'] ?? ''),
        ],
        'target_api' => [
            'name' => env_value('TARGET_API_NAME', $localSecrets['connectors']['target_api']['name'] ?? 'Mock receiver'),
            'endpoint' => env_value('TARGET_API_ENDPOINT', $localSecrets['connectors']['target_api']['endpoint'] ?? ''),
            'api_key' => env_value('TARGET_API_KEY', $localSecrets['connectors']['target_api']['api_key'] ?? ''),
        ],
    ],
    'crawler' => [
        'admin_token' => env_value('CRAWL_ADMIN_TOKEN', $localSecrets['crawler']['admin_token'] ?? ''),
        'github_token' => env_value('GITHUB_TOKEN', $localSecrets['crawler']['github_token'] ?? ''),
        'max_pages' => (int) env_value('CRAWL_MAX_PAGES', (string) ($localSecrets['crawler']['max_pages'] ?? '35')),
        'hard_max_pages' => 120,
        'max_github_repos' => (int) env_value('CRAWL_MAX_GITHUB_REPOS', (string) ($localSecrets['crawler']['max_github_repos'] ?? '24')),
        'max_github_files_per_repo' => (int) env_value('CRAWL_MAX_GITHUB_FILES_PER_REPO', (string) ($localSecrets['crawler']['max_github_files_per_repo'] ?? '12')),
        'timeout' => (int) env_value('CRAWL_TIMEOUT', (string) ($localSecrets['crawler']['timeout'] ?? '20')),
        'user_agent' => 'DIGG Ena Assistant PoC crawler (+student prototype)',
        'seed_urls' => [
            'https://www.digg.se/digitala-tjanster',
            'https://www.digg.se/digitala-tjanster/generell-metod-for-informationsutbyte-mellan-myndigheter---hinderbanan',
            'https://www.digg.se/digitala-tjanster/digital-post',
            'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer',
            'https://www.dataportal.se/',
            'https://www.dataportal.se/rest-api-profil',
            'https://www.dataportal.se/api-playbook/en-introduktion-till-rest',
        ],
        'local_paths' => $localSecrets['crawler']['local_paths'] ?? [
            dirname(__DIR__) . '/data',
        ],
        'github_orgs' => ['diggsweden'],
        'github_repositories' => [
            'diggsweden/DCAT-AP-SE-Processor',
            'diggsweden/rest-api-profil-lint-processor',
        ],
    ],
];
