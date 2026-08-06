<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/llm/OpenAIRequestLog.php';
require_once __DIR__ . '/../app/llm/OpenAIPricing.php';
require_once __DIR__ . '/../app/llm/LLM.php';
require_once __DIR__ . '/../app/supplier_products.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function op_html_artifact_key(array $outputs): string
{
  if (isset($outputs['report_html'])) {
    return 'report_html';
  }
  foreach ($outputs as $key => $rel) {
    $key = (string)$key;
    $rel = (string)$rel;
    if (str_ends_with($key, '_html') || str_ends_with(strtolower($rel), '.html') || str_ends_with(strtolower($rel), '.htm')) {
      return $key;
    }
  }
  return '';
}

function op_safe_return_url(string $raw): string
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

$opId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($opId <= 0) { http_response_code(400); exit('Bad id'); }

$op = ops_get($opId);
if (!$op) { http_response_code(404); exit('Operation not found'); }

$outputs = [];
if (!empty($op['outputs_json'])) $outputs = json_decode($op['outputs_json'], true) ?: [];
$htmlArtifactKey = op_html_artifact_key($outputs);

$op_summary = null;
if (!empty($op['summary_json'])) {
  $op_summary = json_decode((string)$op['summary_json'], true);
  if (!is_array($op_summary)) $op_summary = null;
}

$opParams = [];
if (!empty($op['params_json'])) {
  $opParams = json_decode((string)$op['params_json'], true);
  if (!is_array($opParams)) $opParams = [];
}

$pipelineOpIds = ((string)($op['op_type'] ?? '') === 'run_pipeline') ? ops_pipeline_related_op_ids((int)$op['id']) : [];
$gptLogFilters = $pipelineOpIds ? ['op_ids' => $pipelineOpIds] : ['op_id' => (int)$op['id']];
$gptLogHref = $pipelineOpIds
  ? 'gpt_log.php?pipeline_op_id=' . urlencode((string)$op['id'])
  : 'gpt_log.php?op_id=' . urlencode((string)$op['id']);
