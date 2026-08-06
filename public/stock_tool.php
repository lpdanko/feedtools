<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/stock_tool.php';
require_once __DIR__ . '/../app/navigation.php';

stock_tool_module_bootstrap($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=stock_tool', true, 303);
    exit;
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=stock_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$currentMarketplaceReady = $currentMarketplace === 'ozon' && price_tool_connection_supports($currentConnection, 'stock_tool');
$runtimeCfg = ozon_price_cfg_with_connection($cfg, $currentConnection);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('stock pois доступен только для подключения Ozon.');
            }
            stock_tool_settings_save($currentConnectionId, $_POST, $actor, $cfg);
            header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&settings_saved=1', true, 303);
            exit;
        }
        if ($action === 'add_item') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('stock pois доступен только для подключения Ozon.');
            }
            $itemId = stock_tool_item_add($currentConnectionId, $_POST, $actor, $cfg);
            header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_added=' . urlencode((string)$itemId), true, 303);
            exit;
        }
        if (in_array($action, ['save_item', 'delete_item', 'pause_item', 'resume_item', 'refresh_item'], true)) {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('stock pois доступен только для подключения Ozon.');
            }
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($action === 'save_item') {
                stock_tool_item_save($currentConnectionId, $_POST + ['id' => $itemId], $actor, $cfg);
                header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_saved=1', true, 303);
                exit;
            }
            if ($action === 'delete_item') {
                stock_tool_item_delete($currentConnectionId, $itemId, $cfg);
                header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_deleted=1', true, 303);
                exit;
            }
            if ($action === 'pause_item') {
                stock_tool_item_set_status($currentConnectionId, $itemId, 'paused', $actor, $cfg);
                header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_paused=1', true, 303);
                exit;
            }
            if ($action === 'resume_item') {
                stock_tool_item_set_status($currentConnectionId, $itemId, 'ready', $actor, $cfg);
                header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_resumed=1', true, 303);
                exit;
            }
            if ($action === 'refresh_item') {
                stock_tool_item_refresh($currentConnectionId, $itemId, $actor, $cfg);
                header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&item_refreshed=1', true, 303);
                exit;
            }
        }
        if ($action === 'run_stock_tool') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('stock pois доступен только для подключения Ozon.');
            }
            $runId = stock_tool_run_connection($currentConnectionId, $actor, $cfg);
            header('Location: stock_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&run_id=' . urlencode((string)$runId) . '&run_started=1', true, 303);
            exit;
        }
    }

    if (isset($_GET['settings_saved'])) {
        $flash = 'Настройки stock pois сохранены.';
    } elseif (isset($_GET['item_added'])) {
        $flash = 'Товар добавлен в stock pois.';
    } elseif (isset($_GET['item_saved'])) {
        $flash = 'Товар сохранён.';
    } elseif (isset($_GET['item_deleted'])) {
        $flash = 'Товар удалён.';
    } elseif (isset($_GET['item_paused'])) {
        $flash = 'Товар поставлен на паузу.';
    } elseif (isset($_GET['item_resumed'])) {
        $flash = 'Товар снова активен.';
    } elseif (isset($_GET['item_refreshed'])) {
        $flash = 'Текущая цена Ozon обновлена.';
    } elseif (isset($_GET['run_started'])) {
        $flash = 'Синхронизация stock pois выполнена.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$settings = $currentMarketplaceReady ? stock_tool_settings_get($currentConnectionId, $cfg) : stock_tool_settings_default($currentConnectionId);
$items = $currentMarketplaceReady ? stock_tool_items_list($currentConnectionId, $cfg) : [];
$priceFeeds = $currentMarketplaceReady ? stock_tool_price_feeds_for_connection($currentConnectionId, $cfg) : [];
$warehouseOptions = [];
if ($currentMarketplaceReady) {
    try {
        $warehouseOptions = stocks_tool_warehouse_options($runtimeCfg, $currentConnection);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = $e->getMessage();
        }
    }
}
$runId = (int)($_GET['run_id'] ?? 0);
$selectedRun = $runId > 0 ? stock_tool_run_get($runId, $cfg) : null;
$recentRuns = $currentMarketplaceReady ? stock_tool_recent_runs($currentConnectionId, 8, $cfg) : [];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stock_tool_fmt_money($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 0, ',', ' ') . ' ₽';
}

