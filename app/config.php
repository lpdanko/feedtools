<?php
// feedtools configuration

require_once __DIR__ . '/env.php';

ft_env_load([
  dirname(__DIR__) . '/.env',
  dirname(__DIR__) . '/.env.local',
]);

$explicitLlmEnv = ft_env_has('LLM_PROVIDER')
  || ft_env_has('LLM_API_FORMAT')
  || ft_env_has('LLM_API_KEY')
  || ft_env_has('LLM_BASE_URL')
  || ft_env_has('LLM_MODEL_PREFIX')
  || ft_env_has('LLM_MODEL_DEFAULT')
  || ft_env_has('LLM_MODELS');
$llmProvider = strtolower(trim((string)ft_env('LLM_PROVIDER', 'openai')));
$llmApiFormat = ft_env('LLM_API_FORMAT', $llmProvider === 'openai' ? 'responses' : 'chat_completions');
$llmBaseUrlDefault = match ($llmProvider) {
  'yandex' => 'https://ai.api.cloud.yandex.net/v1',
  'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
  default => ft_env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
};
$llmBaseUrl = ft_env('LLM_BASE_URL', $llmBaseUrlDefault);
$llmApiKeyFallback = match ($llmProvider) {
  'yandex' => ft_env('YANDEX_API_KEY', ft_env('YANDEX_CLOUD_API_KEY', '')),
  'gemini' => ft_env('GEMINI_API_KEY', ft_env('GOOGLE_AI_API_KEY', '')),
  default => ft_env('OPENAI_API_KEY', ''),
};
$llmApiKey = ft_env('LLM_API_KEY', $llmApiKeyFallback);
$llmDefaultModelFallback = match ($llmProvider) {
  'yandex' => 'yandexgpt-5.1/latest',
  'gemini' => 'gemini-3-flash-preview',
  default => ft_env('OPENAI_MODEL_DEFAULT', 'gpt-5.5'),
};
$llmDefaultModel = ft_env('LLM_MODEL_DEFAULT', $llmDefaultModelFallback);
$llmModelsDefault = match ($llmProvider) {
  'openai' => 'gpt-5.5,gpt-5.4,gpt-5.4-mini,gpt-5.4-nano,gpt-5.2',
  'yandex' => 'yandexgpt-5.1/latest,yandexgpt-5-pro/latest,yandexgpt-5-lite/latest,aliceai-llm/latest,gemma-3-27b-it',
  'gemini' => 'gemini-3.1-pro-preview,gemini-3-flash-preview,gemini-3.1-flash-lite-preview,gemini-2.5-pro,gemini-2.5-flash,gemini-2.5-flash-lite',
  default => $llmDefaultModel,
};
$llmVisionModelDefault = match ($llmProvider) {
  'yandex' => 'gemma-3-27b-it',
  'gemini' => 'gemini-3-flash-preview',
  default => '',
};
$yandexFolderId = ft_env('YANDEX_FOLDER_ID', ft_env('YANDEX_CLOUD_FOLDER_ID', ''));
$llmModelPrefixDefault = ($llmProvider === 'yandex' && $yandexFolderId !== '')
  ? ('gpt://' . trim((string)$yandexFolderId, '/'))
  : '';
