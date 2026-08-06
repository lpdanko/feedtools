<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/op_params.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$datasetId = isset($_POST['dataset_id']) ? (int)$_POST['dataset_id'] : 0;
if ($datasetId <= 0) { http_response_code(400); exit('Bad dataset_id'); }

$opType = 'update_stock_from_feed';

$registry = op_registry();
if (!isset($registry[$opType])) {
  http_response_code(500);
  exit('Operation not registered');
}

$stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$datasetId]);
$ds = $stmt->fetch();
if (!$ds) { http_response_code(404); exit('Dataset not found'); }

if (empty($_FILES['feed_xml']) || ($_FILES['feed_xml']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  exit('feed_xml file is required');
}

$ext = strtolower(pathinfo($_FILES['feed_xml']['name'] ?? '', PATHINFO_EXTENSION));
if ($ext !== 'xml') {
  http_response_code(400);
  exit('Only .xml allowed');
}

// params (только inplace из формы)
$paramDefs = $registry[$opType]['params'] ?? [];
$params = op_params_normalize($paramDefs, $_POST);

// временно feed_path пустой — заполним после сохранения файла
$params['feed_path'] = '__PENDING__';

$opId = ops_create($datasetId, $opType, $params);
ops_append_log_tail($opId, "Queued.\n", 200000);

$outDir = op_output_dir($cfg, $datasetId, $opId);
ensure_dir($outDir);

$dst = $outDir . '/stock_feed.xml';
if (!move_uploaded_file($_FILES['feed_xml']['tmp_name'], $dst)) {
  ops_set_status($opId, 'error', null, 'Failed to save uploaded feed XML');
  http_response_code(500);
  exit('Failed to save uploaded feed XML');
}

// обновляем params_json у операции (записываем реальный feed_path)
$params['feed_path'] = $dst;
db()->prepare("UPDATE feedtools_operations SET params_json = ? WHERE id = ?")
  ->execute([json_encode($params, JSON_UNESCAPED_UNICODE), (int)$opId]);

// прогресс
if (function_exists('ops_update_progress')) {
  $total = (int)($ds['offers_count'] ?? 0);
  ops_update_progress($opId, 0, $total, 'queued', 'Queued');
}

// spawn worker (как run_op.php)
if (!empty($cfg['worker']['auto_spawn'])) {
  $php = $cfg['worker']['php_bin'] ?? PHP_BINARY;
  $script = $cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php');

  $spawnLogAbs = $outDir . '/spawn.log';
  @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);

  $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($script)
       . ' --op_id=' . (int)$opId
       . ' > ' . escapeshellarg($spawnLogAbs) . ' 2>&1 &';

  @exec($cmd);

  ops_append_log_tail($opId, "spawnLogAbs: {$spawnLogAbs}\n", 200000);
  ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
}

header("Location: op.php?id=" . urlencode((string)$opId));
exit;
