<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../op_registry.php';
require_once __DIR__ . '/../op_runner.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';

function op_run_pipeline(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $datasetId = (int)$ds['id'];

  $opsJson = (string)($params['ops_json'] ?? '[]');
  $arr = json_decode($opsJson, true);
  if (!is_array($arr) || !$arr) {
    throw new RuntimeException("run_pipeline: ops_json is empty/invalid");
  }

  // offer_ids приходит из run_op.php (если выбранные товары есть)
  $offerIds = [];
  if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
    $offerIds = array_values(array_unique(array_map('strval', $params['offer_ids'])));
  }

  $globalModel = trim((string)($params['model'] ?? ''));
  $stepModels = [];
  $stepModelsRaw = json_decode((string)($params['op_models_json'] ?? '{}'), true);
  if (is_array($stepModelsRaw)) {
    foreach ($stepModelsRaw as $stepKey => $stepModel) {
      $stepKey = trim((string)$stepKey);
      $stepModel = trim((string)$stepModel);
      if ($stepKey !== '' && $stepModel !== '') {
        $stepModels[$stepKey] = mb_substr($stepModel, 0, 120);
      }
    }
  }
  $stepImageModels = [];
  $stepImageModelsRaw = json_decode((string)($params['op_image_models_json'] ?? '{}'), true);
  if (is_array($stepImageModelsRaw)) {
    foreach ($stepImageModelsRaw as $stepKey => $stepModel) {
      $stepKey = trim((string)$stepKey);
      $stepModel = trim((string)$stepModel);
      if ($stepKey !== '' && $stepModel !== '') {
        $stepImageModels[$stepKey] = mb_substr($stepModel, 0, 120);
      }
    }
  }
  $globalMaxItems = trim((string)($params['max_items'] ?? '0'));
  if ($globalMaxItems === '') $globalMaxItems = '0';

  $forceInplace = (string)($params['force_inplace'] ?? '1');
  $forceInplace = ($forceInplace !== '' && $forceInplace !== '0');

  $registry = op_registry();

  // защита от рекурсии
  $arr = array_values(array_filter($arr, fn($x) => is_string($x) && trim($x) !== '' && trim($x) !== 'run_pipeline'));

  if (!$arr) {
    throw new RuntimeException("run_pipeline: nothing to run after filtering");
  }

  $log("run_pipeline: dataset={$datasetId}, steps=" . count($arr) . ", selected_offers=" . count($offerIds) . "\n");

    $steps = [];
  $currentDatasetId = $datasetId;

  $totalCostLegacy = 0.0;
  $totalCostsByCurrency = [];
  $totalIn = 0;
  $totalCachedIn = 0;
  $totalOut = 0;

  $extractUsage = static function (?array $sum): array {
  if (!$sum) return ['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0,'cost'=>0.0,'cost_currency'=>'','cost_label'=>'','cost_usd'=>0.0];

  // Твой стандарт: summary.metrics.*
  if (isset($sum['metrics']) && is_array($sum['metrics'])) {
    $m = $sum['metrics'];
    $cost = (float)($m['cost'] ?? $m['cost_usd'] ?? 0.0);
    $currency = strtoupper(trim((string)($m['cost_currency'] ?? '')));
    return [
      'input_tokens' => (int)($m['tokens_in'] ?? 0),
      'cached_input_tokens' => (int)($m['tokens_cached_in'] ?? 0),
      'output_tokens' => (int)($m['tokens_out'] ?? 0),
      'cost' => $cost,
      'cost_currency' => $currency,
      'cost_label' => (string)($m['cost_label'] ?? ($currency !== '' ? openai_format_cost($cost, $currency) : '')),
      'cost_usd' => (float)($m['cost_usd'] ?? 0.0),
    ];
  }

  // fallback на случай будущих изменений
  if (isset($sum['usage']) && is_array($sum['usage'])) {
    $u = $sum['usage'];
    $cost = (float)($u['cost'] ?? $u['cost_usd'] ?? 0.0);
    $currency = strtoupper(trim((string)($u['cost_currency'] ?? '')));
    return [
      'input_tokens' => (int)($u['input_tokens'] ?? 0),
      'cached_input_tokens' => (int)($u['cached_input_tokens'] ?? 0),
      'output_tokens' => (int)($u['output_tokens'] ?? 0),
      'cost' => $cost,
      'cost_currency' => $currency,
      'cost_label' => (string)($u['cost_label'] ?? ($currency !== '' ? openai_format_cost($cost, $currency) : '')),
      'cost_usd' => (float)($u['cost_usd'] ?? 0.0),
    ];
  }

  return ['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0,'cost'=>0.0,'cost_currency'=>'','cost_label'=>'','cost_usd'=>0.0];
};



  foreach ($arr as $i => $opType) {
    $opType = trim((string)$opType);
    if (!isset($registry[$opType])) {
      throw new RuntimeException("run_pipeline: unknown op_type: {$opType}");
    }

    // собрать параметры под-операции: defaults из registry + глобальные overrides + offer_ids
    $childParamDefs = $registry[$opType]['params'] ?? [];
    $childParams = [];

    foreach ($childParamDefs as $k => $def) {
      if (array_key_exists('default', $def)) {
        $childParams[$k] = (string)$def['default'];
      }
    }

    // проброс выбора товаров
    if ($offerIds) $childParams['offer_ids'] = $offerIds;

    // глобальные настройки, если параметр существует у операции
	    $stepModel = trim((string)($stepModels[$opType] ?? ''));
	    $modelForStep = $stepModel !== '' ? $stepModel : $globalModel;
	    if ($modelForStep !== '' && array_key_exists('model', $childParamDefs)) {
	      $childParams['model'] = $modelForStep;
	    }
	    $imageModelForStep = trim((string)($stepImageModels[$opType] ?? ''));
	    if ($imageModelForStep !== '' && array_key_exists('image_model', $childParamDefs)) {
	      $childParams['image_model'] = $imageModelForStep;
	    }
	    if (array_key_exists('max_items', $childParamDefs)) {
	      $childParams['max_items'] = $globalMaxItems;
	    }
	    $globalBrandList = trim((string)($params['brand_list'] ?? ''));
	    if ($globalBrandList !== '' && array_key_exists('brand_list', $childParamDefs)) {
	      $childParams['brand_list'] = $globalBrandList;
	    }

	    // Явно связываем шаг конвейера с родительской операцией, чтобы UI и очередь
	    // показывали один верхнеуровневый pipeline вместо отдельной "cli"-операции.
	    $childParams['_pipeline_parent_op_id'] = $opId;
	    $childParams['_pipeline_step_index'] = $i + 1;
	    $childParams['_pipeline_steps_total'] = count($arr);
	    $childParams['_pipeline_step_op_type'] = $opType;

	    // принудительно inplace (чтобы следующий шаг видел изменения)
	    if ($forceInplace) {
	      if (array_key_exists('inplace', $childParamDefs)) $childParams['inplace'] = '1';
	      if (array_key_exists('auto_dataset', $childParamDefs)) $childParams['auto_dataset'] = '0';
    }

    // создать под-операцию в БД
    $childOpId = ops_create(
      $currentDatasetId,
      $opType,
      $childParams,
      trim((string)($opRow['created_by'] ?? '')) !== '' ? (string)$opRow['created_by'] : null
    );

    // выставим running (чтобы карточка выглядела правильно если открыть)
    ops_set_status($childOpId, 'running', "Started by run_pipeline op={$opId}\n", null);

    $modelLog = isset($childParams['model']) && trim((string)$childParams['model']) !== '' ? ', model=' . (string)$childParams['model'] : '';
    $imageModelLog = isset($childParams['image_model']) && trim((string)$childParams['image_model']) !== '' ? ', image_model=' . (string)$childParams['image_model'] : '';
    $log("Step " . ($i+1) . "/" . count($arr) . ": op={$opType}, child_op_id={$childOpId}{$modelLog}{$imageModelLog}\n");

    // прочитать opRow и запустить стандартным runner’ом (он создаст meta.json, outputs и т.д.)
    $childRow = ops_get($childOpId);
    if (!$childRow) throw new RuntimeException("run_pipeline: cannot read child op row: {$childOpId}");

    // логгер дочерней операции — в её op.log + tail
    $childOutDir = op_output_dir($cfg, $currentDatasetId, $childOpId);
    ensure_dir($childOutDir);
    $childLogFileAbs = $childOutDir . '/op.log';
    if (!is_file($childLogFileAbs)) file_put_contents($childLogFileAbs, "");

    $childLog = function(string $msg) use ($childOpId, $childLogFileAbs) {
      file_put_contents($childLogFileAbs, $msg, FILE_APPEND);
      try { ops_append_log_tail($childOpId, $msg, 200000); } catch (Throwable $e) {}
    };

    try {
      op_run_one($cfg, $childRow, $childLog);
      ops_set_status($childOpId, 'done', "Done.\n", null);
    } catch (Throwable $e) {
      ops_set_status($childOpId, 'error', null, $e->getMessage());
      $log("Pipeline stop: child op error: {$opType}: " . $e->getMessage() . "\n");
      throw $e;
    }

    // перечитать датасет (на случай inplace-перезаписи sha/path)
    $st = db()->prepare("SELECT * FROM feedtools_datasets WHERE id=?");
    $st->execute([$currentDatasetId]);
    $dsNew = $st->fetch();
    if (!$dsNew) throw new RuntimeException("run_pipeline: dataset vanished: {$currentDatasetId}");

        // перечитать summary дочерней операции и вытащить usage/cost
    $childDone = ops_get($childOpId);
    $childSum = null;
    if ($childDone && !empty($childDone['summary_json'])) {
      $childSum = json_decode((string)$childDone['summary_json'], true) ?: null;
    }
    $u = $extractUsage(is_array($childSum) ? $childSum : null);

    $totalIn += $u['input_tokens'];
    $totalCachedIn += $u['cached_input_tokens'];
    $totalOut += $u['output_tokens'];
    $totalCostLegacy += $u['cost_usd'];
    $usageCurrency = strtoupper(trim((string)($u['cost_currency'] ?? '')));
    if ($usageCurrency !== '') {
      $totalCostsByCurrency[$usageCurrency] = (float)($totalCostsByCurrency[$usageCurrency] ?? 0.0) + (float)($u['cost'] ?? $u['cost_usd'] ?? 0.0);
    }

    $steps[] = [
      'op_type' => $opType,
      'op_id' => $childOpId,
      'status' => 'done',
      'model' => (string)($childParams['model'] ?? ($childParams['image_model'] ?? '')),
      'usage' => $u,
    ];

  }

  $totalCostCurrency = count($totalCostsByCurrency) === 1 ? (string)array_key_first($totalCostsByCurrency) : '';
  $totalCost = $totalCostCurrency !== '' ? round((float)reset($totalCostsByCurrency), 6) : round($totalCostLegacy, 6);
  $totalCostLabel = $totalCostsByCurrency ? openai_format_cost_map($totalCostsByCurrency) : openai_format_cost(0.0, 'USD');

    // итоговый summary (пишем inline, как у других операций)
   $summary = [
  'title' => 'Pipeline finished',
  'pipeline' => [
    'steps' => $steps,
    'selected_offers' => count($offerIds),
    'dataset_id' => $currentDatasetId,
  ],
  'usage_total' => [
    'input_tokens' => $totalIn,
    'cached_input_tokens' => $totalCachedIn,
    'output_tokens' => $totalOut,
    'cost' => $totalCost,
    'cost_currency' => $totalCostCurrency,
    'cost_label' => $totalCostLabel,
    'costs' => $totalCostsByCurrency,
    'cost_usd' => round($totalCostLegacy, 6),
  ],
  // удобное “короткое” поле для UI
  'total_cost' => $totalCost,
  'total_cost_currency' => $totalCostCurrency,
  'total_cost_label' => $totalCostLabel,
  'total_cost_usd' => round($totalCostLegacy, 6),
];



  // записать файл в outputs (иначе rel_to_outputs() упадёт из-за realpath=false)
  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $absStepsPath = $outDir . '/pipeline_steps.json';
  file_put_contents(
    $absStepsPath,
    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  return [
    'summary_json_inline' => $summary,
    'pipeline_steps_json' => rel_to_outputs($cfg, $absStepsPath),
  ];

}
