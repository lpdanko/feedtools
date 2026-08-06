<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_products.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../ozon_actions.php';

function op_ozon_sync_actions(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    ozon_actions_tables_ensure();
    $requestedConnectionId = (int)($params['connection_id'] ?? 0);
    if ($requestedConnectionId <= 0) {
        throw new RuntimeException('Marketplace connection is required for ozon_sync_actions.');
    }
    $connection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
    $cfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($cfg);
    $connectionId = (int)($connection['id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Marketplace connection is required for ozon_sync_actions.');
    }
    $clientId = (string)$oz['client_id'];
    $pdo = db();

    $limit = (int)($params['limit'] ?? 100);
    if ($limit < 20) $limit = 20;
    if ($limit > 100) $limit = 100;

    $maxActions = (int)($params['max_actions'] ?? 0);
    if ($maxActions < 0) $maxActions = 0;

    $syncCandidates = !isset($params['sync_candidates']) || (string)$params['sync_candidates'] !== '0';
    $syncProducts = !isset($params['sync_products']) || (string)$params['sync_products'] !== '0';

    $syncToken = bin2hex(random_bytes(8));
    $syncedAt = date('Y-m-d H:i:s');

    $offerMap = ozon_action_product_offer_map($connectionId, $cfg);
    $actions = ozon_actions_fetch_list($oz);
    if ($maxActions > 0) {
        $actions = array_slice($actions, 0, $maxActions);
    }

    $actionsTotal = count($actions);
    $actionsDone = 0;
    $storedActions = 0;
    $storedProducts = 0;
    $storedCandidates = 0;

    ops_update_progress($opId, 0, max(1, $actionsTotal), 'fetch_actions', 'Получаем список акций Ozon...');
    $log("ozon_sync_actions: connection_id={$connectionId}, client_id={$clientId}, actions={$actionsTotal}, limit={$limit}\n");

    foreach ($actions as $action) {
        $actionId = (int)($action['id'] ?? 0);
        if ($actionId <= 0) continue;

        $title = trim((string)($action['title'] ?? ''));
        $log("action {$actionId}: {$title}\n");
        ozon_actions_upsert_action($pdo, $connectionId, $clientId, $action, $syncToken, $syncedAt);
        $storedActions++;

        foreach (['participating', 'candidate'] as $sourceType) {
            if ($sourceType === 'participating' && !$syncProducts) continue;
            if ($sourceType === 'candidate' && !$syncCandidates) continue;

            $lastId = '';
            $pages = 0;
            do {
                $pages++;
                $page = ozon_actions_fetch_page($oz, $sourceType === 'candidate' ? 'candidate' : 'products', $actionId, $lastId, $limit);
                $items = $page['products'];
                $lastId = (string)($page['last_id'] ?? '');

                foreach ($items as $item) {
                    $productId = (int)($item['id'] ?? 0);
                    if ($productId <= 0) continue;
                    $offerId = $offerMap[$productId] ?? null;
                    ozon_actions_upsert_product($pdo, $connectionId, $clientId, $actionId, $sourceType, $item, $offerId, $syncToken, $syncedAt);
                    if ($sourceType === 'candidate') $storedCandidates++;
                    else $storedProducts++;
                }

                if ($pages % 5 === 0) {
                    $msg = sprintf('Акция %s: %s, страница %d', $actionId, $sourceType, $pages);
                    ops_update_progress($opId, $actionsDone, max(1, $actionsTotal), 'sync_' . $sourceType, $msg);
                }
            } while ($lastId !== '' && !empty($items));
        }

        $actionsDone++;
        ops_update_progress($opId, $actionsDone, max(1, $actionsTotal), 'sync_actions', "Синхронизировано акций: {$actionsDone}/{$actionsTotal}");
    }

    ozon_actions_finalize_sync($pdo, $connectionId, $syncToken, $cfg);
    $summary = ozon_actions_sync_summary($connectionId, $cfg);

    $inlineSummary = [
        'connection_id' => $connectionId,
        'client_id' => $clientId,
        'actions_count' => $summary['actions_count'],
        'products_count' => $summary['products_count'],
        'participating_count' => $summary['participating_count'],
        'candidate_count' => $summary['candidate_count'],
        'last_synced_at' => $summary['last_synced_at'],
        'stored_actions_this_run' => $storedActions,
        'stored_participating_this_run' => $storedProducts,
        'stored_candidates_this_run' => $storedCandidates,
    ];

    ops_update_progress($opId, max(1, $actionsTotal), max(1, $actionsTotal), 'done', 'Синхронизация акций Ozon завершена.');
    $log("done: actions={$storedActions}, participating={$storedProducts}, candidates={$storedCandidates}\n");

    return [
        'summary_json_inline' => $inlineSummary,
        'outputs' => [
            'ozon_actions_synced' => true,
        ],
    ];
}
