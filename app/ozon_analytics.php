<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/ozon_price_tool.php';

function ozon_analytics_metric_columns(): array
{
  return [
    'hits_view_search' => ['column' => 'hits_view_search', 'kind' => 'int'],
    'hits_view_pdp' => ['column' => 'hits_view_pdp', 'kind' => 'int'],
    'hits_view' => ['column' => 'hits_view', 'kind' => 'int'],
    'hits_tocart_search' => ['column' => 'hits_tocart_search', 'kind' => 'int'],
    'hits_tocart_pdp' => ['column' => 'hits_tocart_pdp', 'kind' => 'int'],
    'hits_tocart' => ['column' => 'hits_tocart', 'kind' => 'int'],
    'session_view_search' => ['column' => 'session_view_search', 'kind' => 'int'],
    'session_view_pdp' => ['column' => 'session_view_pdp', 'kind' => 'int'],
    'session_view' => ['column' => 'session_view', 'kind' => 'int'],
    'conv_tocart_search' => ['column' => 'conv_tocart_search', 'kind' => 'decimal'],
    'conv_tocart_pdp' => ['column' => 'conv_tocart_pdp', 'kind' => 'decimal'],
    'conv_tocart' => ['column' => 'conv_tocart', 'kind' => 'decimal'],
    'revenue' => ['column' => 'revenue', 'kind' => 'money'],
    'returns' => ['column' => 'returns_count', 'kind' => 'int'],
    'cancellations' => ['column' => 'cancellations_count', 'kind' => 'int'],
    'ordered_units' => ['column' => 'ordered_units', 'kind' => 'int'],
    'delivered_units' => ['column' => 'delivered_units', 'kind' => 'int'],
    'adv_view_pdp' => ['column' => 'adv_view_pdp', 'kind' => 'int'],
    'adv_view_search_category' => ['column' => 'adv_view_search_category', 'kind' => 'int'],
    'adv_view_all' => ['column' => 'adv_view_all', 'kind' => 'int'],
    'adv_sum_all' => ['column' => 'adv_sum_all', 'kind' => 'money'],
    'position_category' => ['column' => 'position_category', 'kind' => 'decimal'],
    'postings' => ['column' => 'postings', 'kind' => 'int'],
    'postings_premium' => ['column' => 'postings_premium', 'kind' => 'int'],
  ];
}

function ozon_analytics_default_metrics(): array
{
  return array_values(array_diff(array_keys(ozon_analytics_metric_columns()), ozon_analytics_deprecated_metrics()));
}

function ozon_analytics_deprecated_metrics(): array
{
  return [
    // Ozon now rejects this metric with "deprecated metrics used".
    'adv_sum_all',
  ];
}

function ozon_analytics_metric_chunks(array $metrics): array
{
  $known = ozon_analytics_metric_columns();
  $out = [];
  $seen = [];
  foreach ($metrics as $metric) {
    $metric = trim((string)$metric);
    if ($metric === '' || !isset($known[$metric]) || isset($seen[$metric])) {
      continue;
    }
    $seen[$metric] = true;
    $out[] = $metric;
  }
  if (!$out) {
    $out = ozon_analytics_default_metrics();
  }
  return array_chunk($out, 14);
}

