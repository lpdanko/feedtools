<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';

// runner (ты уже добавлял на прошлых шагах)
require_once __DIR__ . '/../app/op_runner.php';

/**
 * Fallback: если ты ещё не добавил эти функции в app/ops.php,
 * воркер сам их определит, чтобы не падать.
 */
if (!function_exists('ops_update_progress')) {
  function ops_update_progress(
    int $opId,
    int $done,
    int $total,
    ?string $stage = null,
    ?string $msg = null
  ): void {
    db()->prepare("
      UPDATE feedtools_operations
      SET
        progress_done = ?,
        progress_total = ?,
        progress_stage = ?,
        progress_msg = ?,
        heartbeat_at = NOW()
      WHERE id = ?
    ")->execute([$done, $total, $stage, $msg, $opId]);
  }
}

if (!function_exists('ops_set_summary')) {
  function ops_set_summary(int $opId, array $summary): void {
    db()->prepare("
      UPDATE feedtools_operations
      SET summary_json = ?
      WHERE id = ?
    ")->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), $opId]);
  }
}

if (!function_exists('ops_claim_next_queued')) {
  function ops_claim_next_queued(array $onlyOpTypes = [], array $excludeOpTypes = []): ?array {
    $pdo = db();
    $filters = '';
    $params = [];
    $onlyOpTypes = worker_parse_op_type_list($onlyOpTypes);
    $excludeOpTypes = worker_parse_op_type_list($excludeOpTypes);
    if ($onlyOpTypes) {
      $filters .= ' AND op_type IN (' . implode(',', array_fill(0, count($onlyOpTypes), '?')) . ')';
      array_push($params, ...$onlyOpTypes);
    }
    if ($excludeOpTypes) {
      $filters .= ' AND op_type NOT IN (' . implode(',', array_fill(0, count($excludeOpTypes), '?')) . ')';
      array_push($params, ...$excludeOpTypes);
    }
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("
        SELECT * FROM feedtools_operations
        WHERE status='queued'
          {$filters}
          AND NOT EXISTS (
            SELECT 1
            FROM feedtools_operations r
            WHERE r.dataset_id = feedtools_operations.dataset_id
              AND r.status = 'running'
              AND (
                (feedtools_operations.op_type = 'master_mobile_feed' AND r.op_type = 'master_mobile_feed')
                OR (feedtools_operations.op_type <> 'master_mobile_feed' AND r.op_type <> 'master_mobile_feed')
              )
          )
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
      ");
      $stmt->execute($params);
      $row = $stmt->fetch();

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

      return ops_get((int)$row['id']);
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }
}

function parse_args(array $argv): array {
  $out = [
    'loop' => false,
    'op_id' => null,
    'max_parallel' => null,
    'only_op_types' => [],
    'exclude_op_types' => [],
  ];

  foreach ($argv as $a) {
    if ($a === '--loop') $out['loop'] = true;
    if (preg_match('/^--op_id=(\d+)$/', $a, $m)) $out['op_id'] = (int)$m[1];
    if (preg_match('/^--max_parallel=(\d+)$/', $a, $m)) $out['max_parallel'] = max(1, (int)$m[1]);
    if (preg_match('/^--only-op-types?=(.+)$/', $a, $m)) $out['only_op_types'] = worker_parse_op_type_list($m[1]);
    if (preg_match('/^--exclude-op-types?=(.+)$/', $a, $m)) $out['exclude_op_types'] = worker_parse_op_type_list($m[1]);
  }
  if (!$out['only_op_types']) {
    $out['only_op_types'] = worker_parse_op_type_list(getenv('WORKER_ONLY_OP_TYPES') ?: '');
  }
  if (!$out['exclude_op_types']) {
    $out['exclude_op_types'] = worker_parse_op_type_list(getenv('WORKER_EXCLUDE_OP_TYPES') ?: '');
  }
  return $out;
}

function worker_parse_op_type_list($value): array
{
  if (is_array($value)) {
    $parts = $value;
  } else {
    $parts = preg_split('/[,\s]+/', trim((string)$value)) ?: [];
  }
  $seen = [];
  $out = [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '' || isset($seen[$part])) {
      continue;
    }
    $seen[$part] = true;
    $out[] = $part;
  }
  return $out;
}

function ds_get(int $datasetId): array {
  $st = db()->prepare("SELECT * FROM feedtools_datasets WHERE id=?");
  $st->execute([$datasetId]);
  $ds = $st->fetch();
  if (!$ds) throw new RuntimeException("Dataset not found: {$datasetId}");
  return $ds;
}

function get_total_from_dataset(int $datasetId): int {
  $st = db()->prepare("SELECT offers_count FROM feedtools_datasets WHERE id=?");
  $st->execute([$datasetId]);
  $r = $st->fetch();
  return $r ? (int)$r['offers_count'] : 0;
}

/**
 * Пишет строку и в файл, и в log_tail операции.
 */
function worker_append_log(array $cfg, int $datasetId, int $opId, string $msg): void {
  $outDir = op_output_dir($cfg, $datasetId, $opId);
  try {
    ensure_dir($outDir);
    $logFileAbs = $outDir . '/op.log';
    if (!is_file($logFileAbs)) {
      file_put_contents($logFileAbs, "");
    }

    file_put_contents($logFileAbs, $msg, FILE_APPEND);
  } catch (Throwable $e) {
    // The DB log below is enough for the UI. A broken output directory must not
    // prevent the worker from marking an operation as failed/cancelled.
  }

  try {
    ops_append_log_tail($opId, $msg, 200000);
  } catch (Throwable $e) {
    // если DB умерла — хотя бы файл лога останется
  }
}

function worker_is_cancel_requested(array $cfg, int $datasetId, int $opId): bool
{
  $flagAbs = op_output_dir($cfg, $datasetId, $opId) . '/cancel.flag';
  if (is_file($flagAbs)) {
    return true;
  }

  try {
    return ops_is_cancel_requested($opId);
  } catch (Throwable $e) {
    return false;
  }
}

function worker_finish_as_cancelled(array $cfg, array $op, string $message = 'Cancelled by user'): void
{
  $opId = (int)$op['id'];
  $datasetId = (int)$op['dataset_id'];
  $fresh = ops_get($opId) ?: $op;
  $done = (int)($fresh['progress_done'] ?? 0);
  $total = (int)($fresh['progress_total'] ?? 0);

  worker_append_log($cfg, $datasetId, $opId, "CANCELLED: {$message}\n");
  ops_mark_cancelled($opId, $message);
  ops_update_progress($opId, $done, $total, 'cancelled', $message);
  ops_set_runner_pid($opId, null);
  feedtools_dataset_lock_release_by_op($opId);
}

function worker_finish_as_crash(array $cfg, array $op, string $message = 'Worker process terminated unexpectedly'): void
{
  $opId = (int)$op['id'];
  $datasetId = (int)$op['dataset_id'];
  $fresh = ops_get($opId) ?: $op;
  $done = (int)($fresh['progress_done'] ?? 0);
  $total = (int)($fresh['progress_total'] ?? 0);

  worker_append_log($cfg, $datasetId, $opId, "ERROR: {$message}\n");
  ops_set_status($opId, 'error', null, $message);
  ops_update_progress($opId, $done, $total, 'error', $message);
  ops_set_runner_pid($opId, null);
  feedtools_dataset_lock_release_by_op($opId);
}

/**
 * Обрабатывает одну операцию (уже в статусе running) внутри дочернего процесса.
 */
function run_claimed_op_direct(array $cfg, array $op): void {
  $opId = (int)$op['id'];
  $datasetId = (int)$op['dataset_id'];

  $log = function(string $msg) use ($cfg, $datasetId, $opId): void {
    worker_append_log($cfg, $datasetId, $opId, $msg);
  };

  // стартовый прогресс
  $total = get_total_from_dataset($datasetId);
  ops_update_progress($opId, 0, $total, 'start', 'Started');

  try {
    // op_runner сам загрузит handler и запишет outputs/meta/op_log
    op_run_one($cfg, $op, $log);

    if (worker_is_cancel_requested($cfg, $datasetId, $opId)) {
      worker_finish_as_cancelled($cfg, $op, 'Cancelled by user');
    } else {
      ops_set_status($opId, 'done', null, null);
      ops_set_runner_pid($opId, null);

      $fresh = ops_get($opId) ?: $op;
      $freshDone = (int)($fresh['progress_done'] ?? 0);
      $freshTotal = (int)($fresh['progress_total'] ?? 0);
      $freshStage = trim((string)($fresh['progress_stage'] ?? ''));
      $freshMsg = trim((string)($fresh['progress_msg'] ?? ''));

      // Если handler сам выставил финальный прогресс, не перетираем его
      // количеством товаров датасета. Это важно для операций, где прогресс
      // считается не по товарам, а по категориям, файлам или API-пакетам.
      if ($freshStage === 'done' && $freshTotal > 0) {
        ops_update_progress($opId, $freshDone, $freshTotal, 'done', $freshMsg !== '' ? $freshMsg : 'Done');
      } elseif ($freshTotal > 0 && $total > 0 && $freshTotal !== $total) {
        ops_update_progress($opId, $freshTotal, $freshTotal, 'done', $freshMsg !== '' ? $freshMsg : 'Done');
      } elseif ($total > 0) {
        ops_update_progress($opId, $total, $total, 'done', 'Done');
      } else {
        ops_update_progress(
          $opId,
          $freshDone,
          $freshTotal,
          'done',
          $freshMsg !== '' ? $freshMsg : 'Done'
        );
      }
    }
  } catch (Throwable $e) {
    if (worker_is_cancel_requested($cfg, $datasetId, $opId)) {
      worker_finish_as_cancelled($cfg, $op, 'Cancelled by user');
      return;
    }

    $log("ERROR: " . $e->getMessage() . "\n");
    ops_set_status($opId, 'error', null, $e->getMessage());
    ops_set_runner_pid($opId, null);
    $fresh = ops_get($opId) ?: $op;
    ops_update_progress(
      $opId,
      (int)($fresh['progress_done'] ?? 0),
      (int)($fresh['progress_total'] ?? 0),
      'error',
      $e->getMessage()
    );
  }
}

/**
 * Обрабатывает операцию под supervision: можно быстро послать SIGTERM/SIGKILL,
 * даже если handler сам редко проверяет cancel.flag.
 */
function run_claimed_op(array $cfg, array $op): void {
  $opId = (int)$op['id'];
  $datasetId = (int)$op['dataset_id'];

  if (worker_is_cancel_requested($cfg, $datasetId, $opId)) {
    worker_finish_as_cancelled($cfg, $op, 'Cancelled before start');
    return;
  }

  if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('pcntl_waitpid')) {
    run_claimed_op_direct($cfg, $op);
    return;
  }

  $pid = pcntl_fork();
  if ($pid === -1) {
    run_claimed_op_direct($cfg, $op);
    return;
  }

  if ($pid === 0) {
    db_reconnect();
    ops_set_runner_pid($opId, getmypid());
    run_claimed_op_direct($cfg, $op);
    exit(0);
  }

  db_reconnect();
  ops_set_runner_pid($opId, $pid);

  $termSentAt = null;
  $killSent = false;

  while (true) {
    $waitPid = pcntl_waitpid($pid, $status, WNOHANG);
    if ($waitPid === -1 || $waitPid > 0) {
      break;
    }

    $fresh = ops_get($opId) ?: $op;
    $cancelRequested = !empty($fresh['cancel_requested_at']) || worker_is_cancel_requested($cfg, $datasetId, $opId);

    if ($cancelRequested) {
      if ($termSentAt === null) {
        @posix_kill($pid, SIGTERM);
        $termSentAt = microtime(true);
        worker_append_log($cfg, $datasetId, $opId, "Cancel signal sent to worker PID {$pid}\n");
      } elseif (!$killSent && (microtime(true) - $termSentAt) >= 5.0) {
        @posix_kill($pid, SIGKILL);
        $killSent = true;
        worker_append_log($cfg, $datasetId, $opId, "Force kill sent to worker PID {$pid}\n");
      }
    }

    usleep(250000);
  }

  ops_set_runner_pid($opId, null);

  $fresh = ops_get($opId) ?: $op;
  $status = trim((string)($fresh['status'] ?? ''));
  $cancelRequested = !empty($fresh['cancel_requested_at']) || worker_is_cancel_requested($cfg, $datasetId, $opId);

  if ($cancelRequested) {
    if (!in_array($status, ['done', 'error', 'cancelled'], true)) {
      worker_finish_as_cancelled($cfg, $op, 'Cancelled by user');
    }
    return;
  }

  if ($status === 'running') {
    worker_finish_as_crash($cfg, $op, 'Worker process terminated unexpectedly');
  }
}

function worker_reap_monitors(array &$monitors): void
{
  if (!function_exists('pcntl_waitpid')) {
    return;
  }

  while (true) {
    $status = 0;
    $pid = pcntl_waitpid(-1, $status, WNOHANG);
    if ($pid <= 0) {
      break;
    }
    unset($monitors[$pid]);
  }
}

function worker_op_lane(array $op): string
{
  if (function_exists('ops_worker_lane_for_op_type')) {
    return ops_worker_lane_for_op_type((string)($op['op_type'] ?? ''));
  }
  return ((string)($op['op_type'] ?? '') === 'master_mobile_feed') ? 'supplier_feed' : 'dataset';
}

function worker_lane_running_count(array $monitors, string $lane): int
{
  $count = 0;
  foreach ($monitors as $monitor) {
    if ((string)($monitor['lane'] ?? 'dataset') === $lane) {
      $count++;
    }
  }
  return $count;
}

function worker_lane_op_types(string $lane): array
{
  if (function_exists('ops_worker_op_types_for_lane')) {
    return ops_worker_op_types_for_lane($lane);
  }
  return $lane === 'supplier_feed' ? ['master_mobile_feed'] : [];
}

function worker_dedicated_lane_capacities(array $cfg = []): array
{
  if (function_exists('ops_worker_dedicated_lane_capacities')) {
    $capacities = ops_worker_dedicated_lane_capacities();
  } else {
    $capacities = ['supplier_feed' => 1];
  }

  $workerCfg = is_array($cfg['worker'] ?? null) ? $cfg['worker'] : [];
  $map = [
    'price_tool' => 'price_tool_max_parallel',
    'wb_promotions' => 'wb_promotions_max_parallel',
    'marketplace_data' => 'marketplace_data_max_parallel',
    'supplier_feed' => 'supplier_feed_max_parallel',
  ];
  foreach ($map as $lane => $key) {
    if (array_key_exists($key, $workerCfg)) {
      $capacities[$lane] = max(0, min(4, (int)$workerCfg[$key]));
    }
  }
  return $capacities;
}

function worker_dedicated_op_types(): array
{
  if (function_exists('ops_worker_dedicated_op_types')) {
    return ops_worker_dedicated_op_types();
  }
  return ['master_mobile_feed'];
}

function worker_filter_allowed_op_types(array $candidateTypes, array $onlyOpTypes, array $excludeOpTypes): array
{
  $candidateTypes = worker_parse_op_type_list($candidateTypes);
  $onlyOpTypes = worker_parse_op_type_list($onlyOpTypes);
  $excludeOpTypes = array_fill_keys(worker_parse_op_type_list($excludeOpTypes), true);
  if (!$candidateTypes) {
    return [];
  }

  if ($onlyOpTypes) {
    $onlySet = array_fill_keys($onlyOpTypes, true);
    $candidateTypes = array_values(array_filter(
      $candidateTypes,
      static fn(string $opType): bool => isset($onlySet[$opType])
    ));
  }

  if ($excludeOpTypes) {
    $candidateTypes = array_values(array_filter(
      $candidateTypes,
      static fn(string $opType): bool => !isset($excludeOpTypes[$opType])
    ));
  }

  return $candidateTypes;
}

function worker_claim_next_for_lane(string $lane, array $onlyOpTypes, array $excludeOpTypes): ?array
{
  if ($lane === 'dataset') {
    $dedicated = worker_dedicated_op_types();
    $effectiveExclude = array_values(array_unique(array_merge(
      worker_parse_op_type_list($excludeOpTypes),
      $dedicated
    )));
    return ops_claim_next_queued($onlyOpTypes, $effectiveExclude);
  }

  $laneTypes = worker_filter_allowed_op_types(worker_lane_op_types($lane), $onlyOpTypes, $excludeOpTypes);
  if (!$laneTypes) {
    return null;
  }
  return ops_claim_next_queued($laneTypes, []);
}

function worker_spawn_monitor(array $cfg, array $op, array &$monitors): void
{
  $lane = worker_op_lane($op);

  if (!function_exists('pcntl_fork')) {
    run_claimed_op($cfg, $op);
    return;
  }

  $pid = pcntl_fork();
  if ($pid === -1) {
    run_claimed_op($cfg, $op);
    return;
  }

  if ($pid === 0) {
    db_reconnect();
    run_claimed_op($cfg, $op);
    exit(0);
  }

  db_reconnect();
  $monitors[$pid] = [
    'op_id' => (int)($op['id'] ?? 0),
    'dataset_id' => (int)($op['dataset_id'] ?? 0),
    'lane' => $lane,
    'spawned_at' => time(),
  ];
}

function worker_recover_dead_running_ops(array $cfg, array $onlyOpTypes = [], array $excludeOpTypes = []): void
{
  static $lastCheckAt = 0;
  $now = time();
  if (($now - $lastCheckAt) < 15) {
    return;
  }
  $lastCheckAt = $now;

  $onlyOpTypes = worker_parse_op_type_list($onlyOpTypes);
  $excludeOpTypes = worker_parse_op_type_list($excludeOpTypes);
  $filters = '';
  $params = [];
  if ($onlyOpTypes) {
    $filters .= ' AND op_type IN (' . implode(',', array_fill(0, count($onlyOpTypes), '?')) . ')';
    array_push($params, ...$onlyOpTypes);
  }
  if ($excludeOpTypes) {
    $filters .= ' AND op_type NOT IN (' . implode(',', array_fill(0, count($excludeOpTypes), '?')) . ')';
    array_push($params, ...$excludeOpTypes);
  }

  $stmt = db()->prepare("
    SELECT *
    FROM feedtools_operations
    WHERE status = 'running'
      {$filters}
      AND (
        (runner_pid IS NOT NULL AND runner_pid > 0)
        OR (
          (runner_pid IS NULL OR runner_pid <= 0)
          AND COALESCE(heartbeat_at, started_at, created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        )
      )
    ORDER BY id ASC
    LIMIT 50
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll() ?: [];

  foreach ($rows as $row) {
    $pid = (int)($row['runner_pid'] ?? 0);
    if ($pid <= 0) {
      worker_finish_as_crash(
        $cfg,
        $row,
        'Worker process is not attached to this running operation; operation recovered as failed'
      );
      continue;
    }

    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
      continue;
    }
    $posixError = function_exists('posix_get_last_error') ? (int)posix_get_last_error() : 0;
    if ($posixError === 1) {
      continue;
    }

    worker_finish_as_crash(
      $cfg,
      $row,
      "Worker process PID {$pid} is not running; operation recovered as failed"
    );
  }
}

function worker_code_version_mtime(): int
{
  $paths = [
    __FILE__,
    __DIR__ . '/../.env',
    __DIR__ . '/../.env.local',
    __DIR__ . '/../app/config.php',
    __DIR__ . '/../app/config.local.php',
    __DIR__ . '/../app/env.php',
    __DIR__ . '/../app/ops.php',
    __DIR__ . '/../app/op_registry.php',
    __DIR__ . '/../app/op_runner.php',
    __DIR__ . '/../app/operations/normalize_param_values.php',
    __DIR__ . '/../app/supplier_products_db_ops.php',
    __DIR__ . '/../app/supplier_products_brand_ops.php',
    __DIR__ . '/../app/marketplace_brand_dictionary.php',
    __DIR__ . '/../app/supplier_products_marketplace_push.php',
    __DIR__ . '/../app/ozon_price_tool.php',
    __DIR__ . '/../app/ozon_fbo_tool.php',
    __DIR__ . '/../app/ozon_actions.php',
    __DIR__ . '/../app/operations/ozon_fbo_refresh.php',
    __DIR__ . '/../app/operations/ozon_push_selected_feeds.php',
    __DIR__ . '/../app/wildberries/WildberriesPriceTool.php',
    __DIR__ . '/../app/wb_promotions.php',
    __DIR__ . '/../app/operations/wb_sync_promotions.php',
    __DIR__ . '/../app/operations/wb_import_promotion_xlsx_folder.php',
    __DIR__ . '/../app/operations/wb_download_promotion_xlsx.php',
    __DIR__ . '/../app/operations/wb_push_selected_feeds.php',
    __DIR__ . '/../app/operations/wb_sync_products.php',
    __DIR__ . '/../app/master_mobile_admin.php',
    __DIR__ . '/../app/operations/master_mobile_feed.php',
  ];
  $max = 0;
  foreach ($paths as $path) {
    $mtime = is_file($path) ? (int)@filemtime($path) : 0;
    if ($mtime > $max) {
      $max = $mtime;
    }
  }
  return $max;
}

function worker_db_exception_is_retryable(PDOException $e): bool
{
  if (function_exists('ops_db_is_retryable') && ops_db_is_retryable($e)) {
    return true;
  }
  if (function_exists('db_connection_exception_is_retryable') && db_connection_exception_is_retryable($e)) {
    return true;
  }

  $driverCode = (int)($e->errorInfo[1] ?? $e->getCode());
  $msg = strtolower($e->getMessage());
  return in_array($driverCode, [2002, 2003, 2006, 2013], true)
    || str_contains($msg, 'connection refused')
    || str_contains($msg, 'server has gone away')
    || str_contains($msg, 'lost connection');
}

function worker_run_loop(array $cfg, int $maxParallel, array $onlyOpTypes = [], array $excludeOpTypes = []): void
{
  $monitors = [];
  $startedCodeMtime = worker_code_version_mtime();
  $reloadRequested = false;
  $nextReloadCheckAt = 0;

  do {
    try {
      worker_reap_monitors($monitors);
      worker_recover_dead_running_ops($cfg, $onlyOpTypes, $excludeOpTypes);

      $now = time();
      if (!$reloadRequested && $now >= $nextReloadCheckAt) {
        $nextReloadCheckAt = $now + 2;
        if (worker_code_version_mtime() > $startedCodeMtime) {
          $reloadRequested = true;
        }
      }

      if ($reloadRequested && count($monitors) === 0) {
        return;
      }

      foreach (worker_dedicated_lane_capacities($cfg) as $lane => $capacity) {
        $lane = (string)$lane;
        $capacity = max(0, (int)$capacity);
        while (!$reloadRequested && $capacity > 0 && worker_lane_running_count($monitors, $lane) < $capacity) {
          $op = worker_claim_next_for_lane($lane, $onlyOpTypes, $excludeOpTypes);
          if (!$op) {
            break;
          }
          worker_spawn_monitor($cfg, $op, $monitors);
          worker_reap_monitors($monitors);
        }
      }

      while (!$reloadRequested && worker_lane_running_count($monitors, 'dataset') < $maxParallel) {
        $op = worker_claim_next_for_lane('dataset', $onlyOpTypes, $excludeOpTypes);
        if (!$op) {
          break;
        }
        worker_spawn_monitor($cfg, $op, $monitors);
        worker_reap_monitors($monitors);
      }

      usleep(count($monitors) > 0 ? 250000 : 400000);
    } catch (PDOException $e) {
      if (!worker_db_exception_is_retryable($e)) {
        throw $e;
      }
      db_reconnect();
      usleep(1000000);
    }
  } while (true);
}

// ---- main ----

$cfg = require __DIR__ . '/../app/config.php';
$args = parse_args($argv);
$maxParallel = max(1, (int)($args['max_parallel'] ?? ($cfg['worker']['max_parallel'] ?? 1)));

// Важно: воркер должен быть CLI
if (php_sapi_name() !== 'cli') {
  http_response_code(400);
  exit("CLI only\n");
}

if ($args['op_id'] !== null) {
  $opId = (int)$args['op_id'];
  $op = ops_try_claim_specific_queued($opId);
  if (!$op) {
    exit(0);
  }
  run_claimed_op($cfg, $op);
  exit(0);
}

if ($args['loop']) {
  worker_run_loop($cfg, $maxParallel, $args['only_op_types'], $args['exclude_op_types']);
  exit(0);
}

$op = ops_claim_next_queued($args['only_op_types'], $args['exclude_op_types']);
if (!$op) {
  exit(0);
}

run_claimed_op($cfg, $op);
