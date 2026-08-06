<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Ozon Seller API helper: получить множество offer_id (артикулов продавца),
 * которые уже существуют в кабинете Ozon.
 *
 * Используется в public/view.php для фильтра "Только отсутствующие в Ozon".
 */

function ozon_products_table_has_column(PDO $pdo, string $table, string $column): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :table_name
      AND COLUMN_NAME = :column_name
  ");
  $st->execute([
    ':table_name' => $table,
    ':column_name' => $column,
  ]);
  return ((int)$st->fetchColumn()) > 0;
}

function ozon_products_table_add_column_if_missing(PDO $pdo, string $table, string $column, string $alterSql): void
{
  if (ozon_products_table_has_column($pdo, $table, $column)) {
    return;
  }
  $pdo->exec($alterSql);
}

function ozon_products_table_character_max_length(PDO $pdo, string $table, string $column): int
{
  $st = $pdo->prepare("
    SELECT COALESCE(CHARACTER_MAXIMUM_LENGTH, 0)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :table_name
      AND COLUMN_NAME = :column_name
    LIMIT 1
  ");
  $st->execute([
    ':table_name' => $table,
    ':column_name' => $column,
  ]);
  return (int)($st->fetchColumn() ?: 0);
}

function ozon_products_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :table_name
      AND INDEX_NAME = :index_name
  ");
  $st->execute([
    ':table_name' => $table,
    ':index_name' => $indexName,
  ]);
  return ((int)$st->fetchColumn()) > 0;
}

function ozon_products_table_index_columns(PDO $pdo, string $table, string $indexName): array
{
  $st = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :table_name
      AND INDEX_NAME = :index_name
    ORDER BY SEQ_IN_INDEX ASC
  ");
  $st->execute([
    ':table_name' => $table,
    ':index_name' => $indexName,
  ]);
  return array_map(
    static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''),
    $st->fetchAll() ?: []
  );
}

function ozon_products_table_add_index_if_missing(PDO $pdo, string $table, string $indexName, string $alterSql): void
{
  if (ozon_products_table_has_index($pdo, $table, $indexName)) {
    return;
  }
  $pdo->exec($alterSql);
}

function ozon_products_table_drop_index_if_exists(PDO $pdo, string $table, string $indexName): void
{
  if (!ozon_products_table_has_index($pdo, $table, $indexName)) {
    return;
  }
  $pdo->exec(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $indexName));
}