function stock_tool_fmt_input_money($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }
    return number_format((float)$value, 2, '.', '');
}

function stock_tool_fmt_index_value($value): string
{
    if ($value === null || $value === '' || !is_numeric($value) || (float)$value <= 0) {
        return '—';
    }
    return number_format((float)$value, 4, ',', ' ');
}

function stock_tool_price_mode_label(string $mode): string
{
    return match (stock_tool_normalize_price_mode($mode)) {
        'discount_amount' => 'Скидка ₽',
        default => 'Точная цена',
    };
}

function stock_tool_discount_value_for_item(array $item): string
{
    $mode = stock_tool_normalize_price_mode((string)($item['price_mode'] ?? 'exact'));
    return $mode === 'discount_amount'
        ? stock_tool_fmt_input_money($item['discount_amount'] ?? null)
        : stock_tool_fmt_input_money($item['discount_price'] ?? null);
}

function stock_tool_price_index_source_label($source): string
{
    $source = trim((string)$source);
    return $source !== '' ? ozon_price_index_source_label($source) : '';
}

function stock_tool_fmt_dt($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $raw;
    }
}

function stock_tool_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Черновик',
        'ready' => 'Готов',
        'active' => 'Скидка',
        'sold' => 'Продан',
        'restored' => 'Цена возвращена',
        'paused' => 'Пауза',
        'error' => 'Ошибка',
        'running' => 'Выполняется',
        'success' => 'Успешно',
        'partial' => 'Частично',
        default => $status !== '' ? $status : '—',
    };
}

function stock_tool_status_class(string $status): string
{
    return match ($status) {
        'active' => 'ok',
        'ready', 'sold' => 'run',
        'restored' => 'muted',
        'paused' => 'idle',
        'error', 'partial' => 'err',
        'success' => 'ok',
        'running' => 'run',
        default => 'draft',
    };
}

function stock_tool_feed_label(array $feed): string
{
    $name = trim((string)($feed['name'] ?? ('Профиль #' . (int)($feed['id'] ?? 0))));
    $supplier = trim((string)($feed['supplier_code'] ?? ''));
    return $supplier !== '' ? ($name . ' / ' . $supplier) : $name;
}

