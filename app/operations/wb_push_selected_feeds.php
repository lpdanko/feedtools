<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../ozon_fbo_tool.php';

function wb_push_selected_feeds_log_gradual_limit(array $apply, callable $log): void
{
    $limited = (int)($apply['gradual_limited'] ?? 0);
    if ($limited <= 0) {
        return;
    }

    $stepFactor = (float)($apply['gradual_step_factor'] ?? 0);
    $stepPercent = $stepFactor > 0 ? round($stepFactor * 100.0, 2) : 0.0;
    $stepSleepSec = (int)($apply['gradual_step_sleep_sec'] ?? 0);
    $stepItems = (int)($apply['gradual_step_items'] ?? 0);
    $priceUploadSteps = (int)($apply['price_upload_steps'] ?? 0);
    $remaining = (int)($apply['gradual_remaining'] ?? 0);
    $examples = [];
    foreach ((array)($apply['gradual_limited_examples'] ?? []) as $example) {
        if (!is_array($example)) {
            continue;
        }
        $name = trim((string)($example['vendor_code'] ?? ''));
        if ($name === '') {
            $name = 'nmID=' . (string)($example['nm_id'] ?? 0);
        }
        $examples[] = sprintf(
            'step %d %s: %.2f -> %.2f instead of %.2f, discount=%d%%',
            (int)($example['step'] ?? 1),
            $name,
            (float)($example['from_sale_price'] ?? 0),
            (float)($example['sent_sale_price'] ?? 0),
            (float)($example['planned_sale_price'] ?? 0),
            (int)($example['sent_discount'] ?? 0)
        );
    }

    $log('gradual price ladder: limited_items=' . $limited
        . ', step_items=' . $stepItems
        . ', price_upload_steps=' . $priceUploadSteps
        . ($remaining > 0 ? ', remaining_for_next_run=' . $remaining : '')
        . ($stepPercent > 0 ? ', max_step=' . $stepPercent . '% of current sale price' : '')
        . ($stepSleepSec > 0 ? ', step_pause=' . $stepSleepSec . 's' : '')
        . ($examples ? ', examples: ' . implode('; ', $examples) : '')
        . "\n");
}

function wb_push_selected_feeds_normalized_feed_ids_from_params(array $params): array
{
    $feedIds = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
    if (!is_array($feedIds)) {
        return [];
    }
    $feedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => (int)$value,
        $feedIds
    ), static fn(int $value): bool => $value > 0)));
    sort($feedIds);
    return $feedIds;
}

function wb_push_selected_feeds_active_duplicate_op_id(int $connectionId, array $feedIds, int $currentOpId): int
{
    if ($connectionId <= 0 || !$feedIds) {
        return 0;
    }
    sort($feedIds);
    $stmt = db()->prepare("
        SELECT id, params_json
        FROM feedtools_operations
        WHERE op_type = 'wb_push_selected_feeds'
          AND status IN ('queued', 'running')
          AND connection_id = ?
          AND id <> ?
        ORDER BY id ASC
        LIMIT 100
    ");
    $stmt->execute([$connectionId, $currentOpId]);
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $rowParams = json_decode((string)($row['params_json'] ?? '{}'), true);
        if (!is_array($rowParams)) {
            continue;
        }
        if ((string)($rowParams['source'] ?? '') === 'ozon_fbo_tool') {
            continue;
        }
        $rowFeedIds = wb_push_selected_feeds_normalized_feed_ids_from_params($rowParams);
        if ($rowFeedIds === $feedIds) {
            return (int)($row['id'] ?? 0);
        }
    }
    return 0;
}

