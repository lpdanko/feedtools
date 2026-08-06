<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/wildberries/WildberriesClient.php';
require_once __DIR__ . '/wildberries/WildberriesPriceTool.php';
require_once __DIR__ . '/wildberries/WildberriesProducts.php';

function wb_analytics_tables_ensure(array $cfg = []): void
{
  static $done = false;
  if ($done) {
    return;
  }

  ozon_price_connections_table_ensure($cfg);
  wb_products_ensure_table(db());
  $pdo = db();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_wb_product_analytics_daily (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      wb_seller_id VARCHAR(80) NOT NULL DEFAULT '',
      analytics_date DATE NOT NULL,
      nm_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      vendor_code VARCHAR(255) NOT NULL DEFAULT '',
      product_name VARCHAR(512) NOT NULL DEFAULT '',
      brand_name VARCHAR(255) NOT NULL DEFAULT '',
      subject_name VARCHAR(255) NOT NULL DEFAULT '',
      open_card_count BIGINT UNSIGNED NULL,
      add_to_cart_count BIGINT UNSIGNED NULL,
      orders_count BIGINT UNSIGNED NULL,
      orders_sum_rub DECIMAL(18,2) NULL,
      buyouts_count BIGINT UNSIGNED NULL,
      buyouts_sum_rub DECIMAL(18,2) NULL,
      cancel_count BIGINT UNSIGNED NULL,
      cancel_sum_rub DECIMAL(18,2) NULL,
      avg_price_rub DECIMAL(18,2) NULL,
      avg_orders_count_per_day DECIMAL(14,4) NULL,
      add_to_cart_conversion DECIMAL(14,4) NULL,
      cart_to_order_conversion DECIMAL(14,4) NULL,
      buyout_percent DECIMAL(14,4) NULL,
      stock_mp BIGINT UNSIGNED NULL,
      stock_wb BIGINT UNSIGNED NULL,
      raw_json LONGTEXT NULL,
      last_op_id BIGINT UNSIGNED NULL,
      fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_connection_date_nm (connection_id, analytics_date, nm_id),
      KEY idx_connection_vendor_date (connection_id, vendor_code, analytics_date),
      KEY idx_connection_nm_date (connection_id, nm_id, analytics_date),
      KEY idx_connection_date (connection_id, analytics_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $columns = [
    'wb_seller_id' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN wb_seller_id VARCHAR(80) NOT NULL DEFAULT '' AFTER connection_id",
    'product_name' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN product_name VARCHAR(512) NOT NULL DEFAULT '' AFTER vendor_code",
    'brand_name' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN brand_name VARCHAR(255) NOT NULL DEFAULT '' AFTER product_name",
    'subject_name' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN subject_name VARCHAR(255) NOT NULL DEFAULT '' AFTER brand_name",
    'raw_json' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN raw_json LONGTEXT NULL AFTER stock_wb",
    'last_op_id' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN last_op_id BIGINT UNSIGNED NULL AFTER raw_json",
    'fetched_at' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD COLUMN fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_op_id",
  ];
  foreach ($columns as $column => $alterSql) {
    if (!wb_products_table_has_column($pdo, 'feedtools_wb_product_analytics_daily', $column)) {
      try {
        $pdo->exec($alterSql);
      } catch (Throwable $e) {
        // Concurrent migrations are harmless here.
      }
    }
  }

  foreach ([
    'uq_connection_date_nm' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD UNIQUE KEY uq_connection_date_nm (connection_id, analytics_date, nm_id)",
    'idx_connection_vendor_date' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD KEY idx_connection_vendor_date (connection_id, vendor_code, analytics_date)",
    'idx_connection_nm_date' => "ALTER TABLE feedtools_wb_product_analytics_daily ADD KEY idx_connection_nm_date (connection_id, nm_id, analytics_date)",
  ] as $indexName => $sql) {
    if (!wb_products_table_has_index($pdo, 'feedtools_wb_product_analytics_daily', $indexName)) {
      try {
        $pdo->exec($sql);
      } catch (Throwable $e) {
        // Optional/duplicate index race.
      }
    }
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_wb_product_search_analytics_period (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      wb_seller_id VARCHAR(80) NOT NULL DEFAULT '',
      date_from DATE NOT NULL,
      date_to DATE NOT NULL,
      nm_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      vendor_code VARCHAR(255) NOT NULL DEFAULT '',
      product_name VARCHAR(512) NOT NULL DEFAULT '',
      subject_name VARCHAR(255) NOT NULL DEFAULT '',
      search_open_card_count BIGINT UNSIGNED NULL,
      search_add_to_cart_count BIGINT UNSIGNED NULL,
      search_orders_count BIGINT UNSIGNED NULL,
      search_open_to_cart_conversion DECIMAL(14,4) NULL,
      search_cart_to_order_conversion DECIMAL(14,4) NULL,
      search_visibility DECIMAL(14,4) NULL,
      search_avg_position DECIMAL(14,4) NULL,
      raw_json LONGTEXT NULL,
      last_op_id BIGINT UNSIGNED NULL,
      fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_connection_period_nm (connection_id, date_from, date_to, nm_id),
      KEY idx_connection_vendor_period (connection_id, vendor_code, date_from, date_to),
      KEY idx_connection_period (connection_id, date_from, date_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_wb_analytics_sync_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      op_id BIGINT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      wb_seller_id VARCHAR(80) NOT NULL DEFAULT '',
      date_from DATE NOT NULL,
      date_to DATE NOT NULL,
      days_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      download_id VARCHAR(80) NOT NULL DEFAULT '',
      report_type VARCHAR(80) NOT NULL DEFAULT 'DETAIL_HISTORY_REPORT',
      api_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
      rows_upserted BIGINT UNSIGNED NOT NULL DEFAULT 0,
      search_api_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
      search_rows_upserted BIGINT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'running',
      error_text TEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_connection_period (connection_id, date_from, date_to),
      KEY idx_op_id (op_id),
      KEY idx_download_id (download_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  foreach ([
    'search_api_rows' => "ALTER TABLE feedtools_wb_analytics_sync_runs ADD COLUMN search_api_rows BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER rows_upserted",
    'search_rows_upserted' => "ALTER TABLE feedtools_wb_analytics_sync_runs ADD COLUMN search_rows_upserted BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER search_api_rows",
  ] as $column => $alterSql) {
    if (!wb_products_table_has_column($pdo, 'feedtools_wb_analytics_sync_runs', $column)) {
      try {
        $pdo->exec($alterSql);
      } catch (Throwable $e) {
        // Concurrent migrations are harmless here.
      }
    }
  }

  $done = true;
}

function wb_analytics_parse_date_range(array $params = []): array
{
  $days = max(1, min(90, (int)($params['days'] ?? 90)));
  $dateTo = trim((string)($params['date_to'] ?? ''));
  if ($dateTo === '') {
    $dateTo = date('Y-m-d', strtotime('yesterday'));
  }
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo)) {
    throw new RuntimeException('Некорректная дата окончания аналитики WB.');
  }

  $dateFrom = trim((string)($params['date_from'] ?? ''));
  if ($dateFrom === '') {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));
  }
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateFrom)) {
    throw new RuntimeException('Некорректная дата начала аналитики WB.');
  }
  if (strtotime($dateFrom) > strtotime($dateTo)) {
    throw new RuntimeException('Дата начала аналитики WB больше даты окончания.');
  }

  $actualDays = (int)floor(((int)strtotime($dateTo) - (int)strtotime($dateFrom)) / 86400) + 1;
  if ($actualDays > 90) {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -89 days'));
    $actualDays = 90;
  }

  return [$dateFrom, $dateTo, $actualDays];
}

