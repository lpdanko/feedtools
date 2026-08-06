<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_products.php';
require_once __DIR__ . '/../ozon_price_tool.php';

/**
 * Синхронизация списка товаров из кабинета Ozon в feedtools_ozon_products.
 *
 * Важно: вкладка "Архив" в кабинете Ozon соответствует filter.visibility = "ARCHIVED" в /v3/product/list.
 * По умолчанию синхронизируем "обычные" товары (visibility="ALL" — по практике Ozon это "все, кроме архивных").
 *
 * Поддерживаемые параметры операции:
 * - mode: continue | daily | full_new | reset
 * - visibility: all | archived | both
 *     all      => /v3/product/list filter.visibility="ALL" (не архив)
 *     archived => /v3/product/list filter.visibility="ARCHIVED" (архив)
 *     both     => выполнит 2 прохода: ALL, затем ARCHIVED
 *
 * Если в таблице есть колонка is_archived (TINYINT), мы будем писать туда 0/1.
 * Если колонки нет — код продолжит работать как раньше (но отличить архивные от неархивных будет невозможно).
 */

function ozon_state_get(PDO $pdo, int $connectionId, string $key, string $default = ''): string
{
  $st = $pdo->prepare("SELECT state_value FROM feedtools_ozon_sync_state WHERE connection_id=:connection_id AND state_key=:k LIMIT 1");
  $st->execute([':connection_id' => $connectionId, ':k' => $key]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null) ? $default : (string)$v;
}

