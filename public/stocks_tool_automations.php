<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/stocks_tool.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
stocks_tool_module_bootstrap($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$profiles = [];
$profilesById = [];
$automationsByProfile = [];
$automationDraftsByProfile = [];
$activeRuns = [];
$automationNewProfileId = (int)($_GET['automation_new_profile_id'] ?? $_POST['automation_new_profile_id'] ?? 0);

$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=stocks_tool', true, 303);
    exit;
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=stocks_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'stocks_tool');
$cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_automation') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $profile = stocks_tool_profile_get($profileId, $currentConnectionId, $cfg);
            if (!is_array($profile)) {
                throw new RuntimeException('Профиль остатков для автоматизации не найден.');
            }
            $savedAutomationId = stocks_tool_automation_save($_POST, $profile, $actor, $cfg);
            header('Location: stocks_tool_automations.php?connection_id=' . urlencode((string)$currentConnectionId) . '&automation_saved=1&automation_id=' . urlencode((string)$savedAutomationId), true, 303);
            exit;
        }
        if ($action === 'delete_automation') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $automationId = (int)($_POST['automation_id'] ?? 0);
            stocks_tool_automation_delete($automationId, $profileId, $cfg);
            header('Location: stocks_tool_automations.php?connection_id=' . urlencode((string)$currentConnectionId) . '&automation_deleted=1', true, 303);
            exit;
        }
    }

    $profiles = $currentMarketplaceReady ? stocks_tool_profile_list($currentConnectionId, $cfg) : [];
    foreach ($profiles as $profile) {
        if (is_array($profile) && !empty($profile['id'])) {
            $profilesById[(int)$profile['id']] = $profile;
        }
    }
    $activeRuns = $currentMarketplaceReady ? stocks_tool_run_active_map(array_keys($profilesById), $cfg) : [];
    $automationsByProfile = $currentMarketplaceReady ? stocks_tool_automation_map(array_keys($profilesById), $profilesById, $cfg) : [];

    if ($automationNewProfileId > 0 && isset($profilesById[$automationNewProfileId])) {
        $automationsByProfile[$automationNewProfileId][] = stocks_tool_automation_default($automationNewProfileId, $profilesById[$automationNewProfileId]);
    }

    if (isset($_GET['automation_saved']) && $_GET['automation_saved'] === '1') {
        $flash = 'Настройка автоматизации сохранена.';
    } elseif (isset($_GET['automation_deleted']) && $_GET['automation_deleted'] === '1') {
        $flash = 'Настройка автоматизации удалена.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $profiles = $currentMarketplaceReady ? stocks_tool_profile_list($currentConnectionId, $cfg) : [];
    foreach ($profiles as $profile) {
        if (is_array($profile) && !empty($profile['id'])) {
            $profilesById[(int)$profile['id']] = $profile;
        }
    }
    $activeRuns = $currentMarketplaceReady ? stocks_tool_run_active_map(array_keys($profilesById), $cfg) : [];
    $automationsByProfile = $currentMarketplaceReady ? stocks_tool_automation_map(array_keys($profilesById), $profilesById, $cfg) : [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_automation') {
        $profileId = (int)($_POST['profile_id'] ?? 0);
        if (isset($profilesById[$profileId])) {
            $automationDraftsByProfile[$profileId][] = stocks_tool_automation_input($_POST, $profilesById[$profileId]);
        }
    }
}