function wb_analytics_connections(array $cfg = []): array
{
  ozon_price_connections_table_ensure($cfg);
  return ozon_price_connection_list($cfg, 'wb');
}

function wb_analytics_connection_context(int $connectionId, array $cfg): array
{
  if ($connectionId <= 0) {
    throw new RuntimeException('Для синхронизации аналитики WB нужно передать connection_id.');
  }
  $connection = ozon_price_connection_get($connectionId, $cfg);
  if (!is_array($connection) || trim((string)($connection['marketplace'] ?? '')) !== 'wb') {
    throw new RuntimeException('Выбранное подключение WB не найдено.');
  }
  $cfgWithConnection = ozon_price_cfg_with_connection($cfg, $connection);
  return [
    'connection' => $connection,
    'cfg' => $cfgWithConnection,
    'client' => wb_price_tool_client($cfg, $connection),
    'connection_id' => (int)($connection['id'] ?? $connectionId),
    'seller_id' => trim((string)($connection['client_id'] ?? '')),
  ];
}

function wb_analytics_uuid_v4(): string
{
  $bytes = random_bytes(16);
  $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
  $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function wb_analytics_create_report(WildberriesClient $client, string $dateFrom, string $dateTo): string
{
  $downloadId = wb_analytics_uuid_v4();
  $payload = [
    'id' => $downloadId,
    'reportType' => 'DETAIL_HISTORY_REPORT',
    'userReportName' => 'FeedTools WB analytics ' . $dateFrom . '..' . $dateTo,
    'params' => [
      'startDate' => $dateFrom,
      'endDate' => $dateTo,
    ],
  ];
  $response = $client->analyticsPost('/api/v2/nm-report/downloads', $payload);
  $returned = trim((string)($response['id'] ?? ''));
  if ($returned !== '' && preg_match('~^[0-9a-f-]{32,36}$~i', $returned)) {
    return $returned;
  }
  return $downloadId;
}

function wb_analytics_extract_downloads(array $response): array
{
  $data = $response['data'] ?? $response['result'] ?? $response;
  if (is_array($data) && isset($data['downloads']) && is_array($data['downloads'])) {
    return $data['downloads'];
  }
  if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
    return $data['items'];
  }
  if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
    return $data;
  }
  return is_array($data) ? [$data] : [];
}

function wb_analytics_download_status(array $download): string
{
  foreach (['status', 'state', 'downloadStatus'] as $key) {
    $value = strtolower(trim((string)($download[$key] ?? '')));
    if ($value !== '') {
      return $value;
    }
  }
  return '';
}

