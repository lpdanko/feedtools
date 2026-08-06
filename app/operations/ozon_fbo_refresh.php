<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_fbo_tool.php';
require_once __DIR__ . '/../ozon_price_tool.php';

function op_ozon_fbo_refresh(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Не указано подключение для FBO Tool.');
    }
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $marketplace = is_array($connection) ? (string)($connection['marketplace'] ?? 'ozon') : 'ozon';
    $warehouseOptions = [
        'warehouse_id' => (string)($params['warehouse_id'] ?? ''),
        'warehouse_key' => (string)($params['warehouse_key'] ?? ''),
        'warehouse_name' => (string)($params['warehouse_name'] ?? ''),
    ];

    $mode = trim((string)($params['mode'] ?? 'full'));
    $offerIdsRaw = $params['offer_ids'] ?? [];
    if (!is_array($offerIdsRaw)) {
        $decoded = json_decode((string)($params['offer_ids_json'] ?? '[]'), true);
        $offerIdsRaw = is_array($decoded) ? $decoded : [];
    }
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIdsRaw
    ))));
    $selectedMode = $mode === 'selected' || $offerIds;

    $total = $selectedMode ? max(1, count($offerIds)) : 1;
    ops_update_progress($opId, 0, $total, 'refresh', $selectedMode
        ? 'Обновляем выбранные FBO-товары...'
        : ($marketplace === 'wb' ? 'Обновляем остатки выбранного склада WB...' : 'Обновляем все FBO-остатки и цены...'));

    $log('ozon_fbo_refresh: connection_id=' . $connectionId
        . ', marketplace=' . $marketplace
        . ', warehouse_id=' . (string)$warehouseOptions['warehouse_id']
        . ', mode=' . ($selectedMode ? 'selected' : 'full')
        . ', selected=' . count($offerIds) . "\n");

    if ($selectedMode) {
        if (!$offerIds) {
            throw new RuntimeException('Не выбраны FBO-товары для обновления.');
        }
        $result = ozon_fbo_tool_refresh_offers($connectionId, $offerIds, $cfg, $log, $warehouseOptions);
    } else {
        $result = ozon_fbo_tool_refresh($connectionId, $cfg, $log, $warehouseOptions);
    }

    $summary = [
        'connection_id' => $connectionId,
        'marketplace' => $marketplace,
        'warehouse_id' => (string)$warehouseOptions['warehouse_id'],
        'mode' => $selectedMode ? 'selected' : 'full',
        'requested' => $selectedMode ? count($offerIds) : (int)($result['offers_seen'] ?? 0),
        'fbo_items' => (int)($result['fbo_items'] ?? 0),
        'prices_loaded' => (int)($result['prices_loaded'] ?? 0),
        'info_loaded' => (int)($result['info_loaded'] ?? 0),
        'analytics_loaded' => (int)($result['analytics_loaded'] ?? 0),
        'rules_annulled' => (int)($result['rules_annulled'] ?? 0),
        'elapsed_sec' => (float)($result['elapsed_sec'] ?? 0),
    ];

    ops_update_progress($opId, $total, $total, 'done', 'FBO Tool: обновление завершено.');
    $log('ozon_fbo_refresh_done: ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'ozon_fbo_refresh' => true,
        ],
    ];
}