foreach ($automationDraftsByProfile as $profileId => $drafts) {
    foreach ($drafts as $draft) {
        $automationsByProfile[(int)$profileId][] = $draft;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stocks_tool_automation_last_run_label(array $automation, ?array $activeRun = null): string
{
    if (is_array($activeRun) && in_array((string)($activeRun['status'] ?? ''), ['queued', 'running'], true)) {
        return 'Сейчас выполняется · run #' . (int)($activeRun['id'] ?? 0);
    }
    $lastRunAt = trim((string)($automation['last_run_at'] ?? ''));
    if ($lastRunAt === '') {
        return 'Пока не запускалась';
    }
    $runId = (int)($automation['last_run_run_id'] ?? 0);
    return $lastRunAt . ($runId > 0 ? ' · run #' . $runId : '');
}

function stocks_tool_automation_next_run_label(array $automation, ?array $activeRun = null): string
{
    if (empty($automation['enabled'])) {
        return 'Выключена';
    }
    if (is_array($activeRun) && in_array((string)($activeRun['status'] ?? ''), ['queued', 'running'], true)) {
        return 'Повторный запуск ждёт завершения текущей операции';
    }
    $nowMsk = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $slot = stocks_tool_automation_slot_info($automation, $nowMsk);
    $lastSlotKey = trim((string)($automation['last_run_slot_key'] ?? ''));
    if ($lastSlotKey === (string)$slot['slot_key']) {
        return 'Следующий запуск после ' . $slot['slot_end']->format('H:i') . ' МСК';
    }
    return 'Ожидает запуск в текущем интервале до ' . $slot['slot_end']->format('H:i') . ' МСК';
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stocks Tool — Автоматизация</title>
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
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
    }
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
    .button-link.danger, button.danger {
      background: #fff5f5;
      color: #b42318;
      border-color: #fecaca;
    }
    .flash, .error {
      max-width: 1420px;
      margin: 0 auto 16px;
      padding: 14px 18px;
      border-radius: 16px;
      border: 1px solid var(--border);
    }
    .flash { background: #edfdf3; color: #166534; border-color: #b7ebc6; }
    .error { background: #fff1f2; color: #b42318; border-color: #fecdd3; }
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
    .automation-profile {
      display: grid;
      gap: 16px;
      border: 1px solid #d7e3f2;
      border-radius: 24px;
      padding: 18px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .automation-profile-head {
      display: flex;
      gap: 12px;
      align-items: start;
      justify-content: space-between;
      flex-wrap: wrap;
    }
    .chip-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 34px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      font-weight: 700;
    }
    .automation-list {
      display: grid;
      gap: 12px;
    }
    .automation-row {
      display: grid;
      gap: 14px;
      padding: 16px;
      border: 1px solid #d7e3f2;
      border-radius: 20px;
      background: #fff;
    }
    .automation-row.is-saving {
      opacity: .72;
      pointer-events: none;
    }
    .automation-row-head {
      display: flex;
      gap: 12px;
      justify-content: space-between;
      align-items: start;
      flex-wrap: wrap;
    }
    .automation-row-meta {
      color: var(--muted);
      font-size: 15px;
      font-weight: 600;
    }
    .automation-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: minmax(220px, 1.5fr) minmax(220px, 1.2fr) minmax(160px, .7fr) auto;
      align-items: end;
    }
    .automation-grid label {
      display: grid;
      gap: 6px;
      color: var(--muted);
      font-weight: 700;
    }
    .automation-grid select,
    .automation-grid input[type="time"] {
      min-height: 46px;
      border-radius: 14px;
      border: 1px solid #cfe0f7;
      padding: 0 14px;
      font: inherit;
      color: var(--text);
      background: #fff;
    }
    .automation-toggle {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      min-height: 48px;
      padding: 0 16px;
      border-radius: 999px;
      border: 1px solid #b7ebc6;
      background: #edfdf3;
      color: #166534;
      font-weight: 800;
      cursor: pointer;
    }
    .automation-toggle.is-off {
      border-color: #dbe5f3;
      background: #f8fbff;
      color: var(--muted);
    }
    .automation-toggle input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .automation-toggle-track {
      position: relative;
      width: 48px;
      height: 28px;
      border-radius: 999px;
      background: #22c55e;
      flex: 0 0 auto;
    }
    .automation-toggle-track::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 23px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
    }
    .automation-toggle.is-off .automation-toggle-track {
      background: #cbd5e1;
    }
    .automation-toggle.is-off .automation-toggle-track::after {
      left: 3px;
    }
    .automation-actions {
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }
    .automation-icon-button {
      width: 46px;
      min-width: 46px;
      min-height: 46px;
      padding: 0;
      border-radius: 14px;
    }
    .automation-icon-button svg {
      width: 20px;
      height: 20px;
    }
    @media (max-width: 1080px) {
      .automation-grid {
        grid-template-columns: 1fr 1fr;
      }
    }
    @media (max-width: 720px) {
      .current-connection-title { font-size: 34px; }
      .tab-link {
        min-height: 56px;
        padding: 0 20px;
        font-size: 16px;
      }
      .automation-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'stocks_tool.php' . ($currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : ''), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">Stocks Tool</h1>
    <div class="current-connection-card">
      <div>
        <div class="current-connection-kicker">Текущий кабинет</div>
        <div class="current-connection-title"><?= h((string)($currentConnection['title'] ?? '—')) ?></div>
        <div class="current-connection-meta">
          <?= h($currentMarketplaceLabel) ?>
          <?php if (!empty($currentConnection['client_id'])): ?>
            · client_id <?= h((string)$currentConnection['client_id']) ?>
          <?php endif; ?>
        </div>
      </div>
      <a class="button-link secondary" href="marketplace_connections.php?connection_id=<?= h((string)$currentConnectionId) ?>">Выбрать кабинет</a>
    </div>
    <div class="tab-stack">
      <div class="tab-nav">
        <a class="tab-link" href="ozon_price_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Price Tool</a>
        <a class="tab-link" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Orders Sync</a>
        <a class="tab-link active" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Stocks Tool</a>
        <?php if ($currentMarketplace === 'ozon'): ?><a class="tab-link" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a><?php endif; ?>
        <?php if (price_tool_connection_supports($currentConnection, 'fbo_tool')): ?><a class="tab-link" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a><?php endif; ?>
      </div>
      <div class="tab-nav is-subtabs">
        <a class="tab-link" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Профили остатков</a>
        <a class="tab-link active" href="stocks_tool_automations.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Автоматизация</a>
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
      <div class="card">
        <h2><?= h($currentMarketplaceLabel) ?>: автоматизация Stocks Tool пока не поддержана</h2>
        <div class="muted">Автоматизация доступна для Ozon и WB. Если маркетплейс неактивен, открой другое подключение.</div>
      </div>
    <?php elseif (!$profilesById): ?>
      <div class="card">
        <h2>Автоматизация Stocks Tool</h2>
        <div class="muted" style="margin-bottom:14px;">Сначала создай хотя бы один профиль остатков на вкладке `Профили остатков`, а затем уже добавляй расписания запусков.</div>
        <a class="button-link secondary" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&new_stock_profile=1">Создать профиль остатков</a>
      </div>
    <?php else: ?>
      <div class="card">
        <h2>Автоматизация Stocks Tool</h2>
        <div class="muted">Здесь можно настроить отдельные расписания обновления остатков для каждого профиля. Автоматизация запускает тот же runtime, что и ручная кнопка `Обновить остатки`, без второй отдельной логики.</div>
      </div>

      <?php foreach ($profilesById as $profileId => $profile): ?>
        <?php $profileAutomations = array_values(array_filter($automationsByProfile[$profileId] ?? [], static fn($row): bool => is_array($row))); ?>
        <?php $activeRun = is_array($activeRuns[$profileId] ?? null) ? $activeRuns[$profileId] : null; ?>
        <div class="card automation-profile">
          <div class="automation-profile-head">
            <div>
              <h2 data-ft-i18n="off"><?= h((string)($profile['name'] ?? '')) ?></h2>
              <div class="muted">
                <?= h((string)($profile['ozon_warehouse_name'] ?? ('Склад ' . $currentMarketplaceLabel . ' не выбран'))) ?>
                <?php if (!empty($profile['supplier_codes'])): ?>
                  · коды поставщиков: <code><?= h(implode(', ', array_map('strval', (array)$profile['supplier_codes']))) ?></code>
                <?php endif; ?>
              </div>
              <div class="chip-row">
                <span class="chip"><?= h((string)($profile['feed_count'] ?? 0)) ?> источников</span>
                <?php if ($activeRun): ?>
                  <span class="chip">Сейчас выполняется run #<?= h((string)($activeRun['id'] ?? 0)) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <a class="button-link secondary" href="stocks_tool_automations.php?connection_id=<?= h((string)$currentConnectionId) ?>&automation_new_profile_id=<?= h((string)$profileId) ?>">Добавить автоматизацию</a>
          </div>

          <?php if (!$profileAutomations): ?>
            <div class="muted">Для этого профиля пока нет ни одного расписания. Добавь первую автоматизацию и включи её, когда будешь готов.</div>
          <?php else: ?>
            <div class="automation-list">
              <?php foreach ($profileAutomations as $automation): ?>
                <?php $automationId = (int)($automation['id'] ?? 0); ?>
                <form method="post" class="automation-row">
                  <input type="hidden" name="action" value="save_automation">
                  <input type="hidden" name="profile_id" value="<?= h((string)$profileId) ?>">
                  <input type="hidden" name="automation_id" value="<?= h((string)$automationId) ?>">

                  <div class="automation-row-head">
                    <div>
                      <strong><?= h(stocks_tool_operation_label((string)($automation['operation_key'] ?? 'sync'))) ?></strong>
                      <div class="automation-row-meta">Последний запуск: <?= h(stocks_tool_automation_last_run_label($automation, $activeRun)) ?></div>
                      <div class="automation-row-meta"><?= h(stocks_tool_automation_next_run_label($automation, $activeRun)) ?></div>
                    </div>
                    <label class="automation-toggle <?= !empty($automation['enabled']) ? '' : 'is-off' ?>">
                      <input type="checkbox" name="enabled" value="1" data-autosubmit="1" <?= !empty($automation['enabled']) ? 'checked' : '' ?>>
                      <span class="automation-toggle-track" aria-hidden="true"></span>
                      <span><?= !empty($automation['enabled']) ? 'Включена' : 'Выключена' ?></span>
                    </label>
                  </div>

                  <div class="automation-grid">
                    <label>
                      <span>Операция</span>
                      <select name="operation_key">
                        <?php foreach (stocks_tool_operation_options() as $operationKey => $meta): ?>
                          <option value="<?= h($operationKey) ?>" <?= ((string)($automation['operation_key'] ?? '') === $operationKey) ? 'selected' : '' ?>>
                            <?= h((string)($meta['label'] ?? $operationKey)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <span>Периодичность запусков</span>
                      <select name="frequency_key">
                        <?php foreach (stocks_tool_automation_frequency_options() as $frequencyKey => $meta): ?>
                          <option value="<?= h($frequencyKey) ?>" <?= ((string)($automation['frequency_key'] ?? '') === $frequencyKey) ? 'selected' : '' ?>>
                            <?= h((string)($meta['label'] ?? $frequencyKey)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <span>Время начала запусков (МСК)</span>
                      <input type="time" name="run_time_msk" step="300" value="<?= h(stocks_tool_automation_run_time_value($automation)) ?>">
                    </label>
                    <div class="automation-actions">
                      <button type="submit" class="secondary automation-icon-button" title="Сохранить автоматизацию" aria-label="Сохранить автоматизацию">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/>
                          <path d="M17 21v-8H7v8"/>
                          <path d="M7 3v5h8"/>
                        </svg>
                      </button>
                      <?php if ($automationId > 0): ?>
                        <button type="submit" class="danger automation-icon-button" onclick="if (!confirm('Удалить эту настройку автоматизации?')) return false; this.form.elements.action.value='delete_automation'; return true;" title="Удалить автоматизацию" aria-label="Удалить автоматизацию">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18"/>
                            <path d="M8 6V4h8v2"/>
                            <path d="M19 6l-1 14H6L5 6"/>
                            <path d="M10 11v6"/>
                            <path d="M14 11v6"/>
                          </svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <script>
    document.querySelectorAll('input[data-autosubmit="1"]').forEach(function(input) {
      input.addEventListener('change', function() {
        var form = input.closest('form');
        if (!form) return;
        var action = form.querySelector('input[name="action"]');
        if (action) action.value = 'save_automation';
        form.classList.add('is-saving');
        if (form.requestSubmit) {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });
  </script>
</body>
</html>