$gptLogLabel = $pipelineOpIds ? 'GPT-запросы pipeline' : 'GPT-запросы';
$gptLogCount = OpenAIRequestLog::count($gptLogFilters);
$gptLogSummary = OpenAIRequestLog::summarize($gptLogFilters);
$gptModel = trim((string)($op_summary['params_effective']['model'] ?? $opParams['model'] ?? LLM::modelForOp($cfg, [])));
$gptLogCost = openai_cost_breakdown(
  $cfg,
  $gptModel,
  (int)($gptLogSummary['api_input_tokens'] ?? 0),
  (int)($gptLogSummary['api_cached_input_tokens'] ?? 0),
  (int)($gptLogSummary['api_output_tokens'] ?? 0)
);
$gptLogBillableInputTokens = max(0, (int)($gptLogSummary['api_input_tokens'] ?? 0) - (int)($gptLogSummary['api_cached_input_tokens'] ?? 0));
$gptLogCostByCurrency = [];
$gptLogCacheSavingsByCurrency = [];
$gptLogModelRows = OpenAIRequestLog::summarizeByModel($gptLogFilters);
$gptLogCostDetailRows = [];
foreach ($gptLogModelRows as $modelRow) {
  $rowCost = openai_cost_breakdown(
    $cfg,
    (string)($modelRow['model'] ?? ''),
    (int)($modelRow['api_input_tokens'] ?? 0),
    (int)($modelRow['api_cached_input_tokens'] ?? 0),
    (int)($modelRow['api_output_tokens'] ?? 0)
  );
  $currency = (string)($rowCost['currency'] ?? 'USD');
  $gptLogCostByCurrency[$currency] = (float)($gptLogCostByCurrency[$currency] ?? 0.0) + (float)($rowCost['cost'] ?? $rowCost['cost_usd'] ?? 0.0);
  $gptLogCacheSavingsByCurrency[$currency] = (float)($gptLogCacheSavingsByCurrency[$currency] ?? 0.0) + (float)($rowCost['cache_savings'] ?? $rowCost['cache_savings_usd'] ?? 0.0);
  $gptLogCostDetailRows[] = [
    'model' => (string)($modelRow['model'] ?? ''),
    'requests' => (int)($modelRow['requests'] ?? 0),
    'api_requests' => (int)($modelRow['api_requests'] ?? 0),
    'local_hits' => (int)($modelRow['local_hits'] ?? 0),
    'input_tokens' => (int)($modelRow['api_input_tokens'] ?? 0),
    'cached_input_tokens' => (int)($modelRow['api_cached_input_tokens'] ?? 0),
    'billable_input_tokens' => max(0, (int)($modelRow['api_input_tokens'] ?? 0) - (int)($modelRow['api_cached_input_tokens'] ?? 0)),
    'output_tokens' => (int)($modelRow['api_output_tokens'] ?? 0),
    'cost_label' => (string)($rowCost['cost_label'] ?? openai_format_cost((float)($rowCost['cost'] ?? 0.0), $currency)),
    'cache_savings_label' => (string)($rowCost['cache_savings_label'] ?? openai_format_cost((float)($rowCost['cache_savings'] ?? 0.0), $currency)),
  ];
}
if (!$gptLogCostByCurrency) {
  $fallbackCurrency = (string)($gptLogCost['currency'] ?? 'USD');
  $gptLogCostByCurrency[$fallbackCurrency] = (float)($gptLogCost['cost'] ?? $gptLogCost['cost_usd'] ?? 0.0);
  $gptLogCacheSavingsByCurrency[$fallbackCurrency] = (float)($gptLogCost['cache_savings'] ?? $gptLogCost['cache_savings_usd'] ?? 0.0);
}
$gptLogCostLabelValue = openai_format_cost_map($gptLogCostByCurrency);
$gptLogCacheSavingsLabelValue = openai_format_cost_map($gptLogCacheSavingsByCurrency);
$gptLogCostCurrency = count($gptLogCostByCurrency) === 1 ? (string)array_key_first($gptLogCostByCurrency) : 'MIXED';
$gptLogCostAmount = count($gptLogCostByCurrency) === 1 ? (float)reset($gptLogCostByCurrency) : 0.0;
$queueSummary = ((string)($op['status'] ?? '') === 'queued') ? ops_queue_summary_for_existing_op((int)$op['id']) : null;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Operation #<?=h($op['id'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    :root{--bg:#f8fafc;--panel:#fff;--line:#dbe4f0;--line-soft:#e8eef7;--text:#111827;--muted:#667085;--blue:#2563eb;--blue-soft:#eff6ff;--green:#047857;--green-soft:#ecfdf5;--red:#b91c1c;--red-soft:#fef2f2;--amber:#b45309;--amber-soft:#fffbeb;--ink:#0b1020;}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1180px;margin:18px auto 48px;padding:0 14px;background:linear-gradient(180deg,#fff 0%,var(--bg) 260px);color:var(--text);}
    a{color:#1d4ed8;text-decoration:none;}
    a:hover{text-decoration:underline;}
    .muted{color:var(--muted);}
    pre{white-space:pre-wrap;word-break:break-word;background:var(--ink);color:#d1d5db;padding:14px;border-radius:12px;font-size:12px;line-height:1.45;}
    .operation-shell{border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.92);box-shadow:0 18px 45px rgba(15,23,42,.06);overflow:hidden;}
    .op-hero{padding:22px 24px 18px;border-bottom:1px solid var(--line-soft);background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%);}
    .op-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;}
    .op-eyebrow{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
    .op-title{margin:0;font-size:30px;line-height:1.15;letter-spacing:0;}
    .status-pill{display:inline-flex;align-items:center;justify-content:center;min-width:86px;border-radius:999px;border:1px solid var(--line);padding:7px 12px;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#f8fafc;color:#475467;}
    .status-running{background:var(--blue-soft);border-color:#bfdbfe;color:#1d4ed8;}
    .status-queued{background:#f5f3ff;border-color:#ddd6fe;color:#6d28d9;}
    .status-done{background:var(--green-soft);border-color:#bbf7d0;color:var(--green);}
    .status-error{background:var(--red-soft);border-color:#fecaca;color:var(--red);}
    .status-cancelled{background:var(--amber-soft);border-color:#fde68a;color:var(--amber);}
    .op-meta-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px;}
    .op-meta-item{border:1px solid var(--line-soft);border-radius:12px;background:#fff;padding:11px 12px;min-width:0;}
    .op-meta-label{display:block;font-size:12px;color:var(--muted);font-weight:700;margin-bottom:4px;}
    .op-meta-value{display:block;font-size:15px;font-weight:800;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .op-meta-value.is-soft{font-weight:700;color:#475467;}
    .op-alert{margin-top:14px;border-radius:12px;padding:11px 12px;font-weight:700;}
    .op-alert.is-error{background:var(--red-soft);color:var(--red);border:1px solid #fecaca;}
    .op-alert.is-cancelled{background:var(--amber-soft);color:var(--amber);border:1px solid #fde68a;}
    .op-cancel{display:flex;align-items:center;gap:12px;margin-top:16px;padding:12px;border:1px solid #fee2e2;background:#fffafa;border-radius:12px;}
    .op-cancel button{background:#c5161d;color:#fff;border:0;border-radius:10px;padding:9px 14px;cursor:pointer;font-weight:800;font-size:14px;}
    .op-cancel button:disabled{opacity:.55;cursor:not-allowed;}
    .op-cancel-note{color:#667085;font-size:14px;}
    .op-main{padding:18px 24px 24px;}
    .progress-panel{border:1px solid var(--line);border-radius:16px;background:#fff;padding:16px;box-shadow:0 10px 28px rgba(15,23,42,.04);}
    .progress-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:12px;}
    .progress-kicker{display:block;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px;}
    .progress-value{display:block;font-size:34px;line-height:1;font-weight:900;color:#0f172a;}
    .progress-message{max-width:620px;color:#334155;font-size:15px;line-height:1.4;text-align:right;}
    .progress-track{height:12px;background:#e5e7eb;border-radius:999px;overflow:hidden;}
    .progress-track > div{height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#0f8b8d);border-radius:999px;transition:width .25s ease;}
    .progress-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:14px;}
    .progress-metric{border:1px solid var(--line-soft);border-radius:12px;background:#f8fafc;padding:10px;min-width:0;}
    .progress-metric span{display:block;color:#64748b;font-size:12px;font-weight:800;margin-bottom:4px;}
    .progress-metric strong{display:block;color:#111827;font-size:15px;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .queue-note{margin-top:10px;font-size:13px;color:#667085;}
    .action-strip{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:14px 0 0;}
    .action-link{display:inline-flex;align-items:center;justify-content:center;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:8px 11px;font-weight:800;font-size:13px;text-decoration:none;}
    .action-link:hover{text-decoration:none;background:#dbeafe;}
    .gpt-stats{margin-top:10px;border:1px solid var(--line-soft);border-radius:12px;background:#f8fafc;padding:10px 12px;color:#475467;font-size:13px;line-height:1.5;display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;overflow-wrap:anywhere;}
    .gpt-stats strong{color:#111827;}
    .gpt-cost-details{margin-top:8px;border:1px solid var(--line-soft);border-radius:12px;background:#fff;overflow:hidden;}
    .gpt-cost-details summary{cursor:pointer;padding:9px 12px;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;list-style:none;}
    .gpt-cost-details summary::-webkit-details-marker{display:none;}
    .gpt-cost-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;padding:0 12px 12px;}
    .gpt-cost-row{border:1px solid var(--line-soft);border-radius:10px;background:#f8fafc;padding:9px 10px;min-width:0;}
    .gpt-cost-row-title{font-weight:900;color:#111827;overflow-wrap:anywhere;margin-bottom:4px;}
    .gpt-cost-row-meta{font-size:12px;color:#64748b;line-height:1.45;overflow-wrap:anywhere;}
    .section-card{border:1px solid var(--line);border-radius:16px;background:#fff;margin-top:16px;padding:16px;}
    .section-card h3{margin:0 0 10px;font-size:20px;}
    .section-card summary{cursor:pointer;list-style:none;}
    .section-card summary::-webkit-details-marker{display:none;}
    .section-summary-title{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:20px;font-weight:900;}
    .section-summary-title::after{content:'раскрыть';font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.06em;}
    details[open] .section-summary-title::after{content:'скрыть';}
    #summaryCard{display:none;}
    #artifacts_box ul{margin:0;padding-left:18px;}
    .empty-state{margin:0;color:#667085;}
    .pipeline-logs{display:none;}
    .pipeline-logs h3{margin:0 0 10px;}
    .pipeline-step{border:1px solid var(--line-soft);border-radius:12px;margin:10px 0;background:#fff;overflow:hidden;}
    .pipeline-step[open]{border-color:#bfdbfe;box-shadow:0 12px 30px rgba(37,99,235,.08);}
    .pipeline-step summary{display:flex;align-items:center;gap:10px;justify-content:space-between;cursor:pointer;padding:11px 12px;list-style:none;}
    .pipeline-step summary::-webkit-details-marker{display:none;}
    .pipeline-step-title{font-weight:800;color:#111827;}
    .pipeline-step-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#667085;font-size:12px;}
    .pipeline-step-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;background:#eef2ff;color:#1d4ed8;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
    .pipeline-step-badge.is-done{background:var(--green-soft);color:var(--green);}
    .pipeline-step-badge.is-error{background:var(--red-soft);color:var(--red);}
    .pipeline-step-badge.is-running{background:#dbeafe;color:#1d4ed8;}
    .pipeline-step-progress{height:7px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:0 12px 10px;}
    .pipeline-step-progress > span{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#0f8b8d);width:0%;}
    .pipeline-step-body{padding:0 12px 12px;}
    .pipeline-step-message{margin:0 0 8px;color:#374151;font-size:13px;}
    .pipeline-step-error{margin:0 0 8px;color:#b91c1c;font-weight:700;font-size:13px;}
    .pipeline-step-log{max-height:380px;overflow:auto;margin:0;font-size:12px;}
    .pipeline-cost-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0 0 10px;}
    .pipeline-cost-item{border:1px solid var(--line-soft);border-radius:10px;background:#f8fafc;padding:8px 9px;min-width:0;}
    .pipeline-cost-item span{display:block;font-size:11px;color:#64748b;font-weight:800;margin-bottom:2px;}
    .pipeline-cost-item strong{display:block;font-size:13px;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .raw-log pre{max-height:460px;overflow:auto;margin-top:12px;}
    .env-badge{position:fixed;top:14px;right:16px;z-index:1000;display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;border:1px solid #f59e0b;background:rgba(255,251,235,.97);color:#92400e;box-shadow:0 12px 28px rgba(146,64,14,.14);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;}
    @media (max-width:900px){.op-meta-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.progress-metrics,.pipeline-cost-strip{grid-template-columns:repeat(2,minmax(0,1fr));}.progress-head{display:block;}.progress-message{text-align:left;margin-top:10px;max-width:none;}}
    @media (max-width:560px){body{padding:0 10px;margin-top:10px;}.op-hero,.op-main{padding-left:14px;padding-right:14px;}.op-title-row{display:block;}.status-pill{margin-top:12px;}.op-title{font-size:25px;}.op-meta-grid,.progress-metrics{grid-template-columns:1fr;}.op-cancel{align-items:flex-start;flex-direction:column;}.progress-value{font-size:30px;}}
  </style>
</head>
<body>
<?php if (ft_is_staging_env($cfg)): ?>
<div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
<?php endif; ?>

<?php
$opConnectionId = (int)($op['connection_id'] ?? 0);
$opServiceKey = trim((string)($op['service_key'] ?? ''));
$backHref = ((((int)($op['dataset_id'] ?? 0) > 0)) ? ('view.php?id=' . urlencode((string)$op['dataset_id'])) : 'index.php');
$backLabel = ((((int)($op['dataset_id'] ?? 0) > 0)) ? 'К датасету' : 'Назад');
$opDatasetId = (int)($op['dataset_id'] ?? 0);
if ($opDatasetId > 0 && supplier_products_supplier_id_for_dataset($opDatasetId, $cfg) > 0) {
  $backHref = 'supplier_products_view.php?id=' . urlencode((string)$opDatasetId);
  $backLabel = 'К товарам поставщика';
}
if ($opConnectionId > 0) {
  if ($opServiceKey === 'price_tool') {
    $backHref = 'ozon_price_tool.php?connection_id=' . urlencode((string)$opConnectionId);
    $backLabel = 'К Price Tool';
  } elseif ($opServiceKey === 'orders_sync') {
    $backHref = 'orders_sync.php?connection_id=' . urlencode((string)$opConnectionId);
    $backLabel = 'К Orders Sync';
  } elseif ($opServiceKey === 'marketplace_data') {
    $backHref = 'marketplace_connections.php?connection_id=' . urlencode((string)$opConnectionId);
    $backLabel = 'К подключению';
  }
}
$explicitReturnUrl = op_safe_return_url((string)($_GET['return_url'] ?? ($opParams['_return_url'] ?? '')));
if ($explicitReturnUrl !== '') {
  $backHref = $explicitReturnUrl;
  $backLabel = 'Назад';
}
?>
<?= ft_top_navigation([
  'back_href' => $backHref,
  'back_label' => $backLabel,
  'links' => ft_default_nav_links(''),
]) ?>

<main class="operation-shell">
  <section class="op-hero">
    <div class="op-title-row">
      <div>
        <div class="op-eyebrow">Операция</div>
        <h1 class="op-title">Operation #<?=h($op['id'])?></h1>
      </div>
      <span id="op_status" class="status-pill status-<?=h(preg_replace('~[^a-z0-9_-]+~i', '', (string)$op['status']))?>"><?=h($op['status'])?></span>
    </div>

    <div class="op-meta-grid">
      <div class="op-meta-item">
        <span class="op-meta-label">Тип</span>
        <span class="op-meta-value"><?=h($op['op_type'])?></span>
      </div>
      <div class="op-meta-item">
        <span class="op-meta-label">Пользователь</span>
        <span class="op-meta-value"><?=h($op['created_by'] ?? '—')?></span>
      </div>
      <div class="op-meta-item">
        <span class="op-meta-label">Старт</span>
        <span class="op-meta-value is-soft"><?= ft_local_datetime_html((string)($op['started_at'] ?? ''), ['show_seconds' => true, 'attrs' => ['id' => 'op_started']]) ?></span>
      </div>
      <div class="op-meta-item">
        <span class="op-meta-label">Финиш</span>
        <span class="op-meta-value is-soft"><?= ft_local_datetime_html((string)($op['finished_at'] ?? ''), ['show_seconds' => true, 'attrs' => ['id' => 'op_finished']]) ?></span>
      </div>
    </div>

  <?php if ($op['status'] === 'error'): ?>
    <div class="op-alert is-error"><b>Ошибка:</b> <?=h($op['error_text'])?></div>
  <?php endif; ?>
  <?php if ($op['status'] === 'cancelled'): ?>
    <div class="op-alert is-cancelled"><b>Отменено:</b> <?=h($op['error_text'] ?: 'Операция была остановлена пользователем.')?></div>
  <?php endif; ?>

  <?php
    $canCancel = in_array($op['status'], ['queued','running'], true);
    $cancelFlagAbs = op_output_dir($cfg, (int)$op['dataset_id'], (int)$op['id']) . '/cancel.flag';
    $cancelRequested = !empty($op['cancel_requested_at']) || is_file($cancelFlagAbs);
  ?>

  <?php if ($canCancel): ?>
    <form method="post" action="op_cancel.php" class="op-cancel">
      <input type="hidden" name="id" value="<?=h($op['id'])?>">
      <button type="submit" <?= $cancelRequested ? 'disabled' : '' ?>>Cancel</button>
      <?php if ($cancelRequested): ?>
        <span class="op-cancel-note">Отмена уже запрошена. Операция остановится на ближайшей точке проверки.</span>
      <?php else: ?>
        <span class="op-cancel-note">Остановится после завершения текущего шага/товара.</span>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  </section>

  <div class="op-main">
  <div class="action-strip">
  <span id="html_report_action">
    <?php if ($htmlArtifactKey !== ''): ?>
      <a class="action-link" href="op_artifact.php?id=<?=h($op['id'])?>&file=<?=h(urlencode($htmlArtifactKey))?>" target="_blank" rel="noopener">Открыть HTML-отчёт</a>
    <?php endif; ?>
  </span>
  <?php if (isset($outputs['report_json'])): ?>
    <a class="action-link" href="report.php?op_id=<?=h($op['id'])?>"><?= $htmlArtifactKey !== '' ? 'Открыть JSON' : 'Открыть отчёт' ?></a>
  <?php endif; ?>
  <?php if ($gptLogCount > 0): ?>
    <a class="action-link" href="<?=h($gptLogHref)?>"><?=h($gptLogLabel)?>: <?=h($gptLogCount)?></a>
  <?php endif; ?>
  </div>
  <?php if ($gptLogCount > 0): ?>
    <div class="gpt-stats">
      <strong>GPT по журналу:</strong>
      <span>api <?=h($gptLogSummary['api_requests'] ?? 0)?></span>
      <span>local <?=h($gptLogSummary['local_hits'] ?? 0)?></span>
      <span>попаданий в кэш <?=h($gptLogSummary['prompt_hits'] ?? 0)?></span>
      <span>in <?=h($gptLogSummary['api_input_tokens'] ?? 0)?></span>
      <span>cached <?=h($gptLogSummary['api_cached_input_tokens'] ?? 0)?></span>
      <span>к оплате <?=h($gptLogBillableInputTokens)?></span>
      <span>out <?=h($gptLogSummary['api_output_tokens'] ?? 0)?></span>
      <span>стоимость <?=h($gptLogCostLabelValue)?></span>
      <span>экономия <?=h($gptLogCacheSavingsLabelValue)?></span>
    </div>
    <?php if ($gptLogCostDetailRows): ?>
      <details class="gpt-cost-details">
        <summary>Расчёт стоимости по моделям</summary>
        <div class="gpt-cost-grid">
          <?php foreach ($gptLogCostDetailRows as $costRow): ?>
            <div class="gpt-cost-row">
              <div class="gpt-cost-row-title"><?=h($costRow['model'] !== '' ? $costRow['model'] : 'unknown model')?></div>
              <div class="gpt-cost-row-meta">
                запросы: <?=h($costRow['api_requests'])?> api / <?=h($costRow['local_hits'])?> local<br>
                токены: in <?=h($costRow['input_tokens'])?>, cached <?=h($costRow['cached_input_tokens'])?>, billable <?=h($costRow['billable_input_tokens'])?>, out <?=h($costRow['output_tokens'])?><br>
                стоимость: <?=h($costRow['cost_label'])?>, экономия кеша: <?=h($costRow['cache_savings_label'])?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  <?php endif; ?>

  <section class="progress-panel">
    <div class="progress-head">
      <div>
        <span class="progress-kicker">Прогресс</span>
        <span id="pct" class="progress-value">-</span>
      </div>
      <div id="msg" class="progress-message"></div>
    </div>
    <div class="progress-track">
      <div id="bar"></div>
    </div>
    <div class="progress-metrics">
      <div class="progress-metric"><span>Прошло</span><strong id="elapsed">-</strong></div>
      <div class="progress-metric"><span>Осталось</span><strong id="eta">-</strong></div>
      <div class="progress-metric"><span>Этап</span><strong id="stage">-</strong></div>
      <div class="progress-metric"><span>Выполнено</span><strong id="dt">-</strong></div>
      <div class="progress-metric"><span>Обновлено</span><strong id="heartbeat">-</strong></div>
    </div>
    <div id="queue_info" class="queue-note"></div>
  </section>

  <section id="summaryCard" class="section-card">
    <h3>Краткий отчёт</h3>
    <div id="summary"></div>
  </section>

  <section class="section-card">
    <h3>Артефакты</h3>
    <div id="artifacts_box">
      <?php if (!$outputs): ?>
        <p class="empty-state">Пока нет.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($outputs as $k => $rel): ?>
            <li><?=h($k)?>:
              <?php if (str_ends_with((string)$k, '_html') || str_ends_with(strtolower((string)$rel), '.html') || str_ends_with(strtolower((string)$rel), '.htm')): ?>
                <a href="op_artifact.php?id=<?=h($op['id'])?>&file=<?=h(urlencode((string)$k))?>" target="_blank" rel="noopener">открыть</a>
                ·
              <?php endif; ?>
              <a href="op_download.php?id=<?=h($op['id'])?>&file=<?=h(urlencode($k))?>">скачать</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>

  <?php if (isset($outputs['result_xml'])): ?>
    <p>
      <a href="op_to_dataset.php?id=<?=h($op['id'])?>&key=result_xml&force=1"
         onclick="return confirm('Создать новый датасет из result.xml?')">
        Создать датасет из result.xml
      </a>
    </p>
  <?php endif; ?>

  <section id="pipeline_logs" class="section-card pipeline-logs">
    <h3>Логи шагов конвейера</h3>
    <div id="pipeline_logs_list"></div>
  </section>

  <details class="section-card raw-log" <?= ((string)($op['op_type'] ?? '') !== 'run_pipeline') ? 'open' : '' ?>>
    <summary><span class="section-summary-title">Лог операции</span></summary>
    <pre id="log_tail"><?=h($op['log_tail'] ?? ($op['log_text'] ?? ''))?></pre>
  </details>

  </div>
</main>

<script>
const opId = <?= json_encode((int)$op['id']) ?>;
const opDatasetId = <?= json_encode((int)($op['dataset_id'] ?? 0)) ?>;
const autoReport = <?= json_encode(((string)($_GET['auto_report'] ?? '') === '1')) ?>;
let __redirected = false;

const __initialSummary = <?= json_encode($op_summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const __initialQueueSummary = <?= json_encode($queueSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const __pageCost = <?= json_encode([
  'amount' => $gptLogCostAmount,
  'currency' => $gptLogCostCurrency,
  'label' => $gptLogCostLabelValue,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const pipelineStepOpenState = new Map();
const pipelineStepOpenStorageKey = 'feedtools.op.pipelineStepOpen.' + String(opId || '');

function loadPipelineStepOpenState(){
  try {
    const raw = window.sessionStorage ? window.sessionStorage.getItem(pipelineStepOpenStorageKey) : '';
    if (!raw) return;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return;
    Object.keys(parsed).forEach(function(key) {
      pipelineStepOpenState.set(String(key), !!parsed[key]);
    });
  } catch (e) {}
}

function savePipelineStepOpenState(){
  try {
    if (!window.sessionStorage) return;
    const plain = {};
    pipelineStepOpenState.forEach(function(value, key) {
      plain[String(key)] = !!value;
    });
    window.sessionStorage.setItem(pipelineStepOpenStorageKey, JSON.stringify(plain));
  } catch (e) {}
}

function pipelineStepStateKey(step){
  const id = Number((step && step.id) || 0);
  if (id > 0) return 'op:' + String(id);
  const idx = Number((step && step.step_index) || 0);
  const opType = String((step && step.op_type) || '');
  return 'step:' + String(idx || 0) + ':' + opType;
}

function bindPipelineStepOpenHandlers(list){
  if (!list) return;
  Array.from(list.querySelectorAll('details.pipeline-step[data-pipeline-step-key]')).forEach(function(details) {
    details.addEventListener('toggle', function() {
      const key = String(details.getAttribute('data-pipeline-step-key') || '');
      if (!key) return;
      pipelineStepOpenState.set(key, !!details.open);
      savePipelineStepOpenState();
    });
  });
}

function rememberCurrentPipelineStepOpenState(list){
  if (!list) return;
  let changed = false;
  Array.from(list.querySelectorAll('details.pipeline-step[data-pipeline-step-key]')).forEach(function(details) {
    const key = String(details.getAttribute('data-pipeline-step-key') || '');
    if (!key) return;
    const value = !!details.open;
    if (!pipelineStepOpenState.has(key) || pipelineStepOpenState.get(key) !== value) {
      pipelineStepOpenState.set(key, value);
      changed = true;
    }
  });
  if (changed) savePipelineStepOpenState();
}

loadPipelineStepOpenState();

function fmt(sec){
  if (sec == null) return '-';
  sec = Math.max(0, sec|0);
  const h = Math.floor(sec/3600);
  const m = Math.floor((sec%3600)/60);
  const s = sec%60;
  return (h? h+'h ':'') + (m? m+'m ':'') + s+'s';
}

function esc(s){
  return String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function statusClass(status){
  const clean = String(status || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '');
  return clean || 'unknown';
}

function fmtCost(amount, currency){
  currency = String(currency || 'USD').toUpperCase();
  amount = Number(amount || 0);
  if (!Number.isFinite(amount)) amount = 0;
  const precision = currency === 'RUB' ? 4 : 6;
  let text = amount.toFixed(precision).replace(/0+$/, '').replace(/\.$/, '');
  if (!text) text = '0';
  if (currency === 'RUB') return text + ' ₽';
  if (currency === 'USD') return '$' + text;
  return text + ' ' + currency;
}

function getSummaryCostCurrency(sum){
  if (!sum || typeof sum !== 'object') return (__pageCost && __pageCost.currency) || 'USD';
  if (sum.total_cost_currency) return String(sum.total_cost_currency);
  if (sum.cost_currency) return String(sum.cost_currency);
  if (sum.usage_total && sum.usage_total.cost_currency) return String(sum.usage_total.cost_currency);
  if (sum.metrics && sum.metrics.cost_currency) return String(sum.metrics.cost_currency);
  if (sum.metrics && sum.metrics.usage && sum.metrics.usage.cost_currency) return String(sum.metrics.usage.cost_currency);
  if (sum.metrics && sum.metrics.usage_total && sum.metrics.usage_total.cost_currency) return String(sum.metrics.usage_total.cost_currency);
  return (__pageCost && __pageCost.currency) || 'USD';
}

function getPipelineTotalCost(sum){
  if (!sum || typeof sum !== 'object') return null;
  if (sum.total_cost != null) return Number(sum.total_cost);
  if (sum.cost != null) return Number(sum.cost);
  if (sum.usage_total && sum.usage_total.cost != null) return Number(sum.usage_total.cost);
  if (sum.metrics && sum.metrics.cost != null) return Number(sum.metrics.cost);
  if (sum.metrics && sum.metrics.usage && sum.metrics.usage.cost != null) return Number(sum.metrics.usage.cost);
  if (sum.metrics && sum.metrics.usage_total && sum.metrics.usage_total.cost != null) return Number(sum.metrics.usage_total.cost);
  if (sum.total_cost_usd != null) return Number(sum.total_cost_usd);
  if (sum.usage_total && sum.usage_total.cost_usd != null) return Number(sum.usage_total.cost_usd);
  if (sum.metrics && sum.metrics.cost_usd != null) return Number(sum.metrics.cost_usd);
  if (sum.metrics && sum.metrics.usage && sum.metrics.usage.cost_usd != null) return Number(sum.metrics.usage.cost_usd);
  if (sum.metrics && sum.metrics.usage_total && sum.metrics.usage_total.cost_usd != null) return Number(sum.metrics.usage_total.cost_usd);
  return null;
}

function getSummaryCostLabel(sum){
  if (!sum || typeof sum !== 'object') {
    return (__pageCost && __pageCost.label) || '';
  }
  if (sum.total_cost_label) return String(sum.total_cost_label);
  if (sum.cost_label) return String(sum.cost_label);
  if (sum.usage_total && sum.usage_total.cost_label) return String(sum.usage_total.cost_label);
  const costs = (sum.usage_total && sum.usage_total.costs) || sum.costs || null;
  if (costs && typeof costs === 'object') {
    const parts = Object.keys(costs).map(currency => fmtCost(Number(costs[currency] || 0), currency));
    if (parts.length) return parts.join(' + ');
  }
  let amount = getPipelineTotalCost(sum);
  let currency = getSummaryCostCurrency(sum);
  if ((!amount || !Number.isFinite(amount)) && __pageCost && __pageCost.amount > 0) {
    amount = Number(__pageCost.amount);
    currency = __pageCost.currency || currency;
  }
  if (amount != null && Number.isFinite(amount)) return fmtCost(amount, currency);
  return (__pageCost && __pageCost.label) || '';
}

function normalizeSummaryItem(item, sum){
  const text = String(item);
  if (!/^Cost USD:\s*\$/.test(text) && !/^GPT cost USD:\s*\$/.test(text)) return text;
  let amount = getPipelineTotalCost(sum);
  let currency = getSummaryCostCurrency(sum);
  if ((!amount || !Number.isFinite(amount)) && __pageCost && __pageCost.amount > 0) {
    amount = Number(__pageCost.amount);
    currency = __pageCost.currency || currency;
  }
  return 'Cost: ' + fmtCost(amount || 0, currency);
}

function getPipelineStepsCount(sum){
  if (!sum || typeof sum !== 'object') return null;
  if (sum.pipeline && Array.isArray(sum.pipeline.steps)) return sum.pipeline.steps.length;
  if (sum.metrics && sum.metrics.pipeline && Array.isArray(sum.metrics.pipeline.steps)) return sum.metrics.pipeline.steps.length;
  return null;
}

function renderSummary(sum){
  if (!sum) return '';
  let html = '';

  if (sum.title) html += '<p><b>'+esc(sum.title)+'</b></p>';

  // Явный вывод стоимости для конвейера
  if ((sum.title === 'Pipeline finished') || (sum.pipeline || (sum.metrics && sum.metrics.pipeline))) {
    const stepsN = getPipelineStepsCount(sum);
    const costLabel = getSummaryCostLabel(sum);
    if (stepsN != null || costLabel) {
      html += '<div style="margin:10px 0;padding:10px;border:1px solid #e5e7eb;border-radius:10px;">';
      if (stepsN != null) html += '<div><b>Итог конвейера:</b> выполнено шагов: ' + esc(String(stepsN)) + '</div>';
      if (costLabel) html += '<div><b>Стоимость запуска:</b> ' + esc(costLabel) + '</div>';
      html += '</div>';
    }
  }

  // ссылка на новый датасет (если создан)
  const dd = sum.metrics && sum.metrics.derived_dataset ? sum.metrics.derived_dataset : null;
  if (dd && dd.dataset_id) {
    const id = String(dd.dataset_id);
    const dup = dd.is_duplicate ? ' <span class="muted">(duplicate)</span>' : '';
    html += '<p><b>Новый датасет:</b> <a href="view.php?id=' + encodeURIComponent(id) + '">dataset #' + esc(id) + '</a>' + dup + '</p>';
  } else if (dd && dd.error) {
    html += '<p style="color:#b91c1c;"><b>Derived dataset error:</b> ' + esc(String(dd.error)) + '</p>';
  }

  if (Array.isArray(sum.items) && sum.items.length){
    html += '<ul>' + sum.items.map(x=>'<li>'+esc(normalizeSummaryItem(x, sum))+'</li>').join('') + '</ul>';
  }

  // Покажем удобным образом usage_total на верхнем уровне, если он есть
  if (sum.usage_total){
    html += '<pre style="white-space:pre-wrap;">'+esc(JSON.stringify({usage_total: sum.usage_total},null,2))+'</pre>';
  } else if (sum.metrics){
    html += '<pre style="white-space:pre-wrap;">'+esc(JSON.stringify(sum.metrics,null,2))+'</pre>';
  }

  return html;
}

function renderQueueInfo(status, queueAheadCount, queueWaitSec, queueBlocker, cancelRequested){
  const box = document.getElementById('queue_info');
  if (!box) return;

  if (status === 'queued') {
    const parts = [];
    if (queueAheadCount > 0) parts.push('В очереди впереди: ' + queueAheadCount);
    if (queueWaitSec > 0) parts.push('Примерный старт через ' + fmt(queueWaitSec));
    if (queueBlocker && queueBlocker.id) {
      const sameDataset = opDatasetId > 0 && Number(queueBlocker.dataset_id || 0) === opDatasetId;
      let blocker = 'Сейчас выполняется операция #' + queueBlocker.id;
      if (queueBlocker.op_type) blocker += ' (' + queueBlocker.op_type + ')';
      if (queueBlocker.dataset_id) blocker += ' по датасету #' + queueBlocker.dataset_id;
      if (queueBlocker.active_child_op_id) {
        blocker += ', шаг #' + queueBlocker.active_child_op_id;
        if (queueBlocker.active_child_op_type) blocker += ' (' + queueBlocker.active_child_op_type + ')';
      }
      parts.push(blocker);
      if (sameDataset) {
        parts.push('Ожидает освобождения этого же датасета');
      }
    }
    if (cancelRequested) parts.push('Отмена уже запрошена');
    box.textContent = parts.join(' · ');
    return;
  }

  if (status === 'running' && cancelRequested) {
    box.textContent = 'Отмена уже запрошена. Worker завершит или принудительно остановит задачу.';
    return;
  }

  box.textContent = '';
}

function renderArtifacts(outputs){
  const box = document.getElementById('artifacts_box');
  if (!box) return;

  if (!outputs || typeof outputs !== 'object') {
    box.innerHTML = '<p class="muted">Пока нет.</p>';
    return;
  }

  const keys = Object.keys(outputs);
  if (!keys.length) {
    box.innerHTML = '<p class="muted">Пока нет.</p>';
    return;
  }

  const items = keys.map(k => {
    const downloadHref = 'op_download.php?id=' + encodeURIComponent(opId) +
                         '&file=' + encodeURIComponent(k);
    const rel = String(outputs[k] || '').toLowerCase();
    const isHtml = String(k).endsWith('_html') || rel.endsWith('.html') || rel.endsWith('.htm');
    const openHref = 'op_artifact.php?id=' + encodeURIComponent(opId) +
                     '&file=' + encodeURIComponent(k);
    const openLink = isHtml ? '<a href="' + openHref + '" target="_blank" rel="noopener">открыть</a> · ' : '';
    return '<li>' + esc(k) + ': ' + openLink + '<a href="' + downloadHref + '">скачать</a></li>';
  }).join('');

  box.innerHTML = '<ul>' + items + '</ul>';
}

function renderHtmlReportAction(outputs){
  const box = document.getElementById('html_report_action');
  if (!box || !outputs || typeof outputs !== 'object') return;
  let key = '';
  if (outputs.report_html) {
    key = 'report_html';
  } else {
    for (const k of Object.keys(outputs)) {
      const rel = String(outputs[k] || '').toLowerCase();
      if (String(k).endsWith('_html') || rel.endsWith('.html') || rel.endsWith('.htm')) {
        key = k;
        break;
      }
    }
  }
  if (!key) return;
  const href = 'op_artifact.php?id=' + encodeURIComponent(opId) + '&file=' + encodeURIComponent(key);
  box.innerHTML = '<a class="action-link" href="' + href + '" target="_blank" rel="noopener">Открыть HTML-отчёт</a>';
}

function pipelineStatusLabel(status){
  status = String(status || '');
  if (status === 'done') return 'готово';
  if (status === 'running') return 'идет';
  if (status === 'queued') return 'в очереди';
  if (status === 'error') return 'ошибка';
  if (status === 'cancelled') return 'отменено';
  return status || '-';
}

function pipelineStepCostLabel(step){
  const usage = step && step.usage && typeof step.usage === 'object' ? step.usage : {};
  if (usage.cost_label) return String(usage.cost_label);
  const cost = usage.cost != null ? Number(usage.cost) : (usage.cost_usd != null ? Number(usage.cost_usd) : null);
  if (cost == null || !Number.isFinite(cost)) return '';
  const currency = usage.cost_currency || (usage.cost_usd != null ? 'USD' : 'USD');
  return fmtCost(cost, currency);
}

function pipelineStepTokensLabel(step){
  const usage = step && step.usage && typeof step.usage === 'object' ? step.usage : {};
  const input = Number(usage.input_tokens || 0);
  const cached = Number(usage.cached_input_tokens || 0);
  const output = Number(usage.output_tokens || 0);
  if (!input && !cached && !output) return '';
  return 'in ' + input + ' / cached ' + cached + ' / out ' + output;
}

function renderPipelineLogs(steps, parentStatus){
  const section = document.getElementById('pipeline_logs');
  const list = document.getElementById('pipeline_logs_list');
  if (!section || !list) return;
  rememberCurrentPipelineStepOpenState(list);

  if (!Array.isArray(steps) || !steps.length) {
    section.style.display = 'none';
    list.innerHTML = '';
    return;
  }

  section.style.display = 'block';
  const parentRunning = parentStatus === 'queued' || parentStatus === 'running';
  const hasRunningStep = steps.some(step => String((step && step.status) || '') === 'running');
  const hasQueuedStep = !hasRunningStep && steps.some(step => String((step && step.status) || '') === 'queued');
  let openedQueued = false;
  list.innerHTML = steps.map(step => {
    const status = String((step && step.status) || '');
    const opId = Number((step && step.id) || 0);
    const idx = Number((step && step.step_index) || 0);
    const total = Number((step && step.step_total) || 0);
    const pct = step && step.percent != null ? Number(step.percent) : null;
    const title = String((step && step.title) || (step && step.op_type) || 'Операция');
    const opType = String((step && step.op_type) || '');
    const msg = String((step && step.msg) || '');
    const stage = String((step && step.stage) || '');
    const err = String((step && step.error_text) || '');
    const log = String((step && step.log_tail) || '');
    const stateKey = pipelineStepStateKey(step);
    let open = parentRunning && status === 'running';
    if (parentRunning && !hasRunningStep && hasQueuedStep && status === 'queued' && !openedQueued) {
      open = true;
      openedQueued = true;
    }
    if (!parentRunning && status === 'error') open = true;
    if (pipelineStepOpenState.has(stateKey)) {
      open = !!pipelineStepOpenState.get(stateKey);
    }
    const progressText = (step && step.total > 0) ? (String(step.done || 0) + '/' + String(step.total || 0)) : '';
    const pctWidth = pct != null && Number.isFinite(pct) ? Math.max(0, Math.min(100, pct)) : 0;
    const stepLabel = idx > 0 && total > 0 ? ('Шаг ' + idx + '/' + total) : (idx > 0 ? ('Шаг ' + idx) : '');
    const model = String((step && step.model) || '');
    const costLabel = pipelineStepCostLabel(step);
    const tokensLabel = pipelineStepTokensLabel(step);
    const meta = [
      stepLabel,
      opId > 0 ? ('#' + opId) : '',
      opType,
      model ? ('модель: ' + model) : '',
      costLabel ? ('стоимость: ' + costLabel) : '',
      stage ? ('этап: ' + stage) : '',
      progressText ? ('выполнено: ' + progressText) : '',
      pct != null && Number.isFinite(pct) ? (pct + '%') : ''
    ].filter(Boolean).map(esc).join(' · ');
    const costStrip = (model || costLabel || tokensLabel) ? (
      '<div class="pipeline-cost-strip">' +
        '<div class="pipeline-cost-item"><span>Модель</span><strong>' + esc(model || '-') + '</strong></div>' +
        '<div class="pipeline-cost-item"><span>Стоимость</span><strong>' + esc(costLabel || '-') + '</strong></div>' +
        '<div class="pipeline-cost-item"><span>Токены</span><strong>' + esc(tokensLabel || '-') + '</strong></div>' +
        '<div class="pipeline-cost-item"><span>Статус</span><strong>' + esc(pipelineStatusLabel(status)) + '</strong></div>' +
      '</div>'
    ) : '';
    return '<details class="pipeline-step" data-pipeline-step-id="' + esc(String(opId)) + '" data-pipeline-step-key="' + esc(stateKey) + '"' + (open ? ' open' : '') + '>' +
      '<summary>' +
        '<span class="pipeline-step-title">' + esc(title) + '</span>' +
        '<span class="pipeline-step-meta">' +
          '<span class="pipeline-step-badge is-' + esc(status || 'unknown') + '">' + esc(pipelineStatusLabel(status)) + '</span>' +
        '</span>' +
      '</summary>' +
      '<div class="pipeline-step-progress"><span style="width:' + esc(String(pctWidth)) + '%"></span></div>' +
      '<div class="pipeline-step-body">' +
        costStrip +
        (meta ? '<div class="pipeline-step-meta" style="margin-bottom:8px;">' + meta + '</div>' : '') +
        (msg ? '<p class="pipeline-step-message">' + esc(msg) + '</p>' : '') +
        (err ? '<p class="pipeline-step-error">' + esc(err) + '</p>' : '') +
        '<pre class="pipeline-step-log">' + esc(log || 'Лог пока пуст.') + '</pre>' +
      '</div>' +
    '</details>';
  }).join('');
  bindPipelineStepOpenHandlers(list);
}

async function poll(){
  const r = await fetch('op_poll.php?id=' + encodeURIComponent(opId), {cache:'no-store'});
  const j = await r.json();

  // status + timestamps
  const s = document.getElementById('op_status');
  if (s) {
    s.textContent = j.status || '';
    s.className = 'status-pill status-' + statusClass(j.status || '');
  }

  const st = document.getElementById('op_started');
  if (st && j.started_at != null) window.ftSetLocalDateTime(st, j.started_at, {showSeconds:true});

  const fin = document.getElementById('op_finished');
  if (fin && j.finished_at != null) window.ftSetLocalDateTime(fin, j.finished_at, {showSeconds:true});

  const heartbeat = document.getElementById('heartbeat');
  if (heartbeat) {
    if (j.heartbeat_at) {
      window.ftSetLocalDateTime(heartbeat, j.heartbeat_at, {showSeconds:true});
    } else {
      heartbeat.textContent = '-';
    }
  }

  // progress
  const pct = (j.percent != null) ? (j.percent + '%') : '-';
  document.getElementById('pct').textContent = pct;
  document.getElementById('bar').style.width = (j.percent != null) ? j.percent+'%' : '0%';

  document.getElementById('elapsed').textContent = fmt(j.elapsed_sec);

  // ETA: берём с сервера, а если нет — считаем сами
  let etaSec = (j.eta_sec !== undefined) ? j.eta_sec : null;
  if (j.status === 'queued') {
    etaSec = (j.queue_wait_sec !== undefined) ? j.queue_wait_sec : null;
  } else if (etaSec == null && j.total > 0 && j.done > 0 && j.elapsed_sec >= 3) {
    const rate = j.done / j.elapsed_sec; // items/sec
    if (rate > 0) etaSec = Math.round((j.total - j.done) / rate);
  }
  document.getElementById('eta').textContent = fmt(etaSec);

  document.getElementById('stage').textContent = j.stage || '-';
  document.getElementById('dt').textContent = (j.total > 0) ? (j.done + '/' + j.total) : String(j.done);
  document.getElementById('msg').textContent = j.msg || '';
  renderQueueInfo(j.status, j.queue_ahead_count || 0, j.queue_wait_sec || 0, j.queue_blocker || null, !!j.cancel_requested);

  // log tail
  const logPre = document.getElementById('log_tail');
  if (logPre && typeof j.log_tail === 'string') logPre.textContent = j.log_tail;

  renderPipelineLogs(j.pipeline_steps, j.status || '');

  // artifacts (без перезагрузки)
  if (j.outputs !== undefined) {
    renderArtifacts(j.outputs);
    renderHtmlReportAction(j.outputs);
  }

  // summary
  if ((j.status === 'done' || j.status === 'error' || j.status === 'cancelled') && j.summary) {
    document.getElementById('summary').innerHTML = renderSummary(j.summary);
    document.getElementById('summaryCard').style.display = 'block';
  }

  if (j.status === 'queued' || j.status === 'running') {
    setTimeout(poll, 1000);
  }

  // auto redirect to report.php when done and report exists
  if (!__redirected && autoReport && (j.status === 'done' || j.status === 'error')) {
    if (j.outputs && j.outputs.report_json) {
      __redirected = true;
      window.location.href = 'report.php?op_id=' + encodeURIComponent(opId);
      return;
    }
  }
}

(function init(){
  // Показать summary сразу, если он уже есть (без ожидания poll)
  if (__initialSummary) {
    document.getElementById('summary').innerHTML = renderSummary(__initialSummary);
    document.getElementById('summaryCard').style.display = 'block';
  }
  if (__initialQueueSummary) {
    renderQueueInfo(
      <?= json_encode((string)($op['status'] ?? '')) ?>,
      __initialQueueSummary.ahead_count || 0,
      __initialQueueSummary.estimated_wait_sec || 0,
      __initialQueueSummary.blocker || null,
      <?= json_encode($cancelRequested) ?>
    );
  }
  poll();
})();
</script>

</body>
</html>