function wb_push_selected_fbo_prices_direct(array $cfg, array $connection, array $offerIds, array $params, int $opId, callable $log): array
{
    $connectionId = (int)($connection['id'] ?? 0);
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        throw new RuntimeException('Выбери товары WB FBO Tool для отправки цен.');
    }

    $warehouse = ozon_fbo_tool_options_warehouse([
        'marketplace' => 'wb',
        'warehouse_id' => (string)($params['warehouse_id'] ?? ''),
        'warehouse_key' => (string)($params['warehouse_key'] ?? ''),
        'warehouse_name' => (string)($params['warehouse_name'] ?? ''),
    ]);
    if ($warehouse['warehouse_key'] === '') {
        throw new RuntimeException('Для отправки WB FBO-цен нужно выбрать scope складов WB.');
    }

    ops_update_progress($opId, 0, max(1, count($offerIds)), 'fbo_refresh', 'Проверяем остатки WB FBO перед отправкой цен...');
    $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $offerIds, $cfg, $log, $warehouse);
    $rows = ozon_fbo_tool_items($connectionId, [
        'offer_ids' => $offerIds,
        'only_with_stock' => false,
        'limit' => max(1, count($offerIds)),
        'warehouse_id' => $warehouse['warehouse_id'],
        'warehouse_key' => $warehouse['warehouse_key'],
    ], $cfg);
    $rulesByOffer = ozon_fbo_tool_price_rules_by_offer($connectionId, $offerIds, $cfg, $warehouse);

    $feedId = (int)($params['feed_id'] ?? 0);
    $feeds = ozon_fbo_tool_price_feeds_for_connection($connectionId, $cfg);
    if ($feedId > 0) {
        usort($feeds, static fn(array $a, array $b): int => ((int)($b['id'] ?? 0) === $feedId) <=> ((int)($a['id'] ?? 0) === $feedId));
    }
    $defaultSettings = [];
    if ($feedId > 0) {
        $feed = ozon_price_feed_get($feedId, $connectionId, $cfg);
        if (is_array($feed)) {
            $defaultSettings = $feed;
        }
    }
    if (!$defaultSettings) {
        $defaultSettings = is_array($feeds[0] ?? null) ? (array)$feeds[0] : [];
    }
    $defaultSettings['connection_id'] = $connectionId;

    $promotionFloorOfferIds = [];
    foreach ($rows as $row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        $rule = is_array($rulesByOffer[$offerId] ?? null) ? (array)$rulesByOffer[$offerId] : null;
        $state = ozon_fbo_tool_rule_state($rule, ozon_fbo_tool_row_units($row));
        $pricingMode = is_array($rule)
            ? ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), 'wb')
            : 'exact';
        if ($offerId !== '' && !empty($state['is_active']) && $pricingMode === 'promotion_floor') {
            $promotionFloorOfferIds[] = $offerId;
        }
    }
    $promotionFloorOfferIds = array_values(array_unique($promotionFloorOfferIds));
    $floorOffersById = [];
    $floorSettingsById = [];
    $goodsIndex = null;
    $runtime = null;
    if ($promotionFloorOfferIds) {
        foreach ($feeds as $feed) {
            $missingOfferIds = array_values(array_filter(
                $promotionFloorOfferIds,
                static fn(string $offerId): bool => !isset($floorOffersById[$offerId])
            ));
            if (!$missingOfferIds) {
                break;
            }
            try {
                foreach (ozon_fbo_tool_feed_find_offers($feed, $missingOfferIds, true) as $foundOfferId => $foundOffer) {
                    $foundOfferId = trim((string)$foundOfferId);
                    if ($foundOfferId === '' || !is_array($foundOffer) || isset($floorOffersById[$foundOfferId])) {
                        continue;
                    }
                    $floorOffersById[$foundOfferId] = $foundOffer;
                    $floorSettingsById[$foundOfferId] = $feed;
                }
            } catch (Throwable $feedError) {
                $log('WB FBO promotion floor feed warning: ' . $feedError->getMessage() . "\n");
            }
        }
        $goodsIndex = wb_price_tool_fetch_all_goods($cfg, $connection, false, 300);
        $runtime = wb_price_tool_runtime_context($cfg, $connection, false, true);
        $log('WB FBO promotion floor: rules=' . count($promotionFloorOfferIds)
            . ', offers_in_price_tool=' . count($floorOffersById) . "\n");
    }

    $desiredStates = [];
    $skipped = 0;
    $done = 0;
    foreach ($rows as $row) {
        $done++;
        $offerId = trim((string)($row['offer_id'] ?? ''));
        $units = ozon_fbo_tool_row_units($row);
        $rule = is_array($rulesByOffer[$offerId] ?? null) ? (array)$rulesByOffer[$offerId] : null;
        $state = ozon_fbo_tool_rule_state($rule, $units);
        $targetPrice = is_array($rule) ? round((float)($rule['target_price'] ?? 0), 2) : 0.0;
        $pricingMode = is_array($rule)
            ? ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), 'wb')
            : 'exact';
        $nmId = (int)($row['product_id'] ?? 0);
        if (empty($state['is_active']) || $targetPrice <= 0 || $nmId <= 0) {
            $skipped++;
            $log("{$offerId}: skipped, no active WB FBO price or stock\n");
            continue;
        }
        $calc = null;
        if ($pricingMode === 'promotion_floor') {
            $offer = is_array($floorOffersById[$offerId] ?? null) ? (array)$floorOffersById[$offerId] : null;
            $settings = is_array($floorSettingsById[$offerId] ?? null) ? (array)$floorSettingsById[$offerId] : null;
            if ($offer === null || $settings === null || !is_array($goodsIndex)) {
                $skipped++;
                $log("{$offerId}: skipped, not found in WB Price Tool feed for promotion-floor calculation\n");
                continue;
            }
            $settings['connection_id'] = $connectionId;
            $settings['fulfillment_scheme'] = 'fbo';
            $good = wb_price_tool_find_good_for_offer($offer, $goodsIndex);
            $calc = wb_price_tool_calculate_offer($settings, $offer, $good, $runtime);
            wb_promotions_record_pricing_decision($connectionId, (int)($settings['id'] ?? 0), $calc);
            $desired = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;
        } else {
            $desired = wb_price_tool_build_desired_state($defaultSettings, [
                'nm_id' => $nmId,
                'recommended_effective_sale_price' => $targetPrice,
            ]);
        }
        if ($desired === null) {
            $skipped++;
            $reason = is_array($calc) ? trim((string)($calc['warnings'][0] ?? 'cannot calculate WB promotion price')) : 'cannot build WB price payload';
            $log("{$offerId}: skipped, {$reason}\n");
            continue;
        }
        if ($pricingMode !== 'promotion_floor') {
            $good = json_decode((string)($row['raw_price_json'] ?? ''), true);
            if (is_array($good)) {
                $desired['current_price'] = wb_price_tool_current_price($good) !== null ? (int)round((float)wb_price_tool_current_price($good)) : null;
                $desired['current_discount'] = isset($good['discount']) ? (int)round((float)$good['discount']) : null;
                $desired['current_club_discount'] = wb_price_tool_current_club_discount($good) !== null ? (int)round((float)wb_price_tool_current_club_discount($good)) : 0;
            }
        }
        $desired['offer_id'] = $offerId;
        $desired['fbo_rule'] = 1;
        $desired['fbo_rule_pricing_mode'] = $pricingMode;
        $desiredStates[] = $desired;
        $promotionStatus = is_array($calc) ? (string)($calc['promotion_decision']['status'] ?? 'none') : 'fixed';
        $log("{$offerId}: ready, mode={$pricingMode}, promotion={$promotionStatus}, nmID={$nmId}, target={$targetPrice}, price={$desired['price']}, discount={$desired['discount']}, club={$desired['club_discount']}\n");
        if ($done === 1 || $done % 20 === 0 || $done >= count($rows)) {
            ops_update_progress($opId, $done, max(1, count($rows)), 'fbo_prepare', 'Готовим WB FBO-цены: ' . $done . ' / ' . count($rows));
        }
    }

    $apply = ['accepted' => 0, 'uploads' => [], 'errors' => []];
    if ($desiredStates) {
        ops_update_progress($opId, count($rows), max(1, count($rows)), 'push_wb_fbo', 'Отправляем сниженные WB FBO-цены...');
        $apply = wb_price_tool_apply_updates($cfg, $connection, $desiredStates);
        foreach ((array)($apply['uploads'] ?? []) as $upload) {
            if (!is_array($upload)) {
                continue;
            }
            $stepInfo = isset($upload['step']) ? ', step=' . (int)$upload['step'] : '';
            $log('upload wb fbo: id=' . (string)($upload['upload_id'] ?? 0) . ', status=' . (string)($upload['status'] ?? 'accepted') . ', items=' . (string)($upload['items'] ?? 0) . $stepInfo . "\n");
        }
        foreach ((array)($apply['errors'] ?? []) as $errorLine) {
            $errorLine = trim((string)$errorLine);
            if ($errorLine !== '') {
                $log('upload warning: ' . $errorLine . "\n");
            }
        }
        wb_push_selected_feeds_log_gradual_limit($apply, $log);
    } else {
        $log("push: no active WB FBO prices for selected offers\n");
    }

    ops_update_progress($opId, max(1, count($rows)), max(1, count($rows)), 'done', 'WB FBO Tool: отправка цен завершена.');
    $summary = [
        'connection_id' => $connectionId,
        'warehouse_id' => $warehouse['warehouse_id'],
        'offers_requested' => count($offerIds),
        'stocks_refreshed' => (int)($refresh['requested'] ?? 0),
        'fbo_active' => (int)($refresh['fbo_active'] ?? 0),
        'prices_ready' => count($desiredStates),
        'prices_accepted' => (int)($apply['accepted'] ?? 0),
        'prices_gradual_limited' => (int)($apply['gradual_limited'] ?? 0),
        'prices_gradual_remaining' => (int)($apply['gradual_remaining'] ?? 0),
        'prices_gradual_step_items' => (int)($apply['gradual_step_items'] ?? 0),
        'price_upload_steps' => (int)($apply['price_upload_steps'] ?? 0),
        'price_upload_items_sent' => (int)($apply['upload_items_sent'] ?? 0),
        'skipped' => $skipped,
        'upload_errors' => count((array)($apply['errors'] ?? [])),
    ];
    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'wb_fbo_prices_pushed' => true,
        ],
    ];
}

