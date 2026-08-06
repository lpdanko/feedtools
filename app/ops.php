<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/op_registry.php';
require_once __DIR__ . '/ozon_products.php';

function ops_table_has_index(PDO $pdo, string $indexName): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedtools_operations'
      AND INDEX_NAME = ?
  ");
  $st->execute([$indexName]);
  return ((int)$st->fetchColumn()) > 0;
}

function ops_schema_cache_path(): string
{
  return dirname(__DIR__) . '/storage/cache/ops_schema_20260524_v2.ready';
}

function ops_schema_cache_is_ready(int $ttlSeconds = 86400): bool
{
  $path = ops_schema_cache_path();
  return is_file($path) && (time() - (int)@filemtime($path)) < $ttlSeconds;
}

function ops_schema_cache_mark_ready(): void
{
  $path = ops_schema_cache_path();
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  @touch($path);
}

function ops_table_probe(PDO $pdo): bool
{
  try {
    $pdo->query("
      SELECT
        id,
        dataset_id,
        op_type,
        connection_id,
        marketplace,
        service_key,
        params_json,
        status,
        created_by,
        created_at,
        started_at,
        finished_at,
        cancel_requested_at,
        runner_pid,
        progress_done,
        progress_total,
        progress_stage,
        progress_msg,
        heartbeat_at,
        log_text,
        log_tail,
        error_text,
        outputs_json,
        summary_json
      FROM feedtools_operations
      LIMIT 0
    ");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function ops_registry_meta(string $opType): array
{
  static $registry = null;
  if ($registry === null) {
    $registry = op_registry();
  }
  return is_array($registry[$opType] ?? null) ? $registry[$opType] : [];
}

function ops_infer_marketplace_from_op_type(string $opType): ?string
{
  if (str_starts_with($opType, 'ozon_')) return 'ozon';
  if (str_starts_with($opType, 'wb_')) return 'wb';
  return null;
}

function ops_infer_service_key_from_op_type(string $opType): ?string
{
  if (in_array($opType, ['ozon_sync_actions', 'ozon_push_selected_feeds', 'wb_push_selected_feeds', 'yandex_push_selected_feeds'], true)) {
    return 'price_tool';
  }
  if ($opType === 'ozon_sync_products') {
    return 'marketplace_data';
  }
  if (str_starts_with($opType, 'orders_sync')) {
    return 'orders_sync';
  }
  return null;
}

function ops_resolve_context(string $opType, array $params): array
{
  $meta = ops_registry_meta($opType);
  $connectionId = isset($params['connection_id']) ? (int)$params['connection_id'] : 0;
  if ($connectionId <= 0) {
    $connectionId = null;
  }

  $marketplace = trim((string)($params['marketplace'] ?? ($meta['marketplace'] ?? ops_infer_marketplace_from_op_type($opType) ?? '')));
  if ($marketplace === '') {
    $marketplace = null;
  }

  $serviceKey = trim((string)($params['service_key'] ?? ($meta['service_key'] ?? ops_infer_service_key_from_op_type($opType) ?? '')));
  if ($serviceKey === '') {
    $serviceKey = null;
  }

  return [
    'connection_id' => $connectionId,
    'marketplace' => $marketplace,
    'service_key' => $serviceKey,
  ];
}

function ops_table_ensure(): void
{
  static $ready = false;
  if ($ready) return;

  $pdo = db();
  if ((string)getenv('FEEDTOOLS_OPS_FORCE_SCHEMA_ENSURE') !== '1') {
    if (ops_schema_cache_is_ready() || ops_table_probe($pdo)) {
      ops_schema_cache_mark_ready();
      $ready = true;
      return;
    }
  }

  ozon_products_connections_table_ensure();
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS feedtools_operations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      dataset_id BIGINT UNSIGNED NOT NULL,
      op_type VARCHAR(191) NOT NULL,
      connection_id BIGINT UNSIGNED NULL DEFAULT NULL,
      marketplace VARCHAR(32) NULL DEFAULT NULL,
      service_key VARCHAR(64) NULL DEFAULT NULL,
      params_json LONGTEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'queued',
      created_by VARCHAR(191) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      cancel_requested_at DATETIME NULL,
      runner_pid INT NULL,
      progress_done BIGINT NOT NULL DEFAULT 0,
      progress_total BIGINT NOT NULL DEFAULT 0,
      progress_stage VARCHAR(191) NULL,
      progress_msg TEXT NULL,
      heartbeat_at DATETIME NULL,
      log_text LONGTEXT NULL,
      log_tail LONGTEXT NULL,
      error_text TEXT NULL,
      outputs_json LONGTEXT NULL,
      summary_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY idx_ops_status_id (status, id),
      KEY idx_ops_dataset_id (dataset_id),
      KEY idx_ops_connection_status_id (connection_id, status, id),
      KEY idx_ops_marketplace_created (marketplace, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $wanted = [
    'connection_id' => "ALTER TABLE feedtools_operations ADD COLUMN connection_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER op_type",
    'marketplace' => "ALTER TABLE feedtools_operations ADD COLUMN marketplace VARCHAR(32) NULL DEFAULT NULL AFTER connection_id",
    'service_key' => "ALTER TABLE feedtools_operations ADD COLUMN service_key VARCHAR(64) NULL DEFAULT NULL AFTER marketplace",
    'created_by' => "ALTER TABLE feedtools_operations ADD COLUMN created_by VARCHAR(191) NULL AFTER status",
    'cancel_requested_at' => "ALTER TABLE feedtools_operations ADD COLUMN cancel_requested_at DATETIME NULL AFTER finished_at",
    'runner_pid' => "ALTER TABLE feedtools_operations ADD COLUMN runner_pid INT NULL AFTER cancel_requested_at",
    'progress_done' => "ALTER TABLE feedtools_operations ADD COLUMN progress_done BIGINT NOT NULL DEFAULT 0 AFTER runner_pid",
    'progress_total' => "ALTER TABLE feedtools_operations ADD COLUMN progress_total BIGINT NOT NULL DEFAULT 0 AFTER progress_done",
    'progress_stage' => "ALTER TABLE feedtools_operations ADD COLUMN progress_stage VARCHAR(191) NULL AFTER progress_total",
    'progress_msg' => "ALTER TABLE feedtools_operations ADD COLUMN progress_msg TEXT NULL AFTER progress_stage",
    'heartbeat_at' => "ALTER TABLE feedtools_operations ADD COLUMN heartbeat_at DATETIME NULL AFTER progress_msg",
    'log_tail' => "ALTER TABLE feedtools_operations ADD COLUMN log_tail LONGTEXT NULL AFTER log_text",
    'outputs_json' => "ALTER TABLE feedtools_operations ADD COLUMN outputs_json LONGTEXT NULL AFTER error_text",
    'summary_json' => "ALTER TABLE feedtools_operations ADD COLUMN summary_json LONGTEXT NULL AFTER outputs_json",
  ];

  foreach ($wanted as $col => $sql) {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'feedtools_operations'
        AND COLUMN_NAME = ?
    ");
    $st->execute([$col]);
    if ((int)$st->fetchColumn() === 0) {
      $pdo->exec($sql);
    }
  }

  $statusInfoSt = $pdo->query("
    SELECT DATA_TYPE, COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedtools_operations'
      AND COLUMN_NAME = 'status'
    LIMIT 1
  ");
  $statusInfo = $statusInfoSt ? $statusInfoSt->fetch() : null;
  $statusType = strtolower((string)($statusInfo['DATA_TYPE'] ?? ''));
  $statusColumnType = strtolower((string)($statusInfo['COLUMN_TYPE'] ?? ''));
  if ($statusType === 'enum' && strpos($statusColumnType, "'cancelled'") === false) {
    $pdo->exec("
      ALTER TABLE feedtools_operations
      MODIFY COLUMN status ENUM('queued','running','done','error','cancelled')
      NOT NULL DEFAULT 'queued'
    ");
  }

  if (!ops_table_has_index($pdo, 'idx_ops_connection_status_id')) {
    $pdo->exec("ALTER TABLE feedtools_operations ADD KEY idx_ops_connection_status_id (connection_id, status, id)");
  }
  if (!ops_table_has_index($pdo, 'idx_ops_marketplace_created')) {
    $pdo->exec("ALTER TABLE feedtools_operations ADD KEY idx_ops_marketplace_created (marketplace, created_at)");
  }
  if (!ops_table_has_index($pdo, 'idx_ops_duplicate_recent')) {
    $pdo->exec("ALTER TABLE feedtools_operations ADD KEY idx_ops_duplicate_recent (dataset_id, op_type, created_by, status, created_at, id)");
  }

  if ((string)getenv('FEEDTOOLS_OPS_CONTEXT_BACKFILL') === '1') {
    $pdo->exec("
      UPDATE feedtools_operations
      SET connection_id = NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED), 0)
      WHERE (connection_id IS NULL OR connection_id = 0)
        AND JSON_EXTRACT(params_json, '$.connection_id') IS NOT NULL
    ");

    $ozonConnectionsSt = $pdo->query("
      SELECT id
      FROM feedtools_marketplace_connections
      WHERE marketplace = 'ozon'
      ORDER BY is_active DESC, sort_order ASC, id ASC
    ");
    $ozonConnections = $ozonConnectionsSt ? ($ozonConnectionsSt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    if (count($ozonConnections) === 1) {
      $onlyOzonConnectionId = (int)$ozonConnections[0];
      $st = $pdo->prepare("
        UPDATE feedtools_operations
        SET connection_id = ?
        WHERE (connection_id IS NULL OR connection_id = 0)
          AND op_type IN ('ozon_sync_products', 'ozon_sync_actions', 'ozon_push_selected_feeds')
      ");
      $st->execute([$onlyOzonConnectionId]);
    }

    foreach (op_registry() as $registeredOpType => $meta) {
      $marketplace = trim((string)($meta['marketplace'] ?? ops_infer_marketplace_from_op_type((string)$registeredOpType) ?? ''));
      if ($marketplace !== '') {
        $st = $pdo->prepare("
          UPDATE feedtools_operations
          SET marketplace = ?
          WHERE op_type = ?
            AND (marketplace IS NULL OR marketplace = '')
        ");
        $st->execute([$marketplace, $registeredOpType]);
      }

      $serviceKey = trim((string)($meta['service_key'] ?? ops_infer_service_key_from_op_type((string)$registeredOpType) ?? ''));
      if ($serviceKey !== '') {
        $st = $pdo->prepare("
          UPDATE feedtools_operations
          SET service_key = ?
          WHERE op_type = ?
            AND (service_key IS NULL OR service_key = '')
        ");
        $st->execute([$serviceKey, $registeredOpType]);
      }
    }
  }

  ops_schema_cache_mark_ready();
  $ready = true;
}

function ops_current_actor(): ?string
{
  if (PHP_SAPI !== 'cli' && function_exists('ft_current_user')) {
    $actor = ft_current_user();
    $actor = is_string($actor) ? trim($actor) : '';
    if ($actor !== '') {
      return $actor;
    }
  }

  foreach (['PHP_AUTH_USER', 'REMOTE_USER'] as $key) {
    $value = trim((string)($_SERVER[$key] ?? ''));
    if ($value !== '') return $value;
  }
  return PHP_SAPI === 'cli' ? 'cli' : null;
}

function ops_worker_max_parallel(): int
{
  $raw = getenv('WORKER_MAX_PARALLEL');
  $workerCfg = is_array($GLOBALS['__feedtools_worker_config'] ?? null) ? $GLOBALS['__feedtools_worker_config'] : [];
  $value = ($raw === false || trim((string)$raw) === '')
    ? (int)($workerCfg['max_parallel'] ?? 3)
    : (int)$raw;
  if ($value < 1) $value = 1;
  if ($value > 8) $value = 8;
  return $value;
}

function ops_worker_env_int(string $name, int $default, int $min = 0, int $max = 8, string $configKey = ''): int
{
  $raw = getenv($name);
  $workerCfg = is_array($GLOBALS['__feedtools_worker_config'] ?? null) ? $GLOBALS['__feedtools_worker_config'] : [];
  if ($raw === false || trim((string)$raw) === '') {
    $value = ($configKey !== '' && array_key_exists($configKey, $workerCfg))
      ? (int)$workerCfg[$configKey]
      : $default;
  } else {
    $value = (int)$raw;
  }
  if ($value < $min) $value = $min;
  if ($value > $max) $value = $max;
  return $value;
}

function ops_worker_dedicated_lane_capacities(): array
{
  return [
    'price_tool' => ops_worker_env_int('WORKER_PRICE_TOOL_MAX_PARALLEL', 3, 0, 4, 'price_tool_max_parallel'),
    'wb_promotions' => ops_worker_env_int('WORKER_WB_PROMOTIONS_MAX_PARALLEL', 1, 0, 4, 'wb_promotions_max_parallel'),
    'marketplace_data' => ops_worker_env_int('WORKER_MARKETPLACE_DATA_MAX_PARALLEL', 1, 0, 4, 'marketplace_data_max_parallel'),
    'supplier_feed' => ops_worker_env_int('WORKER_SUPPLIER_FEED_MAX_PARALLEL', 1, 0, 4, 'supplier_feed_max_parallel'),
  ];
}

function ops_worker_lane_capacity(string $lane): int
{
  $lane = trim($lane);
  if ($lane === '' || $lane === 'dataset') {
    return ops_worker_max_parallel();
  }
  $capacities = ops_worker_dedicated_lane_capacities();
  return max(0, (int)($capacities[$lane] ?? 0));
}

function ops_worker_lane_for_op_type(string $opType): string
{
  $opType = trim($opType);
  if ($opType === '') {
    return 'dataset';
  }

  $meta = ops_registry_meta($opType);
  $queueLane = trim((string)($meta['queue_lane'] ?? ''));
  if ($queueLane !== '' && isset(ops_worker_dedicated_lane_capacities()[$queueLane])) {
    return $queueLane;
  }

  $serviceKey = trim((string)($meta['service_key'] ?? ''));
  if (isset(ops_worker_dedicated_lane_capacities()[$serviceKey])) {
    return $serviceKey;
  }

  return 'dataset';
}

function ops_worker_op_types_for_lane(string $lane): array
{
  $lane = trim($lane);
  if ($lane === '' || $lane === 'dataset') {
    return [];
  }

  $out = [];
  foreach (op_registry() as $opType => $_meta) {
    if (ops_worker_lane_for_op_type((string)$opType) === $lane) {
      $out[] = (string)$opType;
    }
  }
  sort($out, SORT_STRING);
  return $out;
}

function ops_worker_dedicated_op_types(): array
{
  $out = [];
  foreach (array_keys(ops_worker_dedicated_lane_capacities()) as $lane) {
    array_push($out, ...ops_worker_op_types_for_lane((string)$lane));
  }
  $out = array_values(array_unique(array_filter($out, static fn($v): bool => trim((string)$v) !== '')));
  sort($out, SORT_STRING);
  return $out;
}

function ops_op_type_requires_dataset_lock(string $opType): bool
{
  $opType = trim($opType);
  if ($opType === '') {
    return true;
  }

  $meta = ops_registry_meta($opType);
  if (array_key_exists('dataset_lock', $meta)) {
    return (bool)$meta['dataset_lock'];
  }

  return true;
}

function ops_row_requires_dataset_lock(array $row): bool
{
  return ops_op_type_requires_dataset_lock((string)($row['op_type'] ?? ''));
}

function ops_decode_params_json(array $row): array
{
  $raw = (string)($row['params_json'] ?? '');
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function ops_detect_pipeline_parent_id(array $row): int
{
  foreach (['_pipeline_parent_op_id', 'pipeline_parent_op_id', 'parent_op_id'] as $key) {
    $value = isset($row[$key]) ? (int)$row[$key] : 0;
    if ($value > 0) return $value;
  }

  $params = ops_decode_params_json($row);
  foreach (['_pipeline_parent_op_id', 'pipeline_parent_op_id', 'parent_op_id'] as $key) {
    $value = isset($params[$key]) ? (int)$params[$key] : 0;
    if ($value > 0) return $value;
  }

  $logText = (string)($row['log_text'] ?? '');
  if ($logText !== '' && preg_match('/Started by run_pipeline op=(\d+)/', $logText, $m)) {
    return (int)$m[1];
  }

  return 0;
}

function ops_root_op_id(array $row, int $maxDepth = 8): int
{
  ops_table_ensure();

  $currentId = (int)($row['id'] ?? 0);
  if ($currentId <= 0) return 0;

  $seen = [];
  $currentRow = $row;

  for ($depth = 0; $depth < $maxDepth; $depth++) {
    $currentId = (int)($currentRow['id'] ?? 0);
    if ($currentId <= 0 || isset($seen[$currentId])) {
      break;
    }
    $seen[$currentId] = true;

    $parentId = ops_detect_pipeline_parent_id($currentRow);
    if ($parentId <= 0 || isset($seen[$parentId])) {
      return $currentId;
    }

    $parent = ops_get($parentId);
    if (!$parent) {
      return $currentId;
    }
    $currentRow = $parent;
  }

  return (int)($currentRow['id'] ?? 0);
}

function ops_root_row(array $row, int $maxDepth = 8): array
{
  $rootId = ops_root_op_id($row, $maxDepth);
  if ($rootId <= 0) return $row;
  $root = ((int)($row['id'] ?? 0) === $rootId) ? $row : ops_get($rootId);
  return is_array($root) && $root ? $root : $row;
}

function ops_effective_actor_for_row(array $row): ?string
{
  $root = ops_root_row($row);
  foreach ([$root, $row] as $candidate) {
    $value = trim((string)($candidate['created_by'] ?? ''));
    if ($value !== '' && $value !== 'cli') {
      return $value;
    }
  }

  $value = trim((string)($root['created_by'] ?? ($row['created_by'] ?? '')));
  return $value !== '' ? $value : null;
}

function ops_resolve_request_context(array $row): array
{
  $root = ops_root_row($row);
  return [
    'actor_user' => ops_effective_actor_for_row($row),
    'root_op_id' => (int)($root['id'] ?? 0),
    'root_op_type' => trim((string)($root['op_type'] ?? '')) ?: null,
  ];
}

function ops_find_recent_duplicate(int $datasetId, string $opType, array $params = [], int $windowSec = 10, ?string $createdByOverride = null): ?array
{
  ops_table_ensure();

  $createdBy = $createdByOverride !== null ? trim($createdByOverride) : ops_current_actor();
  $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);
  $recentIdWindow = 5000;

  $sql = "
    SELECT id, dataset_id, op_type, status, created_by, created_at, params_json
    FROM feedtools_operations FORCE INDEX (PRIMARY)
    WHERE id >= (
      SELECT GREATEST(COALESCE(MAX(id), 0) - ?, 0)
      FROM feedtools_operations
    )
      AND dataset_id = ?
      AND op_type = ?
      AND status IN ('queued','running','done')
      AND created_at >= (NOW() - INTERVAL ? SECOND)
  ";
  $args = [$recentIdWindow, $datasetId, $opType, $windowSec];

  if ($createdBy !== null && $createdBy !== '') {
    $sql .= " AND created_by = ?";
    $args[] = $createdBy;
  } else {
    $sql .= " AND (created_by IS NULL OR created_by = '')";
  }

  $sql .= " ORDER BY id DESC LIMIT 20";

  $stmt = db()->prepare($sql);
  $stmt->execute($args);
  foreach (($stmt->fetchAll() ?: []) as $row) {
    $rowParamsJson = (string)($row['params_json'] ?? '');
    if ($paramsJson === null) {
      if ($rowParamsJson !== '') {
        continue;
      }
    } elseif ($rowParamsJson !== $paramsJson) {
      continue;
    }
    unset($row['params_json']);
    return $row ?: null;
  }
  return null;
}

function ops_create(int $datasetId, string $opType, array $params = [], ?string $createdByOverride = null): int {
  ops_table_ensure();
  $createdBy = $createdByOverride;
  if ($createdBy === null || trim($createdBy) === '') {
    $createdBy = ops_current_actor();
  }
  $context = ops_resolve_context($opType, $params);
  $stmt = db()->prepare("
    INSERT INTO feedtools_operations (dataset_id, op_type, connection_id, marketplace, service_key, params_json, status, created_by)
    VALUES (?, ?, ?, ?, ?, ?, 'queued', ?)
  ");
  $stmt->execute([
    $datasetId,
    $opType,
    $context['connection_id'],
    $context['marketplace'],
    $context['service_key'],
    json_encode($params, JSON_UNESCAPED_UNICODE),
    $createdBy !== null ? $createdBy : null,
  ]);
  return (int)db()->lastInsertId();
}

function feedtools_global_ops_dataset_id(): int
{
  static $cached = null;
  if ($cached !== null) return $cached;

  $pdo = db();
  $name = '[system] Global operations';
  $storedFilename = 'system_global_ops.txt';
  $storedPath = '[system]/global-operations';
  $sha = hash('sha256', 'feedtools-global-ops-dataset-v1');

  $st = $pdo->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ? LIMIT 1");
  $st->execute([$sha]);
  $existingId = (int)($st->fetchColumn() ?: 0);
  if ($existingId > 0) {
    $cached = $existingId;
    return $cached;
  }

  $ins = $pdo->prepare("
    INSERT INTO feedtools_datasets
      (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
    VALUES
      (?, ?, ?, 0, ?, 0, NULL)
  ");
  $ins->execute([$name, $storedFilename, $storedPath, $sha]);
  $cached = (int)$pdo->lastInsertId();
  return $cached;
}

function ops_db_is_retryable(Throwable $e): bool
{
  if (!$e instanceof PDOException) {
    return false;
  }
  $state = (string)($e->errorInfo[0] ?? $e->getCode());
  $driverCode = (int)($e->errorInfo[1] ?? 0);
  $msg = strtolower($e->getMessage());
  return $state === '40001'
    || in_array($driverCode, [1205, 1213, 2002, 2006, 2013], true)
    || str_contains($msg, 'connection refused')
    || str_contains($msg, 'deadlock found')
    || str_contains($msg, 'lock wait timeout')
    || str_contains($msg, 'server has gone away')
    || str_contains($msg, 'lost connection');
}

function ops_db_retry(callable $fn, int $attempts = 4)
{
  $attempts = max(1, $attempts);
  $last = null;
  for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
      return $fn();
    } catch (Throwable $e) {
      $last = $e;
      if ($attempt >= $attempts || !ops_db_is_retryable($e)) {
        throw $e;
      }
      usleep(150000 * $attempt);
      db_reconnect();
    }
  }
  if ($last instanceof Throwable) {
    throw $last;
  }
  return null;
}

function ops_set_status(int $opId, string $status, ?string $logAppend = null, ?string $errorText = null): void {
  ops_table_ensure();
  $fields = ["status = ?"];
  $args = [$status];

  if ($status === 'running') {
    $fields[] = "started_at = COALESCE(started_at, NOW())";
  }
  if (in_array($status, ['done','error','cancelled'], true)) {
    $fields[] = "finished_at = NOW()";
    $fields[] = "runner_pid = NULL";
  }
  if ($logAppend !== null) {
    $fields[] = "log_text = NULL";
    $fields[] = "log_tail = RIGHT(CONCAT(COALESCE(log_tail,''), ?), ?)";
    $args[] = $logAppend;
    $args[] = 200000;
  }
  if ($errorText !== null) {
    $fields[] = "error_text = ?";
    $args[] = $errorText;
  }

  $args[] = $opId;
  $sql = "UPDATE feedtools_operations SET " . implode(", ", $fields) . " WHERE id = ?";
  ops_db_retry(static function () use ($sql, $args): void {
    db()->prepare($sql)->execute($args);
  });
}

function ops_set_outputs(int $opId, array $outputs): void {
  ops_table_ensure();
  ops_db_retry(static function () use ($opId, $outputs): void {
    db()->prepare("UPDATE feedtools_operations SET outputs_json = ? WHERE id = ?")
      ->execute([json_encode($outputs, JSON_UNESCAPED_UNICODE), $opId]);
  });
}

function ops_list_by_dataset(int $datasetId, int $limit = 50): array {
  ops_table_ensure();
  $stmt = db()->prepare("
    SELECT id, op_type, status, created_at, started_at, finished_at, error_text, cancel_requested_at, created_by
    FROM feedtools_operations
    WHERE dataset_id = ?
    ORDER BY id DESC
    LIMIT ?
  ");
  $stmt->bindValue(1, $datasetId, PDO::PARAM_INT);
  $stmt->bindValue(2, $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll();
}

function ops_get(int $opId): ?array {
  ops_table_ensure();
  $stmt = db()->prepare("SELECT * FROM feedtools_operations WHERE id = ?");
  $stmt->execute([$opId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function ops_pipeline_child_ids(int $pipelineOpId): array
{
  ops_table_ensure();
  $parent = ops_get($pipelineOpId);
  if (!$parent) return [];

  $ids = [];

  $summaryRaw = (string)($parent['summary_json'] ?? '');
  if ($summaryRaw !== '') {
    $summary = json_decode($summaryRaw, true);
    $steps = is_array($summary['pipeline']['steps'] ?? null) ? $summary['pipeline']['steps'] : [];
    foreach ($steps as $step) {
      $childId = isset($step['op_id']) ? (int)$step['op_id'] : 0;
      if ($childId > 0) $ids[$childId] = true;
    }
  }

  $datasetId = (int)($parent['dataset_id'] ?? 0);
  if ($datasetId > 0) {
    $stmt = db()->prepare("
      SELECT
        id,
        CASE WHEN JSON_VALID(params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$._pipeline_parent_op_id')), '') ELSE NULL END AS _pipeline_parent_op_id,
        CASE WHEN JSON_VALID(params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.pipeline_parent_op_id')), '') ELSE NULL END AS pipeline_parent_op_id,
        CASE WHEN JSON_VALID(params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.parent_op_id')), '') ELSE NULL END AS parent_op_id,
        LEFT(COALESCE(log_text, ''), 512) AS log_text
      FROM feedtools_operations
      WHERE dataset_id = ? AND id <> ?
      ORDER BY id ASC
    ");
    $stmt->execute([$datasetId, $pipelineOpId]);
    foreach (($stmt->fetchAll() ?: []) as $row) {
      if (ops_detect_pipeline_parent_id($row) === $pipelineOpId) {
        $ids[(int)$row['id']] = true;
      }
    }
  }

  $out = array_map('intval', array_keys($ids));
  sort($out, SORT_NUMERIC);
  return $out;
}

function ops_pipeline_related_op_ids(int $pipelineOpId): array
{
  $ids = [$pipelineOpId];
  foreach (ops_pipeline_child_ids($pipelineOpId) as $childId) {
    if ($childId > 0) $ids[] = $childId;
  }
  $ids = array_values(array_unique(array_map('intval', $ids)));
  sort($ids, SORT_NUMERIC);
  return $ids;
}

function ops_append_log_tail(int $opId, string $text, int $maxLen = 200000): void
{
  ops_table_ensure();
  db_exec_with_retry(function () use ($opId, $text, $maxLen) {
    $pdo = db();
	    $stmt = $pdo->prepare("
	      UPDATE feedtools_operations
	      SET
	        log_text = NULL,
	        log_tail = RIGHT(CONCAT(COALESCE(log_tail,''), ?), ?)
	      WHERE id=? LIMIT 1
	    ");
	    $stmt->execute([$text, $maxLen, $opId]);
	  }, 2);
	}




function db_reconnect(): void {
    // сбрасываем singleton/кеш
    $GLOBALS['__db'] = null;
}


function db_exec_with_retry(callable $fn, int $retries = 1) {
    for ($i = 0; $i <= $retries; $i++) {
        try {
            return $fn();
} catch (PDOException $e) {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    $msg = $e->getMessage();

    $isConnLoss = (
      $driverCode === 2006 ||
      $driverCode === 2013 ||
      stripos($msg, 'server has gone away') !== false ||
      stripos($msg, 'lost connection') !== false
    );

    if ($isConnLoss && $i < $retries) {
        db_reconnect();
        usleep(200000); // 200ms
        continue;
    }
    throw $e;
}

    }
}


function ops_update_progress(
  int $opId,
  int $done,
  int $total,
  ?string $stage = null,
  ?string $msg = null
): void {
  ops_table_ensure();
  ops_db_retry(static function () use ($opId, $done, $total, $stage, $msg): void {
    db()->prepare("
      UPDATE feedtools_operations
      SET progress_done = ?,
          progress_total = ?,
          progress_stage = ?,
          progress_msg = ?,
          heartbeat_at = NOW()
      WHERE id = ?
    ")->execute([$done, $total, $stage, $msg, $opId]);
  });
}

function ops_set_summary(int $opId, array $summary): void {
  ops_table_ensure();
  ops_db_retry(static function () use ($opId, $summary): void {
    db()->prepare("
      UPDATE feedtools_operations
      SET summary_json = ?
      WHERE id = ?
    ")->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), $opId]);
  });
}

function ops_set_runner_pid(int $opId, ?int $pid): void
{
  ops_table_ensure();
  ops_db_retry(static function () use ($opId, $pid): void {
    db()->prepare("UPDATE feedtools_operations SET runner_pid = ? WHERE id = ?")
      ->execute([$pid !== null ? $pid : null, $opId]);
  });
}

function ops_is_cancel_requested(int $opId): bool
{
  ops_table_ensure();
  $stmt = db()->prepare("
    SELECT 1
    FROM feedtools_operations
    WHERE id = ? AND cancel_requested_at IS NOT NULL
    LIMIT 1
  ");
  $stmt->execute([$opId]);
  return (bool)$stmt->fetchColumn();
}

function ops_request_cancel(int $opId): array
{
  ops_table_ensure();
  $op = ops_get($opId);
  if (!$op) {
    throw new RuntimeException('Operation not found');
  }

  $status = trim((string)($op['status'] ?? ''));
  if (in_array($status, ['done', 'error', 'cancelled'], true)) {
    return ['action' => 'noop', 'op' => $op];
  }

  if ($status === 'queued') {
    db()->prepare("
      UPDATE feedtools_operations
      SET
        status = 'cancelled',
        cancel_requested_at = COALESCE(cancel_requested_at, NOW()),
        finished_at = NOW(),
        progress_stage = 'cancelled',
        progress_msg = 'Cancelled before start',
        error_text = COALESCE(NULLIF(error_text, ''), 'Cancelled by user')
      WHERE id = ? AND status = 'queued'
    ")->execute([$opId]);

    return ['action' => 'cancelled_queued', 'op' => ops_get($opId)];
  }

  db()->prepare("
    UPDATE feedtools_operations
    SET
      cancel_requested_at = COALESCE(cancel_requested_at, NOW()),
      progress_msg = CASE
        WHEN progress_msg IS NULL OR progress_msg = '' THEN 'Cancel requested'
        ELSE progress_msg
      END
    WHERE id = ?
  ")->execute([$opId]);

  return ['action' => 'requested_running', 'op' => ops_get($opId)];
}

function ops_mark_cancelled(int $opId, string $message = 'Cancelled by user'): void
{
  ops_table_ensure();
  db()->prepare("
    UPDATE feedtools_operations
    SET
      status = 'cancelled',
      finished_at = NOW(),
      runner_pid = NULL,
      progress_stage = 'cancelled',
      progress_msg = ?,
      error_text = COALESCE(NULLIF(error_text, ''), ?)
    WHERE id = ?
  ")->execute([$message, $message, $opId]);
}

function ops_elapsed_sec(array $op): int
{
  $base = trim((string)($op['started_at'] ?: ($op['created_at'] ?? '')));
  if ($base === '') return 0;
  $ts = strtotime($base);
  if (!$ts) return 0;
  return max(0, time() - $ts);
}

function ops_estimate_duration_sec(string $opType): int
{
  ops_table_ensure();
  $stmt = db()->prepare("
    SELECT AVG(duration_sec) AS avg_sec
    FROM (
      SELECT GREATEST(TIMESTAMPDIFF(SECOND, started_at, finished_at), 1) AS duration_sec
      FROM feedtools_operations
      WHERE op_type = ?
        AND status IN ('done', 'error', 'cancelled')
        AND started_at IS NOT NULL
        AND finished_at IS NOT NULL
      ORDER BY id DESC
      LIMIT 12
    ) t
  ");
  $stmt->execute([$opType]);
  $avg = (int)round((float)($stmt->fetchColumn() ?: 0));
  if ($avg <= 0) {
    $stmt = db()->query("
      SELECT AVG(duration_sec) AS avg_sec
      FROM (
        SELECT GREATEST(TIMESTAMPDIFF(SECOND, started_at, finished_at), 1) AS duration_sec
        FROM feedtools_operations
        WHERE status IN ('done', 'error', 'cancelled')
          AND started_at IS NOT NULL
          AND finished_at IS NOT NULL
        ORDER BY id DESC
        LIMIT 20
      ) t
    ");
    $avg = (int)round((float)($stmt->fetchColumn() ?: 0));
  }

  if ($avg <= 0) $avg = 120;
  return max(15, min($avg, 4 * 60 * 60));
}

function ops_estimate_remaining_sec(array $op): int
{
  $status = trim((string)($op['status'] ?? ''));
  $elapsed = ops_elapsed_sec($op);
  $done = (int)($op['progress_done'] ?? 0);
  $total = (int)($op['progress_total'] ?? 0);

  if ($status === 'running' && $total > 0 && $done > 0 && $elapsed >= 3) {
    $rate = $done / max(1, $elapsed);
    if ($rate > 0) {
      return max(5, (int)round(max(0, $total - $done) / $rate));
    }
  }

  $estimate = ops_estimate_duration_sec((string)($op['op_type'] ?? ''));
  if ($status === 'running') {
    return max(5, $estimate - min($estimate - 1, $elapsed));
  }
  return $estimate;
}

function ops_fetch_active_queue_rows(int $limit = 200): array
{
  ops_table_ensure();
  $stmt = db()->prepare("
    SELECT
      o.id,
      o.dataset_id,
      o.op_type,
      o.status,
      o.created_by,
      o.created_at,
      o.started_at,
      o.cancel_requested_at,
      o.progress_done,
      o.progress_total,
      o.progress_stage,
      o.progress_msg,
      CASE WHEN JSON_VALID(o.params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.params_json, '$._pipeline_parent_op_id')), '') ELSE NULL END AS _pipeline_parent_op_id,
      CASE WHEN JSON_VALID(o.params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.params_json, '$.pipeline_parent_op_id')), '') ELSE NULL END AS pipeline_parent_op_id,
      CASE WHEN JSON_VALID(o.params_json) THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.params_json, '$.parent_op_id')), '') ELSE NULL END AS parent_op_id,
      LEFT(COALESCE(o.log_text, ''), 512) AS log_text,
      d.original_filename
    FROM feedtools_operations o
    LEFT JOIN feedtools_datasets d ON d.id = o.dataset_id
    WHERE o.status IN ('queued', 'running')
    ORDER BY
      CASE WHEN o.status = 'running' THEN 0 ELSE 1 END,
      o.id ASC
    LIMIT ?
  ");
  $stmt->bindValue(1, $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll() ?: [];

  foreach ($rows as &$row) {
    $row['elapsed_sec'] = ops_elapsed_sec($row);
    $row['eta_sec'] = ops_estimate_remaining_sec($row);
    $row['cancel_requested'] = !empty($row['cancel_requested_at']);
    $row['queue_wait_sec'] = 0;
  }
  unset($row);

  return $rows;
}

function ops_queue_public_row(?array $row): ?array
{
  if (!is_array($row)) {
    return null;
  }
  unset($row['params_json'], $row['log_text']);
  foreach (['progress_msg', 'original_filename'] as $key) {
    if (isset($row[$key]) && is_string($row[$key]) && mb_strlen($row[$key], 'UTF-8') > 500) {
      $row[$key] = mb_substr($row[$key], 0, 500, 'UTF-8') . '...';
    }
  }
  if (isset($row['_child_ops']) && is_array($row['_child_ops'])) {
    foreach ($row['_child_ops'] as &$child) {
      if (is_array($child) && isset($child['msg']) && is_string($child['msg']) && mb_strlen($child['msg'], 'UTF-8') > 500) {
        $child['msg'] = mb_substr($child['msg'], 0, 500, 'UTF-8') . '...';
      }
    }
    unset($child);
  }
  return $row;
}

function ops_collapse_pipeline_children(array $rows): array
{
  $rows = array_values($rows);
  $byId = [];
  $runningPipelinesByDataset = [];

  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $row['_pipeline_parent_op_id'] = ops_detect_pipeline_parent_id($row);
    $row['_active_child_op'] = null;
    $row['_child_ops'] = [];
    $byId[$id] = $row;

    if ((string)($row['status'] ?? '') === 'running' && (string)($row['op_type'] ?? '') === 'run_pipeline') {
      $datasetId = (int)($row['dataset_id'] ?? 0);
      $runningPipelinesByDataset[$datasetId][] = $id;
    }
  }

  foreach ($byId as $id => &$row) {
    if ((int)($row['_pipeline_parent_op_id'] ?? 0) > 0) {
      continue;
    }

    $datasetId = (int)($row['dataset_id'] ?? 0);
    $isLegacyInternal =
      (string)($row['status'] ?? '') === 'running' &&
      (string)($row['op_type'] ?? '') !== 'run_pipeline' &&
      (string)($row['created_by'] ?? '') === 'cli' &&
      !empty($runningPipelinesByDataset[$datasetId]) &&
      count($runningPipelinesByDataset[$datasetId]) === 1;

    if ($isLegacyInternal) {
      $row['_pipeline_parent_op_id'] = (int)$runningPipelinesByDataset[$datasetId][0];
    }
  }
  unset($row);

  $hiddenIds = [];

  foreach ($byId as $id => $row) {
    $parentId = (int)($row['_pipeline_parent_op_id'] ?? 0);
    if ($parentId <= 0 || !isset($byId[$parentId])) {
      continue;
    }

    $hiddenIds[$id] = true;
    $parent = $byId[$parentId];
    $childInfo = [
      'id' => $id,
      'op_type' => (string)($row['op_type'] ?? ''),
      'status' => (string)($row['status'] ?? ''),
      'stage' => (string)($row['progress_stage'] ?? ''),
      'msg' => (string)($row['progress_msg'] ?? ''),
      'eta_sec' => (int)($row['eta_sec'] ?? 0),
      'elapsed_sec' => (int)($row['elapsed_sec'] ?? 0),
    ];

    $parent['_child_ops'][] = $childInfo;

    $current = $parent['_active_child_op'] ?? null;
    $rowStartedAt = strtotime((string)($row['started_at'] ?? '')) ?: 0;
    $currentStartedAt = is_array($current) ? (strtotime((string)($current['started_at'] ?? '')) ?: 0) : 0;
    if ((string)($row['status'] ?? '') === 'running' && (!is_array($current) || $rowStartedAt >= $currentStartedAt)) {
      $parent['_active_child_op'] = $row;
      $parent['active_child_op_id'] = $id;
      $parent['active_child_op_type'] = (string)($row['op_type'] ?? '');
      $parent['active_child_stage'] = (string)($row['progress_stage'] ?? '');
      $parent['active_child_msg'] = (string)($row['progress_msg'] ?? '');
      $parent['active_child_eta_sec'] = (int)($row['eta_sec'] ?? 0);
    }

    $byId[$parentId] = $parent;
  }

  $visible = [];
  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    if (isset($hiddenIds[$id])) {
      continue;
    }
    $visible[] = $byId[$id] ?? $row;
  }

  return $visible;
}

function ops_schedule_queue(array $rows, ?array $futureRow = null): array
{
  $rows = array_values($rows);
  if ($futureRow !== null) {
    $futureRow['elapsed_sec'] = (int)($futureRow['elapsed_sec'] ?? 0);
    $futureRow['eta_sec'] = max(5, (int)($futureRow['eta_sec'] ?? ops_estimate_duration_sec((string)($futureRow['op_type'] ?? ''))));
    $futureRow['cancel_requested'] = !empty($futureRow['cancel_requested_at']);
    $futureRow['queue_wait_sec'] = 0;
    $rows[] = $futureRow;
  }

  $maxParallel = ops_worker_max_parallel();
  $running = [];
  $pending = [];
  $dedicatedRunning = [];
  $dedicatedPending = [];
  $byId = [];
  $capacityRunningIds = [];
  foreach (ops_running_rows_for_worker_capacity(array_values(array_filter(
    $rows,
    static fn($row): bool => is_array($row) && (string)($row['status'] ?? '') === 'running'
  ))) as $row) {
    $id = (int)($row['id'] ?? 0);
    if ($id > 0) {
      $capacityRunningIds[$id] = true;
    }
  }

  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $opType = (string)($row['op_type'] ?? '');
    $lane = ops_worker_lane_for_op_type($opType);
    $row['queue_lane'] = $lane;
    $row['dataset_lock'] = ops_row_requires_dataset_lock($row);
    $byId[$id] = $row;
    if ((string)($row['status'] ?? '') === 'running' && !isset($capacityRunningIds[$id])) {
      $row['queue_wait_sec'] = 0;
      $byId[$id] = $row;
      continue;
    }
    if ($lane !== 'dataset') {
      $etaSec = max(5, (int)($row['eta_sec'] ?? 0));
      if ((string)($row['status'] ?? '') === 'running') {
        $row['queue_wait_sec'] = 0;
        $byId[$id] = $row;
        $dedicatedRunning[$lane][] = [
          'end_sec' => $etaSec,
          'row' => $row,
        ];
      } else {
        $dedicatedPending[$lane][] = $row;
      }
      continue;
    }
    if ((string)($row['status'] ?? '') === 'running') {
      $datasetId = (int)($row['dataset_id'] ?? 0);
      $etaSec = max(5, (int)($row['eta_sec'] ?? 0));
      $requiresDatasetLock = ops_row_requires_dataset_lock($row);
      $row['queue_wait_sec'] = 0;
      $byId[$id] = $row;
      $running[] = [
        'id' => $id,
        'dataset_id' => $datasetId,
        'dataset_lock' => $requiresDatasetLock,
        'end_sec' => $etaSec,
      ];
    } else {
      $pending[] = $row;
    }
  }

  foreach ($dedicatedPending as $lane => $lanePending) {
    usort($lanePending, static function (array $a, array $b): int {
      return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    $capacity = ops_worker_lane_capacity((string)$lane);
    if ($capacity <= 0) {
      $capacity = 1;
    }
    $slots = array_values(array_filter(
      (array)($dedicatedRunning[$lane] ?? []),
      static fn($slot): bool => is_array($slot)
    ));
    usort($slots, static fn(array $a, array $b): int => ((int)($a['end_sec'] ?? 0)) <=> ((int)($b['end_sec'] ?? 0)));

    foreach ($lanePending as $row) {
      $id = (int)($row['id'] ?? 0);
      $etaSec = max(5, (int)($row['eta_sec'] ?? 0));
      $waitSec = 0;

      while (true) {
        $slots = array_values(array_filter(
          $slots,
          static fn(array $slot): bool => (int)($slot['end_sec'] ?? 0) > $waitSec
        ));
        usort($slots, static fn(array $a, array $b): int => ((int)($a['end_sec'] ?? 0)) <=> ((int)($b['end_sec'] ?? 0)));

        $conflictEndSec = null;
        foreach ($slots as $slot) {
          $slotRow = is_array($slot['row'] ?? null) ? $slot['row'] : [];
          if (ops_feed_push_rows_conflict($row, $slotRow)) {
            $slotEndSec = (int)($slot['end_sec'] ?? 0);
            $conflictEndSec = $conflictEndSec === null ? $slotEndSec : min($conflictEndSec, $slotEndSec);
          }
        }
        if ($conflictEndSec !== null) {
          $waitSec = max($waitSec, $conflictEndSec);
          continue;
        }

        if (count($slots) >= $capacity) {
          $waitSec = max($waitSec, (int)($slots[0]['end_sec'] ?? 0));
          continue;
        }

        break;
      }

      $row['queue_wait_sec'] = $waitSec;
      $row['queue_lane'] = (string)$lane;
      $byId[$id] = $row;
      $slots[] = [
        'end_sec' => $waitSec + $etaSec,
        'row' => $row,
      ];
      usort($slots, static fn(array $a, array $b): int => ((int)($a['end_sec'] ?? 0)) <=> ((int)($b['end_sec'] ?? 0)));
    }
  }

  usort($pending, static function (array $a, array $b): int {
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
  });

  $busyDatasets = [];
  foreach ($running as $slot) {
    if (!empty($slot['dataset_lock'])) {
      $busyDatasets[(int)$slot['dataset_id']] = true;
    }
  }

  $currentSec = 0;

  while (true) {
    $scheduled = false;

    while (count($running) < $maxParallel) {
      $candidateIndex = null;
      foreach ($pending as $idx => $row) {
        $datasetId = (int)($row['dataset_id'] ?? 0);
        $requiresDatasetLock = ops_row_requires_dataset_lock($row);
        if ($requiresDatasetLock && $datasetId > 0 && !empty($busyDatasets[$datasetId])) {
          continue;
        }
        $candidateIndex = $idx;
        break;
      }

      if ($candidateIndex === null) {
        break;
      }

      $row = $pending[$candidateIndex];
      array_splice($pending, $candidateIndex, 1);

      $id = (int)($row['id'] ?? 0);
      $datasetId = (int)($row['dataset_id'] ?? 0);
      $etaSec = max(5, (int)($row['eta_sec'] ?? 0));
      $requiresDatasetLock = ops_row_requires_dataset_lock($row);

      $row['queue_wait_sec'] = $currentSec;
      $row['dataset_lock'] = $requiresDatasetLock;
      $byId[$id] = $row;

      $running[] = [
        'id' => $id,
        'dataset_id' => $datasetId,
        'dataset_lock' => $requiresDatasetLock,
        'end_sec' => $currentSec + $etaSec,
      ];
      if ($requiresDatasetLock && $datasetId > 0) {
        $busyDatasets[$datasetId] = true;
      }

      $scheduled = true;
    }

    if (!$pending) {
      break;
    }

    if (!$running) {
      break;
    }

    $nextEnd = null;
    foreach ($running as $slot) {
      $endSec = (int)($slot['end_sec'] ?? 0);
      $nextEnd = $nextEnd === null ? $endSec : min($nextEnd, $endSec);
    }
    if ($nextEnd === null) {
      break;
    }

    $currentSec = max($currentSec, (int)$nextEnd);
    $remaining = [];
    $freedDatasets = [];
    foreach ($running as $slot) {
      if ((int)($slot['end_sec'] ?? 0) <= $currentSec) {
        if (!empty($slot['dataset_lock'])) {
          $freedDatasets[(int)($slot['dataset_id'] ?? 0)] = true;
        }
        continue;
      }
      $remaining[] = $slot;
    }
    $running = $remaining;
    foreach ($freedDatasets as $datasetId => $_) {
      unset($busyDatasets[(int)$datasetId]);
    }

    if (!$scheduled && !$running && $pending) {
      // Safety valve: if somehow nothing can run, let the next pending op start.
      $currentSec += 1;
    }
  }

  $ordered = [];
  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $ordered[] = $byId[$id] ?? $row;
  }

  return $ordered;
}

function ops_has_dedicated_worker_lane(string $opType): bool
{
  return ops_worker_lane_for_op_type($opType) !== 'dataset';
}

function ops_queue_rows_share_wait_context(array $a, array $b): bool
{
  $laneA = (string)($a['queue_lane'] ?? ops_worker_lane_for_op_type((string)($a['op_type'] ?? '')));
  $laneB = (string)($b['queue_lane'] ?? ops_worker_lane_for_op_type((string)($b['op_type'] ?? '')));
  if ($laneA !== 'dataset' || $laneB !== 'dataset') {
    return $laneA === $laneB;
  }
  return true;
}

function ops_running_rows_for_worker_capacity(array $runningRows): array
{
  $runningRows = array_values(array_filter($runningRows, 'is_array'));
  if (!$runningRows) {
    return [];
  }

  $runningIds = [];
  $runningPipelineDatasets = [];
  foreach ($runningRows as $row) {
    $id = (int)($row['id'] ?? 0);
    if ($id > 0) {
      $runningIds[$id] = true;
    }
    if ((string)($row['op_type'] ?? '') === 'run_pipeline') {
      $datasetId = (int)($row['dataset_id'] ?? 0);
      if ($datasetId > 0) {
        $runningPipelineDatasets[$datasetId] = true;
      }
    }
  }

  $effective = [];
  foreach ($runningRows as $row) {
    $parentId = ops_detect_pipeline_parent_id($row);
    if ($parentId > 0 && isset($runningIds[$parentId])) {
      continue;
    }

    $datasetId = (int)($row['dataset_id'] ?? 0);
    if (
      $parentId <= 0
      && $datasetId > 0
      && (string)($row['op_type'] ?? '') !== 'run_pipeline'
      && isset($runningPipelineDatasets[$datasetId])
    ) {
      continue;
    }

    $effective[] = $row;
  }

  return $effective;
}

function ops_json_params_from_row(array $row): array
{
  $params = json_decode((string)($row['params_json'] ?? '{}'), true);
  return is_array($params) ? $params : [];
}

function ops_feed_ids_from_row_params(array $row): array
{
  $params = ops_json_params_from_row($row);
  $raw = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
  if (!is_array($raw)) {
    return [];
  }

  $feedIds = array_values(array_unique(array_filter(array_map(
    static fn($value): int => is_numeric($value) ? (int)$value : 0,
    $raw
  ), static fn(int $value): bool => $value > 0)));
  sort($feedIds, SORT_NUMERIC);
  return $feedIds;
}

function ops_feed_push_rows_conflict(array $candidate, array $running): bool
{
  $candidateOpType = (string)($candidate['op_type'] ?? '');
  $runningOpType = (string)($running['op_type'] ?? '');
  if ($candidateOpType === '' || $candidateOpType !== $runningOpType) {
    return false;
  }
  if (!in_array($candidateOpType, ['ozon_push_selected_feeds', 'wb_push_selected_feeds', 'yandex_push_selected_feeds'], true)) {
    return false;
  }
  if ((int)($candidate['connection_id'] ?? 0) !== (int)($running['connection_id'] ?? 0)) {
    return false;
  }

  $candidateFeedIds = ops_feed_ids_from_row_params($candidate);
  $runningFeedIds = ops_feed_ids_from_row_params($running);
  if (!$candidateFeedIds || !$runningFeedIds) {
    return false;
  }

  return (bool)array_intersect($candidateFeedIds, $runningFeedIds);
}

function ops_list_active_global(int $limit = 20): array
{
  $rows = ops_fetch_active_queue_rows(max($limit, 50));
  $scheduled = ops_schedule_queue($rows);
  $visible = ops_collapse_pipeline_children($scheduled);
  return array_slice($visible, 0, $limit);
}

function ops_queue_summary(?int $futureDatasetId = null, ?string $futureOpType = null): array
{
  $active = ops_list_active_global(50);
  $datasetBlocker = null;
  $futureRequiresDatasetLock = $futureOpType !== null && $futureOpType !== ''
    ? ops_op_type_requires_dataset_lock($futureOpType)
    : true;
  if ($futureRequiresDatasetLock && $futureDatasetId !== null && $futureDatasetId > 0) {
    foreach ($active as $row) {
      if (
        (int)($row['dataset_id'] ?? 0) === $futureDatasetId
        && (string)($row['status'] ?? '') === 'running'
        && ops_row_requires_dataset_lock($row)
      ) {
        $datasetBlocker = $row;
        break;
      }
    }
  }
  $futureId = 2147483647;
  $futureRow = null;
  if ($futureDatasetId !== null && $futureDatasetId > 0) {
    $futureRow = [
      'id' => $futureId,
      'dataset_id' => $futureDatasetId,
      'op_type' => $futureOpType !== null && $futureOpType !== '' ? $futureOpType : 'queued_op',
      'status' => 'queued',
      'created_by' => ops_current_actor(),
      'created_at' => date('Y-m-d H:i:s'),
      'started_at' => null,
      'cancel_requested_at' => null,
      'progress_done' => 0,
      'progress_total' => 0,
      'progress_stage' => null,
      'progress_msg' => null,
      'original_filename' => '',
      'elapsed_sec' => 0,
      'eta_sec' => ops_estimate_duration_sec($futureOpType !== null && $futureOpType !== '' ? $futureOpType : 'queued_op'),
      'cancel_requested' => false,
      'queue_wait_sec' => 0,
    ];
  }

  $scheduled = ops_schedule_queue($active, $futureRow);
  $target = null;
  foreach ($scheduled as $row) {
    if ((int)($row['id'] ?? 0) === $futureId) {
      $target = $row;
      break;
    }
  }

  $estimatedWait = (int)($target['queue_wait_sec'] ?? 0);
  $ahead = 0;
  if ($target !== null) {
    foreach ($scheduled as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id === $futureId) continue;
      if (!ops_queue_rows_share_wait_context($target, $row)) continue;
      $rowWait = (int)($row['queue_wait_sec'] ?? 0);
      if ($rowWait < $estimatedWait) {
        $ahead++;
      }
    }
  } else {
    $ahead = count($active);
  }

  $blocker = null;
  if ($target !== null && $estimatedWait > 0) {
    foreach ($active as $row) {
      if (ops_queue_rows_share_wait_context($target, $row)) {
        $blocker = $row;
        break;
      }
    }
  }

  return [
    'ahead_count' => $ahead,
    'estimated_wait_sec' => $estimatedWait,
    'blocker' => ops_queue_public_row($blocker),
    'active' => array_values(array_filter(array_map('ops_queue_public_row', $active))),
    'will_wait' => $estimatedWait > 0,
    'worker_mode' => 'parallel_per_dataset_with_background_lanes',
    'max_parallel' => ops_worker_max_parallel(),
    'background_lanes' => ops_worker_dedicated_lane_capacities(),
    'dataset_blocked' => $datasetBlocker !== null,
    'dataset_blocker' => ops_queue_public_row($datasetBlocker),
    'future_dataset_id' => $futureDatasetId,
    'future_op_type' => $futureOpType,
  ];
}

function ops_queue_summary_for_existing_op(int $opId): array
{
  $rows = ops_list_active_global(100);
  $target = null;
  foreach ($rows as $row) {
    if ((int)($row['id'] ?? 0) === $opId) {
      $target = $row;
      break;
    }
  }

  $estimatedWait = (int)($target['queue_wait_sec'] ?? 0);
  $aheadCount = 0;
  if ($target !== null) {
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id === $opId) continue;
      if (!ops_queue_rows_share_wait_context($target, $row)) continue;
      $rowWait = (int)($row['queue_wait_sec'] ?? 0);
      if ($rowWait < $estimatedWait) {
        $aheadCount++;
      }
    }
  }

  $blocker = null;
  if ($target !== null && $estimatedWait > 0) {
    foreach ($rows as $row) {
      if ((int)($row['id'] ?? 0) === $opId) continue;
      if (ops_queue_rows_share_wait_context($target, $row)) {
        $blocker = $row;
        break;
      }
    }
  }

  return [
    'ahead_count' => $aheadCount,
    'estimated_wait_sec' => $estimatedWait,
    'blocker' => ops_queue_public_row($blocker),
  ];
}

/**
 * Атомарно “забираем” задачу из очереди.
 * Возвращает строку операции (row) или null.
 */
function ops_normalize_op_type_filter(array $opTypes): array
{
  $out = [];
  foreach ($opTypes as $opType) {
    $opType = trim((string)$opType);
    if ($opType !== '') {
      $out[$opType] = true;
    }
  }
  return array_keys($out);
}

function ops_queued_candidate_is_blocked(array $candidate, array $runningRows): bool
{
  $runningRows = ops_running_rows_for_worker_capacity($runningRows);
  $candidateOpType = (string)($candidate['op_type'] ?? '');
  $candidateLane = ops_worker_lane_for_op_type($candidateOpType);
  $candidateDatasetId = (int)($candidate['dataset_id'] ?? 0);
  $candidateRequiresDatasetLock = ops_row_requires_dataset_lock($candidate);

  if ($candidateLane !== 'dataset') {
    $runningInLane = 0;
    foreach ($runningRows as $row) {
      if (ops_worker_lane_for_op_type((string)($row['op_type'] ?? '')) !== $candidateLane) {
        continue;
      }
      if (ops_feed_push_rows_conflict($candidate, $row)) {
        return true;
      }
      $runningInLane++;
    }
    return $runningInLane >= ops_worker_lane_capacity($candidateLane);
  }

  $runningDatasetLane = 0;
  foreach ($runningRows as $row) {
    if (ops_worker_lane_for_op_type((string)($row['op_type'] ?? '')) !== 'dataset') {
      continue;
    }
    $runningDatasetLane++;
    if (
      $candidateRequiresDatasetLock
      && ops_row_requires_dataset_lock($row)
      && (int)($row['dataset_id'] ?? 0) === $candidateDatasetId
    ) {
      return true;
    }
  }
  return $runningDatasetLane >= ops_worker_max_parallel();
}

function ops_claim_next_queued(array $onlyOpTypes = [], array $excludeOpTypes = []): ?array {
  ops_table_ensure();
  $onlyOpTypes = ops_normalize_op_type_filter($onlyOpTypes);
  $excludeOpTypes = ops_normalize_op_type_filter($excludeOpTypes);
  $filters = '';
  $params = [];
  if ($onlyOpTypes) {
    $filters .= ' AND q.op_type IN (' . implode(',', array_fill(0, count($onlyOpTypes), '?')) . ')';
    array_push($params, ...$onlyOpTypes);
  }
  if ($excludeOpTypes) {
    $filters .= ' AND q.op_type NOT IN (' . implode(',', array_fill(0, count($excludeOpTypes), '?')) . ')';
    array_push($params, ...$excludeOpTypes);
  }

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("
      SELECT q.*
      FROM feedtools_operations q
      WHERE q.status = 'queued'
        {$filters}
      ORDER BY q.id ASC
      LIMIT 500
      FOR UPDATE
    ");
    $stmt->execute($params);
    $queuedRows = $stmt->fetchAll() ?: [];
    if (!$queuedRows) {
      $pdo->commit();
      return null;
    }

    $runningStmt = $pdo->query("
      SELECT *
      FROM feedtools_operations
      WHERE status = 'running'
      FOR UPDATE
    ");
    $runningRows = $runningStmt ? ($runningStmt->fetchAll() ?: []) : [];

    $row = null;
    foreach ($queuedRows as $candidate) {
      if (!ops_queued_candidate_is_blocked($candidate, $runningRows)) {
        $row = $candidate;
        break;
      }
    }

    if (!$row) {
      $pdo->commit();
      return null;
    }

    $pdo->prepare("
      UPDATE feedtools_operations
      SET status='running',
          started_at = COALESCE(started_at, NOW()),
          heartbeat_at = NOW()
      WHERE id = ?
    ")->execute([(int)$row['id']]);

    $pdo->commit();
    // перечитать (чтобы статус был running)
    return ops_get((int)$row['id']);
  } catch (Throwable $e) {
    try {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
    } catch (Throwable $rollbackError) {
      db_reconnect();
    }
    throw $e;
  }
}

function ops_try_claim_specific_queued(int $opId): ?array
{
  ops_table_ensure();
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("
      SELECT *
      FROM feedtools_operations
      WHERE id = ? AND status = 'queued'
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$opId]);
    $row = $stmt->fetch();
    if (!$row) {
      $pdo->commit();
      return null;
    }

    $runningStmt = $pdo->prepare("
      SELECT *
      FROM feedtools_operations
      WHERE id <> ?
        AND status = 'running'
      FOR UPDATE
    ");
    $runningStmt->execute([$opId]);
    $runningRows = $runningStmt->fetchAll() ?: [];

    if (ops_queued_candidate_is_blocked($row, $runningRows)) {
      $pdo->commit();
      return null;
    }

    $pdo->prepare("
      UPDATE feedtools_operations
      SET status='running',
          started_at = COALESCE(started_at, NOW()),
          heartbeat_at = NOW()
      WHERE id = ?
    ")->execute([$opId]);

    $pdo->commit();
    return ops_get($opId);
  } catch (Throwable $e) {
    try {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
    } catch (Throwable $rollbackError) {
      db_reconnect();
    }
    throw $e;
  }
}