$llmModelPrefix = ft_env('LLM_MODEL_PREFIX', $llmModelPrefixDefault);
$llmCacheDir = ft_env('LLM_RESPONSE_CACHE_DIR', ft_env('OPENAI_RESPONSE_CACHE_DIR', '')) ?: (__DIR__ . '/../storage/cache/llm_responses');
$openaiModelsDefault = 'gpt-5.5,gpt-5.4,gpt-5.4-mini,gpt-5.4-nano,gpt-5.2';
$openaiModelAliasesDefault = '{"gpt-5.3":"gpt-5.2"}';
$openaiImageModelsDefault = 'gpt-image-1-mini,gpt-image-1.5,gpt-image-2';
$openaiVideoModelsDefault = 'sora-2,sora-2-2025-12-08,sora-2-2025-10-06,sora-2-pro,sora-2-pro-2025-10-06';
$yandexModelsDefault = 'yandexgpt-5.1/latest,yandexgpt-5-pro/latest,yandexgpt-5-lite/latest,aliceai-llm/latest,gemma-3-27b-it';
$geminiModelsDefault = 'gemini-3.1-pro-preview,gemini-2.5-pro,gemini-3-flash-preview,gemini-2.5-flash,gemini-2.5-flash-lite,gemini-3.1-flash-lite-preview';
$yandexDefaultModel = ft_env('YANDEX_MODEL_DEFAULT', $llmProvider === 'yandex' ? $llmDefaultModel : 'yandexgpt-5.1/latest');
$yandexModels = ft_env('YANDEX_MODELS', $llmProvider === 'yandex' ? ft_env('LLM_MODELS', $yandexModelsDefault) : $yandexModelsDefault);
$yandexModelPrefixDefault = $yandexFolderId !== '' ? ('gpt://' . trim((string)$yandexFolderId, '/')) : '';
$yandexModelPrefix = ft_env('YANDEX_MODEL_PREFIX', $llmProvider === 'yandex' ? $llmModelPrefix : $yandexModelPrefixDefault);
$geminiDefaultModel = ft_env('GEMINI_MODEL_DEFAULT', $llmProvider === 'gemini' ? $llmDefaultModel : 'gemini-2.5-pro');
$geminiModels = ft_env('GEMINI_MODELS', $llmProvider === 'gemini' ? ft_env('LLM_MODELS', $geminiModelsDefault) : $geminiModelsDefault);
$llmProviderCommon = [
  'timeout_sec' => ft_env_int('LLM_TIMEOUT_SEC', ft_env_int('OPENAI_TIMEOUT_SEC', 60)),
  'retries' => ft_env_int('LLM_RETRIES', ft_env_int('OPENAI_RETRIES', 4)),
  'retry_base_delay_ms' => ft_env_int('LLM_RETRY_BASE_DELAY_MS', ft_env_int('OPENAI_RETRY_BASE_DELAY_MS', 250)),
  'extra_headers_json' => ft_env('LLM_EXTRA_HEADERS_JSON', ''),
  'ip_resolve' => ft_env('LLM_IP_RESOLVE', ''),
  'tls_verify' => ft_env_bool('LLM_TLS_VERIFY', true),
  'oauth_url' => ft_env('LLM_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
  'oauth_scope' => ft_env('LLM_OAUTH_SCOPE', 'GIGACHAT_API_PERS'),
  'oauth_cache_file' => ft_env('LLM_OAUTH_CACHE_FILE', '') ?: (__DIR__ . '/../storage/cache/llm_oauth_token.json'),
  'response_cache_enabled' => ft_env_bool('LLM_RESPONSE_CACHE_ENABLED', ft_env_bool('OPENAI_RESPONSE_CACHE_ENABLED', true)),
  'response_cache_ttl_sec' => ft_env_int('LLM_RESPONSE_CACHE_TTL_SEC', ft_env_int('OPENAI_RESPONSE_CACHE_TTL_SEC', 30 * 24 * 60 * 60)),
  'response_cache_dir' => $llmCacheDir,
  'prompt_cache_key_enabled' => ft_env_bool('LLM_PROMPT_CACHE_KEY_ENABLED', ft_env_bool('OPENAI_PROMPT_CACHE_KEY_ENABLED', true)),
  'prompt_cache_retention' => ft_env('LLM_PROMPT_CACHE_RETENTION', ft_env('OPENAI_PROMPT_CACHE_RETENTION', 'auto')),
  'request_log_enabled' => ft_env_bool('LLM_REQUEST_LOG_ENABLED', ft_env_bool('OPENAI_REQUEST_LOG_ENABLED', true)),
  'request_log_max_request_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_REQUEST_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_REQUEST_CHARS', 200000)),
  'request_log_max_response_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_RESPONSE_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_RESPONSE_CHARS', 200000)),
  'remote_image_max_bytes' => ft_env_int('LLM_REMOTE_IMAGE_MAX_BYTES', 10 * 1024 * 1024),
  'remote_image_timeout_sec' => ft_env_int('LLM_REMOTE_IMAGE_TIMEOUT_SEC', 20),
];
$llmProviderConfigs = [
  'openai' => array_replace($llmProviderCommon, [
    'provider' => 'openai',
    'api_format' => 'responses',
    'api_key' => $llmProvider === 'openai' ? $llmApiKey : ft_env('OPENAI_API_KEY', ''),
    'base_url' => ft_env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'default_model' => ft_env('OPENAI_MODEL_DEFAULT', 'gpt-5.5'),
    'vision_model' => ft_env('OPENAI_VISION_MODEL_DEFAULT', ''),
    'models' => array_values(array_filter(array_map('trim', explode(',', ft_env('OPENAI_MODELS', $openaiModelsDefault))))),
    'model_prefix' => '',
    'model_aliases_json' => ft_env('OPENAI_MODEL_ALIASES_JSON', $openaiModelAliasesDefault),
    'auth_type' => 'bearer',
    'auth_header' => 'Authorization',
    'auth_value_prefix' => '',
  ]),
  'yandex' => array_replace($llmProviderCommon, [
    'provider' => 'yandex',
    'api_format' => 'chat_completions',
    'api_key' => $llmProvider === 'yandex' ? $llmApiKey : ft_env('YANDEX_API_KEY', ft_env('YANDEX_CLOUD_API_KEY', '')),
    'base_url' => ft_env('YANDEX_BASE_URL', $llmProvider === 'yandex' ? $llmBaseUrl : 'https://ai.api.cloud.yandex.net/v1'),
    'default_model' => $yandexDefaultModel,
    'vision_model' => ft_env('YANDEX_VISION_MODEL_DEFAULT', ft_env('LLM_VISION_MODEL_DEFAULT', 'gemma-3-27b-it')),
    'models' => array_values(array_filter(array_map('trim', explode(',', $yandexModels)))),
    'model_prefix' => $yandexModelPrefix,
    'model_aliases_json' => ft_env('YANDEX_MODEL_ALIASES_JSON', ''),
    'auth_type' => 'bearer',
    'auth_header' => 'Authorization',
    'auth_value_prefix' => '',
  ]),
  'gemini' => array_replace($llmProviderCommon, [
    'provider' => 'gemini',
    'api_format' => ft_env('GEMINI_API_FORMAT', 'gemini_native'),
    'api_key' => $llmProvider === 'gemini' ? $llmApiKey : ft_env('GEMINI_API_KEY', ft_env('GOOGLE_AI_API_KEY', '')),
    'base_url' => ft_env('GEMINI_BASE_URL', $llmProvider === 'gemini' ? $llmBaseUrl : 'https://generativelanguage.googleapis.com/v1beta'),
    'default_model' => $geminiDefaultModel,
    'vision_model' => ft_env('GEMINI_VISION_MODEL_DEFAULT', ft_env('LLM_VISION_MODEL_DEFAULT', 'gemini-2.5-flash')),
    'models' => array_values(array_filter(array_map('trim', explode(',', $geminiModels)))),
    'model_prefix' => '',
    'model_aliases_json' => ft_env('GEMINI_MODEL_ALIASES_JSON', ''),
    'timeout_sec' => ft_env_int('GEMINI_TIMEOUT_SEC', 90),
    'retries' => ft_env_int('GEMINI_RETRIES', 0),
    'retry_base_delay_ms' => ft_env_int('GEMINI_RETRY_BASE_DELAY_MS', 500),
    'ip_resolve' => ft_env('GEMINI_IP_RESOLVE', ft_env('LLM_IP_RESOLVE', '')),
    'auth_type' => 'bearer',
    'auth_header' => 'Authorization',
    'auth_value_prefix' => '',
  ]),
];

