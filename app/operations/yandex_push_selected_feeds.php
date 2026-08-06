<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_price_tool.php';

function op_yandex_push_selected_feeds(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    ozon_price_feeds_table_ensure($cfg);

    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для Яндекс Price Tool нужно передать connection_id.');
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'yandex_market') {
        throw new RuntimeException('Массовая выгрузка Яндекс Price Tool доступна только для подключения Яндекс Маркета.');
    }
    $cfg = ozon_price_cfg_with_connection($cfg, $connection);
    $context = yandex_price_tool_context($connection);

    $feedIds = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
    if (!is_array($feedIds)) {
        throw new RuntimeException('Не удалось прочитать список выбранных профилей Яндекс Маркета.');
    }
    $feedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => (int)$value,
        $feedIds
    ), static fn(int $value): bool => $value > 0)));
    if (!$feedIds) {
        throw new RuntimeException('Выбери хотя бы один профиль Яндекс Маркета для обновления цен.');
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
        throw new RuntimeException('Не удалось найти выбранные профили Яндекс Маркета.');
    }

    $summary = [
        'feeds_selected' => count($feeds),
        'feeds_processed' => 0,
        'offers_total' => 0,
        'offers_processed' => 0,
        'offers_ready' => 0,
        'offers_applied' => 0,
        'offers_skipped' => 0,
        'offers_failed' => 0,
        'upload_errors' => 0,
        'campaign_id' => (int)$context['campaign_id'],
        'business_id' => (int)$context['business_id'],
        'feeds' => [],
    ];

    ops_update_progress($opId, 0, max(1, count($feeds)), 'prepare', 'Готовим массовое обновление цен Яндекс Маркета...');
    $log("yandex_push_selected_feeds: connection_id={$connectionId}, campaign_id={$context['campaign_id']}, business_id={$context['business_id']}, feeds=" . implode(',', array_map(static fn(array $feed): string => (string)$feed['id'], $feeds)) . "\n");

    $totalOffers = 0;
    foreach ($feeds as $feed) {
        $totalOffers += max(1, (int)(ozon_price_feed_offer_count_cached($feed, 3600, false) ?? 1));
    }
    $doneOffers = 0;

    foreach ($feeds as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        $feedName = trim((string)($feed['name'] ?? ('Yandex feed #' . $feedId)));
        $feedReport = [
            'feed_id' => $feedId,
            'feed_name' => $feedName,
            'offers_total' => 0,
            'offers_processed' => 0,
            'offers_ready' => 0,
            'offers_applied' => 0,
            'offers_skipped' => 0,
            'offers_failed' => 0,
            'upload_errors' => 0,
            'api_prices' => 0,
            'api_mappings' => 0,
            'api_recommendations' => 0,
            'api_tariffs' => 0,
            'error' => '',
        ];

        try {
            ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'fetch_feed', 'Скачиваем XML Яндекс: ' . $feedName);
            $log("start: {$feedName}\n");
            $offers = yandex_price_tool_load_feed_offers($feed);
            $offersById = [];
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $offerId = trim((string)($offer['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                $offersById[$offerId] = $offer;
            }
            $offerIds = array_keys($offersById);
            $feedReport['offers_total'] = count($offerIds);
            $summary['offers_total'] += $feedReport['offers_total'];
            $log("offers_total={$feedReport['offers_total']}\n");

            ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'fetch_yandex', 'Получаем цены, карточки, рекомендации и тарифы Яндекс: ' . $feedName);
            $pricesById = yandex_price_tool_fetch_prices($connection, (int)$context['business_id'], $offerIds, $context);
            $mappingsById = yandex_price_tool_fetch_offer_mappings($connection, (int)$context['business_id'], $offerIds);
            $recommendationsById = yandex_price_tool_fetch_recommendations($connection, (int)$context['business_id'], $offerIds);
            $feedReport['api_prices'] = count($pricesById);
            $feedReport['api_mappings'] = count($mappingsById);
            $feedReport['api_recommendations'] = count($recommendationsById);
            $log("api: prices={$feedReport['api_prices']}, mappings={$feedReport['api_mappings']}, recommendations={$feedReport['api_recommendations']}\n");

            $referencePrices = [];
            foreach ($offerIds as $offerId) {
                $purchase = (float)($offersById[$offerId]['purchase_cost'] ?? 0);
                $referencePrices[$offerId] = yandex_price_tool_extract_price_value($pricesById[$offerId] ?? null, 'value') ?? max(1.0, $purchase * 1.5);
            }
            $tariffWarnings = [];
            try {
                $initialTariffsById = yandex_price_tool_calculate_tariffs($connection, $context, $feed, $offersById, $mappingsById, $referencePrices);
            } catch (Throwable $tariffError) {
                $initialTariffsById = [];
                $tariffWarnings[] = 'Первичный расчёт тарифов Яндекса недоступен: ' . $tariffError->getMessage();
                $log("tariffs warning: {$tariffWarnings[0]}\n");
            }
            $feedReport['api_tariffs'] = count($initialTariffsById);
            $targetPrices = [];
            foreach ($offerIds as $offerId) {
                $firstCalc = yandex_price_tool_calculate_offer(
                    $feed,
                    $offersById[$offerId],
                    $pricesById[$offerId] ?? null,
                    $mappingsById[$offerId] ?? null,
                    $recommendationsById[$offerId] ?? null,
                    $initialTariffsById[$offerId] ?? null
                );
                $targetPrices[$offerId] = max(1.0, (float)($firstCalc['recommended_price'] ?? $referencePrices[$offerId]));
            }
            try {
                $targetTariffsById = yandex_price_tool_calculate_tariffs($connection, $context, $feed, $offersById, $mappingsById, $targetPrices);
            } catch (Throwable $tariffError) {
                $targetTariffsById = [];
                $tariffWarnings[] = 'Расчёт тарифов Яндекса по целевым ценам недоступен: ' . $tariffError->getMessage();
                $log("tariffs warning: {$tariffWarnings[count($tariffWarnings) - 1]}\n");
            }
            if ($targetTariffsById) {
                $feedReport['api_tariffs'] = max($feedReport['api_tariffs'], count($targetTariffsById));
            }
            $log("api: tariffs={$feedReport['api_tariffs']}, selling_program=" . yandex_price_tool_selling_program($feed, $context) . "\n");

            $desiredStates = [];
            foreach ($offerIds as $index => $offerId) {
                $rowNo = $index + 1;
                $prefix = "{$rowNo}. {$offerId}";
                $feedReport['offers_processed']++;
                $summary['offers_processed']++;
                try {
                    $calc = yandex_price_tool_calculate_offer(
                        $feed,
                        $offersById[$offerId],
                        $pricesById[$offerId] ?? null,
                        $mappingsById[$offerId] ?? null,
                        $recommendationsById[$offerId] ?? null,
                        $targetTariffsById[$offerId] ?? ($initialTariffsById[$offerId] ?? null)
                    );
                    foreach ($tariffWarnings as $tariffWarning) {
                        if ($tariffWarning !== '') {
                            $calc['warnings'][] = $tariffWarning;
                        }
                    }
                    $desiredState = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;
                    if ($desiredState === null) {
                        $feedReport['offers_skipped']++;
                        $summary['offers_skipped']++;
                        $reason = trim((string)($calc['warnings'][0] ?? 'нет валидного расчёта'));
                        $log("{$prefix}: skipped, {$reason}\n");
                    } elseif (!yandex_price_tool_desired_state_needs_push($calc)) {
                        $feedReport['offers_skipped']++;
                        $summary['offers_skipped']++;
                        $log("{$prefix}: skipped, текущие цена/minimumForBestseller уже совпадают\n");
                    } else {
                        $desiredStates[] = $desiredState;
                        $feedReport['offers_ready']++;
                        $summary['offers_ready']++;
                        $log(
                            "{$prefix}: ready, remote={$desiredState['remote_offer_id']}, price={$desiredState['price']}, min={$desiredState['minimum_for_bestseller']}, " .
                            "profit=" . number_format((float)($calc['profit_rub'] ?? 0), 2, '.', '') .
                            ", tariffs=" . (string)($calc['breakdown']['source'] ?? 'missing_api') . "\n"
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
                        "Яндекс {$feedName}: {$feedReport['offers_processed']} / {$feedReport['offers_total']} товаров, всего {$doneOffers} / {$totalOffers}"
                    );
                }
            }

            if ($desiredStates) {
                ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'push_yandex', 'Отправляем цены Яндекс: ' . $feedName);
                $apply = yandex_price_tool_apply_updates($cfg, $connection, $desiredStates);
                $feedReport['offers_applied'] += (int)($apply['accepted'] ?? 0);
                $summary['offers_applied'] += (int)($apply['accepted'] ?? 0);
                $errors = array_values(array_filter((array)($apply['errors'] ?? []), static fn($value): bool => trim((string)$value) !== ''));
                $feedReport['upload_errors'] += count($errors);
                $summary['upload_errors'] += count($errors);
                foreach ((array)($apply['uploads'] ?? []) as $upload) {
                    if (!is_array($upload)) {
                        continue;
                    }
                    $log("upload {$upload['kind']}: status=" . (string)($upload['status'] ?? 'accepted') . ', items=' . (string)($upload['items'] ?? 0) . "\n");
                }
                foreach ($errors as $errorLine) {
                    $log("upload warning: {$errorLine}\n");
                }
            } else {
                $log("push: no changes for {$feedName}\n");
            }

            $feedReport['error'] = $feedReport['upload_errors'] > 0 ? 'Яндекс принял часть товаров с предупреждениями.' : '';
            $summary['feeds_processed']++;
            $summary['feeds'][] = $feedReport;
            $log("done: ready={$feedReport['offers_ready']}, accepted={$feedReport['offers_applied']}, skipped={$feedReport['offers_skipped']}, failed={$feedReport['offers_failed']}, upload_errors={$feedReport['upload_errors']}\n");
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
        ? 'Массовое обновление Яндекс Маркета завершено с предупреждениями.'
        : 'Массовое обновление Яндекс Маркета завершено.';
    ops_update_progress($opId, max(1, $doneOffers), max(1, $totalOffers), 'done', $statusText);

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'yandex_push_selected_feeds' => true,
        ],
    ];
}
