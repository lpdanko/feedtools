<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';
require_once __DIR__ . '/../llm/PromptTemplates.php';
require_once __DIR__ . '/../supplier_products.php';
require_once __DIR__ . '/../wildberries/WildberriesClient.php';
require_once __DIR__ . '/../wildberries/WildberriesDictionaries.php';
require_once __DIR__ . '/../taxonomy/GlobalAttributeExclusions.php';

function acp_trim(string $s): string
{
  $s = trim(html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'));
  $s = preg_replace('~\s+~u', ' ', $s);
  return trim($s);
}

function acp_trunc(string $s, int $max): string
{
  $s = acp_trim($s);
  if ($max > 0 && mb_strlen($s, 'UTF-8') > $max) {
    return mb_substr($s, 0, $max, 'UTF-8') . '…';
  }
  return $s;
}

function acp_parse_category_code(string $s): ?array
{
  $s = trim($s);
  if ($s === '') return null;
  // ожидаем "descId_typeId"
  if (!preg_match('~^(\d+)\_(\d+)$~', $s, $m)) return null;
  return [(int)$m[1], (int)$m[2]];
}

function acp_load_taxonomy_category(int $descId, int $typeId): ?array
{
  return db_exec_with_retry(function () use ($descId, $typeId) {
    $st = db()->prepare("
      SELECT * FROM feedtools_taxonomy_categories
      WHERE source='ozon' AND is_leaf=1 AND ozon_parent_id=? AND ozon_leaf_id=?
      LIMIT 1
    ");
    $st->execute([$descId, $typeId]);
    $row = $st->fetch();
    return $row ?: null;
  }, 2);
}

function acp_load_wb_taxonomy_category(int $subjectId): ?array
{
  return db_exec_with_retry(function () use ($subjectId) {
    $st = db()->prepare("
      SELECT * FROM feedtools_taxonomy_categories
      WHERE source='wildberries' AND is_leaf=1 AND external_id=?
      LIMIT 1
    ");
    $st->execute(['wb:subject:' . $subjectId]);
    $row = $st->fetch();
    return $row ?: null;
  }, 2);
}

function acp_extract_usage($raw): array
{
  $in = 0;
  $out = 0;
  $cached = 0;

  if (!is_array($raw)) return ['input' => 0, 'output' => 0, 'cached_input' => 0];

  $u = $raw['usage'] ?? null;
  if (is_array($u)) {
    $in  = (int)($u['input_tokens'] ?? 0);
    $out = (int)($u['output_tokens'] ?? 0);

    $details = $u['input_tokens_details'] ?? null;
    if (is_array($details)) {
      $cached = (int)($details['cached_tokens'] ?? 0);
      if ($cached === 0) {
        $cached = (int)($details['cached_input_tokens'] ?? 0);
      }
    }
  }

  return ['input' => $in, 'output' => $out, 'cached_input' => $cached];
}

function acp_calc_cost_usd(array $cfg, string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): float
{
  return openai_cost_usd($cfg, $model, $inputTokens, $cachedInputTokens, $outputTokens);
}


function acp_save_category_meta(int $catId, array $meta): void
{
  db_exec_with_retry(function () use ($catId, $meta) {
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
    $st = db()->prepare("UPDATE feedtools_taxonomy_categories SET meta_json=? WHERE id=? LIMIT 1");
    $st->execute([$json, $catId]);
  }, 2);
}

function acp_decode_image_api_result(array $raw): string
{
  $data = $raw['data'] ?? [];
  if (!is_array($data) || !$data) {
    throw new RuntimeException('OpenAI Image API не вернул изображение.');
  }
  $first = $data[0] ?? null;
  if (!is_array($first)) {
    throw new RuntimeException('OpenAI Image API вернул некорректный ответ.');
  }
  $b64 = trim((string)($first['b64_json'] ?? ''));
  if ($b64 === '') {
    throw new RuntimeException('OpenAI Image API не вернул b64_json.');
  }
  $bytes = base64_decode($b64, true);
  if ($bytes === false || $bytes === '' || !is_array(@getimagesizefromstring($bytes))) {
    throw new RuntimeException('OpenAI Image API вернул поврежденное изображение.');
  }
  return $bytes;
}

function acp_sanitize_image_api_response(array $raw): array
{
  $copy = $raw;
  foreach ((array)($copy['data'] ?? []) as $i => $item) {
    if (!is_array($item)) continue;
    foreach (['b64_json', 'revised_prompt'] as $key) {
      $value = (string)($item[$key] ?? '');
      if ($value === '') continue;
      $copy['data'][$i][$key . '_length'] = strlen($value);
      $copy['data'][$i][$key . '_sha256'] = hash('sha256', $value);
      unset($copy['data'][$i][$key]);
    }
  }
  return $copy;
}

function acp_image_usage(array $raw): array
{
  $usage = $raw['usage'] ?? [];
  $usage = is_array($usage) ? $usage : [];
  $input = max(0, (int)($usage['input_tokens'] ?? 0));
  $output = max(0, (int)($usage['output_tokens'] ?? 0));
  $total = max(0, (int)($usage['total_tokens'] ?? ($input + $output)));
  $detailsIn = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
  $detailsOut = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
  $textIn = max(0, (int)($detailsIn['text_tokens'] ?? 0));
  $textCached = max(0, (int)($detailsIn['cached_text_tokens'] ?? ($detailsIn['text_cached_tokens'] ?? 0)));
  $imageIn = max(0, (int)($detailsIn['image_tokens'] ?? 0));
  $imageCached = max(0, (int)($detailsIn['cached_image_tokens'] ?? ($detailsIn['image_cached_tokens'] ?? 0)));
  $cachedTotal = max(
    0,
    (int)($usage['cached_input_tokens'] ?? ($usage['cached_tokens'] ?? ($detailsIn['cached_tokens'] ?? ($detailsIn['cached_input_tokens'] ?? 0))))
  );
  if ($input > 0 && ($textIn + $imageIn) <= 0) {
    $textIn = $input;
  }
  if ($cachedTotal > ($textCached + $imageCached)) {
    $textCached = min($textIn, $cachedTotal);
    $imageCached = min($imageIn, max(0, $cachedTotal - $textCached));
  }
  $textOut = max(0, (int)($detailsOut['text_tokens'] ?? 0));
  $imageOut = max(0, (int)($detailsOut['image_tokens'] ?? 0));
  if ($output > 0 && ($textOut + $imageOut) <= 0) {
    $imageOut = $output;
  }
  return [
    'input_tokens' => $input,
    'cached_input_tokens' => min($input, max($cachedTotal, $textCached + $imageCached)),
    'text_input_tokens' => $textIn,
    'text_cached_input_tokens' => min($textIn, $textCached),
    'image_input_tokens' => $imageIn,
    'image_cached_input_tokens' => min($imageIn, $imageCached),
    'output_tokens' => $output,
    'text_output_tokens' => $textOut,
    'image_output_tokens' => $imageOut,
    'total_tokens' => $total,
  ];
}

function acp_estimate_image_usage(string $prompt, string $imageModel, string $quality, string $size, array $pricing): array
{
  $textIn = max(1, (int)ceil(max(1, mb_strlen($prompt, 'UTF-8')) / 4));
  $matched = strtolower(trim((string)($pricing['matched_model'] ?? $imageModel)));
  $quality = strtolower(trim($quality));
  $size = trim($size);
  $fixed = [
    'gpt-image-2' => [
      'low' => ['1024x1024' => 0.006, '1024x1536' => 0.005, '1536x1024' => 0.005, '1200x1600' => 0.006],
      'medium' => ['1024x1024' => 0.053, '1024x1536' => 0.041, '1536x1024' => 0.041, '1200x1600' => 0.050],
      'high' => ['1024x1024' => 0.211, '1024x1536' => 0.165, '1536x1024' => 0.165, '1200x1600' => 0.201],
    ],
  ];
  $outputPrice = (float)($fixed[$matched][$quality][$size] ?? 0.0);
  $outputRate = max(0.0, (float)($pricing['image_output_per_1m'] ?? 0.0));
  $imageOut = $outputPrice > 0.0 && $outputRate > 0.0
    ? max(1, (int)round(($outputPrice / $outputRate) * 1_000_000))
    : 0;
  return [
    'input_tokens' => $textIn,
    'cached_input_tokens' => 0,
    'text_input_tokens' => $textIn,
    'text_cached_input_tokens' => 0,
    'image_input_tokens' => 0,
    'image_cached_input_tokens' => 0,
    'output_tokens' => $imageOut,
    'text_output_tokens' => 0,
    'image_output_tokens' => $imageOut,
    'total_tokens' => $textIn + $imageOut,
    'estimated' => true,
    'estimated_output_price' => $outputPrice,
  ];
}

function acp_image_cost(array $cfg, string $imageModel, array $usage, string $quality, string $size, array $pricing): array
{
  $cost = openai_image_generation_cost_breakdown(
    $cfg,
    $imageModel,
    (int)($usage['text_input_tokens'] ?? 0),
    (int)($usage['text_cached_input_tokens'] ?? 0),
    (int)($usage['text_output_tokens'] ?? 0),
    (int)($usage['image_input_tokens'] ?? 0),
    (int)($usage['image_cached_input_tokens'] ?? 0),
    (int)($usage['image_output_tokens'] ?? 0)
  );
  $matched = strtolower(trim((string)($pricing['matched_model'] ?? $imageModel)));
  $estimatedOutputPrice = isset($usage['estimated_output_price']) ? (float)$usage['estimated_output_price'] : 0.0;
  if ($matched !== 'gpt-image-2' || $estimatedOutputPrice <= 0.0) {
    $cost['image_output_pricing_mode'] = 'token';
    $cost['image_output_size'] = $size;
    $cost['image_output_quality'] = $quality;
    return $cost;
  }

  $tokenOutputCost = (float)($cost['image_output_cost'] ?? 0.0);
  $adjustedCost = round(max(0.0, (float)($cost['cost'] ?? 0.0) - $tokenOutputCost + $estimatedOutputPrice), 6);
  $cost['image_output_pricing_mode'] = 'fixed_per_image';
  $cost['image_output_size'] = $size;
  $cost['image_output_quality'] = $quality;
  $cost['image_output_cost_token_based'] = $tokenOutputCost;
  $cost['image_output_cost'] = round($estimatedOutputPrice, 6);
  $cost['cost'] = $adjustedCost;
  $cost['cost_usd'] = $adjustedCost;
  $cost['cost_label'] = openai_format_cost($adjustedCost, (string)($cost['currency'] ?? ($pricing['currency'] ?? 'USD')));
  return $cost;
}

function acp_template_public_url(string $relativePath, array $cfg): string
{
  return supplier_products_image_url($relativePath, $cfg);
}

if (!defined('ACP_BACKGROUND_TEMPLATE_PROMPT_VERSION')) {
  define('ACP_BACKGROUND_TEMPLATE_PROMPT_VERSION', 4);
}

function acp_save_category_background_template(array $cfg, string $source, int $categoryId, string $bytes): array
{
  $info = @getimagesizefromstring($bytes);
  if (!is_array($info)) {
    throw new RuntimeException('Не удалось распознать фон-шаблон категории.');
  }
  $mime = strtolower((string)($info['mime'] ?? ''));
  $ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };
  if ($ext === '') {
    throw new RuntimeException('Фон-шаблон вернулся в неподдерживаемом формате.');
  }

  $sourceSafe = preg_replace('~[^a-zA-Z0-9_-]+~', '_', $source) ?: 'category';
  $baseDir = supplier_products_image_storage_dir($cfg);
  $relativeDir = 'taxonomy_templates/' . $sourceSafe . '/category_' . $categoryId;
  $dir = supplier_products_ensure_writable_dir($baseDir . '/' . $relativeDir, 'Не удалось создать папку шаблонов категорий.');
  $fileName = 'additional_photo_bg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
  $path = $dir . '/' . $fileName;
  if (file_put_contents($path, $bytes) === false) {
    throw new RuntimeException('Не удалось сохранить фон-шаблон категории.');
  }
  @chmod($path, 0664);

  $relativePath = $relativeDir . '/' . $fileName;
  $publicUrl = supplier_products_publish_stored_image($path, $relativePath, $cfg, true);
  return [
    'relative_path' => $relativePath,
    'url' => $publicUrl,
    'width' => (int)($info[0] ?? 0),
    'height' => (int)($info[1] ?? 0),
    'mime' => $mime,
    'bytes' => strlen($bytes),
  ];
}

function acp_category_background_template_prompt(array $catRow, string $source, string $code, string $coverDesign): string
{
  $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'category_photo_background_template_ru.txt');
  $payload = [
    'category' => [
      'source' => $source,
      'code' => $code,
      'name' => (string)($catRow['name'] ?? ''),
      'full_path' => (string)($catRow['full_path'] ?? ''),
    ],
    'cover_design' => $coverDesign,
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  return $prompt . "\n\nCATEGORY JSON AND COVER DESIGN TEXT:\n" . ($json !== false ? $json : '{}');
}

function acp_image_prompt_cache_key(string $op, string $imageModel, string $imageSize, string $imageQuality, string $prompt): string
{
  $payload = [
    'op' => $op,
    'endpoint' => 'responses_image_generation',
    'prompt_sha256' => hash('sha256', $prompt),
    'image_model' => strtolower(trim($imageModel)),
    'image_size' => strtolower(trim($imageSize)),
    'image_quality' => strtolower(trim($imageQuality)),
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    $json = hash('sha256', $prompt);
  }
  return 'img_gen:' . substr(hash('sha256', $json), 0, 48);
}

function acp_image_response_model(array $cfg): string
{
  $model = trim((string)($cfg['openai_image']['response_model'] ?? ''));
  if ($model === '') {
    $model = trim((string)($cfg['openai']['image_response_model'] ?? ''));
  }
  return $model !== '' ? $model : 'gpt-5.4-mini';
}

function acp_category_background_template_needs_update(array $meta, string $coverDesign): bool
{
  $template = is_array($meta['additional_photo_background_template'] ?? null) ? $meta['additional_photo_background_template'] : [];
  $url = trim((string)($template['url'] ?? ''));
  $hash = trim((string)($template['cover_design_sha256'] ?? ''));
  $promptVersion = (int)($template['prompt_version'] ?? 0);
  return $url === ''
    || $hash === ''
    || $promptVersion < (int)ACP_BACKGROUND_TEMPLATE_PROMPT_VERSION
    || !hash_equals($hash, hash('sha256', $coverDesign));
}

function acp_generate_category_background_template(
  array $cfg,
  array $catRow,
  array $meta,
  string $source,
  string $code,
  int $opId,
  string $outDir,
  callable $log,
  ?OpenAIClient &$imageClient,
  array &$imageTotals
): array {
  $coverDesign = trim((string)($meta['cover_design'] ?? ''));
  if ($coverDesign === '') {
    return ['status' => 'skipped', 'reason' => 'empty_cover_design', 'meta' => $meta];
  }
  if (!acp_category_background_template_needs_update($meta, $coverDesign)) {
    return ['status' => 'cached', 'meta' => $meta];
  }

  $imageModel = 'gpt-image-2';
  $imageResponseModel = acp_image_response_model($cfg);
  $imageQuality = 'medium';
  $imageSize = '1200x1600';
  $imageFormat = 'jpeg';
  $pricing = openai_image_pricing_for_model($cfg, $imageModel);
  if ((string)($pricing['source'] ?? 'unknown') === 'unknown') {
    throw new RuntimeException('Неизвестная GPT Image модель для расчета стоимости: ' . $imageModel);
  }

  if ($imageClient === null) {
    $clientConfig = $cfg['llm_providers']['openai'] ?? ($cfg['openai'] ?? []);
    if (!is_array($clientConfig) || trim((string)($clientConfig['api_key'] ?? '')) === '') {
      throw new RuntimeException('OPENAI_API_KEY не настроен для генерации фоновых шаблонов категорий.');
    }
    $clientConfig['provider'] = 'openai';
    $clientConfig['api_format'] = 'responses';
    $clientConfig['model_prefix'] = '';
    $clientConfig['timeout_sec'] = 240;
    $clientConfig['response_cache_enabled'] = false;
    $imageClient = new OpenAIClient($clientConfig);
    $log("category background image pricing: " . openai_image_pricing_debug_string($pricing) . "\n");
  }

  $categoryId = (int)($catRow['id'] ?? 0);
  $prompt = acp_category_background_template_prompt($catRow, $source, $code, $coverDesign);
  $promptCacheKey = acp_image_prompt_cache_key('category_background_template', $imageModel, $imageSize, $imageQuality, $prompt);
  $raw = $imageClient->generateImageWithResponsesTool($imageResponseModel, $imageModel, $prompt, [
    'size' => $imageSize,
    'quality' => $imageQuality,
    'output_format' => $imageFormat,
    'background' => 'opaque',
    'moderation' => 'auto',
    'prompt_cache_key' => $promptCacheKey,
  ]);
  $bytes = acp_decode_image_api_result($raw);
  $usage = acp_image_usage($raw);
  if ((int)($usage['total_tokens'] ?? 0) <= 0) {
    $usage = acp_estimate_image_usage($prompt, $imageModel, $imageQuality, $imageSize, $pricing);
    $imageTotals['estimated_jobs'] = (int)($imageTotals['estimated_jobs'] ?? 0) + 1;
  }
  $cost = acp_image_cost($cfg, $imageModel, $usage, $imageQuality, $imageSize, $pricing);

  $imageTotals['input_tokens'] = (int)($imageTotals['input_tokens'] ?? 0) + (int)($usage['input_tokens'] ?? 0);
  $imageTotals['cached_input_tokens'] = (int)($imageTotals['cached_input_tokens'] ?? 0) + (int)($usage['cached_input_tokens'] ?? 0);
  $imageTotals['output_tokens'] = (int)($imageTotals['output_tokens'] ?? 0) + (int)($usage['output_tokens'] ?? 0);
  $imageTotals['image_input_tokens'] = (int)($imageTotals['image_input_tokens'] ?? 0) + (int)($usage['image_input_tokens'] ?? 0);
  $imageTotals['image_output_tokens'] = (int)($imageTotals['image_output_tokens'] ?? 0) + (int)($usage['image_output_tokens'] ?? 0);
  $imageTotals['cost'] = round((float)($imageTotals['cost'] ?? 0.0) + (float)($cost['cost'] ?? 0.0), 6);
  $imageTotals['jobs'] = (int)($imageTotals['jobs'] ?? 0) + 1;

  $saved = acp_save_category_background_template($cfg, $source, $categoryId, $bytes);
  $template = [
    'kind' => 'additional_photo_background',
    'purpose' => 'background_for_later_product_characteristics_text',
    'prompt_version' => (int)ACP_BACKGROUND_TEMPLATE_PROMPT_VERSION,
    'layout_policy' => 'category_specific_background_no_placeholder_blocks',
    'url' => (string)$saved['url'],
    'relative_path' => (string)$saved['relative_path'],
    'width' => (int)$saved['width'],
    'height' => (int)$saved['height'],
    'mime' => (string)$saved['mime'],
    'bytes' => (int)$saved['bytes'],
    'image_model' => $imageModel,
    'image_response_model' => $imageResponseModel,
    'image_quality' => $imageQuality,
    'image_size' => $imageSize,
    'output_format' => $imageFormat,
    'prompt_cache_key' => $promptCacheKey,
    'cover_design_sha256' => hash('sha256', $coverDesign),
    'updated_by_op_id' => $opId,
    'updated_at' => date('c'),
    'usage' => $usage,
    'cost' => $cost,
  ];
  $meta['additional_photo_background_template'] = $template;

  $debugDir = $outDir . '/category_background_templates';
  ensure_dir($debugDir);
  file_put_contents(
    $debugDir . '/category_' . $categoryId . '.json',
    json_encode([
      'category_id' => $categoryId,
      'source' => $source,
      'code' => $code,
      'status' => 'generated',
      'template' => $template,
      'prompt_sha256' => hash('sha256', $prompt),
      'prompt_cache_key' => $promptCacheKey,
      'raw_sanitized' => acp_sanitize_image_api_response($raw),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  return ['status' => 'generated', 'meta' => $meta, 'template' => $template, 'usage' => $usage, 'cost' => $cost];
}

function acp_background_template_report(array $result): array
{
  $out = [
    'template_status' => (string)($result['status'] ?? 'unknown'),
  ];
  if (isset($result['reason'])) {
    $out['template_reason'] = (string)$result['reason'];
  }
  if (isset($result['error'])) {
    $out['template_error'] = (string)$result['error'];
  }
  $template = is_array($result['template'] ?? null) ? $result['template'] : [];
  if ($template) {
    $out['template_url'] = (string)($template['url'] ?? '');
    $out['template_relative_path'] = (string)($template['relative_path'] ?? '');
    $out['template_image_model'] = (string)($template['image_model'] ?? '');
    $out['template_image_quality'] = (string)($template['image_quality'] ?? '');
    $out['template_image_size'] = (string)($template['image_size'] ?? '');
    $out['template_cost_usd'] = (float)($template['cost']['cost'] ?? $template['cost']['cost_usd'] ?? 0.0);
  }
  return $out;
}

function acp_template_cost_label(array $cfg, float $costUsd): string
{
  $pricing = openai_image_pricing_for_model($cfg, 'gpt-image-2');
  return openai_format_cost(round($costUsd, 6), (string)($pricing['currency'] ?? 'USD'));
}

function acp_ozon_cfg(array $cfg): array
{
  $oz = $cfg['ozon'] ?? [];
  if (!is_array($oz)) $oz = [];
  $oz += ['client_id' => '', 'api_key' => '', 'base_url' => 'https://api-seller.ozon.ru', 'timeout_sec' => 30];

  if (trim((string)$oz['client_id']) === '') {
    $oz['client_id'] = getenv('OZON_CLIENT_ID') ?: getenv('OZON_CLIENTID') ?: '';
  }
  if (trim((string)$oz['api_key']) === '') {
    $oz['api_key'] = getenv('OZON_API_KEY') ?: getenv('OZON_APIKEY') ?: '';
  }

  if (trim((string)$oz['client_id']) === '' || trim((string)$oz['api_key']) === '') {
    throw new RuntimeException('Ozon API не настроен: задайте client_id/api_key в секции ozon (app/config.local.php или ENV)');
  }
  return $oz;
}

function acp_ozon_post_json(array $oz, string $path, array $payload): array
{
  $url = rtrim((string)$oz['base_url'], '/') . $path;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Client-Id: ' . (string)$oz['client_id'],
      'Api-Key: '   . (string)$oz['api_key'],
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => (int)($oz['timeout_sec'] ?? 30),
  ]);

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  unset($ch);

  if ($raw === false) {
    throw new RuntimeException('Ozon request failed: ' . ($curlErr ?: 'curl error'));
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new RuntimeException('Ozon вернул некорректный JSON (HTTP ' . $http . ')');
  }
  if ($http >= 400) {
    $msg = $data['message'] ?? ($data['error']['message'] ?? 'HTTP error');
    throw new RuntimeException('Ozon HTTP ' . $http . ': ' . $msg);
  }
  return $data;
}

function acp_param_bool(array $params, string $key, bool $default): bool
{
  if (!array_key_exists($key, $params)) return $default;
  $value = strtolower(trim((string)$params[$key]));
  if ($value === '') return $default;
  return !in_array($value, ['0', 'false', 'no', 'off', 'нет'], true);
}

function acp_param_int(array $params, string $key, int $default, int $min, int $max): int
{
  $value = array_key_exists($key, $params) ? trim((string)$params[$key]) : '';
  if ($value === '' || !preg_match('~^-?\d+$~', $value)) {
    $value = (string)$default;
  }
  return max($min, min($max, (int)$value));
}

function acp_table_exists(string $table): bool
{
  static $cache = [];
  $table = trim($table);
  if ($table === '') return false;
  if (array_key_exists($table, $cache)) return (bool)$cache[$table];

  try {
    $st = db()->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    $cache[$table] = (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    $cache[$table] = false;
  }
  return (bool)$cache[$table];
}

function acp_ozon_runtime_context(array $cfg, array $ds, array $params): array
{
  $requestedId = (int)($params['ozon_connection_id'] ?? ($params['connection_id'] ?? ($ds['ozon_connection_id'] ?? 0)));
  $connection = null;

  if (function_exists('ozon_price_connection_get') && $requestedId > 0) {
    $tmp = ozon_price_connection_get($requestedId, $cfg);
    if (is_array($tmp) && trim((string)($tmp['marketplace'] ?? 'ozon')) === 'ozon') {
      $connection = $tmp;
    }
  }
  if ($connection === null && function_exists('ozon_price_connection_resolve')) {
    $tmp = ozon_price_connection_resolve($requestedId > 0 ? $requestedId : null, $cfg);
    if (is_array($tmp) && trim((string)($tmp['marketplace'] ?? 'ozon')) === 'ozon') {
      $connection = $tmp;
    }
  }

  if (is_array($connection) && function_exists('ozon_price_cfg_with_connection')) {
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = acp_ozon_cfg($runtimeCfg);
    return [
      'oz' => $oz,
      'connection_id' => (int)($connection['id'] ?? 0),
      'client_id' => trim((string)($connection['client_id'] ?? ($oz['client_id'] ?? ''))),
      'connection_title' => trim((string)($connection['title'] ?? '')),
      'source' => 'marketplace_connection',
    ];
  }

  $oz = acp_ozon_cfg($cfg);
  $connectionId = 0;
  if (function_exists('ozon_products_connection_id_from_cfg')) {
    try {
      $connectionId = (int)ozon_products_connection_id_from_cfg($cfg);
    } catch (Throwable $e) {
      $connectionId = 0;
    }
  }
  if ($connectionId <= 0 && acp_table_exists('feedtools_marketplace_connections')) {
    $clientId = trim((string)($oz['client_id'] ?? ''));
    if ($clientId !== '') {
      try {
        $st = db()->prepare("
          SELECT id
          FROM feedtools_marketplace_connections
          WHERE marketplace = 'ozon' AND client_id = ?
          ORDER BY is_active DESC, sort_order ASC, id ASC
          LIMIT 1
        ");
        $st->execute([$clientId]);
        $connectionId = (int)($st->fetchColumn() ?: 0);
      } catch (Throwable $e) {
        $connectionId = 0;
      }
    }
  }

  return [
    'oz' => $oz,
    'connection_id' => $connectionId,
    'client_id' => trim((string)($oz['client_id'] ?? '')),
    'connection_title' => '',
    'source' => 'config',
  ];
}

function acp_search_queries_period(array $params): array
{
  $days = acp_param_int($params, 'search_queries_days', 28, 7, 30);
  $lagDays = acp_param_int($params, 'search_queries_lag_days', 3, 3, 14);
  $dateTo = date('Y-m-d', strtotime('-' . $lagDays . ' days'));
  $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));

  return [
    'days' => $days,
    'lag_days' => $lagDays,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'request_date_from' => $dateFrom . 'T00:00:00.000Z',
    'request_date_to' => $dateTo . 'T23:59:59.999Z',
  ];
}

function acp_search_query_norm(string $query): string
{
  $query = html_entity_decode($query, ENT_QUOTES | ENT_XML1, 'UTF-8');
  $query = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $query);
  $query = mb_strtolower($query, 'UTF-8');
  $query = str_replace('ё', 'е', $query);
  $query = preg_replace('~[^\p{L}\p{N}]+~u', ' ', (string)$query);
  $query = preg_replace('~\s+~u', ' ', (string)$query);
  return trim((string)$query);
}

function acp_search_query_metric(array $row, array $keys): float
{
  foreach ($keys as $key) {
    if (!array_key_exists($key, $row)) continue;
    $value = $row[$key];
    if (is_int($value) || is_float($value)) return (float)$value;
    $value = str_replace(',', '.', trim((string)$value));
    if ($value !== '' && is_numeric($value)) return (float)$value;
  }
  return 0.0;
}

function acp_search_query_rows(array $resp): array
{
  $result = is_array($resp['result'] ?? null) ? $resp['result'] : $resp;
  foreach (['queries', 'items', 'data'] as $key) {
    if (isset($result[$key]) && is_array($result[$key])) return $result[$key];
    if (isset($resp[$key]) && is_array($resp[$key])) return $resp[$key];
  }
  return [];
}

function acp_search_query_response_int(array $resp, string $key): int
{
  $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
  return (int)($resp[$key] ?? ($result[$key] ?? 0));
}

function acp_search_query_response_period(array $resp): array
{
  $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
  $period = $resp['analytics_period'] ?? ($result['analytics_period'] ?? []);
  return is_array($period) ? $period : [];
}

function acp_search_query_offer_candidates(array $product, string $supplierCode): array
{
  $out = [];
  foreach ([
    (string)($product['offer_id'] ?? ''),
    (function_exists('suppliers_apply_supplier_code') ? suppliers_apply_supplier_code((string)($product['offer_id'] ?? ''), $supplierCode) : ''),
    (string)($product['vendorCode'] ?? ''),
    (string)($product['vendor_code'] ?? ''),
    (string)($product['article'] ?? ''),
  ] as $candidate) {
    $candidate = trim($candidate);
    if ($candidate !== '') $out[$candidate] = true;
  }
  return array_keys($out);
}

function acp_search_query_group_offer_candidates(array $group, string $supplierCode): array
{
  $out = [];
  foreach ((array)($group['offer_ids'] ?? []) as $offerId) {
    $offerId = trim((string)$offerId);
    if ($offerId === '') continue;
    $out[$offerId] = true;
    if (function_exists('suppliers_apply_supplier_code')) {
      $coded = trim(suppliers_apply_supplier_code($offerId, $supplierCode));
      if ($coded !== '') $out[$coded] = true;
    }
  }
  foreach ((array)($group['products'] ?? []) as $product) {
    if (!is_array($product)) continue;
    foreach (acp_search_query_offer_candidates($product, $supplierCode) as $candidate) {
      $out[$candidate] = true;
    }
  }
  return array_keys($out);
}

function acp_ozon_category_skus(array $group, int $connectionId, string $supplierCode, int $limit): array
{
  $offerIds = acp_search_query_group_offer_candidates($group, $supplierCode);
  $offerIds = array_values(array_unique(array_filter($offerIds, static fn(string $value): bool => trim($value) !== '')));
  if (!$offerIds) {
    return ['skus' => [], 'rows' => [], 'offer_candidates_count' => 0, 'source_tables' => []];
  }

  if (function_exists('ozon_products_tables_ensure')) {
    try {
      ozon_products_tables_ensure();
    } catch (Throwable $e) {
      // Ниже всё равно попробуем прочитать таблицы, если они уже существуют.
    }
  }

  $skus = [];
  $rows = [];
  $sourceTables = [];
  $addRow = static function (array $row, string $sourceTable) use (&$skus, &$rows, &$sourceTables, $limit): void {
    if (count($skus) >= $limit) return;
    $sku = trim((string)($row['sku'] ?? ($row['sku_text'] ?? '')));
    if ($sku === '' || $sku === '0') return;
    if (isset($skus[$sku])) return;
    $skus[$sku] = true;
    $sourceTables[$sourceTable] = true;
    $rows[] = [
      'sku' => $sku,
      'offer_id' => trim((string)($row['offer_id'] ?? '')),
      'product_id' => isset($row['product_id']) ? (int)$row['product_id'] : null,
      'connection_id' => isset($row['connection_id']) ? (int)$row['connection_id'] : 0,
      'source_table' => $sourceTable,
    ];
  };

  if (acp_table_exists('feedtools_ozon_products')) {
    foreach (array_chunk($offerIds, 500) as $chunk) {
      if (count($skus) >= $limit) break;
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $where = "offer_id IN ({$placeholders}) AND sku IS NOT NULL AND sku > 0";
      $args = $chunk;
      if ($connectionId > 0) {
        $where = "connection_id = ? AND " . $where;
        array_unshift($args, $connectionId);
      }
      $st = db()->prepare("
        SELECT connection_id, offer_id, sku, product_id
        FROM feedtools_ozon_products
        WHERE {$where}
        ORDER BY is_active DESC, updated_at DESC
      ");
      $st->execute($args);
      while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) continue;
        $addRow($row, 'feedtools_ozon_products');
      }
    }
  }

  if (count($skus) < $limit && acp_table_exists('feedtools_ozon_product_analytics_daily')) {
    foreach (array_chunk($offerIds, 500) as $chunk) {
      if (count($skus) >= $limit) break;
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $where = "offer_id IN ({$placeholders}) AND (sku > 0 OR sku_text <> '')";
      $args = $chunk;
      if ($connectionId > 0) {
        $where = "connection_id = ? AND " . $where;
        array_unshift($args, $connectionId);
      }
      $st = db()->prepare("
        SELECT connection_id, offer_id, MAX(NULLIF(sku, 0)) AS sku, MAX(NULLIF(sku_text, '')) AS sku_text, MAX(product_id) AS product_id
        FROM feedtools_ozon_product_analytics_daily
        WHERE {$where}
        GROUP BY connection_id, offer_id, sku_text
        ORDER BY MAX(analytics_date) DESC
      ");
      $st->execute($args);
      while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) continue;
        $addRow($row, 'feedtools_ozon_product_analytics_daily');
      }
    }
  }

  return [
    'skus' => array_keys($skus),
    'rows' => $rows,
    'offer_candidates_count' => count($offerIds),
    'source_tables' => array_keys($sourceTables),
  ];
}

