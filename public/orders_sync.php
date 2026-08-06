<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/orders_sync.php';
require_once __DIR__ . '/../app/navigation.php';

orders_sync_module_bootstrap($cfg);

$actor = ft_current_user();
$flash = '';
$error = '';
$summary = null;
$moyskladCheck = null;
$testExportResult = null;
$testOrderRef = trim((string)($_POST['test_order_ref'] ?? $_GET['test_order_ref'] ?? ''));

$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
$requestedProfileId = (int)($_GET['profile_id'] ?? $_POST['profile_id'] ?? 0);
$profileEditId = (int)($_GET['profile_edit_id'] ?? $_POST['profile_edit_id'] ?? 0);
$copyProfileId = (int)($_GET['copy_profile_id'] ?? $_POST['copy_profile_id'] ?? 0);
$moyskladEditId = (int)($_GET['moysklad_edit_id'] ?? $_POST['moysklad_edit_id'] ?? 0);
$automationNewProfileId = (int)($_GET['automation_new_profile_id'] ?? $_POST['automation_new_profile_id'] ?? 0);
$requestAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
$isNewProfileMode = (isset($_GET['new_profile']) && $_GET['new_profile'] === '1') || (isset($_POST['new_profile']) && $_POST['new_profile'] === '1');
$isNewMoyskladMode = (isset($_GET['new_moysklad']) && $_GET['new_moysklad'] === '1') || (isset($_POST['new_moysklad']) && $_POST['new_moysklad'] === '1');
$routeProfileId = $profileEditId > 0 ? $profileEditId : $requestedProfileId;
if ($routeProfileId > 0) {
    $routeProfile = orders_sync_profile_get($routeProfileId, $cfg);
    $routeConnectionId = (int)($routeProfile['connection_id'] ?? 0);
    if ($routeConnectionId > 0) {
        $requestedConnectionId = $routeConnectionId;
    }
}
$requestedConnection = $requestedConnectionId > 0 ? ozon_price_connection_get($requestedConnectionId, $cfg) : null;
if ($requestedConnectionId > 0 && !is_array($requestedConnection)) {
    $requestedConnectionId = 0;
}
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=orders_sync', true, 303);
    exit;
}
$moyskladAccounts = orders_sync_moysklad_account_list($cfg);
$requestedMarketplace = orders_sync_marketplace_normalize((string)($requestedConnection['marketplace'] ?? 'ozon'));
$profiles = orders_sync_profile_list($cfg, $requestedMarketplace, $requestedConnectionId);
$currentProfile = orders_sync_profile_resolve($requestedProfileId, $cfg, $requestedMarketplace, $requestedConnectionId);
$currentProfileId = (int)($currentProfile['id'] ?? 0);
$currentConnectionId = $requestedConnectionId;
$currentConnection = $requestedConnection;
$currentMarketplace = orders_sync_marketplace_normalize((string)($currentProfile['marketplace'] ?? $currentConnection['marketplace'] ?? 'ozon'));
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'orders_sync');
$inlineProfileToolsId = $profileEditId > 0 ? $profileEditId : $currentProfileId;
$runId = (int)($_GET['run_id'] ?? 0);
$selectedSource = strtolower(trim((string)($_GET['source'] ?? 'all')));
if (!in_array($selectedSource, ['all', 'fbs', 'fbo', 'dbw', 'dbs', 'fby', 'express', 'laas'], true)) {
    $selectedSource = 'all';
}