function wb_analytics_wait_report(
  WildberriesClient $client,
  string $downloadId,
  int $opId,
  int $runId,
  int $total,
  callable $progress,
  callable $log,
  int $timeoutSec = 900
): array {
  $started = time();
  $attempt = 0;
  while (true) {
    $attempt++;
    $response = $client->analyticsGet('/api/v2/nm-report/downloads', [
      'filter[downloadIds]' => $downloadId,
    ]);
    $downloads = wb_analytics_extract_downloads($response);
    $download = is_array($downloads[0] ?? null) ? $downloads[0] : [];
    $status = wb_analytics_download_status($download);

    $progress(0, max(1, $total), 'report_wait', 'WB analytics: отчет готовится' . ($status !== '' ? ', статус ' . $status : ''));
    if ($status !== '') {
      db()->prepare("UPDATE feedtools_wb_analytics_sync_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$status === 'done' ? 'downloading' : 'running', $runId]);
    }

    if (in_array($status, ['done', 'ready', 'success', 'completed', 'complete'], true)) {
      return $download;
    }
    if (in_array($status, ['error', 'failed', 'fail', 'cancelled', 'canceled'], true)) {
      throw new RuntimeException('WB analytics report failed: ' . json_encode($download, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if ((time() - $started) >= $timeoutSec) {
      throw new RuntimeException('WB analytics report timeout: отчет не был готов за ' . $timeoutSec . ' секунд.');
    }

    if ($attempt === 1 || $attempt % 6 === 0) {
      $log('waiting WB analytics report download_id=' . $downloadId . ', status=' . ($status !== '' ? $status : '-') . "\n");
    }
    sleep($attempt < 6 ? 10 : 20);
  }
}

function wb_analytics_download_report(WildberriesClient $client, string $downloadId): string
{
  $response = $client->analyticsGetRaw('/api/v2/nm-report/downloads/file/' . rawurlencode($downloadId));
  $body = (string)($response['body'] ?? '');
  if ($body === '') {
    throw new RuntimeException('WB analytics report file is empty.');
  }
  return $body;
}

function wb_analytics_header_key(string $value): string
{
  $value = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $value));
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  return preg_replace('~[^a-zа-я0-9]+~u', '', $value) ?: '';
}

function wb_analytics_detect_delimiter(string $line): string
{
  $candidates = [',', ';', "\t"];
  $best = ',';
  $bestCount = -1;
  foreach ($candidates as $delimiter) {
    $count = substr_count($line, $delimiter);
    if ($count > $bestCount) {
      $best = $delimiter;
      $bestCount = $count;
    }
  }
  return $best;
}

function wb_analytics_float($value): ?float
{
  if ($value === null || is_array($value)) {
    return null;
  }
  $value = trim(str_replace(["\xc2\xa0", ' '], '', (string)$value));
  if ($value === '' || $value === '-') {
    return null;
  }
  $value = str_replace(',', '.', $value);
  return is_numeric($value) ? (float)$value : null;
}

function wb_analytics_int($value): ?int
{
  $number = wb_analytics_float($value);
  return $number === null ? null : max(0, (int)round($number));
}

function wb_analytics_csv_get(array $row, array $headerMap, array $keys)
{
  foreach ($keys as $key) {
    $idx = $headerMap[$key] ?? null;
    if ($idx !== null && array_key_exists($idx, $row)) {
      return $row[$idx];
    }
  }
  return null;
}

function wb_analytics_date_from_value($value, string $fallback): string
{
  $value = trim((string)$value);
  if (preg_match('~^\d{4}-\d{2}-\d{2}~', $value, $m)) {
    return $m[0];
  }
  if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})~', $value, $m)) {
    return $m[3] . '-' . $m[2] . '-' . $m[1];
  }
  return $fallback;
}

