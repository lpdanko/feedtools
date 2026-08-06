<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
register_shutdown_function(static function () use ($cfg): void {
  $err = error_get_last();
  if (!is_array($err)) {
    return;
  }
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) {
    return;
  }

  $dir = (string)($cfg['paths']['logs_dir'] ?? (__DIR__ . '/../storage/logs'));
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  $line = json_encode([
    'ts' => date('c'),
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'dataset_id' => (int)($_POST['dataset_id'] ?? 0),
    'op_type' => (string)($_POST['op_type'] ?? ''),
    'error' => $err,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  @file_put_contents(rtrim($dir, '/\\') . '/run_op_fatal.log', $line . "\n", FILE_APPEND);
});
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/op_params.php';
require_once __DIR__ . '/../app/supplier_products.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$datasetId = isset($_POST['dataset_id']) ? (int)$_POST['dataset_id'] : 0;
$opType = trim((string)($_POST['op_type'] ?? ''));

if ($opType === '') { http_response_code(400); exit('Bad op_type'); }

function run_op_safe_return_url(string $raw): string
{
  $raw = trim($raw);
  if ($raw === '' || str_contains($raw, "\n") || str_contains($raw, "\r")) {
    return '';
  }

  $path = '';
  $query = '';
  $parts = parse_url($raw);
  if (is_array($parts) && (isset($parts['scheme']) || isset($parts['host']))) {
    $host = strtolower((string)($parts['host'] ?? ''));
    $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $currentHost === '' || $host !== $currentHost) {
      return '';
    }
    $path = (string)($parts['path'] ?? '');
    $query = (string)($parts['query'] ?? '');
  } elseif (is_array($parts)) {
    $path = (string)($parts['path'] ?? $raw);
    $query = (string)($parts['query'] ?? '');
  }

  if ($path === '' || str_starts_with($path, '//')) {
    return '';
  }
  $base = basename($path);
  if (in_array($base, ['run_op.php', 'op_cancel.php'], true)) {
    return '';
  }
  if (!preg_match('~^/[A-Za-z0-9_./-]+$~', $path) && !preg_match('~^[A-Za-z0-9_./-]+$~', $path)) {
    return '';
  }

  return $path . ($query !== '' ? ('?' . $query) : '');
}

function run_op_url_with_query_param(string $url, string $key, string $value): string
{
  $sep = str_contains($url, '?') ? '&' : '?';
  return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value);
}

function run_op_short_dataset_location(int $datasetId, string $datasetViewPage, bool $isGlobalOp, array $query = []): string
{
  if ($isGlobalOp || $datasetId <= 0) {
    $base = 'op.php';
  } else {
    $base = $datasetViewPage;
    $query = ['id' => (string)$datasetId] + $query;
  }
  return $base . ($query ? ('?' . http_build_query($query)) : '');
}

