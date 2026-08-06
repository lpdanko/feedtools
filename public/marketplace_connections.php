<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/orders_sync.php';
require_once __DIR__ . '/../app/stocks_tool.php';
require_once __DIR__ . '/../app/stock_tool.php';
require_once __DIR__ . '/../app/ozon_fbo_tool.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
ozon_price_feeds_table_ensure($cfg);
orders_sync_profile_table_ensure($cfg);
stocks_tool_profiles_table_ensure($cfg);
stock_tool_module_bootstrap($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$connectionTest = null;
$preservePostedConnectionEditor = false;
$currentConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
$needConnectionService = trim((string)($_GET['need_connection'] ?? ''));
$needConnectionMessage = match ($needConnectionService) {
    'price_tool' => 'Сначала открой нужный кабинет в разделе подключений, а затем заходи в Price Tool уже из карточки этого подключения.',
    'orders_sync' => 'Сначала открой нужный кабинет в разделе подключений, а затем заходи в Orders Sync уже из карточки этого подключения.',
    'stocks_tool' => 'Сначала открой нужный кабинет в разделе подключений, а затем заходи в Stocks Tool уже из карточки этого подключения.',
    'stock_tool' => 'Сначала открой Ozon-кабинет в разделе подключений, а затем заходи в stock pois уже из карточки этого подключения.',
    'fbo_tool' => 'Сначала открой Ozon или WB кабинет в разделе подключений, а затем заходи в FBO Tool уже из карточки этого подключения.',
    default => '',
};
$connectionEditorId = (int)($_GET['connection_edit_id'] ?? $_POST['connection_edit_id'] ?? 0);
$isNewConnectionMode = (isset($_GET['new_connection']) && $_GET['new_connection'] === '1')
    || (isset($_POST['new_connection']) && $_POST['new_connection'] === '1');

$allConnections = ozon_price_connection_list($cfg, null);
if (!$allConnections) {
    $isNewConnectionMode = true;
}

$connectionEditor = ozon_price_connection_default();
if ($connectionEditorId > 0) {
    $connectionEditor = ozon_price_connection_get($connectionEditorId, $cfg) ?? $connectionEditor;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_connection') {
            $savedConnectionId = ozon_price_connection_save($_POST, $actor);
            header('Location: marketplace_connections.php?connection_id=' . urlencode((string)$savedConnectionId) . '&connection_saved=1', true, 303);
            exit;
        }
        if ($action === 'test_connection') {
            $preservePostedConnectionEditor = true;
            $connectionEditor = array_replace(ozon_price_connection_default((string)($_POST['marketplace'] ?? 'ozon')), ozon_price_connection_normalize_input($_POST));
            $connectionTest = ozon_price_connection_test($_POST);
            $resolvedClientId = trim((string)($connectionTest['resolved_client_id'] ?? ''));
            if ($resolvedClientId !== '') {
                $connectionEditor['client_id'] = $resolvedClientId;
            }
        }
    }

    $allConnections = ozon_price_connection_list($cfg, null);
    if (!$allConnections) {
        $isNewConnectionMode = true;
    }
    if ($connectionEditorId > 0 && !$preservePostedConnectionEditor) {
        $connectionEditor = ozon_price_connection_get($connectionEditorId, $cfg) ?? $connectionEditor;
    }

    if (isset($_GET['connection_saved']) && $_GET['connection_saved'] === '1') {
        $flash = 'Подключение сохранено.';
    }
    if ($flash === '' && $needConnectionMessage !== '') {
        $flash = $needConnectionMessage;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $allConnections = ozon_price_connection_list($cfg, null);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $connectionEditor = array_replace(ozon_price_connection_default((string)($_POST['marketplace'] ?? 'ozon')), ozon_price_connection_normalize_input($_POST));
    } elseif ($connectionEditorId > 0) {
        $connectionEditor = ozon_price_connection_get($connectionEditorId, $cfg) ?? $connectionEditor;
    }
}

