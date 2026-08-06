<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../ozon_products.php';

/**
 * Перенести товары в архив Ozon по offer_id (артикул продавца).
 *
 * Ввод:
 * - offer_ids_text: строки offer_id, строго 1 offer_id в строке.
 * - dry_run: 1/0
 *
 * Дополнительно (опционально):
 * - offer_ids: массив offer_id из выделения на странице датасета.
 *
 * Алгоритм:
 * 1) Собираем offer_id.
 * 2) Пытаемся найти product_id в feedtools_ozon_products (по ozon_client_id).
 * 3) Для недостающих — дергаем /v3/product/list с filter.offer_id.
 * 4) Архивируем /v1/product/archive батчами по product_id.
 */

function op_ozon_archive_products(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  ozon_products_tables_ensure($cfg);
  $datasetId = (int)$ds['id'];
  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $dryRun = trim((string)($params['dry_run'] ?? '1'));
  $dryRun = ($dryRun === '' || $dryRun === '1' || strtolower($dryRun) === 'true');

  // --- 1) offer_id: строго по одному в строке ---
  $offerIds = [];

  $text = (string)($params['offer_ids_text'] ?? '');
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $lines = explode("\n", $text);
  foreach ($lines as $ln) {
    $id = trim($ln);
    if ($id !== '') $offerIds[] = $id;
  }

  // merge selected offer_ids from UI (if any)
  if (isset($params['offer_ids']) && is_array($params['offer_ids'])) {
    foreach ($params['offer_ids'] as $v) {
      $id = trim((string)$v);
      if ($id !== '') $offerIds[] = $id;
    }
  }

  // de-dup keep order
  $seen = [];
  $offerIds2 = [];
  foreach ($offerIds as $id) {
    if (!isset($seen[$id])) {
      $seen[$id] = true;
      $offerIds2[] = $id;
    }
  }
  $offerIds = $offerIds2;

  if (!$offerIds) {
    throw new RuntimeException('Не задано ни одного offer_id. Вставь offer_id по одному в строке.');
  }

  $oz = ozon_cfg_or_fail($cfg);
  $scope = ozon_products_scope_from_ref(null, $cfg);
  $connectionId = (int)($scope['connection_id'] ?? 0);
  $ozClientId = (string)$oz['client_id'];

  $report = [
    'ok' => true,
    'dry_run' => $dryRun,
    'input_offer_ids' => $offerIds,
    'total_offer_ids' => count($offerIds),
    'resolved' => [ /* offer_id => product_id */ ],
    'not_found' => [],
    'api_errors' => [],
    'archive_batches' => [],
  ];

  ops_update_progress($opId, 0, 100, 'resolve', 'resolve offer_id -> product_id');

  // --- 2) resolve from DB ---
  $pdo = db();
  $offerToProduct = [];

  $chunks = array_chunk($offerIds, 500);
  [$scopeWhereSql, $scopeArgs] = ozon_products_scope_clause($scope);
  foreach ($chunks as $chunkIdx => $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "SELECT offer_id, product_id FROM feedtools_ozon_products WHERE {$scopeWhereSql} AND offer_id IN ($in)";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($scopeArgs, $chunk));
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $oid = trim((string)($r['offer_id'] ?? ''));
      $pid = (int)($r['product_id'] ?? 0);
      if ($oid !== '' && $pid > 0) {
        $offerToProduct[$oid] = $pid;
      }
    }
  }

  $missing = [];
  foreach ($offerIds as $oid) {
    if (!isset($offerToProduct[$oid])) $missing[] = $oid;
  }

  $log("ozon_archive_products: input offer_ids=" . count($offerIds) . "\n");
  if ($connectionId > 0) {
    $log("ozon_archive_products: connection_id={$connectionId}, client_id={$ozClientId}\n");
  }
  $log("ozon_archive_products: resolved from DB=" . count($offerToProduct) . "\n");
  $log("ozon_archive_products: missing after DB=" . count($missing) . "\n");

  // --- 3) resolve missing via API ---
  if ($missing) {
    $apiChunks = array_chunk($missing, 1000);
    $i = 0;
    foreach ($apiChunks as $chunk) {
      $i++;
      try {
        $payload = [
          'filter' => [
            'offer_id' => array_values($chunk),
          ],
          'limit' => 1000,
        ];

        $resp = ozon_post_json($oz, '/v3/product/list', $payload);
        $items = $resp['result']['items'] ?? [];
        if (is_array($items)) {
          foreach ($items as $it) {
            if (!is_array($it)) continue;
            $oid = trim((string)($it['offer_id'] ?? ''));
            $pid = (int)($it['product_id'] ?? 0);
            if ($oid !== '' && $pid > 0) {
              $offerToProduct[$oid] = $pid;
            }
          }
        }
      } catch (Throwable $e) {
        $report['ok'] = false;
        $report['api_errors'][] = [
          'stage' => 'resolve_product_ids',
          'batch' => $i,
          'offer_ids' => $chunk,
          'error' => $e->getMessage(),
        ];
        $log("API error (resolve) batch {$i}: " . $e->getMessage() . "\n");
      }
    }
  }

  foreach ($offerIds as $oid) {
    if (isset($offerToProduct[$oid])) {
      $report['resolved'][$oid] = $offerToProduct[$oid];
    } else {
      $report['not_found'][] = $oid;
    }
  }

  ops_update_progress($opId, 30, 100, 'resolve', 'resolve done');

  // --- 4) archive ---
  $productIds = array_values($offerToProduct);
  $productIds = array_values(array_unique(array_filter($productIds, fn($x) => (int)$x > 0)));

  $report['total_product_ids'] = count($productIds);

  if ($dryRun) {
    $log("dry_run=1: skip archive. resolved product_ids=" . count($productIds) . "\n");
  } else {
    ops_update_progress($opId, 40, 100, 'archive', 'archiving in Ozon');

    $archiveChunks = array_chunk($productIds, 100);
    $b = 0;
    foreach ($archiveChunks as $chunk) {
      $b++;
      try {
        $resp = ozon_post_json($oz, '/v1/product/archive', [
          'product_id' => array_values($chunk),
        ]);
        $report['archive_batches'][] = [
          'batch' => $b,
          'count' => count($chunk),
          'response' => $resp,
        ];
      } catch (Throwable $e) {
        $report['ok'] = false;
        $report['api_errors'][] = [
          'stage' => 'archive',
          'batch' => $b,
          'product_ids' => $chunk,
          'error' => $e->getMessage(),
        ];
        $log("API error (archive) batch {$b}: " . $e->getMessage() . "\n");
      }
      $pct = 40 + (int)round(($b / max(1, count($archiveChunks))) * 60);
      ops_update_progress($opId, min(99, $pct), 100, 'archive', "archiving batch {$b}/" . count($archiveChunks));
    }
  }

  // --- outputs ---
  $reportAbs = $outDir . '/report.json';
  file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  ops_update_progress($opId, 100, 100, 'done', $report['ok'] ? 'done' : 'done_with_errors');

  return [
    'report_json' => rel_to_outputs($cfg, $reportAbs),
    // небольшой summary в карточку операции
    'summary_json_inline' => [
      'ok' => (bool)$report['ok'],
      'dry_run' => (bool)$dryRun,
      'offer_ids_total' => (int)$report['total_offer_ids'],
      'resolved_total' => (int)count($report['resolved']),
      'not_found_total' => (int)count($report['not_found']),
      'product_ids_total' => (int)($report['total_product_ids'] ?? 0),
      'api_errors_total' => (int)count($report['api_errors']),
    ],
  ];
}
