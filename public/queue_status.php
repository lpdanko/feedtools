<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';

header('Content-Type: application/json; charset=utf-8');

$datasetId = isset($_REQUEST['dataset_id']) ? (int)$_REQUEST['dataset_id'] : 0;
$opType = trim((string)($_REQUEST['op_type'] ?? ''));

if ($datasetId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'bad dataset_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

$summary = ops_queue_summary($datasetId, $opType !== '' ? $opType : null);
echo json_encode($summary, JSON_UNESCAPED_UNICODE);
