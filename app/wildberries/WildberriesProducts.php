<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/WildberriesClient.php';

function wb_products_table_has_column(PDO $pdo, string $table, string $column): bool
{
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function wb_products_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function wb_products_table_index_columns(PDO $pdo, string $table, string $indexName): array
{
  try {
    $st = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND INDEX_NAME = ?
      ORDER BY SEQ_IN_INDEX ASC
    ");
    $st->execute([$table, $indexName]);
    return array_map(
      static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''),
      $st->fetchAll() ?: []
    );
  } catch (Throwable $e) {
    return [];
  }
}

function wb_products_ensure_table(PDO $pdo): void
{
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_wb_products (
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      vendor_code VARCHAR(255) NOT NULL,
      nm_id BIGINT UNSIGNED NULL,
      imt_id BIGINT UNSIGNED NULL,
      subject_id BIGINT UNSIGNED NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      marketplace_status VARCHAR(32) NOT NULL DEFAULT '',
      status_text VARCHAR(255) NOT NULL DEFAULT '',
      quality_score DECIMAL(4,1) NULL,
      quality_recommendations_json LONGTEXT NULL,
      is_trash TINYINT(1) NOT NULL DEFAULT 0,
      raw_json LONGTEXT NULL,
      last_seen_at DATETIME NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (connection_id, vendor_code),
      KEY idx_active_vendor (connection_id, is_active, vendor_code),
      KEY idx_last_seen_at (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  if (!wb_products_table_has_column($pdo, 'feedtools_wb_products', 'connection_id')) {
    try {
      $pdo->exec("ALTER TABLE feedtools_wb_products ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST");
    } catch (Throwable $e) {
      // If another request created the column at the same time, keep going.
    }
  }

  try {
    $pdo->exec("ALTER TABLE feedtools_wb_products MODIFY vendor_code VARCHAR(255) NOT NULL");
  } catch (Throwable $e) {
    // Existing compatible schemas are fine.
  }

  $columns = [
    'nm_id' => "ALTER TABLE feedtools_wb_products ADD COLUMN nm_id BIGINT UNSIGNED NULL AFTER vendor_code",
    'imt_id' => "ALTER TABLE feedtools_wb_products ADD COLUMN imt_id BIGINT UNSIGNED NULL AFTER nm_id",
    'subject_id' => "ALTER TABLE feedtools_wb_products ADD COLUMN subject_id BIGINT UNSIGNED NULL AFTER imt_id",
    'is_active' => "ALTER TABLE feedtools_wb_products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER subject_id",
    'marketplace_status' => "ALTER TABLE feedtools_wb_products ADD COLUMN marketplace_status VARCHAR(32) NOT NULL DEFAULT '' AFTER is_active",
    'status_text' => "ALTER TABLE feedtools_wb_products ADD COLUMN status_text VARCHAR(255) NOT NULL DEFAULT '' AFTER marketplace_status",
    'quality_score' => "ALTER TABLE feedtools_wb_products ADD COLUMN quality_score DECIMAL(4,1) NULL AFTER status_text",
    'quality_recommendations_json' => "ALTER TABLE feedtools_wb_products ADD COLUMN quality_recommendations_json LONGTEXT NULL AFTER quality_score",
    'is_trash' => "ALTER TABLE feedtools_wb_products ADD COLUMN is_trash TINYINT(1) NOT NULL DEFAULT 0 AFTER status_text",
    'raw_json' => "ALTER TABLE feedtools_wb_products ADD COLUMN raw_json LONGTEXT NULL AFTER is_trash",
    'last_seen_at' => "ALTER TABLE feedtools_wb_products ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER raw_json",
    'updated_at' => "ALTER TABLE feedtools_wb_products ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_seen_at",
  ];

  foreach ($columns as $column => $sql) {
    if (!wb_products_table_has_column($pdo, 'feedtools_wb_products', $column)) {
      try {
        $pdo->exec($sql);
      } catch (Throwable $e) {
        // If another request created the column at the same time, keep going.
      }
    }
  }

  $pdo->exec("
    UPDATE feedtools_wb_products
    SET marketplace_status = CASE
      WHEN is_trash = 1 THEN 'archived'
      WHEN is_active = 1 THEN 'ready'
      ELSE 'not_created'
    END
    WHERE marketplace_status = ''
  ");

  try {
    $primaryColumns = wb_products_table_index_columns($pdo, 'feedtools_wb_products', 'PRIMARY');
    if ($primaryColumns !== ['connection_id', 'vendor_code']) {
      $pdo->exec("ALTER TABLE feedtools_wb_products DROP PRIMARY KEY, ADD PRIMARY KEY (connection_id, vendor_code)");
    }
  } catch (Throwable $e) {
    // Старый ключ не должен ломать страницу. Если миграция не прошла, фильтр
    // продолжит работать, но без полноценного разделения нескольких WB-кабинетов.
  }

  if (!wb_products_table_has_index($pdo, 'feedtools_wb_products', 'idx_connection_active_vendor')) {
    try {
      $pdo->exec("ALTER TABLE feedtools_wb_products ADD KEY idx_connection_active_vendor (connection_id, is_active, vendor_code)");
    } catch (Throwable $e) {
      // Optional performance index.
    }
  }
  if (!wb_products_table_has_index($pdo, 'feedtools_wb_products', 'idx_connection_status_vendor')) {
    try {
      $pdo->exec("ALTER TABLE feedtools_wb_products ADD KEY idx_connection_status_vendor (connection_id, marketplace_status, vendor_code)");
    } catch (Throwable $e) {
      // Optional performance index.
    }
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_wb_sync_state (
      state_key VARCHAR(100) NOT NULL,
      state_value TEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (state_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

function wb_products_state_set(PDO $pdo, string $key, string $value): void
{
  $st = $pdo->prepare("
    INSERT INTO feedtools_wb_sync_state (state_key, state_value)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE
      state_value = VALUES(state_value),
      updated_at = CURRENT_TIMESTAMP
  ");
  $st->execute([$key, $value]);
}

function wb_products_state_get(PDO $pdo, string $key, string $default = ''): string
{
  try {
    $st = $pdo->prepare("SELECT state_value FROM feedtools_wb_sync_state WHERE state_key = ? LIMIT 1");
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return ($value === false || $value === null) ? $default : (string)$value;
  } catch (Throwable $e) {
    return $default;
  }
}

function wb_products_load_present_set(PDO $pdo, int $connectionId = 0): array
{
  wb_products_ensure_table($pdo);

  $set = [];
  if ($connectionId > 0) {
    $stmt = $pdo->prepare("SELECT vendor_code FROM feedtools_wb_products WHERE connection_id = ? AND is_active = 1");
    $stmt->execute([$connectionId]);
  } else {
    $stmt = $pdo->query("SELECT vendor_code FROM feedtools_wb_products WHERE is_active = 1");
  }
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $vendorCode = trim((string)($row['vendor_code'] ?? ''));
    if ($vendorCode !== '') {
      $set[$vendorCode] = true;
    }
  }
  return $set;
}

function wb_products_stats(PDO $pdo, int $connectionId = 0): array
{
  wb_products_ensure_table($pdo);

  if ($connectionId > 0) {
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_total,
        MAX(CASE WHEN is_active = 1 THEN last_seen_at ELSE NULL END) AS last_seen_at
      FROM feedtools_wb_products
      WHERE connection_id = ?
    ");
    $st->execute([$connectionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } else {
    $row = $pdo->query("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_total,
        MAX(CASE WHEN is_active = 1 THEN last_seen_at ELSE NULL END) AS last_seen_at
      FROM feedtools_wb_products
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  $stateSuffix = $connectionId > 0 ? ('_' . $connectionId) : '';

  return [
    'total' => (int)($row['total'] ?? 0),
    'active_total' => (int)($row['active_total'] ?? 0),
    'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
    'last_success_at' => wb_products_state_get($pdo, 'last_success_at' . $stateSuffix, ''),
    'last_success_total' => (int)wb_products_state_get($pdo, 'last_success_total' . $stateSuffix, '0'),
  ];
}

function wb_products_truncate(string $value, int $limit): string
{
  $value = trim($value);
  if ($limit <= 0) {
    return '';
  }
  return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function wb_products_json(array $row): ?string
{
  $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return is_string($json) ? $json : null;
}

function wb_products_lower(string $value): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function wb_products_text_contains_any(string $haystack, array $needles): bool
{
  foreach ($needles as $needle) {
    $needle = (string)$needle;
    if ($needle !== '' && str_contains($haystack, $needle)) {
      return true;
    }
  }
  return false;
}

function wb_products_issue_label_from_text(string $text): string
{
  $text = trim($text);
  if ($text === '') {
    return '';
  }
  $lower = wb_products_lower($text);
  if (
    wb_products_text_contains_any($lower, [
      'не удалось объедин',
      'объединить в похожие товары',
      'объединить с другими товарами',
      'похожие товары',
      'merge',
      'similar products',
    ])
  ) {
    return 'ошибка объединения';
  }
  if (wb_products_text_contains_any($lower, ['дубл', 'duplicate', 'уже существует', 'already exists'])) {
    return 'дубль товара';
  }
  if (wb_products_text_contains_any($lower, ['фото', 'изображ', 'photo', 'image'])) {
    return 'ошибка фото';
  }
  if (wb_products_text_contains_any($lower, ['бренд', 'brand'])) {
    return 'ошибка бренда';
  }
  if (wb_products_text_contains_any($lower, ['тн вэд', 'тнвэд', 'tnved', 'tn ved'])) {
    return 'нет кода ТН ВЭД';
  }
  if (wb_products_text_contains_any($lower, ['габарит', 'упаковк', 'размер', 'dimension', 'height', 'width', 'length', 'weight'])) {
    return 'габариты упаковки';
  }
  if (wb_products_text_contains_any($lower, ['штрихкод', 'баркод', 'barcode'])) {
    return 'ошибка штрихкода';
  }
  if (wb_products_text_contains_any($lower, ['категор', 'предмет', 'subject'])) {
    return 'ошибка категории';
  }
  if (wb_products_text_contains_any($lower, ['цена', 'price'])) {
    return 'ошибка цены';
  }
  if (preg_match('~характеристик[а-я\s]*:\s*(тип|type)\s*$~iu', $text)) {
    return 'ошибка категории';
  }
  if (preg_match('~характеристик[а-я\s]*:\s*(название|name|title)\s*$~iu', $text)) {
    return 'ошибка в названии';
  }
  if (wb_products_text_contains_any($lower, ['характерист', 'characteristic', 'значен'])) {
    return 'ошибка характеристики';
  }
  return wb_products_truncate($text, 120);
}

function wb_products_issue_labels_from_text(string $text): array
{
  $parts = preg_split('~[\n;]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $labels = [];
  foreach ($parts as $part) {
    $label = wb_products_issue_label_from_text((string)$part);
    if ($label !== '') {
      $labels[] = $label;
    }
  }
  if (!$labels && trim($text) !== '') {
    $labels[] = wb_products_issue_label_from_text($text);
  }
  return array_values(array_unique($labels));
}

function wb_products_quality_number($value): ?float
{
  if (is_int($value) || is_float($value)) {
    $number = (float)$value;
  } elseif (is_string($value)) {
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
      return null;
    }
    $number = (float)$normalized;
  } else {
    return null;
  }
  if (!is_finite($number)) {
    return null;
  }
  return max(0.0, min(10.0, round($number, 1)));
}

function wb_products_card_quality_score_from_raw(array $card): ?float
{
  $keys = [
    'qualityScore',
    'quality_score',
    'qualityRating',
    'quality_rating',
    'cardQuality',
    'card_quality',
    'cardRating',
    'card_rating',
    'contentRating',
    'content_rating',
  ];
  foreach ($keys as $key) {
    if (array_key_exists($key, $card)) {
      $score = wb_products_quality_number($card[$key]);
      if ($score !== null) {
        return $score;
      }
      if (is_array($card[$key])) {
        foreach (['score', 'rating', 'value', 'total'] as $nestedKey) {
          if (array_key_exists($nestedKey, $card[$key])) {
            $score = wb_products_quality_number($card[$key][$nestedKey]);
            if ($score !== null) {
              return $score;
            }
          }
        }
      }
    }
  }
  foreach (['quality', 'qualityInfo', 'quality_info', 'cardQualityInfo'] as $key) {
    if (!empty($card[$key]) && is_array($card[$key])) {
      foreach (['score', 'rating', 'value', 'total'] as $nestedKey) {
        if (array_key_exists($nestedKey, $card[$key])) {
          $score = wb_products_quality_number($card[$key][$nestedKey]);
          if ($score !== null) {
            return $score;
          }
        }
      }
    }
  }
  return null;
}

function wb_products_quality_recommendation_type(string $text, string $fallbackType = ''): array
{
  $type = wb_products_lower(trim($fallbackType));
  $lower = wb_products_lower($text . ' ' . $type);

  if (wb_products_text_contains_any($lower, ['наимен', 'назван', 'title', 'name'])) {
    return ['name', 'Наименование'];
  }
  if (wb_products_text_contains_any($lower, ['фото', 'изображ', 'photo', 'image', 'ракурс'])) {
    return ['photo', 'Фото'];
  }
  if (wb_products_text_contains_any($lower, ['описан', 'description'])) {
    return ['description', 'Описание'];
  }
  if (wb_products_text_contains_any($lower, ['характерист', 'characteristic', 'param'])) {
    return ['characteristics', 'Характеристики'];
  }
  if (wb_products_text_contains_any($lower, ['габарит', 'упаковк', 'размер', 'dimension'])) {
    return ['dimensions', 'Габариты'];
  }
  if (wb_products_text_contains_any($lower, ['видео', 'video', 'rich'])) {
    return ['media', 'Медиа'];
  }
  if (wb_products_text_contains_any($lower, ['штрихкод', 'баркод', 'barcode'])) {
    return ['barcode', 'Штрихкод'];
  }
  return ['content', 'Контент'];
}

function wb_products_quality_recommendation_short(string $text, string $fallback = ''): string
{
  $fallback = trim($fallback);
  $lower = wb_products_lower($text . ' ' . $fallback);
  if (wb_products_text_contains_any($lower, ['повтор', 'синоним', 'duplicate'])) {
    return 'повторы';
  }
  if (wb_products_text_contains_any($lower, ['лишн', 'подробност', 'детал'])) {
    return 'детали';
  }
  if (wb_products_text_contains_any($lower, ['ракурс'])) {
    return 'ракурсы';
  }
  if (wb_products_text_contains_any($lower, ['главн', 'облож'])) {
    return 'главное фото';
  }
  if (wb_products_text_contains_any($lower, ['мало', 'больше', 'добав', 'заполн'])) {
    return 'заполнить';
  }
  if (wb_products_text_contains_any($lower, ['проверь', 'исправ'])) {
    return 'проверить';
  }
  if ($fallback !== '') {
    return wb_products_truncate($fallback, 32);
  }
  return 'улучшить';
}

function wb_products_quality_recommendation(array $row): array
{
  $text = trim((string)($row['text'] ?? ($row['message'] ?? ($row['recommendation'] ?? ($row['title'] ?? ($row['name'] ?? ''))))));
  $comment = trim((string)($row['comment'] ?? ($row['hint'] ?? ($row['description'] ?? ($row['reason'] ?? '')))));
  $short = trim((string)($row['short'] ?? ($row['code'] ?? '')));
  $typeRaw = trim((string)($row['type'] ?? ($row['category'] ?? ($row['section'] ?? ($row['field'] ?? '')))));

  if ($text === '' && $comment !== '') {
    $text = $comment;
    $comment = '';
  }

  [$type, $label] = wb_products_quality_recommendation_type($text . ' ' . $comment, $typeRaw);
  $short = wb_products_quality_recommendation_short($text . ' ' . $comment, $short);

  return [
    'type' => $type,
    'label' => $label,
    'short' => $short,
    'text' => $text !== '' ? $text : $label,
    'comment' => $comment,
  ];
}

function wb_products_card_quality_recommendations_from_raw(array $card): array
{
  $candidates = [];
  foreach (['qualityRecommendations', 'quality_recommendations', 'recommendations', 'improvements', 'advices'] as $key) {
    if (array_key_exists($key, $card) && is_array($card[$key])) {
      $candidates[] = $card[$key];
    }
  }
  foreach (['quality', 'qualityInfo', 'quality_info', 'cardQualityInfo'] as $key) {
    if (!empty($card[$key]) && is_array($card[$key])) {
      foreach (['recommendations', 'improvements', 'advices', 'items'] as $nestedKey) {
        if (array_key_exists($nestedKey, $card[$key]) && is_array($card[$key][$nestedKey])) {
          $candidates[] = $card[$key][$nestedKey];
        }
      }
    }
  }

  $out = [];
  foreach ($candidates as $items) {
    foreach ($items as $item) {
      if (is_string($item)) {
        $item = ['text' => $item];
      }
      if (!is_array($item)) {
        continue;
      }
      $rec = wb_products_quality_recommendation($item);
      if (trim((string)$rec['text']) !== '') {
        $out[] = $rec;
      }
    }
  }
  return wb_products_quality_deduplicate_recommendations($out);
}

function wb_products_quality_title_words(string $title): array
{
  if ($title === '' || !preg_match_all('~[\p{L}\p{N}]+~u', wb_products_lower($title), $m)) {
    return [];
  }
  $stop = array_flip([
    'для',
    'или',
    'под',
    'без',
    'при',
    'что',
    'как',
    'the',
    'and',
    'for',
    'with',
    'from',
    'pro',
    'mini',
    'micro',
  ]);
  $words = [];
  foreach ($m[0] as $word) {
    $word = trim((string)$word);
    $len = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
    if ($len < 3 || isset($stop[$word])) {
      continue;
    }
    $words[] = $word;
  }
  return $words;
}

function wb_products_card_has_repeated_title_words(string $title): bool
{
  $seen = [];
  foreach (wb_products_quality_title_words($title) as $word) {
    if (isset($seen[$word])) {
      return true;
    }
    $seen[$word] = true;
  }
  return false;
}

function wb_products_card_quality_photo_count(array $card): int
{
  foreach (['photos', 'mediaFiles', 'images'] as $key) {
    if (isset($card[$key]) && is_array($card[$key])) {
      return count($card[$key]);
    }
  }
  return 0;
}

function wb_products_card_quality_characteristic_count(array $card): int
{
  foreach (['characteristics', 'characteristicsList', 'params'] as $key) {
    if (isset($card[$key]) && is_array($card[$key])) {
      return count($card[$key]);
    }
  }
  return 0;
}

function wb_products_quality_add_recommendation(array &$target, string $type, string $label, string $short, string $text, string $comment = ''): void
{
  $target[] = [
    'type' => $type,
    'label' => $label,
    'short' => $short,
    'text' => $text,
    'comment' => $comment,
  ];
}

function wb_products_quality_deduplicate_recommendations(array $recommendations): array
{
  $out = [];
  $seen = [];
  foreach ($recommendations as $rec) {
    if (!is_array($rec)) {
      continue;
    }
    $type = trim((string)($rec['type'] ?? 'content'));
    $label = trim((string)($rec['label'] ?? 'Контент'));
    $short = trim((string)($rec['short'] ?? 'улучшить'));
    $text = trim((string)($rec['text'] ?? ''));
    $comment = trim((string)($rec['comment'] ?? ''));
    if ($text === '') {
      continue;
    }
    $key = wb_products_lower($type . '|' . $short . '|' . $text);
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $out[] = [
      'type' => $type,
      'label' => $label,
      'short' => $short,
      'text' => $text,
      'comment' => $comment,
    ];
  }
  return array_slice($out, 0, 12);
}

function wb_products_card_quality_recommendations(array $card, string $statusText = ''): array
{
  $recommendations = wb_products_card_quality_recommendations_from_raw($card);

  $title = trim((string)($card['title'] ?? ($card['name'] ?? '')));
  $titleLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
  if ($title !== '' && wb_products_card_has_repeated_title_words($title)) {
    wb_products_quality_add_recommendation(
      $recommendations,
      'name',
      'Наименование',
      'повторы',
      'Удалите из наименования повторяющиеся слова и синонимы',
      'Так товар будет проще найти через поиск'
    );
  }
  if ($titleLen > 60 || ($titleLen > 45 && preg_match('~[/,;|]~u', $title))) {
    wb_products_quality_add_recommendation(
      $recommendations,
      'name',
      'Наименование',
      'детали',
      'Удалите из наименования лишние подробности о товаре',
      'Рекомендации и другие детали лучше добавить в поле «Описание»'
    );
  }

  $photosCount = wb_products_card_quality_photo_count($card);
  if ($photosCount === 0 || wb_products_text_contains_any(wb_products_lower($statusText), ['ошибка фото', 'фото'])) {
    wb_products_quality_add_recommendation(
      $recommendations,
      'photo',
      'Фото',
      'главное фото',
      'Добавьте главное фото товара',
      'Карточка без фото хуже проходит проверку и хуже выглядит в каталоге'
    );
  } elseif ($photosCount < 3) {
    wb_products_quality_add_recommendation(
      $recommendations,
      'photo',
      'Фото',
      'ракурсы',
      'Добавьте фото товара с разных ракурсов',
      'Так покупателю будет проще принять решение о заказе'
    );
  }

  $dimensions = $card['dimensions'] ?? null;
  if (
    (is_array($dimensions) && array_key_exists('isValid', $dimensions) && (bool)$dimensions['isValid'] === false)
    || wb_products_text_contains_any(wb_products_lower($statusText), ['габарит', 'упаковк'])
  ) {
    wb_products_quality_add_recommendation(
      $recommendations,
      'dimensions',
      'Габариты',
      'проверить',
      'Проверьте габариты упаковки',
      'Размеры упаковки должны быть заполнены корректно в сантиметрах'
    );
  }

  return wb_products_quality_deduplicate_recommendations($recommendations);
}

function wb_products_card_quality_score(array $card, array $recommendations): ?float
{
  $rawScore = wb_products_card_quality_score_from_raw($card);
  if ($rawScore !== null) {
    return $rawScore;
  }

  $score = 10.0;
  foreach ($recommendations as $rec) {
    if (!is_array($rec)) {
      continue;
    }
    $type = (string)($rec['type'] ?? '');
    $short = wb_products_lower((string)($rec['short'] ?? ''));
    if ($type === 'photo' && str_contains($short, 'глав')) {
      $score -= 3.0;
    } elseif ($type === 'photo') {
      $score -= 1.5;
    } elseif ($type === 'name') {
      $score -= 1.5;
    } elseif ($type === 'description') {
      $score -= 1.0;
    } elseif ($type === 'characteristics') {
      $score -= 1.0;
    } elseif ($type === 'dimensions') {
      $score -= 1.5;
    } else {
      $score -= 0.5;
    }
  }

  return max(0.0, min(10.0, round($score, 1)));
}

function wb_products_card_quality_summary(array $card, string $statusText = ''): array
{
  $recommendations = wb_products_card_quality_recommendations($card, $statusText);
  return [
    'score' => wb_products_card_quality_score($card, $recommendations),
    'recommendations' => $recommendations,
  ];
}

function wb_products_card_marketplace_status(array $card, string $defaultStatus, int $isTrash, string $defaultText = ''): array
{
  if ($isTrash === 1 || $defaultStatus === 'archived') {
    return ['archived', $defaultText !== '' ? $defaultText : 'в архиве'];
  }
  if ($defaultStatus === 'error') {
    return ['error', $defaultText];
  }

  $photos = $card['photos'] ?? null;
  if (!is_array($photos) || count($photos) === 0) {
    return ['error', 'ошибка фото'];
  }

  $dimensions = $card['dimensions'] ?? null;
  if (is_array($dimensions) && array_key_exists('isValid', $dimensions) && (bool)$dimensions['isValid'] === false) {
    return ['revision', 'габариты упаковки'];
  }

  return ['ready', $defaultText];
}

function wb_products_card_rows(array $cards, string $marketplaceStatus = 'ready', int $isActive = 1, int $isTrash = 0, string $statusText = ''): array
{
  $rows = [];
  foreach ($cards as $card) {
    if (!is_array($card)) {
      continue;
    }

    $vendorCode = trim((string)($card['vendorCode'] ?? ($card['vendor_code'] ?? '')));
    if ($vendorCode === '') {
      continue;
    }

    [$resolvedStatus, $resolvedText] = wb_products_card_marketplace_status($card, $marketplaceStatus, $isTrash, $statusText);
    $quality = wb_products_card_quality_summary($card, $resolvedText);

    $rows[] = [
      'vendor_code' => $vendorCode,
      'nm_id' => isset($card['nmID']) && is_numeric($card['nmID']) ? (int)$card['nmID'] : null,
      'imt_id' => isset($card['imtID']) && is_numeric($card['imtID']) ? (int)$card['imtID'] : null,
      'subject_id' => isset($card['subjectID']) && is_numeric($card['subjectID']) ? (int)$card['subjectID'] : null,
      'is_active' => $isActive,
      'marketplace_status' => $resolvedStatus,
      'status_text' => $resolvedText,
      'quality_score' => $quality['score'],
      'quality_recommendations_json' => wb_products_json((array)($quality['recommendations'] ?? [])),
      'is_trash' => $isTrash,
      'raw_json' => wb_products_json($card),
    ];
  }
  return $rows;
}

function wb_products_find_first_key_recursive($value, array $keys)
{
  if (!is_array($value)) {
    return null;
  }
  foreach ($keys as $key) {
    if (array_key_exists($key, $value) && $value[$key] !== null && $value[$key] !== '') {
      return $value[$key];
    }
  }
  foreach ($value as $child) {
    if (is_array($child)) {
      $found = wb_products_find_first_key_recursive($child, $keys);
      if ($found !== null && $found !== '') {
        return $found;
      }
    }
  }
  return null;
}

function wb_products_error_text_from_item(array $item): string
{
  $parts = [];
  foreach (['errorText', 'error', 'message'] as $key) {
    $value = trim((string)($item[$key] ?? ''));
    if ($value !== '') {
      $parts[] = $value;
    }
  }
  foreach (['errors', 'additionalErrors'] as $key) {
    $value = $item[$key] ?? null;
    if (is_array($value)) {
      foreach ($value as $row) {
        if (is_array($row)) {
          $text = trim((string)($row['error'] ?? ($row['message'] ?? ($row['text'] ?? ''))));
          if ($text !== '') {
            $parts[] = $text;
          }
        } else {
          $text = trim((string)$row);
          if ($text !== '') {
            $parts[] = $text;
          }
        }
      }
    } elseif (trim((string)$value) !== '') {
      $parts[] = trim((string)$value);
    }
  }
  $parts = array_values(array_unique($parts));
  return wb_products_truncate(implode('; ', $parts), 255);
}

function wb_products_error_rows(array $items): array
{
  $rows = [];
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $vendorCode = wb_products_find_first_key_recursive($item, ['vendorCode', 'vendor_code', 'vendorСode']);
    $vendorCode = trim((string)$vendorCode);
    if ($vendorCode === '') {
      continue;
    }
    $statusText = implode("\n", wb_products_issue_labels_from_text(wb_products_error_text_from_item($item)));
    $quality = wb_products_card_quality_summary($item, $statusText);
    $rows[] = [
      'vendor_code' => $vendorCode,
      'nm_id' => ($v = wb_products_find_first_key_recursive($item, ['nmID', 'nm_id'])) !== null && is_numeric($v) ? (int)$v : null,
      'imt_id' => ($v = wb_products_find_first_key_recursive($item, ['imtID', 'imt_id'])) !== null && is_numeric($v) ? (int)$v : null,
      'subject_id' => ($v = wb_products_find_first_key_recursive($item, ['subjectID', 'subject_id'])) !== null && is_numeric($v) ? (int)$v : null,
      'is_active' => 0,
      'marketplace_status' => 'error',
      'status_text' => $statusText,
      'quality_score' => $quality['score'],
      'quality_recommendations_json' => wb_products_json((array)($quality['recommendations'] ?? [])),
      'is_trash' => 0,
      'raw_json' => wb_products_json($item),
    ];
  }
  return $rows;
}

function wb_products_upsert_rows(PDO $pdo, array $rows, string $seenAt, int $connectionId = 0): int
{
  if (!$rows) {
    return 0;
  }

  $stmt = $pdo->prepare("
    INSERT INTO feedtools_wb_products
      (connection_id, vendor_code, nm_id, imt_id, subject_id, is_active, marketplace_status, status_text, quality_score, quality_recommendations_json, is_trash, raw_json, last_seen_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      nm_id = VALUES(nm_id),
      imt_id = VALUES(imt_id),
      subject_id = VALUES(subject_id),
      is_active = VALUES(is_active),
      marketplace_status = VALUES(marketplace_status),
      status_text = VALUES(status_text),
      quality_score = VALUES(quality_score),
      quality_recommendations_json = VALUES(quality_recommendations_json),
      is_trash = VALUES(is_trash),
      raw_json = VALUES(raw_json),
      last_seen_at = VALUES(last_seen_at),
      updated_at = CURRENT_TIMESTAMP
  ");

  $done = 0;
  foreach ($rows as $row) {
    $stmt->execute([
      $connectionId,
      (string)$row['vendor_code'],
      $row['nm_id'],
      $row['imt_id'],
      $row['subject_id'],
      (int)($row['is_active'] ?? 1),
      (string)($row['marketplace_status'] ?? 'ready'),
      wb_products_truncate((string)($row['status_text'] ?? ''), 255),
      $row['quality_score'] ?? null,
      $row['quality_recommendations_json'] ?? null,
      (int)($row['is_trash'] ?? 0),
      $row['raw_json'] ?? null,
      $seenAt,
    ]);
    $done++;
  }

  return $done;
}

function wb_products_sync_trash(WildberriesClient $client, PDO $pdo, int $connectionId, string $seenAt, int $limit, ?callable $log = null, int $opId = 0): array
{
  $cursor = ['limit' => $limit];
  $lastCursorKey = '';
  $pages = 0;
  $processed = 0;
  $upserted = 0;

  for ($page = 1; $page <= 200000; $page++) {
    $resp = $client->contentPost('/content/v2/get/cards/trash', [
      'settings' => [
        'cursor' => $cursor,
        'filter' => [
          'withPhoto' => -1,
        ],
      ],
    ], 'content');
    $cards = $resp['cards'] ?? ($resp['data']['cards'] ?? []);
    if (!is_array($cards)) {
      $cards = [];
    }
    $rows = wb_products_card_rows($cards, 'archived', 0, 1, 'в архиве');
    if ($rows) {
      $pdo->beginTransaction();
      try {
        $upserted += wb_products_upsert_rows($pdo, $rows, $seenAt, $connectionId);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $e;
      }
    }
    $pages = $page;
    $processed += count($rows);
    if ($log) {
      $log("WB trash page={$page}, cards=" . count($cards) . ", rows=" . count($rows) . ", processed={$processed}\n");
    }
    if ($opId > 0) {
      ops_update_progress($opId, min(99, 75 + ($page % 20)), 100, 'trash', "WB archive page {$page}: processed={$processed}");
    }
    $respCursor = $resp['cursor'] ?? ($resp['data']['cursor'] ?? []);
    if (!is_array($respCursor)) {
      break;
    }
    $total = (int)($respCursor['total'] ?? count($cards));
    if (count($cards) === 0 || $total < $limit) {
      break;
    }
    $updatedAt = trim((string)($respCursor['updatedAt'] ?? ''));
    $nmID = $respCursor['nmID'] ?? null;
    if ($updatedAt === '' || !is_numeric($nmID)) {
      break;
    }
    $cursor = [
      'limit' => $limit,
      'updatedAt' => $updatedAt,
      'nmID' => (int)$nmID,
    ];
    $cursorKey = json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($cursorKey === false || $cursorKey === $lastCursorKey) {
      break;
    }
    $lastCursorKey = $cursorKey;
  }

  return ['processed' => $processed, 'upserted' => $upserted, 'pages' => $pages];
}

function wb_products_sync_errors(WildberriesClient $client, PDO $pdo, int $connectionId, string $seenAt, int $limit, ?callable $log = null, int $opId = 0): array
{
  $cursor = ['limit' => $limit];
  $pages = 0;
  $processed = 0;
  $upserted = 0;

  for ($page = 1; $page <= 200000; $page++) {
    $resp = $client->contentPost('/content/v2/cards/error/list', [
      'cursor' => $cursor,
      'order' => [
        'ascending' => true,
      ],
    ], 'content');
    $items = $resp['data']['items'] ?? ($resp['items'] ?? []);
    if (!is_array($items)) {
      $items = [];
    }
    $rows = wb_products_error_rows($items);
    if ($rows) {
      $pdo->beginTransaction();
      try {
        $upserted += wb_products_upsert_rows($pdo, $rows, $seenAt, $connectionId);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $e;
      }
    }
    $pages = $page;
    $processed += count($rows);
    if ($log) {
      $log("WB error cards page={$page}, items=" . count($items) . ", rows=" . count($rows) . ", processed={$processed}\n");
    }
    if ($opId > 0) {
      ops_update_progress($opId, min(99, 90 + ($page % 8)), 100, 'errors', "WB errors page {$page}: processed={$processed}");
    }

    $respCursor = $resp['data']['cursor'] ?? ($resp['cursor'] ?? []);
    if (!is_array($respCursor) || empty($respCursor['next'])) {
      break;
    }
    $updatedAt = trim((string)($respCursor['updatedAt'] ?? ''));
    $batchUUID = trim((string)($respCursor['batchUUID'] ?? ''));
    if ($updatedAt === '' || $batchUUID === '') {
      break;
    }
    $cursor = [
      'limit' => $limit,
      'updatedAt' => $updatedAt,
      'batchUUID' => $batchUUID,
    ];
  }

  return ['processed' => $processed, 'upserted' => $upserted, 'pages' => $pages];
}

function wb_products_sync_full(array $cfg, int $opId = 0, ?callable $log = null, array $options = []): array
{
  $pdo = db();
  wb_products_ensure_table($pdo);

  $client = new WildberriesClient((array)($cfg['wildberries'] ?? []));
  $connectionId = (int)($options['connection_id'] ?? ($cfg['price_tool_connection']['id'] ?? 0));
  $limit = (int)($options['limit'] ?? 100);
  if ($limit < 1) $limit = 100;
  if ($limit > 100) $limit = 100;

  $now = date('Y-m-d H:i:s');
  $cursor = ['limit' => $limit];
  $lastCursorKey = '';
  $pages = 0;
  $processed = 0;
  $upserted = 0;

  if ($opId > 0) {
    ops_update_progress($opId, 0, 100, 'init', 'WB sync: preparing products table');
  }

  for ($page = 1; $page <= 200000; $page++) {
    $resp = $client->getCardsList([
      'cursor' => $cursor,
      'filter' => [
        'withPhoto' => -1,
      ],
    ]);

    $cards = $resp['cards'] ?? ($resp['data']['cards'] ?? []);
    if (!is_array($cards)) {
      $cards = [];
    }

    $rows = wb_products_card_rows($cards);
    if ($rows) {
      $pdo->beginTransaction();
      try {
        $upserted += wb_products_upsert_rows($pdo, $rows, $now, $connectionId);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $e;
      }
    }

    $pages = $page;
    $processed += count($rows);

    if ($log) {
      $log("WB sync page={$page}, cards=" . count($cards) . ", rows=" . count($rows) . ", processed={$processed}\n");
    }
    if ($opId > 0) {
      $pct = min(99, 5 + ($page % 94));
      ops_update_progress($opId, $pct, 100, 'sync', "WB cards page {$page}: processed={$processed}");
    }

    $respCursor = $resp['cursor'] ?? ($resp['data']['cursor'] ?? []);
    if (!is_array($respCursor)) {
      break;
    }

    $total = (int)($respCursor['total'] ?? count($cards));
    if (count($cards) === 0 || $total < $limit) {
      break;
    }

    $updatedAt = trim((string)($respCursor['updatedAt'] ?? ''));
    $nmID = $respCursor['nmID'] ?? null;
    if ($updatedAt === '' || !is_numeric($nmID)) {
      break;
    }

    $cursor = [
      'limit' => $limit,
      'updatedAt' => $updatedAt,
      'nmID' => (int)$nmID,
    ];

    $cursorKey = json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($cursorKey === false || $cursorKey === $lastCursorKey) {
      break;
    }
    $lastCursorKey = $cursorKey;
  }

  $trashResult = ['processed' => 0, 'upserted' => 0, 'pages' => 0, 'error' => ''];
  try {
    $trashResult = wb_products_sync_trash($client, $pdo, $connectionId, $now, $limit, $log, $opId);
  } catch (Throwable $e) {
    $trashResult['error'] = $e->getMessage();
    if ($log) {
      $log("WB trash sync skipped: " . $e->getMessage() . "\n");
    }
  }
  $errorResult = ['processed' => 0, 'upserted' => 0, 'pages' => 0, 'error' => ''];
  try {
    $errorResult = wb_products_sync_errors($client, $pdo, $connectionId, $now, $limit, $log, $opId);
  } catch (Throwable $e) {
    $errorResult['error'] = $e->getMessage();
    if ($log) {
      $log("WB error-card sync skipped: " . $e->getMessage() . "\n");
    }
  }

  if ($connectionId > 0) {
    $st = $pdo->prepare("
      UPDATE feedtools_wb_products
      SET is_active = 0,
          marketplace_status = 'not_created',
          status_text = '',
          is_trash = 0
      WHERE connection_id = ? AND is_active = 1 AND last_seen_at <> ?
    ");
    $st->execute([$connectionId, $now]);
  } else {
    $st = $pdo->prepare("
      UPDATE feedtools_wb_products
      SET is_active = 0,
          marketplace_status = 'not_created',
          status_text = '',
          is_trash = 0
      WHERE is_active = 1 AND last_seen_at <> ?
    ");
    $st->execute([$now]);
  }
  $deactivated = (int)$st->rowCount();

  $stateSuffix = $connectionId > 0 ? ('_' . $connectionId) : '';
  wb_products_state_set($pdo, 'last_success_at' . $stateSuffix, $now);
  wb_products_state_set($pdo, 'last_success_total' . $stateSuffix, (string)$processed);

  $stats = wb_products_stats($pdo, $connectionId);

  if ($opId > 0) {
    ops_update_progress($opId, 100, 100, 'done', "WB sync done: active={$stats['active_total']}, processed={$processed}");
  }

  return [
    'processed' => $processed,
    'upserted' => $upserted,
    'trash_processed' => (int)$trashResult['processed'],
    'trash_upserted' => (int)$trashResult['upserted'],
    'error_processed' => (int)$errorResult['processed'],
    'error_upserted' => (int)$errorResult['upserted'],
    'trash_error' => (string)($trashResult['error'] ?? ''),
    'error_sync_error' => (string)($errorResult['error'] ?? ''),
    'deactivated' => $deactivated,
    'active_total' => (int)$stats['active_total'],
    'pages' => $pages,
    'trash_pages' => (int)$trashResult['pages'],
    'error_pages' => (int)$errorResult['pages'],
    'limit' => $limit,
    'last_success_at' => $now,
  ];
}

function wb_products_offer_exists(array $offer, array $presentSet): bool
{
  $candidates = [
    (string)($offer['id'] ?? ''),
    (string)($offer['article'] ?? ''),
    (string)($offer['vendorCode'] ?? ($offer['vendor_code'] ?? '')),
  ];

  foreach ($candidates as $candidate) {
    $candidate = trim($candidate);
    if ($candidate !== '' && isset($presentSet[$candidate])) {
      return true;
    }
  }

  return false;
}