function ozon_state_set(PDO $pdo, int $connectionId, string $clientId, string $key, string $value): void
{
  $st = $pdo->prepare("
    INSERT INTO feedtools_ozon_sync_state (connection_id, ozon_client_id, state_key, state_value)
    VALUES (:connection_id,:cid,:k,:v)
    ON DUPLICATE KEY UPDATE ozon_client_id=VALUES(ozon_client_id), state_value=VALUES(state_value), updated_at=CURRENT_TIMESTAMP
  ");
  $st->execute([':connection_id' => $connectionId, ':cid' => $clientId, ':k' => $key, ':v' => $value]);
}

/**
 * Одна страница /v3/product/list.
 * $visibility: 'ALL' | 'ARCHIVED'
 */
function ozon_list_page_v3(array $oz, string $lastId, int $limit, string $visibility): array
{
  $visibility = strtoupper(trim($visibility));
  $filter = new stdClass();

  if ($visibility === 'ARCHIVED') {
    $filter->visibility = 'ARCHIVED';
  } else {
    // по практике Ozon: ALL => все товары кроме архивных (т.е. "активный список")
    $filter->visibility = 'ALL';
  }

  $payload = [
    'filter' => $filter,
    'last_id' => $lastId,
    'limit' => $limit, // обычно максимум 1000
  ];

  $resp = ozon_post_json($oz, '/v3/product/list', $payload);
  $result = $resp['result'] ?? null;

  $items = [];
  $newLast = '';

  if (is_array($result)) {
    if (isset($result['items']) && is_array($result['items'])) $items = $result['items'];
    if (array_key_exists('last_id', $result)) $newLast = (string)$result['last_id'];
  }

  return [$items, $newLast];
}

function ozon_sync_products_status_from_list(string $visibility, int $isArchived, int $isAutoArchived = 0): string
{
  if ($isAutoArchived === 1) {
    return 'auto_archived';
  }
  return ($isArchived === 1 || strtoupper(trim($visibility)) === 'ARCHIVED') ? 'archived' : 'ready';
}

function ozon_sync_products_lower(string $value): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ozon_sync_products_contains_any(string $haystack, array $needles): bool
{
  foreach ($needles as $needle) {
    $needle = (string)$needle;
    if ($needle !== '' && str_contains($haystack, $needle)) {
      return true;
    }
  }
  return false;
}

function ozon_sync_products_truncate(string $value, int $limit): string
{
  $value = trim($value);
  if ($limit <= 0) {
    return '';
  }
  return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function ozon_sync_products_datetime_from_api($value): ?string
{
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  try {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function ozon_sync_products_status_from_info(array $item): string
{
  if (function_exists('ozon_products_autoarchive_marker') && ozon_products_autoarchive_marker($item)) {
    return 'auto_archived';
  }
  if (!empty($item['is_archived']) || !empty($item['archived'])) {
    return 'archived';
  }

  $statuses = $item['statuses'] ?? [];
  if (!is_array($statuses)) {
    $statuses = [];
  }

  $status = ozon_sync_products_lower(trim((string)($statuses['status'] ?? '')));
  $statusFailed = ozon_sync_products_lower(trim((string)($statuses['status_failed'] ?? '')));
  $moderate = ozon_sync_products_lower(trim((string)($statuses['moderate_status'] ?? '')));
  $validation = ozon_sync_products_lower(trim((string)($statuses['validation_status'] ?? '')));
  $text = ozon_sync_products_lower(
    (string)($statuses['status_name'] ?? '') . ' ' .
    (string)($statuses['status_description'] ?? '') . ' ' .
    (string)($statuses['status_tooltip'] ?? '')
  );
  $errors = $item['errors'] ?? [];
  $hasErrors = is_array($errors) && count($errors) > 0;
  $isCreated = array_key_exists('is_created', $statuses) ? (bool)$statuses['is_created'] : true;

  if (ozon_sync_products_contains_any($text, ['архив'])) {
    return 'archived';
  }
  if (!$isCreated || ozon_sync_products_contains_any($text, ['не создан'])) {
    return 'error';
  }
  if ($hasErrors && str_contains(ozon_sync_products_error_summary($item), 'ошибка объединения')) {
    return 'error';
  }
  if (
    $moderate === 'declined'
    || $status === 'declined'
    || ozon_sync_products_contains_any($validation, ['fail', 'error'])
    || ozon_sync_products_contains_any($text, ['доработ', 'не прош', 'отклон', 'модерац'])
  ) {
    return 'revision';
  }
  if (
    $statusFailed !== ''
    || $hasErrors
    || ozon_sync_products_contains_any($text, ['ошиб', 'не удалось', 'не обнов', 'не загруж'])
  ) {
    return 'revision';
  }
  if (
    ozon_sync_products_contains_any($text, ['готов к продаже'])
    || ($moderate === 'approved' && $validation === 'success')
    || $status === 'price_sent'
  ) {
    return 'ready';
  }
  if (
    ozon_sync_products_contains_any($validation, ['pending'])
    || ozon_sync_products_contains_any($moderate, ['pending', 'new'])
    || in_array($status, ['imported', 'moderated'], true)
  ) {
    return 'revision';
  }

  return 'ready';
}

function ozon_sync_products_error_label(string $code, string $attribute, string $description, string $message): string
{
  $haystack = ozon_sync_products_lower($code . ' ' . $attribute . ' ' . $description . ' ' . $message);
  $attributeLower = ozon_sync_products_lower(trim($attribute));
  $isEmpty = ozon_sync_products_contains_any($haystack, ['empty', 'обязательное поле', 'заполните поле', 'не заполн']);

  if (ozon_sync_products_contains_any($haystack, ['не удалось объедин', 'объединить в похожие товары', 'объединить с другими товарами', 'похожие товары', 'merge', 'similar products'])) {
    return 'ошибка объединения';
  }
  if (ozon_sync_products_contains_any($haystack, ['дубл', 'duplicate', 'уже существует', 'already exists'])) {
    return 'дубль товара';
  }
  if (in_array($attributeLower, ['тип', 'type'], true)) {
    return $isEmpty ? 'нет категории' : 'ошибка категории';
  }
  if (in_array($attributeLower, ['название', 'name', 'title'], true)) {
    return $isEmpty ? 'нет названия' : 'ошибка в названии';
  }
  if (ozon_sync_products_contains_any($haystack, ['тн вэд', 'тнвэд', 'tnved', 'tn ved'])) {
    return $isEmpty ? 'нет кода ТН ВЭД' : 'ошибка кода ТН ВЭД';
  }
  if (ozon_sync_products_contains_any($haystack, ['фото', 'изображ', 'image', 'picture'])) {
    return $isEmpty ? 'нет фото' : 'ошибка фото';
  }
  if (ozon_sync_products_contains_any($haystack, ['бренд', 'brand', 'vendor'])) {
    return $isEmpty ? 'нет бренда' : 'ошибка бренда';
  }
  if (ozon_sync_products_contains_any($haystack, ['штрихкод', 'barcode', 'bar code'])) {
    return $isEmpty ? 'нет штрихкода' : 'ошибка штрихкода';
  }
  if (ozon_sync_products_contains_any($haystack, ['категор', 'category', 'type_id', 'description_category'])) {
    return $isEmpty ? 'нет категории' : 'ошибка категории';
  }
  if (ozon_sync_products_contains_any($haystack, ['цена', 'price'])) {
    return $isEmpty ? 'нет цены' : 'ошибка цены';
  }
  if (ozon_sync_products_contains_any($haystack, ['габарит', 'размер', 'dimension', 'height', 'width', 'length', 'weight'])) {
    return $isEmpty ? 'нет габаритов' : 'ошибка габаритов';
  }
  if (ozon_sync_products_contains_any($haystack, ['название модели', 'model'])) {
    return $isEmpty ? 'нет модели' : 'ошибка модели';
  }
  $attribute = trim($attribute);
  if ($attribute !== '') {
    return $isEmpty ? ('нет: ' . $attribute) : ('ошибка характеристики: ' . $attribute);
  }
  $description = trim($description);
  if ($description !== '') {
    return ozon_sync_products_truncate($description, 120);
  }
  $message = trim($message);
  return $message !== '' ? ozon_sync_products_truncate($message, 120) : 'ошибка карточки';
}

function ozon_sync_products_error_summary(array $item): string
{
  $errors = $item['errors'] ?? [];
  if (!is_array($errors) || !$errors) {
    return '';
  }
  $labels = [];
  foreach ($errors as $error) {
    if (!is_array($error)) {
      continue;
    }
    $texts = $error['texts'] ?? [];
    if (!is_array($texts)) {
      $texts = [];
    }
    $label = ozon_sync_products_error_label(
      trim((string)($error['code'] ?? '')),
      trim((string)($texts['attribute_name'] ?? '')),
      trim((string)($texts['description'] ?? '')),
      trim((string)($texts['message'] ?? ($error['code'] ?? '')))
    );
    if ($label !== '') {
      $labels[] = $label;
    }
    if (count($labels) >= 8) {
      break;
    }
  }
  return ozon_sync_products_truncate(implode("\n", array_values(array_unique($labels))), 500);
}

function ozon_sync_products_refresh_status_details(array $oz, int $connectionId, string $clientId, array $productIds, int $opId = 0, ?callable $log = null): array
{
  $productIds = array_values(array_unique(array_filter(array_map(
    static fn($value): int => is_numeric($value) ? (int)$value : 0,
    $productIds
  ), static fn(int $value): bool => $value > 0)));
  if (!$productIds) {
    return ['requested' => 0, 'updated' => 0, 'errors' => 0];
  }

  $pdo = db();
  $updated = 0;
  $errors = 0;
  $chunks = array_chunk($productIds, 100);
  $totalChunks = count($chunks);
  $chunkIndex = 0;

  $st = $pdo->prepare("
    UPDATE feedtools_ozon_products
    SET
      ozon_client_id = :cid,
      product_id = :product_id,
      sku = :sku,
      is_active = :is_active,
      is_archived = :is_archived,
      is_autoarchived = :is_autoarchived,
      marketplace_status = :marketplace_status,
      status_name = :status_name,
      status_description = :status_description,
      status_failed = :status_failed,
      moderate_status = :moderate_status,
      validation_status = :validation_status,
      content_rating = :content_rating,
      content_rating_recommendations_json = :content_rating_recommendations_json,
      status_updated_at = :status_updated_at,
      raw_json = :raw_json,
      updated_at = CURRENT_TIMESTAMP
    WHERE connection_id = :connection_id AND offer_id = :offer_id
  ");

  foreach ($chunks as $chunk) {
    $chunkIndex++;
    if ($opId > 0 && function_exists('ops_update_progress')) {
      $pct = min(99, 90 + (int)floor(($chunkIndex / max(1, $totalChunks)) * 8));
      ops_update_progress($opId, $pct, 100, 'statuses', "Ozon: обновляю статусы {$chunkIndex}/{$totalChunks}");
    }
    try {
      $resp = ozon_post_json($oz, '/v3/product/info/list', [
        'product_id' => array_values($chunk),
      ]);
    } catch (Throwable $e) {
      $errors += count($chunk);
      if ($log) {
        $log("Ozon status details error: " . $e->getMessage() . "\n");
      }
      continue;
    }

    $items = $resp['items'] ?? ($resp['result']['items'] ?? []);
    if (!is_array($items)) {
      $items = [];
    }

    $skuByOffer = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $offerId = trim((string)($item['offer_id'] ?? ''));
      if ($offerId === '') {
        continue;
      }
      $sku = isset($item['sku']) && is_numeric($item['sku']) ? (int)$item['sku'] : null;
      if ($sku !== null && $sku > 0) {
        $skuByOffer[$offerId] = $sku;
      }
      $statuses = $item['statuses'] ?? [];
      if (!is_array($statuses)) {
        $statuses = [];
      }
      $marketplaceStatus = ozon_sync_products_status_from_info($item);
      $isAutoArchived = (function_exists('ozon_products_autoarchive_marker') && ozon_products_autoarchive_marker($item)) ? 1 : 0;
      $isArchived = (!empty($item['is_archived']) || !empty($item['archived']) || in_array($marketplaceStatus, ['archived', 'auto_archived'], true)) ? 1 : 0;
      $statusDescription = trim((string)($statuses['status_description'] ?? ''));
      $errorSummary = ozon_sync_products_error_summary($item);
      if (in_array($marketplaceStatus, ['revision', 'error'], true) && $errorSummary !== '') {
        $statusDescription = $errorSummary;
      }
      $rawJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $contentRatingRecommendations = ozon_products_content_rating_recommendations_from_raw($item);
      $contentRatingRecommendationsJson = $contentRatingRecommendations
        ? json_encode($contentRatingRecommendations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
      $st->execute([
        ':cid' => $clientId,
        ':product_id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : (isset($item['product_id']) && is_numeric($item['product_id']) ? (int)$item['product_id'] : null),
        ':sku' => $sku,
        ':is_active' => $isArchived ? 0 : 1,
        ':is_archived' => $isArchived,
        ':is_autoarchived' => $isAutoArchived,
        ':marketplace_status' => $marketplaceStatus,
        ':status_name' => ozon_sync_products_truncate((string)($statuses['status_name'] ?? ''), 190),
        ':status_description' => $statusDescription,
        ':status_failed' => ozon_sync_products_truncate((string)($statuses['status_failed'] ?? ''), 190),
        ':moderate_status' => ozon_sync_products_truncate((string)($statuses['moderate_status'] ?? ''), 64),
        ':validation_status' => ozon_sync_products_truncate((string)($statuses['validation_status'] ?? ''), 64),
        ':content_rating' => ozon_products_content_rating_from_raw($item, false),
        ':content_rating_recommendations_json' => is_string($contentRatingRecommendationsJson) ? $contentRatingRecommendationsJson : null,
        ':status_updated_at' => ozon_sync_products_datetime_from_api($statuses['status_updated_at'] ?? null),
        ':raw_json' => is_string($rawJson) ? $rawJson : null,
        ':connection_id' => $connectionId,
        ':offer_id' => $offerId,
      ]);
      $updated++;
    }

    if ($skuByOffer) {
      $ratingPayloadBySku = ozon_products_content_rating_payload_by_sku($oz, array_values($skuByOffer), $log);
      if ($ratingPayloadBySku) {
        $ratingSt = $pdo->prepare("
          UPDATE feedtools_ozon_products
          SET
            content_rating = COALESCE(:content_rating, content_rating),
            content_rating_recommendations_json = CASE
              WHEN :content_rating_recommendations_json_keep = 1 THEN content_rating_recommendations_json
              ELSE :content_rating_recommendations_json
            END,
            updated_at = CURRENT_TIMESTAMP
          WHERE connection_id = :connection_id AND offer_id = :offer_id
        ");
        foreach ($skuByOffer as $offerId => $sku) {
          $payload = $ratingPayloadBySku[(string)$sku] ?? null;
          if (!is_array($payload)) {
            continue;
          }
          $rating = $payload['rating'] ?? null;
          $recommendations = is_array($payload['recommendations'] ?? null) ? $payload['recommendations'] : [];
          $recommendationsJson = $recommendations
            ? json_encode($recommendations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
          if ($rating === null && $recommendationsJson === null) {
            continue;
          }
          $ratingSt->execute([
            ':content_rating' => $rating,
            ':content_rating_recommendations_json_keep' => $recommendationsJson === null ? 1 : 0,
            ':content_rating_recommendations_json' => is_string($recommendationsJson) ? $recommendationsJson : null,
            ':connection_id' => $connectionId,
            ':offer_id' => $offerId,
          ]);
        }
      }
    }
  }

  return ['requested' => count($productIds), 'updated' => $updated, 'errors' => $errors];
}

function op_ozon_sync_products(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  ozon_products_tables_ensure($cfg);
  $requestedConnectionId = (int)($params['connection_id'] ?? 0);
  if ($requestedConnectionId <= 0) {
    throw new RuntimeException('Marketplace connection is required for ozon_sync_products.');
  }
  $connection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
  $cfg = ozon_price_cfg_with_connection($cfg, $connection);
  $oz = ozon_cfg_or_fail($cfg);
  $connectionId = (int)($connection['id'] ?? 0);
  $clientId = (string)$oz['client_id'];
  if ($connectionId <= 0) {
    throw new RuntimeException('Для синхронизации товаров Ozon не удалось определить marketplace connection.');
  }

  $mode = (string)($params['mode'] ?? 'full_new'); // continue | daily | full_new | reset
  $mode = in_array($mode, ['continue','daily','full_new','reset'], true) ? $mode : 'full_new';

  // visibility: all | archived | both
  $vis = strtolower(trim((string)($params['visibility'] ?? 'both')));
  if (!in_array($vis, ['all','archived','both'], true)) $vis = 'all';
  $log("ozon_sync_products: connection_id={$connectionId}, client_id={$clientId}, mode={$mode}, visibility={$vis}\n");

  // в reset сбрасываем оба курсора, чтобы "both" можно было начать заново
  $pdo = db();
  $now = date('Y-m-d H:i:s');

  if ($mode === 'reset') {
    foreach (['ALL', 'ARCHIVED'] as $v) {
      $suffix = ($v === 'ARCHIVED') ? '_archived' : '_all';
      ozon_state_set($pdo, $connectionId, $clientId, 'products_last_id' . $suffix, '');
      ozon_state_set($pdo, $connectionId, $clientId, 'products_done' . $suffix, '0');
      ozon_state_set($pdo, $connectionId, $clientId, 'products_scan_last_id' . $suffix, '');
    }
    ops_update_progress($opId, 100, 100, 'done', 'Ozon sync: reset выполнен.');
    return ['outputs' => ['reset' => true]];
  }

  $batch = (int)($params['batch'] ?? 10000);
  if ($mode === 'full_new') {
    $batch = PHP_INT_MAX; // полный проход
  } else {
    if ($batch < 1000) $batch = 1000;
    if ($batch > 50000) $batch = 50000;
  }

  $limit = (int)($params['limit'] ?? 1000);
  if ($limit < 100) $limit = 100;
  if ($limit > 1000) $limit = 1000;

  $commitEach = (int)($params['commit_each'] ?? 10000);
  if ($commitEach < 1000) $commitEach = 1000;

  // какие проходы делаем
  $passes = [];
  if ($vis === 'both') {
    $passes = ['ALL', 'ARCHIVED'];
  } elseif ($vis === 'archived') {
    $passes = ['ARCHIVED'];
  } else {
    $passes = ['ALL'];
  }

  $allOutputs = [];

  $passIndex = 0;
  foreach ($passes as $visibility) {
    $passIndex++;

    $suffix = ($visibility === 'ARCHIVED') ? '_archived' : '_all';
    $isArchived = ($visibility === 'ARCHIVED') ? 1 : 0;
    $isActive = ($visibility === 'ARCHIVED') ? 0 : 1;

    // стартовый курсор
    $lastId = '';
    if ($mode === 'continue') {
      $lastId = ozon_state_get($pdo, $connectionId, 'products_last_id' . $suffix, '');
    } elseif ($mode === 'daily') {
      $lastId = ozon_state_get($pdo, $connectionId, 'products_scan_last_id' . $suffix, '');
    } elseif ($mode === 'full_new') {
      $lastId = '';
    }

    ops_update_progress($opId, 0, 100, 'init', "Ozon sync: connection={$connectionId}, pass {$passIndex}/" . count($passes) . ", visibility={$visibility}, mode={$mode}, batch={$batch}, limit={$limit}");

    $stUpsert = $pdo->prepare("
      INSERT INTO feedtools_ozon_products
        (
          connection_id, ozon_client_id, offer_id, product_id, sku,
          is_active, is_archived, is_autoarchived, marketplace_status, status_name,
          status_description, status_failed, moderate_status, validation_status,
          status_updated_at, last_seen_at, raw_json
        )
      VALUES
        (
          :connection_id, :cid, :offer_id, :product_id, :sku,
          :is_active, :is_archived, :is_autoarchived, :marketplace_status, :status_name,
          '', '', '', '', NULL, :seen, :raw_json
        )
      ON DUPLICATE KEY UPDATE
        ozon_client_id = VALUES(ozon_client_id),
        product_id = VALUES(product_id),
        sku = VALUES(sku),
        is_active = VALUES(is_active),
        is_archived = VALUES(is_archived),
        is_autoarchived = VALUES(is_autoarchived),
        marketplace_status = VALUES(marketplace_status),
        status_name = CASE WHEN VALUES(status_name) <> '' THEN VALUES(status_name) ELSE status_name END,
        status_description = VALUES(status_description),
        status_failed = VALUES(status_failed),
        moderate_status = VALUES(moderate_status),
        validation_status = VALUES(validation_status),
        status_updated_at = VALUES(status_updated_at),
        last_seen_at = VALUES(last_seen_at),
        raw_json = VALUES(raw_json),
        updated_at = CURRENT_TIMESTAMP
    ");

    $done = 0;
    $inserted = 0;
    $pages = 0;
    $statusProductIds = [];

    $maxPages = ($mode === 'full_new') ? 200000 : (int)ceil($batch / $limit) + 5;

    $pdo->beginTransaction();
    try {
      for ($pages = 1; $pages <= $maxPages; $pages++) {
        [$items, $newLast] = ozon_list_page_v3($oz, $lastId, $limit, $visibility);
        $count = is_array($items) ? count($items) : 0;
        if ($count === 0) break;

        $pct = ($mode === 'full_new')
          ? (int)min(99, ($pages % 99))
          : (int)min(99, floor(($done / max(1, $batch)) * 100));

        ops_update_progress($opId, $pct, 100, 'sync', "pass {$passIndex}/" . count($passes) . " стр {$pages}: items={$count}, processed={$done}, inserted_new={$inserted}");

        foreach ($items as $it) {
          if (!is_array($it)) continue;

          $offerId = trim((string)($it['offer_id'] ?? ''));
          if ($offerId === '') continue;

          $productId = (isset($it['product_id']) && is_numeric($it['product_id'])) ? (int)$it['product_id'] : null;
          $sku       = (isset($it['sku']) && is_numeric($it['sku'])) ? (int)$it['sku'] : null;

          $rawJson = json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $isAutoArchived = ($isArchived === 1 && function_exists('ozon_products_autoarchive_marker') && ozon_products_autoarchive_marker($it)) ? 1 : 0;
          $marketplaceStatus = ozon_sync_products_status_from_list($visibility, $isArchived, $isAutoArchived);
          $stUpsert->execute([
            ':connection_id' => $connectionId,
            ':cid' => $clientId,
            ':offer_id' => $offerId,
            ':product_id' => $productId,
            ':sku' => $sku,
            ':is_active' => $isActive,
            ':is_archived' => $isArchived,
            ':is_autoarchived' => $isAutoArchived,
            ':marketplace_status' => $marketplaceStatus,
            ':status_name' => $isArchived ? ($isAutoArchived ? 'В автоархиве' : 'В архиве') : '',
            ':seen' => $now,
            ':raw_json' => is_string($rawJson) ? $rawJson : null,
          ]);
          $inserted += (int)$stUpsert->rowCount();
          $done++;
          if ($productId !== null && $productId > 0) {
            $statusProductIds[$productId] = true;
          }

          if ($done % $commitEach === 0) {
            $pdo->commit();
            $pdo->beginTransaction();

            $pct2 = ($mode === 'full_new')
              ? (int)min(99, ($pages % 99))
              : (int)min(99, floor(($done / max(1, $batch)) * 100));
            ops_update_progress($opId, $pct2, 100, 'sync', "pass {$passIndex}/" . count($passes) . " коммит. processed={$done}, inserted_new={$inserted}");
          }

          if ($done >= $batch) break;
        }

        if ($done >= $batch) break;

        if ($newLast === '' || $newLast === $lastId) break;
        $lastId = $newLast;
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }

    // сохраняем курсоры
    $doneFlag = '0';

    if ($mode === 'continue') {
      ozon_state_set($pdo, $connectionId, $clientId, 'products_last_id' . $suffix, $lastId);
      if ($pages < $maxPages && $done < $batch) {
        $doneFlag = '1';
        ozon_state_set($pdo, $connectionId, $clientId, 'products_done' . $suffix, '1');
      }
    }

    if ($mode === 'daily') {
      ozon_state_set($pdo, $connectionId, $clientId, 'products_scan_last_id' . $suffix, $lastId);
      ozon_state_set($pdo, $connectionId, $clientId, 'products_daily_last_run' . $suffix, $now);
    }

    if ($mode === 'full_new') {
      ozon_state_set($pdo, $connectionId, $clientId, 'products_full_new_last_run' . $suffix, $now);
    }

    $statusDetails = ['requested' => 0, 'updated' => 0, 'errors' => 0];
    if ($statusProductIds) {
      $log("Ozon sync: обновляю подробные статусы, product_id=" . count($statusProductIds) . "\n");
      $statusDetails = ozon_sync_products_refresh_status_details($oz, $connectionId, $clientId, array_keys($statusProductIds), $opId, $log);
      $log("Ozon sync: статусы updated={$statusDetails['updated']}, errors={$statusDetails['errors']}\n");
    }

    $allOutputs[] = [
      'connection_id' => $connectionId,
      'visibility' => $visibility,
      'mode' => $mode,
      'processed' => $done,
      'inserted_new' => $inserted,
      'status_details' => $statusDetails,
      'limit' => $limit,
      'pages' => $pages,
      'cursor_last_id' => $lastId,
      'products_done' => $doneFlag,
      'ts' => $now,
    ];
  }

  if ($mode === 'full_new') {
    $st = $pdo->prepare("
      UPDATE feedtools_ozon_products
      SET is_active = 0,
          marketplace_status = CASE
            WHEN is_archived = 1 AND is_autoarchived = 1 THEN 'auto_archived'
            WHEN is_archived = 1 THEN 'archived'
            ELSE 'not_created'
          END,
          updated_at = CURRENT_TIMESTAMP
      WHERE connection_id = ? AND (last_seen_at IS NULL OR last_seen_at <> ?)
    ");
    $st->execute([$connectionId, $now]);
  }

  ops_update_progress($opId, 100, 100, 'done', "Готово: visibility={$vis}, mode={$mode}");

  return ['outputs' => [
    'ozon_sync' => $allOutputs,
  ]];
}