function ozon_analytics_tables_ensure(array $cfg = []): void
{
  static $done = false;
  if ($done) {
    return;
  }

  ozon_products_tables_ensure($cfg);
  $pdo = db();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_ozon_product_analytics_daily (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      ozon_client_id VARCHAR(64) NOT NULL DEFAULT '',
      analytics_date DATE NOT NULL,
      sku BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sku_text VARCHAR(80) NOT NULL DEFAULT '',
      offer_id VARCHAR(80) NOT NULL DEFAULT '',
      product_id BIGINT UNSIGNED NULL,
      hits_view_search BIGINT UNSIGNED NULL,
      hits_view_pdp BIGINT UNSIGNED NULL,
      hits_view BIGINT UNSIGNED NULL,
      hits_tocart_search BIGINT UNSIGNED NULL,
      hits_tocart_pdp BIGINT UNSIGNED NULL,
      hits_tocart BIGINT UNSIGNED NULL,
      session_view_search BIGINT UNSIGNED NULL,
      session_view_pdp BIGINT UNSIGNED NULL,
      session_view BIGINT UNSIGNED NULL,
      conv_tocart_search DECIMAL(14,4) NULL,
      conv_tocart_pdp DECIMAL(14,4) NULL,
      conv_tocart DECIMAL(14,4) NULL,
      revenue DECIMAL(18,2) NULL,
      returns_count BIGINT UNSIGNED NULL,
      cancellations_count BIGINT UNSIGNED NULL,
      ordered_units BIGINT UNSIGNED NULL,
      delivered_units BIGINT UNSIGNED NULL,
      adv_view_pdp BIGINT UNSIGNED NULL,
      adv_view_search_category BIGINT UNSIGNED NULL,
      adv_view_all BIGINT UNSIGNED NULL,
      adv_sum_all DECIMAL(18,2) NULL,
      position_category DECIMAL(14,4) NULL,
      postings BIGINT UNSIGNED NULL,
      postings_premium BIGINT UNSIGNED NULL,
      dimensions_json LONGTEXT NULL,
      raw_metrics_json LONGTEXT NULL,
      last_op_id BIGINT UNSIGNED NULL,
      fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_connection_date_sku (connection_id, analytics_date, sku_text),
      KEY idx_connection_offer_date (connection_id, offer_id, analytics_date),
      KEY idx_connection_sku_date (connection_id, sku, analytics_date),
      KEY idx_connection_date (connection_id, analytics_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $columns = [
    'ozon_client_id' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN ozon_client_id VARCHAR(64) NOT NULL DEFAULT '' AFTER connection_id",
    'sku_text' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN sku_text VARCHAR(80) NOT NULL DEFAULT '' AFTER sku",
    'product_id' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN product_id BIGINT UNSIGNED NULL AFTER offer_id",
    'dimensions_json' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN dimensions_json LONGTEXT NULL AFTER postings_premium",
    'raw_metrics_json' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN raw_metrics_json LONGTEXT NULL AFTER dimensions_json",
    'last_op_id' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN last_op_id BIGINT UNSIGNED NULL AFTER raw_metrics_json",
    'fetched_at' => "ALTER TABLE feedtools_ozon_product_analytics_daily ADD COLUMN fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_op_id",
  ];
  foreach ($columns as $column => $alterSql) {
    ozon_products_table_add_column_if_missing($pdo, 'feedtools_ozon_product_analytics_daily', $column, $alterSql);
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_ozon_analytics_sync_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      op_id BIGINT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      ozon_client_id VARCHAR(64) NOT NULL DEFAULT '',
      date_from DATE NOT NULL,
      date_to DATE NOT NULL,
      days_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      requested_metrics_json LONGTEXT NULL,
      available_metrics_json LONGTEXT NULL,
      failed_metrics_json LONGTEXT NULL,
      api_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
      rows_upserted BIGINT UNSIGNED NOT NULL DEFAULT 0,
      pages_count INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'running',
      error_text TEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_connection_period (connection_id, date_from, date_to),
      KEY idx_op_id (op_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_product_analytics_daily',
    'uq_connection_date_sku',
    "ALTER TABLE feedtools_ozon_product_analytics_daily ADD UNIQUE KEY uq_connection_date_sku (connection_id, analytics_date, sku_text)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_product_analytics_daily',
    'idx_connection_offer_date',
    "ALTER TABLE feedtools_ozon_product_analytics_daily ADD KEY idx_connection_offer_date (connection_id, offer_id, analytics_date)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_product_analytics_daily',
    'idx_connection_sku_date',
    "ALTER TABLE feedtools_ozon_product_analytics_daily ADD KEY idx_connection_sku_date (connection_id, sku, analytics_date)"
  );

  $done = true;
}

function ozon_analytics_parse_date_range(array $params = []): array
{
  $days = (int)($params['days'] ?? 90);
  if ($days < 1) {
    $days = 90;
  }
  if ($days > 90) {
    $days = 90;
  }

  $includeToday = !empty($params['include_today']);
  $dateTo = trim((string)($params['date_to'] ?? ''));
  if ($dateTo === '') {
    $dateTo = date('Y-m-d', strtotime($includeToday ? 'today' : 'yesterday'));
  }
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo)) {
    throw new RuntimeException('Некорректная дата окончания аналитики Ozon.');
  }

  $dateFrom = trim((string)($params['date_from'] ?? ''));
  if ($dateFrom === '') {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));
  }
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateFrom)) {
    throw new RuntimeException('Некорректная дата начала аналитики Ozon.');
  }
  if (strtotime($dateFrom) > strtotime($dateTo)) {
    throw new RuntimeException('Дата начала аналитики Ozon больше даты окончания.');
  }

  $actualDays = (int)floor(((int)strtotime($dateTo) - (int)strtotime($dateFrom)) / 86400) + 1;
  if ($actualDays > 90) {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -89 days'));
    $actualDays = 90;
  }

  return [$dateFrom, $dateTo, $actualDays];
}