$cfg = [
  'app' => [
    'env' => ft_env('APP_ENV', 'production'),
    'base_url' => ft_env('APP_BASE_URL', ''),
  ],
  'auth' => [
    'enabled' => ft_env_bool('APP_BASIC_AUTH_ENABLED', false),
    'realm' => ft_env('APP_BASIC_AUTH_REALM', 'FeedTools'),
    'user' => ft_env('APP_BASIC_AUTH_USER', ''),
    'pass' => ft_env('APP_BASIC_AUTH_PASS', ''),
    'pass_hash' => ft_env('APP_BASIC_AUTH_PASS_HASH', ''),
  ],
  'db' => [
    'host' => ft_env('DB_HOST', '127.0.0.1'),
    'port' => ft_env_int('DB_PORT', 3306),
    'unix_socket' => ft_env('DB_SOCKET', ''),
    'name' => ft_env('DB_NAME', ''),
    'user' => ft_env('DB_USER', ''),
    'pass' => ft_env('DB_PASS', ''),
    'charset' => ft_env('DB_CHARSET', 'utf8mb4'),
    'timeout_sec' => ft_env_int('DB_TIMEOUT_SEC', 5),
  ],

  'retention' => [
    'llm_request_days' => ft_env_int('APP_RETENTION_LLM_REQUEST_DAYS', 1),
    'price_push_days' => ft_env_int('APP_RETENTION_PRICE_PUSH_DAYS', 1),
    'operation_output_days' => ft_env_int('APP_RETENTION_OPERATION_OUTPUT_DAYS', 1),
    'remote_uploaded_local_days' => ft_env_int('APP_RETENTION_REMOTE_UPLOADED_LOCAL_DAYS', 1),
    'wb_promotion_price_history_days' => ft_env_int('APP_RETENTION_WB_PROMOTION_PRICE_HISTORY_DAYS', 7),
    // История заказов хранит только изменения состояния, не полные копии каждого обновления.
    'order_snapshot_history_days' => ft_env_int('APP_RETENTION_ORDER_SNAPSHOT_HISTORY_DAYS', 7),
  ],

  // storage directories (absolute paths based on this file)
  'paths' => [
    'storage_dir' => __DIR__ . '/../storage',
    'uploads_dir' => __DIR__ . '/../storage/uploads',
    'outputs_dir' => __DIR__ . '/../storage/outputs',
    'reports_dir' => __DIR__ . '/../storage/reports',
    'logs_dir'    => __DIR__ . '/../storage/logs',
  ],

  'limits' => [
    'max_upload_bytes' => ft_env_int('MAX_UPLOAD_BYTES', 5 * 1024 * 1024 * 1024),
    'sample_offers'    => ft_env_int('SAMPLE_OFFERS', 3),
  ],

  'llm' => [
    'provider' => $llmProvider,
    // responses = OpenAI Responses API; chat_completions = OpenAI-compatible /chat/completions.
    'api_format' => $llmApiFormat,
    'api_key' => $llmApiKey,
    'base_url' => $llmBaseUrl,
    'default_model' => $llmDefaultModel,
    'vision_model' => ft_env('LLM_VISION_MODEL_DEFAULT', $llmVisionModelDefault),
    'models' => array_values(array_filter(array_map('trim', explode(',', ft_env('LLM_MODELS', $llmModelsDefault))))),
    // Optional resolver for UI-friendly model names. Example for Yandex:
    // YANDEX_FOLDER_ID=b1g... + model yandexgpt-5.1/latest becomes
    // gpt://b1g.../yandexgpt-5.1/latest at request time.
    'model_prefix' => $llmModelPrefix,
    'model_aliases_json' => ft_env('LLM_MODEL_ALIASES_JSON', $llmProvider === 'openai' ? $openaiModelAliasesDefault : ''),
    'remote_image_max_bytes' => ft_env_int('LLM_REMOTE_IMAGE_MAX_BYTES', 10 * 1024 * 1024),
    'remote_image_timeout_sec' => ft_env_int('LLM_REMOTE_IMAGE_TIMEOUT_SEC', 20),
    'timeout_sec' => ft_env_int('LLM_TIMEOUT_SEC', ft_env_int('OPENAI_TIMEOUT_SEC', 60)),

    'retries' => ft_env_int('LLM_RETRIES', ft_env_int('OPENAI_RETRIES', 4)),
    'retry_base_delay_ms' => ft_env_int('LLM_RETRY_BASE_DELAY_MS', ft_env_int('OPENAI_RETRY_BASE_DELAY_MS', 250)),

    'auth_type' => ft_env('LLM_AUTH_TYPE', 'bearer'), // bearer|api_key|none|gigachat_oauth
    'auth_header' => ft_env('LLM_AUTH_HEADER', 'Authorization'),
    'auth_value_prefix' => ft_env('LLM_AUTH_VALUE_PREFIX', ''),
    'extra_headers_json' => ft_env('LLM_EXTRA_HEADERS_JSON', ''),
    'tls_verify' => ft_env_bool('LLM_TLS_VERIFY', true),

    // GigaChat OAuth: api_key = authorization key, OAuth returns short-lived access token.
    'oauth_url' => ft_env('LLM_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
    'oauth_scope' => ft_env('LLM_OAUTH_SCOPE', 'GIGACHAT_API_PERS'),
    'oauth_cache_file' => ft_env('LLM_OAUTH_CACHE_FILE', '') ?: (__DIR__ . '/../storage/cache/llm_oauth_token.json'),

    // Локальный файловый кеш полных ответов LLM.
    // Ключ строится по endpoint + model + instructions + input + options.
    'response_cache_enabled' => ft_env_bool('LLM_RESPONSE_CACHE_ENABLED', ft_env_bool('OPENAI_RESPONSE_CACHE_ENABLED', true)),
    'response_cache_ttl_sec' => ft_env_int('LLM_RESPONSE_CACHE_TTL_SEC', ft_env_int('OPENAI_RESPONSE_CACHE_TTL_SEC', 30 * 24 * 60 * 60)),
    'response_cache_dir' => $llmCacheDir,

    // Серверный OpenAI prompt caching применяется только в режиме responses.
    'prompt_cache_key_enabled' => ft_env_bool('LLM_PROMPT_CACHE_KEY_ENABLED', ft_env_bool('OPENAI_PROMPT_CACHE_KEY_ENABLED', true)),
    'prompt_cache_retention' => ft_env('LLM_PROMPT_CACHE_RETENTION', ft_env('OPENAI_PROMPT_CACHE_RETENTION', 'auto')),

    // Журнал LLM-запросов в БД для отладки промптов, кэша и стоимости.
    'request_log_enabled' => ft_env_bool('LLM_REQUEST_LOG_ENABLED', ft_env_bool('OPENAI_REQUEST_LOG_ENABLED', true)),
    'request_log_max_request_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_REQUEST_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_REQUEST_CHARS', 200000)),
    'request_log_max_response_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_RESPONSE_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_RESPONSE_CHARS', 200000)),
  ],
  'llm_providers' => $llmProviderConfigs,
  'openai' => [
    // Backward-compatible alias. Existing operation code can still read $cfg['openai'].
    'provider' => $llmProvider,
    'api_format' => $llmApiFormat,
    'api_key' => $llmApiKey,
    'base_url' => $llmBaseUrl,
    'default_model' => $llmDefaultModel,
    'vision_model' => ft_env('LLM_VISION_MODEL_DEFAULT', $llmVisionModelDefault),
    'models' => array_values(array_filter(array_map('trim', explode(',', ft_env('LLM_MODELS', $llmModelsDefault))))),
    'model_prefix' => $llmModelPrefix,
    'model_aliases_json' => ft_env('LLM_MODEL_ALIASES_JSON', $llmProvider === 'openai' ? $openaiModelAliasesDefault : ''),
    'remote_image_max_bytes' => ft_env_int('LLM_REMOTE_IMAGE_MAX_BYTES', 10 * 1024 * 1024),
    'remote_image_timeout_sec' => ft_env_int('LLM_REMOTE_IMAGE_TIMEOUT_SEC', 20),
    'timeout_sec' => ft_env_int('LLM_TIMEOUT_SEC', ft_env_int('OPENAI_TIMEOUT_SEC', 60)),
    'retries' => ft_env_int('LLM_RETRIES', ft_env_int('OPENAI_RETRIES', 4)),
    'retry_base_delay_ms' => ft_env_int('LLM_RETRY_BASE_DELAY_MS', ft_env_int('OPENAI_RETRY_BASE_DELAY_MS', 250)),
    'auth_type' => ft_env('LLM_AUTH_TYPE', 'bearer'),
    'auth_header' => ft_env('LLM_AUTH_HEADER', 'Authorization'),
    'auth_value_prefix' => ft_env('LLM_AUTH_VALUE_PREFIX', ''),
    'extra_headers_json' => ft_env('LLM_EXTRA_HEADERS_JSON', ''),
    'tls_verify' => ft_env_bool('LLM_TLS_VERIFY', true),
    'oauth_url' => ft_env('LLM_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
    'oauth_scope' => ft_env('LLM_OAUTH_SCOPE', 'GIGACHAT_API_PERS'),
    'oauth_cache_file' => ft_env('LLM_OAUTH_CACHE_FILE', '') ?: (__DIR__ . '/../storage/cache/llm_oauth_token.json'),
    'response_cache_enabled' => ft_env_bool('LLM_RESPONSE_CACHE_ENABLED', ft_env_bool('OPENAI_RESPONSE_CACHE_ENABLED', true)),
    'response_cache_ttl_sec' => ft_env_int('LLM_RESPONSE_CACHE_TTL_SEC', ft_env_int('OPENAI_RESPONSE_CACHE_TTL_SEC', 30 * 24 * 60 * 60)),
    'response_cache_dir' => $llmCacheDir,
    'prompt_cache_key_enabled' => ft_env_bool('LLM_PROMPT_CACHE_KEY_ENABLED', ft_env_bool('OPENAI_PROMPT_CACHE_KEY_ENABLED', true)),
    'prompt_cache_retention' => ft_env('LLM_PROMPT_CACHE_RETENTION', ft_env('OPENAI_PROMPT_CACHE_RETENTION', 'auto')),
    'request_log_enabled' => ft_env_bool('LLM_REQUEST_LOG_ENABLED', ft_env_bool('OPENAI_REQUEST_LOG_ENABLED', true)),
    'request_log_max_request_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_REQUEST_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_REQUEST_CHARS', 200000)),
    'request_log_max_response_chars' => ft_env_int('LLM_REQUEST_LOG_MAX_RESPONSE_CHARS', ft_env_int('OPENAI_REQUEST_LOG_MAX_RESPONSE_CHARS', 200000)),
  ],
  'openai_image' => [
    'default_model' => ft_env('OPENAI_IMAGE_MODEL_DEFAULT', 'gpt-image-2'),
    'models' => array_values(array_filter(array_map('trim', explode(',', ft_env('OPENAI_IMAGE_MODELS', $openaiImageModelsDefault))))),
  ],
  'openai_video' => [
    'default_model' => ft_env('OPENAI_VIDEO_MODEL_DEFAULT', 'sora-2'),
    'models' => array_values(array_filter(array_map('trim', explode(',', ft_env('OPENAI_VIDEO_MODELS', $openaiVideoModelsDefault))))),
  ],
  'openai_pricing' => [
    'models' => [
      'gpt-5.5' => [
        'input_per_1m' => 5.00,
        'cached_input_per_1m' => 0.50,
        'output_per_1m' => 30.00,
        'source' => 'config',
      ],
      'gpt-5.4' => [
        'input_per_1m' => 2.50,
        'cached_input_per_1m' => 0.25,
        'output_per_1m' => 15.00,
        'source' => 'config',
      ],
      'gpt-5.4-mini' => [
        'input_per_1m' => 0.75,
        'cached_input_per_1m' => 0.075,
        'output_per_1m' => 4.50,
        'source' => 'config',
      ],
      'gpt-5.4-nano' => [
        'input_per_1m' => 0.20,
        'cached_input_per_1m' => 0.02,
        'output_per_1m' => 1.25,
        'source' => 'config',
      ],
      'gpt-5.2' => [
        'input_per_1m' => 1.75,
        'cached_input_per_1m' => 0.175,
        'output_per_1m' => 14.00,
        'source' => 'config',
      ],
      'gpt-5-mini' => [
        'input_per_1m' => 0.25,
        'cached_input_per_1m' => 0.025,
        'output_per_1m' => 2.00,
        'source' => 'config',
      ],
    ],
  ],

  'pixlab' => [
    'api_key' => ft_env('PIXLAB_API_KEY', ''),
    'txtremove_url' => ft_env('PIXLAB_TXTREMOVE_URL', 'https://api.pixlab.io/txtremove'),
    'timeout_sec' => ft_env_int('PIXLAB_TIMEOUT_SEC', 90),
    'max_image_bytes' => ft_env_int('PIXLAB_MAX_IMAGE_BYTES', 10 * 1024 * 1024),
    'txtremove_requests_per_minute' => ft_env_int('PIXLAB_TXTREMOVE_REQUESTS_PER_MINUTE', 7),
    'rate_limit_retry_after_sec' => ft_env_int('PIXLAB_RATE_LIMIT_RETRY_AFTER_SEC', 65),
    'max_retries' => ft_env_int('PIXLAB_MAX_RETRIES', 3),
    'price_per_1000_requests' => ft_env_float('PIXLAB_PRICE_PER_1000_REQUESTS', 0.07),
    'cost_currency' => strtoupper(trim((string)ft_env('PIXLAB_COST_CURRENCY', 'USD')) ?: 'USD'),
  ],

  // Ozon Seller API
  // Ключи хранить в ENV или в app/config.local.php (не коммитить)
  'ozon' => [
    'client_id'   => ft_env('OZON_CLIENT_ID', ''),
    'api_key'     => ft_env('OZON_API_KEY', ''),
    'base_url'    => ft_env('OZON_BASE_URL', 'https://api-seller.ozon.ru'),
    'timeout_sec' => ft_env_int('OZON_TIMEOUT_SEC', 30),
    'exclude_attribute_names' => [
      '#Хештеги',
      'Аннотация',
      'Бренд',
      'Бренд*',
      'Документ PDF',
      'Название группы',
      'Название модели (для объединения в одну карточку)',
      'Название файла PDF',
      'Название',
      'Название товара',
      'Озон.Видео: название',
      'Озон.Видео: ссылка',
      'Озон.Видео: товары на видео',
      'Озон.Видеообложка: ссылка',
      'Тип',
      'Тип*',
      'Rich-контент JSON',
      'Rich content JSON',
    ],
  ],

  // Wildberries API
  // По документации WB авторизация идёт через Authorization: Bearer <token>.
  // Токены могут иметь разные категории доступа, поэтому поддерживаем:
  // - общий api_token как fallback
  // - отдельные content/promotion/marketplace токены
  'wildberries' => [
    'api_token' => ft_env('WB_API_TOKEN', ft_env('WILDBERRIES_API_TOKEN', '')),
    'content_token' => ft_env('WB_CONTENT_TOKEN', ''),
    'promotion_token' => ft_env('WB_PROMOTION_TOKEN', ''),
    'marketplace_token' => ft_env('WB_MARKETPLACE_TOKEN', ''),
    'content_base_url' => ft_env('WB_CONTENT_BASE_URL', 'https://content-api.wildberries.ru'),
    'marketplace_base_url' => ft_env('WB_MARKETPLACE_BASE_URL', 'https://marketplace-api.wildberries.ru'),
    'analytics_base_url' => ft_env('WB_ANALYTICS_BASE_URL', 'https://seller-analytics-api.wildberries.ru'),
    'statistics_base_url' => ft_env('WB_STATISTICS_BASE_URL', 'https://statistics-api.wildberries.ru'),
    'timeout_sec' => ft_env_int('WB_TIMEOUT_SEC', 30),
    'default_locale' => ft_env('WB_LOCALE', 'ru'),
    'rate_limit_max_attempts' => ft_env_int('WB_RATE_LIMIT_MAX_ATTEMPTS', 8),
    'rate_limit_base_delay_sec' => ft_env_int('WB_RATE_LIMIT_BASE_DELAY_SEC', 12),
    'rate_limit_max_delay_sec' => ft_env_int('WB_RATE_LIMIT_MAX_DELAY_SEC', 120),
    'min_request_interval_ms' => ft_env_int('WB_MIN_REQUEST_INTERVAL_MS', 700),
  ],
  'remote_images' => [
  'enabled' => ft_env_bool('REMOTE_IMAGES_ENABLED', false),
  'driver' => ft_env('REMOTE_IMAGES_DRIVER', 'ftp'), // ftp|http_put
  'base_url' => ft_env('REMOTE_IMAGES_BASE_URL', ''),
  'path_prefix' => ft_env('REMOTE_IMAGES_PATH_PREFIX', 'feedtools/blur'),

  'ftp' => [
    'host' => ft_env('REMOTE_IMAGES_FTP_HOST', ''),
    'port' => ft_env_int('REMOTE_IMAGES_FTP_PORT', 21),
    'user' => ft_env('REMOTE_IMAGES_FTP_USER', ''),
    'pass' => ft_env('REMOTE_IMAGES_FTP_PASS', ''),
    'root_dir' => ft_env('REMOTE_IMAGES_FTP_ROOT_DIR', ''),
    'ssl' => ft_env_bool('REMOTE_IMAGES_FTP_SSL', false),
    'passive' => ft_env_bool('REMOTE_IMAGES_FTP_PASSIVE', true),
    'timeout_sec' => ft_env_int('REMOTE_IMAGES_FTP_TIMEOUT', 30),
  ],

  'http_put' => [
    'endpoint' => ft_env('REMOTE_IMAGES_HTTP_ENDPOINT', ''),
    'auth_type' => ft_env('REMOTE_IMAGES_HTTP_AUTH_TYPE', 'bearer'), // bearer|basic|none
    'bearer_token' => ft_env('REMOTE_IMAGES_HTTP_BEARER', ''),
    'basic_user' => ft_env('REMOTE_IMAGES_HTTP_USER', ''),
    'basic_pass' => ft_env('REMOTE_IMAGES_HTTP_PASS', ''),
    'timeout_sec' => ft_env_int('REMOTE_IMAGES_HTTP_TIMEOUT', 45),
  ],
],

  'worker' => [
    'auto_spawn' => ft_env_bool('WORKER_AUTO_SPAWN', false),
    'max_parallel' => ft_env_int('WORKER_MAX_PARALLEL', 3),
    'price_tool_max_parallel' => ft_env_int('WORKER_PRICE_TOOL_MAX_PARALLEL', 3),
    'stocks_tool_automation_max_parallel' => ft_env_int('STOCKS_TOOL_AUTOMATION_MAX_PARALLEL', 1),
    'wb_promotions_max_parallel' => ft_env_int('WORKER_WB_PROMOTIONS_MAX_PARALLEL', 1),
    'marketplace_data_max_parallel' => ft_env_int('WORKER_MARKETPLACE_DATA_MAX_PARALLEL', 1),
    'supplier_feed_max_parallel' => ft_env_int('WORKER_SUPPLIER_FEED_MAX_PARALLEL', 1),
    'php_bin' => ft_env('WORKER_PHP_BIN', PHP_BINARY),
    'worker_script' => ft_env('WORKER_SCRIPT', __DIR__ . '/../bin/worker.php'),
  ],
];


$localPath = __DIR__ . '/config.local.php';
$localHasLlm = false;
if (is_file($localPath)) {
  $local = require $localPath;
  if (is_array($local)) {
    $localHasLlm = isset($local['llm']) && is_array($local['llm']);
    // Перезаписывает только те ключи, которые есть в config.local.php
    // (и делает это рекурсивно, т.е. openai/api_key или db/host и т.д.)
    $cfg = array_replace_recursive($cfg, $local);
  }
}

if (!isset($cfg['llm']) || !is_array($cfg['llm'])) {
  $cfg['llm'] = [];
}
if (!isset($cfg['openai']) || !is_array($cfg['openai'])) {
  $cfg['openai'] = [];
}
$cfg['llm'] = ($localHasLlm || $explicitLlmEnv)
  ? array_replace_recursive($cfg['openai'], $cfg['llm'])
  : array_replace_recursive($cfg['llm'], $cfg['openai']);
$cfg['openai'] = array_replace_recursive($cfg['openai'], $cfg['llm']);
$GLOBALS['__feedtools_worker_config'] = is_array($cfg['worker'] ?? null) ? $cfg['worker'] : [];

return $cfg;
