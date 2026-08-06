<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/supplier_content_progress.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/time_display.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scps_fmt_int($value): string
{
    return number_format((int)$value, 0, '.', ' ');
}

function scps_fmt_score($value): string
{
    return number_format((float)$value, 1, '.', ' ');
}

function scps_status_label(string $status): string
{
    return [
        'not_uploaded' => 'не загружен',
        'archived' => 'архив',
        'error' => 'ошибка',
        'revision' => 'доработка',
        'uploaded_not_ready' => 'загружен',
        'ready_not_sellable' => 'готов',
        'sellable' => 'продается',
    ][$status] ?? $status;
}

function scps_status_class(string $status): string
{
    if ($status === 'sellable') return 'good';
    if ($status === 'ready_not_sellable' || $status === 'uploaded_not_ready') return 'blue';
    if ($status === 'revision') return 'warn';
    if ($status === 'error') return 'bad';
    return 'gray';
}

function scps_delta($value, string $suffix = ''): string
{
    if ($value === null || $value === '') return '—';
    $num = (float)$value;
    if (abs($num) < 0.01) return '0' . $suffix;
    return ($num > 0 ? '+' : '') . (floor($num) == $num ? (string)(int)$num : number_format($num, 1, '.', ' ')) . $suffix;
}

function scps_delta_class($value, bool $inverse = false): string
{
    $num = (float)$value;
    if (abs($num) < 0.01) return 'flat';
    $good = $inverse ? $num < 0 : $num > 0;
    return $good ? 'up' : 'down';
}

function scps_actor_kind_label(string $kind): string
{
    return [
        'human' => 'пользователь',
        'automation' => 'автоматизация',
        'system' => 'система',
        'unknown' => 'не указан',
    ][$kind] ?? $kind;
}

function scps_products_group_href(array $connections, int $supplierId, string $contentFilter, string $issueCode = ''): string
{
    $args = [
        'limit' => 100,
        'page' => 1,
        'filter_apply' => 1,
        'content_filter' => $contentFilter,
    ];
    $datasetId = (int)(($connections['dataset_id'] ?? 0) ?: 0);
    if ($datasetId > 0) {
        $args['id'] = $datasetId;
    } else {
        $args['supplier_id'] = $supplierId;
    }
    if ($issueCode !== '') {
        $args['content_issue'] = $issueCode;
    }
    return 'supplier_products_view.php?' . http_build_query($args);
}

