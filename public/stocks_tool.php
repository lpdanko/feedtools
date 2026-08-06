<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/stocks_tool.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
ozon_price_feeds_table_ensure($cfg);
stocks_tool_profiles_table_ensure($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$profiles = [];
$activeRuns = [];
$recentRunsMap = [];
$feedOptions = [];
$feedOfferCounts = [];
$zeroRuleOptions = [];
$warehouseOptions = [];
$testArticle = '';
$testProfileId = 0;
$testResult = null;
$profileEditorId = (int)($_GET['stock_profile_edit_id'] ?? $_POST['stock_profile_edit_id'] ?? 0);
$isNewProfileMode = (isset($_GET['new_stock_profile']) && $_GET['new_stock_profile'] === '1')
    || (isset($_POST['new_stock_profile']) && $_POST['new_stock_profile'] === '1');
$preservePostedEditor = false;

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

$profileEditor = stocks_tool_profile_default($currentConnectionId);
if ($profileEditorId > 0) {
    $profileEditor = stocks_tool_profile_get($profileEditorId, $currentConnectionId, $cfg) ?? $profileEditor;
}

$refreshEditorResources = static function () use (&$feedOptions, &$feedOfferCounts, &$zeroRuleOptions, &$warehouseOptions, $currentMarketplaceReady, $currentConnectionId, $currentConnection, $cfg): void {
    if (!$currentMarketplaceReady || $currentConnectionId <= 0) {
        $feedOptions = [];
        $feedOfferCounts = [];
        $zeroRuleOptions = [];
        $warehouseOptions = [];
        return;
    }
    $feedOptions = stocks_tool_profile_feed_options($currentConnectionId, $cfg);
    $feedOfferCounts = [];
    foreach ($feedOptions as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        if ($feedId <= 0) {
            continue;
        }
        $feedOfferCounts[$feedId] = suppliers_offer_count_cached($feed, 3600, false);
        $zeroRuleOptions[$feedId] = stocks_tool_zero_rule_options_for_feed($feed, $cfg, false);
    }
    $warehouseOptions = stocks_tool_warehouse_options($cfg, $currentConnection);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_stock_profile') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $existingForceZeroFeedIds = [];
            $postedProfileId = (int)($_POST['id'] ?? 0);
            if ($postedProfileId > 0) {
                $existingProfile = stocks_tool_profile_get($postedProfileId, $currentConnectionId, $cfg);
                if (is_array($existingProfile)) {
                    $existingForceZeroFeedIds = stocks_tool_profile_force_zero_feed_ids($existingProfile);
                }
            }
            $savedProfileId = stocks_tool_profile_save($_POST, $actor, $currentConnectionId, $cfg);
            $forceZeroRunId = 0;
            $savedProfile = stocks_tool_profile_get($savedProfileId, $currentConnectionId, $cfg);
            if (is_array($savedProfile)) {
                $savedForceZeroFeedIds = stocks_tool_profile_force_zero_feed_ids($savedProfile);
                $activatedForceZeroFeedIds = array_diff($savedForceZeroFeedIds, $existingForceZeroFeedIds);
                if ($activatedForceZeroFeedIds) {
                    $forceZeroRunId = stocks_tool_manual_run_start($savedProfileId, $actor, $cfg);
                }
            }
            $redirect = 'stocks_tool.php?connection_id=' . urlencode((string)$currentConnectionId)
                . '&profile_id=' . urlencode((string)$savedProfileId)
                . '&stock_profile_saved=1';
            if ($forceZeroRunId > 0) {
                $redirect .= '&stock_force_zero_run_started=1&run_id=' . urlencode((string)$forceZeroRunId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }
        if ($action === 'delete_stock_profile') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $deleteProfileId = (int)($_POST['profile_id'] ?? 0);
            stocks_tool_profile_delete($deleteProfileId, $currentConnectionId, $cfg);
            header('Location: stocks_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&stock_profile_deleted=1', true, 303);
            exit;
        }
        if ($action === 'run_stock_profile') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $runProfileId = (int)($_POST['profile_id'] ?? 0);
            $runId = stocks_tool_manual_run_start($runProfileId, $actor, $cfg);
            header('Location: stocks_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&run_id=' . urlencode((string)$runId) . '&stock_run_started=1', true, 303);
            exit;
        }
        if ($action === 'zero_supplier_stock') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $runProfileId = (int)($_POST['profile_id'] ?? 0);
            $zeroFeedId = (int)($_POST['zero_feed_id'] ?? 0);
            $runId = stocks_tool_zero_supplier_run_start($runProfileId, $zeroFeedId, $actor, $cfg);
            header('Location: stocks_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&stock_profile_edit_id=' . urlencode((string)$runProfileId) . '&stock_zero_started=1&run_id=' . urlencode((string)$runId), true, 303);
            exit;
        }
        if ($action === 'test_stock_item') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $testProfileId = (int)($_POST['profile_id'] ?? 0);
            $testArticle = trim((string)($_POST['test_article'] ?? ''));
            $testResult = stocks_tool_test_offer_by_article($testProfileId, $testArticle, $cfg);
        }
        if ($action === 'push_test_stock_item') {
            if (!$currentMarketplaceReady) {
                throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' Stocks Tool пока не поддержан.');
            }
            $testProfileId = (int)($_POST['profile_id'] ?? 0);
            $testArticle = trim((string)($_POST['test_article'] ?? ''));
            $testResult = stocks_tool_test_offer_push_by_article($testProfileId, $testArticle, $actor, $cfg);
            $flash = 'Тестовая отправка остатка по одному товару выполнена.';
        }
    }

    $profiles = $currentMarketplaceReady ? stocks_tool_profile_list($currentConnectionId, $cfg) : [];
    $profileIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), array_filter($profiles, 'is_array'));
    $activeRuns = $currentMarketplaceReady ? stocks_tool_run_active_map($profileIds, $cfg) : [];
    $recentRunsMap = $currentMarketplaceReady ? stocks_tool_run_recent_map($profileIds, 6, $cfg) : [];
    if (!$profiles) {
        $isNewProfileMode = true;
    }
    if ($profileEditorId > 0 && !$preservePostedEditor) {
        $profileEditor = stocks_tool_profile_get($profileEditorId, $currentConnectionId, $cfg) ?? $profileEditor;
    }

    if ($isNewProfileMode || $profileEditorId > 0) {
        $refreshEditorResources();
    }

    if (isset($_GET['stock_profile_saved']) && $_GET['stock_profile_saved'] === '1') {
        $flash = isset($_GET['stock_force_zero_run_started']) && $_GET['stock_force_zero_run_started'] === '1'
            ? 'Профиль остатков сохранён. Обнуление поставщика поставлено в очередь.'
            : 'Профиль остатков сохранён.';
    } elseif (isset($_GET['stock_profile_deleted']) && $_GET['stock_profile_deleted'] === '1') {
        $flash = 'Профиль остатков удалён.';
    } elseif (isset($_GET['stock_run_started']) && $_GET['stock_run_started'] === '1') {
        $flash = 'Запуск обновления остатков поставлен в очередь.';
    } elseif (isset($_GET['stock_zero_started']) && $_GET['stock_zero_started'] === '1') {
        $flash = 'Обнуление выбранного поставщика поставлено в очередь.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $profiles = $currentMarketplaceReady ? stocks_tool_profile_list($currentConnectionId, $cfg) : [];
    $profileIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), array_filter($profiles, 'is_array'));
    $activeRuns = $currentMarketplaceReady ? stocks_tool_run_active_map($profileIds, $cfg) : [];
    $recentRunsMap = $currentMarketplaceReady ? stocks_tool_run_recent_map($profileIds, 6, $cfg) : [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $preservePostedEditor = true;
        $profileEditor = stocks_tool_profile_normalize_input($_POST, $currentConnectionId);
        $refreshEditorResources();
    } elseif ($profileEditorId > 0) {
        $profileEditor = stocks_tool_profile_get($profileEditorId, $currentConnectionId, $cfg) ?? $profileEditor;
        $refreshEditorResources();
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stocks_tool_run_status_label(string $status): string
{
    $status = trim($status);
    return match ($status) {
        'queued' => 'В очереди',
        'running' => 'Выполняется',
        'success' => 'Успешно',
        'partial' => 'Частично',
        'error' => 'Ошибка',
        default => $status !== '' ? $status : '—',
    };
}

function stocks_tool_run_status_class(string $status): string
{
    $status = trim($status);
    return match ($status) {
        'success' => 'ok',
        'partial' => 'warn',
        'error' => 'err',
        'queued', 'running' => 'run',
        default => '',
    };
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stocks Tool</title>
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
      --ok-bg: #edfdf3;
      --ok-text: #166534;
      --warn-bg: #fffaf3;
      --warn-text: #9a3412;
      --stocks-soft: linear-gradient(180deg, #f7fcff 0%, #eef7fb 100%);
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
      min-width: 0;
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
    .flash {
      background: var(--ok-bg);
      color: var(--ok-text);
      border-color: #b7ebc6;
    }
    .error {
      background: #fff1f2;
      color: #b42318;
      border-color: #fecdd3;
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
    .section-head {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .profiles-list {
      display: grid;
      gap: 14px;
    }
    .profile-card {
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 18px;
      background: var(--stocks-soft);
    }
    .profile-top {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: flex-start;
      flex-wrap: wrap;
    }
    .profile-title {
      font-size: 22px;
      font-weight: 900;
      line-height: 1.08;
      margin-bottom: 4px;
    }
    .profile-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .run-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .test-item-card {
      margin-top: 16px;
      padding: 18px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #fcfdff 0%, #f7fbff 100%);
      display: grid;
      gap: 14px;
    }
    .test-item-form {
      display: grid;
      grid-template-columns: minmax(280px, 1.4fr) auto auto;
      gap: 12px;
      align-items: end;
    }
    .test-item-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
    }
    .test-item-box {
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: #fff;
    }
    .test-item-kicker {
      margin-bottom: 4px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .03em;
      text-transform: uppercase;
    }
    .test-item-value {
      font-size: 24px;
      line-height: 1.1;
      font-weight: 800;
      color: var(--text);
    }
    .test-item-log {
      margin: 0;
      padding: 14px 16px;
      border-radius: 16px;
      background: #0f172a;
      color: #e5eef9;
      overflow: auto;
      font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      white-space: pre-wrap;
    }
    .run-panel {
      margin-top: 14px;
      border: 1px solid #d8e3ef;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.8);
      padding: 14px;
      display: grid;
      gap: 12px;
    }
    .run-panel.current {
      border-color: #c9dcff;
      background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
    }
    .run-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      flex-wrap: wrap;
    }
    .run-metrics {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .run-metric {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 10px;
      border-radius: 999px;
      border: 1px solid #d7e3f0;
      background: #fff;
      font-size: 13px;
      font-weight: 700;
    }
    .run-log {
      margin: 0;
      padding: 12px 14px;
      border-radius: 14px;
      background: #0f172a;
      color: #f8fafc;
      font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace;
      white-space: pre-wrap;
      max-height: 320px;
      overflow: auto;
    }
    .run-list {
      margin-top: 14px;
      display: grid;
      gap: 10px;
    }
    .run-item {
      border: 1px solid #d8e3ef;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.82);
      padding: 12px 14px;
      display: grid;
      gap: 8px;
    }
    .run-error {
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid #fecdd3;
      background: #fff1f2;
      color: #b42318;
      font-size: 14px;
      font-weight: 600;
    }
    .run-details {
      display: grid;
      gap: 8px;
    }
    .run-details > summary {
      list-style: none;
      cursor: pointer;
      color: var(--muted);
      font-weight: 700;
      user-select: none;
    }
    .run-details > summary::-webkit-details-marker {
      display: none;
    }
    .run-details > summary::before {
      content: '▸';
      display: inline-block;
      margin-right: 8px;
      color: #52637d;
    }
    .run-details[open] > summary::before {
      content: '▾';
    }
    .status-chip {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 10px;
      border-radius: 999px;
      border: 1px solid #d7e3f0;
      background: #fff;
      font-size: 13px;
      font-weight: 800;
    }
    .status-chip.run {
      background: #eef5ff;
      color: #2458b8;
      border-color: #c9dcff;
    }
    .status-chip.ok {
      background: #edfdf3;
      color: #166534;
      border-color: #b7ebc6;
    }
    .status-chip.warn {
      background: #fff7ed;
      color: #9a3412;
      border-color: #fed7aa;
    }
    .status-chip.err {
      background: #fff1f2;
      color: #b42318;
      border-color: #fecdd3;
    }
    .chip-row {
      margin-top: 12px;
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
      background: #edfdf3;
      color: #166534;
      border-color: #b7ebc6;
    }
    .chip.warn {
      background: #fff7ed;
      color: #9a3412;
      border-color: #fed7aa;
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
    .required-mark {
      color: #b42318;
      font-weight: 800;
      margin-left: 4px;
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
    .stack { display: grid; gap: 14px; }
    .feed-select-grid {
      display: grid;
      gap: 14px;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      align-items: stretch;
    }
    .feed-select {
      display: grid;
      gap: 14px;
      grid-template-rows: auto auto minmax(72px, 1fr) auto;
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: #fbfdff;
      height: 100%;
      align-content: start;
    }
    .feed-select.force-zero {
      border-color: #fb923c;
      background: #fff7ed;
    }
    .feed-select.disabled {
      opacity: .68;
      background: #f8fafc;
    }
    .feed-select input[type="checkbox"] {
      width: 18px;
      height: 18px;
      flex: 0 0 auto;
    }
    .feed-select-head {
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }
    .feed-select-title {
      font-weight: 800;
      margin-bottom: 6px;
    }
    .feed-settings-grid {
      display: grid;
      gap: 12px 14px;
      grid-template-columns: minmax(0, 1.15fr) repeat(2, minmax(0, 1fr));
      align-items: start;
    }
    .feed-settings-grid label {
      display: grid;
      gap: 8px;
      align-content: start;
      grid-template-rows: minmax(68px, auto) auto;
    }
    .feed-settings-grid label span {
      display: flex;
      align-items: flex-end;
      margin-bottom: 0;
      min-height: 68px;
      line-height: 1.35;
    }
    .feed-select-actions {
      display: flex;
      justify-content: flex-end;
      align-items: flex-end;
      min-height: 48px;
    }
    .feed-select-actions.is-empty {
      visibility: hidden;
      pointer-events: none;
    }
    .feed-inline-note {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
      min-height: 58px;
    }
    .supplier-zero-toggle {
      position: relative;
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 10px;
      align-items: center;
      border: 1px solid #fed7aa;
      border-radius: 14px;
      padding: 10px 12px;
      background: #fff;
      cursor: pointer;
    }
    .supplier-zero-toggle input[type="checkbox"] {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .supplier-zero-switch {
      position: relative;
      width: 44px;
      height: 26px;
      border-radius: 999px;
      background: #d8e3f0;
      border: 1px solid #c7d4e4;
      transition: background .15s ease, border-color .15s ease;
    }
    .supplier-zero-switch::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: #fff;
      box-shadow: 0 2px 6px rgba(15, 23, 42, .22);
      transition: transform .15s ease;
    }
    .supplier-zero-toggle input[type="checkbox"]:checked + .supplier-zero-switch {
      background: #ea580c;
      border-color: #c2410c;
    }
    .supplier-zero-toggle input[type="checkbox"]:checked + .supplier-zero-switch::after {
      transform: translateX(18px);
    }
    .supplier-zero-toggle-copy {
      min-width: 0;
      display: grid;
      gap: 3px;
      line-height: 1.3;
    }
    .supplier-zero-toggle-copy strong {
      font-size: 14px;
      color: var(--text);
    }
    .supplier-zero-toggle-copy span {
      color: var(--muted);
      font-size: 12px;
      overflow-wrap: anywhere;
    }
    .zero-rules-card {
      border: 1px solid #fed7aa;
      border-radius: 18px;
      padding: 16px;
      background: linear-gradient(180deg, #fffaf3 0%, #ffffff 100%);
      display: grid;
      gap: 14px;
    }
    .zero-rules-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
    }
    .zero-rule-field {
      display: grid;
      gap: 8px;
      align-content: start;
    }
    .zero-rule-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      color: var(--muted);
      font-size: 14px;
    }
    .zero-rule-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 24px;
      padding: 0 9px;
      border-radius: 999px;
      background: #eef5ff;
      border: 1px solid #c9dcff;
      color: #2458b8;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
    }
    .zero-rule-toolbar {
      display: grid;
      gap: 8px;
      grid-template-columns: minmax(0, 1fr) auto auto;
      align-items: center;
    }
    .zero-rule-toolbar input[type="search"] {
      min-height: 38px;
      padding: 7px 10px;
      border: 1px solid var(--border);
      border-radius: 12px;
      font-size: 14px;
      background: #fff;
      color: var(--text);
    }
    .zero-rule-mini-button {
      min-height: 38px;
      padding: 0 10px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 800;
    }
    .zero-rule-list {
      min-height: 150px;
      max-height: 220px;
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      padding: 6px;
    }
    .zero-rule-option {
      display: grid;
      grid-template-columns: 18px minmax(0, 1fr);
      gap: 9px;
      align-items: start;
      padding: 8px 9px;
      border-radius: 10px;
      color: var(--text);
      font-weight: 650;
      cursor: pointer;
      line-height: 1.25;
    }
    .zero-rule-option:hover {
      background: #f3f7fd;
    }
    .zero-rule-option.is-selected {
      background: #eaf2ff;
      outline: 1px solid #9ec2ff;
    }
    .zero-rule-option input {
      width: 18px;
      height: 18px;
      margin: 1px 0 0;
    }
    .zero-rule-option-main {
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .zero-rule-option-count {
      color: var(--muted);
      font-weight: 800;
      white-space: nowrap;
    }
    .zero-rule-empty {
      padding: 12px;
      color: var(--muted);
      font-size: 13px;
    }
    .zero-rule-note {
      color: #9a3412;
      font-size: 13px;
      font-weight: 700;
    }
    .placeholder-card {
      background: linear-gradient(180deg, #fffaf3 0%, #ffffff 100%);
    }
    code {
      padding: 1px 6px;
      border-radius: 999px;
      background: #f2f6fb;
      border: 1px solid #dce6f3;
      font-size: 0.95em;
    }
    @media (max-width: 960px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      .test-item-form,
      .test-item-grid {
        grid-template-columns: 1fr;
      }
      .run-head {
        flex-direction: column;
      }
      .feed-settings-grid {
        grid-template-columns: 1fr;
      }
      .feed-settings-grid label {
        grid-template-rows: auto auto;
      }
      .feed-settings-grid label span {
        min-height: 0;
      }
      .feed-inline-note {
        min-height: 0;
      }
      .tab-link {
        min-height: 56px;
        padding: 0 20px;
        font-size: 16px;
      }
      .current-connection-title {
        font-size: 34px;
      }
    }
  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'marketplace_connections.php' . ($currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : ''), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">Stocks Tool</h1>
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
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="button-link secondary" href="suppliers.php">Поставщики</a>
        <a class="button-link secondary" href="marketplace_connections.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Выбрать кабинет</a>
      </div>
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
        <a class="tab-link active" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Профили остатков</a>
        <a class="tab-link" href="stocks_tool_automations.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Автоматизация</a>
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
      <div class="card placeholder-card">
        <h2><?= h($currentMarketplaceLabel) ?>: Stocks Tool пока не включён для этого подключения</h2>
        <div class="muted">Служба обновления остатков сейчас поддерживает Ozon, WB и Яндекс Маркет. Если маркетплейс неактивен, открой другое подключение или проверь тип кабинета.</div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="section-head">
          <div>
            <h2>Профили остатков</h2>
            <div class="muted">Профиль остатков привязывается к этому кабинету <?= h($currentMarketplaceLabel) ?>, выбирает источники поставщиков и один склад, на который дальше будут отправляться остатки.</div>
          </div>
          <a class="button-link secondary" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&new_stock_profile=1">Добавить профиль</a>
        </div>

        <div class="profiles-list">
          <?php if (!$profiles): ?>
            <div class="profile-card">
              <div class="muted">Пока нет ни одного профиля остатков. Создай первый профиль, выбери источники поставщиков и склад <?= h($currentMarketplaceLabel) ?>, чтобы подготовить основу для обновления остатков.</div>
            </div>
          <?php endif; ?>

          <?php foreach ($profiles as $profile): ?>
            <?php $profileId = (int)($profile['id'] ?? 0); ?>
            <?php $activeRun = is_array($activeRuns[$profileId] ?? null) ? $activeRuns[$profileId] : null; ?>
            <?php $recentRuns = is_array($recentRunsMap[$profileId] ?? null) ? $recentRunsMap[$profileId] : []; ?>
            <div class="profile-card">
              <div class="profile-top">
                <div>
                  <div class="profile-title"><?= h((string)($profile['name'] ?? '')) ?></div>
                  <div class="muted">
                    <?= h((string)($profile['ozon_warehouse_name'] ?? ('Склад ' . $currentMarketplaceLabel . ' не выбран'))) ?>
                    <?php if (trim((string)($profile['ozon_warehouse_id'] ?? '')) !== ''): ?>
                      · ID <?= h((string)$profile['ozon_warehouse_id']) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="profile-actions">
                  <a class="button-link secondary" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&stock_profile_edit_id=<?= h((string)$profileId) ?>">Редактировать</a>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Удалить этот профиль остатков и его служебное состояние?');">
                    <input type="hidden" name="action" value="delete_stock_profile">
                    <input type="hidden" name="profile_id" value="<?= h((string)$profileId) ?>">
                    <button type="submit" class="danger">Удалить</button>
                  </form>
                </div>
              </div>

              <div class="chip-row">
                <span class="chip"><?= h((string)($profile['feed_count'] ?? 0)) ?> источников</span>
                <?php if (!empty($profile['zero_missing_items'])): ?>
                  <span class="chip ok">обнулять исчезнувшие товары</span>
                <?php else: ?>
                  <span class="chip warn">не обнулять исчезнувшие</span>
                <?php endif; ?>
                <?php if (!empty($profile['subtract_reserved'])): ?>
                  <span class="chip ok">учитывать общий резерв</span>
                <?php else: ?>
                  <span class="chip warn">без общего резерва</span>
                <?php endif; ?>
                <?php if ((int)($profile['buffer_qty'] ?? 0) > 0): ?>
                  <span class="chip">буфер <?= h((string)($profile['buffer_qty'] ?? 0)) ?></span>
                <?php endif; ?>
                <?php if ((int)($profile['max_qty'] ?? 0) > 0): ?>
                  <span class="chip">максимум <?= h((string)($profile['max_qty'] ?? 0)) ?></span>
                <?php endif; ?>
                <?php if ((int)($profile['force_zero_supplier_count'] ?? 0) > 0): ?>
                  <span class="chip warn">поставщики в 0: <?= h((string)(int)$profile['force_zero_supplier_count']) ?></span>
                <?php endif; ?>
                <?php
                  $zeroArticleCount = count(stocks_tool_split_zero_offer_ids((string)($profile['zero_offer_ids_text'] ?? '')));
                  $zeroCategoryCount = array_sum(array_map('count', (array)($profile['zero_supplier_categories'] ?? [])));
                  $zeroBrandCount = array_sum(array_map('count', (array)($profile['zero_supplier_brands'] ?? [])));
                ?>
                <?php if ($zeroArticleCount > 0): ?>
                  <span class="chip warn">0 по артикулам: <?= h((string)$zeroArticleCount) ?></span>
                <?php endif; ?>
                <?php if ($zeroCategoryCount > 0): ?>
                  <span class="chip warn">0 по категориям: <?= h((string)$zeroCategoryCount) ?></span>
                <?php endif; ?>
                <?php if ($zeroBrandCount > 0): ?>
                  <span class="chip warn">0 по брендам: <?= h((string)$zeroBrandCount) ?></span>
                <?php endif; ?>
              </div>

              <?php if (!empty($profile['feed_names']) || !empty($profile['supplier_codes'])): ?>
                <div class="muted" style="margin-top:12px;">
                  <?php if (!empty($profile['feed_names'])): ?>
                    Источники: <?= h(implode(' · ', array_map('strval', (array)$profile['feed_names']))) ?>
                  <?php endif; ?>
                  <?php if (!empty($profile['supplier_codes'])): ?>
                    <?php if (!empty($profile['feed_names'])): ?> · <?php endif; ?>
                    Коды поставщиков: <code><?= h(implode(', ', array_map('strval', (array)$profile['supplier_codes']))) ?></code>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="run-actions">
                <?php if ($activeRun): ?>
                  <span class="status-chip run">Идёт run #<?= h((string)($activeRun['id'] ?? 0)) ?></span>
                <?php else: ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="run_stock_profile">
                    <input type="hidden" name="profile_id" value="<?= h((string)$profileId) ?>">
                    <button type="submit">Обновить остатки</button>
                  </form>
                <?php endif; ?>
              </div>

              <div class="test-item-card">
                <div>
                  <h3 style="margin:0 0 6px;">Проверка одного товара</h3>
                  <div class="muted">Показывает расчёт остатка по одному артикулу или полному <code>offer_id</code> на складе этого профиля. Можно сначала посмотреть preview, а затем отправить остаток только по этому товару.</div>
                </div>
                <form method="post" class="test-item-form">
                  <input type="hidden" name="action" value="test_stock_item">
                  <input type="hidden" name="profile_id" value="<?= h((string)$profileId) ?>">
                  <label>
                    <span>Артикул или offer_id</span>
                    <input type="text" name="test_article" required placeholder="Например, ABC-123 или ABC-123__supplier" value="<?= $testProfileId === $profileId ? h($testArticle) : '' ?>">
                  </label>
                  <button type="submit" name="action" value="test_stock_item" class="secondary">Проверить товар</button>
                  <button type="submit" name="action" value="push_test_stock_item" onclick="return confirm('Отправить остаток только по этому товару на <?= h($currentMarketplaceLabel) ?>?');">Отправить тест</button>
                </form>

                <?php if ($testProfileId === $profileId && is_array($testResult)): ?>
                  <?php if (is_array($testResult['test_push'] ?? null)): ?>
                    <?php $testPush = $testResult['test_push']; ?>
                    <div class="<?= !empty($testPush['success']) ? 'flash' : 'error' ?>" style="margin:0; max-width:none;">
                      <strong><?= !empty($testPush['success']) ? 'Тестовая отправка выполнена.' : 'Тестовая отправка не выполнена.' ?></strong>
                      <?= h((string)($testPush['message'] ?? '')) ?>
                      <?php if (!empty($testPush['sent_at'])): ?>
                        <span class="muted"> · <?= h((string)$testPush['sent_at']) ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <div class="test-item-grid">
                    <div class="test-item-box">
                      <div class="test-item-kicker">Товар</div>
                      <div style="font-weight:800;"><?= h((string)($testResult['raw_offer_id'] ?? '')) ?></div>
                      <div class="muted"><?= h((string)($testResult['offer_id'] ?? '')) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Статус</div>
                      <div style="font-weight:800;"><?= h((string)($testResult['status_label'] ?? '—')) ?></div>
                      <div class="muted"><?= h((string)($testResult['warehouse_name'] ?? '')) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Payload</div>
                      <div style="font-weight:800;"><?= is_array($testResult['payload_preview'] ?? null) ? 'Будет отправлен' : 'Отправка не требуется' ?></div>
                      <div class="muted">Склад ID <?= h((string)($testResult['warehouse_id'] ?? '')) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Остаток из фида</div>
                      <div class="test-item-value"><?= h((string)($testResult['feed_qty'] ?? 0)) ?></div>
                      <div class="muted">
                        <?php if (($testResult['feed_price'] ?? null) !== null): ?>
                          Цена: <?= h((string)$testResult['feed_price']) ?>
                        <?php else: ?>
                          Цена в фиде не найдена
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Общий резерв</div>
                      <div class="test-item-value"><?= h((string)($testResult['reserved_qty'] ?? 0)) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Остаток на <?= h((string)($testResult['marketplace_label'] ?? $currentMarketplaceLabel)) ?></div>
                      <div class="test-item-value"><?= h((string)($testResult['present_qty'] ?? 0)) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Итоговый остаток</div>
                      <div class="test-item-value"><?= h((string)($testResult['target_qty'] ?? 0)) ?></div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Источник</div>
                      <div style="font-weight:800;">Поставщик #<?= h((string)($testResult['source_feed_id'] ?? 0)) ?></div>
                      <div class="muted"><?= !empty($testResult['is_missing_from_feed']) ? 'Сейчас отсутствует в фиде' : 'Найден в фиде' ?></div>
                      <div class="muted">
                        Буфер: <?= h((string)($testResult['feed_buffer_qty'] ?? 0)) ?>
                        <?php if (($testResult['feed_min_price'] ?? null) !== null): ?> · min <?= h((string)$testResult['feed_min_price']) ?><?php endif; ?>
                        <?php if (($testResult['feed_max_price'] ?? null) !== null): ?> · max <?= h((string)$testResult['feed_max_price']) ?><?php endif; ?>
                      </div>
                    </div>
                    <div class="test-item-box">
                      <div class="test-item-kicker">Наличие на <?= h((string)($testResult['marketplace_label'] ?? $currentMarketplaceLabel)) ?></div>
                      <div style="font-weight:800;"><?= !empty($testResult['is_on_ozon']) ? ('Найден на ' . h((string)($testResult['marketplace_label'] ?? $currentMarketplaceLabel))) : ('Не найден на ' . h((string)($testResult['marketplace_label'] ?? $currentMarketplaceLabel))) ?></div>
                      <div class="muted">
                        <?= !empty($testResult['supplier_code']) ? ('Поставщик: ' . (string)$testResult['supplier_code']) : 'Без кода поставщика' ?>
                        <?php if (!empty($testResult['force_zero_stock'])): ?> · Поставщик удерживается в 0<?php endif; ?>
                        <?php if (!empty($testResult['price_out_of_range'])): ?> · Цена вне диапазона<?php endif; ?>
                        <?php if (trim((string)($testResult['zero_rule_reason'] ?? '')) !== ''): ?> · Правило 0: <?= h((string)$testResult['zero_rule_reason']) ?><?php endif; ?>
                        <?php if (!empty($testResult['fbo_zero_fbs_active'])): ?> · FBS обнуляется из-за FBO: <?= h((string)($testResult['fbo_present_qty'] ?? 0)) ?><?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <?php if (is_array($testResult['payload_preview'] ?? null)): ?>
                    <div>
                      <div class="test-item-kicker" style="margin-bottom:8px;">Preview payload</div>
                      <pre class="test-item-log"><?= h((string)json_encode($testResult['payload_preview'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($testResult['log_lines']) && is_array($testResult['log_lines'])): ?>
                    <div>
                      <div class="test-item-kicker" style="margin-bottom:8px;">Лог проверки</div>
                      <pre class="test-item-log"><?= h(implode("\n", array_map('strval', $testResult['log_lines']))) ?></pre>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <?php if ($activeRun): ?>
                <?php
                  $activeTotals = is_array($activeRun['totals'] ?? null) ? $activeRun['totals'] : [];
                  $activeSummary = is_array($activeRun['summary'] ?? null) ? $activeRun['summary'] : [];
                ?>
                <div class="run-panel current" data-run-id="<?= h((string)($activeRun['id'] ?? 0)) ?>">
                  <div class="run-head">
                    <div>
                      <strong>Текущий запуск</strong>
                      <div class="muted">run #<?= h((string)($activeRun['id'] ?? 0)) ?> · <?= h(stocks_tool_run_status_label((string)($activeRun['status'] ?? ''))) ?></div>
                    </div>
                    <div class="run-metrics">
                      <span class="run-metric" data-metric="progress"><?= h((string)(($activeRun['progress_current'] ?? 0) . ' / ' . ($activeRun['progress_total'] ?? 0))) ?></span>
                      <span class="run-metric" data-metric="updated">updated <?= h((string)($activeTotals['updated'] ?? 0)) ?></span>
                      <span class="run-metric" data-metric="supplier-zero">поставщик 0 <?= h((string)($activeTotals['supplier_force_zero'] ?? 0)) ?></span>
                      <span class="run-metric" data-metric="fbo-zero">FBO→FBS 0 <?= h((string)($activeTotals['fbo_fbs_zeroed'] ?? 0)) ?></span>
                      <span class="run-metric" data-metric="skipped">skipped <?= h((string)($activeTotals['skipped'] ?? 0)) ?></span>
                      <span class="run-metric" data-metric="errors">errors <?= h((string)($activeTotals['errors'] ?? 0)) ?></span>
                    </div>
                  </div>
                  <pre class="run-log" data-run-log><?= h((string)($activeRun['log_text'] ?? '')) ?></pre>
                </div>
              <?php endif; ?>

              <?php if ($recentRuns): ?>
                <div class="run-list">
                  <?php foreach ($recentRuns as $run): ?>
                    <?php if ($activeRun && (int)($activeRun['id'] ?? 0) === (int)($run['id'] ?? 0)) { continue; } ?>
                    <?php $runTotals = is_array($run['totals'] ?? null) ? $run['totals'] : []; ?>
                    <?php $runLogText = trim((string)($run['log_text'] ?? '')); ?>
                    <?php $runErrorText = trim((string)($run['error_text'] ?? '')); ?>
                    <div class="run-item">
                      <div class="run-head">
                        <div>
                          <strong>Run #<?= h((string)($run['id'] ?? 0)) ?></strong>
                          <div class="muted"><?= h((string)($run['created_at'] ?? '')) ?></div>
                        </div>
                        <span class="status-chip <?= h(stocks_tool_run_status_class((string)($run['status'] ?? ''))) ?>"><?= h(stocks_tool_run_status_label((string)($run['status'] ?? ''))) ?></span>
                      </div>
                      <div class="run-metrics">
                        <span class="run-metric">scope <?= h((string)($runTotals['scoped_offers'] ?? 0)) ?></span>
                        <span class="run-metric">to update <?= h((string)($runTotals['to_update'] ?? 0)) ?></span>
                        <?php if ((int)($runTotals['supplier_force_zero'] ?? 0) > 0): ?><span class="run-metric">поставщик 0 <?= h((string)$runTotals['supplier_force_zero']) ?></span><?php endif; ?>
                        <?php if ((int)($runTotals['fbo_fbs_zeroed'] ?? 0) > 0): ?><span class="run-metric">FBO→FBS 0 <?= h((string)$runTotals['fbo_fbs_zeroed']) ?></span><?php endif; ?>
                        <?php if ((int)($runTotals['feed_errors'] ?? 0) > 0): ?><span class="run-metric">источники недоступны <?= h((string)$runTotals['feed_errors']) ?></span><?php endif; ?>
                        <span class="run-metric">updated <?= h((string)($runTotals['updated'] ?? 0)) ?></span>
                        <span class="run-metric">skipped <?= h((string)($runTotals['skipped'] ?? 0)) ?></span>
                        <span class="run-metric">errors <?= h((string)($runTotals['errors'] ?? 0)) ?></span>
                      </div>
                      <?php if ($runErrorText !== ''): ?>
                        <div class="run-error">Ошибка: <?= h($runErrorText) ?></div>
                      <?php endif; ?>
                      <?php if ($runLogText !== ''): ?>
                        <details class="run-details"<?= ((int)($runTotals['errors'] ?? 0) > 0 || $runErrorText !== '') ? ' open' : '' ?>>
                          <summary>Показать лог</summary>
                          <pre class="run-log"><?= h($runLogText) ?></pre>
                        </details>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($isNewProfileMode || $profileEditorId > 0): ?>
        <div class="card">
          <div class="section-head">
            <div>
              <h2><?= $profileEditorId > 0 ? 'Редактирование профиля остатков' : 'Новый профиль остатков' ?></h2>
              <div class="muted">Профиль задаёт источники поставщиков, склад <?= h($currentMarketplaceLabel) ?> и базовые правила расчёта остатка. Эти настройки используются и в ручном запуске, и в автоматизациях, без отдельной второй логики.</div>
            </div>
          </div>

          <form method="post" class="stack">
            <input type="hidden" name="id" value="<?= h((string)($profileEditor['id'] ?? 0)) ?>">
            <input type="hidden" name="profile_id" value="<?= h((string)($profileEditor['id'] ?? 0)) ?>">
            <input type="hidden" name="zero_feed_id" value="">

            <div class="form-grid">
              <label>
                <span>Название профиля<span class="required-mark">*</span></span>
                <input type="text" name="name" required value="<?= h((string)($profileEditor['name'] ?? '')) ?>">
              </label>
              <label>
                <span>Склад <?= h($currentMarketplaceLabel) ?><span class="required-mark">*</span></span>
                <select name="ozon_warehouse_key" required>
                  <option value="">Выбери склад <?= h($currentMarketplaceLabel) ?></option>
                  <?php foreach ($warehouseOptions as $warehouseKey => $warehouse): ?>
                    <option value="<?= h((string)$warehouseKey) ?>" <?= (string)($profileEditor['ozon_warehouse_key'] ?? '') === (string)$warehouseKey ? 'selected' : '' ?>>
                      <?= h((string)($warehouse['warehouse_name'] ?? '—')) ?>
                      <?php if (trim((string)($warehouse['warehouse_id'] ?? '')) !== ''): ?>
                        · ID <?= h((string)$warehouse['warehouse_id']) ?>
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="ozon_warehouse_id" value="<?= h((string)($profileEditor['ozon_warehouse_id'] ?? '')) ?>">
                <input type="hidden" name="ozon_warehouse_name" value="<?= h((string)($profileEditor['ozon_warehouse_name'] ?? '')) ?>">
              </label>
              <label>
                <span>Буфер остатка, шт</span>
                <input type="number" min="0" max="100000" name="buffer_qty" value="<?= h((string)($profileEditor['buffer_qty'] ?? 0)) ?>">
              </label>
              <label>
                <span>Максимум остатка, шт</span>
                <input type="number" min="0" max="100000" name="max_qty" value="<?= h((string)($profileEditor['max_qty'] ?? 0)) ?>">
              </label>
              <label>
                <span>Порядок</span>
                <input type="number" min="1" max="9999" name="sort_order" value="<?= h((string)($profileEditor['sort_order'] ?? 100)) ?>">
              </label>
              <label>
                <span>Служебные флаги</span>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                  <label class="checkbox-chip"><input type="checkbox" name="zero_missing_items" value="1" <?= !empty($profileEditor['zero_missing_items']) ? 'checked' : '' ?>> Обнулять исчезнувшие</label>
                  <label class="checkbox-chip"><input type="checkbox" name="subtract_reserved" value="1" <?= !empty($profileEditor['subtract_reserved']) ? 'checked' : '' ?>> Вычитать общий резерв площадок</label>
                  <label class="checkbox-chip"><input type="checkbox" name="is_active" value="1" <?= !empty($profileEditor['is_active']) ? 'checked' : '' ?>> Профиль включён</label>
                </div>
              </label>
            </div>

            <label>
              <span>Заметки</span>
              <textarea name="notes"><?= h((string)($profileEditor['notes'] ?? '')) ?></textarea>
            </label>

            <div class="zero-rules-card">
              <div>
                <h3 style="margin:0 0 6px;">Правила обнуления остатков</h3>
                <div class="muted">Эти правила применяются перед отправкой остатков на <?= h($currentMarketplaceLabel) ?>. Исходные товары поставщика не меняются: в маркетплейс у выбранных товаров уходит остаток <code>0</code>.</div>
              </div>
              <label>
                <span>Артикулы для обнуления</span>
                <textarea name="zero_offer_ids_text" placeholder="Один артикул или offer_id в строке. Например:&#10;ABC-123&#10;ABC-123__4"><?= h((string)($profileEditor['zero_offer_ids_text'] ?? '')) ?></textarea>
                <div class="zero-rule-note">Если в профиле несколько поставщиков и указан артикул без <code>__код</code>, правило применится к этому артикулу у каждого выбранного поставщика.</div>
              </label>
            </div>

              <div>
                <h3 style="margin-bottom:8px;">Источники поставщиков<span class="required-mark">*</span></h3>
              <div class="muted" style="margin-bottom:12px;">Здесь выбираются поставщики из общего раздела проекта. Код поставщика нужен для scope товаров на <?= h($currentMarketplaceLabel) ?> по suffix <code>__supplier_code</code>; для Яндекс Маркета при отправке он автоматически превращается в формат <code>article000supplier_code</code>. В этом профиле остатков для каждого поставщика можно задать собственный буфер и ценовые границы. Если цена товара ниже минимума или выше максимума, в <?= h($currentMarketplaceLabel) ?> по нему уйдёт остаток <code>0</code>. Остатки и обнуление исчезнувших применяются только к складу, выбранному в профиле.</div>

              <div class="feed-select-grid">
                <?php if (!$feedOptions): ?>
                  <div class="feed-select disabled">
                    <div>
                      <strong>Нет поставщиков</strong>
                      <div class="muted">Сначала заведи поставщика в общем разделе, а затем вернись сюда и привяжи его к профилю остатков.</div>
                      <div style="margin-top:10px;"><a class="button-link secondary" href="suppliers.php">Открыть поставщиков</a></div>
                    </div>
                  </div>
                <?php endif; ?>
                <?php foreach ($feedOptions as $feed): ?>
                  <?php
                    $feedId = (int)($feed['id'] ?? 0);
                    $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
                    $disabled = $supplierCode === '';
                    $selected = in_array($feedId, (array)($profileEditor['feed_ids'] ?? []), true);
                    $feedOfferCount = $feedOfferCounts[$feedId] ?? null;
                    $feedSettings = is_array(($profileEditor['feed_settings'] ?? [])[$feedId] ?? null)
                      ? ($profileEditor['feed_settings'] ?? [])[$feedId]
                      : ['buffer_qty' => 0, 'min_price' => null, 'max_price' => null, 'force_zero_stock' => 0];
                    $feedForceZero = !empty($feedSettings['force_zero_stock']);
                    $feedZeroOptions = is_array($zeroRuleOptions[$feedId] ?? null) ? $zeroRuleOptions[$feedId] : ['categories' => [], 'brands' => []];
                    $selectedZeroCategories = array_fill_keys(array_map('strval', (array)(($profileEditor['zero_supplier_categories'] ?? [])[$feedId] ?? [])), true);
                    $selectedZeroBrands = [];
                    foreach ((array)(($profileEditor['zero_supplier_brands'] ?? [])[$feedId] ?? []) as $selectedBrand) {
                      $selectedZeroBrands[stocks_tool_norm_rule_value((string)$selectedBrand)] = true;
                    }
                  ?>
                  <div class="feed-select<?= $disabled ? ' disabled' : '' ?><?= $feedForceZero ? ' force-zero' : '' ?>">
                    <div class="feed-select-head">
                      <input type="checkbox" name="supplier_ids[]" value="<?= h((string)$feedId) ?>" <?= $selected ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                      <div>
                        <div class="feed-select-title"><?= h((string)($feed['name'] ?? '')) ?></div>
                        <div class="muted" style="font-size:13px;">Товаров в источнике: <?= $feedOfferCount === null ? '—' : h((string)$feedOfferCount) ?></div>
                        <?php if ($supplierCode !== ''): ?>
                          <div class="muted" style="font-size:13px;">Код поставщика: <code><?= h($supplierCode) ?></code></div>
                        <?php endif; ?>
                        <?php if ($supplierCode === ''): ?>
                          <div class="muted" style="font-size:13px; color:#9a3412;">Заполни код в карточке поставщика, иначе источник нельзя использовать для остатков.</div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="feed-settings-grid">
                      <label>
                        <span>Буфер поставщика, шт</span>
                        <input type="number" min="0" max="100000" name="feed_buffer_qty[<?= h((string)$feedId) ?>]" value="<?= h((string)($feedSettings['buffer_qty'] ?? 0)) ?>" <?= $disabled ? 'disabled' : '' ?>>
                      </label>
                      <label>
                        <span>Мин. цена</span>
                        <input type="text" inputmode="decimal" name="feed_min_price[<?= h((string)$feedId) ?>]" value="<?= h((string)($feedSettings['min_price'] ?? '')) ?>" placeholder="Например, 1000" <?= $disabled ? 'disabled' : '' ?>>
                      </label>
                      <label>
                        <span>Макс. цена</span>
                        <input type="text" inputmode="decimal" name="feed_max_price[<?= h((string)$feedId) ?>]" value="<?= h((string)($feedSettings['max_price'] ?? '')) ?>" placeholder="Например, 50000" <?= $disabled ? 'disabled' : '' ?>>
                      </label>
                    </div>

                    <label class="supplier-zero-toggle">
                      <input
                        type="checkbox"
                        name="feed_force_zero_stock[<?= h((string)$feedId) ?>]"
                        value="1"
                        <?= $feedForceZero ? 'checked' : '' ?>
                        <?= $disabled ? 'disabled' : '' ?>
                      >
                      <span class="supplier-zero-switch" aria-hidden="true"></span>
                      <span class="supplier-zero-toggle-copy">
                        <strong>Держать поставщика в нуле</strong>
                        <span>Все товары с кодом <?= $supplierCode !== '' ? h($supplierCode) : 'поставщика' ?> будут отправляться с остатком 0.</span>
                      </span>
                    </label>

                    <div class="feed-inline-note">Если цена товара выходит за границы этого поставщика, в <?= h($currentMarketplaceLabel) ?> по нему будет передан остаток <code>0</code>.</div>

                    <div class="zero-rules-grid">
                      <div class="zero-rule-field" data-zero-picker>
                        <div class="zero-rule-title">
                          <span>Категории поставщика для остатка 0</span>
                          <span class="zero-rule-count" data-zero-count>выбрано: <?= h((string)count($selectedZeroCategories)) ?></span>
                        </div>
                        <div class="zero-rule-toolbar">
                          <input type="search" data-zero-search placeholder="найти категорию" <?= $disabled ? 'disabled' : '' ?>>
                          <button type="button" class="secondary zero-rule-mini-button" data-zero-action="all" <?= $disabled ? 'disabled' : '' ?>>все</button>
                          <button type="button" class="secondary zero-rule-mini-button" data-zero-action="none" <?= $disabled ? 'disabled' : '' ?>>очистить</button>
                        </div>
                        <div class="zero-rule-list">
                          <?php $renderedZeroCategories = []; ?>
                          <?php foreach ((array)($feedZeroOptions['categories'] ?? []) as $categoryOption): ?>
                            <?php
                              $categoryValue = trim((string)($categoryOption['value'] ?? ''));
                              if ($categoryValue === '') { continue; }
                              $categorySelected = isset($selectedZeroCategories[$categoryValue]);
                              $renderedZeroCategories[$categoryValue] = true;
                            ?>
                            <label class="zero-rule-option<?= $categorySelected ? ' is-selected' : '' ?>" data-zero-option data-zero-text="<?= h(mb_strtolower((string)($categoryOption['label'] ?? $categoryValue), 'UTF-8')) ?>">
                              <input type="checkbox" name="zero_supplier_categories[<?= h((string)$feedId) ?>][]" value="<?= h($categoryValue) ?>" <?= $categorySelected ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                              <span class="zero-rule-option-main">
                                <?= h((string)($categoryOption['label'] ?? $categoryValue)) ?>
                                <?php if ((int)($categoryOption['count'] ?? 0) > 0): ?><span class="zero-rule-option-count"> · <?= h((string)(int)$categoryOption['count']) ?></span><?php endif; ?>
                              </span>
                            </label>
                          <?php endforeach; ?>
                          <?php foreach (array_keys($selectedZeroCategories) as $categoryValue): ?>
                            <?php if (isset($renderedZeroCategories[$categoryValue])) { continue; } ?>
                            <label class="zero-rule-option is-selected" data-zero-option data-zero-text="<?= h(mb_strtolower((string)$categoryValue, 'UTF-8')) ?>">
                              <input type="checkbox" name="zero_supplier_categories[<?= h((string)$feedId) ?>][]" value="<?= h((string)$categoryValue) ?>" checked <?= $disabled ? 'disabled' : '' ?>>
                              <span class="zero-rule-option-main"><?= h((string)$categoryValue) ?><span class="zero-rule-option-count"> · сохранено</span></span>
                            </label>
                          <?php endforeach; ?>
                          <?php if (empty($feedZeroOptions['categories']) && empty($selectedZeroCategories)): ?>
                            <div class="zero-rule-empty">Категории пока не найдены. Обнови товары поставщика или дождись чтения прайса.</div>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="zero-rule-field" data-zero-picker>
                        <div class="zero-rule-title">
                          <span>Бренды из прайса для остатка 0</span>
                          <span class="zero-rule-count" data-zero-count>выбрано: <?= h((string)count($selectedZeroBrands)) ?></span>
                        </div>
                        <div class="zero-rule-toolbar">
                          <input type="search" data-zero-search placeholder="найти бренд" <?= $disabled ? 'disabled' : '' ?>>
                          <button type="button" class="secondary zero-rule-mini-button" data-zero-action="all" <?= $disabled ? 'disabled' : '' ?>>все</button>
                          <button type="button" class="secondary zero-rule-mini-button" data-zero-action="none" <?= $disabled ? 'disabled' : '' ?>>очистить</button>
                        </div>
                        <div class="zero-rule-list">
                          <?php $renderedZeroBrands = []; ?>
                          <?php foreach ((array)($feedZeroOptions['brands'] ?? []) as $brandOption): ?>
                            <?php
                              $brandValue = trim((string)($brandOption['value'] ?? ''));
                              if ($brandValue === '') { continue; }
                              $brandNorm = stocks_tool_norm_rule_value($brandValue);
                              $brandSelected = isset($selectedZeroBrands[$brandNorm]);
                              $renderedZeroBrands[$brandNorm] = true;
                            ?>
                            <label class="zero-rule-option<?= $brandSelected ? ' is-selected' : '' ?>" data-zero-option data-zero-text="<?= h(mb_strtolower((string)($brandOption['label'] ?? $brandValue), 'UTF-8')) ?>">
                              <input type="checkbox" name="zero_supplier_brands[<?= h((string)$feedId) ?>][]" value="<?= h($brandValue) ?>" <?= $brandSelected ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                              <span class="zero-rule-option-main">
                                <?= h((string)($brandOption['label'] ?? $brandValue)) ?>
                                <?php if ((int)($brandOption['count'] ?? 0) > 0): ?><span class="zero-rule-option-count"> · <?= h((string)(int)$brandOption['count']) ?></span><?php endif; ?>
                              </span>
                            </label>
                          <?php endforeach; ?>
                          <?php foreach ((array)(($profileEditor['zero_supplier_brands'] ?? [])[$feedId] ?? []) as $brandValue): ?>
                            <?php
                              $brandValue = trim((string)$brandValue);
                              $brandNorm = stocks_tool_norm_rule_value($brandValue);
                              if ($brandValue === '' || isset($renderedZeroBrands[$brandNorm])) { continue; }
                            ?>
                            <label class="zero-rule-option is-selected" data-zero-option data-zero-text="<?= h(mb_strtolower($brandValue, 'UTF-8')) ?>">
                              <input type="checkbox" name="zero_supplier_brands[<?= h((string)$feedId) ?>][]" value="<?= h($brandValue) ?>" checked <?= $disabled ? 'disabled' : '' ?>>
                              <span class="zero-rule-option-main"><?= h($brandValue) ?><span class="zero-rule-option-count"> · сохранено</span></span>
                            </label>
                          <?php endforeach; ?>
                          <?php if (empty($feedZeroOptions['brands']) && empty($selectedZeroBrands)): ?>
                            <div class="zero-rule-empty">Бренды пока не найдены. Они берутся из <code>brand</code> или <code>vendor</code> в прайсе.</div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <?php $canZeroSupplier = !$disabled && $selected && (int)($profileEditor['id'] ?? 0) > 0; ?>
                    <div class="feed-select-actions<?= $canZeroSupplier ? '' : ' is-empty' ?>">
                      <?php if ($canZeroSupplier): ?>
                        <button
                          type="submit"
                          class="secondary"
                          name="action"
                          value="zero_supplier_stock"
                          formaction="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&stock_profile_edit_id=<?= h((string)($profileEditor['id'] ?? 0)) ?>"
                          formnovalidate
                          onclick="this.form.elements['zero_feed_id'].value='<?= h((string)$feedId) ?>'; return confirm('Разово обнулить остатки всех товаров этого поставщика на складе текущего профиля?');"
                        >
                          Разово обнулить сейчас
                        </button>
                      <?php else: ?>
                        <span aria-hidden="true">.</span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <button type="submit" name="action" value="save_stock_profile">Сохранить профиль</button>
              <a class="button-link secondary" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Скрыть</a>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card placeholder-card">
        <h2>Дальше в Stocks Tool</h2>
        <div class="muted">Ручной runtime обновления остатков уже работает, а автоматизация вынесена на отдельную вкладку и использует тот же runner и те же профили.</div>
      </div>
    <?php endif; ?>
  </div>
  <script>
    (function () {
      const normalizeText = (value) => String(value || '').toLocaleLowerCase('ru-RU').trim();

      document.querySelectorAll('[data-zero-picker]').forEach((picker) => {
        const options = Array.from(picker.querySelectorAll('[data-zero-option]'));
        const countEl = picker.querySelector('[data-zero-count]');
        const search = picker.querySelector('[data-zero-search]');
        const buttons = Array.from(picker.querySelectorAll('[data-zero-action]'));
        const checkboxes = () => Array.from(picker.querySelectorAll('input[type="checkbox"]'));

        const refresh = () => {
          const checkedCount = checkboxes().filter((input) => input.checked).length;
          if (countEl) {
            countEl.textContent = `выбрано: ${checkedCount}`;
          }
          options.forEach((option) => {
            const input = option.querySelector('input[type="checkbox"]');
            option.classList.toggle('is-selected', Boolean(input && input.checked));
          });
        };

        const applyFilter = () => {
          const query = normalizeText(search ? search.value : '');
          options.forEach((option) => {
            const text = normalizeText(option.getAttribute('data-zero-text') || option.textContent || '');
            option.hidden = query !== '' && !text.includes(query);
          });
        };

        checkboxes().forEach((input) => {
          input.addEventListener('change', refresh);
        });
        if (search) {
          search.addEventListener('input', applyFilter);
        }
        buttons.forEach((button) => {
          button.addEventListener('click', () => {
            const action = button.getAttribute('data-zero-action');
            const visibleInputs = options
              .filter((option) => !option.hidden)
              .map((option) => option.querySelector('input[type="checkbox"]'))
              .filter(Boolean);
            if (action === 'all') {
              visibleInputs.forEach((input) => {
                if (!input.disabled) input.checked = true;
              });
            } else if (action === 'none') {
              checkboxes().forEach((input) => {
                if (!input.disabled) input.checked = false;
              });
            }
            refresh();
          });
        });
        refresh();
      });
    }());

    (function () {
      const panels = Array.from(document.querySelectorAll('[data-run-id]'));
      if (!panels.length) return;

      const pollPanel = async (panel) => {
        const runId = panel.getAttribute('data-run-id');
        if (!runId) return;
        try {
          const response = await fetch(`stocks_tool_run_poll.php?run_id=${encodeURIComponent(runId)}`, { credentials: 'same-origin' });
          if (!response.ok) return;
          const data = await response.json();
          const progress = panel.querySelector('[data-metric="progress"]');
          const updated = panel.querySelector('[data-metric="updated"]');
          const supplierZero = panel.querySelector('[data-metric="supplier-zero"]');
          const fboZero = panel.querySelector('[data-metric="fbo-zero"]');
          const errors = panel.querySelector('[data-metric="errors"]');
          const log = panel.querySelector('[data-run-log]');
          if (progress) {
            progress.textContent = `${data.progress_current || 0} / ${data.progress_total || 0}`;
          }
          if (updated) {
            updated.textContent = `updated ${data.totals?.updated || 0}`;
          }
          if (supplierZero) {
            supplierZero.textContent = `поставщик 0 ${data.totals?.supplier_force_zero || 0}`;
          }
          if (fboZero) {
            fboZero.textContent = `FBO→FBS 0 ${data.totals?.fbo_fbs_zeroed || 0}`;
          }
          const skipped = panel.querySelector('[data-metric="skipped"]');
          if (skipped) {
            skipped.textContent = `skipped ${data.totals?.skipped || 0}`;
          }
          if (errors) {
            errors.textContent = `errors ${data.totals?.errors || 0}`;
          }
          if (log && typeof data.log_text === 'string') {
            log.textContent = data.log_text;
            log.scrollTop = log.scrollHeight;
          }
          if (data.status === 'queued' || data.status === 'running') {
            setTimeout(() => pollPanel(panel), 3000);
          } else {
            setTimeout(() => window.location.reload(), 1800);
          }
        } catch (e) {
          setTimeout(() => pollPanel(panel), 5000);
        }
      };

      panels.forEach((panel) => {
        setTimeout(() => pollPanel(panel), 1200);
      });
    }());
  </script>
</body>
</html>
