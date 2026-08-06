<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
require_once __DIR__ . '/../app/llm/OpenAIPricing.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/llm/OpenAIRequestLog.php';
require_once __DIR__ . '/../app/ops.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$opId = isset($_GET['op_id']) ? (int)$_GET['op_id'] : 0;
$pipelineOpId = isset($_GET['pipeline_op_id']) ? (int)$_GET['pipeline_op_id'] : 0;
$datasetId = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;

$error = '';
$detail = null;
$rows = [];
$opIdsFilter = [];

try {
  if ($id > 0) {
    $detail = OpenAIRequestLog::get($id);
    if ($detail && !empty($detail['op_id'])) $opId = (int)$detail['op_id'];
  } else {
    if ($pipelineOpId > 0) {
      $opIdsFilter = ops_pipeline_related_op_ids($pipelineOpId);
      if (!$opIdsFilter) {
        $opIdsFilter = [$pipelineOpId];
      }
    }
    $rows = OpenAIRequestLog::latest([
      'op_ids' => $opIdsFilter ?: null,
      'op_id' => $opId > 0 ? $opId : null,
      'dataset_id' => $datasetId > 0 ? $datasetId : null,
    ], $limit);
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}

$backHref = 'index.php';
if ($pipelineOpId > 0) $backHref = 'op.php?id=' . urlencode((string)$pipelineOpId);
elseif ($opId > 0) $backHref = 'op.php?id=' . urlencode((string)$opId);
elseif ($datasetId > 0) $backHref = 'view.php?id=' . urlencode((string)$datasetId);

$decorateRow = static function (array $row) use ($cfg): array {
  $cost = openai_cost_breakdown(
    $cfg,
    (string)($row['model'] ?? ''),
    (int)($row['input_tokens'] ?? 0),
    (int)($row['cached_input_tokens'] ?? 0),
    (int)($row['output_tokens'] ?? 0)
  );
  if (!empty($row['local_cache_hit'])) {
    $cost['billable_input_tokens'] = 0;
    $cost['input_cost'] = 0.0;
    $cost['cached_input_cost'] = 0.0;
    $cost['output_cost'] = 0.0;
    $cost['cost'] = 0.0;
    $cost['cache_savings'] = 0.0;
    $cost['cost_label'] = openai_format_cost(0.0, (string)($cost['currency'] ?? 'USD'));
    $cost['cache_savings_label'] = openai_format_cost(0.0, (string)($cost['currency'] ?? 'USD'));
    $cost['input_cost_usd'] = 0.0;
    $cost['cached_input_cost_usd'] = 0.0;
    $cost['output_cost_usd'] = 0.0;
    $cost['cost_usd'] = 0.0;
    $cost['cache_savings_usd'] = 0.0;
  }
  $row['_cost'] = $cost;
  if (!empty($row['local_cache_hit'])) {
    $row['_cache_mode'] = ['label' => 'local hit', 'class' => 'cache'];
  } elseif ((int)($row['cached_input_tokens'] ?? 0) > 0) {
    $row['_cache_mode'] = ['label' => 'prompt hit', 'class' => 'cache'];
  } elseif (!empty($row['prompt_cache_key'])) {
    $row['_cache_mode'] = ['label' => 'prompt miss', 'class' => ''];
  } else {
    $row['_cache_mode'] = ['label' => 'no cache', 'class' => ''];
  }
  return $row;
};

$rowsSummary = [
  'requests' => 0,
  'api_requests' => 0,
  'local_hits' => 0,
  'prompt_hits' => 0,
  'input_tokens' => 0,
  'cached_input_tokens' => 0,
  'billable_input_tokens' => 0,
  'output_tokens' => 0,
  'cost_usd' => 0.0,
  'cache_savings_usd' => 0.0,
  'costs' => [],
  'cache_savings' => [],
];

if ($detail) {
  $detail = $decorateRow($detail);
} elseif ($rows) {
  foreach ($rows as $idx => $row) {
    $row = $decorateRow($row);
    $rows[$idx] = $row;
    $rowsSummary['requests']++;
    $rowsSummary['api_requests'] += empty($row['local_cache_hit']) ? 1 : 0;
    $rowsSummary['local_hits'] += !empty($row['local_cache_hit']) ? 1 : 0;
    $rowsSummary['prompt_hits'] += (empty($row['local_cache_hit']) && (int)($row['cached_input_tokens'] ?? 0) > 0) ? 1 : 0;
    $rowsSummary['input_tokens'] += (int)($row['_cost']['input_tokens'] ?? 0);
    $rowsSummary['cached_input_tokens'] += (int)($row['_cost']['cached_input_tokens'] ?? 0);
    $rowsSummary['billable_input_tokens'] += (int)($row['_cost']['billable_input_tokens'] ?? 0);
    $rowsSummary['output_tokens'] += (int)($row['_cost']['output_tokens'] ?? 0);
    $rowsSummary['cost_usd'] += (float)($row['_cost']['cost_usd'] ?? 0.0);
    $rowsSummary['cache_savings_usd'] += (float)($row['_cost']['cache_savings_usd'] ?? 0.0);
    $currency = (string)($row['_cost']['currency'] ?? 'USD');
    $rowsSummary['costs'][$currency] = (float)($rowsSummary['costs'][$currency] ?? 0.0) + (float)($row['_cost']['cost'] ?? $row['_cost']['cost_usd'] ?? 0.0);
    $rowsSummary['cache_savings'][$currency] = (float)($rowsSummary['cache_savings'][$currency] ?? 0.0) + (float)($row['_cost']['cache_savings'] ?? $row['_cost']['cache_savings_usd'] ?? 0.0);
  }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Журнал GPT-запросов</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    :root{--border:#e5e7eb;--muted:#6b7280;--ink:#111827;--bg:#f8fafc;--ok:#047857;--bad:#b91c1c;--blue:#2563eb;}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1280px;margin:24px auto;padding:0 14px;color:var(--ink);background:#fff;}
    a{color:var(--ink);}
    .muted{color:var(--muted);}
    .card{border:1px solid var(--border);border-radius:16px;padding:16px;margin:14px 0;background:#fff;}
    .row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
    label{display:block;font-size:13px;color:var(--muted);margin-bottom:4px;}
    input,select{border:1px solid #d1d5db;border-radius:10px;padding:9px 10px;font:inherit;}
    button,.btn{display:inline-block;border:1px solid var(--ink);border-radius:10px;padding:9px 12px;background:var(--ink);color:#fff;text-decoration:none;cursor:pointer;}
    table{width:100%;border-collapse:collapse;font-size:13px;}
    th,td{border-bottom:1px solid var(--border);padding:9px 8px;text-align:left;vertical-align:top;}
    th{background:var(--bg);position:sticky;top:0;}
    code{background:#f3f4f6;border-radius:7px;padding:2px 5px;}
    pre{white-space:pre-wrap;word-break:break-word;background:#0b1020;color:#d1d5db;padding:12px;border-radius:12px;font-size:12px;max-height:520px;overflow:auto;}
    .pill{display:inline-block;border-radius:999px;padding:3px 8px;background:#f3f4f6;}
    .ok{color:var(--ok);}
    .bad{color:var(--bad);}
    .cache{background:#ecfdf5;color:#047857;}
    .grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;}
    @media(max-width:900px){.grid{grid-template-columns:1fr;} table{font-size:12px;}}
  </style>
</head>
<body>
  <?= ft_top_navigation([
    'back_href' => $backHref,
    'back_label' => 'Назад',
    'links' => ft_default_nav_links(''),
  ]) ?>
  <h1>Журнал GPT-запросов</h1>

  <?php if ($error): ?>
    <div class="card" style="border-color:#fecaca;color:#b91c1c;"><?=h($error)?></div>
  <?php endif; ?>

  <?php if ($detail): ?>
    <div class="card">
      <h2>Запрос #<?=h($detail['id'])?></h2>
      <?php $detailCost = $detail['_cost'] ?? []; ?>
      <div class="grid">
        <div><span class="muted">Дата</span><br><?=ft_local_datetime_html((string)($detail['created_at'] ?? ''), ['show_seconds' => true])?></div>
        <div><span class="muted">Операция</span><br><?= $detail['op_id'] ? '<a href="op.php?id='.h($detail['op_id']).'">#'.h($detail['op_id']).'</a>' : '—' ?></div>
        <div><span class="muted">Модель</span><br><code><?=h($detail['model'])?></code></div>
        <div><span class="muted">Статус</span><br><span class="<?= $detail['status']==='ok' ? 'ok' : 'bad' ?>"><?=h($detail['status'])?></span></div>
        <div><span class="muted">Время</span><br><?=h($detail['duration_ms'])?> ms</div>
        <div><span class="muted">Кэш</span><br><span class="pill <?=h($detail['_cache_mode']['class'] ?? '')?>"><?=h($detail['_cache_mode']['label'] ?? 'no cache')?></span></div>
        <div><span class="muted">Токены</span><br>in <?=h($detail['input_tokens'])?> / cached <?=h($detail['cached_input_tokens'])?> / billable <?=h($detailCost['billable_input_tokens'] ?? 0)?> / out <?=h($detail['output_tokens'])?></div>
        <div><span class="muted">Стоимость</span><br><?=h((string)($detailCost['cost_label'] ?? openai_format_cost((float)($detailCost['cost_usd'] ?? 0.0), (string)($detailCost['currency'] ?? 'USD'))))?></div>
        <div><span class="muted">Экономия от cache</span><br><?=h((string)($detailCost['cache_savings_label'] ?? openai_format_cost((float)($detailCost['cache_savings_usd'] ?? 0.0), (string)($detailCost['currency'] ?? 'USD'))))?></div>
        <div><span class="muted">prompt_cache_key</span><br><code><?=h($detail['prompt_cache_key'] ?: '—')?></code></div>
        <div><span class="muted">Тариф</span><br><code><?=h(($detailCost['pricing']['matched_model'] ?? $detail['model']) . ' · ' . number_format((float)($detailCost['pricing']['input_per_1m'] ?? 0), 2) . ' / ' . number_format((float)($detailCost['pricing']['cached_input_per_1m'] ?? 0), 3) . ' / ' . number_format((float)($detailCost['pricing']['output_per_1m'] ?? 0), 2) . ' ' . (string)($detailCost['currency'] ?? 'USD') . '/1M')?></code></div>
      </div>
      <?php if (!empty($detail['error_text'])): ?>
        <h3>Ошибка</h3>
        <pre><?=h($detail['error_text'])?></pre>
      <?php endif; ?>
      <?php if (!empty($detail['response_text'])): ?>
        <h3>Ответ текстом</h3>
        <pre><?=h($detail['response_text'])?></pre>
      <?php endif; ?>
      <h3>Запрос</h3>
      <pre><?=h($detail['request_json'] ?? '')?></pre>
      <h3>Raw response</h3>
      <pre><?=h($detail['response_json'] ?? '')?></pre>
    </div>
  <?php else: ?>
    <div class="card">
      <form class="row" method="get">
        <?php if ($pipelineOpId > 0): ?>
          <input type="hidden" name="pipeline_op_id" value="<?=h($pipelineOpId)?>">
        <?php endif; ?>
        <div>
          <label>op_id</label>
          <input name="op_id" value="<?=h($opId > 0 ? $opId : '')?>" placeholder="например 123">
        </div>
        <div>
          <label>dataset_id</label>
          <input name="dataset_id" value="<?=h($datasetId > 0 ? $datasetId : '')?>" placeholder="например 5">
        </div>
        <div>
          <label>лимит</label>
          <input name="limit" value="<?=h($limit)?>" style="width:90px;">
        </div>
        <button type="submit">Показать</button>
      </form>
    </div>

    <div class="card">
      <h2>Сводка</h2>
      <div class="grid">
        <div><span class="muted">Запросов</span><br><?=h($rowsSummary['requests'])?></div>
        <div><span class="muted">API запросов</span><br><?=h($rowsSummary['api_requests'])?></div>
        <div><span class="muted">Local cache hit</span><br><?=h($rowsSummary['local_hits'])?></div>
        <div><span class="muted">Prompt cache hit</span><br><?=h($rowsSummary['prompt_hits'])?></div>
        <div><span class="muted">Input</span><br><?=h($rowsSummary['input_tokens'])?></div>
        <div><span class="muted">Cached input</span><br><?=h($rowsSummary['cached_input_tokens'])?></div>
        <div><span class="muted">Billable input</span><br><?=h($rowsSummary['billable_input_tokens'])?></div>
        <div><span class="muted">Output</span><br><?=h($rowsSummary['output_tokens'])?></div>
        <div><span class="muted">Стоимость</span><br><?=h(openai_format_cost_map((array)$rowsSummary['costs']))?></div>
        <div><span class="muted">Экономия от cache</span><br><?=h(openai_format_cost_map((array)$rowsSummary['cache_savings']))?></div>
      </div>
    </div>

    <div class="card">
      <h2><?= $pipelineOpId > 0 ? 'GPT-запросы конвейера' : 'Последние запросы' ?></h2>
      <?php if (!$rows): ?>
        <p class="muted">Пока нет записей.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Дата</th>
              <th>Операция</th>
              <th>Модель</th>
              <th>Статус</th>
              <th>Кэш</th>
              <th>Токены</th>
              <th>Стоимость</th>
              <th>Экономия</th>
              <th>Время</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?=h($r['id'])?></td>
                <td><?=ft_local_datetime_html((string)($r['created_at'] ?? ''), ['show_seconds' => true])?></td>
                <td>
                  <?php if (!empty($r['op_id'])): ?>
                    <a href="op.php?id=<?=h($r['op_id'])?>">#<?=h($r['op_id'])?></a>
                    <div class="muted"><?=h($r['op_type'])?></div>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><code><?=h($r['model'])?></code></td>
                <td><span class="<?= $r['status']==='ok' ? 'ok' : 'bad' ?>"><?=h($r['status'])?></span></td>
                <td><span class="pill <?=h($r['_cache_mode']['class'] ?? '')?>"><?=h($r['_cache_mode']['label'] ?? 'no cache')?></span></td>
                <td>in <?=h($r['input_tokens'])?> / cached <?=h($r['cached_input_tokens'])?> / billable <?=h($r['_cost']['billable_input_tokens'] ?? 0)?> / out <?=h($r['output_tokens'])?></td>
                <td><?=h((string)($r['_cost']['cost_label'] ?? openai_format_cost((float)($r['_cost']['cost_usd'] ?? 0.0), (string)($r['_cost']['currency'] ?? 'USD'))))?></td>
                <td><?=h((string)($r['_cost']['cache_savings_label'] ?? openai_format_cost((float)($r['_cost']['cache_savings_usd'] ?? 0.0), (string)($r['_cost']['currency'] ?? 'USD'))))?></td>
                <td><?=h($r['duration_ms'])?> ms</td>
                <td><a href="gpt_log.php?id=<?=h($r['id'])?><?= $pipelineOpId > 0 ? '&pipeline_op_id=' . urlencode((string)$pipelineOpId) : '' ?>">открыть</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</body>
</html>