function acp_ozon_fetch_product_query_details(array $oz, array $skus, array $period, int $limitBySku, int $pageSize, int $maxPages): array
{
  $skus = array_values(array_unique(array_filter(array_map(
    static fn($sku): string => trim((string)$sku),
    $skus
  ), static fn(string $sku): bool => $sku !== '')));
  if (!$skus) {
    return ['rows' => [], 'page_count' => 0, 'total' => 0, 'analytics_period' => [], 'pages_fetched' => 0];
  }

  $rows = [];
  $pageCountMax = 0;
  $totalMax = 0;
  $analyticsPeriod = [];
  $pagesFetched = 0;

  foreach (array_chunk($skus, 1000) as $skuChunk) {
    $page = 0;
    while ($page < $maxPages) {
      $payload = [
        'date_from' => (string)$period['request_date_from'],
        'date_to' => (string)$period['request_date_to'],
        'limit_by_sku' => $limitBySku,
        'page' => $page,
        'page_size' => $pageSize,
        'skus' => $skuChunk,
        'sort_by' => 'BY_SEARCHES',
        'sort_dir' => 'DESCENDING',
      ];
      $resp = acp_ozon_post_json($oz, '/v1/analytics/product-queries/details', $payload);
      $pagesFetched++;
      $pageRows = acp_search_query_rows($resp);
      foreach ($pageRows as $row) {
        if (is_array($row)) $rows[] = $row;
      }

      $pageCount = acp_search_query_response_int($resp, 'page_count');
      $total = acp_search_query_response_int($resp, 'total');
      if ($pageCount > $pageCountMax) $pageCountMax = $pageCount;
      if ($total > $totalMax) $totalMax = $total;
      $periodResp = acp_search_query_response_period($resp);
      if ($periodResp) $analyticsPeriod = $periodResp;

      if ($pageCount <= 0 || ($page + 1) >= $pageCount) {
        break;
      }
      $page++;
    }
  }

  return [
    'rows' => $rows,
    'page_count' => $pageCountMax,
    'total' => $totalMax,
    'analytics_period' => $analyticsPeriod,
    'pages_fetched' => $pagesFetched,
  ];
}

