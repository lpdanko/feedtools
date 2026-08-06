<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/op_registry.php';
require_once __DIR__ . '/DatasetLock.php';
require_once __DIR__ . '/llm/OpenAIRequestLog.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/supplier_products_db_ops.php';

function op_run_one(array $cfg, array $opRow, callable $log): void {
  $opId = (int)$opRow['id'];
  $datasetId = (int)$opRow['dataset_id'];
  $opType = (string)$opRow['op_type'];

  $registry = op_registry();
  if (!isset($registry[$opType])) {
    throw new RuntimeException("Unknown op_type: {$opType}");
  }
  $opMeta = $registry[$opType];
  $isGlobalOp = !empty($opMeta['global_op']);

  // dataset
  if ($isGlobalOp && $datasetId <= 0) {
    $ds = [
      'id' => 0,
      'original_filename' => '',
      'stored_filename' => '',
      'offers_count' => 0,
    ];
  } else {
    $stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
    $stmt->execute([$datasetId]);
    $ds = $stmt->fetch();
    if (!$ds) throw new RuntimeException("Dataset not found: {$datasetId}");
  }

  $isSupplierProductsDataset = (!$isGlobalOp && supplier_products_is_dataset_row($ds));

  $params = [];
  if (!empty($opRow['params_json'])) $params = json_decode($opRow['params_json'], true) ?: [];

  $datasetLock = null;
  if (!$isGlobalOp && feedtools_dataset_lock_should_guard_inplace($params)) {
    $datasetLock = feedtools_dataset_lock_acquire($datasetId, $opId, $opType);
    $log("Dataset lock acquired for dataset #{$datasetId}\n");
  }

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $logFileAbs = $outDir . '/op.log';
  if (!is_file($logFileAbs)) file_put_contents($logFileAbs, "");

  // meta
  $meta = [
    'app' => 'feedtools',
    'meta_version' => 1,
    'op_id' => $opId,
    'dataset_id' => $datasetId,
    'op_type' => $opType,
    'status' => 'running',
    'created_at' => (string)($opRow['created_at'] ?? date('c')),
    'started_at' => date('c'),
    'finished_at' => null,
    'params' => $params,
    'input' => [
      'original_filename' => (string)($ds['original_filename'] ?? ''),
      'stored_filename' => (string)($ds['stored_filename'] ?? ''),
      'offers_count' => (int)($ds['offers_count'] ?? 0),
    ],
    'outputs' => new stdClass(),
  ];
  file_put_contents($outDir.'/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

  $handlerFile = $opMeta['handler_file'];
  $handlerFunc = $opMeta['handler_func'];
  if (!$isSupplierProductsDataset) {
    require_once $handlerFile;
  }

  $log("Starting op={$opType}\n");
  $log("Handler loaded: " . ($isSupplierProductsDataset ? 'supplier_products_db_router' : $handlerFunc) . "\n");

  $requestContext = ops_resolve_request_context($opRow);

  OpenAIRequestLog::setContext([
    'op_id' => $opId,
    'dataset_id' => $datasetId,
    'op_type' => $opType,
    'actor_user' => $requestContext['actor_user'] ?? null,
    'root_op_id' => (int)($requestContext['root_op_id'] ?? 0),
    'root_op_type' => $requestContext['root_op_type'] ?? null,
  ]);

  try {
    try {
      if ($isSupplierProductsDataset) {
        $outputs = supplier_products_run_db_operation($cfg, $ds, $opId, $params, $log, $opType, $opMeta);
      } else {
        $outputs = $handlerFunc($cfg, $ds, $opId, $params, $log);
      }
    } finally {
      OpenAIRequestLog::clearContext();
    }
  } finally {
    if (is_array($datasetLock) && !empty($datasetLock['owner_token'])) {
      feedtools_dataset_lock_release($datasetId, (string)$datasetLock['owner_token']);
      $log("Dataset lock released for dataset #{$datasetId}\n");
    }
  }

  $outputs['meta_json'] = rel_to_outputs($cfg, $outDir . '/meta.json');
  $outputs['op_log'] = rel_to_outputs($cfg, $logFileAbs);

  ops_set_outputs($opId, $outputs);

  // если операция положила summary (см. ниже стандарт) — сохраняем в БД
  if (isset($outputs['summary_json_inline']) && is_array($outputs['summary_json_inline'])) {
    ops_set_summary($opId, $outputs['summary_json_inline']);
    unset($outputs['summary_json_inline']);
    ops_set_outputs($opId, $outputs); // обновить outputs без inline
  }

  $meta['status'] = 'done';
  $meta['finished_at'] = date('c');
  $meta['outputs'] = $outputs;
  file_put_contents($outDir.'/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

  $log("Done.\n");
}