$feedLabels = [];
foreach ($priceFeeds as $feed) {
    $feedLabels[(int)($feed['id'] ?? 0)] = stock_tool_feed_label($feed);
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>stock pois</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f7fb;
      --card: #ffffff;
      --soft: #f7fbfa;
      --border: #d9e4ef;
      --text: #172033;
      --muted: #637189;
      --ink: #101827;
      --ok-bg: #eafaf0;
      --ok-text: #166534;
      --run-bg: #eef6ff;
      --run-text: #1d4ed8;
      --warn-bg: #fff7ed;
      --warn-text: #9a3412;
      --err-bg: #fff1f2;
      --err-text: #b42318;
      --shadow: 0 16px 34px rgba(20, 40, 70, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
      color: var(--text);
      font: 15px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .topbar, .page, .flash, .error { max-width: 1420px; margin: 0 auto; }
    .topbar { padding: 28px 18px 16px; }
    .page { padding: 0 18px 38px; display: grid; gap: 16px; }
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      box-shadow: var(--shadow);
      min-width: 0;
    }
    h1, h2, h3 { margin: 0 0 8px; line-height: 1.16; }
    h1 { font-size: 30px; }
    h2 { font-size: 20px; }
    h3 { font-size: 16px; }
    .muted { color: var(--muted); }
    .flash, .error {
      margin-bottom: 14px;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid var(--border);
    }
    .flash { background: var(--ok-bg); color: var(--ok-text); border-color: #b7ebc6; }
    .error { background: var(--err-bg); color: var(--err-text); border-color: #fecdd3; }
    .tab-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .tab-link, .button, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 40px;
      padding: 0 14px;
      border-radius: 10px;
      border: 1px solid var(--ink);
      background: var(--ink);
      color: #fff;
      text-decoration: none;
      font-weight: 800;
      cursor: pointer;
      white-space: nowrap;
    }
    .tab-link { background: #fff; color: var(--text); border-color: var(--border); }
    .tab-link.active { background: var(--ink); color: #fff; border-color: var(--ink); }
    button.secondary, .button.secondary { background: #fff; color: var(--text); border-color: var(--border); }
    button.danger { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
    input, select, textarea {
      width: 100%;
      border: 1px solid #ccd8e5;
      border-radius: 10px;
      padding: 10px 12px;
      background: #fff;
      color: var(--text);
      font: inherit;
    }
    textarea { min-height: 74px; resize: vertical; }
    label { display: grid; gap: 6px; font-weight: 800; }
    .field-note { color: var(--muted); font-size: 12px; font-weight: 700; }
    .grid { display: grid; gap: 14px; }
    .settings-grid { grid-template-columns: minmax(230px, 1fr) minmax(230px, 1fr) 140px; align-items: end; }
    .add-grid { grid-template-columns: minmax(160px, 1.1fr) minmax(96px, .55fr) minmax(130px, .72fr) minmax(120px, .68fr) minmax(190px, 1fr) minmax(118px, auto); align-items: end; }
    .add-grid button { min-width: 118px; }
    .split { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(320px, .85fr); gap: 16px; align-items: stretch; }
    .split > .card { min-width: 0; }
    .sync-form { display: grid; gap: 10px; }
    .stat-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .stat {
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px;
      background: var(--soft);
    }
    .stat-value { font-size: 22px; font-weight: 900; }
    .stat-label { color: var(--muted); font-size: 12px; font-weight: 800; }
    .items-table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1220px; }
    th, td { padding: 10px 9px; border-bottom: 1px solid #edf1f6; vertical-align: top; text-align: left; }
    th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .02em; }
    .num { text-align: right; }
    .offer { font-weight: 900; color: var(--ink); }
    .name { max-width: 280px; color: var(--muted); font-size: 13px; }
    .status {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      background: #f1f5f9;
      color: #475569;
      white-space: nowrap;
    }
    .status.ok { background: var(--ok-bg); color: var(--ok-text); }
    .status.run { background: var(--run-bg); color: var(--run-text); }
    .status.err { background: var(--err-bg); color: var(--err-text); }
    .status.idle, .status.muted { background: #f1f5f9; color: #64748b; }
    .row-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
    .row-actions button { min-height: 34px; padding: 0 10px; border-radius: 9px; font-size: 12px; }
    .compact-input { min-width: 92px; padding: 8px 9px; }
    .compact-select { min-width: 128px; padding: 8px 9px; }
    .price-cell { display: grid; gap: 7px; min-width: 142px; }
    .wide-select { min-width: 190px; padding: 8px 9px; }
    .log {
      max-height: 320px;
      overflow: auto;
      white-space: pre-wrap;
      background: #101827;
      color: #e5eef9;
      border-radius: 12px;
      padding: 14px;
      font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .run-list { display: grid; gap: 8px; }
    .run-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      padding: 10px 0;
      border-bottom: 1px solid #edf1f6;
      color: inherit;
      text-decoration: none;
    }
    .run-item span:first-child { overflow-wrap: anywhere; }
    .run-item .muted { flex: 0 0 auto; }
    @media (max-width: 1360px) {
      .add-grid { grid-template-columns: minmax(160px, 1.15fr) minmax(96px, .6fr) minmax(130px, .75fr) minmax(120px, .7fr); }
      .add-grid label:nth-of-type(5) { grid-column: 1 / span 3; }
      .add-grid button {
        grid-column: 4;
        width: 100%;
        min-width: 0;
      }
    }
    @media (max-width: 980px) {
      .settings-grid, .add-grid, .split, .stat-row { grid-template-columns: 1fr; }
      .add-grid label:nth-of-type(5), .add-grid button { grid-column: auto; }
      .tab-link, .button, button { width: 100%; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <?= ft_top_navigation(['active' => 'connections', 'back_href' => 'marketplace_connections.php?connection_id=' . urlencode((string)$currentConnectionId), 'back_label' => 'Подключения', 'history_back' => true]) ?>
    <h1>stock pois</h1>
    <div class="muted"><?= h($currentMarketplaceLabel) ?> / <?= h((string)($currentConnection['title'] ?? '')) ?></div>
    <nav class="tab-nav" aria-label="Сервисы подключения">
      <a class="tab-link" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Price Tool</a>
      <a class="tab-link" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Stocks Tool</a>
      <a class="tab-link active" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a>
      <a class="tab-link" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a>
      <a class="tab-link" href="orders_sync.php?connection_id=<?= h((string)$currentConnectionId) ?>">Orders Sync</a>
    </nav>
  </header>

  <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <main class="page">
    <?php if (!$currentMarketplaceReady): ?>
      <section class="card">
        <h2><?= h($currentMarketplaceLabel) ?>: stock pois недоступен</h2>
      </section>
    <?php else: ?>
      <?php
        $activeCount = count(array_filter($items, static fn(array $item): bool => (string)($item['status'] ?? '') === 'active'));
        $readyCount = count(array_filter($items, static fn(array $item): bool => (string)($item['status'] ?? '') === 'ready'));
        $stockUnits = array_sum(array_map(static fn(array $item): int => (int)($item['last_known_stock_qty'] ?? 0), $items));
        $reservedUnits = array_sum(array_map(static fn(array $item): int => (int)($item['last_known_reserved_qty'] ?? 0), $items));
      ?>
      <section class="stat-row">
        <div class="stat"><div class="stat-value"><?= h((string)count($items)) ?></div><div class="stat-label">Товаров</div></div>
        <div class="stat"><div class="stat-value"><?= h((string)$activeCount) ?></div><div class="stat-label">Под скидкой</div></div>
        <div class="stat"><div class="stat-value"><?= h((string)$readyCount) ?></div><div class="stat-label">Готовы к отправке</div></div>
        <div class="stat"><div class="stat-value"><?= h((string)($stockUnits + $reservedUnits)) ?></div><div class="stat-label">Остаток + резерв</div></div>
      </section>

      <section class="card">
        <h2>Настройки</h2>
        <form method="post" class="grid settings-grid">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <label>
            Склад Ozon
            <select name="ozon_warehouse_key" required>
              <option value="">Выбери склад</option>
              <?php foreach ($warehouseOptions as $warehouse): ?>
                <?php $key = (string)($warehouse['key'] ?? ''); ?>
                <option value="<?= h($key) ?>" <?= $key === (string)($settings['ozon_warehouse_key'] ?? '') ? 'selected' : '' ?>>
                  <?= h((string)($warehouse['warehouse_name'] ?? $key)) ?><?= trim((string)($warehouse['warehouse_id'] ?? '')) !== '' ? ' / ID ' . h((string)$warehouse['warehouse_id']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Профиль Price Tool
            <select name="default_price_feed_id">
              <option value="0">Не выбран</option>
              <?php foreach ($priceFeeds as $feed): ?>
                <?php $feedId = (int)($feed['id'] ?? 0); ?>
                <option value="<?= h((string)$feedId) ?>" <?= $feedId === (int)($settings['default_price_feed_id'] ?? 0) ? 'selected' : '' ?>>
                  <?= h(stock_tool_feed_label($feed)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Статус
            <select name="is_enabled">
              <option value="1" <?= !empty($settings['is_enabled']) ? 'selected' : '' ?>>Включен</option>
              <option value="0" <?= empty($settings['is_enabled']) ? 'selected' : '' ?>>Выключен</option>
            </select>
          </label>
          <label style="grid-column: 1 / -2;">
            Заметка
            <textarea name="notes"><?= h((string)($settings['notes'] ?? '')) ?></textarea>
          </label>
          <button type="submit" name="action" value="save_settings">Сохранить</button>
        </form>
      </section>

      <section class="split">
        <div class="card">
          <h2>Добавить товар</h2>
          <form method="post" class="grid add-grid">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <label>
              Артикул
              <input name="offer_id" placeholder="offer_id" required>
            </label>
            <label>
              Остаток
              <input type="number" name="target_stock_qty" min="0" step="1" value="0">
            </label>
            <label>
              Тип цены
              <select name="price_mode">
                <option value="exact">Точная цена</option>
                <option value="discount_amount">Скидка ₽</option>
              </select>
            </label>
            <label>
              Значение
              <input type="number" name="discount_value" min="0" step="1" placeholder="0">
            </label>
            <label>
              Price Tool
              <select name="price_feed_id">
                <option value="<?= h((string)(int)($settings['default_price_feed_id'] ?? 0)) ?>">По умолчанию</option>
                <?php foreach ($priceFeeds as $feed): ?>
                  <?php $feedId = (int)($feed['id'] ?? 0); ?>
                  <option value="<?= h((string)$feedId) ?>"><?= h(stock_tool_feed_label($feed)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" name="action" value="add_item">Добавить</button>
          </form>
        </div>
        <div class="card">
          <h2>Синхронизация</h2>
          <form method="post" class="sync-form">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <button type="submit" name="action" value="run_stock_tool">Синхронизировать</button>
          </form>
          <?php if ($recentRuns): ?>
            <div class="run-list" style="margin-top:12px;">
              <?php foreach ($recentRuns as $run): ?>
                <a class="run-item" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&run_id=<?= h((string)(int)$run['id']) ?>">
                  <span>#<?= h((string)(int)$run['id']) ?> · <?= h(stock_tool_status_label((string)($run['status'] ?? ''))) ?></span>
                  <span class="muted"><?= h(stock_tool_fmt_dt($run['started_at'] ?? $run['created_at'] ?? '')) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <?php if (is_array($selectedRun)): ?>
        <section class="card">
          <h2>Лог запуска #<?= h((string)(int)$selectedRun['id']) ?></h2>
          <pre class="log"><?= h((string)($selectedRun['log_text'] ?? '')) ?></pre>
        </section>
      <?php endif; ?>

      <section class="card">
        <h2>Товары</h2>
        <div class="items-table-wrap">
          <table>
            <thead>
              <tr>
                <th>Артикул</th>
                <th>Статус</th>
                <th>Ozon</th>
                <th>Остаток</th>
                <th>Скидка</th>
                <th>Price Tool</th>
                <th>Синхронизация</th>
                <th class="num">Действия</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="8" class="muted">Список пуст.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
              <?php
                $itemId = (int)($item['id'] ?? 0);
                $formId = 'stock-item-form-' . $itemId;
                $status = (string)($item['status'] ?? '');
              ?>
              <tr>
                <td>
                  <div class="offer"><?= h((string)$item['offer_id']) ?></div>
                  <?php if (trim((string)($item['name'] ?? '')) !== ''): ?>
                    <div class="name" data-ft-i18n="off"><?= h((string)$item['name']) ?></div>
                  <?php endif; ?>
                  <?php if ((int)($item['product_id'] ?? 0) > 0): ?>
                    <div class="field-note">product_id <?= h((string)(int)$item['product_id']) ?></div>
                  <?php endif; ?>
                  <?php if (trim((string)($item['last_error'] ?? '')) !== ''): ?>
                    <div class="field-note" style="color:#b42318;"><?= h((string)$item['last_error']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="status <?= h(stock_tool_status_class($status)) ?>"><?= h(stock_tool_status_label($status)) ?></span></td>
                <td>
                  <div><?= h(stock_tool_fmt_money($item['current_price'] ?? null)) ?></div>
                  <div class="field-note">min <?= h(stock_tool_fmt_money($item['current_min_price'] ?? null)) ?></div>
                  <div class="field-note">уровень <?= h((string)(($item['current_price_index_level_label'] ?? '') ?: '—')) ?></div>
                  <div class="field-note">индекс <?= h(stock_tool_fmt_index_value($item['current_price_index_value'] ?? null)) ?><?= stock_tool_price_index_source_label($item['current_price_index_source'] ?? '') !== '' ? ' · ' . h(stock_tool_price_index_source_label($item['current_price_index_source'] ?? '')) : '' ?></div>
                  <?php if (($item['regular_price_snapshot'] ?? null) !== null): ?>
                    <div class="field-note">snapshot <?= h(stock_tool_fmt_money($item['regular_price_snapshot'])) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <input class="compact-input" form="<?= h($formId) ?>" type="number" name="target_stock_qty" min="0" step="1" value="<?= h((string)(int)($item['target_stock_qty'] ?? 0)) ?>">
                  <div class="field-note">Ozon <?= h((string)(int)($item['last_known_stock_qty'] ?? 0)) ?> + резерв <?= h((string)(int)($item['last_known_reserved_qty'] ?? 0)) ?></div>
                  <?php if (!empty($item['stock_push_required'])): ?><div class="field-note">ждет отправки</div><?php endif; ?>
                </td>
                <td>
                  <?php $priceMode = stock_tool_normalize_price_mode((string)($item['price_mode'] ?? 'exact')); ?>
                  <div class="price-cell">
                    <select class="compact-select" form="<?= h($formId) ?>" name="price_mode">
                      <option value="exact" <?= $priceMode === 'exact' ? 'selected' : '' ?>>Точная цена</option>
                      <option value="discount_amount" <?= $priceMode === 'discount_amount' ? 'selected' : '' ?>>Скидка ₽</option>
                    </select>
                    <input class="compact-input" form="<?= h($formId) ?>" type="number" name="discount_value" min="0" step="1" value="<?= h(stock_tool_discount_value_for_item($item)) ?>">
                  </div>
                  <div class="field-note">цель <?= h(stock_tool_fmt_money($item['discount_price'] ?? null)) ?></div>
                  <?php if (!empty($item['price_push_required'])): ?><div class="field-note">ждет цены</div><?php endif; ?>
                  <?php if (!empty($item['action_ids'])): ?><div class="field-note">акций <?= h((string)count((array)$item['action_ids'])) ?></div><?php endif; ?>
                </td>
                <td>
                  <select class="wide-select" form="<?= h($formId) ?>" name="price_feed_id">
                    <option value="0">Не выбран</option>
                    <?php foreach ($priceFeeds as $feed): ?>
                      <?php $feedId = (int)($feed['id'] ?? 0); ?>
                      <option value="<?= h((string)$feedId) ?>" <?= $feedId === (int)($item['price_feed_id'] ?? 0) ? 'selected' : '' ?>>
                        <?= h(stock_tool_feed_label($feed)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <div><?= h(stock_tool_fmt_dt($item['last_sync_at'] ?? '')) ?></div>
                  <div class="field-note">скидка <?= h(stock_tool_fmt_dt($item['discount_started_at'] ?? '')) ?></div>
                  <div class="field-note">возврат <?= h(stock_tool_fmt_dt($item['restored_at'] ?? '')) ?></div>
                </td>
                <td class="num">
                  <form id="<?= h($formId) ?>" method="post"></form>
                  <input form="<?= h($formId) ?>" type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                  <input form="<?= h($formId) ?>" type="hidden" name="item_id" value="<?= h((string)$itemId) ?>">
                  <div class="row-actions">
                    <button form="<?= h($formId) ?>" type="submit" name="action" value="save_item">Сохранить</button>
                    <button form="<?= h($formId) ?>" class="secondary" type="submit" name="action" value="refresh_item">Обновить</button>
                    <?php if ($status === 'paused'): ?>
                      <button form="<?= h($formId) ?>" class="secondary" type="submit" name="action" value="resume_item">Включить</button>
                    <?php else: ?>
                      <button form="<?= h($formId) ?>" class="secondary" type="submit" name="action" value="pause_item">Пауза</button>
                    <?php endif; ?>
                    <button form="<?= h($formId) ?>" class="danger" type="submit" name="action" value="delete_item" onclick="return confirm('Удалить товар из stock pois?')">Удалить</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