function acp_aggregate_key_search_queries(array $rows, int $topLimit): array
{
  $agg = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)($row['query'] ?? ($row['search_query'] ?? ($row['text'] ?? ''))));
    $norm = acp_search_query_norm($query);
    if ($norm === '' || mb_strlen($norm, 'UTF-8') < 2) continue;

    if (!isset($agg[$norm])) {
      $agg[$norm] = [
        'query' => $query,
        'normalized_query' => $norm,
        'rows_count' => 0,
        'unique_search_users_sum' => 0.0,
        'unique_view_users_sum' => 0.0,
        'order_count_sum' => 0.0,
        'gmv_sum' => 0.0,
        '_position_sum' => 0.0,
        '_position_weight' => 0.0,
        '_view_conversion_sum' => 0.0,
        '_view_conversion_weight' => 0.0,
        '_skus' => [],
      ];
    }

    $searchUsers = acp_search_query_metric($row, ['unique_search_users', 'search_users', 'searches']);
    $viewUsers = acp_search_query_metric($row, ['unique_view_users', 'view_users', 'views']);
    $orders = acp_search_query_metric($row, ['order_count', 'orders']);
    $gmv = acp_search_query_metric($row, ['gmv', 'revenue']);
    $position = acp_search_query_metric($row, ['position', 'avg_position']);
    $viewConversion = acp_search_query_metric($row, ['view_conversion']);
    $sku = trim((string)($row['sku'] ?? ''));

    $agg[$norm]['rows_count']++;
    $agg[$norm]['unique_search_users_sum'] += $searchUsers;
    $agg[$norm]['unique_view_users_sum'] += $viewUsers;
    $agg[$norm]['order_count_sum'] += $orders;
    $agg[$norm]['gmv_sum'] += $gmv;
    if ($position > 0) {
      $weight = max(1.0, $searchUsers);
      $agg[$norm]['_position_sum'] += $position * $weight;
      $agg[$norm]['_position_weight'] += $weight;
    }
    if ($viewConversion > 0) {
      $weight = max(1.0, $searchUsers);
      $agg[$norm]['_view_conversion_sum'] += $viewConversion * $weight;
      $agg[$norm]['_view_conversion_weight'] += $weight;
    }
    if ($sku !== '') {
      $agg[$norm]['_skus'][$sku] = true;
    }
  }

  $out = [];
  foreach ($agg as $item) {
    $searchUsers = (float)$item['unique_search_users_sum'];
    $viewUsers = (float)$item['unique_view_users_sum'];
    $orders = (float)$item['order_count_sum'];
    $gmv = (float)$item['gmv_sum'];
    $avgPosition = (float)$item['_position_weight'] > 0 ? ((float)$item['_position_sum'] / (float)$item['_position_weight']) : null;
    $avgViewConversion = (float)$item['_view_conversion_weight'] > 0 ? ((float)$item['_view_conversion_sum'] / (float)$item['_view_conversion_weight']) : null;
    $score = $searchUsers + ($viewUsers * 1.5) + ($orders * 30.0) + min(2000.0, $gmv / 100.0);
    if ($avgPosition !== null) {
      $score += max(0.0, 50.0 - min(50.0, $avgPosition));
    }

    $skus = array_keys((array)$item['_skus']);
    $out[] = [
      'query' => (string)$item['query'],
      'normalized_query' => (string)$item['normalized_query'],
      'priority_score' => round($score, 4),
      'unique_search_users_sum' => (int)round($searchUsers),
      'unique_view_users_sum' => (int)round($viewUsers),
      'view_conversion_avg' => $avgViewConversion !== null ? round($avgViewConversion, 4) : null,
      'order_count_sum' => (int)round($orders),
      'gmv_sum' => round($gmv, 2),
      'position_avg' => $avgPosition !== null ? round($avgPosition, 4) : null,
      'rows_count' => (int)$item['rows_count'],
      'skus_count' => count($skus),
      'skus_sample' => array_slice($skus, 0, 10),
    ];
  }

  usort($out, static function (array $a, array $b): int {
    $scoreCmp = (float)($b['priority_score'] ?? 0.0) <=> (float)($a['priority_score'] ?? 0.0);
    if ($scoreCmp !== 0) return $scoreCmp;
    return (int)($b['unique_search_users_sum'] ?? 0) <=> (int)($a['unique_search_users_sum'] ?? 0);
  });

  $out = array_slice($out, 0, $topLimit);
  foreach ($out as $idx => &$item) {
    $item['rank'] = $idx + 1;
  }
  unset($item);
  return $out;
}

