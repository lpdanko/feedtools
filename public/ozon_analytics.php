<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/ozon_analytics.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function oa_fmt_int($value): string
{
    return number_format((int)round((float)$value), 0, '.', ' ');
}

function oa_fmt_money($value): string
{
    return number_format((float)$value, 2, '.', ' ');
}

function oa_fmt_pct($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, 2, '.', ' ') . '%';
}

function oa_fmt_decimal($value, int $scale = 2): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, $scale, '.', ' ');
}

function oa_fmt_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0с';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%dч %02dм', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dм %02dс', $m, $s);
    }
    return sprintf('%dс', $s);
}

function oa_valid_date_or_empty(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return preg_match('~^\d{4}-\d{2}-\d{2}$~', $value) ? $value : '';
}

function oa_connections(array $cfg): array
{
    ozon_price_connections_table_ensure($cfg);
    $st = db()->query("
        SELECT id, title, marketplace, client_id, is_active, sort_order
        FROM feedtools_marketplace_connections
        WHERE marketplace = 'ozon'
        ORDER BY is_active DESC, sort_order ASC, id ASC
    ");
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function oa_selected_connection_id(array $connections): int
{
    $raw = isset($_GET['connection_id']) ? (int)$_GET['connection_id'] : 0;
    foreach ($connections as $connection) {
        $id = (int)($connection['id'] ?? 0);
        if ($id > 0 && $id === $raw) {
            return $id;
        }
    }
    foreach ($connections as $connection) {
        if ((int)($connection['is_active'] ?? 0) === 1) {
            return (int)($connection['id'] ?? 0);
        }
    }
    return (int)($connections[0]['id'] ?? 0);
}

function oa_active_operation(int $connectionId): ?array
{
    if ($connectionId <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_operations
        WHERE op_type = 'ozon_sync_analytics'
          AND connection_id = ?
          AND status IN ('queued','running')
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$connectionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function oa_latest_operation_for_connection(int $connectionId): ?array
{
    if ($connectionId <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_operations
        WHERE op_type = 'ozon_sync_analytics'
          AND connection_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$connectionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function oa_summary(int $connectionId, string $dateFrom, string $dateTo): array
{
    if ($connectionId <= 0) {
        return [];
    }
    $sql = "
        SELECT
          COUNT(*) AS rows_count,
          COUNT(DISTINCT sku_text) AS sku_count,
          COUNT(DISTINCT CASE WHEN offer_id <> '' THEN offer_id END) AS offer_count,
          MIN(analytics_date) AS actual_date_from,
          MAX(analytics_date) AS actual_date_to,
          " . ozon_analytics_summary_columns_sql() . "
        FROM feedtools_ozon_product_analytics_daily
        WHERE connection_id = ?
          AND analytics_date BETWEEN ? AND ?
    ";
    $st = db()->prepare($sql);
    $st->execute([$connectionId, $dateFrom, $dateTo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [];
    }
    $row['cart_conversion_from_impressions_pct'] = ((float)($row['hits_view'] ?? 0) > 0)
        ? ((float)($row['hits_tocart'] ?? 0) / (float)$row['hits_view']) * 100
        : null;
    $row['order_conversion_from_pdp_sessions_pct'] = ((float)($row['session_view_pdp'] ?? 0) > 0)
        ? ((float)($row['ordered_units'] ?? 0) / (float)$row['session_view_pdp']) * 100
        : null;
    return $row;
}

function oa_product_rows(int $connectionId, string $dateFrom, string $dateTo, string $query, int $limit = 100): array
{
    if ($connectionId <= 0) {
        return [];
    }
    $where = [
        'a.connection_id = ?',
        'a.analytics_date BETWEEN ? AND ?',
    ];
    $args = [$connectionId, $dateFrom, $dateTo];
    $query = trim($query);
    if ($query !== '') {
        $where[] = '(a.offer_id LIKE ? OR a.sku_text LIKE ?)';
        $like = '%' . $query . '%';
        $args[] = $like;
        $args[] = $like;
    }

    $limit = max(20, min(500, $limit));
    $sql = "
        SELECT
          a.offer_id,
          a.sku_text,
          MAX(a.product_id) AS product_id,
          COUNT(*) AS days_count,
          MIN(a.analytics_date) AS actual_date_from,
          MAX(a.analytics_date) AS actual_date_to,
          " . ozon_analytics_summary_columns_sql('a.') . "
        FROM feedtools_ozon_product_analytics_daily a
        WHERE " . implode(' AND ', $where) . "
        GROUP BY a.offer_id, a.sku_text
        ORDER BY revenue DESC, ordered_units DESC, hits_view DESC, a.offer_id ASC
        LIMIT {$limit}
    ";
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['cart_conversion_from_impressions_pct'] = ((float)($row['hits_view'] ?? 0) > 0)
            ? ((float)($row['hits_tocart'] ?? 0) / (float)$row['hits_view']) * 100
            : null;
        $row['order_conversion_from_pdp_sessions_pct'] = ((float)($row['session_view_pdp'] ?? 0) > 0)
            ? ((float)($row['ordered_units'] ?? 0) / (float)$row['session_view_pdp']) * 100
            : null;
    }
    unset($row);
    return $rows;
}

function oa_sync_runs(int $connectionId, int $limit = 8): array
{
    if ($connectionId <= 0) {
        return [];
    }
    $limit = max(1, min(30, $limit));
    $st = db()->prepare("
        SELECT *
        FROM feedtools_ozon_analytics_sync_runs
        WHERE connection_id = ?
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function oa_status_class(string $status): string
{
    return preg_replace('~[^a-z0-9_-]+~i', '', strtolower($status)) ?: 'unknown';
}

function oa_operation_percent(?array $op): float
{
    if (!$op) {
        return 0.0;
    }
    $done = (int)($op['progress_done'] ?? 0);
    $total = (int)($op['progress_total'] ?? 0);
    if ($total <= 0) {
        return in_array((string)($op['status'] ?? ''), ['done'], true) ? 100.0 : 0.0;
    }
    return round((max(0, min($done, $total)) / $total) * 100, 1);
}

$error = '';
$notice = '';

try {
    ozon_analytics_tables_ensure($cfg);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$connections = [];
try {
    $connections = oa_connections($cfg);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$connectionId = oa_selected_connection_id($connections);
$days = max(1, min(90, (int)($_GET['days'] ?? 90)));
$dateTo = oa_valid_date_or_empty((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'start_sync') {
        $postConnectionId = (int)($_POST['connection_id'] ?? 0);
        $knownIds = array_map(static fn($row): int => (int)($row['id'] ?? 0), $connections);
        if ($postConnectionId <= 0 || !in_array($postConnectionId, $knownIds, true)) {
            $error = 'Выберите подключение Ozon для синхронизации аналитики.';
        } else {
            $syncDays = max(1, min(90, (int)($_POST['days'] ?? 90)));
            $syncDateTo = oa_valid_date_or_empty((string)($_POST['date_to'] ?? ''));
            $params = [
                'connection_id' => (string)$postConnectionId,
                'days' => (string)$syncDays,
                'date_to' => $syncDateTo,
                'limit' => '1000',
            ];
            $datasetId = feedtools_global_ops_dataset_id();
            $actor = function_exists('ft_current_user') ? ft_current_user() : ops_current_actor();
            $actor = is_string($actor) ? trim($actor) : '';
            $duplicate = ops_find_recent_duplicate($datasetId, 'ozon_sync_analytics', $params, 10);
            if ($duplicate) {
                $opId = (int)($duplicate['id'] ?? 0);
            } else {
                $opId = ops_create($datasetId, 'ozon_sync_analytics', $params, $actor !== '' ? $actor : null);
                ops_append_log_tail($opId, "Queued.\n", 200000);
                ops_update_progress($opId, 0, max(1, $syncDays * 1000), 'queued', 'Queued');
            }
            $redirect = 'ozon_analytics.php?connection_id=' . urlencode((string)$postConnectionId)
                . '&days=' . urlencode((string)$syncDays)
                . ($syncDateTo !== '' ? '&date_to=' . urlencode($syncDateTo) : '')
                . '&op_id=' . urlencode((string)$opId)
                . '&sync_started=1';
            header('Location: ' . $redirect, true, 303);
            exit;
        }
    }
}

[$dateFrom, $dateToEffective, $actualDays] = ozon_analytics_parse_date_range([
    'days' => $days,
    'date_to' => $dateTo,
]);
$dateTo = $dateToEffective;

$summary = [];
$productRows = [];
$runs = [];
$currentOp = null;
$requestedOpId = isset($_GET['op_id']) ? (int)$_GET['op_id'] : 0;

try {
    if ($connectionId > 0) {
        $summary = oa_summary($connectionId, $dateFrom, $dateTo);
        $productRows = oa_product_rows($connectionId, $dateFrom, $dateTo, $search, 100);
        $runs = oa_sync_runs($connectionId);
        if ($requestedOpId > 0) {
            $candidate = ops_get($requestedOpId);
            if ($candidate && (string)($candidate['op_type'] ?? '') === 'ozon_sync_analytics') {
                $currentOp = $candidate;
            }
        }
        if (!$currentOp) {
            $currentOp = oa_active_operation($connectionId) ?: oa_latest_operation_for_connection($connectionId);
        }
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

if (isset($_GET['sync_started'])) {
    $notice = 'Синхронизация аналитики поставлена в очередь.';
}

$selectedConnection = null;
foreach ($connections as $connection) {
    if ((int)($connection['id'] ?? 0) === $connectionId) {
        $selectedConnection = $connection;
        break;
    }
}

$opStatus = $currentOp ? (string)($currentOp['status'] ?? '') : '';
$opPercent = oa_operation_percent($currentOp);
$initiallyLive = $currentOp && in_array($opStatus, ['queued', 'running'], true);
$selectedDateLabel = $dateFrom . ' - ' . $dateTo;
$actualCoverageLabel = trim((string)($summary['actual_date_from'] ?? '')) !== ''
    ? ((string)$summary['actual_date_from'] . ' - ' . (string)$summary['actual_date_to'])
    : '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Аналитика Ozon</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      --bg: #f4f7fb;
      --panel: #fff;
      --soft: #f8fbff;
      --line: #d9e5f2;
      --line-soft: #e8eef7;
      --text: #17233a;
      --muted: #66738a;
      --blue: #2563eb;
      --blue-soft: #eff6ff;
      --green: #087f5b;
      --green-soft: #ecfdf5;
      --red: #b91c1c;
      --red-soft: #fef2f2;
      --amber: #b45309;
      --amber-soft: #fffbeb;
      --ink: #0b1020;
      --shadow: 0 18px 40px rgba(27, 57, 90, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
      color: var(--text);
      font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    a { color: #1d4ed8; }
    .shell { width: min(1560px, calc(100% - 36px)); margin: 0 auto; padding: 22px 0 42px; }
    h1, h2, h3, p { margin-top: 0; }
    h1 { margin: 0 0 8px; font-size: clamp(34px, 4vw, 52px); line-height: 1; letter-spacing: 0; }
    h2 { margin-bottom: 12px; font-size: 24px; }
    h3 { margin-bottom: 8px; font-size: 18px; }
    .muted { color: var(--muted); }
    .warn-text { color: var(--amber); font-weight: 800; }
    .page-hero {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 18px;
      align-items: end;
      margin-bottom: 16px;
      padding: 22px 24px;
      border: 1px solid var(--line);
      border-radius: 20px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .hero-copy p { max-width: 900px; margin-bottom: 0; font-size: 17px; }
    .hero-badges { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .badge {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 10px;
      border: 1px solid #bfdbfe;
      border-radius: 999px;
      background: var(--blue-soft);
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
      white-space: nowrap;
    }
    .badge.done { border-color: #bbf7d0; background: var(--green-soft); color: var(--green); }
    .badge.error { border-color: #fecaca; background: var(--red-soft); color: var(--red); }
    .badge.running { border-color: #bfdbfe; background: var(--blue-soft); color: #1d4ed8; }
    .badge.queued { border-color: #ddd6fe; background: #f5f3ff; color: #6d28d9; }
    .notice, .error {
      margin-bottom: 14px;
      padding: 13px 15px;
      border-radius: 14px;
      font-weight: 800;
    }
    .notice { border: 1px solid #bbf7d0; background: var(--green-soft); color: var(--green); }
    .error { border: 1px solid #fecaca; background: var(--red-soft); color: var(--red); }
    .layout {
      display: grid;
      grid-template-columns: 420px minmax(0, 1fr);
      gap: 16px;
      align-items: start;
    }
    .panel {
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .panel-pad { padding: 18px; }
    .controls { display: grid; gap: 14px; }
    .field { display: grid; gap: 6px; }
    .field label { color: var(--muted); font-size: 13px; font-weight: 800; }
    select, input {
      width: 100%;
      min-height: 44px;
      padding: 0 12px;
      border: 1px solid #cfe0f4;
      border-radius: 12px;
      background: #fff;
      color: var(--text);
      font: inherit;
      font-weight: 700;
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 15px;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--text);
      text-decoration: none;
      font-weight: 900;
      cursor: pointer;
    }
    .btn.primary { border-color: #111827; background: #111827; color: #fff; }
    .btn.blue { border-color: #1d4ed8; background: linear-gradient(90deg, #2563eb, #0f8b8d); color: #fff; }
    .btn:disabled { opacity: .55; cursor: not-allowed; }
    .small-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }
    .stat {
      min-width: 0;
      padding: 13px;
      border: 1px solid var(--line-soft);
      border-radius: 14px;
      background: var(--soft);
    }
    .stat span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; margin-bottom: 4px; }
    .stat strong { display: block; color: var(--text); font-size: 24px; line-height: 1.05; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .progress-card {
      display: grid;
      gap: 14px;
      padding: 18px;
      border-bottom: 1px solid var(--line-soft);
      background: linear-gradient(135deg, #fff 0%, #f8fbff 100%);
    }
    .progress-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
    }
    .progress-title { margin: 0; font-size: 22px; }
    .progress-message { color: #334155; text-align: right; max-width: 540px; }
    .progress-number { font-size: 40px; line-height: 1; font-weight: 950; letter-spacing: 0; }
    .progress-track { height: 12px; border-radius: 999px; overflow: hidden; background: #e5e7eb; }
    .progress-fill { height: 100%; width: 0%; border-radius: 999px; background: linear-gradient(90deg, #2563eb, #0f8b8d); transition: width .25s ease; }
    .progress-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }
    .mini {
      min-width: 0;
      border: 1px solid var(--line-soft);
      border-radius: 12px;
      padding: 10px 11px;
      background: #fff;
    }
    .mini span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; }
    .mini strong { display: block; margin-top: 3px; font-size: 15px; font-weight: 900; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    details.log-panel { padding: 0 18px 18px; }
    details.log-panel summary {
      cursor: pointer;
      list-style: none;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 0 10px;
      font-size: 18px;
      font-weight: 900;
    }
    details.log-panel summary::-webkit-details-marker { display: none; }
    details.log-panel summary::after {
      content: 'раскрыть';
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .06em;
    }
    details.log-panel[open] summary::after { content: 'скрыть'; }
    pre {
      margin: 0;
      max-height: 420px;
      overflow: auto;
      white-space: pre-wrap;
      word-break: break-word;
      border-radius: 12px;
      background: var(--ink);
      color: #d1d5db;
      padding: 14px;
      font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }
    .data-panel { padding: 18px; }
    .section-head {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .section-head h2 { margin: 0; }
    .table-wrap { overflow: auto; border: 1px solid var(--line-soft); border-radius: 14px; }
    table { width: 100%; min-width: 1180px; border-collapse: collapse; background: #fff; }
    th, td { padding: 10px 11px; border-bottom: 1px solid #e8eef7; text-align: left; vertical-align: top; font-size: 13px; }
    th { position: sticky; top: 0; z-index: 1; background: #f8fbff; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    .offer { font-weight: 900; color: var(--text); }
    .subline { display: block; margin-top: 3px; color: var(--muted); font-size: 12px; font-weight: 700; }
    .empty {
      padding: 18px;
      border: 1px dashed #cfe0f4;
      border-radius: 14px;
      background: #fbfdff;
      color: var(--muted);
      font-weight: 700;
    }
    .run-list { display: grid; gap: 8px; }
    .run-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 8px;
      align-items: center;
      padding: 10px 11px;
      border: 1px solid var(--line-soft);
      border-radius: 12px;
      background: #fff;
    }
    .run-row strong { display: block; }
    .run-row span { color: var(--muted); font-size: 12px; font-weight: 700; }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 50;
      display: inline-flex;
      padding: 9px 13px;
      border-radius: 999px;
      border: 1px solid #f59e0b;
      background: rgba(255, 251, 235, .97);
      color: #92400e;
      box-shadow: 0 12px 28px rgba(146, 64, 14, .14);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    @media (max-width: 1180px) {
      .layout { grid-template-columns: 1fr; }
      .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 760px) {
      .shell { width: min(100% - 24px, 1560px); padding-top: 14px; }
      .page-hero { grid-template-columns: 1fr; padding: 18px; }
      .hero-badges { justify-content: flex-start; }
      .form-row, .stats-grid, .progress-metrics { grid-template-columns: 1fr; }
      .progress-top, .section-head { display: block; }
      .progress-message { text-align: left; margin-top: 8px; }
      .btn { width: 100%; }
    }
  </style>
</head>
<body>
<?php if (ft_is_staging_env($cfg)): ?>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
<?php endif; ?>

<main class="shell">
  <?= ft_top_navigation([
      'back_href' => 'index.php',
      'back_label' => 'Назад',
      'active' => 'marketplace_analytics',
  ]) ?>

  <section class="page-hero">
    <div class="hero-copy">
      <h1>Аналитика Ozon</h1>
      <p class="muted">Показы, переходы, корзина, конверсия, продажи, возвраты, отмены и рекламные метрики по товарам. Данные хранятся по дням, SKU и подключению, поэтому их можно быстро смотреть по товару и периоду.</p>
    </div>
    <div class="hero-badges">
      <span class="badge">до 90 дней</span>
      <?php if ($selectedConnection): ?>
        <span class="badge done"><?= h($selectedConnection['title'] ?? ('Ozon #' . $connectionId)) ?></span>
      <?php endif; ?>
      <a class="btn primary" href="ozon_analytics.php<?= $connectionId > 0 ? '?connection_id=' . h($connectionId) : '' ?>">Ozon</a>
      <a class="btn" href="wb_analytics.php">WB</a>
    </div>
  </section>

  <?php if ($notice !== ''): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <div class="layout">
    <aside class="controls">
      <section class="panel panel-pad">
        <h2>Подключение и период</h2>
        <?php if (!$connections): ?>
          <div class="empty">Нет подключений Ozon. Сначала добавьте кабинет в разделе подключений.</div>
          <p style="margin:12px 0 0;"><a class="btn primary" href="marketplace_connections.php">Открыть подключения</a></p>
        <?php else: ?>
          <form method="get" class="controls">
            <div class="field">
              <label for="connection_id">Подключение Ozon</label>
              <select id="connection_id" name="connection_id">
                <?php foreach ($connections as $connection): ?>
                  <?php $id = (int)($connection['id'] ?? 0); ?>
                  <option value="<?= h($id) ?>" <?= $id === $connectionId ? 'selected' : '' ?>>
                    <?= h((string)($connection['title'] ?? ('Ozon #' . $id))) ?> · #<?= h($id) ?><?= (int)($connection['is_active'] ?? 0) === 1 ? '' : ' · выключено' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row">
              <div class="field">
                <label for="days">Период</label>
                <select id="days" name="days">
                  <?php foreach ([7, 14, 30, 60, 90] as $option): ?>
                    <option value="<?= h($option) ?>" <?= $actualDays === $option ? 'selected' : '' ?>><?= h($option) ?> дней</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="date_to">Дата окончания</label>
                <input id="date_to" name="date_to" type="date" value="<?= h($dateTo) ?>">
              </div>
            </div>
            <div class="field">
              <label for="q">Поиск по артикулу или SKU</label>
              <input id="q" name="q" value="<?= h($search) ?>" placeholder="например 042020__24">
            </div>
            <button class="btn primary" type="submit">Показать статистику</button>
          </form>
        <?php endif; ?>
      </section>

      <?php if ($connections): ?>
        <section class="panel panel-pad">
          <h2>Синхронизация</h2>
          <p class="muted">Запускает загрузку аналитики Ozon за выбранный период. Операция идет в очереди, страницу можно оставить открытой.</p>
          <form method="post" class="controls">
            <input type="hidden" name="action" value="start_sync">
            <input type="hidden" name="connection_id" value="<?= h($connectionId) ?>">
            <input type="hidden" name="days" value="<?= h($actualDays) ?>">
            <input type="hidden" name="date_to" value="<?= h($dateTo) ?>">
            <button class="btn blue" type="submit" <?= $connectionId <= 0 ? 'disabled' : '' ?>>Запустить синхронизацию</button>
          </form>
          <?php if ($currentOp): ?>
            <div class="small-actions" style="margin-top:10px;">
              <a class="btn" href="op.php?id=<?= h($currentOp['id']) ?>&return_url=<?= h(urlencode('ozon_analytics.php?connection_id=' . $connectionId)) ?>">Открыть операцию #<?= h($currentOp['id']) ?></a>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="panel panel-pad">
        <h2>Последние синхронизации</h2>
        <?php if (!$runs): ?>
          <div class="empty">Запусков синхронизации для этого подключения пока нет.</div>
        <?php else: ?>
          <div class="run-list">
            <?php foreach ($runs as $run): ?>
              <?php $runStatus = (string)($run['status'] ?? ''); ?>
              <?php
                $failedMetrics = json_decode((string)($run['failed_metrics_json'] ?? ''), true);
                $failedMetricsCount = is_array($failedMetrics) ? count($failedMetrics) : 0;
              ?>
              <div class="run-row">
                <div>
                  <strong>#<?= h($run['id'] ?? '') ?> · <?= h($run['date_from'] ?? '') ?> - <?= h($run['date_to'] ?? '') ?></strong>
                  <span><?= h(oa_fmt_int((int)($run['rows_upserted'] ?? 0))) ?> строк · <?= h(oa_fmt_int((int)($run['api_rows'] ?? 0))) ?> API rows · <?= h($run['finished_at'] ?? $run['started_at'] ?? '') ?></span>
                  <?php if ($failedMetricsCount > 0): ?>
                    <span class="warn-text">ошибки метрик: <?= h(oa_fmt_int($failedMetricsCount)) ?></span>
                  <?php endif; ?>
                </div>
                <span class="badge <?= h(oa_status_class($runStatus)) ?>"><?= h($runStatus) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </aside>

    <section class="panel">
      <?php if ($currentOp): ?>
        <div class="progress-card" id="syncProgress" data-op-id="<?= h($currentOp['id']) ?>" data-live="<?= $initiallyLive ? '1' : '0' ?>">
          <div class="progress-top">
            <div>
              <span class="badge <?= h(oa_status_class($opStatus)) ?>" id="opStatus"><?= h($opStatus) ?></span>
              <h2 class="progress-title" style="margin-top:10px;">Синхронизация #<?= h($currentOp['id']) ?></h2>
              <div class="progress-number"><span id="opPercent"><?= h($opPercent) ?></span>%</div>
            </div>
            <div class="progress-message" id="opMessage"><?= h(trim((string)($currentOp['progress_msg'] ?? '')) ?: 'Операция ожидает обновления статуса') ?></div>
          </div>
          <div class="progress-track"><div class="progress-fill" id="opProgressFill" style="width:<?= h($opPercent) ?>%;"></div></div>
          <div class="progress-metrics">
            <div class="mini"><span>Выполнено</span><strong id="opDone"><?= h(oa_fmt_int((int)($currentOp['progress_done'] ?? 0))) ?> / <?= h(oa_fmt_int((int)($currentOp['progress_total'] ?? 0))) ?></strong></div>
            <div class="mini"><span>Этап</span><strong id="opStage"><?= h($currentOp['progress_stage'] ?? '-') ?></strong></div>
            <div class="mini"><span>Старт</span><strong><?= h($currentOp['started_at'] ?? $currentOp['created_at'] ?? '-') ?></strong></div>
            <div class="mini"><span>Финиш</span><strong id="opFinished"><?= h($currentOp['finished_at'] ?? '-') ?></strong></div>
          </div>
          <?php if (!empty($currentOp['error_text'])): ?>
            <div class="error" id="opError" style="margin:0;"><?= h($currentOp['error_text']) ?></div>
          <?php else: ?>
            <div class="error" id="opError" style="display:none;margin:0;"></div>
          <?php endif; ?>
        </div>
        <details class="log-panel" <?= $initiallyLive ? 'open' : '' ?>>
          <summary>Живой лог синхронизации</summary>
          <pre id="opLog"><?= h((string)($currentOp['log_tail'] ?? $currentOp['log_text'] ?? '')) ?></pre>
        </details>
      <?php endif; ?>

      <div class="data-panel">
        <div class="section-head">
          <div>
            <h2>Сводка</h2>
            <p class="muted" style="margin:4px 0 0;">
              Период: <?= h($selectedDateLabel) ?>
              <?php if ($actualCoverageLabel !== '' && $actualCoverageLabel !== $selectedDateLabel): ?>
                · данные в БД: <?= h($actualCoverageLabel) ?>
              <?php endif; ?>
            </p>
          </div>
          <a class="btn" href="ozon_analytics.php?connection_id=<?= h($connectionId) ?>&days=<?= h($actualDays) ?>&date_to=<?= h($dateTo) ?>">Обновить</a>
        </div>

        <div class="stats-grid">
          <div class="stat"><span>Товаров с аналитикой</span><strong><?= h(oa_fmt_int((int)($summary['offer_count'] ?? $summary['sku_count'] ?? 0))) ?></strong></div>
          <div class="stat"><span>Выручка</span><strong><?= h(oa_fmt_money($summary['revenue'] ?? 0)) ?></strong></div>
          <div class="stat"><span>Заказано, шт</span><strong><?= h(oa_fmt_int($summary['ordered_units'] ?? 0)) ?></strong></div>
          <div class="stat"><span>Доставлено, шт</span><strong><?= h(oa_fmt_int($summary['delivered_units'] ?? 0)) ?></strong></div>
          <div class="stat"><span>Показы</span><strong><?= h(oa_fmt_int($summary['hits_view'] ?? 0)) ?></strong></div>
          <div class="stat"><span>Переходы в карточку</span><strong><?= h(oa_fmt_int($summary['session_view_pdp'] ?? 0)) ?></strong></div>
          <div class="stat"><span>В корзину</span><strong><?= h(oa_fmt_int($summary['hits_tocart'] ?? 0)) ?></strong></div>
          <div class="stat"><span>Конверсия в заказ</span><strong><?= h(oa_fmt_pct($summary['order_conversion_from_pdp_sessions_pct'] ?? null)) ?></strong></div>
        </div>
      </div>

      <div class="data-panel" style="padding-top:0;">
        <div class="section-head">
          <div>
            <h2>Товары</h2>
            <p class="muted" style="margin:4px 0 0;">Первые 100 строк, отсортировано по выручке, заказам и показам.</p>
          </div>
        </div>
        <?php if (!$productRows): ?>
          <div class="empty">Для выбранного подключения и периода данных пока нет. Запустите синхронизацию или расширьте период.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Товар</th>
                  <th class="num">Дней</th>
                  <th class="num">Выручка</th>
                  <th class="num">Заказы</th>
                  <th class="num">Доставлено</th>
                  <th class="num">Возвраты</th>
                  <th class="num">Отмены</th>
                  <th class="num">Показы</th>
                  <th class="num">Переходы</th>
                  <th class="num">Корзина</th>
                  <th class="num">Конв. корзина</th>
                  <th class="num">Конв. заказ</th>
                  <th class="num">Рекл. показы</th>
                  <th class="num">Позиция</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($productRows as $row): ?>
                  <tr>
                    <td>
                      <span class="offer"><?= h(trim((string)($row['offer_id'] ?? '')) !== '' ? $row['offer_id'] : 'без артикула') ?></span>
                      <span class="subline">SKU <?= h($row['sku_text'] ?? '-') ?><?= !empty($row['product_id']) ? ' · product ' . h($row['product_id']) : '' ?></span>
                    </td>
                    <td class="num"><?= h(oa_fmt_int($row['days_count'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_money($row['revenue'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['ordered_units'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['delivered_units'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['returns_count'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['cancellations_count'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['hits_view'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['session_view_pdp'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['hits_tocart'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_pct($row['cart_conversion_from_impressions_pct'] ?? null)) ?></td>
                    <td class="num"><?= h(oa_fmt_pct($row['order_conversion_from_pdp_sessions_pct'] ?? null)) ?></td>
                    <td class="num"><?= h(oa_fmt_int($row['adv_view_all'] ?? 0)) ?></td>
                    <td class="num"><?= h(oa_fmt_decimal($row['avg_position_category'] ?? null, 1)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<?php if ($currentOp): ?>
<script>
(function() {
  var root = document.getElementById('syncProgress');
  if (!root) return;
  var opId = root.getAttribute('data-op-id');
  var reloadOnTerminal = root.getAttribute('data-live') === '1';
  var terminalReloaded = false;
  var statusEl = document.getElementById('opStatus');
  var percentEl = document.getElementById('opPercent');
  var fillEl = document.getElementById('opProgressFill');
  var doneEl = document.getElementById('opDone');
  var stageEl = document.getElementById('opStage');
  var messageEl = document.getElementById('opMessage');
  var finishedEl = document.getElementById('opFinished');
  var errorEl = document.getElementById('opError');
  var logEl = document.getElementById('opLog');

  function fmtInt(value) {
    value = Math.round(Number(value || 0));
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function setStatusClass(status) {
    if (!statusEl) return;
    statusEl.className = 'badge ' + String(status || '').replace(/[^a-z0-9_-]+/ig, '').toLowerCase();
  }

  function update(data) {
    var status = data.status || '';
    var percent = data.percent;
    if (percent === null || typeof percent === 'undefined') {
      percent = status === 'done' ? 100 : 0;
    }
    percent = Math.max(0, Math.min(100, Number(percent) || 0));
    if (statusEl) {
      statusEl.textContent = status || '-';
      setStatusClass(status);
    }
    if (percentEl) percentEl.textContent = String(percent).replace(/\.0$/, '');
    if (fillEl) fillEl.style.width = percent + '%';
    if (doneEl) doneEl.textContent = fmtInt(data.done) + ' / ' + fmtInt(data.total);
    if (stageEl) stageEl.textContent = data.stage || '-';
    if (messageEl) messageEl.textContent = data.msg || (status === 'queued' ? 'Ожидает очереди' : 'Синхронизация обновляет данные');
    if (finishedEl) finishedEl.textContent = data.finished_at || '-';
    if (logEl && typeof data.log_tail === 'string') logEl.textContent = data.log_tail;
    if (errorEl) {
      if (data.error_text) {
        errorEl.style.display = '';
        errorEl.textContent = data.error_text;
      } else {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
      }
    }
    if (reloadOnTerminal && !terminalReloaded && ['done', 'error', 'cancelled'].indexOf(status) !== -1) {
      terminalReloaded = true;
      window.setTimeout(function() {
        window.location.href = window.location.href.replace(/[?&]sync_started=1/g, '');
      }, 1600);
    }
  }

  function poll() {
    fetch('op_poll.php?id=' + encodeURIComponent(opId), { cache: 'no-store' })
      .then(function(resp) { return resp.ok ? resp.json() : null; })
      .then(function(data) {
        if (!data) return;
        update(data);
        if (['done', 'error', 'cancelled'].indexOf(data.status || '') === -1) {
          window.setTimeout(poll, 2500);
        }
      })
      .catch(function() {
        window.setTimeout(poll, 5000);
      });
  }

  if (reloadOnTerminal) {
    poll();
  }
})();
</script>
<?php endif; ?>
</body>
</html>