function run_op_abort_before_create(string $message, int $datasetId, string $datasetViewPage, bool $isGlobalOp): void
{
  $returnUrl = run_op_safe_return_url((string)($_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
  if ($returnUrl === '' && !$isGlobalOp && $datasetId > 0) {
    $returnUrl = $datasetViewPage . '?id=' . urlencode((string)$datasetId);
  }
  if ($returnUrl !== '' && strlen($returnUrl) > 1800) {
    $returnUrl = !$isGlobalOp && $datasetId > 0
      ? run_op_short_dataset_location($datasetId, $datasetViewPage, $isGlobalOp)
      : '';
  }
  if ($returnUrl !== '') {
    header('Location: ' . run_op_url_with_query_param($returnUrl, 'inline_error', $message), true, 303);
    exit;
  }

  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo $message;
  exit;
}

function run_op_spawn_worker_detached(array $cfg, int $datasetId, int $opId): void
{
  if ($opId <= 0) {
    return;
  }

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $spawnLogAbs = $outDir . '/spawn.log';
  @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);
  if (!function_exists('exec')) {
    @file_put_contents($spawnLogAbs, "spawn skipped: exec disabled\n", FILE_APPEND);
    return;
  }

  $php = trim((string)($cfg['worker']['php_bin'] ?? PHP_BINARY));
  if ($php === '') {
    $php = 'php';
  }
  $script = trim((string)($cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php')));
  if ($script === '') {
    $script = __DIR__ . '/../bin/worker.php';
  }

  $cmd = 'nohup ' . escapeshellarg($php)
    . ' ' . escapeshellarg($script)
    . ' --op_id=' . (int)$opId
    . ' >> ' . escapeshellarg($spawnLogAbs)
    . ' 2>&1 < /dev/null & echo $!';

  $pidLines = [];
  $exitCode = 0;
  @exec($cmd, $pidLines, $exitCode);
  $pid = trim((string)($pidLines[0] ?? ''));

  try {
    ops_append_log_tail($opId, "spawnLogAbs: {$spawnLogAbs}\n", 200000);
    ops_append_log_tail($opId, "spawn_pid: " . ($pid !== '' ? $pid : 'unknown') . " exit_code={$exitCode}\n", 200000);
    ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
  } catch (Throwable $e) {
    // The queued operation remains visible even if spawn logging fails.
  }
}

function run_op_supplier_products_operation_is_supported(string $opType, array $opMeta): bool
{
  if ($opType === 'run_pipeline') {
    return true;
  }
  return trim((string)($opMeta['supplier_products_db_handler'] ?? '')) !== '';
}

$registry = op_registry();
if (!isset($registry[$opType])) {
  http_response_code(400);
  exit('Unknown op_type');
}
$opMeta = $registry[$opType];
$isGlobalOp = !empty($opMeta['global_op']);

if ($datasetId <= 0 && !$isGlobalOp) { http_response_code(400); exit('Bad dataset_id'); }
if ($datasetId <= 0 && $isGlobalOp) {
  $datasetId = feedtools_global_ops_dataset_id();
}

// dataset
if ($datasetId > 0) {
  $stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
  $stmt->execute([$datasetId]);
  $ds = $stmt->fetch();
  if (!$ds) { http_response_code(404); exit('Dataset not found'); }
} else {
  $ds = [
    'id' => 0,
    'original_filename' => '',
    'stored_filename' => '',
    'offers_count' => 0,
  ];
}

$datasetViewPage = 'view.php';
$isSupplierProductsDataset = $datasetId > 0 && is_array($ds) && supplier_products_is_dataset_row($ds);
if ($isSupplierProductsDataset) {
  $datasetViewPage = 'supplier_products_view.php';
}

if ($isSupplierProductsDataset) {
  if (!run_op_supplier_products_operation_is_supported($opType, $opMeta)) {
    http_response_code(400);
    exit('Operation is not available for supplier products');
  }
}

// params
$paramDefs = $registry[$opType]['params'] ?? [];
$params = op_params_normalize($paramDefs, $_POST);
$actor = function_exists('ft_current_user') ? ft_current_user() : ops_current_actor();
$actor = is_string($actor) ? trim($actor) : '';
if ($actor !== '') {
  $params['_actor'] = $actor;
}
$connectionBoundOps = [
  'ozon_sync_products' => true,
  'ozon_sync_actions' => true,
  'ozon_sync_analytics' => true,
  'wb_sync_analytics' => true,
  'wb_sync_promotions' => true,
  'ozon_push_selected_feeds' => true,
  'wb_push_selected_feeds' => true,
  'yandex_push_selected_feeds' => true,
  'wb_sync_products' => true,
  'supplier_push_ozon_content' => true,
  'supplier_push_wb_content' => true,
];
if (isset($connectionBoundOps[$opType]) && (int)($params['connection_id'] ?? 0) <= 0) {
  http_response_code(400);
  exit('connection_id is required for marketplace service operations');
}
$runInline = !empty($_POST['run_inline']) && (string)$_POST['run_inline'] !== '0';
$inlineAllowedOps = [
  'set_ozon_category' => true,
  'set_wb_category' => true,
  'set_wb_subject' => true,
  'ozon_sync_actions' => true,
  'wb_sync_promotions' => true,
];

// selected offers (optional)
// Frontend sends JSON array string in offer_ids_json. Offer IDs are strings and may contain spaces/symbols.
if (!empty($_POST['offer_ids_json']) && is_string($_POST['offer_ids_json'])) {
  $raw = trim((string)$_POST['offer_ids_json']);
  if ($raw !== '') {
    $arr = json_decode($raw, true);
    if (is_array($arr)) {
      $clean = [];
      foreach ($arr as $v) {
        $s = trim((string)$v);
        if ($s !== '') $clean[] = $s;
      }
      // de-dup + keep order
      $clean = array_values(array_unique($clean));
      if ($clean) {
        $params['offer_ids'] = $clean;
      }
    }
  }
}

if ($isSupplierProductsDataset && $opType === 'gpt_generate_cover_image') {
  require_once __DIR__ . '/../app/supplier_products_db_ops.php';
  $preflight = supplier_products_db_cover_features_preflight($cfg, $ds, $params);
  $confirmed = ((string)($_POST['cover_preflight_confirmed'] ?? '') === '1');
  if (!empty($preflight['blocked'])) {
    run_op_abort_before_create((string)$preflight['message'], $datasetId, $datasetViewPage, $isGlobalOp);
  }
  if (!empty($preflight['warning']) && !$confirmed) {
    run_op_abort_before_create((string)$preflight['message'], $datasetId, $datasetViewPage, $isGlobalOp);
  }
  $params['_cover_preflight'] = [
    'total_products' => (int)($preflight['total_products'] ?? 0),
    'products_with_features' => (int)($preflight['products_with_features'] ?? 0),
    'products_missing_features' => (int)($preflight['products_missing_features'] ?? 0),
    'products_with_cover_design' => (int)($preflight['products_with_cover_design'] ?? 0),
    'products_missing_cover_design' => (int)($preflight['products_missing_cover_design'] ?? 0),
    'will_run_products' => (int)($preflight['will_run_products'] ?? 0),
  ];
}

// server-side duplicate guard: protects from browser retries / duplicate submits
$recentDuplicate = ops_find_recent_duplicate($datasetId, $opType, $params, 10);
if ($recentDuplicate) {
  $existingOpId = (int)($recentDuplicate['id'] ?? 0);
  if ($existingOpId > 0) {
    $returnUrl = run_op_safe_return_url((string)($_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
    if ($runInline && isset($inlineAllowedOps[$opType])) {
      if ($isGlobalOp) {
        header('Location: op.php?id=' . urlencode((string)$existingOpId), true, 303);
      } else {
        header('Location: ' . $datasetViewPage . '?id=' . urlencode((string)$datasetId) . '&inline_op=' . urlencode((string)$existingOpId), true, 303);
      }
      exit;
    }

    $redir = 'op.php?id=' . urlencode((string)$existingOpId);
    $wantReport = !empty($registry[$opType]['redirect_to_report']);
    if ($wantReport) {
      $redir .= '&auto_report=1';
    }
    header('Location: ' . $redir, true, 303);
    exit;
  }
}

$returnUrl = run_op_safe_return_url((string)($_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
if ($returnUrl !== '') {
  $params['_return_url'] = $returnUrl;
}

// 1) enqueue
$opId = ops_create($datasetId, $opType, $params, $actor !== '' ? $actor : null);



// немного лога в карточку операции
ops_append_log_tail($opId, "Queued.\n", 200000);

// прогресс (если ты добавил колонки и функцию)
if (function_exists('ops_update_progress')) {
  $total = (int)($ds['offers_count'] ?? 0);
  ops_update_progress($opId, 0, $total, 'queued', 'Queued');
}

if ($runInline && isset($inlineAllowedOps[$opType])) {
  require_once __DIR__ . '/../app/op_runner.php';

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $logFileAbs = $outDir . '/op.log';
  if (!is_file($logFileAbs)) {
    file_put_contents($logFileAbs, '');
  }

  $log = static function (string $msg) use ($opId, $logFileAbs): void {
    file_put_contents($logFileAbs, $msg, FILE_APPEND);
    try {
      ops_append_log_tail($opId, $msg, 200000);
    } catch (Throwable $e) {
      // file log remains the source of truth if DB append fails
    }
  };

  ops_set_status($opId, 'running');
  ops_update_progress($opId, 0, (int)($ds['offers_count'] ?? 0), 'start', 'Started');

  try {
    $op = ops_get($opId);
    if (!$op) {
      throw new RuntimeException('Operation not found after create');
    }

    op_run_one($cfg, $op, $log);
    ops_set_status($opId, 'done');

    $total = (int)($ds['offers_count'] ?? 0);
    if ($total > 0) {
      ops_update_progress($opId, $total, $total, 'done', 'Done');
    } else {
      ops_update_progress($opId, 0, 0, 'done', 'Done');
    }

    if ($isGlobalOp) {
      header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
    } else {
      header('Location: ' . $datasetViewPage . '?id=' . urlencode((string)$datasetId) . '&inline_op=' . urlencode((string)$opId), true, 303);
    }
    exit;
  } catch (Throwable $e) {
    $log("ERROR: " . $e->getMessage() . "\n");
    ops_set_status($opId, 'error', null, $e->getMessage());
    ops_update_progress($opId, 0, (int)($ds['offers_count'] ?? 0), 'error', $e->getMessage());
    if ($isGlobalOp) {
      header(
        'Location: op.php?id=' . urlencode((string)$opId)
        . '&inline_error=' . urlencode($e->getMessage()),
        true,
        303
      );
    } else {
      header(
        'Location: ' . $datasetViewPage . '?id=' . urlencode((string)$datasetId)
        . '&inline_op=' . urlencode((string)$opId)
        . '&inline_error=' . urlencode($e->getMessage()),
        true,
        303
      );
    }
    exit;
  }
}

// 2) redirect to op page (НЕ ждём выполнения)
$redir = "op.php?id=" . urlencode((string)$opId);

$wantReport = !empty($registry[$opType]['redirect_to_report']);
if ($wantReport) {
  $redir .= "&auto_report=1";
}

// 3) auto-spawn worker. On PHP-FPM, detach after sending the redirect so a
// long GPT operation cannot keep /run_op.php open until nginx returns 502.
if (!empty($cfg['worker']['auto_spawn']) && function_exists('fastcgi_finish_request')) {
  header("Location: " . $redir, true, 303);
  if (function_exists('session_write_close')) {
    @session_write_close();
  }
  fastcgi_finish_request();
  run_op_spawn_worker_detached($cfg, $datasetId, $opId);
  exit;
}

if (!empty($cfg['worker']['auto_spawn'])) {
  run_op_spawn_worker_detached($cfg, $datasetId, $opId);
}

header("Location: " . $redir, true, 303);
exit;
