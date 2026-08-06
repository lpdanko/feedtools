<?php

return [
  'auth' => [
    'enabled' => false,
    'realm' => 'FeedTools',
    'user' => 'admin',
    'pass' => 'change-me',
    'pass_hash' => '',
  ],

  'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'feedtools',
    'user' => 'feedtools',
    'pass' => 'change-me',
    'charset' => 'utf8mb4',
    'timeout_sec' => 5,
  ],

  'llm' => [
    'provider' => 'openai',
    'api_format' => 'responses', // responses|chat_completions
    'api_key' => '',
    'base_url' => 'https://api.openai.com/v1',
    'default_model' => 'gpt-5.5',
    'vision_model' => '',
    'models' => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5.2'],
    'model_prefix' => '',
    'model_aliases_json' => '{"gpt-5.3":"gpt-5.2"}',
    'remote_image_max_bytes' => 10 * 1024 * 1024,
    'remote_image_timeout_sec' => 20,
    'auth_type' => 'bearer', // bearer|api_key|none|gigachat_oauth
    'auth_value_prefix' => '',
    'response_cache_enabled' => true,
    'response_cache_ttl_sec' => 30 * 24 * 60 * 60,
    'prompt_cache_key_enabled' => true,
    'prompt_cache_retention' => 'auto',
    'request_log_enabled' => true,
    'request_log_max_request_chars' => 200000,
    'request_log_max_response_chars' => 200000,
  ],

  // Legacy alias. Prefer 'llm' above for new installations.
  'openai' => [
    'api_key' => '',
  ],

  'openai_image' => [
    'default_model' => 'gpt-image-2',
    'models' => ['gpt-image-1-mini', 'gpt-image-1.5', 'gpt-image-2'],
  ],

  'openai_video' => [
    'default_model' => 'sora-2',
    'models' => ['sora-2', 'sora-2-2025-12-08', 'sora-2-2025-10-06', 'sora-2-pro', 'sora-2-pro-2025-10-06'],
  ],

  'pixlab' => [
    'api_key' => '',
    'txtremove_url' => 'https://api.pixlab.io/txtremove',
    'timeout_sec' => 90,
    'max_image_bytes' => 10 * 1024 * 1024,
    'txtremove_requests_per_minute' => 7,
    'rate_limit_retry_after_sec' => 65,
    'max_retries' => 3,
    'price_per_1000_requests' => 0.07,
    'cost_currency' => 'USD',
  ],

  'ozon' => [
    'client_id' => '',
    'api_key' => '',
  ],

  'wildberries' => [
    'api_token' => '',
    'content_token' => '',
    'promotion_token' => '',
    'marketplace_token' => '',
    'content_base_url' => 'https://content-api.wildberries.ru',
    'marketplace_base_url' => 'https://marketplace-api.wildberries.ru',
    'analytics_base_url' => 'https://seller-analytics-api.wildberries.ru',
    'timeout_sec' => 30,
    'default_locale' => 'ru',
  ],

  'remote_images' => [
    'enabled' => false,
    'driver' => 'ftp',
    'base_url' => 'https://feedtools.example.com/feedtools-images',
    'path_prefix' => 'feedtools/blur',
    'ftp' => [
      'host' => '127.0.0.1',
      'port' => 21,
      'user' => '',
      'pass' => '',
      'root_dir' => '/var/www/feedtools-images',
      'ssl' => false,
      'passive' => true,
      'timeout_sec' => 30,
    ],
  ],

  'worker' => [
    'auto_spawn' => false,
    'max_parallel' => 3,
    'price_tool_max_parallel' => 3,
    'wb_promotions_max_parallel' => 1,
    'marketplace_data_max_parallel' => 1,
    'supplier_feed_max_parallel' => 1,
    'php_bin' => '/usr/bin/php',
    'worker_script' => __DIR__ . '/../bin/worker.php',
  ],
];