function ozon_analytics_connection_context(int $connectionId, array $cfg): array
{
  if ($connectionId <= 0) {
    throw new RuntimeException('Для синхронизации аналитики Ozon нужно передать connection_id.');
  }
  $connection = ozon_price_connection_resolve($connectionId, $cfg);
  $cfgWithConnection = ozon_price_cfg_with_connection($cfg, $connection);
  $oz = ozon_cfg_or_fail($cfgWithConnection);
  return [
    'connection' => $connection,
    'cfg' => $cfgWithConnection,
    'oz' => $oz,
    'connection_id' => (int)($connection['id'] ?? $connectionId),
    'client_id' => (string)($oz['client_id'] ?? ''),
  ];
}

function ozon_analytics_extract_result_rows(array $resp): array
{
  $result = $resp['result'] ?? $resp;
  if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
    return $result['data'];
  }
  if (isset($resp['data']) && is_array($resp['data'])) {
    return $resp['data'];
  }
  return [];
}

function ozon_analytics_dimension_value(array $dimension): string
{
  foreach (['id', 'value', 'name'] as $key) {
    $value = trim((string)($dimension[$key] ?? ''));
    if ($value !== '') {
      return $value;
    }
  }
  return '';
}

function ozon_analytics_row_key_parts(array $row): ?array
{
  $dimensions = $row['dimensions'] ?? ($row['dimension'] ?? []);
  if (!is_array($dimensions) || count($dimensions) < 2) {
    return null;
  }
  $dayValue = is_array($dimensions[0] ?? null) ? ozon_analytics_dimension_value($dimensions[0]) : trim((string)($dimensions[0] ?? ''));
  $skuValue = is_array($dimensions[1] ?? null) ? ozon_analytics_dimension_value($dimensions[1]) : trim((string)($dimensions[1] ?? ''));
  if (!preg_match('~^\d{4}-\d{2}-\d{2}~', $dayValue, $m)) {
    return null;
  }
  $date = $m[0];
  $skuText = trim($skuValue);
  if ($skuText === '') {
    return null;
  }
  $sku = preg_match('~^\d+$~', $skuText) ? (int)$skuText : 0;
  return [$date, $skuText, $sku, $dimensions];
}

function ozon_analytics_metric_value(string $metric, $value)
{
  if ($value === null || $value === '') {
    return null;
  }
  if (is_array($value)) {
    return null;
  }
  $cfg = ozon_analytics_metric_columns()[$metric] ?? null;
  if (!is_array($cfg)) {
    return null;
  }
  $number = is_numeric($value) ? (float)$value : (float)str_replace(',', '.', (string)$value);
  if (($cfg['kind'] ?? '') === 'int') {
    return max(0, (int)round($number));
  }
  if (($cfg['kind'] ?? '') === 'money') {
    return round($number, 2);
  }
  return round($number, 4);
}

function ozon_analytics_metrics_from_row(array $row, array $requestedMetrics): array
{
  $values = $row['metrics'] ?? [];
  if (!is_array($values)) {
    return [];
  }

  $out = [];
  $isAssoc = array_keys($values) !== range(0, count($values) - 1);
  foreach ($requestedMetrics as $idx => $metric) {
    $metric = (string)$metric;
    if ($isAssoc) {
      $raw = $values[$metric] ?? null;
    } else {
      $raw = $values[$idx] ?? null;
    }
    $parsed = ozon_analytics_metric_value($metric, $raw);
    if ($parsed !== null) {
      $out[$metric] = $parsed;
    }
  }
  return $out;
}