$profileEditor = orders_sync_profile_default();
if ($profileEditId > 0) {
    $profileEditor = orders_sync_profile_get($profileEditId, $cfg) ?? $profileEditor;
} elseif ($isNewProfileMode && $requestedConnectionId > 0) {
    $profileEditor['connection_id'] = $requestedConnectionId;
    $profileEditor['marketplace'] = $requestedMarketplace;
    $profileEditor['order_sources'] = array_keys(orders_sync_marketplace_order_source_options($requestedMarketplace));
    $profileEditor['order_sources_json'] = json_encode($profileEditor['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["fbs"]';
} elseif (!$isNewProfileMode && is_array($currentProfile)) {
    $profileEditor = $currentProfile;
}

$moyskladEditor = orders_sync_moysklad_account_default();
if ($moyskladEditId > 0) {
    $moyskladEditor = orders_sync_moysklad_account_get($moyskladEditId, $cfg) ?? $moyskladEditor;
}

$profileEditorMoyskladAccount = null;
$profileMoyskladOptions = [
    'organizations' => [],
    'counterparties' => [],
    'projects' => [],
    'saleschannels' => [],
    'stores' => [],
    'customerorder_states' => [],
];
$profileWarehouseMappings = [];
$profileStatusCatalog = [];
$profileStatusCatalogGrouped = ['order' => [], 'fbo_only' => [], 'return' => []];
$copySourceProfile = null;
$copyTargetConnections = [];
$copyTitle = trim((string)($_POST['copy_title'] ?? ''));
$copyTargetConnectionId = (int)($_POST['copy_target_connection_id'] ?? 0);
$shouldLoadProfileEditorResources = $isNewProfileMode || $profileEditId > 0;
$activeRunsByProfile = [];
$activeRunListsByProfile = [];
$automationDraftsByProfile = [];
$refreshProfileEditorResources = static function () use (&$profileEditor, &$profileEditorMoyskladAccount, &$profileMoyskladOptions, &$profileWarehouseMappings, &$profileStatusCatalog, &$profileStatusCatalogGrouped, $cfg): void {
    $resources = orders_sync_profile_editor_resources($profileEditor, $cfg);
    $profileEditorMoyskladAccount = $resources['moysklad_account'] ?? null;
    $profileMoyskladOptions = is_array($resources['options'] ?? null) ? $resources['options'] : [
        'organizations' => [],
        'counterparties' => [],
        'projects' => [],
        'saleschannels' => [],
        'stores' => [],
        'customerorder_states' => [],
    ];
    $profileWarehouseMappings = is_array($resources['warehouse_mappings'] ?? null) ? $resources['warehouse_mappings'] : [];
    $profileStatusCatalog = is_array($resources['status_catalog'] ?? null) ? $resources['status_catalog'] : [];
    $profileStatusCatalogGrouped = is_array($resources['status_catalog_grouped'] ?? null) ? $resources['status_catalog_grouped'] : ['order' => [], 'fbo_only' => [], 'return' => []];
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $requestAction;

        if ($action === 'save_moysklad_account') {
            $savedId = orders_sync_moysklad_account_save($_POST, $actor, $cfg);
            $redirect = 'orders_sync.php?moysklad_saved=1';
            if ($requestedConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$requestedConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'check_moysklad_account') {
            $moyskladEditor = orders_sync_moysklad_account_input($_POST);
            $moyskladCheck = orders_sync_moysklad_account_check($_POST, $cfg);
            $flash = 'Подключение к МойСклад успешно проверено.';
        }

        if ($action === 'save_sync_profile') {
            $shouldLoadProfileEditorResources = true;
            $profileEditor = orders_sync_profile_input($_POST);
            $savedId = orders_sync_profile_save_with_mappings($_POST, $actor, $cfg);
            $redirectConnectionId = (int)($_POST['connection_id'] ?? $requestedConnectionId);
            $redirect = 'orders_sync.php?profile_saved=1&profile_id=' . urlencode((string)$savedId);
            if ($redirectConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$redirectConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'copy_sync_profile') {
            $newProfileId = orders_sync_profile_clone(
                (int)($_POST['copy_profile_id'] ?? 0),
                (int)($_POST['copy_target_connection_id'] ?? 0),
                trim((string)($_POST['copy_title'] ?? '')),
                $actor,
                $cfg
            );
            $newProfile = orders_sync_profile_get($newProfileId, $cfg);
            $redirectConnectionId = (int)($newProfile['connection_id'] ?? 0);
            $redirect = 'orders_sync.php?profile_copied=1&profile_id=' . urlencode((string)$newProfileId);
            if ($redirectConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$redirectConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'toggle_sync_profile_active') {
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $targetState = !empty($_POST['target_active']);
            orders_sync_profile_set_active($profileId, $targetState, $actor, $cfg);
            $redirect = 'orders_sync.php?profile_id=' . urlencode((string)$profileId);
            if ($requestedConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$requestedConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'delete_sync_profile') {
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $redirectConnectionId = (int)($_POST['connection_id'] ?? $requestedConnectionId);
            orders_sync_profile_delete($profileId, $cfg);
            $redirect = 'orders_sync.php?profile_deleted=1';
            if ($redirectConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$redirectConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'save_automation') {
            $automationProfileId = (int)($_POST['profile_id'] ?? 0);
            $automationProfile = orders_sync_profile_get($automationProfileId, $cfg);
            if (!is_array($automationProfile)) {
                throw new RuntimeException('Профиль синхронизации для автоматизации не найден.');
            }
            $savedAutomationId = orders_sync_automation_save($_POST, $automationProfile, $actor, $cfg);
            $redirect = 'orders_sync.php?profile_id=' . urlencode((string)$automationProfileId) . '&automation_saved=1&automation_id=' . urlencode((string)$savedAutomationId);
            if ($requestedConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$requestedConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'delete_automation') {
            $automationProfileId = (int)($_POST['profile_id'] ?? 0);
            $automationId = (int)($_POST['automation_id'] ?? 0);
            orders_sync_automation_delete($automationId, $automationProfileId, $cfg);
            $redirect = 'orders_sync.php?profile_id=' . urlencode((string)$automationProfileId) . '&automation_deleted=1';
            if ($requestedConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$requestedConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'manual_sync') {
            $runProfileId = (int)($_POST['profile_id'] ?? $currentProfileId);
            if (!$currentMarketplaceReady || $runProfileId <= 0) {
                throw new RuntimeException('Сначала выбери профиль синхронизации для этого маркетплейса.');
            }
            $summary = orders_sync_manual_sync_ozon_profile_start($runProfileId, $actor, $cfg);
            $runFlag = !empty($summary['already_running']) ? 'sync_already_running=1' : 'sync_started=1';
            header(
                'Location: orders_sync.php?profile_id=' . urlencode((string)$runProfileId)
                . '&run_id=' . urlencode((string)($summary['run_id'] ?? 0))
                . '&' . $runFlag
                . ($requestedConnectionId > 0 ? '&connection_id=' . urlencode((string)$requestedConnectionId) : ''),
                true,
                303
            );
            exit;
        }

        if ($action === 'bulk_export_moysklad') {
            $runProfileId = (int)($_POST['profile_id'] ?? $currentProfileId);
            if (!$currentMarketplaceReady || $runProfileId <= 0) {
                throw new RuntimeException('Сначала выбери профиль синхронизации для этого маркетплейса.');
            }
            $summary = orders_sync_manual_export_moysklad_profile_start($runProfileId, $actor, $cfg);
            $runFlag = !empty($summary['already_running']) ? 'export_already_running=1' : 'export_started=1';
            header(
                'Location: orders_sync.php?profile_id=' . urlencode((string)$runProfileId)
                . '&run_id=' . urlencode((string)($summary['run_id'] ?? 0))
                . '&' . $runFlag
                . ($requestedConnectionId > 0 ? '&connection_id=' . urlencode((string)$requestedConnectionId) : ''),
                true,
                303
            );
            exit;
        }

        if ($action === 'bulk_create_moysklad') {
            $runProfileId = (int)($_POST['profile_id'] ?? $currentProfileId);
            if (!$currentMarketplaceReady || $runProfileId <= 0) {
                throw new RuntimeException('Сначала выбери профиль синхронизации для этого маркетплейса.');
            }
            $summary = orders_sync_manual_create_moysklad_profile_start($runProfileId, $actor, $cfg);
            $runFlag = !empty($summary['already_running']) ? 'create_already_running=1' : 'create_started=1';
            header(
                'Location: orders_sync.php?profile_id=' . urlencode((string)$runProfileId)
                . '&run_id=' . urlencode((string)($summary['run_id'] ?? 0))
                . '&' . $runFlag
                . ($requestedConnectionId > 0 ? '&connection_id=' . urlencode((string)$requestedConnectionId) : ''),
                true,
                303
            );
            exit;
        }

        if ($action === 'bulk_update_statuses_moysklad') {
            $runProfileId = (int)($_POST['profile_id'] ?? $currentProfileId);
            if (!$currentMarketplaceReady || $runProfileId <= 0) {
                throw new RuntimeException('Сначала выбери профиль синхронизации для этого маркетплейса.');
            }
            $summary = orders_sync_manual_update_statuses_moysklad_profile_start($runProfileId, $actor, $cfg);
            $runFlag = !empty($summary['already_running']) ? 'status_already_running=1' : 'status_started=1';
            header(
                'Location: orders_sync.php?profile_id=' . urlencode((string)$runProfileId)
                . '&run_id=' . urlencode((string)($summary['run_id'] ?? 0))
                . '&' . $runFlag
                . ($requestedConnectionId > 0 ? '&connection_id=' . urlencode((string)$requestedConnectionId) : ''),
                true,
                303
            );
            exit;
        }

        if ($action === 'stop_run') {
            $stopRunId = (int)($_POST['run_id'] ?? 0);
            if ($stopRunId <= 0) {
                throw new RuntimeException('Не удалось определить запуск для остановки.');
            }
            orders_sync_run_request_stop($stopRunId, $cfg);
            $redirect = 'orders_sync.php?run_stop_requested=1&run_id=' . urlencode((string)$stopRunId);
            if ($currentProfileId > 0) {
                $redirect .= '&profile_id=' . urlencode((string)$currentProfileId);
            }
            if ($requestedConnectionId > 0) {
                $redirect .= '&connection_id=' . urlencode((string)$requestedConnectionId);
            }
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'test_export_order') {
            $runProfileId = (int)($_POST['profile_id'] ?? $currentProfileId);
            if (!$currentMarketplaceReady || $runProfileId <= 0) {
                throw new RuntimeException('Сначала выбери профиль синхронизации для этого маркетплейса.');
            }
            $testOrderRef = trim((string)($_POST['test_order_ref'] ?? ''));
            $testExportResult = orders_sync_test_export_marketplace_order($runProfileId, $testOrderRef, $actor, $cfg);
            $flash = 'Тестовая выгрузка заказа в МойСклад завершена.';
        }
    }

    $moyskladAccounts = orders_sync_moysklad_account_list($cfg);
    $profiles = orders_sync_profile_list($cfg, $requestedMarketplace, $requestedConnectionId);
    $currentProfile = orders_sync_profile_resolve($requestedProfileId, $cfg, $requestedMarketplace, $requestedConnectionId);
    $currentProfileId = (int)($currentProfile['id'] ?? 0);
    $currentConnectionId = $requestedConnectionId;
    $currentConnection = $requestedConnection;
    $currentMarketplace = orders_sync_marketplace_normalize((string)($currentProfile['marketplace'] ?? $currentConnection['marketplace'] ?? 'ozon'));
    $currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
    $currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'orders_sync');
    $inlineProfileToolsId = $profileEditId > 0 ? $profileEditId : $currentProfileId;

    if ($copyProfileId > 0) {
        $copySourceProfile = orders_sync_profile_get($copyProfileId, $cfg);
        if (is_array($copySourceProfile)) {
            $copyMarketplace = trim((string)($copySourceProfile['marketplace'] ?? $currentMarketplace));
            $copyTargetConnections = array_values(array_filter(
                ozon_price_connection_list($cfg, $copyMarketplace !== '' ? $copyMarketplace : null),
                static fn(array $connection): bool => (int)($connection['id'] ?? 0) !== (int)($copySourceProfile['connection_id'] ?? 0)
            ));
            if ($copyTitle === '') {
                $copyTitle = 'Копия — ' . trim((string)($copySourceProfile['title'] ?? 'Профиль'));
            }
        }
    }

    if ($runId > 0) {
        $summary = orders_sync_run_get($runId, $cfg);
    }

    $runLogs = $currentProfileId > 0 ? orders_sync_run_recent(12, $currentProfileId, null, $cfg) : [];
    $recentOrders = $currentProfileId > 0
        ? orders_sync_orders_recent(40, $currentProfileId, null, $selectedSource === 'all' ? null : $selectedSource, $cfg)
        : [];
    $statusTotals = $currentProfileId > 0 ? orders_sync_status_totals($currentProfileId, null, $cfg) : [];

    if (isset($_GET['sync_already_running']) && $_GET['sync_already_running'] === '1') {
        $flash = 'По этому профилю синхронизация уже идёт. Открыт текущий запуск.';
    } elseif (isset($_GET['sync_started']) && $_GET['sync_started'] === '1') {
        $flash = 'Синхронизация запущена. Ход выполнения и лог обновляются ниже автоматически.';
    } elseif (isset($_GET['synced']) && $_GET['synced'] === '1') {
        if (is_array($summary)) {
            $totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
            $flash = 'Синхронизация завершена: fetched '
                . (int)($totals['fetched'] ?? 0)
                . ', new '
                . (int)($totals['inserted'] ?? 0)
                . ', updated '
                . (int)($totals['updated'] ?? 0)
                . '.';
        } else {
            $flash = 'Синхронизация завершена.';
        }
    } elseif (isset($_GET['export_already_running']) && $_GET['export_already_running'] === '1') {
        $flash = 'По этому профилю уже идёт запуск Orders Sync. Открыт текущий запуск.';
    } elseif (isset($_GET['export_started']) && $_GET['export_started'] === '1') {
        $flash = 'Выгрузка в МойСклад запущена. Ход выполнения и лог обновляются ниже автоматически.';
    } elseif (isset($_GET['create_already_running']) && $_GET['create_already_running'] === '1') {
        $flash = 'По этому профилю уже идёт запуск Orders Sync. Открыт текущий запуск.';
    } elseif (isset($_GET['create_started']) && $_GET['create_started'] === '1') {
        $flash = 'Запуск поиска и создания новых заказов начался. Ход выполнения и лог обновляются ниже автоматически.';
    } elseif (isset($_GET['status_already_running']) && $_GET['status_already_running'] === '1') {
        $flash = 'По этому профилю уже идёт запуск Orders Sync. Открыт текущий запуск.';
    } elseif (isset($_GET['status_started']) && $_GET['status_started'] === '1') {
        $flash = 'Запуск обновления статусов начался. Ход выполнения и лог обновляются ниже автоматически.';
    } elseif (isset($_GET['run_stop_requested']) && $_GET['run_stop_requested'] === '1') {
        $flash = 'Запрос на остановку отправлен. Текущий запуск завершит активный шаг и остановится.';
    } elseif (isset($_GET['exported']) && $_GET['exported'] === '1') {
        if (is_array($summary)) {
            $totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
            $flash = 'Выгрузка в МойСклад завершена: processed '
                . (int)($totals['scanned'] ?? 0)
                . ', created '
                . (int)($totals['created'] ?? 0)
                . ', updated '
                . (int)($totals['updated'] ?? 0)
                . ', skipped '
                . (int)($totals['skipped'] ?? 0)
                . ', errors '
                . (int)($totals['errors'] ?? 0)
                . '.';
        } else {
            $flash = 'Выгрузка в МойСклад завершена.';
        }
    } elseif (isset($_GET['profile_saved']) && $_GET['profile_saved'] === '1') {
        $flash = 'Профиль синхронизации сохранён.';
    } elseif (isset($_GET['profile_copied']) && $_GET['profile_copied'] === '1') {
        $flash = 'Профиль синхронизации скопирован.';
    } elseif (isset($_GET['profile_deleted']) && $_GET['profile_deleted'] === '1') {
        $flash = 'Профиль синхронизации удалён.';
    } elseif (isset($_GET['automation_saved']) && $_GET['automation_saved'] === '1') {
        $flash = 'Настройка автоматизации сохранена.';
    } elseif (isset($_GET['automation_deleted']) && $_GET['automation_deleted'] === '1') {
        $flash = 'Настройка автоматизации удалена.';
    } elseif (isset($_GET['moysklad_saved']) && $_GET['moysklad_saved'] === '1') {
        $flash = 'Аккаунт МойСклад сохранён.';
    }

    if ($profileEditId > 0) {
        $profileEditor = orders_sync_profile_get($profileEditId, $cfg) ?? $profileEditor;
    } elseif ($isNewProfileMode && $requestedConnectionId > 0) {
        $profileEditor = orders_sync_profile_default();
        $profileEditor['connection_id'] = $requestedConnectionId;
        $profileEditor['marketplace'] = $requestedMarketplace;
        $profileEditor['order_sources'] = array_keys(orders_sync_marketplace_order_source_options($requestedMarketplace));
        $profileEditor['order_sources_json'] = json_encode($profileEditor['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["fbs"]';
    } elseif (!$isNewProfileMode && is_array($currentProfile)) {
        $profileEditor = $currentProfile;
    }
    if ($moyskladEditId > 0) {
        $moyskladEditor = orders_sync_moysklad_account_get($moyskladEditId, $cfg) ?? $moyskladEditor;
    }
    if ($shouldLoadProfileEditorResources) {
        $refreshProfileEditorResources();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $runLogs = $currentProfileId > 0 ? orders_sync_run_recent(12, $currentProfileId, null, $cfg) : [];
    $recentOrders = $currentProfileId > 0
        ? orders_sync_orders_recent(40, $currentProfileId, null, $selectedSource === 'all' ? null : $selectedSource, $cfg)
        : [];
    $statusTotals = $currentProfileId > 0 ? orders_sync_status_totals($currentProfileId, null, $cfg) : [];
    if ($shouldLoadProfileEditorResources || $requestAction === 'save_sync_profile') {
        $refreshProfileEditorResources();
    }
    if ($requestAction === 'save_automation') {
        $draftProfileId = (int)($_POST['profile_id'] ?? 0);
        $draftProfile = orders_sync_profile_get($draftProfileId, $cfg);
        if (is_array($draftProfile)) {
            $automationDraftsByProfile[$draftProfileId][] = orders_sync_automation_input($_POST, $draftProfile);
        }
    }
}

$activeRunListsByProfile = orders_sync_run_active_list_map(array_map(
    static fn(array $profile): int => (int)($profile['id'] ?? 0),
    $profiles
), $cfg);
$activeRunsByProfile = [];
foreach ($activeRunListsByProfile as $profileId => $runs) {
    if (!is_array($runs) || !$runs) {
        continue;
    }
    $activeRunsByProfile[(int)$profileId] = $runs[0];
}
$profilesById = [];
foreach ($profiles as $profileRow) {
    if (!is_array($profileRow)) {
        continue;
    }
    $profilesById[(int)($profileRow['id'] ?? 0)] = $profileRow;
}
$automationsByProfile = orders_sync_automation_map(array_keys($profilesById), $profilesById, $cfg);
if ($automationNewProfileId > 0 && isset($profilesById[$automationNewProfileId])) {
    $automationsByProfile[$automationNewProfileId][] = orders_sync_automation_default($automationNewProfileId, $profilesById[$automationNewProfileId]);
}
foreach ($automationDraftsByProfile as $profileId => $drafts) {
    foreach ((array)$drafts as $draft) {
        $automationsByProfile[(int)$profileId][] = $draft;
    }
}
if ($runId <= 0 && $currentProfileId > 0 && isset($activeRunsByProfile[$currentProfileId])) {
    $summary = $activeRunsByProfile[$currentProfileId];
    $runId = (int)($summary['id'] ?? 0);
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function orders_sync_run_status_label(string $status): string
{
    return match ($status) {
        'success' => 'Успешно',
        'partial' => 'Частично',
        'error' => 'Ошибка',
        'running' => 'В работе',
        'stopping' => 'Останавливается',
        default => $status !== '' ? $status : '—',
    };
}

function orders_sync_run_status_class(string $status): string
{
    return match ($status) {
        'success' => 'status-ok',
        'partial' => 'status-warn',
        'error' => 'status-error',
        'running' => 'status-running',
        'stopping' => 'status-warn',
        default => 'status-neutral',
    };
}

function orders_sync_run_kind(array $summary): string
{
    return trim((string)($summary['kind'] ?? 'ozon_sync'));
}

function orders_sync_run_total_labels(array $summary): array
{
    $totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
    $kind = orders_sync_run_kind($summary);
    if (orders_sync_run_kind_is_moysklad_operation($kind)) {
        $refresh = is_array($summary['refresh'] ?? null) ? $summary['refresh'] : [];
        $marketplaceLabel = price_tool_marketplace_label((string)($summary['marketplace'] ?? 'ozon'));
        $labels = [
            $marketplaceLabel . ' fetched ' . (int)($refresh['fetched'] ?? 0),
            'candidates ' . (int)($totals['scanned'] ?? 0),
            'processed ' . (int)($totals['processed'] ?? 0),
        ];
        if ($kind === 'moysklad_create_orders') {
            $labels[] = 'created ' . (int)($totals['created'] ?? 0);
            $labels[] = 'linked ' . (int)($totals['linked'] ?? 0);
            $labels[] = 'skipped ' . (int)($totals['skipped'] ?? 0);
            $labels[] = 'errors ' . (int)($totals['errors'] ?? 0);
            return $labels;
        }
        if ($kind === 'moysklad_update_statuses') {
            $labels[] = 'updated ' . (int)($totals['updated'] ?? 0);
            $labels[] = 'linked ' . (int)($totals['linked'] ?? 0);
            $labels[] = 'skipped ' . (int)($totals['skipped'] ?? 0);
            $labels[] = 'errors ' . (int)($totals['errors'] ?? 0);
            return $labels;
        }
        $labels[] = 'created ' . (int)($totals['created'] ?? 0);
        $labels[] = 'updated ' . (int)($totals['updated'] ?? 0);
        $labels[] = 'skipped ' . (int)($totals['skipped'] ?? 0);
        $labels[] = 'errors ' . (int)($totals['errors'] ?? 0);
        return $labels;
    }
    return [
        'fetched ' . (int)($totals['fetched'] ?? 0),
        'new ' . (int)($totals['inserted'] ?? 0),
        'updated ' . (int)($totals['updated'] ?? 0),
        'errors ' . (int)($totals['errors'] ?? 0),
    ];
}

function orders_sync_run_kind_label(array $summary): string
{
    return match (orders_sync_run_kind($summary)) {
        'moysklad_create_orders' => 'Создание новых заказов',
        'moysklad_update_statuses' => 'Обновление статусов заказов',
        'moysklad_export' => 'Выгрузка в МойСклад',
        default => 'Синхронизация заказов',
    };
}

function orders_sync_run_dataset_label(array $summary): string
{
    if (!orders_sync_run_kind_is_moysklad_operation(orders_sync_run_kind($summary))) {
        return '';
    }
    $runId = (int)($summary['run_id'] ?? 0);
    $sourceRunId = (int)($summary['source_run_id'] ?? 0);
    if ($sourceRunId <= 0) {
        return '';
    }
    $label = $runId > 0 && $sourceRunId === $runId
        ? 'Источник: актуальные данные этого запуска'
        : 'Источник: sync run #' . $sourceRunId;
    $dateFrom = trim((string)($summary['source_sync_period_from'] ?? ''));
    $dateTo = trim((string)($summary['source_sync_period_to'] ?? ''));
    if ($dateFrom !== '' && $dateTo !== '') {
        $label .= ' · период ' . $dateFrom . ' → ' . $dateTo;
    }
    return $label;
}

function orders_sync_run_percent(array $summary): ?float
{
    $totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
    if (orders_sync_run_kind_is_moysklad_operation(orders_sync_run_kind($summary))) {
        $total = max(0, (int)($totals['scanned'] ?? 0));
        $done = (int)($totals['processed'] ?? 0);
        return $total > 0 ? round(min(100, ($done / $total) * 100), 1) : null;
    }

    $sources = is_array($summary['sources'] ?? null) ? $summary['sources'] : [];
    $total = count($sources);
    if ($total <= 0) {
        return null;
    }
    $done = 0;
    foreach ($sources as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!empty($item['error']) || (int)($item['fetched'] ?? 0) > 0 || (int)($item['inserted'] ?? 0) > 0 || (int)($item['updated'] ?? 0) > 0) {
            $done++;
        }
    }
    return round(min(100, ($done / $total) * 100), 1);
}

function orders_sync_payload_preview(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return '{}';
    }
    if (mb_strlen($json, 'UTF-8') > 5000) {
        return mb_substr($json, 0, 5000, 'UTF-8') . "\n...";
    }
    return $json;
}

function orders_sync_checkbox_checked(array $values, string $needle): string
{
    return in_array($needle, $values, true) ? 'checked' : '';
}

function orders_sync_automation_last_run_label(array $automation, ?array $activeProfileRun = null): string
{
    if (is_array($activeProfileRun)) {
        return 'Сейчас выполняется · run #' . (int)($activeProfileRun['id'] ?? 0);
    }
    $lastRunAt = trim((string)($automation['last_run_at'] ?? ''));
    if ($lastRunAt === '') {
        return 'Пока не запускалась';
    }
    try {
        $lastRunAt = (new DateTimeImmutable($lastRunAt, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Moscow'))
            ->format('d.m.Y H:i') . ' МСК';
    } catch (Throwable $e) {
        // Keep the raw DB value if the timestamp is malformed.
    }
    $runId = (int)($automation['last_run_run_id'] ?? 0);
    return $lastRunAt . ($runId > 0 ? ' · run #' . $runId : '');
}

function orders_sync_automation_next_run_label(array $automation, ?array $activeProfileRun = null): string
{
    if (is_array($activeProfileRun)) {
        return 'Повторный запуск ждёт завершения текущей операции';
    }
    if (empty($automation['enabled'])) {
        return 'Автоматизация выключена';
    }
    $nowMsk = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $slot = orders_sync_automation_slot_info($automation, $nowMsk);
    $lastSlotKey = trim((string)($automation['last_run_slot_key'] ?? ''));
    if ($lastSlotKey !== '' && $lastSlotKey === (string)($slot['slot_key'] ?? '')) {
        $nextAt = $slot['slot_end'] ?? null;
        if ($nextAt instanceof DateTimeImmutable) {
            return 'Следующий запуск: ' . $nextAt->format('d.m H:i') . ' МСК';
        }
    }
    $slotEnd = $slot['slot_end'] ?? null;
    if ($slotEnd instanceof DateTimeImmutable) {
        return 'Ожидает запуск в текущем интервале до ' . $slotEnd->format('H:i') . ' МСК';
    }
    return 'Ожидает ближайший запуск по МСК';
}

function orders_sync_render_status_mapping_section(array $statusItems, array $profileEditor, ?array $profileEditorMoyskladAccount, array $profileMoyskladOptions): void
{
    if (!$statusItems) {
        return;
    }
    $marketplaceLabel = price_tool_marketplace_label((string)($profileEditor['marketplace'] ?? 'ozon'));
    ?>
    <div class="status-mapping-table">
      <div class="status-mapping-head">
        <span>Статус <?= h($marketplaceLabel) ?></span>
        <span>Статус нового заказа</span>
        <span>Статус для обновления</span>
      </div>
      <?php foreach ($statusItems as $statusItem): ?>
        <?php
          $statusCode = (string)($statusItem['code'] ?? '');
          if ($statusCode === '') {
              continue;
          }
          $createSelected = is_array($profileEditorMoyskladAccount)
              ? orders_sync_profile_status_mapping_resolved_value($profileEditor, $profileEditorMoyskladAccount, $statusCode, 'create')
              : '';
          $updateSelected = is_array($profileEditorMoyskladAccount)
              ? orders_sync_profile_status_mapping_resolved_value($profileEditor, $profileEditorMoyskladAccount, $statusCode, 'update')
              : '';
        ?>
        <div class="status-mapping-row">
          <input type="hidden" name="status_mapping_statuses[]" value="<?= h($statusCode) ?>">
          <div class="status-cell">
            <span class="status-cell-title"><?= h((string)($statusItem['label'] ?? $statusCode)) ?></span>
            <span class="status-cell-code"><?= h($statusCode) ?></span>
          </div>
          <label>
            <span>Статус нового заказа</span>
            <select name="status_mapping_create_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
              <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
              <option value="<?= h(orders_sync_status_create_new_order_token()) ?>" <?= ($createSelected === orders_sync_status_create_new_order_token()) ? 'selected' : '' ?>>НОВЫЙ ЗАКАЗ</option>
              <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                <option value="<?= h((string)$option['id']) ?>" <?= ($createSelected === (string)$option['id']) ? 'selected' : '' ?>>
                  <?= h((string)$option['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Статус для обновления</span>
            <select name="status_mapping_update_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
              <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задано' : 'Сначала выбери аккаунт МойСклад' ?></option>
              <option value="<?= h(orders_sync_status_update_keep_token()) ?>" <?= ($updateSelected === orders_sync_status_update_keep_token()) ? 'selected' : '' ?>>Не менять статус</option>
              <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                <option value="<?= h((string)$option['id']) ?>" <?= ($updateSelected === (string)$option['id']) ? 'selected' : '' ?>>
                  <?= h((string)$option['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

function orders_sync_render_cancelled_transition_section(array $stateOptions, array $profileEditor, ?array $profileEditorMoyskladAccount): void
{
    $rows = [];
    $map = is_array($profileEditor['cancelled_transition_map'] ?? null) ? $profileEditor['cancelled_transition_map'] : [];
    foreach ($map as $sourceStateId => $targetStateId) {
        $sourceStateId = trim((string)$sourceStateId);
        $targetStateId = trim((string)$targetStateId);
        if ($sourceStateId === '' || $targetStateId === '') {
            continue;
        }
        $rows[] = [
            'source_state_id' => $sourceStateId,
            'target_state_id' => $targetStateId,
        ];
    }
    if (!$rows) {
        $rows[] = [
            'source_state_id' => '',
            'target_state_id' => '',
        ];
    }
    ?>
    <div class="status-mapping-subsection">
      <div class="status-mapping-subtitle">Обработка отмененных заказов</div>
      <div class="muted" style="margin-bottom:14px;">Если маркетплейс прислал статус отмены, можно отдельно задать, во что переводить заказ в зависимости от его текущего статуса в МойСклад. Эти правила имеют приоритет над обычным сопоставлением статуса отмены.</div>
      <div class="form-grid" style="margin-bottom:14px;">
        <label>
          <span>По умолчанию для отмененных заказов</span>
          <select name="cancelled_transition_default_state_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
            <option value="" <?= ((string)($profileEditor['cancelled_transition_default_state_id'] ?? '') === '') ? 'selected' : '' ?>>Использовать обычное сопоставление cancelled</option>
            <option value="<?= h(orders_sync_status_update_keep_token()) ?>" <?= ((string)($profileEditor['cancelled_transition_default_state_id'] ?? '') === orders_sync_status_update_keep_token()) ? 'selected' : '' ?>>Не менять статус</option>
            <?php foreach ($stateOptions as $option): ?>
              <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['cancelled_transition_default_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                <?= h((string)$option['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div style="align-self:end;">
          <span>Сумма отмененного заказа</span>
          <label class="inline-checkbox">
            <input type="checkbox" name="cancelled_before_ship_zero_prices" value="1" <?= !empty($profileEditor['cancelled_before_ship_zero_prices']) ? 'checked' : '' ?>>
            <span>Обнулять сумму, если заказ отменен до отправки</span>
          </label>
        </div>
      </div>
      <div class="cancel-transition-table">
        <div class="cancel-transition-head">
          <span>Текущий статус в МойСклад</span>
          <span>Что ставить при отмене</span>
          <span></span>
        </div>
        <div class="stack" id="cancel-transition-rows">
        <?php foreach ($rows as $row): ?>
          <?php
            $sourceStateId = trim((string)($row['source_state_id'] ?? ''));
            $selectedValue = trim((string)($row['target_state_id'] ?? ''));
          ?>
          <div class="cancel-transition-row">
            <label>
              <span>Текущий статус в МойСклад</span>
              <select name="cancel_transition_source_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери текущий статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
                <?php foreach ($stateOptions as $sourceOption): ?>
                  <option value="<?= h((string)$sourceOption['id']) ?>" <?= ($sourceStateId === (string)$sourceOption['id']) ? 'selected' : '' ?>>
                    <?= h((string)$sourceOption['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span>Что ставить при отмене</span>
              <select name="cancel_transition_target_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери целевой статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
                <option value="<?= h(orders_sync_status_update_keep_token()) ?>" <?= ($selectedValue === orders_sync_status_update_keep_token()) ? 'selected' : '' ?>>Не менять статус</option>
                <?php foreach ($stateOptions as $targetOption): ?>
                  <option value="<?= h((string)$targetOption['id']) ?>" <?= ($selectedValue === (string)$targetOption['id']) ? 'selected' : '' ?>>
                    <?= h((string)$targetOption['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="cancel-transition-actions">
              <button type="button" class="button-link secondary js-remove-cancel-row">Убрать</button>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div>
          <button type="button" class="button-link secondary" id="add-cancel-transition-row" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>Добавить правило</button>
        </div>
      </div>
      <template id="cancel-transition-row-template">
        <div class="cancel-transition-row">
          <label>
            <span>Текущий статус в МойСклад</span>
            <select name="cancel_transition_source_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
              <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери текущий статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
              <?php foreach ($stateOptions as $sourceOption): ?>
                <option value="<?= h((string)$sourceOption['id']) ?>"><?= h((string)$sourceOption['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Что ставить при отмене</span>
            <select name="cancel_transition_target_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
              <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери целевой статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
              <option value="<?= h(orders_sync_status_update_keep_token()) ?>">Не менять статус</option>
              <?php foreach ($stateOptions as $targetOption): ?>
                <option value="<?= h((string)$targetOption['id']) ?>"><?= h((string)$targetOption['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="cancel-transition-actions">
            <button type="button" class="button-link secondary js-remove-cancel-row">Убрать</button>
          </div>
        </div>
      </template>
    </div>
    <?php
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Orders Sync</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --card: #ffffff;
      --border: #d9e5f2;
      --text: #17233a;
      --muted: #61738d;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
      --profiles-bg: linear-gradient(180deg, #f7fbff 0%, #eef5ff 100%);
      --profiles-border: #cfe0f7;
      --moysklad-bg: linear-gradient(180deg, #fcfbf7 0%, #f7f3ea 100%);
      --moysklad-border: #eadfc8;
      --ok-bg: #edfdf3;
      --ok-text: #166534;
      --warn-bg: #fffbeb;
      --warn-text: #92400e;
      --err-bg: #fff1f2;
      --err-text: #b42318;
      --run-bg: #eff6ff;
      --run-text: #1d4ed8;
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
    .topbar, .page, .flash, .error {
      max-width: 1420px;
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
    .card.card-profiles {
      background: var(--profiles-bg);
      border-color: var(--profiles-border);
    }
    .card.card-moysklad {
      background: var(--moysklad-bg);
      border-color: var(--moysklad-border);
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
    .error { background: var(--err-bg); color: var(--err-text); border-color: #fecdd3; }
    .button-link, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 16px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 800;
      letter-spacing: -.01em;
      cursor: pointer;
      transition: transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
    }
    .button-link:hover, button:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 22px rgba(15, 23, 42, 0.12);
    }
    .button-link.secondary, button.secondary {
      background: #fff;
      color: var(--text);
      border-color: #d5e2f0;
      box-shadow: none;
    }
    .button-link.secondary:hover, button.secondary:hover {
      border-color: #bfd2e8;
      background: #f8fbff;
      box-shadow: 0 8px 18px rgba(148, 163, 184, 0.14);
    }
    .button-link.danger, button.danger {
      background: #fff7f7;
      color: #a12828;
      border-color: #f1c7c7;
      box-shadow: none;
    }
    .button-link.danger:hover, button.danger:hover {
      background: #fff1f1;
      border-color: #e9b3b3;
      box-shadow: 0 8px 18px rgba(185, 28, 28, 0.08);
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
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(360px, .9fr);
      gap: 18px;
    }
    .hero-card, .list-card {
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 18px;
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
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
      line-height: 1.05;
      font-weight: 800;
      letter-spacing: -0.03em;
      margin-bottom: 6px;
    }
    .current-connection-meta {
      color: var(--muted);
      font-size: 15px;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      align-items: start;
    }
    .form-grid.three {
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, .8fr);
    }
    .status-mapping-table {
      display: grid;
      gap: 10px;
    }
    .status-mapping-subsection + .status-mapping-subsection {
      margin-top: 18px;
      padding-top: 18px;
      border-top: 1px solid #e3edf8;
    }
    .status-mapping-subtitle {
      margin: 0 0 12px;
      font-size: 16px;
      font-weight: 800;
      color: #17233a;
    }
    .status-mapping-head,
    .status-mapping-row {
      display: grid;
      grid-template-columns: minmax(260px, 1.1fr) minmax(240px, 1fr) minmax(240px, 1fr);
      gap: 12px;
      align-items: start;
    }
    .status-mapping-head {
      padding: 0 4px;
    }
    .status-mapping-head span {
      display: block;
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
    }
    .status-mapping-row {
      padding: 12px;
      border: 1px solid #dbe7f5;
      border-radius: 18px;
      background: linear-gradient(180deg, #fbfdff 0%, #f4f8fe 100%);
    }
    .status-mapping-row label span {
      display: none;
    }
    .cancel-transition-table {
      display: grid;
      gap: 10px;
    }
    .cancel-transition-head,
    .cancel-transition-row {
      display: grid;
      grid-template-columns: minmax(220px, 1fr) minmax(280px, 1.2fr) auto;
      gap: 12px;
      align-items: start;
    }
    .cancel-transition-head {
      padding: 0 4px;
    }
    .cancel-transition-head span {
      display: block;
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
    }
    .cancel-transition-row {
      padding: 12px;
      border: 1px solid #dbe7f5;
      border-radius: 18px;
      background: linear-gradient(180deg, #fbfdff 0%, #f4f8fe 100%);
    }
    .cancel-transition-row label span {
      display: none;
    }
    .cancel-transition-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      min-height: 46px;
    }
    .status-cell {
      display: flex;
      flex-direction: column;
      justify-content: center;
      min-height: 46px;
      padding: 10px 14px;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
    }
    .status-cell-title {
      font-size: 15px;
      font-weight: 700;
      color: var(--text);
    }
    .status-cell-code {
      margin-top: 2px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 600;
    }
    label span {
      display: block;
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 14px;
    }
    .required-mark {
      display: inline;
      color: #b42318;
      font-weight: 800;
      margin-left: 4px;
      line-height: 1;
      vertical-align: baseline;
    }
    .warehouse-mapping-head {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1fr);
      gap: 12px;
      margin-top: 8px;
      padding: 0 4px;
    }
    .warehouse-mapping-head span {
      display: block;
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
    }
    input[type="text"], input[type="password"], input[type="number"], select, textarea {
      width: 100%;
      min-height: 46px;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px 14px;
      font: inherit;
      background: #fff;
      color: var(--text);
    }
    textarea { min-height: 90px; resize: vertical; }
    .static-value {
      width: 100%;
      min-height: 46px;
      display: flex;
      align-items: center;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px 14px;
      background: #f8fbff;
      color: var(--text);
      font-weight: 600;
    }
    .checkbox-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      padding-top: 10px;
    }
    .checkbox-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 999px;
      background: #fff;
    }
    .checkbox-chip.is-primary {
      border-color: #b7ebc6;
      background: #f5fff8;
    }
    .toolbar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-top: 16px;
    }
    .stat {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 14px;
      background: #fff;
    }
    .stat b {
      display: block;
      font-size: 26px;
      margin-top: 6px;
    }
    .status-chip {
      display: inline-flex;
      align-items: center;
      min-height: 32px;
      padding: 0 10px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-size: 13px;
      font-weight: 700;
    }
    .status-ok { background: var(--ok-bg); color: var(--ok-text); border-color: #b7ebc6; }
    .status-warn { background: var(--warn-bg); color: var(--warn-text); border-color: #fbd38d; }
    .status-error { background: var(--err-bg); color: var(--err-text); border-color: #fecdd3; }
    .status-running { background: var(--run-bg); color: var(--run-text); border-color: #bfdbfe; }
    .status-neutral { background: #f8fafc; color: #475569; border-color: #dbe4ef; }
    .summary-pills, .source-stats {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 10px;
    }
    .summary-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 10px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      font-size: 13px;
      font-weight: 700;
    }
    .stack {
      display: grid;
      gap: 12px;
    }
    .item {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    }
    .item-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 10px;
    }
    .item-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .item-actions-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .item-actions-group + .item-actions-group {
      padding-left: 10px;
      margin-left: 2px;
    }
    .item-actions .button-link,
    .item-actions button {
      min-height: 42px;
      padding: 0 20px;
      border-radius: 999px;
      font-size: 15px;
      white-space: nowrap;
    }
    .item-actions-group.operations .button-link.secondary,
    .item-actions-group.operations button.secondary {
      background: linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
      border-color: #cadcf0;
      color: #1f3150;
    }
    .item-actions-group.operations .button-link.secondary:hover,
    .item-actions-group.operations button.secondary:hover {
      background: linear-gradient(180deg, #ffffff 0%, #eef5ff 100%);
      border-color: #b8d0ea;
    }
    .item-actions-group.profile-tools .button-link.secondary,
    .item-actions-group.profile-tools button.secondary {
      min-height: 40px;
      padding: 0 16px;
      background: #f8fbff;
      color: #52657f;
      border-color: #dce7f3;
      font-size: 14px;
      font-weight: 700;
      box-shadow: none;
    }
    .item-actions-group.profile-tools .button-link.secondary:hover,
    .item-actions-group.profile-tools button.secondary:hover {
      background: #f1f6fc;
      color: #334862;
      border-color: #cbdbea;
      box-shadow: 0 6px 14px rgba(148, 163, 184, 0.10);
    }
    .item-actions-group.profile-tools .button-link.danger,
    .item-actions-group.profile-tools button.danger {
      min-height: 40px;
      padding: 0 16px;
      font-size: 14px;
    }
    .item.item-moysklad {
      background: linear-gradient(180deg, #fffdfa 0%, #ffffff 100%);
      border-color: #e7dcc6;
    }
    .automation-section {
      margin-top: 16px;
      padding-top: 16px;
      border-top: 1px solid #e1ebf7;
      display: grid;
      gap: 12px;
    }
    .automation-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }
    .automation-title {
      font-size: 16px;
      font-weight: 800;
      color: #17233a;
    }
    .automation-list {
      display: grid;
      gap: 12px;
    }
    .automation-row {
      border: 1px solid #d9e6f5;
      border-radius: 18px;
      background: linear-gradient(180deg, #fbfdff 0%, #f5f9ff 100%);
      padding: 12px 14px;
      display: grid;
      gap: 10px;
    }
    .automation-row-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }
    .automation-row-meta {
      color: var(--muted);
      font-size: 13px;
      font-weight: 600;
    }
    .automation-grid {
      display: grid;
      grid-template-columns: minmax(220px, 1.1fr) minmax(120px, .48fr) minmax(220px, .95fr) minmax(150px, .55fr) auto auto;
      gap: 10px;
      align-items: end;
    }
    .automation-grid select,
    .automation-grid input[type="number"],
    .automation-grid input[type="time"] {
      min-height: 40px;
      padding: 8px 12px;
      border-radius: 12px;
      font-size: 15px;
    }
    .automation-grid input[type="time"] {
      width: 100%;
    }
    .automation-sources {
      display: grid;
      gap: 6px;
      align-content: start;
      min-width: 94px;
    }
    .automation-source-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 34px;
      padding: 0 10px;
      border: 1px solid #d7e3f1;
      border-radius: 12px;
      background: #fff;
      color: #41556f;
      font-weight: 700;
      font-size: 13px;
    }
    .automation-source-chip input {
      width: 14px;
      height: 14px;
      margin: 0;
    }
    .automation-toggle {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      min-height: 40px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid #bfe6cd;
      background: #effcf4;
      color: #1f7a44;
      font-weight: 800;
      white-space: nowrap;
    }
    .automation-toggle.is-off {
      border-color: #d7e3f1;
      background: #fff;
      color: #60748f;
    }
    .automation-toggle input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .automation-toggle-track {
      position: relative;
      width: 38px;
      height: 22px;
      border-radius: 999px;
      background: #cbd5e1;
      transition: background .15s ease;
      flex: 0 0 auto;
    }
    .automation-toggle-track::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.18);
      transition: transform .15s ease;
    }
    .automation-toggle input:checked + .automation-toggle-track {
      background: #22c55e;
    }
    .automation-toggle input:checked + .automation-toggle-track::after {
      transform: translateX(16px);
    }
    .automation-toggle-label {
      line-height: 1;
    }
    .automation-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
      min-height: 40px;
    }
    .automation-icon-button {
      width: 44px;
      min-width: 44px;
      height: 44px;
      min-height: 44px;
      padding: 0;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .automation-icon-button svg {
      width: 20px;
      height: 20px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .split {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 18px;
      align-items: start;
    }
    details { margin-top: 12px; }
    pre {
      margin: 0;
      padding: 14px;
      border-radius: 16px;
      background: #0f172a;
      color: #e2e8f0;
      overflow: auto;
      font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .source-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 16px;
    }
    .mini-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-top: 10px;
    }
    .mini-grid > div {
      border: 1px solid #e4edf7;
      border-radius: 14px;
      padding: 10px 12px;
      background: #fff;
    }
    @media (max-width: 1180px) {
      .hero, .split {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 780px) {
      .current-connection-title {
        font-size: 26px;
      }
      .form-grid, .form-grid.three, .stats-grid, .source-grid, .mini-grid {
        grid-template-columns: 1fr;
      }
      .status-mapping-head {
        display: none;
      }
      .status-mapping-row {
        grid-template-columns: 1fr;
      }
      .status-mapping-row label span {
        display: block;
      }
      .cancel-transition-head {
        display: none;
      }
      .cancel-transition-row {
        grid-template-columns: 1fr;
      }
      .cancel-transition-row label span {
        display: block;
      }
      .cancel-transition-actions {
        justify-content: flex-start;
        min-height: 0;
      }
      .warehouse-mapping-head {
        display: none;
      }
      .automation-grid {
        grid-template-columns: 1fr;
      }
      .automation-sources {
        min-width: 0;
      }
    }
  </style>
</head>
<body>
  <?php if (ft_is_staging_env($cfg)): ?>
    <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?></div>
  <?php endif; ?>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'marketplace_connections.php' . ($currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : ''), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">Orders Sync</h1>
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
        <a class="tab-link" href="ozon_price_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Price Tool</a>
        <a class="tab-link active" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : ($currentProfileId > 0 ? '?profile_id=' . urlencode((string)$currentProfileId) : '') ?>">Orders Sync</a>
        <a class="tab-link" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Stocks Tool</a>
        <?php if ($currentMarketplace === 'ozon'): ?><a class="tab-link" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a><?php endif; ?>
        <?php if (price_tool_connection_supports($currentConnection, 'fbo_tool')): ?><a class="tab-link" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="page">
    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($runId > 0 && is_array($summary)): ?>
      <?php $selectedRunSummary = is_array($summary['summary'] ?? null) ? $summary['summary'] : $summary; ?>
      <?php $selectedRunPercent = orders_sync_run_percent($selectedRunSummary); ?>
      <div class="list-card" id="run-progress-card" data-run-id="<?= h((string)$runId) ?>" data-run-status="<?= h((string)($summary['status'] ?? '')) ?>">
        <div class="item-top" style="margin-bottom:12px;">
          <div>
            <h3 style="margin:0;">Текущий запуск</h3>
            <div class="muted">
              <?= h(orders_sync_run_kind_label($selectedRunSummary)) ?>
              · Run #<?= h((string)($summary['id'] ?? $runId)) ?>
              · <?= h((string)($summary['started_at'] ?? '')) ?>
            </div>
            <?php if (orders_sync_run_dataset_label($selectedRunSummary) !== ''): ?>
              <div class="muted" style="margin-top:4px;"><?= h(orders_sync_run_dataset_label($selectedRunSummary)) ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <span id="run-progress-status" class="status-chip <?= h(orders_sync_run_status_class((string)($summary['status'] ?? ''))) ?>"><?= h(orders_sync_run_status_label((string)($summary['status'] ?? ''))) ?></span>
            <span id="run-progress-percent" class="summary-pill"><?= $selectedRunPercent !== null ? h((string)$selectedRunPercent . '%') : 'в работе' ?></span>
            <?php if (in_array((string)($summary['status'] ?? ''), ['running', 'stopping'], true)): ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="stop_run">
                <input type="hidden" name="run_id" value="<?= h((string)$runId) ?>">
                <button type="submit" class="button-link secondary" <?= (string)($summary['status'] ?? '') === 'stopping' ? 'disabled' : '' ?>>
                  <?= (string)($summary['status'] ?? '') === 'stopping' ? 'Остановка запрошена' : 'Остановить' ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <div id="run-progress-pills" class="summary-pills">
          <?php foreach (orders_sync_run_total_labels($selectedRunSummary) as $pill): ?>
            <span class="summary-pill"><?= h($pill) ?></span>
          <?php endforeach; ?>
        </div>
        <details open style="margin-top:12px;">
          <summary>Показать текущий лог</summary>
          <pre id="run-progress-log"><?= h((string)($summary['log_text'] ?? '')) ?></pre>
        </details>
      </div>
    <?php endif; ?>

    <div class="card card-profiles">
      <div class="toolbar">
        <div>
          <h2>Профили синхронизации</h2>
          <div class="muted">Главный объект этой страницы: профиль связывает аккаунт маркетплейса, аккаунт МойСклад и настройки запуска.</div>
        </div>
        <?php if ($currentConnectionId > 0): ?>
          <a class="button-link secondary" href="orders_sync.php?new_profile=1&amp;connection_id=<?= h((string)$currentConnectionId) ?>">Добавить профиль</a>
        <?php endif; ?>
      </div>

      <div class="stack" style="margin-bottom:18px;">
        <?php if (!$profiles): ?>
          <div class="muted"><?= $currentConnectionId > 0 ? 'Для этого кабинета пока нет профилей синхронизации.' : 'Пока нет профилей синхронизации.' ?></div>
          <?php if ($currentConnectionId > 0): ?>
            <div>
              <a class="button-link secondary" href="orders_sync.php?new_profile=1&amp;connection_id=<?= h((string)$currentConnectionId) ?>">Добавить первый профиль</a>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($profiles as $profile): ?>
            <?php $profileId = (int)($profile['id'] ?? 0); ?>
            <?php $activeProfileRuns = array_values(array_filter($activeRunListsByProfile[$profileId] ?? [], static fn($row): bool => is_array($row))); ?>
            <?php $activeProfileRun = $activeProfileRuns[0] ?? null; ?>
            <?php $activeProfileSummary = is_array($activeProfileRun['summary'] ?? null) ? $activeProfileRun['summary'] : []; ?>
            <?php $activeFullRun = orders_sync_run_conflicting_from_list($activeProfileRuns, 'moysklad_export'); ?>
            <?php $activeCreateRun = orders_sync_run_conflicting_from_list($activeProfileRuns, 'moysklad_create_orders'); ?>
            <?php $activeStatusRun = orders_sync_run_conflicting_from_list($activeProfileRuns, 'moysklad_update_statuses'); ?>
            <?php $profileAutomations = array_values(array_filter($automationsByProfile[$profileId] ?? [], static fn($row): bool => is_array($row))); ?>
            <div class="item">
              <div class="item-top">
                <div>
                  <strong><?= h((string)$profile['title']) ?></strong>
                  <div class="muted">
                    <?= h((string)($profile['connection_title'] ?? '—')) ?>
                    · МойСклад: <?= h((string)($profile['moysklad_title'] ?? 'не выбран')) ?>
                  </div>
                  <?php if (is_array($activeProfileRun)): ?>
                    <div class="muted" style="margin-top:6px;">
                      <?php if (count($activeProfileRuns) > 1): ?>
                        Сейчас выполняется <?= h((string)count($activeProfileRuns)) ?> операций
                        · последний run #<?= h((string)($activeProfileRun['id'] ?? '')) ?>
                      <?php else: ?>
                        Сейчас выполняется <?= h(orders_sync_run_kind_label($activeProfileSummary)) ?>
                        · Run #<?= h((string)($activeProfileRun['id'] ?? '')) ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
	                <div class="item-actions">
	                  <div class="item-actions-group operations">
	                    <?php if (is_array($activeFullRun)): ?>
	                      <a class="button-link secondary" href="orders_sync.php?profile_id=<?= h((string)$profileId) ?>&run_id=<?= h((string)($activeFullRun['id'] ?? 0)) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Открыть полную выгрузку</a>
	                    <?php else: ?>
		                      <form method="post" style="margin:0;" onsubmit="return confirm('Запустить обновление заказов из маркетплейса и затем выгрузку этого профиля в МойСклад?');">
	                        <input type="hidden" name="action" value="bulk_export_moysklad">
	                        <input type="hidden" name="profile_id" value="<?= h((string)$profile['id']) ?>">
	                        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
	                        <button type="submit">Полная выгрузка</button>
	                      </form>
	                    <?php endif; ?>
	                    <?php if (is_array($activeCreateRun)): ?>
	                      <a class="button-link secondary" href="orders_sync.php?profile_id=<?= h((string)$profileId) ?>&run_id=<?= h((string)($activeCreateRun['id'] ?? 0)) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Открыть создание</a>
	                    <?php else: ?>
	                      <form method="post" style="margin:0;">
	                        <input type="hidden" name="action" value="bulk_create_moysklad">
	                        <input type="hidden" name="profile_id" value="<?= h((string)$profile['id']) ?>">
	                        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
	                        <button type="submit" class="secondary">Создать новые</button>
	                      </form>
	                    <?php endif; ?>
	                    <?php if (is_array($activeStatusRun)): ?>
	                      <a class="button-link secondary" href="orders_sync.php?profile_id=<?= h((string)$profileId) ?>&run_id=<?= h((string)($activeStatusRun['id'] ?? 0)) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Открыть статусы</a>
	                    <?php else: ?>
	                      <form method="post" style="margin:0;">
	                        <input type="hidden" name="action" value="bulk_update_statuses_moysklad">
	                        <input type="hidden" name="profile_id" value="<?= h((string)$profile['id']) ?>">
	                        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
	                        <button type="submit" class="secondary">Обновить статусы</button>
	                      </form>
	                    <?php endif; ?>
	                  </div>
                  <div class="item-actions-group profile-tools">
                    <a class="button-link secondary" href="orders_sync.php?copy_profile_id=<?= h((string)$profile['id']) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Скопировать</a>
                    <a class="button-link secondary" href="orders_sync.php?profile_edit_id=<?= h((string)$profile['id']) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Редактировать</a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Удалить этот профиль синхронизации и все его данные Orders Sync?');">
                      <input type="hidden" name="action" value="delete_sync_profile">
                      <input type="hidden" name="profile_id" value="<?= h((string)$profile['id']) ?>">
                      <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                      <button type="submit" class="danger">Удалить</button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="summary-pills">
                <span class="summary-pill">период <?= h((string)($profile['sync_date_from'] ?? '')) ?> → <?= h((string)($profile['sync_date_to'] ?? '')) ?></span>
                <?php foreach ((array)($profile['order_sources'] ?? []) as $source): ?>
                  <span class="summary-pill"><?= h(strtoupper((string)$source)) ?></span>
                <?php endforeach; ?>
              </div>
              <?php if (trim((string)($profile['notes'] ?? '')) !== ''): ?>
                <div class="muted" style="margin-top:10px;"><?= h((string)$profile['notes']) ?></div>
              <?php endif; ?>
              <div class="automation-section">
                <div class="automation-toolbar">
                  <div>
                    <div class="automation-title">Автоматизация профиля</div>
                    <div class="muted">Здесь можно настроить отдельные расписания для полной выгрузки, создания новых заказов и обновления статусов.</div>
                  </div>
                  <a class="button-link secondary" href="orders_sync.php?automation_new_profile_id=<?= h((string)$profileId) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?><?= $currentProfileId > 0 ? '&profile_id=' . urlencode((string)$currentProfileId) : '' ?>">Добавить автоматизацию</a>
                </div>
                <?php if (!$profileAutomations): ?>
                  <div class="muted">Для этого профиля пока нет строк автоматизации.</div>
                <?php else: ?>
                  <div class="automation-list">
                    <?php foreach ($profileAutomations as $automation): ?>
                      <?php
                        $automationId = (int)($automation['id'] ?? 0);
                        $automationSources = (array)($automation['order_sources'] ?? []);
                        $automationActiveRun = orders_sync_run_conflicting_from_list(
                            $activeProfileRuns,
                            orders_sync_moysklad_operation_kind((string)($automation['operation_key'] ?? 'full'))
                        );
                      ?>
                      <form method="post" class="automation-row">
                        <input type="hidden" name="profile_id" value="<?= h((string)$profileId) ?>">
                        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                        <input type="hidden" name="automation_id" value="<?= h((string)$automationId) ?>">
                        <div class="automation-row-head">
                          <div>
                            <strong><?= h(orders_sync_moysklad_operation_label((string)($automation['operation_key'] ?? 'full'))) ?></strong>
                            <div class="automation-row-meta">Последний запуск: <?= h(orders_sync_automation_last_run_label($automation, $automationActiveRun)) ?></div>
                            <div class="automation-row-meta"><?= h(orders_sync_automation_next_run_label($automation, $automationActiveRun)) ?></div>
                          </div>
                          <label class="automation-toggle <?= !empty($automation['enabled']) ? '' : 'is-off' ?>">
                            <input type="checkbox" name="enabled" value="1" <?= !empty($automation['enabled']) ? 'checked' : '' ?>>
                            <span class="automation-toggle-track" aria-hidden="true"></span>
                            <span class="automation-toggle-label"><?= !empty($automation['enabled']) ? 'Включена' : 'Выключена' ?></span>
                          </label>
                        </div>
                        <div class="automation-grid">
                          <label>
                            <span>Операция</span>
                            <select name="operation_key">
                              <?php foreach (orders_sync_moysklad_operation_options() as $operationKey => $operationMeta): ?>
                                <option value="<?= h($operationKey) ?>" <?= ((string)($automation['operation_key'] ?? '') === $operationKey) ? 'selected' : '' ?>>
                                  <?= h((string)($operationMeta['label'] ?? $operationKey)) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label>
                            <span>Период заказов, дней</span>
                            <input type="number" name="period_days" min="1" max="90" value="<?= h((string)($automation['period_days'] ?? 1)) ?>">
                          </label>
                          <label>
                            <span>Периодичность запусков</span>
                            <select name="frequency_key">
                              <?php foreach (orders_sync_automation_frequency_options() as $frequencyKey => $frequencyMeta): ?>
                                <option value="<?= h($frequencyKey) ?>" <?= ((string)($automation['frequency_key'] ?? '') === $frequencyKey) ? 'selected' : '' ?>>
                                  <?= h((string)($frequencyMeta['label'] ?? $frequencyKey)) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label>
                            <span>Время начала запусков</span>
                            <input type="time" name="run_time_msk" step="300" value="<?= h(orders_sync_automation_run_time_value($automation)) ?>">
                          </label>
                          <div class="automation-sources">
                            <?php foreach (orders_sync_marketplace_order_source_options((string)($profile['marketplace'] ?? $currentMarketplace)) as $sourceKey => $sourceMeta): ?>
                              <label class="automation-source-chip">
                                <input type="checkbox" name="order_sources[]" value="<?= h((string)$sourceKey) ?>" <?= orders_sync_checkbox_checked($automationSources, (string)$sourceKey) ?>>
                                <span><?= h((string)($sourceMeta['label'] ?? strtoupper((string)$sourceKey))) ?></span>
                              </label>
                            <?php endforeach; ?>
                          </div>
                          <div class="automation-actions">
                            <button type="submit" name="action" value="save_automation" class="secondary automation-icon-button" title="Сохранить автоматизацию" aria-label="Сохранить автоматизацию">
                              <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 4h11l3 3v13H5z"></path>
                                <path d="M8 4v6h8V4"></path>
                                <path d="M9 18h6"></path>
                              </svg>
                            </button>
                            <?php if ($automationId > 0): ?>
                              <button type="submit" name="action" value="delete_automation" class="danger automation-icon-button" onclick="return confirm('Удалить эту настройку автоматизации?');" title="Удалить автоматизацию" aria-label="Удалить автоматизацию">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                  <path d="M4 7h16"></path>
                                  <path d="M10 11v6"></path>
                                  <path d="M14 11v6"></path>
                                  <path d="M6 7l1 12h10l1-12"></path>
                                  <path d="M9 7V4h6v3"></path>
                                </svg>
                              </button>
                            <?php else: ?>
                              <a class="button-link secondary" href="orders_sync.php?profile_id=<?= h((string)$profileId) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Скрыть</a>
                            <?php endif; ?>
                          </div>
                        </div>
                      </form>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($inlineProfileToolsId === (int)($profile['id'] ?? 0)): ?>
                <div class="list-card" style="margin-top:16px;">
                  <h3>Тестовая выгрузка в МойСклад</h3>
                  <?php if ((int)($profile['moysklad_account_id'] ?? 0) <= 0): ?>
                    <div class="muted">У этого профиля пока не выбран аккаунт МойСклад. Сначала привяжи его в настройках профиля.</div>
                  <?php else: ?>
                    <form method="post" class="stack">
                      <input type="hidden" name="action" value="test_export_order">
                      <input type="hidden" name="profile_id" value="<?= h((string)$profile['id']) ?>">
                      <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                      <div class="form-grid">
                        <label>
	                          <span>Номер заказа <?= h(price_tool_marketplace_label((string)($profile['marketplace'] ?? $currentMarketplace))) ?></span>
	                          <input type="text" name="test_order_ref" value="<?= h($testOrderRef) ?>" placeholder="Например, номер заказа или posting number">
                        </label>
                        <label>
                          <span>Профиль</span>
                          <div class="static-value"><?= h((string)($profile['title'] ?? '—')) ?></div>
                        </label>
                      </div>
	                      <div class="muted">Тест идёт строго по настройкам этого профиля: берёт один заказ из маркетплейса, обновляет сохранённые данные заказа и создаёт или обновляет заказ покупателя в МойСклад с выбранными значениями полей.</div>
                      <?php if (is_array($testExportResult)): ?>
                        <div class="summary-pills" style="margin-top:0;">
                          <span class="summary-pill"><?= h(orders_sync_test_export_mode_label((string)($testExportResult['mode'] ?? 'created'))) ?></span>
                          <span class="summary-pill">Источник: <?= h(strtoupper((string)($testExportResult['source'] ?? ''))) ?></span>
                          <span class="summary-pill">Posting: <?= h((string)($testExportResult['posting_number'] ?? '')) ?></span>
	                          <span class="summary-pill">Статус <?= h(price_tool_marketplace_label((string)($profile['marketplace'] ?? $currentMarketplace))) ?>: <?= h((string)($testExportResult['ozon_status'] ?? '')) ?></span>
                          <?php if (trim((string)($testExportResult['ozon_effective_status'] ?? '')) !== '' && (string)($testExportResult['ozon_effective_status'] ?? '') !== (string)($testExportResult['ozon_status'] ?? '')): ?>
                            <span class="summary-pill">Сценарий sync: <?= h((string)($testExportResult['ozon_effective_status_label'] ?? $testExportResult['ozon_effective_status'] ?? '')) ?></span>
                          <?php endif; ?>
                          <span class="summary-pill">Статус МойСклад: <?= h((string)($testExportResult['moysklad_state_name'] ?? '')) ?></span>
                          <span class="summary-pill">Позиций: <?= h((string)($testExportResult['positions_count'] ?? 0)) ?></span>
                        </div>
                        <div class="mini-grid" style="margin-top:14px;">
                          <div>
                            <div class="muted">Заказ в МойСклад</div>
                            <strong><?= h((string)($testExportResult['moysklad_customerorder_name'] ?? '—')) ?></strong>
                          </div>
                          <div>
                            <div class="muted">Контрагент</div>
                            <strong><?= h((string)($testExportResult['moysklad_counterparty_name'] ?? '—')) ?></strong>
                          </div>
                          <div>
                            <div class="muted">Организация / склад</div>
                            <strong><?= h(trim((string)($testExportResult['organization_name'] ?? '—') . ((string)($testExportResult['store_name'] ?? '') !== '' ? ' / ' . (string)$testExportResult['store_name'] : ''))) ?></strong>
                          </div>
                          <div>
                            <div class="muted">Проект / канал продаж</div>
                            <strong><?= h(trim((string)($testExportResult['project_name'] ?? '—') . ((string)($testExportResult['saleschannel_name'] ?? '') !== '' ? ' / ' . (string)$testExportResult['saleschannel_name'] : ''))) ?></strong>
                          </div>
                        </div>
                        <details>
                          <summary>Показать payload, который ушёл в МойСклад</summary>
                          <pre><?= h(json_encode($testExportResult['request'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
                        </details>
                      <?php endif; ?>
                      <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit">Тестировать выгрузку</button>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($copyProfileId > 0 && is_array($copySourceProfile)): ?>
        <div class="list-card" style="margin-bottom:18px;">
          <h3>Копирование профиля синхронизации</h3>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="copy_sync_profile">
            <input type="hidden" name="copy_profile_id" value="<?= h((string)$copyProfileId) ?>">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <div class="form-grid">
              <label>
                <span>Исходный профиль</span>
                <div class="static-value"><?= h((string)($copySourceProfile['title'] ?? '—')) ?></div>
              </label>
              <label>
                <span>Из подключения</span>
                <div class="static-value"><?= h((string)($copySourceProfile['connection_title'] ?? '—')) ?></div>
              </label>
            </div>
            <div class="form-grid">
              <label>
                <span>Новое название профиля</span>
                <input type="text" name="copy_title" value="<?= h($copyTitle) ?>" placeholder="Например, Ozon → МойСклад 2">
              </label>
              <label>
                <span>Куда скопировать</span>
                <select name="copy_target_connection_id" required>
                  <option value=""><?= $copyTargetConnections ? 'Выбери подключение' : 'Нет других подключений этого маркетплейса' ?></option>
                  <?php foreach ($copyTargetConnections as $connection): ?>
                    <option value="<?= h((string)$connection['id']) ?>" <?= ((int)($connection['id'] ?? 0) === $copyTargetConnectionId) ? 'selected' : '' ?>>
                      <?= h(ozon_price_connection_title_short($connection)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="muted">Скопируются настройки профиля, статусы, отмены и сопоставления складов. После копирования профиль откроется уже в целевом подключении.</div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <button type="submit" <?= !$copyTargetConnections ? 'disabled' : '' ?>>Скопировать профиль</button>
              <a class="button-link secondary" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Скрыть</a>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($isNewProfileMode || $profileEditId > 0): ?>
        <div class="list-card">
          <h3><?= $profileEditId > 0 ? 'Редактирование профиля синхронизации' : 'Новый профиль синхронизации' ?></h3>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="save_sync_profile">
            <input type="hidden" name="id" value="<?= h((string)($profileEditor['id'] ?? 0)) ?>">
            <?php $profileEditorMarketplace = orders_sync_marketplace_from_profile($profileEditor); ?>
            <?php $profileEditorSourceOptions = orders_sync_marketplace_order_source_options($profileEditorMarketplace); ?>
            <input type="hidden" name="marketplace" value="<?= h($profileEditorMarketplace) ?>">
            <div class="form-grid">
              <label>
                <span>Название профиля<span class="required-mark">*</span></span>
                <input type="text" name="title" value="<?= h((string)($profileEditor['title'] ?? '')) ?>" required>
              </label>
              <label>
                <span>Аккаунт маркетплейса<span class="required-mark">*</span></span>
                <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                <div class="static-value"><?= h(ozon_price_connection_title_short($currentConnection)) ?></div>
              </label>
            </div>
            <div class="form-grid">
              <label>
                <span>Аккаунт МойСклад</span>
                <select name="moysklad_account_id">
                  <option value="0">Пока не привязан</option>
                  <?php foreach ($moyskladAccounts as $account): ?>
                    <option value="<?= h((string)$account['id']) ?>" <?= ((int)($account['id'] ?? 0) === (int)($profileEditor['moysklad_account_id'] ?? 0)) ? 'selected' : '' ?>>
                      <?= h((string)$account['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span>Дата начала</span>
                <input type="date" name="sync_date_from" value="<?= h((string)($profileEditor['sync_date_from'] ?? '')) ?>" required>
              </label>
              <label>
                <span>Дата окончания</span>
                <input type="date" name="sync_date_to" value="<?= h((string)($profileEditor['sync_date_to'] ?? '')) ?>" required>
              </label>
            </div>
            <div>
              <span class="muted" style="display:block; margin-bottom:6px;">Источники заказов</span>
              <div class="checkbox-row">
                <?php $editorSources = orders_sync_marketplace_normalize_order_sources($profileEditor['order_sources'] ?? $profileEditor['order_sources_json'] ?? null, $profileEditorMarketplace); ?>
                <?php foreach ($profileEditorSourceOptions as $sourceKey => $sourceMeta): ?>
                  <label class="checkbox-chip">
                    <input type="checkbox" name="order_sources[]" value="<?= h((string)$sourceKey) ?>" <?= orders_sync_checkbox_checked($editorSources, (string)$sourceKey) ?>>
                    <?= h((string)($sourceMeta['label'] ?? strtoupper((string)$sourceKey))) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div>
              <span class="muted" style="display:block; margin-bottom:6px;">Статус профиля</span>
              <label class="checkbox-chip is-primary"><input type="checkbox" name="is_active" value="1" <?= !empty($profileEditor['is_active']) ? 'checked' : '' ?>> Активен</label>
            </div>
            <div class="form-grid">
              <label>
                <span>Sort order</span>
                <input type="number" name="sort_order" min="0" max="9999" value="<?= h((string)($profileEditor['sort_order'] ?? 100)) ?>">
              </label>
              <label>
                <span>Заметки</span>
                <textarea name="notes"><?= h((string)($profileEditor['notes'] ?? '')) ?></textarea>
              </label>
            </div>
            <div class="list-card" style="padding:18px 20px;">
              <h3 style="margin:0 0 14px;">Сопоставление полей МойСклад</h3>
              <div class="muted" style="margin-bottom:14px;">Эти значения будут подставляться в заказ покупателя при выгрузке заказов по этому профилю.</div>
              <div class="form-grid">
                <label>
                  <span>Организация<span class="required-mark">*</span></span>
                  <select name="moysklad_organization_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : 'required' ?>>
                    <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери организацию' : 'Сначала выбери аккаунт МойСклад' ?></option>
                    <?php foreach ($profileMoyskladOptions['organizations'] as $option): ?>
                      <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_organization_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                        <?= h((string)$option['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span>Контрагент<span class="required-mark">*</span></span>
                  <select name="moysklad_counterparty_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : 'required' ?>>
                    <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери контрагента' : 'Сначала выбери аккаунт МойСклад' ?></option>
                    <?php foreach ($profileMoyskladOptions['counterparties'] as $option): ?>
                      <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_counterparty_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                        <?= h((string)$option['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="form-grid">
                <label>
                  <span>Проект</span>
                  <select name="moysklad_project_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                    <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать' : 'Сначала выбери аккаунт МойСклад' ?></option>
                    <?php foreach ($profileMoyskladOptions['projects'] as $option): ?>
                      <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_project_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                        <?= h((string)$option['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span>Канал продаж</span>
                  <select name="moysklad_saleschannel_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                    <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать' : 'Сначала выбери аккаунт МойСклад' ?></option>
                    <?php foreach ($profileMoyskladOptions['saleschannels'] as $option): ?>
                      <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_saleschannel_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                        <?= h((string)$option['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="form-grid">
                <label>
                  <span>План. дата отгрузки</span>
                  <select name="moysklad_delivery_planned_source" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                    <option value="order_created_at" <?= ((string)($profileEditor['moysklad_delivery_planned_source'] ?? 'order_created_at') === 'order_created_at') ? 'selected' : '' ?>>Дата создания заказа</option>
                    <?php if ($profileEditorMarketplace === 'ozon'): ?>
                      <option value="ozon_shipment_date" <?= ((string)($profileEditor['moysklad_delivery_planned_source'] ?? '') === 'ozon_shipment_date') ? 'selected' : '' ?>>Дата отгрузки с Ozon</option>
                    <?php endif; ?>
                    <?php if ($profileEditorMarketplace === 'yandex_market'): ?>
                      <option value="yandex_shipment_date" <?= ((string)($profileEditor['moysklad_delivery_planned_source'] ?? '') === 'yandex_shipment_date') ? 'selected' : '' ?>>Дата отгрузки с Яндекс Маркета</option>
                    <?php endif; ?>
                  </select>
                </label>
              </div>
            </div>
            <div class="list-card" style="padding:18px 20px;">
              <h3 style="margin:0 0 14px;">Сопоставление статусов заказов</h3>
              <div class="muted" style="margin-bottom:14px;">Для обычных статусов заказа и для сценариев отмен/возвратов можно отдельно задать статус МойСклад при создании нового заказа и при обработке уже существующего заказа. Для существующих заказов можно выбрать вариант “Не менять статус”.</div>
              <?php if (!$profileStatusCatalog): ?>
                <div class="muted">Статусы <?= h(price_tool_marketplace_label($profileEditorMarketplace)) ?> появятся здесь после чтения заказов этого кабинета.</div>
              <?php else: ?>
                <div class="status-mapping-subsection">
                  <div class="status-mapping-subtitle">Поведение по умолчанию для статусов без сопоставления</div>
                  <div class="form-grid">
                    <label>
                      <span>Для новых заказов</span>
                      <select name="ozon_status_create_default_state_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать статус' : 'Сначала выбери аккаунт МойСклад' ?></option>
                        <option value="<?= h(orders_sync_status_create_new_order_token()) ?>" <?= ((string)($profileEditor['ozon_status_create_default_state_id'] ?? '') === orders_sync_status_create_new_order_token()) ? 'selected' : '' ?>>НОВЫЙ ЗАКАЗ</option>
                        <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['ozon_status_create_default_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <span>Для существующих заказов</span>
                      <select name="ozon_status_update_default_state_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value="<?= h(orders_sync_status_update_keep_token()) ?>" <?= ((string)($profileEditor['ozon_status_update_default_state_id'] ?? orders_sync_status_update_keep_token()) === orders_sync_status_update_keep_token()) ? 'selected' : '' ?>>Не менять статус</option>
                        <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['ozon_status_update_default_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                </div>
                <div class="status-mapping-subsection">
                  <div class="status-mapping-subtitle">Основные статусы заказа</div>
                  <?php orders_sync_render_status_mapping_section((array)($profileStatusCatalogGrouped['order'] ?? []), $profileEditor, $profileEditorMoyskladAccount, $profileMoyskladOptions); ?>
                </div>
                <?php if (!empty($profileStatusCatalogGrouped['fbo_only'])): ?>
                  <div class="status-mapping-subsection">
                    <div class="status-mapping-subtitle">Статусы только для FBO</div>
                    <?php orders_sync_render_status_mapping_section((array)($profileStatusCatalogGrouped['fbo_only'] ?? []), $profileEditor, $profileEditorMoyskladAccount, $profileMoyskladOptions); ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($profileStatusCatalogGrouped['return'])): ?>
                  <div class="status-mapping-subsection">
                    <div class="status-mapping-subtitle">Сценарии отмен и возвратов</div>
                    <?php orders_sync_render_status_mapping_section((array)($profileStatusCatalogGrouped['return'] ?? []), $profileEditor, $profileEditorMoyskladAccount, $profileMoyskladOptions); ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($profileMoyskladOptions['customerorder_states'])): ?>
                  <?php orders_sync_render_cancelled_transition_section((array)$profileMoyskladOptions['customerorder_states'], $profileEditor, $profileEditorMoyskladAccount); ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="list-card" style="padding:18px 20px;">
              <h3 style="margin:0 0 14px;">Сопоставление складов</h3>
              <div class="muted" style="margin-bottom:14px;">
                <?php if ($profileEditorMarketplace === 'ozon'): ?>
                  Сначала выбери склад МойСклад по умолчанию. Ниже можно задать точные соответствия для FBO и для складов из логистики Ozon этого кабинета, включая статус МойСклад для сценария “НОВЫЙ ЗАКАЗ”.
                <?php elseif ($profileEditorMarketplace === 'wb'): ?>
                  Сначала выбери склад МойСклад по умолчанию. Ниже можно задать отдельный склад для заказов DBW / со склада WB и точные соответствия для складов WB этого кабинета, включая статус МойСклад для сценария “НОВЫЙ ЗАКАЗ”.
                <?php else: ?>
                  Сначала выбери склад МойСклад по умолчанию. Ниже можно задать точные соответствия для складов Яндекс Маркета, которые появятся после чтения заказов, включая статус МойСклад для сценария “НОВЫЙ ЗАКАЗ”.
                <?php endif; ?>
              </div>
              <div class="form-grid">
                <label>
                  <span>Склад МойСклад по умолчанию<span class="required-mark">*</span></span>
                  <select name="moysklad_default_store_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : 'required' ?>>
                    <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Выбери склад по умолчанию' : 'Сначала выбери аккаунт МойСклад' ?></option>
                    <?php foreach ($profileMoyskladOptions['stores'] as $option): ?>
                      <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_default_store_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                        <?= h((string)$option['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="warehouse-mapping-head">
                <span>Склад <?= h(price_tool_marketplace_label($profileEditorMarketplace)) ?></span>
                <span>Склад МойСклад</span>
                <span>Статус для НОВОГО ЗАКАЗА</span>
              </div>
              <div class="stack">
                <?php if ($profileEditorMarketplace === 'ozon'): ?>
                  <div class="form-grid three">
                    <label>
                      <span>Склад Ozon</span>
                      <div class="static-value">FBO</div>
                    </label>
                    <label>
                      <span>Склад МойСклад</span>
                      <select name="moysklad_fbo_store_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Использовать склад по умолчанию' : 'Сначала выбери аккаунт МойСклад' ?></option>
                        <?php foreach ($profileMoyskladOptions['stores'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_fbo_store_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <span>Статус для НОВОГО ЗАКАЗА</span>
                      <select name="moysklad_fbo_new_order_state_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать' : 'Сначала выбери аккаунт МойСклад' ?></option>
                        <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_fbo_new_order_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                <?php endif; ?>
                <?php if ($profileEditorMarketplace === 'wb'): ?>
                  <div class="form-grid three">
                    <label>
                      <span>Склад WB</span>
                      <div class="static-value">DBW / склад WB</div>
                    </label>
                    <label>
                      <span>Склад МойСклад</span>
                      <select name="moysklad_wb_dbw_store_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Использовать склад по умолчанию' : 'Сначала выбери аккаунт МойСклад' ?></option>
                        <?php foreach ($profileMoyskladOptions['stores'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_wb_dbw_store_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <span>Статус для НОВОГО ЗАКАЗА</span>
                      <select name="moysklad_wb_dbw_new_order_state_id" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                        <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать' : 'Сначала выбери аккаунт МойСклад' ?></option>
                        <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((string)($profileEditor['moysklad_wb_dbw_new_order_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                            <?= h((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                <?php endif; ?>
                  <?php foreach ($profileWarehouseMappings as $mapping): ?>
                    <div class="form-grid three">
                      <input type="hidden" name="warehouse_mapping_keys[]" value="<?= h((string)($mapping['ozon_warehouse_key'] ?? '')) ?>">
                      <input type="hidden" name="warehouse_mapping_ids[]" value="<?= h((string)($mapping['ozon_warehouse_id'] ?? '')) ?>">
                      <input type="hidden" name="warehouse_mapping_names[]" value="<?= h((string)($mapping['ozon_warehouse_name'] ?? '')) ?>">
                      <label>
                        <span>Склад <?= h(price_tool_marketplace_label($profileEditorMarketplace)) ?></span>
                        <div class="static-value">
                          <?= h((string)($mapping['ozon_warehouse_name'] ?? '—')) ?>
                          <?php if (trim((string)($mapping['ozon_warehouse_id'] ?? '')) !== ''): ?>
                            <span class="muted"> · ID <?= h((string)$mapping['ozon_warehouse_id']) ?></span>
                          <?php endif; ?>
                        </div>
                      </label>
                      <label>
                        <span>Склад МойСклад</span>
                        <select name="warehouse_mapping_moysklad_store_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                          <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Использовать склад по умолчанию' : 'Сначала выбери аккаунт МойСклад' ?></option>
                          <?php foreach ($profileMoyskladOptions['stores'] as $option): ?>
                            <option value="<?= h((string)$option['id']) ?>" <?= ((string)($mapping['moysklad_store_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                              <?= h((string)$option['label']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>
                        <span>Статус для НОВОГО ЗАКАЗА</span>
                        <select name="warehouse_mapping_new_order_state_ids[]" <?= !is_array($profileEditorMoyskladAccount) ? 'disabled' : '' ?>>
                          <option value=""><?= is_array($profileEditorMoyskladAccount) ? 'Не задавать' : 'Сначала выбери аккаунт МойСклад' ?></option>
                          <?php foreach ($profileMoyskladOptions['customerorder_states'] as $option): ?>
                            <option value="<?= h((string)$option['id']) ?>" <?= ((string)($mapping['moysklad_new_order_state_id'] ?? '') === (string)$option['id']) ? 'selected' : '' ?>>
                              <?= h((string)$option['label']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </div>
                  <?php endforeach; ?>
              </div>
              <?php if (empty($profileWarehouseMappings)): ?>
                <div class="muted" style="margin-top:12px;">
                  Склады <?= h(price_tool_marketplace_label($profileEditorMarketplace)) ?> появятся здесь после чтения доступных складов этого кабинета.
                </div>
              <?php endif; ?>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <button type="submit">Сохранить профиль</button>
              <a class="button-link secondary" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Скрыть</a>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="card card-moysklad">
      <div class="toolbar">
        <div>
          <h2>Аккаунты МойСклад</h2>
          <div class="muted">Ниже отдельный список аккаунтов МойСклад, которые можно подключать к профилям синхронизации.</div>
        </div>
        <a class="button-link secondary" href="orders_sync.php?new_moysklad=1<?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Добавить аккаунт</a>
      </div>

      <div class="stack" style="margin-bottom:18px;">
        <?php if (!$moyskladAccounts): ?>
          <div class="muted">Пока нет аккаунтов МойСклад.</div>
        <?php else: ?>
          <?php foreach ($moyskladAccounts as $account): ?>
            <div class="item item-moysklad">
              <div class="item-top">
                <div>
                  <strong><?= h((string)$account['title']) ?></strong>
                  <div class="muted"><?= h((string)($account['base_url'] ?? '')) ?></div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                  <a class="button-link secondary" href="orders_sync.php?moysklad_edit_id=<?= h((string)$account['id']) ?><?= $currentConnectionId > 0 ? '&connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Редактировать</a>
                </div>
              </div>
              <?php if (trim((string)($account['notes'] ?? '')) !== ''): ?>
                <div class="muted"><?= h((string)$account['notes']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($isNewMoyskladMode || $moyskladEditId > 0): ?>
        <div class="list-card">
          <h3><?= $moyskladEditId > 0 ? 'Редактирование аккаунта МойСклад' : 'Новый аккаунт МойСклад' ?></h3>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="save_moysklad_account">
            <input type="hidden" name="id" value="<?= h((string)($moyskladEditor['id'] ?? 0)) ?>">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <div class="form-grid">
              <label>
                <span>Название</span>
                <input type="text" name="title" value="<?= h((string)($moyskladEditor['title'] ?? '')) ?>">
              </label>
              <label>
                <span>Base URL</span>
                <input type="text" name="base_url" value="<?= h((string)($moyskladEditor['base_url'] ?? 'https://api.moysklad.ru/api/remap/1.2')) ?>">
              </label>
            </div>
            <label>
              <span>API token</span>
              <input type="password" name="api_token" value="<?= h((string)($moyskladEditor['api_token'] ?? '')) ?>">
            </label>
            <div class="form-grid">
              <label>
                <span>Sort order</span>
                <input type="number" name="sort_order" min="0" max="9999" value="<?= h((string)($moyskladEditor['sort_order'] ?? 100)) ?>">
              </label>
              <div></div>
            </div>
            <label>
              <span>Заметки</span>
              <textarea name="notes"><?= h((string)($moyskladEditor['notes'] ?? '')) ?></textarea>
            </label>
            <?php if (is_array($moyskladCheck)): ?>
              <div class="summary-pills" style="margin-top:0;">
                <span class="summary-pill">HTTP <?= h((string)($moyskladCheck['http_code'] ?? '')) ?></span>
                <span class="summary-pill">Контрагентов в ответе: <?= h((string)($moyskladCheck['counterparty_total'] ?? 0)) ?></span>
              </div>
            <?php endif; ?>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <button type="submit">Сохранить аккаунт</button>
              <button type="submit" name="action" value="check_moysklad_account" class="secondary">Проверить подключение</button>
              <a class="button-link secondary" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Сбросить</a>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="split">
        <div>
        <h2>Последние запуски</h2>
        <div class="muted" style="margin-bottom:14px;">Run logs теперь тоже привязаны к профилю синхронизации.</div>
        <div class="stack">
          <?php if (!$runLogs): ?>
            <div class="muted">Запусков пока нет.</div>
          <?php else: ?>
            <?php foreach ($runLogs as $run): ?>
              <?php $runSummary = is_array($run['summary'] ?? null) ? $run['summary'] : []; ?>
              <div class="item">
                <div class="item-top">
                  <div>
                    <strong>Run #<?= h((string)$run['id']) ?></strong>
                    <div class="muted">
                      Профиль: <?= h((string)($run['profile_title'] ?? '—')) ?>
                      · <?= h(orders_sync_run_kind_label($runSummary)) ?>
                      · <?= h((string)($run['started_at'] ?? '')) ?>
                    </div>
                    <?php if (orders_sync_run_dataset_label($runSummary) !== ''): ?>
                      <div class="muted" style="margin-top:4px;"><?= h(orders_sync_run_dataset_label($runSummary)) ?></div>
                    <?php endif; ?>
                  </div>
                  <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <span class="status-chip <?= h(orders_sync_run_status_class((string)($run['status'] ?? ''))) ?>"><?= h(orders_sync_run_status_label((string)($run['status'] ?? ''))) ?></span>
                    <a class="button-link secondary" href="orders_sync.php?profile_id=<?= h((string)$currentProfileId) ?>&run_id=<?= h((string)$run['id']) ?>">Открыть</a>
                  </div>
                </div>
                <div class="summary-pills">
                  <?php foreach (orders_sync_run_total_labels($runSummary) as $pill): ?>
                    <span class="summary-pill"><?= h($pill) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php if (!empty($run['error_text'])): ?>
                  <div class="muted" style="margin-top:10px; color: var(--err-text);"><?= h((string)$run['error_text']) ?></div>
                <?php endif; ?>
                <?php if (trim((string)($run['log_text'] ?? '')) !== ''): ?>
                  <details>
                    <summary>Показать log</summary>
                    <pre><?= h((string)$run['log_text']) ?></pre>
                  </details>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php if ($statusTotals): ?>
          <div class="summary-pills" style="margin-bottom:14px;">
            <?php foreach ($statusTotals as $source => $totalsByStatus): ?>
              <?php foreach ($totalsByStatus as $status => $count): ?>
                <span class="summary-pill"><?= h(strtoupper((string)$source)) ?> · <?= h((string)$status) ?> · <?= h((string)$count) ?></span>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const rows = document.getElementById('cancel-transition-rows');
      const addButton = document.getElementById('add-cancel-transition-row');
      const template = document.getElementById('cancel-transition-row-template');
      if (!rows || !addButton || !template) {
        return;
      }

      const bindRemoveButtons = (root) => {
        root.querySelectorAll('.js-remove-cancel-row').forEach((button) => {
          if (button.dataset.bound === '1') {
            return;
          }
          button.dataset.bound = '1';
          button.addEventListener('click', () => {
            const row = button.closest('.cancel-transition-row');
            if (!row) {
              return;
            }
            if (rows.children.length <= 1) {
              row.querySelectorAll('select').forEach((select) => {
                select.value = '';
              });
              return;
            }
            row.remove();
          });
        });
      };

      addButton.addEventListener('click', () => {
        const fragment = template.content.cloneNode(true);
        rows.appendChild(fragment);
        bindRemoveButtons(rows);
      });

      bindRemoveButtons(rows);
    })();
  </script>
  <script>
    (() => {
      const card = document.getElementById('run-progress-card');
      if (!card) return;

      const runId = Number(card.dataset.runId || '0');
      let runStatus = String(card.dataset.runStatus || '');
      if (!runId || runStatus === '' || runStatus === 'success' || runStatus === 'error' || runStatus === 'partial') {
        return;
      }

      const statusEl = document.getElementById('run-progress-status');
      const percentEl = document.getElementById('run-progress-percent');
      const pillsEl = document.getElementById('run-progress-pills');
      const logEl = document.getElementById('run-progress-log');

      const statusLabel = (status) => ({
        success: 'Успешно',
        partial: 'Частично',
        error: 'Ошибка',
        running: 'В работе',
        stopping: 'Останавливается',
      }[status] || status || '—');

      const statusClass = (status) => ({
        success: 'status-ok',
        partial: 'status-warn',
        error: 'status-error',
        running: 'status-running',
        stopping: 'status-warn',
      }[status] || 'status-neutral');

      const runKind = (summary) => String(summary?.kind || 'ozon_sync');
      const isMoyskladRun = (summary) => ['moysklad_export', 'moysklad_create_orders', 'moysklad_update_statuses'].includes(runKind(summary));
        const renderPills = (summary) => {
          const totals = summary && typeof summary === 'object' && summary.totals && typeof summary.totals === 'object' ? summary.totals : {};
          const refresh = summary && typeof summary === 'object' && summary.refresh && typeof summary.refresh === 'object' ? summary.refresh : {};
          let labels = [];
          if (isMoyskladRun(summary)) {
            const marketplaceLabels = { ozon: 'Ozon', wb: 'WB', yandex_market: 'Яндекс Маркет' };
            const marketplaceLabel = marketplaceLabels[String(summary?.marketplace || 'ozon')] || 'Маркетплейс';
            labels = [
              `${marketplaceLabel} fetched ${Number(refresh.fetched || 0)}`,
              `candidates ${Number(totals.scanned || 0)}`,
              `processed ${Number(totals.processed || 0)}`,
            ];
            if (runKind(summary) === 'moysklad_create_orders') {
              labels.push(`created ${Number(totals.created || 0)}`);
              labels.push(`linked ${Number(totals.linked || 0)}`);
              labels.push(`skipped ${Number(totals.skipped || 0)}`);
              labels.push(`errors ${Number(totals.errors || 0)}`);
            } else if (runKind(summary) === 'moysklad_update_statuses') {
              labels.push(`updated ${Number(totals.updated || 0)}`);
              labels.push(`linked ${Number(totals.linked || 0)}`);
              labels.push(`skipped ${Number(totals.skipped || 0)}`);
              labels.push(`errors ${Number(totals.errors || 0)}`);
            } else {
              labels.push(`created ${Number(totals.created || 0)}`);
              labels.push(`updated ${Number(totals.updated || 0)}`);
              labels.push(`skipped ${Number(totals.skipped || 0)}`);
              labels.push(`errors ${Number(totals.errors || 0)}`);
            }
        } else {
          labels = [
            `fetched ${Number(totals.fetched || 0)}`,
            `new ${Number(totals.inserted || 0)}`,
            `updated ${Number(totals.updated || 0)}`,
            `errors ${Number(totals.errors || 0)}`,
          ];
        }
        pillsEl.innerHTML = labels.map((label) => `<span class="summary-pill">${label}</span>`).join('');
      };

      const poll = async () => {
        try {
          const response = await fetch(`orders_sync_run_poll.php?run_id=${encodeURIComponent(String(runId))}`, { credentials: 'same-origin' });
          if (!response.ok) return;
          const data = await response.json();
          runStatus = String(data.status || '');
          statusEl.className = `status-chip ${statusClass(runStatus)}`;
          statusEl.textContent = statusLabel(runStatus);
          percentEl.textContent = data.percent !== null && data.percent !== undefined ? `${data.percent}%` : 'в работе';
          renderPills(data.summary || {});
          if (typeof data.log_text === 'string') {
            logEl.textContent = data.log_text;
          }
          if (runStatus === 'running' || runStatus === 'stopping') {
            setTimeout(poll, 3000);
          } else {
            window.location.reload();
          }
        } catch (_error) {
          setTimeout(poll, 5000);
        }
      };

      setTimeout(poll, 1500);
    })();
  </script>
</body>
</html>