function acp_collect_category_key_search_queries(
  array $cfg,
  array $ds,
  array $params,
  array $group,
  array $catRow,
  string $source,
  string $code,
  int $opId,
  int $supplierId,
  string $supplierCode
): array {
  $updatedAt = date('c');
  $period = acp_search_queries_period($params);
  $base = [
    'status' => 'skipped',
    'source' => 'ozon_product_queries_details',
    'marketplace' => $source,
    'category_id' => (int)($catRow['id'] ?? 0),
    'category_code' => $code,
    'updated_at' => $updatedAt,
    'updated_by_op_id' => $opId,
    'supplier_id' => $supplierId,
    'period' => [
      'date_from' => $period['date_from'],
      'date_to' => $period['date_to'],
      'days' => $period['days'],
      'lag_days' => $period['lag_days'],
    ],
    'scope' => [
      'products_total' => (int)($group['total'] ?? 0),
      'products_sample_count' => count((array)($group['products'] ?? [])),
    ],
    'queries' => [],
  ];

  if (!acp_param_bool($params, 'search_queries_enabled', true)) {
    return array_replace($base, ['reason' => 'disabled']);
  }
  if ($source !== 'ozon') {
    return array_replace($base, ['reason' => 'unsupported_source']);
  }

  try {
    $ctx = acp_ozon_runtime_context($cfg, $ds, $params);
    $connectionId = (int)($ctx['connection_id'] ?? 0);
    $skuLimit = acp_param_int($params, 'search_queries_sku_limit', 300, 1, 1000);
    $limitBySku = acp_param_int($params, 'search_queries_limit_by_sku', 10, 1, 15);
    $pageSize = acp_param_int($params, 'search_queries_page_size', 100, 1, 100);
    $maxPages = acp_param_int($params, 'search_queries_max_pages', 20, 1, 100);
    $topLimit = acp_param_int($params, 'search_queries_top_limit', 80, 1, 250);

    $skuData = acp_ozon_category_skus($group, $connectionId, $supplierCode, $skuLimit);
    $skus = (array)($skuData['skus'] ?? []);
    if (!$skus) {
      return array_replace($base, [
        'reason' => 'no_ozon_skus',
        'connection_id' => $connectionId,
        'client_id' => (string)($ctx['client_id'] ?? ''),
        'scope' => array_replace($base['scope'], [
          'offer_candidates_count' => (int)($skuData['offer_candidates_count'] ?? 0),
          'skus_count' => 0,
        ]),
      ]);
    }

    $raw = acp_ozon_fetch_product_query_details((array)$ctx['oz'], $skus, $period, $limitBySku, $pageSize, $maxPages);
    $queries = acp_aggregate_key_search_queries((array)($raw['rows'] ?? []), $topLimit);
    return array_replace($base, [
      'status' => 'ok',
      'connection_id' => $connectionId,
      'client_id' => (string)($ctx['client_id'] ?? ''),
      'connection_title' => (string)($ctx['connection_title'] ?? ''),
      'api' => [
        'endpoint' => '/v1/analytics/product-queries/details',
        'sort_by' => 'BY_SEARCHES',
        'sort_dir' => 'DESCENDING',
        'limit_by_sku' => $limitBySku,
        'page_size' => $pageSize,
        'max_pages' => $maxPages,
        'pages_fetched' => (int)($raw['pages_fetched'] ?? 0),
        'page_count' => (int)($raw['page_count'] ?? 0),
        'total' => (int)($raw['total'] ?? 0),
        'analytics_period' => is_array($raw['analytics_period'] ?? null) ? $raw['analytics_period'] : [],
      ],
      'scope' => array_replace($base['scope'], [
        'offer_candidates_count' => (int)($skuData['offer_candidates_count'] ?? 0),
        'skus_count' => count($skus),
        'sku_source_tables' => (array)($skuData['source_tables'] ?? []),
        'skus_sample' => array_slice($skus, 0, 20),
      ]),
      'rows_count' => count((array)($raw['rows'] ?? [])),
      'queries_count' => count($queries),
      'queries' => $queries,
    ]);
  } catch (Throwable $e) {
    return array_replace($base, [
      'status' => 'error',
      'error' => $e->getMessage(),
    ]);
  }
}