function ozon_analytics_sku_map(int $connectionId, array $skuTexts): array
{
  $skuTexts = array_values(array_unique(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    $skuTexts
  ))));
  if (!$skuTexts) {
    return [];
  }

  $map = [];
  foreach (array_chunk($skuTexts, 500) as $chunk) {
    $numeric = [];
    foreach ($chunk as $skuText) {
      if (preg_match('~^\d+$~', $skuText)) {
        $numeric[] = (int)$skuText;
      }
    }
    if (!$numeric) {
      continue;
    }
    $placeholders = implode(',', array_fill(0, count($numeric), '?'));
    $st = db()->prepare("
      SELECT sku, offer_id, product_id
      FROM feedtools_ozon_products
      WHERE connection_id = ?
        AND sku IN ({$placeholders})
    ");
    $st->execute(array_merge([$connectionId], $numeric));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $sku = trim((string)($row['sku'] ?? ''));
      if ($sku !== '') {
        $map[$sku] = [
          'offer_id' => trim((string)($row['offer_id'] ?? '')),
          'product_id' => isset($row['product_id']) ? (int)$row['product_id'] : null,
        ];
      }
    }
  }
  return $map;
}

function ozon_analytics_error_is_transient($error): bool
{
  $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
  $message = strtolower($message);
  foreach ([
    'http 408',
    'http 429',
    'http 500',
    'http 502',
    'http 503',
    'http 504',
    'rate limit',
    'too many requests',
    'timed out',
    'timeout',
    'temporarily unavailable',
  ] as $needle) {
    if (str_contains($message, $needle)) {
      return true;
    }
  }
  return false;
}

function ozon_analytics_rate_limit_wait(int $minIntervalMs = 1200): void
{
  static $lastRequestAt = 0.0;
  $now = microtime(true);
  if ($lastRequestAt > 0) {
    $elapsedMs = ($now - $lastRequestAt) * 1000;
    $waitMs = $minIntervalMs - $elapsedMs;
    if ($waitMs > 0) {
      usleep((int)round($waitMs * 1000));
    }
  }
  $lastRequestAt = microtime(true);
}

function ozon_analytics_retry_delay_sec(int $attempt, string $message): int
{
  $message = strtolower($message);
  if (str_contains($message, 'rate limit') || str_contains($message, 'http 429') || str_contains($message, 'too many requests')) {
    return min(30, max(2, 2 ** min(5, $attempt)));
  }
  return min(20, max(1, 2 ** min(4, $attempt - 1)));
}

function ozon_analytics_sleep_with_progress(
  int $seconds,
  ?callable $progress,
  int $done,
  int $total,
  string $message
): void {
  $seconds = max(1, $seconds);
  for ($left = $seconds; $left > 0; $left--) {
    if ($progress) {
      $progress($done, $total, 'rate_limit_wait', $message . " пауза {$left}с");
    }
    sleep(1);
  }
}

function ozon_analytics_fetch_page(
  array $oz,
  string $dateFrom,
  string $dateTo,
  array $metrics,
  int $limit,
  int $offset,
  ?callable $progress = null,
  ?callable $log = null,
  array $progressState = []
): array
{
  $payload = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'metrics' => array_values($metrics),
    'dimension' => ['day', 'sku'],
    'filters' => [],
    'sort' => [],
    'limit' => $limit,
    'offset' => $offset,
  ];
  $maxAttempts = 8;
  $done = (int)($progressState['done'] ?? 0);
  $total = max(1, (int)($progressState['total'] ?? 1));
  $metricsLabel = implode(',', $metrics);

  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    ozon_analytics_rate_limit_wait();
    try {
      return ozon_post_json($oz, '/v1/analytics/data', $payload);
    } catch (Throwable $e) {
      if (!ozon_analytics_error_is_transient($e) || $attempt >= $maxAttempts) {
        throw $e;
      }
      $delay = ozon_analytics_retry_delay_sec($attempt, $e->getMessage());
      if ($log) {
        $log("WARN Ozon analytics temporary API error, retry {$attempt}/{$maxAttempts} after {$delay}s, metrics={$metricsLabel}, offset={$offset}: " . $e->getMessage() . "\n");
      }
      ozon_analytics_sleep_with_progress(
        $delay,
        $progress,
        $done,
        $total,
        "Ozon analytics: лимит API, повтор {$attempt}/{$maxAttempts}"
      );
    }
  }

  throw new RuntimeException('Ozon analytics request failed after retries.');
}

