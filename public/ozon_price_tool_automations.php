<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
ozon_price_feeds_table_ensure($cfg);
ozon_price_automation_table_ensure($cfg);
ozon_price_automation_run_log_table_ensure($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$feeds = [];
$automations = [];
$runLogs = [];
$connections = ozon_price_connection_list($cfg, 'ozon');
$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=price_tool', true, 303);
    exit;
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=price_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'automations');
$cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_automations') {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' автоматизация Price Tool пока не поддержана.');
        }
        ozon_price_automation_save($_POST, $actor, $currentConnectionId, $cfg);
        header('Location: ozon_price_tool_automations.php?connection_id=' . urlencode((string)$currentConnectionId) . '&saved=1', true, 303);
        exit;
    }

    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    $automations = $currentMarketplaceReady ? ozon_price_automation_list($currentConnectionId, $cfg) : [];
    $runLogs = $currentMarketplaceReady ? ozon_price_automation_run_log_recent(12, $currentConnectionId, $cfg) : [];
    if (isset($_GET['saved']) && $_GET['saved'] === '1') {
        $flash = 'Настройки автоматизации сохранены.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    $automations = $currentMarketplaceReady ? ozon_price_automation_list($currentConnectionId, $cfg) : [];
    $runLogs = $currentMarketplaceReady ? ozon_price_automation_run_log_recent(12, $currentConnectionId, $cfg) : [];
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function automation_time_value($value): string
{
    $int = (int)$value;
    return str_pad((string)$int, 2, '0', STR_PAD_LEFT);
}

function automation_frequency_value($value): string
{
    return ozon_price_automation_frequency_normalize((string)$value);
}

function automation_run_status_label(string $status): string
{
    return match ($status) {
        'success' => 'Успешно',
        'error' => 'Ошибка',
        'running' => 'Идёт сейчас',
        default => $status !== '' ? $status : '—',
    };
}

function automation_run_status_class(string $status): string
{
    return match ($status) {
        'success' => 'status-ok',
        'error' => 'status-error',
        'running' => 'status-running',
        default => 'status-neutral',
    };
}

function automation_run_is_significant(array $run): bool
{
    $status = (string)($run['status'] ?? '');
    if ($status === 'error' || $status === 'running') {
        return true;
    }
    $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
    if ((int)($summary['queued'] ?? 0) > 0) {
        return true;
    }
    $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
    foreach ($items as $item) {
        $itemStatus = (string)($item['status'] ?? '');
        if ($itemStatus === 'queued' || $itemStatus === 'duplicate' || $itemStatus === 'error') {
            return true;
        }
    }
    return false;
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Price Tool — Автоматизация</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f8fc;
      --card: #ffffff;
      --border: #d9e5f2;
      --text: #17233a;
      --muted: #61738d;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
      --danger: #b42318;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f7fbff 0%, #f4f7fb 100%);
      color: var(--text);
      font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 20;
      padding: 8px 12px;
      border-radius: 999px;
      background: #fff5d6;
      color: #8a5a00;
      border: 1px solid #f2d386;
      font-weight: 700;
      font-size: 13px;
    }
    .topbar {
      max-width: 1420px;
      margin: 0 auto;
      padding: 28px 18px 18px;
    }
    .page {
      max-width: 1420px;
      margin: 0 auto;
      padding: 0 18px 36px;
      display: grid;
      gap: 18px;
    }
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 22px;
      box-shadow: var(--shadow);
    }
    h1, h2, h3 { margin: 0 0 10px; line-height: 1.15; }
    .muted { color: var(--muted); }
    .flash, .error {
      margin-bottom: 16px;
      padding: 14px 18px;
      border-radius: 16px;
      border: 1px solid var(--border);
    }
    .flash { background: #edfdf3; color: #166534; border-color: #b7ebc6; }
    .error { background: #fff1f2; color: var(--danger); border-color: #fecdd3; }
    .button-link, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 14px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 700;
      cursor: pointer;
    }
    .button-link.secondary, button.secondary {
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    .current-connection-card {
      margin-top: 18px;
      display: flex;
      gap: 18px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      border: 1px solid #cfe0f7;
      border-radius: 24px;
      padding: 20px 22px;
      background:
        radial-gradient(circle at top right, rgba(191, 219, 254, 0.35), transparent 34%),
        linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
      box-shadow: 0 16px 32px rgba(27, 57, 90, 0.08);
    }
    .current-connection-kicker {
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .current-connection-title {
      font-size: 32px;
      font-weight: 800;
      line-height: 1.05;
      letter-spacing: -0.03em;
      margin-bottom: 6px;
    }
    .current-connection-meta {
      color: var(--muted);
      font-size: 15px;
    }
    .tab-nav {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 18px;
      align-items: end;
      padding-left: 10px;
      border-bottom: 1px solid #cfdceb;
      width: fit-content;
    }
    .tab-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 52px;
      padding: 0 24px;
      border-radius: 18px 18px 0 0;
      border: 1px solid #cfdceb;
      border-bottom: none;
      background: linear-gradient(180deg, #ffffff 0%, #eef4fb 100%);
      color: #334155;
      text-decoration: none;
      font-weight: 700;
      position: relative;
      top: 1px;
      box-shadow: 0 -1px 0 rgba(255,255,255,.9) inset;
    }
    .tab-link.active {
      background: linear-gradient(180deg, #17233a 0%, #0f172a 100%);
      color: #fff;
      border-color: #0f172a;
      box-shadow:
        0 10px 24px rgba(15, 23, 42, 0.14),
        0 -1px 0 rgba(255,255,255,.08) inset;
    }
    .tab-stack {
      display: grid;
      gap: 12px;
      justify-content: start;
      margin-top: 18px;
    }
    .tab-nav.is-subtabs {
      margin-top: 0;
      gap: 8px;
      padding-left: 14px;
      border-bottom-color: #dbe7f3;
    }
    .tab-nav.is-subtabs .tab-link {
      min-height: 44px;
      padding: 0 20px;
      border-radius: 14px 14px 0 0;
      background: linear-gradient(180deg, #f8fbff 0%, #edf3fb 100%);
      color: #52637d;
      border-color: #dbe7f3;
      box-shadow: 0 -1px 0 rgba(255,255,255,.95) inset;
    }
    .tab-nav.is-subtabs .tab-link.active {
      background: linear-gradient(180deg, #e8f1ff 0%, #dce8fb 100%);
      color: #13233d;
      border-color: #bfd1ea;
      box-shadow:
        0 8px 20px rgba(148, 163, 184, 0.16),
        0 -1px 0 rgba(255,255,255,.85) inset;
    }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
    }
    .back-link:hover {
      color: var(--text);
    }
    .flash, .error {
      max-width: 1420px;
      margin: 0 auto 16px;
    }
    .automation-grid {
      display: grid;
      gap: 18px;
      grid-template-columns: minmax(0, 1fr);
      align-items: start;
    }
	    .automation-card {
	      border: 1px solid var(--border);
	      border-radius: 20px;
	      padding: 18px;
	      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
	    }
	    .feed-automation-list {
	      display: grid;
	      gap: 12px;
	      margin-top: 16px;
	    }
	    .feed-automation-row {
	      display: grid;
	      grid-template-columns: minmax(240px, 1.4fr) minmax(140px, .7fr) minmax(140px, .7fr) minmax(180px, .8fr) minmax(170px, .8fr) 90px 90px;
	      gap: 12px;
	      align-items: stretch;
	      border: 1px solid #b8cde6;
	      border-radius: 18px;
	      padding: 14px 16px;
	      background: linear-gradient(180deg, #f7fbff 0%, #ffffff 100%);
	      box-shadow: 0 12px 28px rgba(23, 35, 58, 0.06);
	    }
	    .feed-automation-row.is-enabled {
	      border-color: #b7ebc6;
	      background: linear-gradient(180deg, #edfdf3 0%, #ffffff 100%);
	    }
	    .feed-automation-row.is-disabled {
	      border-color: #fecdd3;
	      background: linear-gradient(180deg, #fff1f2 0%, #ffffff 100%);
	    }
	    .feed-automation-main {
	      min-width: 0;
	      display: flex;
	      flex-direction: column;
	      justify-content: center;
	    }
	    .feed-automation-title {
	      font-size: 24px;
	      font-weight: 800;
	      line-height: 1.1;
	    }
	    .feed-automation-meta,
	    .feed-automation-hint {
	      color: var(--muted);
	      font-size: 14px;
	      margin-top: 4px;
	    }
	    .feed-automation-cell {
	      min-width: 0;
	      border: 1px solid #d9e5f2;
	      border-radius: 14px;
	      background: #fff;
	      padding: 10px 12px;
	      display: flex;
	      flex-direction: column;
	      justify-content: center;
	    }
	    .feed-automation-row.is-enabled .feed-automation-cell {
	      border-color: #cfe9d9;
	    }
	    .feed-automation-row.is-disabled .feed-automation-cell {
	      border-color: #f6d3d8;
	    }
	    .feed-automation-cell .cell-label {
	      font-size: 12px;
	      text-transform: uppercase;
	      letter-spacing: .04em;
	      color: var(--muted);
	      margin-bottom: 4px;
	      font-weight: 700;
	    }
	    .feed-automation-cell .cell-value {
	      font-size: 16px;
	      font-weight: 700;
	      line-height: 1.2;
	    }
	    .feed-automation-row select,
	    .feed-automation-row input[type="number"] {
	      width: 100%;
	      min-height: 42px;
	      padding: 0 12px;
	      border: 1px solid #ced9e8;
	      border-radius: 12px;
	      font-size: 15px;
	      background: #fff;
	      color: var(--text);
	    }
	    .feed-enabled {
	      display: inline-flex;
	      align-items: center;
	      gap: 8px;
	      font-weight: 700;
	      font-size: 15px;
	      min-height: 42px;
	    }
	    .feed-enabled.enabled {
	      color: #166534;
	    }
	    .feed-enabled.disabled {
	      color: #b42318;
	    }
	    .feed-enabled input {
	      inline-size: 18px;
	      block-size: 18px;
	    }
	    .feed-inline-open {
	      margin-top: 6px;
	    }
	    .stats {
	      display: grid;
	      gap: 12px;
	      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	      margin-top: 14px;
    }
    .stat {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 12px 14px;
      background: #fff;
    }
    .stat .label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .stat .value {
      font-size: 28px;
      font-weight: 800;
      line-height: 1.05;
    }
    .field { margin-top: 14px; }
    .field label {
      display: block;
      margin-bottom: 6px;
      font-weight: 700;
      font-size: 14px;
    }
    .field input[type="number"] {
      width: 100%;
      min-height: 46px;
      padding: 0 14px;
      border: 1px solid #ced9e8;
      border-radius: 14px;
      font-size: 16px;
      background: #fff;
      color: var(--text);
    }
    .field small { display: block; margin-top: 6px; color: var(--muted); }
    .checkbox-line {
      display: flex;
      gap: 10px;
      align-items: center;
      font-weight: 700;
    }
    .time-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(2, minmax(120px, 160px));
    }
    .feed-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
      max-height: 280px;
      overflow: auto;
      padding-right: 4px;
    }
    .feed-item {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      background: #fff;
    }
    .feed-item input { margin-top: 4px; }
	    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
    .history-list {
      display: grid;
      gap: 14px;
    }
    .run-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      background: #fff;
      padding: 16px;
    }
    .run-top {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      align-items: flex-start;
    }
    .status-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 32px;
      padding: 0 12px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 13px;
      border: 1px solid transparent;
    }
    .status-ok { background: #edfdf3; color: #166534; border-color: #b7ebc6; }
    .status-error { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
    .status-running { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .status-neutral { background: #f8fafc; color: #475569; border-color: #dbe4ef; }
    .run-meta {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      margin-top: 8px;
      color: var(--muted);
      font-size: 14px;
    }
    .run-summary {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      margin-top: 14px;
    }
    .run-summary .stat .value {
      font-size: 22px;
    }
    .run-items {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }
    .run-item {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      background: #f8fbff;
    }
    .run-item-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      align-items: baseline;
    }
    .run-item-title {
      font-weight: 800;
    }
    .run-item-meta {
      color: var(--muted);
      font-size: 14px;
      margin-top: 4px;
    }
	    .run-log {
	      margin-top: 12px;
	    }
    .run-log summary {
      cursor: pointer;
      font-weight: 700;
      color: var(--text);
    }
	    .run-log pre {
	      margin: 10px 0 0;
      padding: 14px;
      border-radius: 14px;
      background: #0f172a;
      color: #e2e8f0;
      overflow: auto;
      white-space: pre-wrap;
	      word-break: break-word;
	      font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace;
	    }
	    @media (max-width: 1240px) {
	      .feed-automation-row {
	        grid-template-columns: minmax(240px, 1.4fr) repeat(3, minmax(140px, 1fr));
	      }
	    }
	    @media (max-width: 860px) {
	      .feed-automation-row {
	        grid-template-columns: 1fr;
	      }
	    }
	  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'ozon_price_tool.php?connection_id=' . urlencode((string)$currentConnectionId), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">Price Tool</h1>
    <div class="current-connection-card">
      <div>
        <div class="current-connection-kicker">Текущий кабинет</div>
        <?php if ($currentConnectionId > 0 && is_array($currentConnection)): ?>
          <div class="current-connection-title"><?= h((string)($currentConnection['title'] ?? '—')) ?></div>
          <div class="current-connection-meta">
            <?= h($currentMarketplaceLabel) ?>
            <?php if (!empty($currentConnection['client_id'])): ?>
              · client_id <?= h((string)$currentConnection['client_id']) ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="muted">Кабинет пока не выбран. Открой нужное подключение из общего раздела.</div>
        <?php endif; ?>
      </div>
      <a class="button-link secondary" href="marketplace_connections.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Выбрать кабинет</a>
    </div>
    <div class="tab-stack">
      <div class="tab-nav">
        <a class="tab-link active" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Price Tool</a>
        <a class="tab-link" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Orders Sync</a>
        <a class="tab-link" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Stocks Tool</a>
        <?php if ($currentMarketplace === 'ozon'): ?><a class="tab-link" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a><?php endif; ?>
        <?php if (price_tool_connection_supports($currentConnection, 'fbo_tool')): ?><a class="tab-link" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a><?php endif; ?>
      </div>
      <div class="tab-nav is-subtabs">
        <a class="tab-link" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Общие настройки и фиды</a>
        <a class="tab-link active" href="ozon_price_tool_automations.php?connection_id=<?= h((string)$currentConnectionId) ?>">Автоматизация</a>
      </div>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="page">
    <?php if (!$currentMarketplaceReady): ?>
    <div class="card" style="background:linear-gradient(180deg,#fffaf3 0%,#ffffff 100%);">
      <h2><?= h($currentMarketplaceLabel) ?>: автоматизация Price Tool ещё не включена</h2>
      <div class="muted">Для этого маркетплейса подключение уже можно хранить и редактировать, но расписания расчёта и выгрузки пока не подключены.</div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="muted">Технически runner проверяет автоматизации каждые 5 минут, а сами процессы ставятся в очередь по выбранной частоте и базовому времени по Москве.</div>
      <form method="post">
        <input type="hidden" name="action" value="save_automations">
        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
        <?php
          $generalAutomationKeys = $currentMarketplace === 'wb'
              ? ['wb_sync_promotions_nightly', 'wb_download_promotion_xlsx_hourly', 'wb_import_promotion_xlsx_folder_hourly']
              : ['ozon_sync_actions_nightly'];
          $generalAutomationDescriptions = [
              'ozon_sync_actions_nightly' => 'Это общий процесс для всего инструмента: он обновляет локальную базу акций, чтобы все фиды работали с актуальными ценами входа, кандидатами и параметрами бустинга.',
              'wb_sync_promotions_nightly' => 'Это общий процесс для WB Price Tool: он обновляет локальную базу календаря акций, кандидатов и плановых скидок, чтобы профили могли безопасно считать цены под акции.',
              'wb_download_promotion_xlsx_hourly' => 'По сохранённому cURL-шаблону кабинета WB скачивает XLS/XLSX активных автоакций, берёт сроки из календаря акций и сразу импортирует плановые цены.',
              'wb_import_promotion_xlsx_folder_hourly' => 'Проверяет серверную папку с XLS/XLSX-файлами автоакций WB, разбирает новые файлы, сохраняет плановые цены и переносит обработанные файлы в архив.',
          ];
          $generalAutomations = [];
          foreach ($generalAutomationKeys as $generalAutomationKey) {
              if (!empty($automations[$generalAutomationKey])) {
                  $generalAutomations[] = $automations[$generalAutomationKey];
              }
          }
          $feedAutomations = array_values(array_filter($automations, static function (array $automation): bool {
              return in_array((string)($automation['op_type'] ?? ''), ozon_price_feed_push_op_types(), true) && !empty($automation['feed_id']);
          }));
          usort($feedAutomations, static function (array $a, array $b): int {
              return strnatcasecmp((string)($a['feed_name'] ?? $a['title'] ?? ''), (string)($b['feed_name'] ?? $b['title'] ?? ''));
          });
        ?>

	        <?php if ($feedAutomations): ?>
	          <div style="margin-top:18px;">
	            <h2 style="margin-bottom:8px;">Обновление цен по фидам</h2>
	            <div class="muted">У каждого фида здесь свои отдельные настройки: включение, частота и базовое время запуска именно для этого профиля.</div>
	            <div class="feed-automation-list">
	              <?php foreach ($feedAutomations as $automation): ?>
	                <?php $key = (string)$automation['automation_key']; ?>
	                <div class="feed-automation-row <?= !empty($automation['enabled']) ? 'is-enabled' : 'is-disabled' ?>">
	                  <div class="feed-automation-main">
	                    <div class="feed-automation-title"><?= h((string)($automation['feed_name'] ?? $automation['title'] ?? 'Фид')) ?></div>
	                    <div class="feed-automation-meta">
	                      <code><?= h((string)($automation['feed_cost_tag'] ?? '')) ?></code>
	                      · Схема: <?= h(strtoupper((string)($automation['feed_scheme'] ?? ''))) ?>
	                      <?php if (!empty($automation['feed_supplier_code'])): ?>
	                        · Код поставщика: <code><?= h((string)$automation['feed_supplier_code']) ?></code>
	                      <?php endif; ?>
	                    </div>
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Последний запуск</div>
	                    <div class="cell-value"><?= h((string)($automation['last_run_at'] ?? '—') ?: '—') ?></div>
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Последняя операция</div>
	                    <div class="cell-value"><?= !empty($automation['last_op_id']) ? '#' . h((string)$automation['last_op_id']) : '—' ?></div>
	                    <?php if (!empty($automation['last_op_id'])): ?>
	                      <div class="feed-inline-open"><a class="button-link secondary" style="min-height:34px; padding:0 12px;" href="op.php?id=<?= h((string)$automation['last_op_id']) ?>">Открыть</a></div>
	                    <?php endif; ?>
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Включение</div>
	                    <label class="feed-enabled <?= !empty($automation['enabled']) ? 'enabled' : 'disabled' ?>">
	                      <input type="checkbox" name="<?= h($key) ?>_enabled" value="1" <?= !empty($automation['enabled']) ? 'checked' : '' ?>>
	                      <span><?= !empty($automation['enabled']) ? 'Включено' : 'Выключено' ?></span>
	                    </label>
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Частота</div>
	                    <select id="<?= h($key) ?>_frequency_key" name="<?= h($key) ?>_frequency_key">
	                      <?php foreach (ozon_price_automation_frequency_options() as $frequencyKey => $frequency): ?>
	                        <option value="<?= h($frequencyKey) ?>" <?= automation_frequency_value($automation['frequency_key'] ?? '') === $frequencyKey ? 'selected' : '' ?>><?= h((string)$frequency['label']) ?></option>
	                      <?php endforeach; ?>
	                    </select>
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Часы</div>
	                    <input id="<?= h($key) ?>_run_hour_msk" type="number" min="0" max="23" name="<?= h($key) ?>_run_hour_msk" value="<?= h(automation_time_value($automation['run_hour_msk'] ?? 0)) ?>">
	                  </div>

	                  <div class="feed-automation-cell">
	                    <div class="cell-label">Минуты</div>
	                    <input id="<?= h($key) ?>_run_minute_msk" type="number" min="0" max="59" name="<?= h($key) ?>_run_minute_msk" value="<?= h(automation_time_value($automation['run_minute_msk'] ?? 0)) ?>">
	                  </div>
	                </div>
	              <?php endforeach; ?>
	            </div>
	          </div>
	        <?php else: ?>
          <div class="muted" style="margin-top:18px;">Пока нет профилей фидов. Сначала создай хотя бы один профиль, и здесь появятся его настройки автоматического обновления.</div>
        <?php endif; ?>

        <?php if ($generalAutomations): ?>
          <div style="margin-top:24px;">
            <h2 style="margin-bottom:8px;"><?= $currentMarketplace === 'wb' ? 'Службы акций WB' : 'Синхронизация акций Ozon' ?></h2>
            <div class="automation-grid" style="margin-top:16px;">
              <?php foreach ($generalAutomations as $generalAutomation): ?>
                <?php
                  $key = (string)$generalAutomation['automation_key'];
                  $generalParams = json_decode((string)($generalAutomation['params_json'] ?? '{}'), true);
                  $generalParams = is_array($generalParams) ? $generalParams : [];
                  $generalDescription = (string)($generalAutomationDescriptions[$key] ?? 'Общий процесс Price Tool.');
                ?>
                <div class="automation-card">
                  <h3 style="margin-bottom:8px;"><?= h((string)($generalAutomation['title'] ?? $key)) ?></h3>
                  <div class="muted"><?= h($generalDescription) ?></div>
                  <?php if (in_array((string)($generalAutomation['op_type'] ?? ''), ['wb_import_promotion_xlsx_folder', 'wb_download_promotion_xlsx'], true)): ?>
                    <div class="muted" style="margin-top:8px;">Папка: <code><?= h((string)($generalParams['inbox_dir'] ?? '')) ?></code></div>
                  <?php endif; ?>
                  <div class="stats" style="margin-top:14px;">
                    <div class="stat">
                      <div class="label">Последний запуск</div>
                      <div class="value" style="font-size:22px;"><?= h((string)($generalAutomation['last_run_at'] ?? '—') ?: '—') ?></div>
                    </div>
                    <div class="stat">
                      <div class="label">Последняя операция</div>
                      <div class="value" style="font-size:22px;"><?= !empty($generalAutomation['last_op_id']) ? '#' . h((string)$generalAutomation['last_op_id']) : '—' ?></div>
                      <?php if (!empty($generalAutomation['last_op_id'])): ?>
                        <div style="margin-top:6px;"><a class="button-link secondary" style="min-height:36px; padding:0 12px;" href="op.php?id=<?= h((string)$generalAutomation['last_op_id']) ?>">Открыть</a></div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="field">
                    <label class="checkbox-line">
                      <input type="checkbox" name="<?= h($key) ?>_enabled" value="1" <?= !empty($generalAutomation['enabled']) ? 'checked' : '' ?>>
                      <span>Автоматизация включена</span>
                    </label>
                    <small>Если выключить, runner будет полностью пропускать этот процесс.</small>
                  </div>

                  <div class="field">
                    <label for="<?= h($key) ?>_frequency_key">Частота запуска</label>
                    <select id="<?= h($key) ?>_frequency_key" name="<?= h($key) ?>_frequency_key" style="width:100%; min-height:46px; padding:0 14px; border:1px solid #ced9e8; border-radius:14px; font-size:16px; background:#fff; color:var(--text);">
                      <?php foreach (ozon_price_automation_frequency_options() as $frequencyKey => $frequency): ?>
                        <option value="<?= h($frequencyKey) ?>" <?= automation_frequency_value($generalAutomation['frequency_key'] ?? '') === $frequencyKey ? 'selected' : '' ?>><?= h((string)$frequency['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>Базовое время ниже служит первой точкой запуска. Дальше runner повторяет процесс по выбранной частоте.</small>
                  </div>

                  <div class="field">
                    <label>Базовое время по Москве</label>
                    <div class="time-grid">
                      <div>
                        <label for="<?= h($key) ?>_run_hour_msk">Часы</label>
                        <input id="<?= h($key) ?>_run_hour_msk" type="number" min="0" max="23" name="<?= h($key) ?>_run_hour_msk" value="<?= h(automation_time_value($generalAutomation['run_hour_msk'] ?? 0)) ?>">
                      </div>
                      <div>
                        <label for="<?= h($key) ?>_run_minute_msk">Минуты</label>
                        <input id="<?= h($key) ?>_run_minute_msk" type="number" min="0" max="59" name="<?= h($key) ?>_run_minute_msk" value="<?= h(automation_time_value($generalAutomation['run_minute_msk'] ?? 0)) ?>">
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="actions">
          <button type="submit">Сохранить настройки автоматизации</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
        <div>
          <h2>Последние запуски автоматизации</h2>
          <div class="muted">Здесь видно, что runner проверил, что реально поставил в очередь, что пропустил и почему.</div>
        </div>
      </div>

      <?php
        $significantRunLogs = array_values(array_filter($runLogs, 'automation_run_is_significant'));
      ?>
      <?php if (!$significantRunLogs): ?>
        <div class="muted" style="margin-top:14px;">Пока значимых запусков не было. Пустые 5-минутные проверки runner здесь скрыты.</div>
      <?php else: ?>
        <div class="history-list" style="margin-top:16px;">
          <?php foreach ($significantRunLogs as $run): ?>
            <?php
              $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
              $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
              $startedAt = (string)($run['started_at'] ?? '');
              $finishedAt = (string)($run['finished_at'] ?? '');
              $status = (string)($run['status'] ?? '');
            ?>
            <div class="run-card">
              <div class="run-top">
                <div>
                  <div style="font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);">Запуск #<?= h((string)$run['id']) ?></div>
                  <div class="run-meta">
                    <span>Старт: <?= h($startedAt !== '' ? $startedAt : '—') ?></span>
                    <span>Финиш: <?= h($finishedAt !== '' ? $finishedAt : '—') ?></span>
                    <span>Источник: <?= h((string)($run['source'] ?? 'cron')) ?></span>
                  </div>
                </div>
                <div class="status-chip <?= h(automation_run_status_class($status)) ?>"><?= h(automation_run_status_label($status)) ?></div>
              </div>

              <div class="run-summary">
                <div class="stat">
                  <div class="label">Проверено</div>
                  <div class="value"><?= h((string)($summary['checked'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Поставлено в очередь</div>
                  <div class="value"><?= h((string)($summary['queued'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Пропущено</div>
                  <div class="value"><?= h((string)($summary['skipped'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Время по Москве</div>
                  <div class="value" style="font-size:20px;"><?= h((string)($summary['now_msk'] ?? '—')) ?></div>
                </div>
              </div>

              <?php if ($items): ?>
                <div class="run-items">
                  <?php foreach ($items as $item): ?>
                    <div class="run-item">
                      <div class="run-item-top">
                        <div class="run-item-title"><?= h((string)($item['title'] ?? $item['automation_key'] ?? 'Автоматизация')) ?></div>
                        <div class="status-chip <?= h(automation_run_status_class((string)($item['status'] ?? ''))) ?>"><?= h(automation_run_status_label((string)($item['status'] ?? ''))) ?></div>
                      </div>
                      <div class="run-item-meta">
                        <?= h((string)($item['message'] ?? '')) ?>
                        <?php if (!empty($item['frequency'])): ?>
                          · Частота: <?= h((string)$item['frequency']) ?>
                        <?php endif; ?>
                        <?php if (!empty($item['slot_start']) && !empty($item['slot_end'])): ?>
                          · Интервал: <?= h((string)$item['slot_start']) ?> - <?= h((string)$item['slot_end']) ?>
                        <?php endif; ?>
                        <?php if (!empty($item['op_id'])): ?>
                          · <a href="op.php?id=<?= h((string)$item['op_id']) ?>">Операция #<?= h((string)$item['op_id']) ?></a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (trim((string)($run['log_text'] ?? '')) !== ''): ?>
                <details class="run-log">
                  <summary>Показать полный лог запуска</summary>
                  <pre><?= h((string)$run['log_text']) ?></pre>
                </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