function wb_analytics_make_upsert_statement(): PDOStatement
{
  $columns = [
    'connection_id',
    'wb_seller_id',
    'analytics_date',
    'nm_id',
    'vendor_code',
    'product_name',
    'brand_name',
    'subject_name',
    'open_card_count',
    'add_to_cart_count',
    'orders_count',
    'orders_sum_rub',
    'buyouts_count',
    'buyouts_sum_rub',
    'cancel_count',
    'cancel_sum_rub',
    'avg_price_rub',
    'avg_orders_count_per_day',
    'add_to_cart_conversion',
    'cart_to_order_conversion',
    'buyout_percent',
    'stock_mp',
    'stock_wb',
    'raw_json',
    'last_op_id',
    'fetched_at',
  ];
  $updates = [
    'wb_seller_id = VALUES(wb_seller_id)',
    "vendor_code = CASE WHEN VALUES(vendor_code) <> '' THEN VALUES(vendor_code) ELSE vendor_code END",
    "product_name = CASE WHEN VALUES(product_name) <> '' THEN VALUES(product_name) ELSE product_name END",
    "brand_name = CASE WHEN VALUES(brand_name) <> '' THEN VALUES(brand_name) ELSE brand_name END",
    "subject_name = CASE WHEN VALUES(subject_name) <> '' THEN VALUES(subject_name) ELSE subject_name END",
  ];
  foreach (array_slice($columns, 8, 15) as $column) {
    $updates[] = "{$column} = COALESCE(VALUES({$column}), {$column})";
  }
  $updates[] = 'raw_json = COALESCE(VALUES(raw_json), raw_json)';
  $updates[] = 'last_op_id = VALUES(last_op_id)';
  $updates[] = 'fetched_at = VALUES(fetched_at)';
  $updates[] = 'updated_at = CURRENT_TIMESTAMP';

  return db()->prepare("
    INSERT INTO feedtools_wb_product_analytics_daily
      (" . implode(', ', $columns) . ")
    VALUES
      (" . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ")
    ON DUPLICATE KEY UPDATE
      " . implode(",\n      ", $updates) . "
  ");
}

function wb_analytics_nm_map(int $connectionId, array $nmIds): array
{
  $nmIds = array_values(array_unique(array_filter(array_map('intval', $nmIds), static fn(int $id): bool => $id > 0)));
  if (!$nmIds) {
    return [];
  }
  $out = [];
  foreach (array_chunk($nmIds, 500) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $st = db()->prepare("
      SELECT nm_id, vendor_code, raw_json
      FROM feedtools_wb_products
      WHERE connection_id = ?
        AND nm_id IN ({$placeholders})
    ");
    $st->execute(array_merge([$connectionId], $chunk));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $raw = json_decode((string)($row['raw_json'] ?? ''), true);
      $out[(int)($row['nm_id'] ?? 0)] = [
        'vendor_code' => trim((string)($row['vendor_code'] ?? '')),
        'product_name' => is_array($raw) ? trim((string)($raw['title'] ?? $raw['name'] ?? '')) : '',
        'brand_name' => is_array($raw) ? trim((string)($raw['brand'] ?? '')) : '',
        'subject_name' => is_array($raw) ? trim((string)($raw['subjectName'] ?? $raw['object'] ?? '')) : '',
      ];
    }
  }
  return $out;
}

function wb_analytics_parse_report_zip(
  string $zipBytes,
  int $connectionId,
  string $sellerId,
  int $opId,
  int $runId,
  string $fallbackDate,
  int $estimatedTotal,
  callable $progress,
  callable $log
): array {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('Для чтения WB analytics отчета на сервере нужен PHP extension zip.');
  }

  $tmp = tempnam(sys_get_temp_dir(), 'wb_analytics_');
  if ($tmp === false) {
    throw new RuntimeException('Не удалось создать временный файл для WB analytics.');
  }
  file_put_contents($tmp, $zipBytes);

  $zip = new ZipArchive();
  if ($zip->open($tmp) !== true) {
    @unlink($tmp);
    throw new RuntimeException('WB analytics вернул файл, который не удалось открыть как ZIP.');
  }

  $csvName = '';
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string)$zip->getNameIndex($i);
    if (preg_match('~\.csv$~i', $name)) {
      $csvName = $name;
      break;
    }
  }
  if ($csvName === '' && $zip->numFiles > 0) {
    $csvName = (string)$zip->getNameIndex(0);
  }
  $stream = $csvName !== '' ? $zip->getStream($csvName) : false;
  if (!$stream) {
    $zip->close();
    @unlink($tmp);
    throw new RuntimeException('В WB analytics архиве не найден CSV файл.');
  }

  $firstLine = fgets($stream);
  if ($firstLine === false) {
    fclose($stream);
    $zip->close();
    @unlink($tmp);
    throw new RuntimeException('CSV отчет WB analytics пустой.');
  }
  $delimiter = wb_analytics_detect_delimiter((string)$firstLine);
  $headers = str_getcsv((string)$firstLine, $delimiter);
  $headerMap = [];
  foreach ($headers as $idx => $header) {
    $key = wb_analytics_header_key((string)$header);
    if ($key !== '') {
      $headerMap[$key] = (int)$idx;
    }
  }

  $stmt = wb_analytics_make_upsert_statement();
  $now = date('Y-m-d H:i:s');
  $rowsSeen = 0;
  $rowsUpserted = 0;
  $nmBuffer = [];
  $buffer = [];

  $flush = static function () use (&$buffer, &$nmBuffer, &$rowsUpserted, $stmt, $connectionId, $sellerId, $opId, $now): void {
    if (!$buffer) {
      return;
    }
    $map = wb_analytics_nm_map($connectionId, $nmBuffer);
    foreach ($buffer as $row) {
      $nmId = (int)$row['nm_id'];
      $lookup = $map[$nmId] ?? [];
      $params = [
        ':connection_id' => $connectionId,
        ':wb_seller_id' => $sellerId,
        ':analytics_date' => $row['analytics_date'],
        ':nm_id' => $nmId,
        ':vendor_code' => trim((string)($row['vendor_code'] ?? '')) !== '' ? (string)$row['vendor_code'] : (string)($lookup['vendor_code'] ?? ''),
        ':product_name' => trim((string)($row['product_name'] ?? '')) !== '' ? (string)$row['product_name'] : (string)($lookup['product_name'] ?? ''),
        ':brand_name' => trim((string)($row['brand_name'] ?? '')) !== '' ? (string)$row['brand_name'] : (string)($lookup['brand_name'] ?? ''),
        ':subject_name' => trim((string)($row['subject_name'] ?? '')) !== '' ? (string)$row['subject_name'] : (string)($lookup['subject_name'] ?? ''),
        ':raw_json' => json_encode($row['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':last_op_id' => $opId > 0 ? $opId : null,
        ':fetched_at' => $now,
      ];
      foreach ([
        'open_card_count',
        'add_to_cart_count',
        'orders_count',
        'orders_sum_rub',
        'buyouts_count',
        'buyouts_sum_rub',
        'cancel_count',
        'cancel_sum_rub',
        'avg_price_rub',
        'avg_orders_count_per_day',
        'add_to_cart_conversion',
        'cart_to_order_conversion',
        'buyout_percent',
        'stock_mp',
        'stock_wb',
      ] as $column) {
        $params[':' . $column] = $row[$column] ?? null;
      }
      $stmt->execute($params);
      $rowsUpserted++;
    }
    $buffer = [];
    $nmBuffer = [];
  };

  while (($csvRow = fgetcsv($stream, 0, $delimiter)) !== false) {
    if (!is_array($csvRow) || count($csvRow) < 2) {
      continue;
    }
    $rowsSeen++;
    $nmId = wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['nmid', 'nomenclatureid', 'nm']));
    if (($nmId ?? 0) <= 0) {
      continue;
    }
    $date = wb_analytics_date_from_value(wb_analytics_csv_get($csvRow, $headerMap, ['dt', 'date', 'day', 'reportdate', 'period']), $fallbackDate);
    $rawFields = array_slice(array_pad($csvRow, count($headers), null), 0, count($headers));
    $buffer[] = [
      'analytics_date' => $date,
      'nm_id' => $nmId,
      'vendor_code' => trim((string)wb_analytics_csv_get($csvRow, $headerMap, ['vendorcode', 'supplierarticle', 'article', 'artikul'])),
      'product_name' => trim((string)wb_analytics_csv_get($csvRow, $headerMap, ['name', 'productname', 'title'])),
      'brand_name' => trim((string)wb_analytics_csv_get($csvRow, $headerMap, ['brand', 'brandname'])),
      'subject_name' => trim((string)wb_analytics_csv_get($csvRow, $headerMap, ['subjectname', 'subject', 'object'])),
      'open_card_count' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['opencardcount', 'opencards', 'cardviews'])),
      'add_to_cart_count' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['addtocartcount', 'addtocart'])),
      'orders_count' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['orderscount', 'orders'])),
      'orders_sum_rub' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['orderssumrub', 'orderssum', 'orderssumrur'])),
      'buyouts_count' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['buyoutscount', 'buyouts'])),
      'buyouts_sum_rub' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['buyoutssumrub', 'buyoutssum', 'buyoutssumrur'])),
      'cancel_count' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['cancelcount', 'cancellationscount', 'cancelscount'])),
      'cancel_sum_rub' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['cancelsumrub', 'cancelsum', 'cancelsumrur'])),
      'avg_price_rub' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['avgpricerub', 'avgprice'])),
      'avg_orders_count_per_day' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['avgorderscountperday', 'avgorders'])),
      'add_to_cart_conversion' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['addtocartconversion', 'conversionscart'])),
      'cart_to_order_conversion' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['carttoorderconversion', 'conversionsorder'])),
      'buyout_percent' => wb_analytics_float(wb_analytics_csv_get($csvRow, $headerMap, ['buyoutpercent', 'buyoutpercentage'])),
      'stock_mp' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['stocksmp', 'stockmp'])),
      'stock_wb' => wb_analytics_int(wb_analytics_csv_get($csvRow, $headerMap, ['stockswb', 'stockwb'])),
      'raw' => array_combine($headers, $rawFields) ?: $csvRow,
    ];
    $nmBuffer[] = $nmId;

    if (count($buffer) >= 500) {
      $flush();
      if ($rowsSeen % 2000 === 0) {
        $progress($rowsUpserted, max($estimatedTotal, $rowsUpserted + 500), 'parse', 'WB analytics: обработано строк ' . $rowsUpserted);
        db()->prepare("UPDATE feedtools_wb_analytics_sync_runs SET api_rows = ?, rows_upserted = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
          ->execute([$rowsSeen, $rowsUpserted, $runId]);
      }
    }
  }

  $flush();
  fclose($stream);
  $zip->close();
  @unlink($tmp);

  $log("parsed WB analytics CSV {$csvName}: seen={$rowsSeen}, upserted={$rowsUpserted}\n");
  return [
    'api_rows' => $rowsSeen,
    'rows_upserted' => $rowsUpserted,
  ];
}

