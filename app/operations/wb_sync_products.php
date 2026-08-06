<?php
declare(strict_types=1);

require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../wildberries/WildberriesProducts.php';

/**
 * Синхронизация списка карточек Wildberries в feedtools_wb_products.
 *
 * Фильтр "Только отсутствующие на WB" читает эту таблицу и не обращается
 * к WB API во время открытия страницы датасета.
 */
function op_wb_sync_products(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $requestedConnectionId = (int)($params['connection_id'] ?? 0);
  if ($requestedConnectionId <= 0) {
    throw new RuntimeException('Marketplace connection is required for wb_sync_products.');
  }
  $connection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
  if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
    throw new RuntimeException('Для синхронизации товаров WB выбери подключение Wildberries.');
  }
  $cfg = ozon_price_cfg_with_connection($cfg, $connection);

  $limit = (int)($params['limit'] ?? 100);
  if ($limit < 1) $limit = 100;
  if ($limit > 100) $limit = 100;

  $log("WB sync products: connection_id={$requestedConnectionId}, start\n");
  $result = wb_products_sync_full($cfg, $opId, $log, [
    'limit' => $limit,
    'connection_id' => $requestedConnectionId,
  ]);
  $log("WB sync products: done active={$result['active_total']} processed={$result['processed']} archive={$result['trash_processed']} errors={$result['error_processed']} pages={$result['pages']}\n");

  return [
    'outputs' => [
      'wb_sync' => $result,
    ],
    'summary_json_inline' => [
      'wb_products_active_total' => (int)$result['active_total'],
      'wb_products_processed' => (int)$result['processed'],
      'wb_products_deactivated' => (int)$result['deactivated'],
      'wb_products_archived' => (int)($result['trash_processed'] ?? 0),
      'wb_products_errors' => (int)($result['error_processed'] ?? 0),
      'wb_sync_pages' => (int)$result['pages'],
      'last_success_at' => (string)$result['last_success_at'],
    ],
  ];
}