function acp_key_search_queries_keyword_lines(array $result, int $limit): string
{
  if ((string)($result['status'] ?? '') !== 'ok') return '';
  $lines = [];
  foreach ((array)($result['queries'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)($row['query'] ?? ''));
    if ($query === '') continue;
    $lines[] = $query;
    if (count($lines) >= $limit) break;
  }
  return implode("\n", array_values(array_unique($lines)));
}

function acp_apply_key_search_queries_to_meta(array $meta, array $result, int $keywordLinesLimit): array
{
  $meta['key_search_queries'] = $result;
  $status = (string)($result['status'] ?? '');
  if ($status === 'ok') {
    $lines = acp_key_search_queries_keyword_lines($result, $keywordLinesLimit);
    $meta['search_queries_keywords_lines'] = $lines;
    $prevSource = trim((string)($meta['keywords_lines_source'] ?? ''));
    $current = trim((string)($meta['keywords_lines'] ?? ''));
    if ($lines !== '' && ($current === '' || $prevSource === 'key_search_queries')) {
      $meta['keywords_lines'] = $lines;
      $meta['keywords_lines_source'] = 'key_search_queries';
    }
  }

  $meta['category_analysis'] = is_array($meta['category_analysis'] ?? null) ? $meta['category_analysis'] : [];
  $meta['category_analysis']['key_search_queries_status'] = $status !== '' ? $status : 'unknown';
  $meta['category_analysis']['key_search_queries_updated_at'] = (string)($result['updated_at'] ?? date('c'));
  $meta['category_analysis']['key_search_queries_count'] = (int)($result['queries_count'] ?? count((array)($result['queries'] ?? [])));
  $meta['category_analysis']['key_search_queries_period'] = (array)($result['period'] ?? []);

  return $meta;
}

function acp_key_search_queries_report(array $result): array
{
  $period = is_array($result['period'] ?? null) ? $result['period'] : [];
  $scope = is_array($result['scope'] ?? null) ? $result['scope'] : [];
  $out = [
    'key_search_queries_status' => (string)($result['status'] ?? ''),
    'key_search_queries_count' => (int)($result['queries_count'] ?? count((array)($result['queries'] ?? []))),
    'key_search_queries_skus_count' => (int)($scope['skus_count'] ?? 0),
  ];
  if (!empty($period['date_from']) && !empty($period['date_to'])) {
    $out['key_search_queries_period'] = (string)$period['date_from'] . '..' . (string)$period['date_to'];
  }
  if (!empty($result['reason'])) {
    $out['key_search_queries_reason'] = (string)$result['reason'];
  }
  if (!empty($result['error'])) {
    $out['key_search_queries_error'] = (string)$result['error'];
  }
  return $out;
}

function acp_ozon_norm_attr_name(string $s): string
{
  $s = trim($s);
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace('ё', 'е', $s);
  $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return trim($s);
}

function acp_ozon_is_tnved_attr_name(string $name): bool
{
  $norm = acp_ozon_norm_attr_name($name);
  return in_array($norm, [
    'тн вэд',
    'тнвэд',
    'тн вэд коды еаэс',
    'код тн вэд',
    'коды тн вэд',
    'tn ved',
    'tnved',
    'tnved code',
  ], true)
    || str_contains($norm, 'тн вэд')
    || str_contains($norm, 'тнвэд')
    || str_contains($norm, 'tnved');
}

function acp_ozon_tnved_allowed_values_from_meta(array $attrsMeta): array
{
  $out = [];
  $seen = [];
  foreach ($attrsMeta as $row) {
    if (!is_array($row)) continue;
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '' || !acp_ozon_is_tnved_attr_name($name)) continue;
    foreach ((array)($row['allowed_values'] ?? []) as $value) {
      $value = trim((string)$value);
      $key = acp_ozon_norm_attr_name($value);
      if ($value === '' || $key === '' || isset($seen[$key])) continue;
      $seen[$key] = true;
      $out[] = $value;
    }
  }
  return $out;
}

function acp_wb_norm_attr_name(string $s): string
{
  $s = trim($s);
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace('ё', 'е', $s);
  $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return trim($s);
}

function acp_ozon_attribute_values(array $oz, int $descriptionCategoryId, int $typeId, int $attributeId, string $language = 'RU', int $limitTotal = 500): array
{
  static $cache = [];
  $key = $descriptionCategoryId . ':' . $typeId . ':' . $attributeId . ':' . $language . ':' . $limitTotal;
  if (isset($cache[$key])) return $cache[$key];

  $values = [];
  $seen = [];

  $lastValueId = 0;
  $safety = 0;

  while ($safety++ < 100 && count($values) < $limitTotal) {
    $resp = acp_ozon_post_json($oz, '/v1/description-category/attribute/values', [
      'description_category_id' => $descriptionCategoryId,
      'type_id' => $typeId,
      'attribute_id' => $attributeId,
      'language' => $language,
      'last_value_id' => $lastValueId,
      'limit' => min(1000, max(1, $limitTotal - count($values))),
    ]);

    $result = $resp['result'] ?? [];
    if (!is_array($result)) $result = [];

    // в разных ответах список может лежать либо в result, либо в result.values
    $items = $result;
    if (isset($result['values']) && is_array($result['values'])) {
      $items = $result['values'];
    }

    if (!is_array($items) || !$items) break;

    $maxId = $lastValueId;

    foreach ($items as $it) {
      if (!is_array($it)) continue;

      $v = trim((string)($it['value'] ?? ''));
      if ($v === '') continue;

      $vk = acp_ozon_norm_attr_name($v);
      if ($vk !== '' && !isset($seen[$vk])) {
        $seen[$vk] = 1;
        $values[] = $v;
        if (count($values) >= $limitTotal) break;
      }

      $vid = isset($it['id']) ? (int)$it['id'] : 0;
      if ($vid > $maxId) $maxId = $vid;
    }

    $hasNext = array_key_exists('has_next', $resp) ? (bool)$resp['has_next'] : (bool)($result['has_next'] ?? false);
    if (!$hasNext || count($values) >= $limitTotal) break;

    $next = isset($resp['last_value_id'])
      ? (int)$resp['last_value_id']
      : (isset($result['last_value_id']) ? (int)$result['last_value_id'] : $maxId);
    if ($next <= $lastValueId) break;

    $lastValueId = $next;
  }

  $cache[$key] = $values;
  return $values;
}