function ozon_analytics_make_upsert_statement(): PDOStatement
{
  $metricMap = ozon_analytics_metric_columns();
  $metricColumns = array_values(array_map(static fn(array $row): string => $row['column'], $metricMap));
  $columns = array_merge([
    'connection_id',
    'ozon_client_id',
    'analytics_date',
    'sku',
    'sku_text',
    'offer_id',
    'product_id',
  ], $metricColumns, [
    'dimensions_json',
    'raw_metrics_json',
    'last_op_id',
    'fetched_at',
  ]);

  $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
  $updates = [
    'ozon_client_id = VALUES(ozon_client_id)',
    "offer_id = CASE WHEN VALUES(offer_id) <> '' THEN VALUES(offer_id) ELSE offer_id END",
    'product_id = COALESCE(VALUES(product_id), product_id)',
  ];
  foreach ($metricColumns as $column) {
    $updates[] = "{$column} = COALESCE(VALUES({$column}), {$column})";
  }
  $updates[] = 'dimensions_json = COALESCE(VALUES(dimensions_json), dimensions_json)';
  $updates[] = 'raw_metrics_json = COALESCE(VALUES(raw_metrics_json), raw_metrics_json)';
  $updates[] = 'last_op_id = VALUES(last_op_id)';
  $updates[] = 'fetched_at = VALUES(fetched_at)';
  $updates[] = 'updated_at = CURRENT_TIMESTAMP';

  return db()->prepare("
    INSERT INTO feedtools_ozon_product_analytics_daily
      (" . implode(', ', $columns) . ")
    VALUES
      (" . implode(', ', $placeholders) . ")
    ON DUPLICATE KEY UPDATE
      " . implode(",\n      ", $updates) . "
  ");
}

function ozon_analytics_upsert_rows(
  int $connectionId,
  string $clientId,
  int $opId,
  array $rows,
  array $metrics,
  PDOStatement $stmt
): int {
  if (!$rows) {
    return 0;
  }

  $skuTexts = [];
  $prepared = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $parts = ozon_analytics_row_key_parts($row);
    if ($parts === null) {
      continue;
    }
    [$date, $skuText, $sku, $dimensions] = $parts;
    $rowMetrics = ozon_analytics_metrics_from_row($row, $metrics);
    if (!$rowMetrics) {
      continue;
    }
    $skuTexts[] = $skuText;
    $prepared[] = [
      'date' => $date,
      'sku_text' => $skuText,
      'sku' => $sku,
      'dimensions' => $dimensions,
      'metrics' => $rowMetrics,
    ];
  }
  if (!$prepared) {
    return 0;
  }

  $skuMap = ozon_analytics_sku_map($connectionId, $skuTexts);
  $metricMap = ozon_analytics_metric_columns();
  $metricColumns = array_values(array_map(static fn(array $row): string => $row['column'], $metricMap));
  $now = date('Y-m-d H:i:s');
  $done = 0;

  foreach ($prepared as $row) {
    $lookup = $skuMap[$row['sku_text']] ?? [];
    $params = [
      ':connection_id' => $connectionId,
      ':ozon_client_id' => $clientId,
      ':analytics_date' => $row['date'],
      ':sku' => (int)$row['sku'],
      ':sku_text' => $row['sku_text'],
      ':offer_id' => trim((string)($lookup['offer_id'] ?? '')),
      ':product_id' => isset($lookup['product_id']) && (int)$lookup['product_id'] > 0 ? (int)$lookup['product_id'] : null,
      ':dimensions_json' => json_encode($row['dimensions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':raw_metrics_json' => json_encode($row['metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':last_op_id' => $opId > 0 ? $opId : null,
      ':fetched_at' => $now,
    ];
    foreach ($metricColumns as $column) {
      $params[':' . $column] = null;
    }
    foreach ($row['metrics'] as $metric => $value) {
      $column = (string)($metricMap[$metric]['column'] ?? '');
      if ($column !== '') {
        $params[':' . $column] = $value;
      }
    }
    $stmt->execute($params);
    $done++;
  }

  return $done;
}

function ozon_analytics_sync_metrics_chunk(
  array $oz,
  int $connectionId,
  string $clientId,
  int $opId,
  int $runId,
  string $dateFrom,
  string $dateTo,
  array $metrics,
  int $limit,
  callable $progress,
  callable $log,
  array &$stats
): void {
  $stmt = ozon_analytics_make_upsert_statement();
  $offset = 0;
  $pages = 0;
  $maxPages = 10000;

  while ($pages < $maxPages) {
    $resp = ozon_analytics_fetch_page($oz, $dateFrom, $dateTo, $metrics, $limit, $offset, $progress, $log, [
      'done' => (int)$stats['rows_upserted'],
      'total' => max(1, (int)$stats['estimated_total']),
    ]);
    $rows = ozon_analytics_extract_result_rows($resp);
    $count = count($rows);
    if ($count <= 0) {
      break;
    }
    $pages++;
    $stats['api_rows'] += $count;
    $stats['pages_count']++;

    $upserted = ozon_analytics_upsert_rows($connectionId, $clientId, $opId, $rows, $metrics, $stmt);
    $stats['rows_upserted'] += $upserted;
    if ((int)$stats['rows_upserted'] >= (int)$stats['estimated_total']) {
      $stats['estimated_total'] = (int)$stats['rows_upserted'] + max($limit, (int)ceil((int)$stats['rows_upserted'] * 0.08));
    }
    foreach ($metrics as $metric) {
      $stats['available_metrics'][$metric] = true;
      unset($stats['failed_metrics'][$metric]);
    }

    $progress(
      (int)$stats['rows_upserted'],
      max(1, (int)$stats['estimated_total']),
      'sync',
      'Ozon analytics: ' . implode(',', $metrics) . " rows={$stats['rows_upserted']}"
    );

    db()->prepare("
      UPDATE feedtools_ozon_analytics_sync_runs
      SET api_rows = ?, rows_upserted = ?, pages_count = ?, available_metrics_json = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([
      (int)$stats['api_rows'],
      (int)$stats['rows_upserted'],
      (int)$stats['pages_count'],
      json_encode(array_keys($stats['available_metrics']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $runId,
    ]);

    if ($count < $limit) {
      break;
    }
    $offset += $limit;
  }

  $log('metrics ' . implode(',', $metrics) . ": pages={$pages}, offset={$offset}\n");
}

function ozon_analytics_sync_connection(
  array $cfg,
  int $connectionId,
  string $dateFrom,
  string $dateTo,
  int $daysCount,
  array $metrics,
  int $opId,
  callable $progress,
  callable $log,
  array $options = []
): array {
  ozon_analytics_tables_ensure($cfg);
  $ctx = ozon_analytics_connection_context($connectionId, $cfg);
  $connectionId = (int)$ctx['connection_id'];
  $clientId = (string)$ctx['client_id'];
  $oz = $ctx['oz'];

  $limit = (int)($options['limit'] ?? 1000);
  if ($limit < 100) {
    $limit = 100;
  }
  if ($limit > 1000) {
    $limit = 1000;
  }

  $metrics = array_values(array_unique(array_intersect($metrics, ozon_analytics_default_metrics())));
  if (!$metrics) {
    $metrics = ozon_analytics_default_metrics();
  }
  $deprecatedMetrics = array_values(array_intersect($metrics, ozon_analytics_deprecated_metrics()));
  if ($deprecatedMetrics) {
    $metrics = array_values(array_diff($metrics, $deprecatedMetrics));
    $log('skipped deprecated Ozon analytics metrics: ' . implode(',', $deprecatedMetrics) . "\n");
  }
  if (!$metrics) {
    throw new RuntimeException('Нет доступных метрик Ozon analytics для синхронизации.');
  }

  $runSt = db()->prepare("
    INSERT INTO feedtools_ozon_analytics_sync_runs
      (op_id, connection_id, ozon_client_id, date_from, date_to, days_count, requested_metrics_json, status)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, 'running')
  ");
  $runSt->execute([
    $opId > 0 ? $opId : null,
    $connectionId,
    $clientId,
    $dateFrom,
    $dateTo,
    $daysCount,
    json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);
  $runId = (int)db()->lastInsertId();

  $stats = [
    'run_id' => $runId,
    'api_rows' => 0,
    'rows_upserted' => 0,
    'pages_count' => 0,
    'available_metrics' => [],
    'failed_metrics' => [],
    'estimated_total' => max(1, $daysCount * 1000),
  ];

  $log("ozon_analytics_sync: connection_id={$connectionId}, client_id={$clientId}, {$dateFrom}..{$dateTo}, days={$daysCount}\n");
  $log('requested metrics: ' . implode(',', $metrics) . "\n");

  try {
    foreach (ozon_analytics_metric_chunks($metrics) as $chunk) {
      try {
        ozon_analytics_sync_metrics_chunk($oz, $connectionId, $clientId, $opId, $runId, $dateFrom, $dateTo, $chunk, $limit, $progress, $log, $stats);
      } catch (Throwable $e) {
        $log('WARN metrics chunk failed (' . implode(',', $chunk) . '): ' . $e->getMessage() . "\n");
        if (ozon_analytics_error_is_transient($e)) {
          throw $e;
        }
        if (count($chunk) <= 1) {
          $stats['failed_metrics'][$chunk[0]] = $e->getMessage();
          continue;
        }
        foreach ($chunk as $metric) {
          try {
            ozon_analytics_sync_metrics_chunk($oz, $connectionId, $clientId, $opId, $runId, $dateFrom, $dateTo, [$metric], $limit, $progress, $log, $stats);
          } catch (Throwable $singleError) {
            if (ozon_analytics_error_is_transient($singleError)) {
              throw $singleError;
            }
            $stats['failed_metrics'][$metric] = $singleError->getMessage();
            $log("WARN metric {$metric} unavailable: " . $singleError->getMessage() . "\n");
          }
        }
      }
    }

    db()->prepare("
      UPDATE feedtools_ozon_analytics_sync_runs
      SET status = 'done',
          api_rows = ?,
          rows_upserted = ?,
          pages_count = ?,
          available_metrics_json = ?,
          failed_metrics_json = ?,
          finished_at = NOW(),
          updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([
      (int)$stats['api_rows'],
      (int)$stats['rows_upserted'],
      (int)$stats['pages_count'],
      json_encode(array_keys($stats['available_metrics']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      json_encode($stats['failed_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $runId,
    ]);
  } catch (Throwable $e) {
    db()->prepare("
      UPDATE feedtools_ozon_analytics_sync_runs
      SET status = 'error', error_text = ?, finished_at = NOW(), updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$e->getMessage(), $runId]);
    throw $e;
  }

  return [
    'run_id' => $runId,
    'connection_id' => $connectionId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'days_count' => $daysCount,
    'api_rows' => (int)$stats['api_rows'],
    'rows_upserted' => (int)$stats['rows_upserted'],
    'pages_count' => (int)$stats['pages_count'],
    'available_metrics' => array_keys($stats['available_metrics']),
    'failed_metrics' => $stats['failed_metrics'],
  ];
}

function ozon_analytics_summary_columns_sql(string $prefix = ''): string
{
  $p = $prefix;
  return "
    SUM(COALESCE({$p}hits_view_search,0)) AS hits_view_search,
    SUM(COALESCE({$p}hits_view_pdp,0)) AS hits_view_pdp,
    SUM(COALESCE({$p}hits_view,0)) AS hits_view,
    SUM(COALESCE({$p}hits_tocart_search,0)) AS hits_tocart_search,
    SUM(COALESCE({$p}hits_tocart_pdp,0)) AS hits_tocart_pdp,
    SUM(COALESCE({$p}hits_tocart,0)) AS hits_tocart,
    SUM(COALESCE({$p}session_view_search,0)) AS session_view_search,
    SUM(COALESCE({$p}session_view_pdp,0)) AS session_view_pdp,
    SUM(COALESCE({$p}session_view,0)) AS session_view,
    AVG({$p}conv_tocart_search) AS avg_conv_tocart_search,
    AVG({$p}conv_tocart_pdp) AS avg_conv_tocart_pdp,
    AVG({$p}conv_tocart) AS avg_conv_tocart,
    SUM(COALESCE({$p}revenue,0)) AS revenue,
    SUM(COALESCE({$p}returns_count,0)) AS returns_count,
    SUM(COALESCE({$p}cancellations_count,0)) AS cancellations_count,
    SUM(COALESCE({$p}ordered_units,0)) AS ordered_units,
    SUM(COALESCE({$p}delivered_units,0)) AS delivered_units,
    SUM(COALESCE({$p}adv_view_pdp,0)) AS adv_view_pdp,
    SUM(COALESCE({$p}adv_view_search_category,0)) AS adv_view_search_category,
    SUM(COALESCE({$p}adv_view_all,0)) AS adv_view_all,
    SUM(COALESCE({$p}adv_sum_all,0)) AS adv_sum_all,
    AVG({$p}position_category) AS avg_position_category,
    SUM(COALESCE({$p}postings,0)) AS postings,
    SUM(COALESCE({$p}postings_premium,0)) AS postings_premium
  ";
}

function ozon_analytics_product_summary(int $connectionId, string $offerId = '', string $skuText = '', int $days = 90, ?string $dateTo = null): array
{
  ozon_analytics_tables_ensure();
  $days = max(1, min(90, $days));
  $dateTo = $dateTo && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo) ? $dateTo : date('Y-m-d', strtotime('yesterday'));
  $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));

  $where = ['connection_id = ?', 'analytics_date BETWEEN ? AND ?'];
  $args = [$connectionId, $dateFrom, $dateTo];
  if (trim($offerId) !== '') {
    $where[] = 'offer_id = ?';
    $args[] = trim($offerId);
  }
  if (trim($skuText) !== '') {
    $where[] = 'sku_text = ?';
    $args[] = trim($skuText);
  }

  $sql = "SELECT COUNT(*) AS rows_count, MIN(analytics_date) AS date_from, MAX(analytics_date) AS date_to, " .
    ozon_analytics_summary_columns_sql() .
    " FROM feedtools_ozon_product_analytics_daily WHERE " . implode(' AND ', $where);
  $st = db()->prepare($sql);
  $st->execute($args);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    return [];
  }
  $row['cart_conversion_from_impressions_pct'] = ((float)($row['hits_view'] ?? 0) > 0)
    ? round(((float)($row['hits_tocart'] ?? 0) / (float)$row['hits_view']) * 100, 4)
    : null;
  $row['order_conversion_from_pdp_sessions_pct'] = ((float)($row['session_view_pdp'] ?? 0) > 0)
    ? round(((float)($row['ordered_units'] ?? 0) / (float)$row['session_view_pdp']) * 100, 4)
    : null;
  return $row;
}

function ozon_analytics_recent_for_offers(int $connectionId, array $offerIds, int $days = 90, ?string $dateTo = null): array
{
  ozon_analytics_tables_ensure();
  $offerIds = array_values(array_unique(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    $offerIds
  ))));
  if (!$offerIds) {
    return [];
  }
  $days = max(1, min(90, $days));
  $dateTo = $dateTo && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo) ? $dateTo : date('Y-m-d', strtotime('yesterday'));
  $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));

  $out = [];
  foreach (array_chunk($offerIds, 500) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "
      SELECT offer_id, COUNT(*) AS rows_count, MIN(analytics_date) AS date_from, MAX(analytics_date) AS date_to,
        " . ozon_analytics_summary_columns_sql() . "
      FROM feedtools_ozon_product_analytics_daily
      WHERE connection_id = ?
        AND analytics_date BETWEEN ? AND ?
        AND offer_id IN ({$placeholders})
      GROUP BY offer_id
    ";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$connectionId, $dateFrom, $dateTo], $chunk));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $offerId = trim((string)($row['offer_id'] ?? ''));
      if ($offerId !== '') {
        $out[$offerId] = $row;
      }
    }
  }
  return $out;
}