function ozon_products_connections_table_ensure(): void
{
  static $done = false;
  if ($done) {
    return;
  }

  db()->exec("
    CREATE TABLE IF NOT EXISTS feedtools_marketplace_connections (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
      title VARCHAR(190) NOT NULL,
      client_id VARCHAR(64) NOT NULL DEFAULT '',
      api_key VARCHAR(4096) NOT NULL DEFAULT '',
      base_url VARCHAR(255) NOT NULL DEFAULT 'https://api-seller.ozon.ru',
      timeout_sec INT NOT NULL DEFAULT 30,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 100,
      notes TEXT NULL,
      created_by VARCHAR(190) NULL DEFAULT NULL,
      updated_by VARCHAR(190) NULL DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_marketplace_active (marketplace, is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  if (ozon_products_table_character_max_length(db(), 'feedtools_marketplace_connections', 'api_key') > 0
      && ozon_products_table_character_max_length(db(), 'feedtools_marketplace_connections', 'api_key') < 4096) {
    db()->exec("ALTER TABLE feedtools_marketplace_connections MODIFY api_key VARCHAR(4096) NOT NULL DEFAULT ''");
  }

  $done = true;
}

function ozon_products_connection_id_from_client_id(string $clientId): int
{
  ozon_products_connections_table_ensure();
  $clientId = trim($clientId);
  if ($clientId === '') {
    return 0;
  }

  $st = db()->prepare("
    SELECT id
    FROM feedtools_marketplace_connections
    WHERE marketplace = 'ozon' AND client_id = ?
    ORDER BY is_active DESC, sort_order ASC, id ASC
    LIMIT 1
  ");
  $st->execute([$clientId]);
  return (int)($st->fetchColumn() ?: 0);
}

function ozon_products_connection_id_from_cfg(array $cfg): int
{
  ozon_products_connections_table_ensure();
  $connectionId = (int)($cfg['price_tool_connection']['id'] ?? 0);
  if ($connectionId > 0) {
    return $connectionId;
  }

  $clientId = trim((string)(
    ($cfg['ozon']['client_id'] ?? '')
    ?: getenv('OZON_CLIENT_ID')
    ?: getenv('OZON_CLIENTID')
    ?: ''
  ));
  if ($clientId !== '') {
    $connectionId = ozon_products_connection_id_from_client_id($clientId);
    if ($connectionId > 0) {
      return $connectionId;
    }
  }

  $st = db()->query("
    SELECT id
    FROM feedtools_marketplace_connections
    WHERE marketplace = 'ozon'
    ORDER BY is_active DESC, sort_order ASC, id ASC
    LIMIT 1
  ");
  return (int)($st->fetchColumn() ?: 0);
}

function ozon_products_client_id_from_connection_id(int $connectionId): string
{
  ozon_products_connections_table_ensure();
  if ($connectionId <= 0) {
    return '';
  }

  $st = db()->prepare("
    SELECT client_id
    FROM feedtools_marketplace_connections
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$connectionId]);
  return trim((string)($st->fetchColumn() ?: ''));
}

function ozon_products_scope_from_ref(int|string|null $scopeRef = null, array $cfg = []): array
{
  ozon_products_tables_ensure($cfg);

  $connectionId = 0;
  $clientId = '';

  if (is_int($scopeRef) && $scopeRef > 0) {
    $connectionId = $scopeRef;
    $clientId = ozon_products_client_id_from_connection_id($connectionId);
  } elseif (is_string($scopeRef) && trim($scopeRef) !== '') {
    $clientId = trim($scopeRef);
    $connectionId = ozon_products_connection_id_from_client_id($clientId);
  } else {
    $connectionId = ozon_products_connection_id_from_cfg($cfg);
    $clientId = $connectionId > 0
      ? ozon_products_client_id_from_connection_id($connectionId)
      : trim((string)(
          ($cfg['ozon']['client_id'] ?? '')
          ?: getenv('OZON_CLIENT_ID')
          ?: getenv('OZON_CLIENTID')
          ?: ''
        ));
  }

  return [
    'connection_id' => $connectionId,
    'client_id' => $clientId,
  ];
}

function ozon_products_scope_clause(array $scope, string $connectionColumn = 'connection_id', string $clientColumn = 'ozon_client_id'): array
{
  $connectionId = (int)($scope['connection_id'] ?? 0);
  if ($connectionId > 0) {
    return [$connectionColumn . ' = ?', [$connectionId]];
  }

  $clientId = trim((string)($scope['client_id'] ?? ''));
  if ($clientId !== '') {
    return [$clientColumn . ' = ?', [$clientId]];
  }

  throw new RuntimeException('Не удалось определить scope подключения Ozon.');
}

function ozon_products_tables_ensure(array $cfg = []): void
{
  static $done = false;
  if ($done) {
    return;
  }

  $schemaCacheDir = dirname(__DIR__) . '/storage/cache';
  $schemaCachePath = $schemaCacheDir . '/ozon_products_schema_20260529_v4.ready';
  if (is_file($schemaCachePath) && (time() - (int)@filemtime($schemaCachePath)) < 86400) {
    $done = true;
    return;
  }

  $pdo = db();
  ozon_products_connections_table_ensure();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_ozon_products (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      ozon_client_id VARCHAR(64) NOT NULL,
      offer_id VARCHAR(80) NOT NULL,
      product_id BIGINT UNSIGNED NULL,
      sku BIGINT UNSIGNED NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      is_archived TINYINT(1) NOT NULL DEFAULT 0,
      is_autoarchived TINYINT(1) NOT NULL DEFAULT 0,
      marketplace_status VARCHAR(32) NOT NULL DEFAULT '',
      status_name VARCHAR(190) NOT NULL DEFAULT '',
      status_description TEXT NULL,
      status_failed VARCHAR(190) NOT NULL DEFAULT '',
      moderate_status VARCHAR(64) NOT NULL DEFAULT '',
      validation_status VARCHAR(64) NOT NULL DEFAULT '',
      content_rating DECIMAL(5,1) NULL,
      content_rating_recommendations_json LONGTEXT NULL,
      status_updated_at DATETIME NULL,
      last_seen_at DATETIME NULL,
      raw_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_connection_offer (connection_id, offer_id),
      KEY idx_connection_active (connection_id, is_active),
      KEY idx_connection_seen (connection_id, last_seen_at),
      KEY idx_connection_archived_offer (connection_id, is_archived, offer_id),
      KEY idx_connection_autoarchived_offer (connection_id, is_archived, is_autoarchived, offer_id),
      KEY idx_client_active (ozon_client_id, is_active),
      KEY idx_client_seen (ozon_client_id, last_seen_at),
      KEY idx_client_archived_offer (ozon_client_id, is_archived, offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'connection_id',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'is_archived',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'is_autoarchived',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN is_autoarchived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'marketplace_status',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN marketplace_status VARCHAR(32) NOT NULL DEFAULT '' AFTER is_autoarchived"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'status_name',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN status_name VARCHAR(190) NOT NULL DEFAULT '' AFTER marketplace_status"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'status_description',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN status_description TEXT NULL AFTER status_name"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'status_failed',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN status_failed VARCHAR(190) NOT NULL DEFAULT '' AFTER status_description"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'moderate_status',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN moderate_status VARCHAR(64) NOT NULL DEFAULT '' AFTER status_failed"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'validation_status',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN validation_status VARCHAR(64) NOT NULL DEFAULT '' AFTER moderate_status"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'content_rating',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN content_rating DECIMAL(5,1) NULL AFTER validation_status"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'content_rating_recommendations_json',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN content_rating_recommendations_json LONGTEXT NULL AFTER content_rating"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'status_updated_at',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN status_updated_at DATETIME NULL AFTER content_rating_recommendations_json"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'raw_json',
    "ALTER TABLE feedtools_ozon_products ADD COLUMN raw_json LONGTEXT NULL AFTER last_seen_at"
  );

  $pdo->exec("
    UPDATE feedtools_ozon_products
    SET marketplace_status = CASE
      WHEN is_archived = 1 AND is_autoarchived = 1 THEN 'auto_archived'
      WHEN is_archived = 1 THEN 'archived'
      WHEN is_active = 1 THEN 'ready'
      ELSE 'not_created'
    END
    WHERE marketplace_status = ''
  ");

  $pdo->exec("
    UPDATE feedtools_ozon_products
    SET
      is_autoarchived = 1,
      marketplace_status = CASE
        WHEN COALESCE(marketplace_status, '') IN ('', 'archived') THEN 'auto_archived'
        ELSE marketplace_status
      END,
      status_name = CASE
        WHEN COALESCE(status_name, '') IN ('', 'В архиве') THEN 'В автоархиве'
        ELSE status_name
      END
    WHERE is_archived = 1
      AND is_autoarchived = 0
      AND (
        LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%auto%archiv%'
        OR LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%archiv%auto%'
        OR LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%авто%архив%'
        OR LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%архив%авто%'
        OR LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%автоматическ%архив%'
        OR LOWER(REPLACE(CONCAT_WS(' ', marketplace_status, status_name, status_description, status_failed, raw_json), 'ё', 'е')) LIKE '%архив%автоматическ%'
      )
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_ozon_sync_state (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      ozon_client_id VARCHAR(64) NOT NULL,
      state_key VARCHAR(64) NOT NULL,
      state_value TEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_connection_key (connection_id, state_key),
      KEY idx_client_key (ozon_client_id, state_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_ozon_sync_state',
    'connection_id',
    "ALTER TABLE feedtools_ozon_sync_state ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
  );

  $pdo->exec("
    UPDATE feedtools_ozon_products p
    JOIN (
      SELECT client_id, MIN(id) AS connection_id
      FROM feedtools_marketplace_connections
      WHERE marketplace = 'ozon' AND client_id <> ''
      GROUP BY client_id
    ) c ON BINARY c.client_id = BINARY p.ozon_client_id
    SET p.connection_id = c.connection_id
    WHERE p.connection_id = 0
  ");

  $pdo->exec("
    UPDATE feedtools_ozon_sync_state s
    JOIN (
      SELECT client_id, MIN(id) AS connection_id
      FROM feedtools_marketplace_connections
      WHERE marketplace = 'ozon' AND client_id <> ''
      GROUP BY client_id
    ) c ON BINARY c.client_id = BINARY s.ozon_client_id
    SET s.connection_id = c.connection_id
    WHERE s.connection_id = 0
  ");

  $fallbackConnectionId = ozon_products_connection_id_from_cfg($cfg);
  if ($fallbackConnectionId > 0) {
    $st = $pdo->prepare("UPDATE feedtools_ozon_products SET connection_id = ? WHERE connection_id = 0");
    $st->execute([$fallbackConnectionId]);
    $st = $pdo->prepare("UPDATE feedtools_ozon_sync_state SET connection_id = ? WHERE connection_id = 0");
    $st->execute([$fallbackConnectionId]);
  }

  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'uq_connection_offer',
    "ALTER TABLE feedtools_ozon_products ADD UNIQUE KEY uq_connection_offer (connection_id, offer_id)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'idx_connection_active',
    "ALTER TABLE feedtools_ozon_products ADD KEY idx_connection_active (connection_id, is_active)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'idx_connection_seen',
    "ALTER TABLE feedtools_ozon_products ADD KEY idx_connection_seen (connection_id, last_seen_at)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'idx_connection_archived_offer',
    "ALTER TABLE feedtools_ozon_products ADD KEY idx_connection_archived_offer (connection_id, is_archived, offer_id)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'idx_connection_autoarchived_offer',
    "ALTER TABLE feedtools_ozon_products ADD KEY idx_connection_autoarchived_offer (connection_id, is_archived, is_autoarchived, offer_id)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_products',
    'idx_connection_status_offer',
    "ALTER TABLE feedtools_ozon_products ADD KEY idx_connection_status_offer (connection_id, marketplace_status, offer_id)"
  );
  ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_products', 'uq_client_offer');

  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_sync_state',
    'uq_connection_key',
    "ALTER TABLE feedtools_ozon_sync_state ADD UNIQUE KEY uq_connection_key (connection_id, state_key)"
  );
  ozon_products_table_add_index_if_missing(
    $pdo,
    'feedtools_ozon_sync_state',
    'idx_client_key',
    "ALTER TABLE feedtools_ozon_sync_state ADD KEY idx_client_key (ozon_client_id, state_key)"
  );
  ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_sync_state', 'uq_client_key');

  if (!is_dir($schemaCacheDir)) {
    @mkdir($schemaCacheDir, 0775, true);
  }
  @touch($schemaCachePath);

  $done = true;
}

function ozon_products_boolish($value, bool $default = false): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_int($value) || is_float($value)) {
    return ((int)$value) !== 0;
  }
  if (is_string($value)) {
    $value = trim($value);
    if ($value === '') {
      return $default;
    }
    $lower = str_replace('ё', 'е', ozon_products_lower($value));
    if (in_array($lower, ['1', 'true', 'yes', 'y', 'on', 'да', 'истина'], true)) {
      return true;
    }
    if (in_array($lower, ['0', 'false', 'no', 'n', 'off', 'нет', 'ложь'], true)) {
      return false;
    }
    if (preg_match('~(?:auto[a-z0-9_\-\s]{0,30}archiv|авто[\p{L}\p{N}_\-\s]{0,30}архив)~iu', $lower)) {
      return true;
    }
  }
  return $default;
}

function ozon_products_marker_key_normalize(string $key): string
{
  $lower = str_replace('ё', 'е', ozon_products_lower($key));
  return (string)preg_replace('~[^\p{L}\p{N}]+~u', '', $lower);
}

function ozon_products_nested_value_by_keys($value, array $keys, bool &$found)
{
  $found = false;
  if (!is_array($value)) {
    return null;
  }
  $keySet = [];
  foreach ($keys as $key) {
    $keySet[ozon_products_marker_key_normalize((string)$key)] = true;
  }
  foreach ($value as $key => $nested) {
    if (is_string($key) && isset($keySet[ozon_products_marker_key_normalize($key)])) {
      $found = true;
      return $nested;
    }
    if (is_array($nested)) {
      $nestedValue = ozon_products_nested_value_by_keys($nested, $keys, $found);
      if ($found) {
        return $nestedValue;
      }
    }
  }
  return null;
}

function ozon_products_flatten_text($value, array &$parts, int $depth = 0): void
{
  if ($depth > 5 || count($parts) > 500) {
    return;
  }
  if (is_scalar($value)) {
    $text = trim((string)$value);
    if ($text !== '') {
      $parts[] = $text;
    }
    return;
  }
  if (!is_array($value)) {
    return;
  }
  foreach ($value as $nested) {
    ozon_products_flatten_text($nested, $parts, $depth + 1);
    if (count($parts) > 500) {
      return;
    }
  }
}

function ozon_products_autoarchive_marker(array $row): bool
{
  $explicitKeys = [
    'is_autoarchived',
    'is_auto_archived',
    'isAutoArchived',
    'autoarchived',
    'auto_archived',
    'autoArchived',
  ];
  $foundExplicit = false;
  $explicit = ozon_products_nested_value_by_keys($row, $explicitKeys, $foundExplicit);
  if ($foundExplicit && ozon_products_boolish($explicit, false)) {
    return true;
  }

  $raw = null;
  if (isset($row['raw_json']) && is_string($row['raw_json']) && trim($row['raw_json']) !== '') {
    $decoded = json_decode((string)$row['raw_json'], true);
    if (is_array($decoded)) {
      $raw = $decoded;
      $foundNestedExplicit = false;
      $nestedExplicit = ozon_products_nested_value_by_keys($decoded, $explicitKeys, $foundNestedExplicit);
      if ($foundNestedExplicit) {
        return ozon_products_boolish($nestedExplicit, false);
      }
    }
  }

  $parts = [
    (string)($row['status_name'] ?? ''),
    (string)($row['status_description'] ?? ''),
    (string)($row['status_failed'] ?? ''),
    (string)($row['marketplace_status'] ?? ''),
  ];
  if (is_array($raw)) {
    ozon_products_flatten_text($raw, $parts);
  } else {
    ozon_products_flatten_text($row, $parts);
  }
  $text = str_replace('ё', 'е', ozon_products_lower(implode(' ', array_filter($parts, static fn($v): bool => trim((string)$v) !== ''))));
  if ($text === '') {
    return false;
  }
  return preg_match('~(?:auto[a-z0-9_\-\s]{0,30}archiv|archiv[a-z0-9_\-\s]{0,30}auto|авто[\p{L}\p{N}_\-\s]{0,30}архив|архив[\p{L}\p{N}_\-\s]{0,30}авто|автоматическ[\p{L}\p{N}_\-\s]{0,60}архив|архив[\p{L}\p{N}_\-\s]{0,60}автоматическ)~iu', $text) === 1;
}

function ozon_products_marketplace_status_from_list(string $visibility, bool $archived, bool $autoArchived = false): string
{
  if ($autoArchived) {
    return 'auto_archived';
  }
  if ($archived || strtoupper(trim($visibility)) === 'ARCHIVED') {
    return 'archived';
  }
  return 'ready';
}

function ozon_products_lower(string $value): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ozon_products_contains_any(string $haystack, array $needles): bool
{
  foreach ($needles as $needle) {
    $needle = (string)$needle;
    if ($needle !== '' && str_contains($haystack, $needle)) {
      return true;
    }
  }
  return false;
}

function ozon_products_content_rating_attribute_name_from_row(array $row): string
{
  foreach (['attribute_name', 'attributeName', 'characteristic_name', 'characteristicName', 'field_name', 'fieldName'] as $key) {
    if (!empty($row[$key]) && is_scalar($row[$key])) {
      return trim((string)$row[$key]);
    }
  }

  $hasAttributeId = false;
  foreach (['id', 'attribute_id', 'attributeId', 'characteristic_id', 'characteristicId'] as $key) {
    if (array_key_exists($key, $row) && is_numeric($row[$key])) {
      $hasAttributeId = true;
      break;
    }
  }
  if ($hasAttributeId && !empty($row['name']) && is_scalar($row['name'])) {
    return trim((string)$row['name']);
  }

  return '';
}

function ozon_products_content_rating_text_is_attribute(string $text, string $fallbackType = ''): bool
{
  $lower = ozon_products_lower(trim($text . ' ' . $fallbackType));
  if ($lower === '') {
    return false;
  }
  if (ozon_products_contains_any($lower, ['характерист', 'атрибут', 'параметр', 'attribute', 'attributes', 'characteristic', 'parameter', 'param', 'spec'])) {
    return true;
  }

  $value = trim(preg_replace('~\s+~u', ' ', ozon_products_lower($text)) ?? ozon_products_lower($text));
  if ($value === '' || in_array($value, ['название', 'наименование', 'название товара', 'наименование товара', 'title', 'name'], true)) {
    return false;
  }

  if (preg_match('~^назван(?:ие|ия)\s+(?:цвета|модел[ьи]|серии|комплекта|оттенка|размера)\b~u', $value)) {
    return true;
  }
  if (preg_match('~^(?:цвет|цвет товара|основной цвет|подходит для|для чего подходит|совместим(?:ость|ые модели)|модель|модель товара|тип|вид|форма|материал|комплектация|партномер|артикул производителя|гарантия|гарантийный срок|срок службы|страна(?:-| )изготовитель|страна производства|количество|число|объем|ёмкость|емкость|напряжение|мощность|вес|длина|ширина|высота|размер|габарит|диаметр|код тн|тн вэд|тнвэд)\b~u', $value)) {
    return true;
  }

  return false;
}

function ozon_products_truncate(string $value, int $limit): string
{
  $value = trim($value);
  if ($limit <= 0) {
    return '';
  }
  return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function ozon_products_marketplace_status_from_info(array $item, bool $archived = false): string
{
  $autoArchived = ozon_products_autoarchive_marker($item);
  if ($autoArchived) {
    return 'auto_archived';
  }
  if ($archived || !empty($item['is_archived']) || !empty($item['archived'])) {
    return 'archived';
  }

  $statuses = $item['statuses'] ?? [];
  if (!is_array($statuses)) {
    $statuses = [];
  }

  $status = ozon_products_lower(trim((string)($statuses['status'] ?? '')));
  $statusFailed = ozon_products_lower(trim((string)($statuses['status_failed'] ?? '')));
  $moderate = ozon_products_lower(trim((string)($statuses['moderate_status'] ?? '')));
  $validation = ozon_products_lower(trim((string)($statuses['validation_status'] ?? '')));
  $statusName = trim((string)($statuses['status_name'] ?? ''));
  $statusDescription = trim((string)($statuses['status_description'] ?? ''));
  $statusTooltip = trim((string)($statuses['status_tooltip'] ?? ''));
  $text = ozon_products_lower($statusName . ' ' . $statusDescription . ' ' . $statusTooltip);
  $errors = $item['errors'] ?? [];
  $hasErrors = is_array($errors) && count($errors) > 0;
  $isCreated = array_key_exists('is_created', $statuses) ? (bool)$statuses['is_created'] : true;

  if (ozon_products_contains_any($text, ['архив'])) {
    return $autoArchived ? 'auto_archived' : 'archived';
  }
  if (!$isCreated || ozon_products_contains_any($text, ['не создан'])) {
    return 'error';
  }
  if ($hasErrors && in_array('ошибка объединения', ozon_products_error_labels($item), true)) {
    return 'error';
  }
  if (
    $moderate === 'declined'
    || $status === 'declined'
    || ozon_products_contains_any($validation, ['fail', 'error'])
    || ozon_products_contains_any($text, ['доработ', 'не прош', 'отклон', 'модерац'])
  ) {
    return 'revision';
  }
  if (
    $statusFailed !== ''
    || $hasErrors
    || ozon_products_contains_any($text, ['ошиб', 'не удалось', 'не обнов', 'не загруж'])
  ) {
    return 'revision';
  }
  if (
    ozon_products_contains_any($text, ['готов к продаже'])
    || ($moderate === 'approved' && $validation === 'success')
    || $status === 'price_sent'
  ) {
    return 'ready';
  }
  if (
    ozon_products_contains_any($validation, ['pending'])
    || ozon_products_contains_any($moderate, ['pending', 'new'])
    || in_array($status, ['imported', 'moderated'], true)
  ) {
    return 'revision';
  }

  return 'ready';
}

function ozon_products_error_label_from_parts(string $code, string $attribute, string $description, string $message): string
{
  $haystack = ozon_products_lower($code . ' ' . $attribute . ' ' . $description . ' ' . $message);
  $attributeLower = ozon_products_lower(trim($attribute));
  $isEmpty = ozon_products_contains_any($haystack, ['empty', 'обязательное поле', 'заполните поле', 'не заполн']);

  if (ozon_products_contains_any($haystack, ['не удалось объедин', 'объединить в похожие товары', 'объединить с другими товарами', 'похожие товары', 'merge', 'similar products'])) {
    return 'ошибка объединения';
  }
  if (ozon_products_contains_any($haystack, ['дубл', 'duplicate', 'уже существует', 'already exists'])) {
    return 'дубль товара';
  }
  if (in_array($attributeLower, ['тип', 'type'], true)) {
    return $isEmpty ? 'нет категории' : 'ошибка категории';
  }
  if (in_array($attributeLower, ['название', 'name', 'title'], true)) {
    return $isEmpty ? 'нет названия' : 'ошибка в названии';
  }
  if (ozon_products_contains_any($haystack, ['тн вэд', 'тнвэд', 'tnved', 'tn ved'])) {
    return $isEmpty ? 'нет кода ТН ВЭД' : 'ошибка кода ТН ВЭД';
  }
  if (ozon_products_contains_any($haystack, ['фото', 'изображ', 'image', 'picture'])) {
    return $isEmpty ? 'нет фото' : 'ошибка фото';
  }
  if (ozon_products_contains_any($haystack, ['бренд', 'brand', 'vendor'])) {
    return $isEmpty ? 'нет бренда' : 'ошибка бренда';
  }
  if (ozon_products_contains_any($haystack, ['штрихкод', 'barcode', 'bar code'])) {
    return $isEmpty ? 'нет штрихкода' : 'ошибка штрихкода';
  }
  if (ozon_products_contains_any($haystack, ['категор', 'category', 'type_id', 'description_category'])) {
    return $isEmpty ? 'нет категории' : 'ошибка категории';
  }
  if (ozon_products_contains_any($haystack, ['цена', 'price'])) {
    return $isEmpty ? 'нет цены' : 'ошибка цены';
  }
  if (ozon_products_contains_any($haystack, ['габарит', 'размер', 'dimension', 'height', 'width', 'length', 'weight'])) {
    return $isEmpty ? 'нет габаритов' : 'ошибка габаритов';
  }
  if (ozon_products_contains_any($haystack, ['название модели', 'model'])) {
    return $isEmpty ? 'нет модели' : 'ошибка модели';
  }

  $attribute = trim($attribute);
  if ($attribute !== '') {
    return $isEmpty ? ('нет: ' . $attribute) : ('ошибка характеристики: ' . $attribute);
  }

  $description = trim($description);
  if ($description !== '') {
    return ozon_products_truncate($description, 120);
  }

  $message = trim($message);
  return $message !== '' ? ozon_products_truncate($message, 120) : 'ошибка карточки';
}

function ozon_products_error_labels(array $item): array
{
  $errors = $item['errors'] ?? [];
  if (!is_array($errors) || !$errors) {
    return [];
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
    $code = trim((string)($error['code'] ?? ''));
    $attribute = trim((string)($texts['attribute_name'] ?? ''));
    $description = trim((string)($texts['description'] ?? ''));
    $message = trim((string)($texts['message'] ?? ($error['code'] ?? '')));
    $label = ozon_products_error_label_from_parts($code, $attribute, $description, $message);
    if ($label !== '') {
      $labels[] = $label;
    }
    if (count($labels) >= 8) {
      break;
    }
  }
  return array_values(array_unique($labels));
}

function ozon_products_error_summary(array $item): string
{
  return ozon_products_truncate(implode("\n", ozon_products_error_labels($item)), 500);
}

function ozon_products_datetime_from_api($value): ?string
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

function ozon_products_content_rating_number($value): ?float
{
  if (is_string($value)) {
    $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
    $value = str_replace(',', '.', $value);
    if ($value !== '' && !is_numeric($value) && preg_match('~[-+]?\d+(?:\.\d+)?~', $value, $m)) {
      $value = $m[0];
    }
  }
  if (!is_numeric($value)) {
    return null;
  }
  return max(0.0, min(100.0, round((float)$value, 1)));
}

function ozon_products_content_rating_from_raw($raw, bool $allowPlainRating = false): ?float
{
  if (is_string($raw)) {
    $raw = trim($raw);
    if ($raw === '') {
      return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return null;
    }
    $raw = $decoded;
  }
  if (!is_array($raw)) {
    return null;
  }

  $contentKeys = [
    'content_rating',
    'contentRating',
    'content_rating_value',
    'contentRatingValue',
    'content_score',
    'contentScore',
  ];
  foreach ($contentKeys as $key) {
    if (array_key_exists($key, $raw)) {
      $rating = ozon_products_content_rating_number($raw[$key]);
      if ($rating !== null) {
        return $rating;
      }
    }
  }

  if ($allowPlainRating && array_key_exists('rating', $raw)) {
    $rating = ozon_products_content_rating_number($raw['rating']);
    if ($rating !== null) {
      return $rating;
    }
  }

  foreach ($raw as $value) {
    if (is_array($value)) {
      $rating = ozon_products_content_rating_from_raw($value, false);
      if ($rating !== null) {
        return $rating;
      }
    }
  }

  return null;
}

function ozon_products_content_rating_recommendation_type(string $text, string $fallbackType = ''): array
{
  $lower = ozon_products_lower($text . ' ' . $fallbackType);
  if (ozon_products_contains_any($lower, ['rich', 'рич', 'rich_content', 'richcontent'])) {
    return ['rich', 'Rich контент', 'rich контент'];
  }
  if (ozon_products_contains_any($lower, ['видео', 'video', 'videocover', 'video_cover', 'cover_video'])) {
    return ['video', 'Видео', 'видео'];
  }
  if (ozon_products_contains_any($lower, ['фото', 'изображ', 'картин', 'photo', 'image', 'picture', 'media', 'cover'])) {
    return ['photo', 'Фото', 'фото'];
  }
  if (ozon_products_content_rating_text_is_attribute($text, $fallbackType)) {
    return ['characteristics', 'Характеристики', 'характеристики'];
  }
  if (ozon_products_contains_any($lower, ['назван', 'наимен', 'title', 'name'])) {
    return ['name', 'Название', 'название'];
  }
  if (ozon_products_contains_any($lower, ['описан', 'аннотац', 'description', 'annotation'])) {
    return ['description', 'Описание', 'описание'];
  }
  if (ozon_products_contains_any($lower, ['бренд', 'brand'])) {
    return ['brand', 'Бренд', 'бренд'];
  }
  if (ozon_products_contains_any($lower, ['цена', 'price'])) {
    return ['price', 'Цена', 'цена'];
  }
  return ['content', 'Контент', 'контент'];
}

function ozon_products_content_rating_recommendation_short(string $text, string $fallback = ''): string
{
  $fallback = trim($fallback);
  $lower = ozon_products_lower($text . ' ' . $fallback);
  if (ozon_products_contains_any($lower, ['missing', 'empty', 'нет ', 'отсутств', 'не заполн', 'заполн', 'добав'])) {
    return 'заполнить';
  }
  if (ozon_products_contains_any($lower, ['error', 'invalid', 'ошиб', 'некоррект', 'исправ', 'проверь'])) {
    return 'проверить';
  }
  if ($fallback !== '') {
    return ozon_products_truncate($fallback, 32);
  }
  return 'улучшить';
}

function ozon_products_content_rating_recommendation_text_from_row(array $row): string
{
  $textKeys = [
    'text',
    'message',
    'recommendation',
    'recommendations',
    'improvement',
    'advice',
    'title',
    'name',
    'label',
    'hint',
    'description',
    'reason',
    'comment',
    'field',
    'field_name',
    'attribute',
    'attribute_name',
    'characteristic',
    'criterion',
    'criteria',
    'factor',
    'section',
    'code',
    'type',
  ];
  $parts = [];
  foreach ($textKeys as $key) {
    if (!array_key_exists($key, $row)) {
      continue;
    }
    $value = $row[$key];
    if (is_scalar($value)) {
      $value = trim((string)$value);
      if ($value !== '' && !is_numeric($value)) {
        $parts[$value] = true;
      }
    } elseif (is_array($value)) {
      foreach (['text', 'message', 'title', 'name', 'label', 'hint', 'description'] as $nestedKey) {
        if (!empty($value[$nestedKey]) && is_scalar($value[$nestedKey])) {
          $nestedValue = trim((string)$value[$nestedKey]);
          if ($nestedValue !== '' && !is_numeric($nestedValue)) {
            $parts[$nestedValue] = true;
          }
        }
      }
    }
  }
  return trim(implode(' ', array_keys($parts)));
}

function ozon_products_content_rating_recommendation(array $row): ?array
{
  $attributeName = ozon_products_content_rating_attribute_name_from_row($row);
  if ($attributeName !== '') {
    return [
      'type' => 'characteristics',
      'label' => 'Характеристики',
      'short' => ozon_products_content_rating_recommendation_short($attributeName, 'характеристики'),
      'text' => $attributeName,
      'comment' => '',
    ];
  }

  $text = ozon_products_content_rating_recommendation_text_from_row($row);
  if ($text === '') {
    return null;
  }
  $fallbackType = trim((string)($row['type'] ?? ($row['code'] ?? ($row['field'] ?? ($row['attribute'] ?? '')))));
  [$type, $label, $labelShort] = ozon_products_content_rating_recommendation_type($text, $fallbackType);
  if ($type === 'content') {
    $lower = ozon_products_lower($text . ' ' . $fallbackType);
    if (!ozon_products_contains_any($lower, ['recommend', 'improve', 'missing', 'ошиб', 'улучш', 'заполн', 'добав', 'проверь', 'отсутств'])) {
      return null;
    }
  }
  return [
    'type' => $type,
    'label' => $label,
    'short' => ozon_products_content_rating_recommendation_short($text, $labelShort),
    'text' => $text,
    'comment' => '',
  ];
}

function ozon_products_content_rating_recommendation_container_key(string $key): bool
{
  $key = ozon_products_lower($key);
  return ozon_products_contains_any($key, [
    'recommend',
    'improvement',
    'improve',
    'advice',
    'hint',
    'tips',
    'quality',
    'rating',
    'content_rating',
    'contentrating',
    'criterion',
    'criteria',
    'check',
    'factor',
    'missing',
    'penalty',
  ]);
}

function ozon_products_content_rating_collect_recommendations($value, array &$out, bool $active = false, int $depth = 0): void
{
  if ($depth > 7 || !is_array($value)) {
    return;
  }

  $keys = array_keys($value);
  $isList = $keys === range(0, count($value) - 1);
  if (!$isList && $active) {
    $rec = ozon_products_content_rating_recommendation($value);
    if ($rec !== null) {
      $out[] = $rec;
    }
  }

  foreach ($value as $key => $child) {
    if (!is_array($child)) {
      continue;
    }
    $childActive = $active || ozon_products_content_rating_recommendation_container_key((string)$key);
    if ($isList && $active && is_array($child)) {
      $rec = ozon_products_content_rating_recommendation($child);
      if ($rec !== null) {
        $out[] = $rec;
      }
    }
    ozon_products_content_rating_collect_recommendations($child, $out, $childActive, $depth + 1);
  }
}

function ozon_products_content_rating_deduplicate_recommendations(array $recommendations): array
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
    [$normalizedType, $normalizedLabel, $normalizedShort] = ozon_products_content_rating_recommendation_type($text, $type);
    if ($normalizedType !== 'content' && ($normalizedType !== $type || $label === '' || $label === 'Контент')) {
      $type = $normalizedType;
      $label = $normalizedLabel;
      if ($short === '' || in_array(ozon_products_lower($short), ['название', 'name', 'title', 'контент', 'улучшить'], true)) {
        $short = $normalizedShort;
      }
    }
    $key = ozon_products_lower($type . '|' . $short . '|' . $text);
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

function ozon_products_content_rating_recommendations_from_raw($raw): array
{
  if (is_string($raw)) {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return [];
    }
    $raw = $decoded;
  }
  if (!is_array($raw)) {
    return [];
  }

  $out = [];
  ozon_products_content_rating_collect_recommendations($raw, $out, false, 0);
  return ozon_products_content_rating_deduplicate_recommendations($out);
}

function ozon_products_content_rating_response_items(array $resp): array
{
  $candidates = [
    $resp['products'] ?? null,
    $resp['items'] ?? null,
    $resp['result']['products'] ?? null,
    $resp['result']['items'] ?? null,
    $resp['result'] ?? null,
  ];
  foreach ($candidates as $items) {
    if (!is_array($items)) {
      continue;
    }
    if (!$items) {
      return [];
    }
    $keys = array_keys($items);
    if ($keys === range(0, count($items) - 1)) {
      return $items;
    }
  }
  return [];
}

function ozon_products_content_rating_payload_by_sku(array $oz, array $skus, ?callable $log = null): array
{
  $skus = array_values(array_unique(array_filter(array_map(
    static fn($value): int => is_numeric($value) ? (int)$value : 0,
    $skus
  ), static fn(int $value): bool => $value > 0)));
  if (!$skus) {
    return [];
  }

  $out = [];
  foreach (array_chunk($skus, 100) as $chunk) {
    try {
      $resp = ozon_post_json($oz, '/v1/product/rating-by-sku', [
        'skus' => array_values($chunk),
      ]);
    } catch (Throwable $e) {
      if ($log) {
        $log('Ozon content rating error: ' . $e->getMessage() . "\n");
      }
      continue;
    }

    foreach (ozon_products_content_rating_response_items($resp) as $item) {
      if (!is_array($item)) {
        continue;
      }
      $sku = isset($item['sku']) && is_numeric($item['sku']) ? (string)(int)$item['sku'] : '';
      if ($sku === '') {
        continue;
      }
      $rating = ozon_products_content_rating_from_raw($item, true);
      $recommendations = ozon_products_content_rating_recommendations_from_raw($item);
      if ($rating !== null || $recommendations) {
        $out[$sku] = [
          'rating' => $rating,
          'recommendations' => $recommendations,
        ];
      }
    }
  }

  return $out;
}

function ozon_products_content_rating_by_sku(array $oz, array $skus, ?callable $log = null): array
{
  $payloads = ozon_products_content_rating_payload_by_sku($oz, $skus, $log);
  $out = [];
  foreach ($payloads as $sku => $payload) {
    $rating = is_array($payload) ? ($payload['rating'] ?? null) : null;
    if ($rating !== null) {
      $out[(string)$sku] = $rating;
    }
  }
  return $out;
}

function ozon_products_refresh_status_details(array $oz, int $connectionId, string $clientId, array $productIds, int $opId = 0, ?callable $log = null): array
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
      $marketplaceStatus = ozon_products_marketplace_status_from_info($item);
      $isAutoArchived = ozon_products_autoarchive_marker($item) ? 1 : 0;
      $isArchived = (!empty($item['is_archived']) || !empty($item['archived']) || in_array($marketplaceStatus, ['archived', 'auto_archived'], true)) ? 1 : 0;
      $statusDescription = trim((string)($statuses['status_description'] ?? ''));
      $errorSummary = ozon_products_error_summary($item);
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
        ':status_name' => ozon_products_truncate((string)($statuses['status_name'] ?? ''), 190),
        ':status_description' => $statusDescription,
        ':status_failed' => ozon_products_truncate((string)($statuses['status_failed'] ?? ''), 190),
        ':moderate_status' => ozon_products_truncate((string)($statuses['moderate_status'] ?? ''), 64),
        ':validation_status' => ozon_products_truncate((string)($statuses['validation_status'] ?? ''), 64),
        ':content_rating' => ozon_products_content_rating_from_raw($item, false),
        ':content_rating_recommendations_json' => is_string($contentRatingRecommendationsJson) ? $contentRatingRecommendationsJson : null,
        ':status_updated_at' => ozon_products_datetime_from_api($statuses['status_updated_at'] ?? null),
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

function ozon_cfg_or_fail(array $cfg): array
{
  ozon_products_tables_ensure($cfg);

  $oz = $cfg['ozon'] ?? [];
  if (!is_array($oz)) $oz = [];
  $oz += [
    'client_id' => '',
    'api_key' => '',
    'base_url' => 'https://api-seller.ozon.ru',
    'timeout_sec' => 30,
  ];
// fallback to ENV
if (trim((string)$oz['client_id']) === '') {
  $oz['client_id'] = getenv('OZON_CLIENT_ID') ?: getenv('OZON_CLIENTID') ?: '';
}
if (trim((string)$oz['api_key']) === '') {
  $oz['api_key'] = getenv('OZON_API_KEY') ?: getenv('OZON_APIKEY') ?: '';
}

  if (trim((string)$oz['client_id']) === '' || trim((string)$oz['api_key']) === '') {
    throw new RuntimeException('Ozon API не настроен: задайте ozon.client_id и ozon.api_key (app/config.local.php или ENV)');
  }
  return $oz;
}

function ozon_post_json(array $oz, string $path, $payload): array
{
  $url = rtrim((string)$oz['base_url'], '/') . $path;

  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (!is_string($body)) {
    throw new RuntimeException('Не удалось сериализовать payload для Ozon.');
  }

  $maxAttempts = 3;
  $attempt = 0;
  $timeout = max(5, (int)($oz['timeout_sec'] ?? 30));
  $connectTimeout = max(3, min(10, (int)($oz['connect_timeout_sec'] ?? 5)));

  while (true) {
    $attempt++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Client-Id: ' . (string)$oz['client_id'],
        'Api-Key: ' . (string)$oz['api_key'],
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_CONNECTTIMEOUT => $connectTimeout,
      CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
      curl_close($ch);
    }

    $transientHttp = in_array($http, [408, 429, 500, 502, 503, 504], true);
    $hasCurlError = $raw === false || $curlErr !== '';
    if (($hasCurlError || $transientHttp) && $attempt < $maxAttempts) {
      usleep($attempt === 1 ? 300000 : 900000);
      continue;
    }

    if ($raw === false) {
      throw new RuntimeException('Ozon request failed: ' . ($curlErr ?: 'curl error'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
      $snippet = trim(substr((string)$raw, 0, 200));
      throw new RuntimeException('Ozon вернул не-JSON (HTTP ' . $http . ') URL=' . $url . ' BODY=' . $snippet);
    }

    if ($http >= 400) {
      $msg = $data['message'] ?? ($data['error']['message'] ?? 'HTTP error');
      $extra = [];
      if (isset($data['code'])) {
        $extra[] = 'code=' . (string)$data['code'];
      }
      foreach (['details', 'errors', 'error'] as $key) {
        if (!empty($data[$key])) {
          $encoded = json_encode($data[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if (is_string($encoded) && $encoded !== '') {
            $extra[] = $key . '=' . substr($encoded, 0, 500);
          }
        }
      }
      $hasBodyContext = false;
      foreach ($extra as $part) {
        if (str_starts_with((string)$part, 'details=') || str_starts_with((string)$part, 'errors=') || str_starts_with((string)$part, 'error=')) {
          $hasBodyContext = true;
          break;
        }
      }
      $snippet = trim(substr((string)$raw, 0, 500));
      if ($snippet !== '' && (!$hasBodyContext || strtoupper((string)$msg) === 'VALIDATION ERROR')) {
        $extra[] = 'body=' . $snippet;
      }
      throw new RuntimeException('Ozon HTTP ' . $http . ': ' . $msg . ($extra ? ' (' . implode('; ', $extra) . ')' : ''));
    }

    return $data;
  }
}

/**
 * Извлечь offer_id из разных вариантов структуры ответа.
 */
function ozon_extract_offer_ids_from_result($result): array
{
  $ids = [];

  $items = null;
  if (is_array($result)) {
    if (isset($result['items']) && is_array($result['items'])) $items = $result['items'];
    elseif (isset($result['products']) && is_array($result['products'])) $items = $result['products'];
    elseif (isset($result['result']) && is_array($result['result'])) {
      $r2 = $result['result'];
      if (isset($r2['items']) && is_array($r2['items'])) $items = $r2['items'];
      elseif (isset($r2['products']) && is_array($r2['products'])) $items = $r2['products'];
    }
  }

  if (!is_array($items)) return [];

  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $oid = trim((string)($it['offer_id'] ?? ''));
    if ($oid !== '') $ids[$oid] = true;
  }

  return array_keys($ids);
}

/**
 * Основной способ: /v3/product/list (last_id/limit).
 */
function ozon_fetch_all_offer_ids_v3(array $oz, int $limit = 1000, int $maxPages = 200): array
{
  $all = [];
  $seen = [];
  $lastId = '';

  for ($page = 0; $page < $maxPages; $page++) {
    $payload = [
  'filter' => new stdClass(),
  'last_id' => $lastId,
  'limit' => $limit,
];


    $resp = ozon_post_json($oz, '/v3/product/list', $payload);
    $result = $resp['result'] ?? null;

    $ids = ozon_extract_offer_ids_from_result($result);
    foreach ($ids as $id) {
      if (!isset($seen[$id])) {
        $seen[$id] = true;
        $all[] = $id;
      }
    }

    $newLast = '';
    if (is_array($result) && array_key_exists('last_id', $result)) $newLast = (string)$result['last_id'];
    elseif (is_array($result) && array_key_exists('lastId', $result)) $newLast = (string)$result['lastId'];

    if (count($ids) === 0) break;
    if ($newLast === '' || $newLast === $lastId) break;

    $lastId = $newLast;
  }

  return $all;
}

/**
 * Вернуть полный список items из /v3/product/list (last_id/limit).
 * Каждый item содержит минимум offer_id, часто product_id/sku и др.
 */
function ozon_fetch_all_products_v3(array $oz, int $limit = 1000, int $maxPages = 200): array
{
  $all = [];
  $lastId = '';

  for ($page = 0; $page < $maxPages; $page++) {
    $payload = [
      'filter' => new stdClass(),
      'last_id' => $lastId,
      'limit' => $limit,
    ];

    $resp = ozon_post_json($oz, '/v3/product/list', $payload);
    $result = $resp['result'] ?? null;

    $items = [];
    if (is_array($result) && isset($result['items']) && is_array($result['items'])) {
      $items = $result['items'];
    }

    foreach ($items as $it) {
      if (is_array($it)) $all[] = $it;
    }

    $newLast = '';
    if (is_array($result) && array_key_exists('last_id', $result)) $newLast = (string)$result['last_id'];

    if (count($items) === 0) break;
    if ($newLast === '' || $newLast === $lastId) break;

    $lastId = $newLast;
  }

  return $all;
}




/**
 * Кэш на 5 минут в storage/cache, ключ — client_id.
 * Возвращает set: [offer_id => true, ...]
 */
function ozon_get_offer_id_set_cached(array $cfg, int $ttlSec = 300): array
{
  ozon_products_tables_ensure($cfg);
  $oz = ozon_cfg_or_fail($cfg);
  $scope = ozon_products_scope_from_ref(null, $cfg);
  $scopeKey = (int)($scope['connection_id'] ?? 0) > 0
    ? 'connection_' . (int)$scope['connection_id']
    : preg_replace('~[^0-9A-Za-z_-]+~', '_', (string)$oz['client_id']);
  if ($scopeKey === '') $scopeKey = 'default';

  $cacheDir = __DIR__ . '/../storage/cache';
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

  $cacheFile = $cacheDir . '/ozon_offer_ids_' . $scopeKey . '.json';
  $now = time();

  if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
      $j = json_decode($raw, true);
      if (is_array($j) && isset($j['ts'], $j['offer_ids']) && is_array($j['offer_ids'])) {
        $ts = (int)$j['ts'];
        if ($ts > 0 && ($now - $ts) <= $ttlSec) {
          $set = [];
          foreach ($j['offer_ids'] as $id) {
            $id = trim((string)$id);
            if ($id !== '') $set[$id] = true;
          }
          return $set;
        }
      }
    }
  }

  $ids = ozon_fetch_all_offer_ids_v3($oz); // только v3, без v1 fallback


  @file_put_contents($cacheFile, json_encode([
    'ts' => $now,
    'offer_ids' => array_values($ids),
  ], JSON_UNESCAPED_UNICODE));

  $set = [];
  foreach ($ids as $id) {
    $id = trim((string)$id);
    if ($id !== '') $set[$id] = true;
  }
  return $set;
}
