<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

$opId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($opId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'bad id'], JSON_UNESCAPED_UNICODE);
  exit;
}

$op = ops_get($opId);
if (!$op) {
  http_response_code(404);
  echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE);
  exit;
}

$done  = (int)($op['progress_done'] ?? 0);
$total = (int)($op['progress_total'] ?? 0);
$percent = ($total > 0) ? round((max(0, min($done, $total)) / $total) * 100, 1) : null;

/**
 * elapsed: считаем на стороне MySQL, чтобы не было проблем с TZ/strtotime().
 * started_at/created_at у тебя в формате 'Y-m-d H:i:s'.
 */
$elapsed = 0;
$baseStr = $op['started_at'] ?: ($op['created_at'] ?? null);

if (!empty($baseStr)) {
  try {
    $st = db()->prepare("SELECT GREATEST(TIMESTAMPDIFF(SECOND, ?, NOW()), 0) AS s");
    $st->execute([$baseStr]);
    $elapsed = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    // fallback
    $ts = strtotime($baseStr);
    $elapsed = $ts ? max(0, time() - $ts) : 0;
  }
}

// ETA
$eta = null;
if ($total > 0 && $done > 0 && $elapsed >= 3) {
  $rate = $done / $elapsed; // items/sec
  if ($rate > 0) {
    $eta = (int)round(($total - $done) / $rate);
  }
}

$queueAheadCount = 0;
$queueWaitSec = 0;
$queueBlocker = null;
if ((string)($op['status'] ?? '') === 'queued') {
  $queue = ops_queue_summary_for_existing_op($opId);
  $queueAheadCount = (int)($queue['ahead_count'] ?? 0);
  $queueWaitSec = (int)($queue['estimated_wait_sec'] ?? 0);
  $queueBlocker = $queue['blocker'] ?? null;
}

$summary = null;
if (!empty($op['summary_json'])) {
  $summary = json_decode($op['summary_json'], true);
}

// outputs (артефакты)
$outputs = null;
if (!empty($op['outputs_json'])) {
  $decoded = json_decode($op['outputs_json'], true);
  if (is_array($decoded)) $outputs = $decoded;
}

function op_poll_usage_from_array(array $u): array
{
  $amount = (float)($u['cost'] ?? $u['cost_usd'] ?? $u['gpt_cost'] ?? $u['gpt_cost_usd'] ?? $u['image_generation_cost'] ?? 0.0);
  $costUsd = (float)($u['cost_usd'] ?? $u['gpt_cost_usd'] ?? $u['image_generation_cost_usd'] ?? $amount);
  return [
    'input_tokens' => (int)($u['input_tokens'] ?? $u['tokens_in'] ?? $u['gpt_tokens_input'] ?? $u['image_input_tokens'] ?? 0),
    'cached_input_tokens' => (int)($u['cached_input_tokens'] ?? $u['tokens_cached_in'] ?? $u['gpt_tokens_cached_input'] ?? $u['image_cached_input_tokens'] ?? 0),
    'output_tokens' => (int)($u['output_tokens'] ?? $u['tokens_out'] ?? $u['gpt_tokens_output'] ?? $u['image_output_tokens'] ?? 0),
    'cost' => $amount,
    'cost_usd' => $costUsd,
    'cost_currency' => (string)($u['cost_currency'] ?? $u['gpt_cost_currency'] ?? $u['image_generation_cost_currency'] ?? ''),
    'cost_label' => (string)($u['cost_label'] ?? $u['gpt_cost_label'] ?? $u['image_generation_cost_label'] ?? ''),
    'image_input_tokens' => (int)($u['image_input_tokens'] ?? 0),
    'image_cached_input_tokens' => (int)($u['image_cached_input_tokens'] ?? 0),
    'image_output_tokens' => (int)($u['image_output_tokens'] ?? 0),
    'image_generation_cost' => (float)($u['image_generation_cost'] ?? 0.0),
    'image_generation_cost_label' => (string)($u['image_generation_cost_label'] ?? ''),
  ];
}