$supplierId = max(0, (int)($_GET['supplier_id'] ?? 0));
$error = '';
$report = [];
try {
    if ($supplierId <= 0) {
        throw new RuntimeException('Не указан supplier_id.');
    }
    $report = supplier_content_progress_fetch_supplier($supplierId, $cfg, [
        'preset' => $_GET['preset'] ?? '7d',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'refresh' => isset($_GET['refresh']) && (string)$_GET['refresh'] === '1',
    ]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$supplier = (array)($report['supplier'] ?? []);
$snapshot = (array)($report['snapshot'] ?? []);
$metrics = (array)($report['metrics'] ?? []);
$priority = (array)($report['priority'] ?? []);
$connections = (array)($report['connections'] ?? []);
$period = (array)($report['period'] ?? supplier_content_progress_parse_period($_GET));
$delta = (array)($report['delta'] ?? []);
$deepDelta = (array)($report['deep_delta'] ?? []);
$analytics = (array)($report['analytics'] ?? []);
$analyticsOverview = (array)($analytics['overview'] ?? []);
$analyticsStateBuckets = (array)($analytics['state_buckets'] ?? []);
$analyticsStatuses = (array)($analytics['status_by_marketplace'] ?? []);
$analyticsMarketplaces = (array)($analytics['marketplace_gaps'] ?? []);
$analyticsQuality = (array)($analytics['quality_bands'] ?? []);
$analyticsDimensions = (array)($analytics['quality_dimensions'] ?? []);
$analyticsFieldGaps = (array)($analytics['field_gaps'] ?? []);
$analyticsIssues = (array)($analytics['top_issues'] ?? []);
$analyticsRecommendations = (array)($analytics['recommendations'] ?? []);
$contributions = (array)($report['contributions'] ?? []);
$contributionOverview = (array)($contributions['overview'] ?? []);
$contributionActors = (array)($contributions['by_actor'] ?? []);
$contributionKinds = (array)($contributions['by_kind'] ?? []);
$contributionEvents = (array)($contributions['events'] ?? []);
$byMarketplace = (array)($metrics['by_marketplace'] ?? []);
$issueBreakdown = (array)($metrics['issue_breakdown'] ?? []);
$confidence = (array)($metrics['data_confidence'] ?? []);
$score = (float)($snapshot['content_progress_score'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — Контент поставщика</title>
  <?= ft_navigation_assets() ?>
  <?= ft_time_display_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f7fb;
      --panel: #fff;
      --ink: #172033;
      --muted: #64748b;
      --line: #d9e5f2;
      --soft: #f8fbff;
      --blue: #2563eb;
      --green: #12805c;
      --yellow: #b7791f;
      --red: #b42318;
      --gray: #64748b;
      --shadow: 0 18px 44px rgba(25, 54, 90, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
      color: var(--ink);
      font: 15px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .shell {
      max-width: 1460px;
      margin: 0 auto;
      padding: 24px 18px 44px;
    }
    h1 {
      margin: 0;
      font-size: clamp(28px, 4vw, 42px);
      line-height: 1;
      letter-spacing: 0;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 20px;
      letter-spacing: 0;
    }
    .head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .sub {
      margin-top: 8px;
      color: var(--muted);
      font-size: 15px;
    }
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .btn {
      min-height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 13px;
      border-radius: 10px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 900;
    }
    .btn.secondary {
      background: #fff;
      color: var(--ink);
      border-color: var(--line);
    }
    .score-band {
      display: grid;
      grid-template-columns: 290px 1fr;
      gap: 14px;
      margin-bottom: 16px;
    }
    .score-card, .panel, .metric, .market-card {
      border: 1px solid var(--line);
      background: var(--panel);
      box-shadow: var(--shadow);
      border-radius: 16px;
    }
    .score-card {
      padding: 18px;
      display: grid;
      gap: 14px;
      align-content: center;
    }
    .score-number {
      width: 158px;
      height: 158px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      margin: 0 auto;
      background:
        radial-gradient(circle at center, #fff 0 58%, transparent 59%),
        conic-gradient(var(--green) 0 var(--p), #e8eef7 0 100%);
      border: 1px solid #e3ebf5;
    }
    .score-number strong {
      display: block;
      font-size: 34px;
      line-height: 1;
      font-weight: 950;
    }
    .score-number span {
      display: block;
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
      text-align: center;
      margin-top: 4px;
    }
    .score-parts {
      display: grid;
      grid-template-columns: repeat(4, minmax(120px, 1fr));
      gap: 10px;
    }
    .score-main {
      display: grid;
      gap: 10px;
      align-content: start;
    }
    .formula-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
    }
    .formula-strip span {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 9px;
      border-radius: 999px;
      background: #f1f5f9;
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
    }
    .metric {
      min-height: 112px;
      padding: 14px;
      display: grid;
      align-content: space-between;
      gap: 8px;
    }
    .metric .k {
      color: var(--muted);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .metric .v {
      font-size: 26px;
      line-height: 1;
      font-weight: 950;
    }
    .bar {
      height: 9px;
      border-radius: 999px;
      background: #e8eef7;
      overflow: hidden;
    }
    .bar span {
      display: block;
      height: 100%;
      width: var(--w);
      border-radius: inherit;
      background: var(--blue);
    }
    .bar.good span { background: var(--green); }
    .bar.warn span { background: #d99a24; }
    .bar.bad span { background: var(--red); }
    .grid-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin: 16px 0;
    }
    .market-card, .panel {
      padding: 16px;
    }
    .change-block {
      padding: 0;
    }
    .market-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: baseline;
      margin-bottom: 12px;
    }
    .market-top strong {
      font-size: 22px;
      font-weight: 950;
    }
    .market-stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
    }
    .mini {
      padding: 10px;
      border: 1px solid #e6eef8;
      border-radius: 12px;
      background: #fbfdff;
    }
    .mini .k {
      color: var(--muted);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .mini .v {
      margin-top: 6px;
      font-weight: 950;
      font-size: 20px;
    }
    .analytics-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.08fr) minmax(0, .92fr);
      gap: 14px;
      margin: 16px 0;
    }
    .analytics-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
      margin-bottom: 12px;
    }
    .analytics-top .muted {
      max-width: 640px;
      text-align: right;
    }
    .overview-grid {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 8px;
      margin-bottom: 12px;
    }
    .state-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 9px;
    }
    .state-item {
      min-height: 96px;
      display: grid;
      align-content: space-between;
      gap: 8px;
      padding: 12px;
      border: 1px solid #e6eef8;
      border-radius: 12px;
      background: #fbfdff;
      color: var(--ink);
      text-decoration: none;
    }
    .state-item .k {
      color: var(--muted);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .state-item .v {
      font-size: 28px;
      line-height: 1;
      font-weight: 950;
    }
    .state-item.bad { border-color: #fecaca; background: #fff8f8; }
    .state-item.warn { border-color: #fde68a; background: #fffcf1; }
    .state-item.good { border-color: #bbf7d0; background: #f2fdf7; }
    a.state-item:hover, .mini-link:hover {
      border-color: #b9cdf0;
      background: #f8fbff;
      transform: translateY(-1px);
    }
    .mini-link {
      display: block;
      color: var(--ink);
      text-decoration: none;
    }
    .row-stack {
      display: grid;
      gap: 9px;
    }
    .status-line, .band-line, .gap-line, .dimension-line {
      display: grid;
      grid-template-columns: minmax(145px, 1fr) minmax(120px, 2fr) 76px;
      gap: 10px;
      align-items: center;
      min-height: 32px;
    }
    .status-line b, .band-line b, .gap-line b, .dimension-line b {
      font-size: 13px;
    }
    .meter {
      height: 9px;
      border-radius: 999px;
      background: #e8eef7;
      overflow: hidden;
    }
    .meter span {
      display: block;
      height: 100%;
      width: var(--w);
      min-width: var(--minw, 0);
      border-radius: inherit;
      background: var(--gray);
    }
    .meter.good span { background: var(--green); }
    .meter.blue span { background: var(--blue); }
    .meter.warn span { background: #d99a24; }
    .meter.bad span { background: var(--red); }
    .quality-split {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }
    .quality-block {
      padding-top: 2px;
    }
    .quality-title, .gap-title {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: baseline;
      margin-bottom: 8px;
    }
    .quality-title b, .gap-title b {
      font-size: 15px;
      font-weight: 950;
    }
    .reco-list {
      display: grid;
      gap: 9px;
    }
    .reco {
      padding: 12px;
      border: 1px solid #e6eef8;
      border-radius: 12px;
      background: #fbfdff;
    }
    .reco b {
      display: block;
      margin-bottom: 4px;
      font-size: 14px;
      font-weight: 950;
    }
    .reco.bad { border-color: #fecaca; background: #fff8f8; }
    .reco.warn { border-color: #fde68a; background: #fffcf1; }
    .reco.good { border-color: #bbf7d0; background: #f2fdf7; }
    .market-gap-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .market-gap {
      border: 1px solid #e6eef8;
      border-radius: 12px;
      padding: 12px;
      background: #fbfdff;
    }
    .issue-list {
      display: grid;
      gap: 8px;
    }
    .issue-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
      padding-bottom: 8px;
      border-bottom: 1px solid #e7edf5;
    }
    .issue-row:last-child {
      padding-bottom: 0;
      border-bottom: 0;
    }
    .right {
      text-align: right;
    }
    .contrib-grid {
      display: grid;
      grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
      gap: 14px;
      margin: 16px 0;
    }
    .compact-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    .compact-table th,
    .compact-table td {
      padding: 10px 8px;
    }
    .op-link {
      color: var(--ink);
      font-weight: 950;
      text-decoration: none;
    }
    .op-link:hover {
      color: var(--blue);
    }
    .analysis-link {
      color: var(--ink);
      text-decoration: none;
    }
    .analysis-link:hover {
      color: var(--blue);
      background: #f8fbff;
    }
    .funnel {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 9px;
    }
    .step {
      min-height: 82px;
      padding: 12px;
      border: 1px solid #e6eef8;
      border-radius: 12px;
      background: #fbfdff;
      display: grid;
      gap: 7px;
    }
    .step b {
      font-size: 22px;
      line-height: 1;
    }
    .step span {
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
    }
    .chip, .status {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 9px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      background: #f1f5f9;
      color: var(--gray);
    }
    .chip.bad, .status.bad { background: #fff1f2; color: var(--red); }
    .chip.warn, .status.warn { background: #fffbeb; color: var(--yellow); }
    .chip.good, .status.good { background: #ecfdf5; color: var(--green); }
    .chip.blue { background: #eef5ff; color: var(--blue); }
    .chip.gray { background: #f1f5f9; color: var(--gray); }
    .status.blue { background: #eef5ff; color: var(--blue); }
    .priority-box {
      display: grid;
      gap: 3px;
      padding: 12px;
      border-radius: 14px;
      border: 1px solid #e6eef8;
      background: #fbfdff;
      text-align: center;
    }
    .priority-box span {
      color: var(--muted);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .priority-box strong {
      font-size: 20px;
      line-height: 1.05;
      font-weight: 950;
    }
    .priority-box small {
      color: var(--muted);
      font-weight: 800;
    }
    .priority-box.red { border-color: #fecaca; background: #fff8f8; }
    .priority-box.red strong { color: var(--red); }
    .priority-box.yellow { border-color: #fde68a; background: #fffcf1; }
    .priority-box.yellow strong { color: var(--yellow); }
    .priority-box.blue { border-color: #bfdbfe; background: #f5f9ff; }
    .priority-box.blue strong { color: var(--blue); }
    .priority-box.green { border-color: #bbf7d0; background: #f2fdf7; }
    .priority-box.green strong { color: var(--green); }
    .delta {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 26px;
      min-width: 58px;
      padding: 0 9px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 950;
      background: #f1f5f9;
      color: var(--gray);
    }
    .delta.up { background: #ecfdf5; color: var(--green); }
    .delta.down { background: #fff1f2; color: var(--red); }
    .delta.flat { background: #f1f5f9; color: var(--gray); }
    .table-wrap {
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: 16px;
      background: #fff;
      box-shadow: var(--shadow);
      margin-top: 16px;
    }
    .table-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    th, td {
      padding: 11px 10px;
      border-bottom: 1px solid #e7edf5;
      text-align: left;
      vertical-align: top;
    }
    th {
      color: var(--muted);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .05em;
      background: #fbfdff;
      white-space: nowrap;
    }
    tr:hover td { background: #fbfdff; }
    .muted { color: var(--muted); }
    .product-title {
      font-weight: 900;
      max-width: 360px;
    }
    .product-sub {
      margin-top: 4px;
      color: var(--muted);
      font-size: 12px;
    }
    .error {
      padding: 14px 16px;
      border: 1px solid #fecaca;
      border-radius: 14px;
      background: #fff1f2;
      color: var(--red);
      font-weight: 800;
      margin: 16px 0;
    }
    .filters {
      margin-bottom: 16px;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(255,255,255,.84);
      box-shadow: 0 10px 24px rgba(25, 54, 90, .05);
    }
    .filters form {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: end;
    }
    label {
      display: grid;
      gap: 5px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    select, input[type=date] {
      min-height: 40px;
      min-width: 150px;
      border: 1px solid #c9d7e8;
      border-radius: 10px;
      padding: 0 10px;
      color: var(--ink);
      background: #fff;
      font: inherit;
      text-transform: none;
      letter-spacing: 0;
    }
    button {
      min-height: 40px;
      border: 1px solid var(--blue);
      border-radius: 10px;
      padding: 0 14px;
      background: var(--blue);
      color: #fff;
      font: inherit;
      font-weight: 900;
      cursor: pointer;
    }
    @media (max-width: 1100px) {
      .score-band, .grid-2, .analytics-grid, .contrib-grid { grid-template-columns: 1fr; }
      .score-parts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .funnel { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .overview-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .state-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .table-wrap { overflow-x: auto; }
      table { min-width: 1060px; }
    }
    @media (max-width: 640px) {
      .shell { padding-left: 12px; padding-right: 12px; }
      .score-parts, .market-stats, .funnel, .overview-grid, .state-grid, .market-gap-grid { grid-template-columns: 1fr; }
      .analytics-top { display: grid; }
      .analytics-top .muted { text-align: left; }
      .status-line, .band-line, .gap-line, .dimension-line { grid-template-columns: 1fr; gap: 5px; }
      label, select, input[type=date], button { width: 100%; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <?= ft_top_navigation([
      'back_href' => 'supplier_content_progress.php',
      'back_label' => 'К прогрессу',
      'active' => 'content_progress',
      'links' => ft_default_nav_links('content_progress'),
    ]) ?>

    <section class="head">
      <div>
        <h1 data-ft-i18n="off"><?= h($supplier['name'] ?? 'Поставщик') ?></h1>
        <div class="sub">Контентная загрузка, качество карточек, ошибки и продаваемость по Ozon/WB. Товары без остатка не входят в цель выгрузки. Код поставщика: <b><?= h($supplier['supplier_code'] ?? '') ?></b></div>
      </div>
      <div class="actions">
        <a class="btn secondary" href="supplier_content_progress_supplier.php?<?= h(http_build_query(array_merge($_GET, ['supplier_id' => $supplierId, 'refresh' => 1]))) ?>">Пересчитать</a>
        <?php if (!empty($connections['dataset_id']) || !empty($supplierId)): ?>
          <?php $datasetId = (int)(($connections['dataset_id'] ?? 0) ?: 0); ?>
          <?php if ($datasetId > 0): ?><a class="btn" href="supplier_products_view.php?id=<?= h((string)$datasetId) ?>">Открыть товары</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="filters">
      <form method="get">
        <input type="hidden" name="supplier_id" value="<?= h((string)$supplierId) ?>">
        <label>
          Период
          <select name="preset">
            <?php foreach (['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', '90d' => '90 дней', 'custom' => 'Свой'] as $value => $labelText): ?>
              <option value="<?= h($value) ?>" <?= (string)($period['preset'] ?? '') === $value ? 'selected' : '' ?>><?= h($labelText) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          От
          <input type="date" name="from" value="<?= h($period['from_date'] ?? '') ?>">
        </label>
        <label>
          До
          <input type="date" name="to" value="<?= h($period['to_date'] ?? '') ?>">
        </label>
        <button type="submit">Показать</button>
      </form>
    </section>

    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="score-band">
      <div class="score-card">
        <div class="score-number" style="--p: <?= h((string)max(0, min(100, $score))) ?>%;">
          <div><strong><?= h(scps_fmt_score($score)) ?></strong><span>content progress</span></div>
        </div>
        <div class="chips" style="justify-content:center;">
          <?php $level = (string)($confidence['level'] ?? ($snapshot['data_confidence_level'] ?? 'medium')); ?>
          <span class="chip <?= $level === 'high' ? 'good' : ($level === 'low' ? 'bad' : 'warn') ?>">
            <?= h(['high' => 'данные свежие', 'medium' => 'средняя уверенность', 'low' => 'низкая уверенность'][$level] ?? 'средняя уверенность') ?>
          </span>
        </div>
        <div class="priority-box <?= h((string)($priority['class'] ?? 'gray')) ?>">
          <span>Приоритет</span>
          <strong><?= h((string)($priority['label'] ?? '—')) ?> · <?= h(scps_fmt_score($priority['score'] ?? 0)) ?></strong>
          <small>фокус: <?= h((string)($priority['focus'] ?? 'Контент')) ?></small>
        </div>
        <div class="chips" style="justify-content:center;">
          <?php foreach (array_slice((array)($priority['reasons'] ?? []), 0, 3) as $reason): ?>
            <span class="chip <?= str_contains((string)$reason, 'ошиб') || str_contains((string)$reason, 'динамика') ? 'bad' : 'gray' ?>"><?= h($reason) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="score-main">
        <div class="score-parts">
          <?php foreach ([
            ['label' => 'Загрузка', 'value' => $snapshot['upload_score'] ?? 0, 'class' => ''],
            ['label' => 'Продается', 'value' => $snapshot['sellable_score'] ?? 0, 'class' => 'good'],
            ['label' => 'Качество', 'value' => $snapshot['avg_card_quality_score'] ?? 0, 'class' => ''],
            ['label' => 'Без ошибок', 'value' => $snapshot['error_health_score'] ?? 0, 'class' => 'warn'],
          ] as $part): ?>
            <?php $value = (float)$part['value']; ?>
            <div class="metric">
              <div class="k"><?= h($part['label']) ?></div>
              <div class="v"><?= h(scps_fmt_score($value)) ?></div>
              <div class="bar <?= h($value >= 80 ? 'good' : ($value < 55 ? 'bad' : 'warn')) ?>" style="--w: <?= h((string)max(0, min(100, $value))) ?>%;"><span></span></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="formula-strip">
          <span>загрузка 35%</span>
          <span>продаваемость 25%</span>
          <span>качество 25%</span>
          <span>ошибки 15%</span>
        </div>
      </div>
    </section>

    <section class="panel">
      <h2>Воронка контента</h2>
      <div class="funnel">
        <div class="step"><span>товаров всего</span><b><?= h(scps_fmt_int($snapshot['products_total'] ?? 0)) ?></b></div>
        <div class="step"><span>с остатком</span><b><?= h(scps_fmt_int($metrics['target_products_total'] ?? 0)) ?></b></div>
        <div class="step"><span>загружено</span><b><?= h(scps_fmt_int($snapshot['uploaded_total'] ?? 0)) ?></b></div>
        <div class="step"><span>готово</span><b><?= h(scps_fmt_int($snapshot['ready_total'] ?? 0)) ?></b></div>
        <div class="step"><span>продается</span><b><?= h(scps_fmt_int($snapshot['sellable_total'] ?? 0)) ?></b></div>
        <div class="step"><span>без остатка</span><b><?= h(scps_fmt_int($metrics['out_of_stock_total'] ?? 0)) ?></b></div>
      </div>
      <div class="chips" style="margin-top:14px;">
        <?php foreach (array_slice((array)($metrics['main_reasons'] ?? []), 0, 8) as $reason): ?>
          <span class="chip <?= str_contains((string)$reason, 'ошиб') || str_contains((string)$reason, 'доработ') ? 'bad' : 'gray' ?>"><?= h($reason) ?></span>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="grid-2">
      <?php foreach (['ozon' => 'Ozon', 'wb' => 'Wildberries'] as $mp => $label): ?>
        <?php $mpRow = (array)($byMarketplace[$mp] ?? []); ?>
        <div class="market-card">
          <div class="market-top">
            <h2><?= h($label) ?></h2>
            <strong><?= h(scps_fmt_score($mpRow['completion_score'] ?? 0)) ?></strong>
          </div>
          <div class="bar <?= ((float)($mpRow['completion_score'] ?? 0) >= 80 ? 'good' : ((float)($mpRow['completion_score'] ?? 0) < 55 ? 'bad' : 'warn')) ?>" style="--w: <?= h((string)max(0, min(100, (float)($mpRow['completion_score'] ?? 0)))) ?>%;"><span></span></div>
          <div class="market-stats" style="margin-top:14px;">
            <div class="mini"><div class="k">Загружено</div><div class="v"><?= h(scps_fmt_int($mpRow['uploaded'] ?? 0)) ?></div></div>
            <div class="mini"><div class="k">Готово</div><div class="v"><?= h(scps_fmt_int($mpRow['ready'] ?? 0)) ?></div></div>
            <div class="mini"><div class="k">Продается</div><div class="v"><?= h(scps_fmt_int($mpRow['sellable'] ?? 0)) ?></div></div>
            <div class="mini"><div class="k">Ошибки</div><div class="v"><?= h(scps_fmt_int(((int)($mpRow['error'] ?? 0)) + ((int)($mpRow['revision'] ?? 0)))) ?></div></div>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="panel">
      <div class="analytics-top">
        <div>
          <h2>Оценка состояния поставщика</h2>
          <div class="muted">Агрегированный срез только по товарам с остатком. Товарные позиции и строки Ozon/WB считаются отдельно, без постановки задач по отдельным товарам.</div>
        </div>
        <?php $focus = (array)($analytics['focus_marketplace'] ?? []); ?>
        <?php if ($focus): ?>
          <span class="chip <?= (int)($focus['error_revision'] ?? 0) > 0 ? 'bad' : 'warn' ?>">
            Фокус: <?= h($focus['label'] ?? '') ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="overview-grid">
        <div class="mini"><div class="k">Товаров в цели</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['target_products'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Строк MP</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['marketplace_rows'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Загружено хоть куда</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['uploaded_any'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Загружено везде</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['uploaded_all'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Продается хоть где</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['sellable_any'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Продается везде</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['sellable_all'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Есть пробелы</div><div class="v"><?= h(scps_fmt_int($analyticsOverview['with_issues'] ?? 0)) ?></div></div>
      </div>
      <div class="state-grid">
        <?php foreach ($analyticsStateBuckets as $bucket): ?>
          <?php $bucketKey = (string)($bucket['key'] ?? ''); ?>
          <a class="state-item <?= h((string)($bucket['class'] ?? '')) ?>" href="<?= h(scps_products_group_href($connections, $supplierId, $bucketKey)) ?>">
            <div class="k"><?= h($bucket['label'] ?? '') ?></div>
            <div>
              <div class="v"><?= h(scps_fmt_int($bucket['count'] ?? 0)) ?></div>
              <div class="product-sub"><?= h(scps_fmt_score($bucket['percent'] ?? 0)) ?>% · открыть группу</div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="analytics-grid">
      <div class="panel">
        <h2>Статусы по маркетплейсам</h2>
        <div class="row-stack">
          <?php foreach ($analyticsStatuses as $mpStatus): ?>
            <div>
              <div class="quality-title">
                <b><?= h($mpStatus['label'] ?? '') ?></b>
                <span class="muted"><?= h(scps_fmt_int($mpStatus['total'] ?? 0)) ?> строк</span>
              </div>
              <div class="row-stack">
                <?php foreach ((array)($mpStatus['statuses'] ?? []) as $statusRow): ?>
                  <?php
                    $statusCount = (int)($statusRow['count'] ?? 0);
                    $statusPercent = (float)($statusRow['percent'] ?? 0);
                  ?>
                  <?php if ($statusCount <= 0 && !in_array((string)($statusRow['key'] ?? ''), ['sellable', 'not_uploaded'], true)) continue; ?>
                  <div class="status-line">
                    <b><?= h($statusRow['label'] ?? '') ?></b>
                    <div class="meter <?= h((string)($statusRow['class'] ?? 'gray')) ?>" style="--w: <?= h((string)max(0, min(100, $statusPercent))) ?>%;"><span></span></div>
                    <span class="muted"><?= h(scps_fmt_int($statusCount)) ?> · <?= h(scps_fmt_score($statusPercent)) ?>%</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel">
        <h2>Интерпретация состояния</h2>
        <div class="reco-list">
          <?php if (!$analyticsRecommendations): ?><div class="muted">Нет заметных просадок для текущего состояния.</div><?php endif; ?>
          <?php foreach ($analyticsRecommendations as $recommendation): ?>
            <div class="reco <?= h((string)($recommendation['class'] ?? '')) ?>">
              <b><?= h($recommendation['title'] ?? '') ?></b>
              <span class="muted"><?= h($recommendation['text'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="analytics-grid">
      <div class="panel">
        <h2>Качество карточек</h2>
        <div class="quality-split">
          <?php foreach (['all' => 'Все строки', 'ozon' => 'Ozon', 'wb' => 'Wildberries'] as $qualityKey => $qualityLabel): ?>
            <?php $bucket = (array)($analyticsQuality[$qualityKey] ?? []); ?>
            <div class="quality-block">
              <div class="quality-title">
                <b><?= h($qualityLabel) ?></b>
                <span class="muted">среднее <?= h(scps_fmt_score($bucket['avg'] ?? 0)) ?></span>
              </div>
              <div class="row-stack">
                <?php foreach ((array)($bucket['bands'] ?? []) as $band): ?>
                  <?php $bandPercent = (float)($band['percent'] ?? 0); ?>
                  <div class="band-line">
                    <b><?= h($band['label'] ?? '') ?></b>
                    <div class="meter <?= h((string)($band['class'] ?? 'gray')) ?>" style="--w: <?= h((string)max(0, min(100, $bandPercent))) ?>%;"><span></span></div>
                    <span class="muted"><?= h(scps_fmt_int($band['count'] ?? 0)) ?> · <?= h(scps_fmt_score($bandPercent)) ?>%</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel">
        <h2>Базовые пробелы</h2>
        <div class="row-stack">
          <?php if (!$analyticsFieldGaps): ?><span class="muted">Базовые пробелы не найдены.</span><?php endif; ?>
          <?php foreach (array_slice($analyticsFieldGaps, 0, 8) as $gap): ?>
            <?php $gapPercent = (float)($gap['percent'] ?? 0); ?>
            <a class="gap-line analysis-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'issue', (string)($gap['code'] ?? ''))) ?>">
              <b><?= h($gap['label'] ?? '') ?></b>
              <div class="meter <?= h((string)($gap['class'] ?? 'gray')) ?>" style="--w: <?= h((string)max(0, min(100, $gapPercent))) ?>%;"><span></span></div>
              <span class="muted"><?= h(scps_fmt_int($gap['products_count'] ?? 0)) ?> · <?= h(scps_fmt_score($gapPercent)) ?>%</span>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($analyticsDimensions): ?>
          <h2 style="margin-top:16px;">Слабые компоненты качества</h2>
          <div class="row-stack">
            <?php foreach (array_slice($analyticsDimensions, 0, 5) as $dimension): ?>
              <?php $dimensionPercent = (float)($dimension['percent'] ?? 0); ?>
              <div class="dimension-line">
                <b><?= h($dimension['label'] ?? '') ?></b>
                <div class="meter <?= $dimensionPercent >= 80 ? 'good' : ($dimensionPercent < 55 ? 'bad' : 'warn') ?>" style="--w: <?= h((string)max(0, min(100, $dimensionPercent))) ?>%;"><span></span></div>
                <span class="muted"><?= h(scps_fmt_score($dimensionPercent)) ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="analytics-grid">
      <div class="panel">
        <h2>Узкие места по площадкам</h2>
        <div class="market-gap-grid">
          <?php foreach ($analyticsMarketplaces as $gap): ?>
            <?php $gapMarketplace = (string)($gap['marketplace'] ?? ''); ?>
            <div class="market-gap">
              <div class="gap-title">
                <b><?= h($gap['label'] ?? '') ?></b>
                <span class="muted">качество <?= h(scps_fmt_score($gap['avg_quality'] ?? 0)) ?></span>
              </div>
              <div class="market-stats">
                <a class="mini mini-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'not_uploaded_' . $gapMarketplace)) ?>"><div class="k">Не загружено</div><div class="v"><?= h(scps_fmt_int($gap['not_uploaded'] ?? 0)) ?></div></a>
                <a class="mini mini-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'marketplace_errors_' . $gapMarketplace)) ?>"><div class="k">Ошибки</div><div class="v"><?= h(scps_fmt_int($gap['error_revision'] ?? 0)) ?></div></a>
                <a class="mini mini-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'not_sellable_' . $gapMarketplace)) ?>"><div class="k">Не продается</div><div class="v"><?= h(scps_fmt_int($gap['not_sellable'] ?? 0)) ?></div></a>
                <a class="mini mini-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'weak_quality_' . $gapMarketplace)) ?>"><div class="k">Слабые</div><div class="v"><?= h(scps_fmt_int($gap['weak_quality'] ?? 0)) ?></div></a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel">
        <h2>Главные причины</h2>
        <div class="issue-list">
          <?php if (!$analyticsIssues): ?><span class="muted">Значимых причин не найдено.</span><?php endif; ?>
          <?php foreach (array_slice($analyticsIssues, 0, 10) as $issue): ?>
            <?php $severity = (string)($issue['severity'] ?? 'minor'); ?>
            <a class="issue-row analysis-link" href="<?= h(scps_products_group_href($connections, $supplierId, 'issue', (string)($issue['code'] ?? ''))) ?>">
              <div>
                <b><?= h($issue['label'] ?? $issue['code'] ?? '') ?></b>
                <div class="product-sub"><?= h(scps_fmt_int($issue['products_count'] ?? 0)) ?> товаров · <?= h(scps_fmt_int($issue['rows_count'] ?? 0)) ?> строк</div>
              </div>
              <span class="chip <?= $severity === 'critical' ? 'bad' : ($severity === 'major' ? 'warn' : 'gray') ?>"><?= h($severity === 'critical' ? 'critical' : ($severity === 'major' ? 'major' : 'minor')) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="grid-2">
      <div class="panel">
        <h2>Ошибки и пробелы</h2>
        <div class="chips">
          <?php if (!$issueBreakdown): ?><span class="muted">Ошибки не найдены.</span><?php endif; ?>
          <?php foreach (array_slice($issueBreakdown, 0, 18) as $issue): ?>
            <?php $severity = (string)($issue['severity'] ?? 'minor'); ?>
            <span class="chip <?= $severity === 'critical' ? 'bad' : ($severity === 'major' ? 'warn' : 'gray') ?>">
              <?= h($issue['label'] ?? $issue['code'] ?? '') ?> · <?= h(scps_fmt_int($issue['count'] ?? 0)) ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel">
        <h2>Что изменилось за период</h2>
        <?php if (empty($delta['available'])): ?>
          <div class="muted">Пока нет достаточной истории snapshots для сравнения.</div>
        <?php else: ?>
          <?php $errorRowsDelta = ((float)($delta['error_total'] ?? 0)) + ((float)($delta['revision_total'] ?? 0)); ?>
          <div class="market-stats">
            <div class="mini"><div class="k">Progress</div><div class="v"><span class="delta <?= h(scps_delta_class($delta['content_progress_score'] ?? 0)) ?>"><?= h(scps_delta($delta['content_progress_score'] ?? 0, ' п.')) ?></span></div></div>
            <div class="mini"><div class="k">Загружено</div><div class="v"><span class="delta <?= h(scps_delta_class($delta['uploaded_total'] ?? 0)) ?>"><?= h(scps_delta($delta['uploaded_total'] ?? 0)) ?></span></div></div>
            <div class="mini"><div class="k">Продается</div><div class="v"><span class="delta <?= h(scps_delta_class($delta['sellable_total'] ?? 0)) ?>"><?= h(scps_delta($delta['sellable_total'] ?? 0)) ?></span></div></div>
            <div class="mini"><div class="k">Качество</div><div class="v"><span class="delta <?= h(scps_delta_class($delta['avg_card_quality_score'] ?? 0)) ?>"><?= h(scps_delta($delta['avg_card_quality_score'] ?? 0, ' п.')) ?></span></div></div>
            <div class="mini"><div class="k">Ошибки MP</div><div class="v"><span class="delta <?= h(scps_delta_class($errorRowsDelta, true)) ?>"><?= h(scps_delta($errorRowsDelta)) ?></span></div></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($confidence['warnings'])): ?>
          <div class="chips" style="margin-top:14px;">
            <?php foreach ((array)$confidence['warnings'] as $warning): ?>
              <span class="chip warn"><?= h($warning) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <h2>Раскладка изменений по критериям</h2>
      <?php if (empty($deepDelta['available'])): ?>
        <div class="muted">Пока нет достаточной истории snapshots для детализации причин.</div>
      <?php else: ?>
        <div class="market-stats">
          <?php foreach ([
            'upload_score' => 'Загрузка',
            'sellable_score' => 'Продается',
            'avg_card_quality_score' => 'Качество',
            'error_health_score' => 'Без ошибок',
          ] as $key => $label): ?>
            <?php $row = (array)($deepDelta['score_deltas'][$key] ?? []); ?>
            <div class="mini">
              <div class="k"><?= h($label) ?></div>
              <div class="v"><span class="delta <?= h(scps_delta_class($row['delta'] ?? 0)) ?>"><?= h(scps_delta($row['delta'] ?? 0, ' п.')) ?></span></div>
              <div class="product-sub"><?= h(scps_fmt_score($row['start'] ?? 0)) ?> → <?= h(scps_fmt_score($row['end'] ?? 0)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php $summaryDeltas = (array)($deepDelta['summary_deltas'] ?? []); ?>
        <div class="market-stats" style="margin-top:12px;">
          <?php foreach ([
            ['key' => 'target_products_total', 'label' => 'Цель выгрузки', 'inverse' => false, 'neutral' => true],
            ['key' => 'uploaded_any_total', 'label' => 'Загружено хоть куда', 'inverse' => false, 'neutral' => false],
            ['key' => 'uploaded_all_total', 'label' => 'Загружено везде', 'inverse' => false, 'neutral' => false],
            ['key' => 'sellable_any_total', 'label' => 'Продается хоть где', 'inverse' => false, 'neutral' => false],
            ['key' => 'products_with_errors_total', 'label' => 'Товары с ошибками', 'inverse' => true, 'neutral' => false],
            ['key' => 'critical_issues_total', 'label' => 'Критичные проблемы', 'inverse' => true, 'neutral' => false],
            ['key' => 'fixable_issues_total', 'label' => 'Исправимые проблемы', 'inverse' => true, 'neutral' => false],
          ] as $summaryCard): ?>
            <?php
              $summaryKey = (string)$summaryCard['key'];
              $summaryRow = (array)($summaryDeltas[$summaryKey] ?? []);
              $summaryDelta = $summaryRow['delta'] ?? 0;
              $summaryClass = !empty($summaryCard['neutral']) ? 'flat' : scps_delta_class($summaryDelta, !empty($summaryCard['inverse']));
            ?>
            <div class="mini">
              <div class="k"><?= h($summaryCard['label']) ?></div>
              <div class="v"><span class="delta <?= h($summaryClass) ?>"><?= h(scps_delta($summaryDelta)) ?></span></div>
              <div class="product-sub"><?= h(scps_fmt_int($summaryRow['start'] ?? 0)) ?> → <?= h(scps_fmt_int($summaryRow['end'] ?? 0)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="grid-2" style="margin-bottom:0;">
          <?php foreach (['ozon' => 'Ozon', 'wb' => 'Wildberries'] as $mp => $label): ?>
            <?php $mpDelta = (array)($deepDelta['marketplace_deltas'][$mp] ?? []); ?>
            <div class="change-block">
              <div class="market-top">
                <h2><?= h($label) ?></h2>
                <strong><span class="delta <?= h(scps_delta_class($mpDelta['completion_score']['delta'] ?? 0)) ?>"><?= h(scps_delta($mpDelta['completion_score']['delta'] ?? 0, ' п.')) ?></span></strong>
              </div>
              <div class="market-stats">
                <div class="mini"><div class="k">Загружено</div><div class="v"><span class="delta <?= h(scps_delta_class($mpDelta['uploaded']['delta'] ?? 0)) ?>"><?= h(scps_delta($mpDelta['uploaded']['delta'] ?? 0)) ?></span></div></div>
                <div class="mini"><div class="k">Готово</div><div class="v"><span class="delta <?= h(scps_delta_class($mpDelta['ready']['delta'] ?? 0)) ?>"><?= h(scps_delta($mpDelta['ready']['delta'] ?? 0)) ?></span></div></div>
                <div class="mini"><div class="k">Продается</div><div class="v"><span class="delta <?= h(scps_delta_class($mpDelta['sellable']['delta'] ?? 0)) ?>"><?= h(scps_delta($mpDelta['sellable']['delta'] ?? 0)) ?></span></div></div>
                <div class="mini"><div class="k">Ошибки</div><div class="v"><span class="delta <?= h(scps_delta_class(((float)($mpDelta['error']['delta'] ?? 0)) + ((float)($mpDelta['revision']['delta'] ?? 0)), true)) ?>"><?= h(scps_delta(((float)($mpDelta['error']['delta'] ?? 0)) + ((float)($mpDelta['revision']['delta'] ?? 0)))) ?></span></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="grid-2" style="margin-bottom:0;">
          <div>
            <h2>Сократились ошибки</h2>
            <div class="chips">
              <?php if (empty($deepDelta['fixed_issues'])): ?><span class="muted">Нет заметных исправлений по типам ошибок.</span><?php endif; ?>
              <?php foreach ((array)($deepDelta['fixed_issues'] ?? []) as $issue): ?>
                <span class="chip good"><?= h($issue['label'] ?? $issue['code'] ?? '') ?> · <?= h(scps_delta($issue['delta'] ?? 0)) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <h2>Ошибки выросли</h2>
            <div class="chips">
              <?php if (empty($deepDelta['new_issues'])): ?><span class="muted">Новых типов ошибок за период не видно.</span><?php endif; ?>
              <?php foreach ((array)($deepDelta['new_issues'] ?? []) as $issue): ?>
                <?php $severity = (string)($issue['severity'] ?? 'minor'); ?>
                <span class="chip <?= $severity === 'critical' ? 'bad' : ($severity === 'major' ? 'warn' : 'gray') ?>"><?= h($issue['label'] ?? $issue['code'] ?? '') ?> · <?= h(scps_delta($issue['delta'] ?? 0)) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="analytics-top">
        <div>
          <h2>Вклад пользователей по поставщику</h2>
          <div class="muted">Контентные операции за выбранный период: <?= h($period['from_date'] ?? '') ?> — <?= h($period['to_date'] ?? '') ?>.</div>
        </div>
        <div class="chips">
          <span class="chip good">Вклад · <?= h(scps_fmt_int($contributionOverview['content_points'] ?? 0)) ?></span>
          <span class="chip gray">Операций · <?= h(scps_fmt_int($contributionOverview['ops_done'] ?? 0)) ?></span>
        </div>
      </div>
      <div class="overview-grid">
        <div class="mini"><div class="k">Операции</div><div class="v"><?= h(scps_fmt_int($contributionOverview['ops_done'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Вклад</div><div class="v"><?= h(scps_fmt_int($contributionOverview['content_points'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Обработано</div><div class="v"><?= h(scps_fmt_int($contributionOverview['products_processed'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Улучшения</div><div class="v"><?= h(scps_fmt_int($contributionOverview['quality_updates'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Выгрузка</div><div class="v"><?= h(scps_fmt_int($contributionOverview['marketplace_uploads'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Каталог</div><div class="v"><?= h(scps_fmt_int($contributionOverview['catalog_updates'] ?? 0)) ?></div></div>
        <div class="mini"><div class="k">Ошибки операций</div><div class="v"><?= h(scps_fmt_int($contributionOverview['ops_error'] ?? 0)) ?></div></div>
      </div>

      <div class="contrib-grid">
        <div>
          <h2>По пользователям</h2>
          <table class="compact-table">
            <thead>
              <tr>
                <th>Пользователь</th>
                <th>Тип</th>
                <th class="right">Вклад</th>
                <th class="right">Операции</th>
                <th>Главное действие</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$contributionActors): ?>
                <tr><td colspan="5" class="muted">Нет контентных операций по этому поставщику за период.</td></tr>
              <?php endif; ?>
              <?php foreach ($contributionActors as $actor): ?>
                <tr>
                  <td><b><?= h($actor['actor_label'] ?? '') ?></b></td>
                  <td><span class="chip <?= (string)($actor['actor_kind'] ?? '') === 'human' ? 'good' : 'gray' ?>"><?= h(scps_actor_kind_label((string)($actor['actor_kind'] ?? 'unknown'))) ?></span></td>
                  <td class="right"><b><?= h(scps_fmt_int($actor['content_points'] ?? 0)) ?></b></td>
                  <td class="right"><?= h(scps_fmt_int($actor['ops_done'] ?? 0)) ?> / <?= h(scps_fmt_int($actor['ops_total'] ?? 0)) ?></td>
                  <td><?= h($actor['top_operation'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div>
          <h2>По типам работы</h2>
          <table class="compact-table">
            <thead>
              <tr>
                <th>Тип</th>
                <th class="right">Вклад</th>
                <th class="right">Операции</th>
                <th class="right">Обработано</th>
                <th class="right">Улучшения</th>
                <th class="right">Выгрузка</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$contributionKinds): ?>
                <tr><td colspan="6" class="muted">Нет данных по типам работы.</td></tr>
              <?php endif; ?>
              <?php foreach ($contributionKinds as $kind): ?>
                <tr>
                  <td><b><?= h($kind['kind_label'] ?? $kind['kind'] ?? '') ?></b></td>
                  <td class="right"><b><?= h(scps_fmt_int($kind['content_points'] ?? 0)) ?></b></td>
                  <td class="right"><?= h(scps_fmt_int($kind['ops_done'] ?? 0)) ?></td>
                  <td class="right"><?= h(scps_fmt_int($kind['products_processed'] ?? 0)) ?></td>
                  <td class="right"><?= h(scps_fmt_int($kind['quality_updates'] ?? 0)) ?></td>
                  <td class="right"><?= h(scps_fmt_int($kind['marketplace_uploads'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($contributionEvents): ?>
        <h2 style="margin-top:16px;">Последние операции поставщика</h2>
        <table class="compact-table">
          <thead>
            <tr>
              <th>Операция</th>
              <th>Пользователь</th>
              <th>Статус</th>
              <th class="right">Вклад</th>
              <th class="right">Обработано</th>
              <th class="right">Улучшения</th>
              <th class="right">Выгрузка</th>
              <th>Время</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($contributionEvents as $event): ?>
              <tr>
                <td>
                  <a class="op-link" href="op.php?id=<?= h((string)($event['op_id'] ?? 0)) ?>">#<?= h((string)($event['op_id'] ?? 0)) ?> · <?= h($event['op_label'] ?? $event['op_type'] ?? '') ?></a>
                  <div class="product-sub"><?= h($event['op_type'] ?? '') ?></div>
                </td>
                <td><?= h($event['actor_label'] ?? '') ?></td>
                <td><span class="chip <?= (string)($event['status'] ?? '') === 'done' ? 'good' : ((string)($event['status'] ?? '') === 'error' ? 'bad' : 'gray') ?>"><?= h($event['status'] ?? '') ?></span></td>
                <td class="right"><b><?= h(scps_fmt_int($event['content_points'] ?? 0)) ?></b></td>
                <td class="right"><?= h(scps_fmt_int($event['products_processed'] ?? 0)) ?></td>
                <td class="right"><?= h(scps_fmt_int($event['quality_updates'] ?? 0)) ?></td>
                <td class="right"><?= h(scps_fmt_int($event['marketplace_uploads'] ?? 0)) ?></td>
                <td><span data-ft-datetime="<?= h($event['created_at'] ?? '') ?>"><?= h($event['created_at'] ?? '') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

  </main>
</body>
</html>