function op_wb_push_selected_feeds(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    ozon_price_feeds_table_ensure($cfg);

    $boolParam = static function (string $key, bool $default) use ($params): bool {
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
    };
    $forceRefresh = $boolParam('force_refresh', true);
    $pushAll = $boolParam('push_all', true);

    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для WB Price Tool нужно передать connection_id.');
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Массовая выгрузка WB доступна только для подключения Wildberries.');
    }
    $cfg = ozon_price_cfg_with_connection($cfg, $connection);

    $selectedOfferIdsRaw = $params['offer_ids'] ?? [];
    if (!is_array($selectedOfferIdsRaw)) {
        $decodedOffers = json_decode((string)($params['offer_ids_json'] ?? '[]'), true);
        $selectedOfferIdsRaw = is_array($decodedOffers) ? $decodedOffers : [];
    }
    $selectedOfferIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        (array)$selectedOfferIdsRaw
    ))));
    if ((string)($params['source'] ?? '') === 'ozon_fbo_tool' && $selectedOfferIds) {
        return wb_push_selected_fbo_prices_direct($cfg, $connection, $selectedOfferIds, $params, $opId, $log);
    }

    $feedIds = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
    if (!is_array($feedIds)) {
        throw new RuntimeException('Не удалось прочитать список выбранных профилей WB.');
    }
    $feedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => (int)$value,
        $feedIds
    ), static fn(int $value): bool => $value > 0)));
    if (!$feedIds) {
        throw new RuntimeException('Выбери хотя бы один профиль WB для обновления цен.');
    }

    $feeds = [];
    foreach ($feedIds as $feedId) {
        $feed = ozon_price_feed_get($feedId, $connectionId, $cfg);
        if (is_array($feed)) {
            if (ozon_price_feed_supplier_is_archived($feed)) {
                $log('skip archived supplier feed #' . $feedId . ': ' . (string)($feed['supplier_name'] ?? $feed['name'] ?? '') . "\n");
                continue;
            }
            $feeds[] = $feed;
        }
    }
    if (!$feeds) {
        throw new RuntimeException('Не удалось найти выбранные профили WB.');
    }

    $summary = [
        'feeds_selected' => count($feeds),
        'feeds_processed' => 0,
        'offers_total' => 0,
        'offers_processed' => 0,
        'offers_ready' => 0,
        'offers_unchanged_sent' => 0,
        'offers_applied' => 0,
        'offers_gradual_limited' => 0,
        'offers_gradual_remaining' => 0,
        'offers_gradual_step_items' => 0,
        'price_upload_steps' => 0,
        'price_upload_items_sent' => 0,
        'offers_skipped' => 0,
        'offers_failed' => 0,
        'upload_errors' => 0,
        'force_refresh' => $forceRefresh,
        'push_all' => $pushAll,
        'feeds' => [],
    ];

    ops_update_progress($opId, 0, max(1, count($feeds)), 'prepare', 'Готовим массовое обновление цен WB...');
    $log("wb_push_selected_feeds: connection_id={$connectionId}, feeds=" . implode(',', array_map(static fn(array $feed): string => (string)$feed['id'], $feeds)) . "\n");
    $log('mode: force_refresh=' . ($forceRefresh ? '1' : '0') . ', push_all=' . ($pushAll ? '1' : '0') . "\n");

    $goodsIndex = wb_price_tool_fetch_all_goods($cfg, $connection, $forceRefresh, $forceRefresh ? 0 : 300);
    $runtime = wb_price_tool_runtime_context($cfg, $connection, $forceRefresh, true);
    $log('WB goods in cabinet=' . count((array)($goodsIndex['items'] ?? []))
        . ($forceRefresh ? ' (fresh)' : ' (cache allowed)')
        . ', cards=' . count((array)($runtime['cards']['items'] ?? []))
        . ', commissions=' . count((array)($runtime['commissions']['items'] ?? []))
        . ', tariffs=' . count((array)($runtime['box_tariffs']['items'] ?? [])) . "\n");

    $totalOffers = 0;
    $estimatedTotalsByFeed = [];
    foreach ($feeds as $feed) {
        $estimated = max(1, (int)(ozon_price_feed_offer_count_cached($feed, 3600, false) ?? 1));
        $estimatedTotalsByFeed[(int)($feed['id'] ?? 0)] = $estimated;
        $totalOffers += $estimated;
    }
    $doneOffers = 0;

    foreach ($feeds as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        $feedName = trim((string)($feed['name'] ?? ('WB feed #' . $feedId)));
        $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
        $feedReport = [
            'feed_id' => $feedId,
            'feed_name' => $feedName,
            'offers_total' => 0,
            'offers_processed' => 0,
            'offers_ready' => 0,
            'offers_unchanged_sent' => 0,
            'offers_applied' => 0,
            'offers_gradual_limited' => 0,
            'offers_gradual_remaining' => 0,
            'offers_gradual_step_items' => 0,
            'price_upload_steps' => 0,
            'price_upload_items_sent' => 0,
            'offers_skipped' => 0,
            'offers_failed' => 0,
            'upload_errors' => 0,
            'error' => '',
        ];

        try {
            $feedDecisionsStartedAt = (string)(db()->query('SELECT NOW()')->fetchColumn() ?: date('Y-m-d H:i:s'));
            ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'fetch_feed', 'Скачиваем XML WB: ' . $feedName);
            $log("start: {$feedName}\n");
            $offers = wb_price_tool_load_feed_offers($feed);
            $preparedOffers = [];
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $offerId = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
                if ($offerId === '') {
                    continue;
                }
                $offer['offer_id'] = $offerId;
                $preparedOffers[] = $offer;
            }

            $feedReport['offers_total'] = count($preparedOffers);
            $estimatedForFeed = (int)($estimatedTotalsByFeed[$feedId] ?? 1);
            if ($feedReport['offers_total'] > $estimatedForFeed) {
                $totalOffers += ($feedReport['offers_total'] - $estimatedForFeed);
            }
            $summary['offers_total'] += $feedReport['offers_total'];
            $log("offers_total={$feedReport['offers_total']}\n");
            $guard = ozon_fbo_tool_refresh_active_rule_stocks_for_offers($connectionId, array_map(
                static fn(array $offer): string => (string)($offer['offer_id'] ?? ''),
                $preparedOffers
            ), $cfg, $log);
            if ((int)($guard['requested'] ?? 0) > 0 || (string)($guard['error'] ?? '') !== '') {
                $log('wb fbo price guard: requested=' . (int)($guard['requested'] ?? 0)
                    . ', active=' . (int)($guard['fbo_active'] ?? 0)
                    . ', annulled=' . (int)($guard['rules_annulled'] ?? 0)
                    . ((string)($guard['error'] ?? '') !== '' ? ', error=' . (string)$guard['error'] : '')
                    . "\n");
            }

            $desiredStates = [];
            foreach ($preparedOffers as $index => $offer) {
                $rowNo = $index + 1;
                $offerId = (string)($offer['offer_id'] ?? '');
                $prefix = "{$rowNo}. {$offerId}";
                $feedReport['offers_processed']++;
                $summary['offers_processed']++;

                try {
                    $good = wb_price_tool_find_good_for_offer($offer, $goodsIndex);
                    $calc = wb_price_tool_calculate_offer($feed, $offer, $good, $runtime);
                    wb_promotions_record_pricing_decision($connectionId, $feedId, $calc);
                    $desiredState = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;

                    $needsPush = $desiredState !== null && wb_price_tool_desired_state_needs_push($calc);
                    if ($desiredState === null) {
                        $feedReport['offers_skipped']++;
                        $summary['offers_skipped']++;
                        $reason = trim((string)($calc['warnings'][0] ?? 'нет валидного расчёта'));
                        $log("{$prefix}: skipped, {$reason}\n");
                    } elseif (!$pushAll && !$needsPush) {
                        $feedReport['offers_skipped']++;
                        $summary['offers_skipped']++;
                        $log("{$prefix}: skipped, текущие цена и скидки уже совпадают\n");
                    } else {
                        $desiredState['offer_id'] = $offerId;
                        $desiredState['target_profit_rub'] = (float)($calc['profit_rub'] ?? 0);
                        $desiredStates[] = $desiredState;
                        $feedReport['offers_ready']++;
                        $summary['offers_ready']++;
                        if (!$needsPush) {
                            $feedReport['offers_unchanged_sent']++;
                            $summary['offers_unchanged_sent']++;
                        }
                        $log(
                            "{$prefix}: ready" . (!$needsPush ? ' (forced unchanged)' : '')
                            . ", nmID={$desiredState['nm_id']}, price={$desiredState['price']}, discount={$desiredState['discount']}, club={$desiredState['club_discount']}\n"
                        );
                    }
                } catch (Throwable $rowError) {
                    $feedReport['offers_failed']++;
                    $summary['offers_failed']++;
                    $log("{$prefix}: failed, {$rowError->getMessage()}\n");
                }

                $doneOffers++;
                if ($doneOffers === 1 || $doneOffers % 10 === 0 || $doneOffers >= $totalOffers) {
                    ops_update_progress(
                        $opId,
                        $doneOffers,
                        max(1, $totalOffers),
                        'calculate',
                        "WB {$feedName}: {$feedReport['offers_processed']} / {$feedReport['offers_total']} товаров, всего {$doneOffers} / {$totalOffers}"
                    );
                }
            }

            if ($desiredStates) {
                ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'push_wb', 'Отправляем цены WB: ' . $feedName);
                $apply = wb_price_tool_apply_updates($cfg, $connection, $desiredStates);
                $feedReport['offers_applied'] += (int)($apply['accepted'] ?? 0);
                $summary['offers_applied'] += (int)($apply['accepted'] ?? 0);
                $gradualLimited = (int)($apply['gradual_limited'] ?? 0);
                $feedReport['offers_gradual_limited'] += $gradualLimited;
                $summary['offers_gradual_limited'] += $gradualLimited;
                $gradualRemaining = (int)($apply['gradual_remaining'] ?? 0);
                $feedReport['offers_gradual_remaining'] += $gradualRemaining;
                $summary['offers_gradual_remaining'] += $gradualRemaining;
                $gradualStepItems = (int)($apply['gradual_step_items'] ?? 0);
                $feedReport['offers_gradual_step_items'] += $gradualStepItems;
                $summary['offers_gradual_step_items'] += $gradualStepItems;
                $priceUploadSteps = (int)($apply['price_upload_steps'] ?? 0);
                $feedReport['price_upload_steps'] += $priceUploadSteps;
                $summary['price_upload_steps'] += $priceUploadSteps;
                $priceUploadItemsSent = (int)($apply['upload_items_sent'] ?? 0);
                $feedReport['price_upload_items_sent'] += $priceUploadItemsSent;
                $summary['price_upload_items_sent'] += $priceUploadItemsSent;
                $errors = array_values(array_filter((array)($apply['errors'] ?? []), static fn($value): bool => trim((string)$value) !== ''));
                $feedReport['upload_errors'] += count($errors);
                $summary['upload_errors'] += count($errors);
                foreach ((array)($apply['uploads'] ?? []) as $upload) {
                    if (!is_array($upload)) {
                        continue;
                    }
                    $kindRaw = (string)($upload['kind'] ?? 'price_discount');
                    $kind = $kindRaw === 'club_discount' ? 'club' : ($kindRaw === 'promotion_upload' ? 'promotion' : 'price');
                    $stepInfo = isset($upload['step']) ? ', step=' . (int)$upload['step'] : '';
                    $log("upload {$kind}: id=" . (string)($upload['upload_id'] ?? 0) . ', status=' . (string)($upload['status'] ?? 'accepted') . ', items=' . (string)($upload['items'] ?? 0) . $stepInfo . "\n");
                }
                foreach ($errors as $errorLine) {
                    $log("upload warning: {$errorLine}\n");
                }
                wb_push_selected_feeds_log_gradual_limit($apply, $log);
                if ((int)($apply['accepted'] ?? 0) > 0) {
                    $log("WB goods cache cleared after upload; next preview/test will fetch fresh cabinet prices.\n");
                }
            } else {
                $log("push: no valid calculated prices for {$feedName}\n");
            }

            $staleDecisionsDeleted = wb_promotions_delete_stale_pricing_decisions($connectionId, $feedId, $feedDecisionsStartedAt);
            if ($staleDecisionsDeleted > 0) {
                $log("wb promotion decisions cleanup: deleted stale={$staleDecisionsDeleted}\n");
            }

            $feedReport['error'] = $feedReport['upload_errors'] > 0 ? 'WB принял часть товаров с предупреждениями.' : '';
            $summary['feeds_processed']++;
            $summary['feeds'][] = $feedReport;
            $log("done: ready={$feedReport['offers_ready']}, forced_unchanged={$feedReport['offers_unchanged_sent']}, accepted={$feedReport['offers_applied']}, gradual_limited={$feedReport['offers_gradual_limited']}, gradual_remaining={$feedReport['offers_gradual_remaining']}, gradual_step_items={$feedReport['offers_gradual_step_items']}, price_upload_steps={$feedReport['price_upload_steps']}, skipped={$feedReport['offers_skipped']}, failed={$feedReport['offers_failed']}, upload_errors={$feedReport['upload_errors']}\n");
        } catch (Throwable $feedError) {
            $feedReport['error'] = $feedError->getMessage();
            $feedReport['offers_failed'] += max(1, $feedReport['offers_total']);
            $summary['offers_failed'] += max(1, $feedReport['offers_total']);
            $summary['feeds_processed']++;
            $summary['feeds'][] = $feedReport;
            $log("error: {$feedError->getMessage()}\n");
        }
    }

    $statusText = $summary['upload_errors'] > 0 || $summary['offers_failed'] > 0
        ? 'Массовое обновление WB завершено с предупреждениями.'
        : 'Массовое обновление WB завершено.';
    ops_update_progress($opId, max(1, $doneOffers), max(1, $totalOffers), 'done', $statusText);

    $autoPriceLadder = $boolParam('auto_price_ladder', true);
    $ladderRunsRemaining = array_key_exists('price_ladder_runs_remaining', $params)
        ? max(0, (int)$params['price_ladder_runs_remaining'])
        : 6;
    if (
        $autoPriceLadder
        && (int)($summary['offers_gradual_remaining'] ?? 0) > 0
        && (int)($summary['upload_errors'] ?? 0) === 0
        && (int)($summary['offers_failed'] ?? 0) === 0
    ) {
        if ($ladderRunsRemaining > 0) {
            $duplicateOpId = wb_push_selected_feeds_active_duplicate_op_id($connectionId, $feedIds, $opId);
            if ($duplicateOpId > 0) {
                $summary['price_ladder_existing_op_id'] = $duplicateOpId;
                $summary['price_ladder_runs_remaining'] = $ladderRunsRemaining;
                $log('price ladder: next run not queued, active duplicate exists #' . $duplicateOpId
                    . ', remaining_items=' . (int)$summary['offers_gradual_remaining'] . "\n");
            } else {
                $nextParams = $params;
                $nextParams['force_refresh'] = '1';
                $nextParams['push_all'] = '1';
                $nextParams['auto_price_ladder'] = '1';
                $nextParams['price_ladder_runs_remaining'] = (string)($ladderRunsRemaining - 1);
                $nextParams['price_ladder_parent_op_id'] = (int)($params['price_ladder_parent_op_id'] ?? $opId);
                $nextParams['price_ladder_previous_op_id'] = $opId;
                $nextOpId = ops_create(feedtools_global_ops_dataset_id(), 'wb_push_selected_feeds', $nextParams, ops_current_actor());
                $summary['price_ladder_next_op_id'] = $nextOpId;
                $summary['price_ladder_runs_remaining'] = $ladderRunsRemaining - 1;
                $log('price ladder: queued next WB price run #' . $nextOpId
                    . ', remaining_items=' . (int)$summary['offers_gradual_remaining']
                    . ', runs_left=' . ($ladderRunsRemaining - 1) . "\n");
            }
        } else {
            $summary['price_ladder_next_op_id'] = 0;
            $summary['price_ladder_runs_remaining'] = 0;
            $log('price ladder: next run not queued, run limit reached; remaining_items='
                . (int)$summary['offers_gradual_remaining'] . "\n");
        }
    }

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'wb_push_selected_feeds' => true,
        ],
    ];
}