function op_poll_summary_usage(?array $summary, array $fallback = []): array
{
  if ($summary) {
    if (isset($summary['summary']['usage']) && is_array($summary['summary']['usage'])) {
      return op_poll_usage_from_array($summary['summary']['usage']);
    }
    if (isset($summary['metrics']['usage']) && is_array($summary['metrics']['usage'])) {
      return op_poll_usage_from_array($summary['metrics']['usage']);
    }
    if (isset($summary['usage']) && is_array($summary['usage'])) {
      return op_poll_usage_from_array($summary['usage']);
    }
    if (isset($summary['usage_total']) && is_array($summary['usage_total'])) {
      return op_poll_usage_from_array($summary['usage_total']);
    }
    if (isset($summary['metrics']) && is_array($summary['metrics'])) {
      return op_poll_usage_from_array($summary['metrics']);
    }
  }

  if ($fallback && isset($fallback['usage']) && is_array($fallback['usage'])) {
    return op_poll_usage_from_array($fallback['usage']);
  }

  return op_poll_usage_from_array([]);
}

$pipelineSteps = null;
if ((string)($op['op_type'] ?? '') === 'run_pipeline') {
  $registry = op_registry();
  $childIdsMap = [];
  $summaryStepByOpId = [];
  $summaryRaw = (string)($op['summary_json'] ?? '');
  if ($summaryRaw !== '') {
    $summaryForSteps = json_decode($summaryRaw, true);
    if (is_array($summaryForSteps)) {
      $summarySteps = is_array($summaryForSteps['pipeline']['steps'] ?? null) ? $summaryForSteps['pipeline']['steps'] : [];
      foreach ($summarySteps as $step) {
        $childId = (int)($step['op_id'] ?? 0);
        if ($childId > 0) {
          $childIdsMap[$childId] = true;
          $summaryStepByOpId[$childId] = is_array($step) ? $step : [];
        }
      }
    }
  }
  $parentLog = (string)($op['log_text'] ?? $op['log_tail'] ?? '');
  if ($parentLog !== '' && preg_match_all('/child_op_id=(\d+)/', $parentLog, $matches)) {
    foreach ((array)($matches[1] ?? []) as $childIdRaw) {
      $childId = (int)$childIdRaw;
      if ($childId > 0) {
        $childIdsMap[$childId] = true;
      }
    }
  }
  $childIds = array_keys($childIdsMap);
  sort($childIds, SORT_NUMERIC);
  $pipelineSteps = [];
  foreach ($childIds as $childId) {
    $child = ops_get((int)$childId);
    if (!$child) {
      continue;
    }
    $childParams = ops_decode_params_json($child);
    $stepIndex = (int)($childParams['_pipeline_step_index'] ?? 0);
    $stepTotal = (int)($childParams['_pipeline_steps_total'] ?? 0);
    $opType = (string)($child['op_type'] ?? '');
    $stepSummary = (array)($summaryStepByOpId[(int)$child['id']] ?? []);
    $childSummary = null;
    if (!empty($child['summary_json'])) {
      $decodedChildSummary = json_decode((string)$child['summary_json'], true);
      if (is_array($decodedChildSummary)) {
        $childSummary = $decodedChildSummary;
      }
    }
    $usage = op_poll_summary_usage($childSummary, $stepSummary);
    $meta = is_array($registry[$opType] ?? null) ? (array)$registry[$opType] : [];
    $title = trim((string)($meta['title'] ?? $opType));
    if ($title !== '' && preg_match('~^([a-z0-9_]+)\s*\((.*?)\)~iu', $title, $m)) {
      $title = trim((string)$m[2]);
    }
    $childDone = (int)($child['progress_done'] ?? 0);
    $childTotal = (int)($child['progress_total'] ?? 0);
    $childPercent = ($childTotal > 0) ? round((max(0, min($childDone, $childTotal)) / $childTotal) * 100, 1) : null;
    $stepModel = trim((string)($childParams['model'] ?? ''));
    if ($stepModel === '') {
      $stepModel = trim((string)($childParams['image_model'] ?? ''));
    }
    if ($stepModel === '') {
      $stepModel = trim((string)($stepSummary['model'] ?? ''));
    }

    $pipelineSteps[] = [
      'id' => (int)$child['id'],
      'op_type' => $opType,
      'title' => $title !== '' ? $title : $opType,
      'status' => (string)($child['status'] ?? ''),
      'model' => $stepModel,
      'usage' => $usage,
      'step_index' => $stepIndex,
      'step_total' => $stepTotal,
      'done' => $childDone,
      'total' => $childTotal,
      'percent' => $childPercent,
      'stage' => $child['progress_stage'] ?? null,
      'msg' => $child['progress_msg'] ?? null,
      'started_at' => $child['started_at'] ?? null,
      'finished_at' => $child['finished_at'] ?? null,
      'error_text' => $child['error_text'] ?? null,
      'log_tail' => (string)($child['log_tail'] ?? $child['log_text'] ?? ''),
    ];
  }
  usort($pipelineSteps, static function (array $a, array $b): int {
    $ai = (int)($a['step_index'] ?? 0);
    $bi = (int)($b['step_index'] ?? 0);
    if ($ai > 0 && $bi > 0 && $ai !== $bi) {
      return $ai <=> $bi;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
  });

  if (is_array($summary)) {
    $totalIn = 0;
    $totalCachedIn = 0;
    $totalOut = 0;
    $totalCostUsd = 0.0;
    $costsByCurrency = [];
    $hasUsage = false;
    foreach ($pipelineSteps as $step) {
      $u = is_array($step['usage'] ?? null) ? $step['usage'] : [];
      $input = (int)($u['input_tokens'] ?? 0);
      $cached = (int)($u['cached_input_tokens'] ?? 0);
      $output = (int)($u['output_tokens'] ?? 0);
      $cost = (float)($u['cost'] ?? $u['cost_usd'] ?? 0.0);
      $costUsd = (float)($u['cost_usd'] ?? $cost);
      if ($input || $cached || $output || $cost || $costUsd) {
        $hasUsage = true;
      }
      $totalIn += $input;
      $totalCachedIn += $cached;
      $totalOut += $output;
      $totalCostUsd += $costUsd;

      $currency = strtoupper(trim((string)($u['cost_currency'] ?? '')));
      if ($currency === '' && ($cost || $costUsd)) {
        $currency = 'USD';
      }
      if ($currency !== '') {
        $costsByCurrency[$currency] = (float)($costsByCurrency[$currency] ?? 0.0) + $cost;
      }
    }

    if ($hasUsage) {
      $singleCurrency = count($costsByCurrency) === 1 ? (string)array_key_first($costsByCurrency) : '';
      $totalCost = $singleCurrency !== '' ? round((float)reset($costsByCurrency), 6) : round($totalCostUsd, 6);
      $usageTotal = [
        'input_tokens' => $totalIn,
        'cached_input_tokens' => $totalCachedIn,
        'output_tokens' => $totalOut,
        'cost' => $totalCost,
        'cost_currency' => $singleCurrency,
        'cost_label' => '',
        'costs' => $costsByCurrency,
        'cost_usd' => round($totalCostUsd, 6),
      ];
      $summary['usage_total'] = $usageTotal;
      $summary['total_cost'] = $totalCost;
      $summary['total_cost_currency'] = $singleCurrency;
      $summary['total_cost_label'] = '';
      $summary['total_cost_usd'] = round($totalCostUsd, 6);
    }
  }
}

echo json_encode([
  'id' => (int)$op['id'],
  'status' => (string)$op['status'],
  'created_at' => $op['created_at'] ?? null,
  'started_at' => $op['started_at'] ?? null,
  'finished_at' => $op['finished_at'] ?? null,
  'heartbeat_at' => $op['heartbeat_at'] ?? null,
  'done' => $done,
  'total' => $total,
  'percent' => $percent,
  'stage' => $op['progress_stage'] ?? null,
  'msg' => $op['progress_msg'] ?? null,
  'elapsed_sec' => $elapsed,
  'eta_sec' => $eta,
  'cancel_requested' => !empty($op['cancel_requested_at']),
  'queue_ahead_count' => $queueAheadCount,
  'queue_wait_sec' => $queueWaitSec,
  'queue_blocker' => $queueBlocker,
  'log_tail' => $op['log_tail'] ?? '',

  'error_text' => $op['error_text'] ?? null,
  'summary' => $summary,
  'outputs' => $outputs, // <-- добавили
  'pipeline_steps' => $pipelineSteps,
], JSON_UNESCAPED_UNICODE);