function wb_analytics_metric_current(array $row, string $key): ?float
{
  $value = $row[$key] ?? null;
  if (is_array($value)) {
    $value = $value['current'] ?? null;
  }
  return wb_analytics_float($value);
}

function wb_analytics_search_products_from_response(array $response): array
{
  $products = $response['data']['products'] ?? $response['products'] ?? [];
  if (!is_array($products)) {
    return [];
  }
  return array_values(array_filter($products, static fn($row): bool => is_array($row)));
}

function wb_analytics_make_search_upsert_statement(): PDOStatement
{
  $columns = [
    'connection_id',
    'wb_seller_id',
    'date_from',
    'date_to',
    'nm_id',
    'vendor_code',
    'product_name',
    'subject_name',
    'search_open_card_count',
    'search_add_to_cart_count',
    'search_orders_count',
    'search_open_to_cart_conversion',
    'search_cart_to_order_conversion',
    'search_visibility',
    'search_avg_position',
    'raw_json',
    'last_op_id',
    'fetched_at',
  ];
  $updates = [
    'wb_seller_id = VALUES(wb_seller_id)',
    "vendor_code = CASE WHEN VALUES(vendor_code) <> '' THEN VALUES(vendor_code) ELSE vendor_code END",
    "product_name = CASE WHEN VALUES(product_name) <> '' THEN VALUES(product_name) ELSE product_name END",
    "subject_name = CASE WHEN VALUES(subject_name) <> '' THEN VALUES(subject_name) ELSE subject_name END",
  ];
  foreach (array_slice($columns, 8, 7) as $column) {
    $updates[] = "{$column} = COALESCE(VALUES({$column}), {$column})";
  }
  $updates[] = 'raw_json = COALESCE(VALUES(raw_json), raw_json)';
  $updates[] = 'last_op_id = VALUES(last_op_id)';
  $updates[] = 'fetched_at = VALUES(fetched_at)';
  $updates[] = 'updated_at = CURRENT_TIMESTAMP';

  return db()->prepare("
    INSERT INTO feedtools_wb_product_search_analytics_period
      (" . implode(', ', $columns) . ")
    VALUES
      (" . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ")
    ON DUPLICATE KEY UPDATE
      " . implode(",\n      ", $updates) . "
  ");
}

function wb_analytics_sync_search_report_products(
  WildberriesClient $client,
  int $connectionId,
  string $sellerId,
  int $opId,
  int $runId,
  string $dateFrom,
  string $dateTo,
  int $estimatedTotal,
  callable $progress,
  callable $log
): array {
  $stmt = wb_analytics_make_search_upsert_statement();
  $limit = 1000;
  $offset = 0;
  $rowsSeen = 0;
  $rowsUpserted = 0;
  $now = date('Y-m-d H:i:s');

  for ($page = 1; $page <= 200; $page++) {
    $progress(
      min($estimatedTotal, $rowsUpserted),
      max(1, $estimatedTotal),
      'search_report',
      'WB analytics: загружаю видимость и переходы из поиска, страница ' . $page
    );
    $response = $client->analyticsPost('/api/v2/search-report/table/details', [
      'currentPeriod' => [
        'start' => $dateFrom,
        'end' => $dateTo,
      ],
      'orderBy' => [
        'field' => 'openCard',
        'mode' => 'desc',
      ],
      'positionCluster' => 'all',
      'includeSubstitutedSKUs' => true,
      'includeSearchTexts' => true,
      'limit' => $limit,
      'offset' => $offset,
    ]);
    $products = wb_analytics_search_products_from_response($response);
    $count = count($products);
    $log("WB search report page={$page}, offset={$offset}, products={$count}\n");
    if ($count <= 0) {
      break;
    }

    foreach ($products as $product) {
      $nmId = wb_analytics_int($product['nmId'] ?? ($product['nmID'] ?? null));
      if (($nmId ?? 0) <= 0) {
        continue;
      }
      $params = [
        ':connection_id' => $connectionId,
        ':wb_seller_id' => $sellerId,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':nm_id' => $nmId,
        ':vendor_code' => trim((string)($product['vendorCode'] ?? $product['vendor_code'] ?? '')),
        ':product_name' => trim((string)($product['name'] ?? $product['productName'] ?? '')),
        ':subject_name' => trim((string)($product['subjectName'] ?? '')),
        ':search_open_card_count' => wb_analytics_int(wb_analytics_metric_current($product, 'openCard')),
        ':search_add_to_cart_count' => wb_analytics_int(wb_analytics_metric_current($product, 'addToCart')),
        ':search_orders_count' => wb_analytics_int(wb_analytics_metric_current($product, 'orders')),
        ':search_open_to_cart_conversion' => wb_analytics_metric_current($product, 'openToCart'),
        ':search_cart_to_order_conversion' => wb_analytics_metric_current($product, 'cartToOrder'),
        ':search_visibility' => wb_analytics_metric_current($product, 'visibility'),
        ':search_avg_position' => wb_analytics_metric_current($product, 'avgPosition'),
        ':raw_json' => json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':last_op_id' => $opId > 0 ? $opId : null,
        ':fetched_at' => $now,
      ];
      $stmt->execute($params);
      $rowsUpserted++;
    }
    $rowsSeen += $count;

    db()->prepare("
      UPDATE feedtools_wb_analytics_sync_runs
      SET search_api_rows = ?, search_rows_upserted = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$rowsSeen, $rowsUpserted, $runId]);

    if ($count < $limit) {
      break;
    }
    $offset += $limit;
  }

  $log("synced WB search report products: seen={$rowsSeen}, upserted={$rowsUpserted}\n");
  return [
    'api_rows' => $rowsSeen,
    'rows_upserted' => $rowsUpserted,
  ];
}

