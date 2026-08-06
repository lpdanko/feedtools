<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../wildberries/WildberriesPriceTool.php';
require_once __DIR__ . '/../wb_promotions.php';

function wb_sync_promotions_bool_param(array $params, string $key, bool $default): bool
{
    if (!array_key_exists($key, $params)) {
        return $default;
    }
    $value = strtolower(trim((string)$params[$key]));
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function wb_sync_promotions_extract_promotions(array $response): array
{
    $items = $response['data']['promotions'] ?? ($response['promotions'] ?? null);
    if (!is_array($items) && is_array($response['data'] ?? null)) {
        $data = (array)$response['data'];
        $isList = array_keys($data) === range(0, count($data) - 1);
        if ($isList) {
            $items = $data;
        }
    }
    return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
}

function wb_sync_promotions_extract_nomenclatures(array $response): array
{
    $items = $response['data']['nomenclatures'] ?? ($response['nomenclatures'] ?? null);
    return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
}

function wb_sync_promotions_iso(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function op_wb_sync_promotions(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    wb_promotions_tables_ensure($cfg);

    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для синхронизации акций WB нужно передать connection_id.');
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Синхронизация акций WB доступна только для подключения Wildberries.');
    }
    $cfg = ozon_price_cfg_with_connection($cfg, $connection);
    $client = wb_price_tool_client($cfg, $connection);

    $daysBack = max(0, min(365, (int)($params['days_back'] ?? 7)));
    $daysAhead = max(1, min(365, (int)($params['days_ahead'] ?? 45)));
    $allPromo = wb_sync_promotions_bool_param($params, 'all_promo', false);
    $limit = max(1, min(1000, (int)($params['limit'] ?? 1000)));
    $maxPromotions = max(0, (int)($params['max_promotions'] ?? 0));
    $syncProducts = wb_sync_promotions_bool_param($params, 'sync_products', true);
    $syncCandidates = wb_sync_promotions_bool_param($params, 'sync_candidates', true);
    $maxProductsPerPromotion = max(0, (int)($params['max_products_per_promotion'] ?? 0));
    $dangerPlanDiscount = max(0.0, min(99.0, (float)($params['danger_plan_discount_percent'] ?? 60)));

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $startIso = wb_sync_promotions_iso($now->modify('-' . $daysBack . ' days'));
    $endIso = wb_sync_promotions_iso($now->modify('+' . $daysAhead . ' days'));
    $syncToken = bin2hex(random_bytes(8));
    $syncedAt = date('Y-m-d H:i:s');
    $pdo = db();

    $summary = [
        'connection_id' => $connectionId,
        'period_start' => $startIso,
        'period_end' => $endIso,
        'promotions_seen' => 0,
        'promotions_stored' => 0,
        'auto_promotions' => 0,
        'regular_promotions' => 0,
        'regular_promotions_skipped_empty' => 0,
        'participating_stored' => 0,
        'candidates_stored' => 0,
        'risky_plan_discount' => 0,
        'risky_plan_discount_threshold' => $dangerPlanDiscount,
        'max_plan_discount_seen' => null,
        'product_pages' => 0,
        'details_loaded' => 0,
        'complete_sync' => false,
    ];

    ops_update_progress($opId, 0, 1, 'fetch_calendar', 'Получаем календарь акций WB...');
    $log("wb_sync_promotions: connection_id={$connectionId}, period={$startIso}..{$endIso}, all_promo=" . ($allPromo ? '1' : '0') . ", limit={$limit}\n");

    $promotions = [];
    $offset = 0;
    for ($pageNo = 1; $pageNo <= 1000; $pageNo++) {
        $resp = $client->getPromotionCalendar($startIso, $endIso, $allPromo, $limit, $offset);
        $items = wb_sync_promotions_extract_promotions($resp);
        if (!$items) {
            break;
        }
        foreach ($items as $item) {
            $promotions[] = $item;
            if ($maxPromotions > 0 && count($promotions) >= $maxPromotions) {
                break 2;
            }
        }
        if (count($items) < $limit) {
            break;
        }
        $offset += $limit;
    }
    $summary['promotions_seen'] = count($promotions);
    $log("calendar: promotions=" . count($promotions) . "\n");

    $promotionIds = [];
    foreach ($promotions as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) {
            $promotionIds[] = $id;
        }
    }
    $promotionIds = array_values(array_unique($promotionIds));

    $detailsById = [];
    foreach (array_chunk($promotionIds, 100) as $chunk) {
        $detailsResp = $client->getPromotionDetails($chunk);
        foreach (wb_sync_promotions_extract_promotions($detailsResp) as $detail) {
            $id = (int)($detail['id'] ?? 0);
            if ($id > 0) {
                $detailsById[$id] = $detail;
                $summary['details_loaded']++;
            }
        }
    }
    $log("details: loaded={$summary['details_loaded']}\n");

    $goodsByNm = [];
    try {
        $goodsIndex = wb_price_tool_fetch_all_goods($cfg, $connection, false, 300);
        $goodsByNm = is_array($goodsIndex['by_nm_id'] ?? null) ? (array)$goodsIndex['by_nm_id'] : [];
        $log('goods index: nm=' . count($goodsByNm) . "\n");
    } catch (Throwable $goodsError) {
        $log('goods index warning: ' . $goodsError->getMessage() . "\n");
    }

    $total = max(1, count($promotions));
    foreach ($promotions as $index => $promo) {
        $promotionId = (int)($promo['id'] ?? 0);
        if ($promotionId <= 0) {
            continue;
        }
        $merged = array_replace($promo, (array)($detailsById[$promotionId] ?? []));
        $promoName = trim((string)($merged['name'] ?? ('WB action #' . $promotionId)));
        $promoType = trim((string)($merged['type'] ?? ''));
        if ($promoType === 'auto') {
            wb_promotions_upsert_promotion($pdo, $connectionId, $merged, $syncToken, $syncedAt);
            $summary['promotions_stored']++;
            $summary['auto_promotions']++;
            $log("promotion {$promotionId}: {$promoName} [auto], product list skipped by WB API\n");
            ops_update_progress($opId, $index + 1, $total, 'sync_promotions', "WB акции: " . ($index + 1) . "/{$total}");
            continue;
        }
        $summary['regular_promotions']++;
        $log("promotion {$promotionId}: {$promoName} [{$promoType}]\n");

        $storedForPromotion = 0;
        foreach ([
            'participating' => $syncProducts,
            'candidate' => $syncCandidates,
        ] as $sourceType => $enabled) {
            if (!$enabled) {
                continue;
            }

            $inAction = $sourceType === 'participating';
            $storedForPromoSource = 0;
            for ($productOffset = 0, $pageNo = 1; $pageNo <= 1000; $pageNo++) {
                if ($maxProductsPerPromotion > 0 && $storedForPromoSource >= $maxProductsPerPromotion) {
                    break;
                }
                $pageLimit = $limit;
                if ($maxProductsPerPromotion > 0) {
                    $pageLimit = min($pageLimit, max(1, $maxProductsPerPromotion - $storedForPromoSource));
                }
                try {
                    $resp = $client->getPromotionNomenclatures($promotionId, $inAction, $pageLimit, $productOffset);
                } catch (Throwable $productListError) {
                    $log("  {$sourceType}: skipped, WB API did not return product list: " . $productListError->getMessage() . "\n");
                    break;
                }
                $items = wb_sync_promotions_extract_nomenclatures($resp);
                $summary['product_pages']++;
                if (!$items) {
                    break;
                }

                foreach ($items as $item) {
                    $nmId = wb_promotions_item_id($item);
                    if ($nmId <= 0) {
                        continue;
                    }
                    $vendorCode = trim((string)($item['vendorCode'] ?? ''));
                    if ($vendorCode === '' && isset($goodsByNm[$nmId]) && is_array($goodsByNm[$nmId])) {
                        $vendorCode = trim((string)($goodsByNm[$nmId]['vendorCode'] ?? ''));
                    }
                    wb_promotions_upsert_product($pdo, $connectionId, $promotionId, $sourceType, $item, $vendorCode !== '' ? $vendorCode : null, $syncToken, $syncedAt);
                    $storedForPromoSource++;
                    if ($sourceType === 'participating') {
                        $summary['participating_stored']++;
                    } else {
                        $summary['candidates_stored']++;
                    }
                    $planDiscount = (float)($item['planDiscount'] ?? 0);
                    if ($summary['max_plan_discount_seen'] === null || $planDiscount > (float)$summary['max_plan_discount_seen']) {
                        $summary['max_plan_discount_seen'] = $planDiscount;
                    }
                    if ($planDiscount >= $dangerPlanDiscount) {
                        $summary['risky_plan_discount']++;
                    }
                }

                if (count($items) < $pageLimit) {
                    break;
                }
                $productOffset += $pageLimit;
            }
            $storedForPromotion += $storedForPromoSource;
            $log("  {$sourceType}: stored={$storedForPromoSource}\n");
        }

        if ($storedForPromotion > 0 || (!$syncProducts && !$syncCandidates)) {
            wb_promotions_upsert_promotion($pdo, $connectionId, $merged, $syncToken, $syncedAt);
            $summary['promotions_stored']++;
        } else {
            $summary['regular_promotions_skipped_empty']++;
            $log("  skipped regular promotion without participating/candidate products\n");
        }

        ops_update_progress($opId, $index + 1, $total, 'sync_promotions', "WB акции: " . ($index + 1) . "/{$total}");
    }

    $completeSync = $maxPromotions <= 0 && $maxProductsPerPromotion <= 0 && $syncProducts && $syncCandidates;
    $summary['complete_sync'] = $completeSync;
    if ($completeSync) {
        wb_promotions_finalize_sync($pdo, $connectionId, $syncToken);
        $log("finalize: old WB promotion rows removed for this connection\n");
    } else {
        $log("finalize skipped: operation had limits or disabled product modes\n");
    }

    $historyDeleted = wb_promotions_cleanup_price_history_old($pdo, $cfg);
    $summary['price_history_deleted'] = $historyDeleted;
    if ($historyDeleted > 0) {
        $log("price history retention: deleted={$historyDeleted}\n");
    }

    $dbSummary = wb_promotions_sync_summary($connectionId, $cfg, $dangerPlanDiscount);
    $summary += [
        'promotions_count' => $dbSummary['promotions_count'],
        'products_count' => $dbSummary['products_count'],
        'participating_count' => $dbSummary['participating_count'],
        'candidate_count' => $dbSummary['candidate_count'],
        'last_synced_at' => $dbSummary['last_synced_at'],
    ];

    ops_update_progress($opId, $total, $total, 'done', 'Синхронизация акций WB завершена.');
    $log("done: promotions={$summary['promotions_stored']}, participating={$summary['participating_stored']}, candidates={$summary['candidates_stored']}, risky={$summary['risky_plan_discount']}\n");

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'wb_promotions_synced' => true,
        ],
    ];
}