$feedCounts = ozon_price_feed_counts_by_connection($cfg);
$ordersSyncCounts = orders_sync_profile_counts_by_connection($cfg);
$stocksCounts = stocks_tool_profile_counts_by_connection($cfg);
$stockToolCounts = stock_tool_count_by_connection($cfg);
$fboCounts = ozon_fbo_tool_count_by_connection($cfg);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function connection_service_items(array $connection, array $feedCounts, array $ordersSyncCounts, array $stocksCounts, array $stockToolCounts, array $fboCounts): array
{
    $connectionId = (int)($connection['id'] ?? 0);
    return [
        [
            'key' => 'price_tool',
            'label' => 'Price Tool',
            'description' => 'Профили фидов, расчёт цен и автоматизация по этому кабинету.',
            'count_label' => 'Профилей фидов',
            'count' => (int)($feedCounts[$connectionId] ?? 0),
            'supported' => price_tool_connection_supports($connection, 'price_tool'),
            'href' => 'ozon_price_tool.php?connection_id=' . urlencode((string)$connectionId),
            'tone' => 'service-price',
        ],
        [
            'key' => 'orders_sync',
            'label' => 'Orders Sync',
            'description' => 'Профили синхронизации заказов и запуск ручного sync для этого кабинета.',
            'count_label' => 'Профилей sync',
            'count' => (int)($ordersSyncCounts[$connectionId] ?? 0),
            'supported' => price_tool_connection_supports($connection, 'orders_sync'),
            'href' => 'orders_sync.php?connection_id=' . urlencode((string)$connectionId),
            'tone' => 'service-orders',
        ],
        [
            'key' => 'stocks_tool',
            'label' => 'Stocks Tool',
            'description' => 'Профили остатков по поставщикам и обновление остатков на поддерживаемых маркетплейсах.',
            'count_label' => 'Профилей остатков',
            'count' => (int)($stocksCounts[$connectionId] ?? 0),
            'supported' => price_tool_connection_supports($connection, 'stocks_tool'),
            'href' => 'stocks_tool.php?connection_id=' . urlencode((string)$connectionId),
            'tone' => 'service-stocks',
        ],
        [
            'key' => 'stock_tool',
            'label' => 'stock pois',
            'description' => 'Ручной список товаров для быстрой распродажи: склад Ozon, остаток и временная сниженная цена.',
            'count_label' => 'Товаров',
            'count' => (int)($stockToolCounts[$connectionId] ?? 0),
            'supported' => price_tool_connection_supports($connection, 'stock_tool'),
            'href' => 'stock_tool.php?connection_id=' . urlencode((string)$connectionId),
            'tone' => 'service-stock',
        ],
        [
            'key' => 'fbo_tool',
            'label' => 'FBO Tool',
            'description' => 'Аналитика остатков FBO на складе Ozon и подготовка управления сниженной ценой.',
            'count_label' => 'FBO товаров',
            'count' => (int)($fboCounts[$connectionId] ?? 0),
            'supported' => price_tool_connection_supports($connection, 'fbo_tool'),
            'href' => 'ozon_fbo_tool.php?connection_id=' . urlencode((string)$connectionId),
            'tone' => 'service-fbo',
        ],
    ];
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Подключения маркетплейсов</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f7fb;
      --card: #ffffff;
      --card-soft: #f8fbff;
      --border: #d8e3f0;
      --text: #17233a;
      --muted: #61738d;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
      --ok-bg: #edfdf3;
      --ok-text: #166534;
      --idle-bg: #eef2ff;
      --idle-text: #334155;
      --warn-bg: #fff7ed;
      --warn-text: #9a3412;
      --price-bg: linear-gradient(180deg, #f7fbff 0%, #eef5ff 100%);
      --price-border: #cfe0f7;
      --orders-bg: linear-gradient(180deg, #f7fbfa 0%, #eef8f3 100%);
      --orders-border: #cfe9d9;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f7fbff 0%, var(--bg) 100%);
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
    .topbar, .page, .flash, .error {
      max-width: 1380px;
      margin: 0 auto;
    }
    .topbar { padding: 28px 18px 18px; }
    .page { padding: 0 18px 36px; display: grid; gap: 18px; }
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
    .flash { background: var(--ok-bg); color: var(--ok-text); border-color: #b7ebc6; }
    .error { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
    }
    .tab-nav {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 18px;
    }
    .tab-link, .button-link, button {
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
    .tab-link {
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    .tab-link.active {
      background: #0f172a;
      color: #fff;
      border-color: #0f172a;
    }
    .button-link.secondary, button.secondary {
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    .toolbar, .connection-top, .connection-actions, .service-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: flex-start;
    }
    .stack {
      display: grid;
      gap: 14px;
    }
    .connection-card {
      position: relative;
      overflow: hidden;
      border: 1px solid #c8d7ea;
      border-radius: 26px;
      padding: 22px;
      background:
        radial-gradient(circle at top right, rgba(191, 219, 254, 0.42), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
      box-shadow:
        0 16px 34px rgba(24, 57, 90, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.92);
    }
    .connection-card::before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 6px;
      background: linear-gradient(180deg, #1d4ed8 0%, #60a5fa 100%);
      opacity: .95;
    }
    .connection-card.is-current {
      border-color: #8bb9ff;
      background:
        radial-gradient(circle at top right, rgba(147, 197, 253, 0.55), transparent 38%),
        linear-gradient(180deg, #ffffff 0%, #eef5ff 100%);
      box-shadow:
        0 22px 42px rgba(59, 130, 246, 0.16),
        0 0 0 1px rgba(147, 197, 253, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.95);
    }
    .connection-card.is-collapsed {
      padding-bottom: 18px;
    }
    .connection-card.is-collapsed .connection-top {
      align-items: center;
    }
    .connection-card.is-collapsed .connection-body {
      display: none;
    }
    .connection-card.is-collapsed .connection-title {
      margin-bottom: 0;
    }
    .connection-title {
      font-size: 30px;
      font-weight: 800;
      line-height: 1.1;
      letter-spacing: -0.03em;
      margin-bottom: 4px;
    }
    .connection-toggle {
      min-width: 104px;
    }
    .chip-row {
      margin-top: 10px;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 32px;
      padding: 0 12px;
      border-radius: 999px;
      border: 1px solid #d7e3f0;
      background: rgba(255, 255, 255, 0.88);
      color: var(--text);
      font-size: 13px;
      font-weight: 700;
    }
    .chip.ok {
      background: var(--ok-bg);
      color: var(--ok-text);
      border-color: #b7ebc6;
    }
    .chip.warn {
      background: var(--warn-bg);
      color: var(--warn-text);
      border-color: #f7cda0;
    }
    .service-grid {
      margin-top: 16px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
    }
    .service-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: var(--card-soft);
    }
    .service-card.service-price {
      background: var(--price-bg);
      border-color: var(--price-border);
    }
    .service-card.service-orders {
      background: var(--orders-bg);
      border-color: var(--orders-border);
    }
    .service-card.service-stocks {
      background: linear-gradient(180deg, #f7fcff 0%, #eef7fb 100%);
      border-color: #cfe5ef;
    }
    .service-card.service-stock {
      background: linear-gradient(180deg, #f7fbf8 0%, #eef8f1 100%);
      border-color: #cfe9d8;
    }
    .service-card.service-fbo {
      background: linear-gradient(180deg, #fff8f1 0%, #ffffff 100%);
      border-color: #fed7aa;
    }
    .service-card.disabled {
      opacity: .72;
    }
    .service-title {
      font-size: 18px;
      font-weight: 800;
      margin-bottom: 6px;
    }
    .service-metric {
      margin-top: 12px;
      font-size: 14px;
      color: var(--muted);
    }
    .service-metric strong {
      color: var(--text);
      font-size: 18px;
    }
    .marketplace-sync-panel {
      margin-top: 14px;
      border: 1px solid #cfe0f7;
      border-radius: 18px;
      padding: 14px;
      background: rgba(255, 255, 255, 0.74);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .marketplace-sync-title {
      font-weight: 800;
      margin-bottom: 4px;
    }
    .marketplace-sync-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .marketplace-sync-actions form {
      margin: 0;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      align-items: start;
    }
    label span {
      display: block;
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 14px;
    }
    input[type="text"], input[type="number"], select, textarea {
      width: 100%;
      min-height: 46px;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px 14px;
      font: inherit;
      color: var(--text);
      background: #fff;
    }
    textarea {
      min-height: 120px;
      resize: vertical;
    }
    .checkbox-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 44px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      font-weight: 700;
    }
    @media (max-width: 960px) {
      .service-grid, .form-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'index.php', 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1>Подключения маркетплейсов</h1>
    <div class="muted">Здесь живут аккаунты маркетплейсов. Внутри каждого подключения собраны отдельные службы: `Price Tool`, `Orders Sync`, `Stocks Tool`, `stock pois` и `FBO Tool`.</div>
    <div style="margin-top:14px;">
      <a class="button-link secondary" href="suppliers.php">Поставщики</a>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if (is_array($connectionTest)): ?>
    <div class="flash">
      <strong><?= h((string)($connectionTest['title'] ?? 'Проверка соединения завершена.')) ?></strong>
      <?php foreach ((array)($connectionTest['details'] ?? []) as $detail): ?>
        <div><?= h((string)$detail) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="page">
    <div class="card">
      <div class="toolbar">
        <div>
          <h2>Список подключений</h2>
          <div class="muted">Сначала создаём аккаунт маркетплейса, потом уже внутри него запускаем нужные службы.</div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
          <button type="button" class="secondary" data-collapse-all-connections>Свернуть все</button>
          <button type="button" class="secondary" data-expand-all-connections>Развернуть все</button>
          <a class="button-link secondary" href="marketplace_connections.php?new_connection=1">Добавить подключение</a>
        </div>
      </div>

      <div class="stack" style="margin-top:16px;">
        <?php if (!$allConnections): ?>
          <div class="muted">Пока нет ни одного подключения. Создай первое подключение и затем включай для него нужные службы.</div>
        <?php else: ?>
          <?php foreach ($allConnections as $connection): ?>
            <?php
              $connectionId = (int)($connection['id'] ?? 0);
              $isCurrent = $currentConnectionId > 0 && $connectionId === $currentConnectionId;
              $marketplaceMeta = price_tool_marketplace_meta((string)($connection['marketplace'] ?? 'ozon'));
              $services = connection_service_items($connection, $feedCounts, $ordersSyncCounts, $stocksCounts, $stockToolCounts, $fboCounts);
            ?>
            <div class="connection-card<?= $isCurrent ? ' is-current' : '' ?>" data-connection-card data-connection-id="<?= h((string)$connectionId) ?>">
              <div class="connection-top">
                <div>
                  <div class="connection-title"><?= h((string)($connection['title'] ?? '')) ?></div>
                  <div class="muted">
                    <?= h(price_tool_marketplace_label((string)($connection['marketplace'] ?? 'ozon'))) ?>
                    · <?= h((string)($marketplaceMeta['client_id_label'] ?? 'Client ID')) ?> <?= h((string)($connection['client_id'] ?? '—')) ?>
                  </div>
                  <div class="chip-row">
                    <?php if (($marketplaceMeta['status'] ?? '') !== 'ready'): ?><span class="chip warn"><?= h(($marketplaceMeta['status'] ?? '') === 'planned' ? 'Скоро' : 'Черновик') ?></span><?php endif; ?>
                  </div>
                </div>
                <div class="connection-actions">
                  <button type="button" class="secondary connection-toggle" data-connection-toggle aria-expanded="true">Скрыть</button>
                  <a class="button-link secondary" href="marketplace_connections.php?connection_edit_id=<?= h((string)$connectionId) ?>&connection_id=<?= h((string)$connectionId) ?>">Редактировать</a>
                </div>
              </div>

              <div class="connection-body" data-connection-body>
                <div class="service-grid">
                  <?php foreach ($services as $service): ?>
                    <div class="service-card <?= h((string)$service['tone']) ?><?= empty($service['supported']) ? ' disabled' : '' ?>">
                      <div class="toolbar" style="margin-bottom:8px;">
                        <div>
                          <div class="service-title"><?= h((string)$service['label']) ?></div>
                          <div class="muted"><?= h((string)$service['description']) ?></div>
                        </div>
                        <span class="chip <?= !empty($service['supported']) ? 'ok' : 'warn' ?>"><?= !empty($service['supported']) ? 'Доступно' : 'Пока недоступно' ?></span>
                      </div>
                      <div class="service-metric">
                        <?= h((string)$service['count_label']) ?>: <strong><?= h((string)$service['count']) ?></strong>
                      </div>
                      <div class="service-actions" style="margin-top:14px;">
                        <?php if (!empty($service['supported'])): ?>
                          <a class="button-link secondary" href="<?= h((string)$service['href']) ?>">Открыть службу</a>
                        <?php else: ?>
                          <span class="muted">Подключение сохранено, но рабочий модуль для этого маркетплейса ещё не включён.</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php $marketplaceKey = (string)($connection['marketplace'] ?? ''); ?>
                <?php if ($marketplaceKey === 'ozon' || $marketplaceKey === 'wb'): ?>
                  <div class="marketplace-sync-panel">
                    <div>
                      <div class="marketplace-sync-title">Синхронизация товаров <?= h(price_tool_marketplace_label($marketplaceKey)) ?></div>
                      <div class="muted">
                        Обновляет локальный список товаров для фильтров “отсутствует на маркетплейсе”.
                        <?php if ($marketplaceKey === 'ozon'): ?>
                          Для Ozon подтягиваются активные товары и архив.
                        <?php else: ?>
                          Для WB подтягиваются карточки по vendorCode.
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="marketplace-sync-actions">
                      <?php if ($marketplaceKey === 'ozon'): ?>
                        <form method="post" action="run_op.php">
                          <input type="hidden" name="op_type" value="ozon_sync_products">
                          <input type="hidden" name="connection_id" value="<?= h((string)$connectionId) ?>">
                          <input type="hidden" name="mode" value="full_new">
                          <input type="hidden" name="visibility" value="both">
                          <button type="submit" class="secondary">Обновить товары Ozon</button>
                        </form>
                      <?php else: ?>
                        <form method="post" action="run_op.php">
                          <input type="hidden" name="op_type" value="wb_sync_products">
                          <input type="hidden" name="connection_id" value="<?= h((string)$connectionId) ?>">
                          <button type="submit" class="secondary">Обновить товары WB</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isNewConnectionMode || $connectionEditorId > 0): ?>
      <div class="card">
        <div class="toolbar" style="margin-bottom:12px;">
          <div>
            <h2><?= $connectionEditorId > 0 ? 'Редактирование подключения' : 'Новое подключение' ?></h2>
            <div class="muted">Ключи и базовые параметры аккаунта маркетплейса. Службы подключаются уже после сохранения кабинета.</div>
          </div>
        </div>

        <form method="post" class="stack">
          <input type="hidden" name="id" value="<?= h((string)($connectionEditor['id'] ?? 0)) ?>">
          <?php $editorMeta = price_tool_marketplace_meta((string)($connectionEditor['marketplace'] ?? 'ozon')); ?>
          <div class="form-grid">
            <label>
              <span>Маркетплейс</span>
              <select name="marketplace" data-connection-marketplace>
                <?php foreach (price_tool_marketplace_definitions() as $marketplaceKey => $marketplaceMeta): ?>
                  <option value="<?= h($marketplaceKey) ?>" <?= (string)($connectionEditor['marketplace'] ?? 'ozon') === $marketplaceKey ? 'selected' : '' ?>>
                    <?= h((string)$marketplaceMeta['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="muted" data-marketplace-note style="margin-top:6px; font-size:13px;"><?= h((string)($editorMeta['note'] ?? '')) ?></div>
            </label>
            <label>
              <span>Название подключения</span>
              <input type="text" name="title" value="<?= h((string)($connectionEditor['title'] ?? '')) ?>">
            </label>
            <label>
              <span data-marketplace-client-label><?= h((string)($editorMeta['client_id_label'] ?? 'Client ID')) ?></span>
              <input
                type="text"
                name="client_id"
                id="connection_client_id"
                value="<?= h((string)($connectionEditor['client_id'] ?? '')) ?>"
                data-default-placeholder="Укажи идентификатор кабинета"
                data-auto-placeholder="Определится автоматически по токену"
                <?= (string)($connectionEditor['marketplace'] ?? 'ozon') === 'wb' ? 'disabled' : '' ?>
              >
            </label>
            <label>
              <span data-marketplace-api-label><?= h((string)($editorMeta['api_key_label'] ?? 'API key')) ?></span>
              <input type="text" name="api_key" value="<?= h((string)($connectionEditor['api_key'] ?? '')) ?>">
            </label>
            <label>
              <span>Таймаут, сек</span>
              <input type="number" min="5" max="120" name="timeout_sec" value="<?= h((string)($connectionEditor['timeout_sec'] ?? 30)) ?>">
            </label>
            <label>
              <span>Порядок</span>
              <input type="number" min="1" max="9999" name="sort_order" value="<?= h((string)($connectionEditor['sort_order'] ?? 100)) ?>">
            </label>
            <label style="grid-column:1 / -1;">
              <span>Заметки</span>
              <textarea name="notes"><?= h((string)($connectionEditor['notes'] ?? '')) ?></textarea>
            </label>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit" name="action" value="test_connection" class="secondary">Проверить соединение</button>
            <button type="submit" name="action" value="save_connection">Сохранить подключение</button>
            <a class="button-link secondary" href="marketplace_connections.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Сбросить</a>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <script>
    (() => {
      const storageKey = 'feedtools.marketplaceConnections.collapsed.v1';
      const cards = Array.from(document.querySelectorAll('[data-connection-card]'));
      if (!cards.length) return;

      const readState = () => {
        try {
          const parsed = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
          return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) {
          return {};
        }
      };
      const writeState = (state) => {
        try {
          window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (e) {
          // Local browser settings are optional.
        }
      };
      let state = readState();

      const applyCard = (card, collapsed) => {
        const button = card.querySelector('[data-connection-toggle]');
        card.classList.toggle('is-collapsed', collapsed);
        if (button) {
          button.textContent = collapsed ? 'Показать' : 'Скрыть';
          button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
      };

      const setCollapsed = (card, collapsed) => {
        const id = String(card.dataset.connectionId || '');
        if (id !== '') {
          if (collapsed) {
            state[id] = true;
          } else {
            delete state[id];
          }
          writeState(state);
        }
        applyCard(card, collapsed);
      };

      cards.forEach((card) => {
        const id = String(card.dataset.connectionId || '');
        applyCard(card, !!state[id]);
        const button = card.querySelector('[data-connection-toggle]');
        if (!button) return;
        button.addEventListener('click', () => {
          setCollapsed(card, !card.classList.contains('is-collapsed'));
        });
      });

      const collapseAll = document.querySelector('[data-collapse-all-connections]');
      if (collapseAll) {
        collapseAll.addEventListener('click', () => {
          cards.forEach((card) => setCollapsed(card, true));
        });
      }

      const expandAll = document.querySelector('[data-expand-all-connections]');
      if (expandAll) {
        expandAll.addEventListener('click', () => {
          cards.forEach((card) => setCollapsed(card, false));
        });
      }
    })();

    (() => {
      const definitions = <?= json_encode(price_tool_marketplace_definitions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const select = document.querySelector('[data-connection-marketplace]');
      if (!select) return;
      const clientLabel = document.querySelector('[data-marketplace-client-label]');
      const apiLabel = document.querySelector('[data-marketplace-api-label]');
      const marketplaceNote = document.querySelector('[data-marketplace-note]');
      const clientInput = document.querySelector('#connection_client_id');
      const syncMeta = () => {
        const meta = definitions[select.value] || definitions.ozon || {};
        if (clientLabel) clientLabel.textContent = meta.client_id_label || 'Client ID';
        if (apiLabel) apiLabel.textContent = meta.api_key_label || 'API key';
        if (marketplaceNote) marketplaceNote.textContent = meta.note || '';
        if (clientInput) {
          const autoResolved = select.value === 'wb';
          clientInput.disabled = autoResolved;
          clientInput.placeholder = autoResolved
            ? (clientInput.dataset.autoPlaceholder || '')
            : (clientInput.dataset.defaultPlaceholder || '');
        }
      };
      select.addEventListener('change', syncMeta);
      syncMeta();
    })();
  </script>
</body>
</html>