function wb_analytics_estimated_total(int $connectionId, int $daysCount): int
{
  try {
    $st = db()->prepare("SELECT COUNT(*) FROM feedtools_wb_products WHERE connection_id = ? AND is_active = 1 AND COALESCE(nm_id,0) > 0");
    $st->execute([$connectionId]);
    $products = max(1, (int)$st->fetchColumn());
    return max(1, $products * max(1, $daysCount));
  } catch (Throwable $e) {
    return max(1, $daysCount * 1000);
  }
}

function wb_analytics_sync_connection(
  array $cfg,
  int $connectionId,
  string $dateFrom,
  string $dateTo,
  int $daysCount,
  int $opId,
  callable $progress,
  callable $log,
  array $options = []
): array {
  wb_analytics_tables_ensure($cfg);
  $ctx = wb_analytics_connection_context($connectionId, $cfg);
  $connectionId = (int)$ctx['connection_id'];
  $sellerId = (string)$ctx['seller_id'];
  /** @var WildberriesClient $client */
  $client = $ctx['client'];
  $timeoutSec = max(60, (int)($options['timeout_sec'] ?? 1200));
  $estimatedTotal = wb_analytics_estimated_total($connectionId, $daysCount);

  $runSt = db()->prepare("
    INSERT INTO feedtools_wb_analytics_sync_runs
      (op_id, connection_id, wb_seller_id, date_from, date_to, days_count, status)
    VALUES
      (?, ?, ?, ?, ?, ?, 'running')
  ");
  $runSt->execute([$opId > 0 ? $opId : null, $connectionId, $sellerId, $dateFrom, $dateTo, $daysCount]);
  $runId = (int)db()->lastInsertId();

  $log("wb_analytics_sync: connection_id={$connectionId}, seller_id={$sellerId}, {$dateFrom}..{$dateTo}, days={$daysCount}\n");
  $client->setRetryLogger(static function (int $attempt, int $delaySec, string $method, string $api, string $path) use ($log): void {
    $log("WARN WB {$api} rate limit, retry {$attempt} after {$delaySec}s: {$method} {$path}\n");
  });

  try {
    $progress(0, $estimatedTotal, 'report_create', 'WB analytics: создаю отчет');
    $downloadId = wb_analytics_create_report($client, $dateFrom, $dateTo);
    db()->prepare("UPDATE feedtools_wb_analytics_sync_runs SET download_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      ->execute([$downloadId, $runId]);
    $log("created WB analytics report download_id={$downloadId}\n");

    wb_analytics_wait_report($client, $downloadId, $opId, $runId, $estimatedTotal, $progress, $log, $timeoutSec);
    $progress(0, $estimatedTotal, 'download', 'WB analytics: скачиваю отчет');
    $zipBytes = wb_analytics_download_report($client, $downloadId);
    $log('downloaded WB analytics report bytes=' . strlen($zipBytes) . "\n");

    $progress(0, $estimatedTotal, 'parse', 'WB analytics: разбираю CSV');
    $parsed = wb_analytics_parse_report_zip($zipBytes, $connectionId, $sellerId, $opId, $runId, $dateFrom, $estimatedTotal, $progress, $log);

    $progress(0, $estimatedTotal, 'search_report', 'WB analytics: загружаю видимость и переходы из поиска');
    $searchParsed = wb_analytics_sync_search_report_products(
      $client,
      $connectionId,
      $sellerId,
      $opId,
      $runId,
      $dateFrom,
      $dateTo,
      $estimatedTotal,
      $progress,
      $log
    );

    db()->prepare("
      UPDATE feedtools_wb_analytics_sync_runs
      SET status = 'done',
          api_rows = ?,
          rows_upserted = ?,
          search_api_rows = ?,
          search_rows_upserted = ?,
          finished_at = NOW(),
          updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([
      (int)$parsed['api_rows'],
      (int)$parsed['rows_upserted'],
      (int)$searchParsed['api_rows'],
      (int)$searchParsed['rows_upserted'],
      $runId,
    ]);

    return [
      'run_id' => $runId,
      'connection_id' => $connectionId,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'days_count' => $daysCount,
      'download_id' => $downloadId,
      'api_rows' => (int)$parsed['api_rows'],
      'rows_upserted' => (int)$parsed['rows_upserted'],
      'search_api_rows' => (int)$searchParsed['api_rows'],
      'search_rows_upserted' => (int)$searchParsed['rows_upserted'],
    ];
  } catch (Throwable $e) {
    db()->prepare("
      UPDATE feedtools_wb_analytics_sync_runs
      SET status = 'error', error_text = ?, finished_at = NOW(), updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$e->getMessage(), $runId]);
    throw $e;
  }
}

function wb_analytics_summary_columns_sql(string $prefix = ''): string
{
  $p = $prefix;
  return "
    SUM(COALESCE({$p}open_card_count,0)) AS open_card_count,
    SUM(COALESCE({$p}add_to_cart_count,0)) AS add_to_cart_count,
    SUM(COALESCE({$p}orders_count,0)) AS orders_count,
    SUM(COALESCE({$p}orders_sum_rub,0)) AS orders_sum_rub,
    SUM(COALESCE({$p}buyouts_count,0)) AS buyouts_count,
    SUM(COALESCE({$p}buyouts_sum_rub,0)) AS buyouts_sum_rub,
    SUM(COALESCE({$p}cancel_count,0)) AS cancel_count,
    SUM(COALESCE({$p}cancel_sum_rub,0)) AS cancel_sum_rub,
    AVG({$p}avg_price_rub) AS avg_price_rub,
    AVG({$p}add_to_cart_conversion) AS avg_add_to_cart_conversion,
    AVG({$p}cart_to_order_conversion) AS avg_cart_to_order_conversion,
    AVG({$p}buyout_percent) AS avg_buyout_percent,
    MAX(COALESCE({$p}stock_mp,0)) AS max_stock_mp,
    MAX(COALESCE({$p}stock_wb,0)) AS max_stock_wb
  ";
}

function wb_analytics_apply_conversions(array $row): array
{
  $row['cart_conversion_pct'] = ((float)($row['open_card_count'] ?? 0) > 0)
    ? ((float)($row['add_to_cart_count'] ?? 0) / (float)$row['open_card_count']) * 100
    : null;
  $row['order_conversion_pct'] = ((float)($row['open_card_count'] ?? 0) > 0)
    ? ((float)($row['orders_count'] ?? 0) / (float)$row['open_card_count']) * 100
    : null;
  $row['cart_to_order_pct'] = ((float)($row['add_to_cart_count'] ?? 0) > 0)
    ? ((float)($row['orders_count'] ?? 0) / (float)$row['add_to_cart_count']) * 100
    : null;
  return $row;
}

function wb_analytics_search_period_summary(int $connectionId, string $dateFrom, string $dateTo): array
{
  wb_analytics_tables_ensure();
  if ($connectionId <= 0) {
    return [];
  }

  $st = db()->prepare("
    SELECT
      COUNT(*) AS search_rows_count,
      COUNT(DISTINCT nm_id) AS search_nm_count,
      SUM(COALESCE(search_open_card_count,0)) AS search_open_card_count,
      SUM(COALESCE(search_add_to_cart_count,0)) AS search_add_to_cart_count,
      SUM(COALESCE(search_orders_count,0)) AS search_orders_count,
      AVG(search_visibility) AS avg_search_visibility,
      AVG(search_avg_position) AS avg_search_position
    FROM feedtools_wb_product_search_analytics_period
    WHERE connection_id = ?
      AND date_from = ?
      AND date_to = ?
  ");
  $st->execute([$connectionId, $dateFrom, $dateTo]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    return [];
  }
  $openCards = (float)($row['search_open_card_count'] ?? 0);
  $cartCount = (float)($row['search_add_to_cart_count'] ?? 0);
  $ordersCount = (float)($row['search_orders_count'] ?? 0);
  $row['search_open_to_cart_pct'] = $openCards > 0 ? ($cartCount / $openCards) * 100 : null;
  $row['search_cart_to_order_pct'] = $cartCount > 0 ? ($ordersCount / $cartCount) * 100 : null;
  return $row;
}

function wb_analytics_search_period_map_for_vendors(int $connectionId, array $vendorCodes, string $dateFrom, string $dateTo): array
{
  $vendorCodes = array_values(array_unique(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    $vendorCodes
  ), static fn(string $value): bool => $value !== '')));
  if ($connectionId <= 0 || !$vendorCodes) {
    return [];
  }

  $out = [];
  foreach (array_chunk($vendorCodes, 1000) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $st = db()->prepare("
      SELECT
        vendor_code,
        nm_id,
        search_open_card_count,
        search_add_to_cart_count,
        search_orders_count,
        search_open_to_cart_conversion,
        search_cart_to_order_conversion,
        search_visibility,
        search_avg_position
      FROM feedtools_wb_product_search_analytics_period
      WHERE connection_id = ?
        AND date_from = ?
        AND date_to = ?
        AND vendor_code IN ({$placeholders})
    ");
    $st->execute(array_merge([$connectionId, $dateFrom, $dateTo], $chunk));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $vendorCode = trim((string)($row['vendor_code'] ?? ''));
      if ($vendorCode !== '') {
        $out[$vendorCode] = $row;
      }
    }

    $missing = array_values(array_filter($chunk, static fn(string $vendorCode): bool => !isset($out[$vendorCode])));
    if ($missing) {
      $fallbackPlaceholders = implode(',', array_fill(0, count($missing), '?'));
      $latestDateTo = '';
      try {
        $latest = db()->prepare("
          SELECT MAX(date_to)
          FROM feedtools_wb_product_search_analytics_period
          WHERE connection_id = ?
            AND date_to <= ?
            AND vendor_code IN ({$fallbackPlaceholders})
        ");
        $latest->execute(array_merge([$connectionId, $dateTo], $missing));
        $latestDateTo = trim((string)($latest->fetchColumn() ?: ''));
      } catch (Throwable) {
        $latestDateTo = '';
      }

      if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $latestDateTo)) {
        $fallback = db()->prepare("
          SELECT
            vendor_code,
            nm_id,
            search_open_card_count,
            search_add_to_cart_count,
            search_orders_count,
            search_open_to_cart_conversion,
            search_cart_to_order_conversion,
            search_visibility,
            search_avg_position
          FROM feedtools_wb_product_search_analytics_period
          WHERE connection_id = ?
            AND date_to = ?
            AND vendor_code IN ({$fallbackPlaceholders})
        ");
        $fallback->execute(array_merge([$connectionId, $latestDateTo], $missing));
        while ($row = $fallback->fetch(PDO::FETCH_ASSOC)) {
          $vendorCode = trim((string)($row['vendor_code'] ?? ''));
          if ($vendorCode !== '' && !isset($out[$vendorCode])) {
            $out[$vendorCode] = $row;
          }
        }
      }
    }
  }
  return $out;
}

function wb_analytics_product_summary(int $connectionId, string $vendorCode = '', string $nmId = '', int $days = 90, ?string $dateTo = null): array
{
  wb_analytics_tables_ensure();
  $days = max(1, min(90, $days));
  $dateTo = $dateTo && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo) ? $dateTo : date('Y-m-d', strtotime('yesterday'));
  $dateFrom = date('Y-m-d', strtotime($dateTo . ' -' . ($days - 1) . ' days'));

  $where = ['connection_id = ?', 'analytics_date BETWEEN ? AND ?'];
  $args = [$connectionId, $dateFrom, $dateTo];
  if (trim($vendorCode) !== '') {
    $where[] = 'vendor_code = ?';
    $args[] = trim($vendorCode);
  }
  if (trim($nmId) !== '') {
    $where[] = 'nm_id = ?';
    $args[] = (int)$nmId;
  }
  $st = db()->prepare("SELECT COUNT(*) AS rows_count, MIN(analytics_date) AS date_from, MAX(analytics_date) AS date_to, " . wb_analytics_summary_columns_sql() . " FROM feedtools_wb_product_analytics_daily WHERE " . implode(' AND ', $where));
  $st->execute($args);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? wb_analytics_apply_conversions($row) : [];
}