function acp_ozon_required_attr_data(array $oz, int $descriptionCategoryId, int $typeId, array $excludeNames = []): array
{

  $resp = acp_ozon_post_json($oz, '/v1/description-category/attribute', [
    'description_category_id' => $descriptionCategoryId,
    'type_id' => $typeId,
  ]);

  $items = $resp['result'] ?? [];
  if (!is_array($items)) $items = [];

  if (isset($items['attributes']) && is_array($items['attributes'])) {
    $items = $items['attributes'];
  }

  $exclude = [];
  foreach ($excludeNames as $x) {
    $x = trim((string)$x);
    if ($x === '') continue;
    $exclude[acp_ozon_norm_attr_name($x)] = true;
  }

  $lines = []; // только имена (для ozon_required_attributes)
  $meta  = []; // имя+описание+id (для ozon_required_attributes_meta)
  $seen  = [];

  foreach ($items as $a) {
    if (!is_array($a)) continue;

    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $nk = acp_ozon_norm_attr_name($name);
    if ($nk !== '' && isset($exclude[$nk])) continue;

    if (isset($seen[$nk])) continue;
    $seen[$nk] = 1;

    $desc = trim((string)($a['description'] ?? ''));
    $id = isset($a['id']) ? (int)$a['id'] : 0;
    $dictionaryId = (int)($a['dictionary_id'] ?? 0);
    $allowedValues = [];
    $isTnved = acp_ozon_is_tnved_attr_name($name);

    // Если Ozon отдал dictionary_id, значит значение выбирается из справочника.
    // Описание не всегда содержит фразу "выберите из списка", поэтому опираемся на сам флаг словаря.
    $descLower = str_replace('ё', 'е', mb_strtolower($desc, 'UTF-8'));
    $shouldFetchValues = $id > 0
      && $dictionaryId > 0
      && !in_array($nk, ['бренд', 'бренд товара'], true)
      && (
        $isTnved
        || (
          !str_contains($descLower, 'любой удобный формат')
          && !str_contains($descLower, 'можно указать только целое число')
          && !str_contains($descLower, 'десятичную дробь')
          && (
            str_contains($descLower, 'спис')
            || str_contains($descLower, 'одно значение')
            || in_array($nk, ['цвет', 'цвет товара', 'название цвета', 'основной цвет', 'материал', 'материал изделия', 'основной материал', 'страна производства', 'страна изготовитель', 'страна производитель', 'пол', 'сезон', 'вид выпуска товара'], true)
          )
        )
      );
    if ($shouldFetchValues) {
      $limitTotal = $isTnved ? 1000 : 500;
      $vals = acp_ozon_attribute_values($oz, $descriptionCategoryId, $typeId, $id, 'RU', $limitTotal);

      if ($vals) {
        $allowedValues = array_slice(array_values(array_unique($vals)), 0, $limitTotal);
        $desc = trim($desc);
      }
    }


    $id = isset($a['id']) ? (int)$a['id'] : 0;

    $lines[] = $name;
    $meta[$nk] = [
      'name' => $name,
      'description' => $desc,
      'id' => $id,
      'attribute_complex_id' => (int)($a['attribute_complex_id'] ?? ($a['complex_id'] ?? 0)),
      'required' => !empty($a['is_required']) || !empty($a['required']),
      'dictionary_id' => $dictionaryId,
    ];
    $meta[$nk]['allowed_values'] = $allowedValues; // [] если нет
    $meta[$nk]['selection_mode'] = $allowedValues ? 'choose_one' : ($dictionaryId > 0 ? 'dictionary' : 'free');
    $meta[$nk]['value_source']   = $allowedValues ? 'ozon_dictionary' : ($dictionaryId > 0 ? 'ozon_dictionary' : 'free_text');
  }

  return ['lines' => $lines, 'meta' => $meta];
}

function acp_wb_cfg(array $cfg): array
{
  $wb = $cfg['wildberries'] ?? [];
  if (!is_array($wb)) $wb = [];
  return $wb;
}

function acp_wb_characteristics_data(WildberriesClient $wb, int $subjectId, array $excludeNames = []): array
{
  $resp = $wb->getSubjectCharacteristics($subjectId);
  $items = $resp['data'] ?? [];
  if (!is_array($items)) $items = [];

  $exclude = [];
  foreach ($excludeNames as $x) {
    $x = trim((string)$x);
    if ($x === '') continue;
    $exclude[acp_wb_norm_attr_name($x)] = true;
  }

  $lines = [];
  $meta = [];
  $seen = [];

  foreach ($items as $a) {
    if (!is_array($a)) continue;

    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $nk = acp_wb_norm_attr_name($name);
    if ($nk !== '' && isset($exclude[$nk])) continue;
    if (isset($seen[$nk])) continue;
    $seen[$nk] = 1;

    $row = [
      'name' => $name,
      'id' => (int)($a['charcID'] ?? 0),
      'required' => !empty($a['required']),
      'unit' => trim((string)($a['unitName'] ?? '')),
      'max_count' => (int)($a['maxCount'] ?? 0),
      'popular' => !empty($a['popular']),
      'charc_type' => (int)($a['charcType'] ?? 0),
      'is_variable' => !empty($a['isVariable']),
      'subject_id' => (int)($a['subjectID'] ?? 0),
      'subject_name' => trim((string)($a['subjectName'] ?? '')),
    ];
    $row = wb_dict_enrich_characteristic_meta($wb, $row);

    $meta[$nk] = $row;

    $lines[] = $name;
  }

  return ['lines' => $lines, 'meta' => $meta];
}


/**
 * analyze_category_products
 */
