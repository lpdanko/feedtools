<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/DatasetLock.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$opId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($opId <= 0) { http_response_code(400); exit('Bad id'); }

$op = ops_get($opId);
if (!$op) { http_response_code(404); exit('Operation not found'); }

$datasetId = (int)$op['dataset_id'];

$outDir = op_output_dir($cfg, $datasetId, $opId);
try {
  ensure_dir($outDir);
  $flag = $outDir . '/cancel.flag';
  @file_put_contents($flag, "cancel requested at: " . date('c') . "\n");
} catch (Throwable $e) {
  // DB cancel is authoritative. The file flag is only a fast side-channel for
  // long-running handlers, so a permissions issue in outputs must not block UI cancel.
}

$result = ops_request_cancel($opId);
$fresh = $result['op'] ?? ops_get($opId) ?? $op;

$actor = trim((string)(ops_current_actor() ?? 'user'));
$status = trim((string)($fresh['status'] ?? ''));
$message = "Cancel requested by {$actor}.\n";

if (($result['action'] ?? '') === 'cancelled_queued') {
  $message = "Queued operation cancelled by {$actor} before start.\n";
} elseif ($status === 'cancelled') {
  $message = "Operation already cancelled.\n";
}

try {
  ops_append_log_tail($opId, $message, 200000);
} catch (Throwable $e) {
  // Keep cancellation usable even if log persistence is temporarily unavailable.
}

$runnerPid = (int)($fresh['runner_pid'] ?? 0);
$runnerAlive = false;
if ($runnerPid > 0 && function_exists('posix_kill')) {
  $runnerAlive = @posix_kill($runnerPid, 0);
}

if (($result['action'] ?? '') === 'requested_running' && ($runnerPid <= 0 || !$runnerAlive)) {
  $freshNow = ops_get($opId) ?: $fresh;
  $done = (int)($freshNow['progress_done'] ?? 0);
  $total = (int)($freshNow['progress_total'] ?? 0);

  ops_mark_cancelled($opId, 'Cancelled by user');
  ops_update_progress($opId, $done, $total, 'cancelled', 'Cancelled by user');
  feedtools_dataset_lock_release_by_op($opId);
  $runnerPid = 0;
}

if ($runnerPid > 0 && $runnerAlive && function_exists('posix_kill')) {
  @posix_kill($runnerPid, 15);
}

header("Location: op.php?id=" . urlencode((string)$opId));
exit;