function op_analyze_category_products(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $datasetId = (int)$ds['id'];
  $inputPath = (string)($ds['stored_path'] ?? '');
  if ($inputPath === '' || !is_file($inputPath)) {
    throw new RuntimeException("Input XML not found: {$inputPath}");
  }

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $model = LLM::modelForOp($cfg, $params);

  $maxCats  = isset($params['max_categories']) ? (int)$params['max_categories'] : 0; // 0=all
  if ($maxCats < 0) $maxCats = 0;

  $maxPerCat = isset($params['max_products_per_category']) ? (int)$params['max_products_per_category'] : 60;
  if ($maxPerCat < 5) $maxPerCat = 5;
  $searchQueriesEnabled = acp_param_bool($params, 'search_queries_enabled', true);
  $searchQuerySkuLimit = acp_param_int($params, 'search_queries_sku_limit', 300, 1, 1000);
  $searchQueryOfferLimit = min(3000, max($maxPerCat, $searchQuerySkuLimit * 3));
  $searchQueryKeywordLinesLimit = acp_param_int($params, 'search_queries_keyword_lines_limit', 40, 1, 120);

  // Всегда перезаписываем данные категории при каждом запуске операции
  $onlyMissing = false;

  // Эти флаги больше не влияют на выполнение (оставляем переменную, чтобы ниже код не ломать)
  $refreshFilledIfChanged = false;



  $descMax = isset($params['max_desc_len']) ? (int)$params['max_desc_len'] : 280;
  if ($descMax < 0) $descMax = 0;

  $log("analyze_category_products: model={$model}, max_categories={$maxCats}, max_products_per_category={$maxPerCat}, overwrite=1, search_queries=" . ($searchQueriesEnabled ? '1' : '0') . "\n");


  $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'category_analyze.txt');
  $client = LLM::client($cfg, $model);
  $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");
  $tokensInTotal = 0;
  $tokensOutTotal = 0;
  $tokensCachedTotal = 0;
  $costUsdTotal = 0.0;
  $backgroundImageClient = null;
  $backgroundImageTotals = [
    'jobs' => 0,
    'generated' => 0,
    'cached' => 0,
    'skipped' => 0,
    'errors' => 0,
    'input_tokens' => 0,
    'cached_input_tokens' => 0,
    'output_tokens' => 0,
    'image_input_tokens' => 0,
    'image_output_tokens' => 0,
    'estimated_jobs' => 0,
    'cost' => 0.0,
  ];


  // 1) scan dataset, group by ozon_category and wb_category
  ops_update_progress($opId, 0, 100, 'scan', 'Scanning dataset and grouping by marketplace categories');

  $groups = []; // "<source>:<code>" => ['source','code','total','products']
  $offersSeen = 0;

  $reader = new XMLReader();
  if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException("Cannot open XML: {$inputPath}");
  }

  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

    $offersSeen++;
    $offerDepth = $reader->depth;

    $offerId = (string)$reader->getAttribute('id');
    $name = '';
    $vendorCode = '';
    $brand = '';
    $vendor = '';
    $modelVal = '';
    $desc = '';
    $ozonCat = '';
    $wbCategory = '';
    $paramsArr = [];

    while ($reader->read()) {
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
        break;
      }
      if ($reader->nodeType !== XMLReader::ELEMENT) continue;

      $tag = $reader->name;

      if ($tag === 'param') {
        $pName = acp_trim((string)$reader->getAttribute('name'));
        $pVal  = acp_trunc((string)$reader->readString(), 180);
        if ($pName !== '' && $pVal !== '') {
          $paramsArr[] = ['name' => $pName, 'value' => $pVal];
        }
        continue;
      }

      if (in_array($tag, ['name', 'vendorCode', 'brand', 'vendor', 'model', 'description', 'ozon_category', 'wb_category', 'wb_subject_id'], true)) {
        $val = $reader->readString();
        $val = acp_trim($val);

        if ($tag === 'name') $name = $val;
        elseif ($tag === 'vendorCode') $vendorCode = $val;
        elseif ($tag === 'brand') $brand = $val;
        elseif ($tag === 'vendor') $vendor = $val;
        elseif ($tag === 'model') $modelVal = $val;
        elseif ($tag === 'description') $desc = $val; // позже обрежем
        elseif ($tag === 'ozon_category') $ozonCat = $val;
        elseif ($tag === 'wb_category' || $tag === 'wb_subject_id') $wbCategory = $val;
      }
    }

    $ozonCat = trim($ozonCat);
    $wbCategory = trim($wbCategory);
    if ($ozonCat === '' && $wbCategory === '') continue;

    // --- score: чем больше param и чем информативнее description, тем выше
    $paramsCnt = count($paramsArr);

    $descTxt = $desc !== '' ? acp_trim(strip_tags($desc)) : '';
    $descLen = $descTxt !== '' ? mb_strlen($descTxt, 'UTF-8') : 0;

    // весим параметры и описание
    $score =
      ($paramsCnt * 5) +                      // много характеристик
      ($descLen > 40 ? 30 : 0) +              // есть осмысленное описание
      (int)min(50, ($descLen / 40));          // небольшой бонус за длину, с потолком

    $product = [
      '_score' => $score,
      'offer_id' => $offerId,
      'name' => acp_trunc($name, 180),
      'vendorCode' => acp_trunc($vendorCode, 80),
      'brand' => acp_trunc($brand, 80),
      'vendor' => acp_trunc($vendor, 80),
      'model' => acp_trunc($modelVal, 80),
      'params' => array_slice($paramsArr, 0, 25),
      'description_snippet' => $descMax > 0 ? acp_trunc($descTxt, $descMax) : '',
    ];

    $targets = [];
    if ($ozonCat !== '') $targets[] = ['source' => 'ozon', 'code' => $ozonCat];
    if ($wbCategory !== '') $targets[] = ['source' => 'wildberries', 'code' => $wbCategory];

    foreach ($targets as $target) {
      $groupKey = $target['source'] . ':' . $target['code'];
      if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
          'source' => $target['source'],
          'code' => $target['code'],
          'total' => 0,
          'products' => [],
          'offer_ids' => [],
          '_offer_id_set' => [],
        ];
      }
      $groups[$groupKey]['total']++;
      if ($offerId !== '' && count($groups[$groupKey]['offer_ids']) < $searchQueryOfferLimit && empty($groups[$groupKey]['_offer_id_set'][$offerId])) {
        $groups[$groupKey]['offer_ids'][] = $offerId;
        $groups[$groupKey]['_offer_id_set'][$offerId] = true;
      }

      $bucket = &$groups[$groupKey]['products'];
      if (count($bucket) < $maxPerCat) {
        $bucket[] = $product;
      } else {
        $minIdx = 0;
        $minScore = $bucket[0]['_score'] ?? -999999;
        for ($i = 1; $i < $maxPerCat; $i++) {
          $s = $bucket[$i]['_score'] ?? -999999;
          if ($s < $minScore) {
            $minScore = $s;
            $minIdx = $i;
          }
        }
        if ($score > $minScore) {
          $bucket[$minIdx] = $product;
        }
      }
      unset($bucket);
    }


    if (($offersSeen % 2000) === 0) {
      ops_update_progress($opId, 5, 100, 'scan', "Scanning offers: {$offersSeen}");
    }
  }

  $reader->close();

  if (!$groups) {
    throw new RuntimeException("No offers with <ozon_category> or <wb_category> found in dataset");
  }

  // финальная сортировка sample внутри каждой категории (по убыванию score)
  foreach ($groups as $groupKey => &$g) {
    if (!empty($g['products'])) {
      usort($g['products'], function ($a, $b) {
        return (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0);
      });
      // отрезаем до maxPerCat на всякий
      $g['products'] = array_slice($g['products'], 0, $maxPerCat);
      // удаляем служебный ключ
      foreach ($g['products'] as &$p) {
        unset($p['_score']);
      }
      unset($p);
    }
    unset($g['_offer_id_set']);
  }
  unset($g);


  // deterministic order: biggest categories first
  uasort($groups, fn($a, $b) => ($b['total'] <=> $a['total']));

  $groupKeys = array_keys($groups);
  if ($maxCats > 0) $groupKeys = array_slice($groupKeys, 0, $maxCats);

  $totalCats = count($groupKeys);

  // 1.5) перед GPT: массово заполняем обязательные атрибуты категорий из Ozon API (с описаниями)
  $oz = null;
  try {
    $oz = acp_ozon_cfg($cfg);
  } catch (Throwable $e) {
    // не валим всю операцию, но логируем: GPT-анализ может быть полезен и без атрибутов
    $log("WARN ozon cfg: " . $e->getMessage() . "\n");
  }
  $wb = null;
  try {
    $wb = new WildberriesClient(acp_wb_cfg($cfg));
  } catch (Throwable $e) {
    $log("WARN wb cfg: " . $e->getMessage() . "\n");
  }

  $globalOzonExcludeNames = taxonomy_get_global_exclude_attribute_names('ozon', $cfg);
  $globalWbExcludeNames = taxonomy_get_global_exclude_attribute_names('wildberries', $cfg);


  // debug outputs
  $gptJsonlAbs = $outDir . '/gpt_results.jsonl';
  $rawJsonlAbs = $outDir . '/gpt_raw.jsonl';
  $templateJsonlAbs = $outDir . '/category_background_templates.jsonl';
  $searchQueriesJsonlAbs = $outDir . '/category_search_queries.jsonl';
  @file_put_contents($gptJsonlAbs, '');
  @file_put_contents($rawJsonlAbs, '');
  @file_put_contents($templateJsonlAbs, '');
  @file_put_contents($searchQueriesJsonlAbs, '');

  $report = [
    'summary' => [
      'offers_seen' => $offersSeen,
      'categories_found_in_feed' => count($groups),
      'categories_planned' => $totalCats,
      'model' => $model,
      'max_products_per_category' => $maxPerCat,
      'overwrite' => 1,
      'key_search_queries' => [
        'enabled' => $searchQueriesEnabled ? 1 : 0,
        'sku_limit' => $searchQuerySkuLimit,
        'offer_id_limit' => $searchQueryOfferLimit,
      ],

    ],
    'categories' => [],
  ];

  // 2) process categories
  $done = 0;
  foreach ($groupKeys as $groupKey) {
    $done++;
    $group = $groups[$groupKey];
    $source = (string)($group['source'] ?? '');
    $code = (string)($group['code'] ?? '');
    ops_update_progress($opId, 5 + (int)(90 * ($done / max(1, $totalCats))), 100, 'gpt', "Category {$done}/{$totalCats}: {$source}:{$code}");

    $descId = 0;
    $typeId = 0;
    $catRow = null;

    if ($source === 'ozon') {
      $pair = acp_parse_category_code($code);
      if (!$pair) {
        $report['categories'][] = ['source' => $source, 'code' => $code, 'status' => 'skip', 'reason' => 'bad_code_format'];
        continue;
      }
      [$descId, $typeId] = $pair;

      $catRow = acp_load_taxonomy_category($descId, $typeId);
      if (!$catRow) {
        $report['categories'][] = ['source' => $source, 'code' => $code, 'status' => 'skip', 'reason' => 'taxonomy_leaf_not_found', 'desc_id' => $descId, 'type_id' => $typeId];
        continue;
      }
    } elseif ($source === 'wildberries') {
      if (!ctype_digit($code)) {
        $report['categories'][] = ['source' => $source, 'code' => $code, 'status' => 'skip', 'reason' => 'bad_subject_id'];
        continue;
      }

      $catRow = acp_load_wb_taxonomy_category((int)$code);
      if (!$catRow) {
        $report['categories'][] = ['source' => $source, 'code' => $code, 'status' => 'skip', 'reason' => 'taxonomy_leaf_not_found', 'subject_id' => (int)$code];
        continue;
      }
    } else {
      $report['categories'][] = ['source' => $source, 'code' => $code, 'status' => 'skip', 'reason' => 'unsupported_source'];
      continue;
    }

    $meta = [];
    if (!empty($catRow['meta_json'])) {
      $tmp = json_decode((string)$catRow['meta_json'], true);
      if (is_array($tmp)) $meta = $tmp;
    }
    $meta += ['description' => '', 'typical_goods' => '', 'features' => '', 'cover_design' => '', 'video_cover_design' => ''];

    // --- сначала подтягиваем атрибуты категории из Ozon API (массово), как в taxonomy/edit.php
    if ($source === 'ozon' && is_array($oz)) {
      try {
        $descIdTry = (int)($meta['ozon_description_category_id'] ?? 0);
        $typeIdTry = (int)($meta['ozon_type_id'] ?? 0);

        $lines = [];
        $attrsMeta = [];
        $usedDescId = 0;
        $usedTypeId = 0;

        if ($descIdTry > 0 && $typeIdTry > 0) {
          $tmp = acp_ozon_required_attr_data($oz, $descIdTry, $typeIdTry, $globalOzonExcludeNames);
          $lines = $tmp['lines'];
          $attrsMeta = $tmp['meta'];
          $usedDescId = $descIdTry;
          $usedTypeId = $typeIdTry;
        } else {
          // пробуем (descId,typeId) и (typeId,descId) — на случай путаницы связки
          $attempts = [
            ['desc' => $descId, 'type' => $typeId],
            ['desc' => $typeId, 'type' => $descId],
          ];

          $bestLines = [];
          $bestMeta = [];
          $bestDesc = 0;
          $bestType = 0;

          $errors = [];
          foreach ($attempts as $t) {
            try {
              $tmp = acp_ozon_required_attr_data($oz, (int)$t['desc'], (int)$t['type'], $globalOzonExcludeNames);
              $tmpLines = $tmp['lines'];
              $tmpMeta  = $tmp['meta'];

              // выбираем попытку с максимальным числом атрибутов
              if (count($tmpLines) > count($bestLines)) {
                $bestLines = $tmpLines;
                $bestMeta = $tmpMeta;
                $bestDesc = (int)$t['desc'];
                $bestType = (int)$t['type'];
              }
            } catch (Throwable $e) {
              $errors[] = $e->getMessage();
            }
          }

          $lines = $bestLines;
          $attrsMeta = $bestMeta;
          $usedDescId = $bestDesc;
          $usedTypeId = $bestType;

          if ($usedDescId <= 0 || $usedTypeId <= 0) {
            throw new RuntimeException('Не удалось определить description_category_id/type_id для Ozon');
          }

          // если совсем пусто и были ошибки — покажем первую причину
          if ($lines === [] && $errors) {
            $log("WARN attrs {$code}: " . implode(' | ', array_slice($errors, 0, 2)) . "\n");
          }
        }

        // сохраняем связку (чтобы дальше не гадать) + сами атрибуты
        if ($usedDescId > 0 && $usedTypeId > 0) {
          $meta['ozon_description_category_id'] = $usedDescId;
          $meta['ozon_type_id'] = $usedTypeId;
        }
        $meta['ozon_required_attributes'] = $lines;
        $meta['ozon_required_attributes_meta'] = $attrsMeta;
        $tnvedAllowedValues = acp_ozon_tnved_allowed_values_from_meta($attrsMeta);
        $meta['ozon_tnved_allowed_values'] = $tnvedAllowedValues;
        $meta['ozon_tnved_allowed_values_count'] = count($tnvedAllowedValues);
        $meta['ozon_tnved_allowed_values_updated_at'] = date('c');

        acp_save_category_meta((int)$catRow['id'], $meta);
      } catch (Throwable $e) {
        $log("WARN ozon attrs {$code}: " . $e->getMessage() . "\n");
      }
    }
    if ($source === 'wildberries' && $wb instanceof WildberriesClient) {
      try {
        $tmp = acp_wb_characteristics_data($wb, (int)$code, $globalWbExcludeNames);
        $meta['wb_required_attributes'] = $tmp['lines'];
        $meta['wb_characteristics_meta'] = $tmp['meta'];
        acp_save_category_meta((int)$catRow['id'], $meta);
      } catch (Throwable $e) {
        $log("WARN wb attrs {$code}: " . $e->getMessage() . "\n");
      }
    }

    $keySearchQueries = acp_collect_category_key_search_queries(
      $cfg,
      $ds,
      $params,
      $group,
      $catRow,
      $source,
      $code,
      $opId,
      0,
      ''
    );
    $meta = acp_apply_key_search_queries_to_meta($meta, $keySearchQueries, $searchQueryKeywordLinesLimit);
    acp_save_category_meta((int)$catRow['id'], $meta);
    file_put_contents($searchQueriesJsonlAbs, json_encode([
      'source' => $source,
      'code' => $code,
      'category_id' => (int)$catRow['id'],
      'result' => $keySearchQueries,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    if ((string)($keySearchQueries['status'] ?? '') === 'error') {
      $log("WARN key search queries {$code}: " . (string)($keySearchQueries['error'] ?? 'unknown error') . "\n");
    }

    // --- signature: чтобы понимать, изменились ли товары категории
    // Берем products_total и список offer_id из sample (уже отсортирован и детерминирован)
    $sampleIds = [];
    foreach (($group['products'] ?? []) as $p) {
      $sampleIds[] = (string)($p['offer_id'] ?? '');
    }

    $sigPayload = [
      'source' => $source,
      'products_total' => (int)($group['total'] ?? 0),
      'sample_offer_ids' => $sampleIds,
      'max_products_per_category' => $maxPerCat, // чтобы изменение размера выборки тоже считалось изменением
    ];

    $currentSig = sha1(json_encode($sigPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $prevSig = (string)($meta['category_analysis']['signature'] ?? '');


    $missing = (
      trim((string)$meta['description']) === ''
      || trim((string)$meta['typical_goods']) === ''
      || trim((string)$meta['features']) === ''
      || trim((string)$meta['cover_design']) === ''
      || trim((string)$meta['video_cover_design']) === ''
    );
    $changed = ($prevSig === '' || $prevSig !== $currentSig);



    $input = [
      'category' => [
        'source' => $source,
        'code' => $code,
        'name' => (string)$catRow['name'],
        'full_path' => (string)$catRow['full_path'],
      ],

      // текущие заполненные блоки категории (важно для "дополнить/исправить")
      'category_current' => [
        'description' => (string)($meta['description'] ?? ''),
        'typical_goods' => (string)($meta['typical_goods'] ?? ''),
        'features' => (string)($meta['features'] ?? ''),
        'cover_design' => (string)($meta['cover_design'] ?? ''),
        'video_cover_design' => (string)($meta['video_cover_design'] ?? ''),
      ],

      'products_total' => (int)($group['total'] ?? 0),
      'products_sample' => $group['products'] ?? [],

      // актуализируем, т.к. выборка не "первые", а "самые информативные"
      'sampling_note' => "products_sample is a subset (up to {$maxPerCat} most informative offers selected by params count and description length).",
    ];


    $inputJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($inputJson === false) $inputJson = '{"category":{}}';

    try {
      $res = $client->generateText(
        $model,
        "Ниже входной JSON. Верни только JSON-объект ответа.\n\n" . $inputJson,
        $prompt,
        [
          'prompt_cache_key' => 'an_cat:' . substr(hash('sha256', json_encode([
            'category' => $input['category'] ?? [],
            'category_current' => $input['category_current'] ?? [],
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 48),
          'text' => [
            'format' => [
              'type' => 'json_object',
            ],
          ],
        ]
      );

      $rawText = trim((string)($res['output_text'] ?? ''));
      $raw = $res['raw'] ?? null;
      $u = acp_extract_usage($raw);

      $tokensInTotal += (int)$u['input'];
      $tokensOutTotal += (int)$u['output'];
      $tokensCachedTotal += (int)$u['cached_input'];

      $costUsdTotal += acp_calc_cost_usd($cfg, $model, (int)$u['input'], (int)$u['cached_input'], (int)$u['output']);


      $decoded = json_decode($rawText, true);
      if (!is_array($decoded)) {
        // fallback: braces extraction
        $i = strpos($rawText, '{');
        $j = strrpos($rawText, '}');
        if ($i !== false && $j !== false && $j > $i) {
          $decoded = json_decode(substr($rawText, $i, $j - $i + 1), true);
        }
      }
      if (!is_array($decoded)) {
        throw new RuntimeException('GPT output is not valid JSON. Preview: ' . mb_substr($rawText, 0, 200, 'UTF-8'));
      }

      $desc = trim((string)($decoded['description'] ?? ''));
      $typ  = trim((string)($decoded['typical_goods'] ?? ''));
      $feat = trim((string)($decoded['features'] ?? ''));
      $coverDesign = trim((string)($decoded['cover_design'] ?? ''));
      $videoCoverDesign = trim((string)($decoded['video_cover_design'] ?? ''));

      if ($desc === '' || $typ === '' || $feat === '' || $coverDesign === '' || $videoCoverDesign === '') {
        throw new RuntimeException('GPT JSON missing required keys (description/typical_goods/features/cover_design/video_cover_design)');
      }

      // save to taxonomy meta_json
      $meta['description'] = $desc;
      $meta['typical_goods'] = $typ;
      $meta['features'] = $feat;
      $meta['cover_design'] = $coverDesign;
      $meta['video_cover_design'] = $videoCoverDesign;
      $meta['category_analysis'] = $meta['category_analysis'] ?? [];
      $meta['category_analysis']['source'] = $source;
      $meta['category_analysis']['updated_by_op_id'] = $opId;
      $meta['category_analysis']['updated_at'] = date('c');
      $meta['category_analysis']['products_total'] = (int)($group['total'] ?? 0);
      $meta['category_analysis']['products_sample_count'] = count($group['products'] ?? []);
      $meta['category_analysis']['model'] = $model;
      $meta['category_analysis']['dataset_id'] = $datasetId;
      $meta['category_analysis']['signature'] = $currentSig;

      $templateResult = ['status' => 'skipped', 'reason' => 'not_started', 'meta' => $meta];
      try {
        $templateResult = acp_generate_category_background_template(
          $cfg,
          $catRow,
          $meta,
          $source,
          $code,
          $opId,
          $outDir,
          $log,
          $backgroundImageClient,
          $backgroundImageTotals
        );
        $meta = is_array($templateResult['meta'] ?? null) ? $templateResult['meta'] : $meta;
        $templateStatus = (string)($templateResult['status'] ?? '');
        if ($templateStatus === 'generated') {
          $backgroundImageTotals['generated']++;
          $log("category background template generated: {$source}:{$code}\n");
        } elseif ($templateStatus === 'cached') {
          $backgroundImageTotals['cached']++;
        } elseif ($templateStatus === 'skipped') {
          $backgroundImageTotals['skipped']++;
        }
      } catch (Throwable $e) {
        $backgroundImageTotals['errors']++;
        $templateResult = ['status' => 'error', 'error' => $e->getMessage(), 'meta' => $meta];
        $log("WARN category background template {$code}: " . $e->getMessage() . "\n");
      }
      acp_save_category_meta((int)$catRow['id'], $meta);
      file_put_contents($templateJsonlAbs, json_encode([
        'source' => $source,
        'code' => $code,
        'category_id' => (int)$catRow['id'],
        'result' => acp_background_template_report($templateResult),
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

      // debug jsonl
      file_put_contents($gptJsonlAbs, json_encode([
        'source' => $source,
        'code' => $code,
        'category_id' => (int)$catRow['id'],
        'ok' => 1,
        'output' => ['description' => $desc, 'typical_goods' => $typ, 'features' => $feat, 'cover_design' => $coverDesign, 'video_cover_design' => $videoCoverDesign],
        'usage' => $u,

      ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

      file_put_contents($rawJsonlAbs, json_encode([
        'source' => $source,
        'code' => $code,
        'category_id' => (int)$catRow['id'],
        'raw' => $raw,
      ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

      $report['categories'][] = [
        'source' => $source,
        'code' => $code,
        'status' => 'ok',
        'category_id' => (int)$catRow['id'],
        'products_total' => (int)($group['total'] ?? 0),
        'products_sample_count' => count($group['products'] ?? []),
        'was_missing' => $missing ? 1 : 0,
        'was_changed' => $changed ? 1 : 0,
      ] + acp_key_search_queries_report($keySearchQueries) + acp_background_template_report($templateResult);
    } catch (Throwable $e) {
      $report['categories'][] = [
        'source' => $source,
        'code' => $code,
        'status' => 'error',
        'category_id' => (int)$catRow['id'],
        'error' => $e->getMessage(),
      ] + acp_key_search_queries_report($keySearchQueries ?? []);
      // логируем, но не падаем целиком
      $log("ERROR category {$code}: " . $e->getMessage() . "\n");
    }
  }

  $costUsdTotal = round($costUsdTotal, 6);
  $backgroundCostTotal = round((float)($backgroundImageTotals['cost'] ?? 0.0), 6);
  $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
  $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");
  $log(
    "BACKGROUND templates: generated={$backgroundImageTotals['generated']}, cached={$backgroundImageTotals['cached']}, " .
    "skipped={$backgroundImageTotals['skipped']}, errors={$backgroundImageTotals['errors']}, " .
    "cost=" . acp_template_cost_label($cfg, $backgroundCostTotal) . "\n"
  );

  $report['summary']['usage'] = [
    'input_tokens' => $tokensInTotal,
    'cached_input_tokens' => $tokensCachedTotal,
    'output_tokens' => $tokensOutTotal,
    'cost_usd' => round($costUsdTotal, 6),
  ];
  $report['summary']['background_templates'] = [
    'model' => 'gpt-image-2',
    'quality' => 'medium',
    'size' => '1200x1600',
    'generated' => (int)$backgroundImageTotals['generated'],
    'cached' => (int)$backgroundImageTotals['cached'],
    'skipped' => (int)$backgroundImageTotals['skipped'],
    'errors' => (int)$backgroundImageTotals['errors'],
    'jobs' => (int)$backgroundImageTotals['jobs'],
    'estimated_jobs' => (int)$backgroundImageTotals['estimated_jobs'],
    'input_tokens' => (int)$backgroundImageTotals['input_tokens'],
    'cached_input_tokens' => (int)$backgroundImageTotals['cached_input_tokens'],
    'output_tokens' => (int)$backgroundImageTotals['output_tokens'],
    'image_input_tokens' => (int)$backgroundImageTotals['image_input_tokens'],
    'image_output_tokens' => (int)$backgroundImageTotals['image_output_tokens'],
    'cost_usd' => $backgroundCostTotal,
  ];



  ops_update_progress($opId, 100, 100, 'done', 'Done');

  file_put_contents($outDir . '/report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  return [
    'summary_json_inline' => [
      'categories_found_in_feed' => count($groups),
      'categories_processed' => $totalCats,
      'offers_seen' => $offersSeen,
      'report_json' => 'report.json',
      'gpt_results_jsonl' => 'gpt_results.jsonl',
      'category_background_templates_jsonl' => 'category_background_templates.jsonl',
      'category_search_queries_jsonl' => 'category_search_queries.jsonl',
      'tokens_in' => $tokensInTotal,
      'tokens_cached_in' => $tokensCachedTotal,
      'tokens_out' => $tokensOutTotal,
      'cost_usd' => round($costUsdTotal, 6),
      'background_templates_generated' => (int)$backgroundImageTotals['generated'],
      'background_templates_cost_usd' => $backgroundCostTotal,

    ],
  ];
}
