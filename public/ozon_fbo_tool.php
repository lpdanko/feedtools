<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/ozon_fbo_tool.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_fbo_tool_tables_ensure($cfg);

$error = '';
$flash = '';
$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=fbo_tool', true, 303);
    exit;
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=fbo_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'fbo_tool');
$cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
$isWbMarketplace = $currentMarketplace === 'wb';
$isOzonMarketplace = $currentMarketplace === 'ozon';
$wbWarehouseOptions = [];
$selectedWarehouse = [];
$requestedWarehouseKey = trim((string)($_GET['warehouse_key'] ?? $_POST['warehouse_key'] ?? ''));
$requestedWarehouseId = trim((string)($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? ''));
if ($currentMarketplaceReady && $isWbMarketplace) {
    try {
        $wbWarehouseOptions = ozon_fbo_tool_wb_warehouse_options($cfg, $currentConnection);
        if ($requestedWarehouseKey === '' && $requestedWarehouseId !== '') {
            $requestedWarehouseKey = ozon_fbo_tool_warehouse_key($requestedWarehouseId);
        }
        if ($requestedWarehouseKey !== '' && isset($wbWarehouseOptions[$requestedWarehouseKey])) {
            $selectedWarehouse = $wbWarehouseOptions[$requestedWarehouseKey];
        } elseif ($wbWarehouseOptions) {
            $selectedWarehouse = array_values($wbWarehouseOptions)[0];
            $requestedWarehouseKey = (string)($selectedWarehouse['warehouse_key'] ?? '');
        }
    } catch (Throwable $warehouseError) {
        $error = $warehouseError->getMessage();
    }
}
$fboScopeOptions = $isWbMarketplace && $selectedWarehouse ? $selectedWarehouse : [];

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$onlyWithStock = (string)($_GET['all'] ?? $_POST['all'] ?? '') !== '1';
$sortKey = trim((string)($_GET['sort'] ?? $_POST['sort'] ?? 'present'));
$sortDir = strtolower(trim((string)($_GET['dir'] ?? $_POST['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
$requestedFeedId = (int)($_GET['feed_id'] ?? $_POST['feed_id'] ?? 0);
$priceMinRaw = trim((string)($_GET['price_min'] ?? $_POST['price_min'] ?? ''));
$priceMaxRaw = trim((string)($_GET['price_max'] ?? $_POST['price_max'] ?? ''));
$priceMin = $priceMinRaw !== '' ? ozon_price_to_float($priceMinRaw) : null;
$priceMax = $priceMaxRaw !== '' ? ozon_price_to_float($priceMaxRaw) : null;
$refreshLog = '';

try {
    $postedAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction === 'sync_ozon_actions') {
        if (!$currentMarketplaceReady || $currentMarketplace !== 'ozon') {
            throw new RuntimeException('Синхронизация акций доступна только для подключения Ozon.');
        }
        $opId = ops_create(feedtools_global_ops_dataset_id(), 'ozon_sync_actions', [
            'connection_id' => (string)$currentConnectionId,
            'limit' => '100',
            'sync_candidates' => '1',
            'sync_products' => '1',
            'source' => 'ozon_fbo_tool',
        ], ft_current_user());
        ops_append_log_tail($opId, "Queued from FBO Tool.\n", 200000);
        fbo_spawn_worker_for_operation($cfg, $opId);
        header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction === 'refresh_fbo') {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('FBO Tool недоступен для этого подключения.');
        }
        if ($isWbMarketplace && !$selectedWarehouse) {
            throw new RuntimeException('Выбери scope складов WB для обновления остатков.');
        }
        $opId = ops_create(feedtools_global_ops_dataset_id(), 'ozon_fbo_refresh', [
            'connection_id' => (string)$currentConnectionId,
            'mode' => 'full',
            'warehouse_id' => (string)($selectedWarehouse['warehouse_id'] ?? ''),
            'warehouse_key' => (string)($selectedWarehouse['warehouse_key'] ?? ''),
            'warehouse_name' => (string)($selectedWarehouse['warehouse_name'] ?? ''),
            'source' => 'ozon_fbo_tool',
        ], ft_current_user());
        ops_append_log_tail($opId, "Queued from FBO Tool: full refresh.\n", 200000);
        fbo_spawn_worker_for_operation($cfg, $opId);
        header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($postedAction, ['refresh_selected_fbo', 'bulk_save_fbo_rules', 'bulk_disable_fbo_rules', 'bulk_restore_regular_prices', 'push_selected_fbo_prices', 'bulk_enable_zero_fbs', 'bulk_disable_zero_fbs'], true)) {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('FBO Tool недоступен для этого подключения.');
        }
        if ($isWbMarketplace && !$selectedWarehouse) {
            throw new RuntimeException('Выбери scope складов WB для FBO Tool.');
        }
        if (in_array($postedAction, ['bulk_enable_zero_fbs', 'bulk_disable_zero_fbs'], true) && !$isOzonMarketplace) {
            throw new RuntimeException('Обнуление FBS при FBO доступно только для Ozon.');
        }
        $selectedOfferIds = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($_POST['selected_offer_ids'] ?? [])
        ))));
        if (!$selectedOfferIds) {
            throw new RuntimeException('Выбери хотя бы один FBO-товар.');
        }

        if ($postedAction === 'refresh_selected_fbo') {
            $opId = ops_create(feedtools_global_ops_dataset_id(), 'ozon_fbo_refresh', [
                'connection_id' => (string)$currentConnectionId,
                'mode' => 'selected',
                'offer_ids' => $selectedOfferIds,
                'warehouse_id' => (string)($selectedWarehouse['warehouse_id'] ?? ''),
                'warehouse_key' => (string)($selectedWarehouse['warehouse_key'] ?? ''),
                'warehouse_name' => (string)($selectedWarehouse['warehouse_name'] ?? ''),
                'source' => 'ozon_fbo_tool',
            ], ft_current_user());
            ops_append_log_tail($opId, 'Queued from FBO Tool: selected refresh, offers=' . count($selectedOfferIds) . ".\n", 200000);
            fbo_spawn_worker_for_operation($cfg, $opId);
            header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
            exit;
        }

        if (in_array($postedAction, ['bulk_enable_zero_fbs', 'bulk_disable_zero_fbs'], true)) {
            ozon_fbo_tool_set_zero_fbs_flag(
                $currentConnectionId,
                $selectedOfferIds,
                $postedAction === 'bulk_enable_zero_fbs',
                $cfg
            );
            $savedParam = $postedAction === 'bulk_enable_zero_fbs'
                ? 'zero_fbs_enabled=' . count($selectedOfferIds)
                : 'zero_fbs_disabled=' . count($selectedOfferIds);
            $returnParams = [
                'connection_id' => $currentConnectionId,
                'q' => $q,
                'feed_id' => $requestedFeedId,
                'sort' => $sortKey,
                'dir' => $sortDir,
            ];
            if ($priceMinRaw !== '') {
                $returnParams['price_min'] = $priceMinRaw;
            }
            if ($priceMaxRaw !== '') {
                $returnParams['price_max'] = $priceMaxRaw;
            }
            if (!$onlyWithStock) {
                $returnParams['all'] = '1';
            }
            if ($requestedWarehouseKey !== '') {
                $returnParams['warehouse_key'] = $requestedWarehouseKey;
            }
            header('Location: ozon_fbo_tool.php?' . http_build_query($returnParams) . '&' . $savedParam, true, 303);
            exit;
        }

        if ($postedAction === 'push_selected_fbo_prices') {
            $rulesByOffer = ozon_fbo_tool_price_rules_by_offer($currentConnectionId, $selectedOfferIds, $cfg, $fboScopeOptions);
            $pushOfferIds = [];
            foreach ($selectedOfferIds as $offerId) {
                $offerId = trim((string)$offerId);
                if ($offerId === '') {
                    continue;
                }
                $rule = is_array($rulesByOffer[$offerId] ?? null) ? (array)$rulesByOffer[$offerId] : null;
                $state = ozon_fbo_tool_rule_state($rule, 1);
                if (!empty($state['is_active']) && (float)($rule['target_price'] ?? 0) > 0) {
                    $pushOfferIds[] = $offerId;
                }
            }
            $pushOfferIds = array_values(array_unique($pushOfferIds));
            if (!$pushOfferIds) {
                throw new RuntimeException(
                    'У выбранных товаров нет сохранённой включённой сниженной FBO-цены. '
                    . 'Сначала установи сниженную цену; актуальный FBO-остаток операция проверит уже в фоне перед отправкой.'
                );
            }

            $pushOpType = $isWbMarketplace ? 'wb_push_selected_feeds' : 'ozon_push_selected_feeds';
            $opId = ops_create(feedtools_global_ops_dataset_id(), $pushOpType, [
                'connection_id' => (string)$currentConnectionId,
                'feed_ids_json' => '[]',
                'feed_id' => (string)$requestedFeedId,
                'offer_ids' => $pushOfferIds,
                'warehouse_id' => (string)($selectedWarehouse['warehouse_id'] ?? ''),
                'warehouse_key' => (string)($selectedWarehouse['warehouse_key'] ?? ''),
                'warehouse_name' => (string)($selectedWarehouse['warehouse_name'] ?? ''),
                'allow_cross_connection_feeds' => '1',
                'source' => 'ozon_fbo_tool',
            ], ft_current_user());
            ops_append_log_tail(
                $opId,
                "Queued from FBO Tool.\n"
                . 'FBO stock preflight will run inside the background operation.' . "\n",
                200000
            );
            fbo_spawn_worker_for_operation($cfg, $opId);
            header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
            exit;
        }

        if ($postedAction === 'bulk_restore_regular_prices') {
            $opId = fbo_queue_regular_price_restore(
                $cfg,
                $currentConnectionId,
                $currentMarketplace,
                $selectedOfferIds,
                ft_current_user(),
                $fboScopeOptions
            );
            header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
            exit;
        }

        if ($postedAction === 'bulk_disable_fbo_rules') {
            foreach ($selectedOfferIds as $offerId) {
                ozon_fbo_tool_disable_price_rule($currentConnectionId, $offerId, ft_current_user(), $cfg, $fboScopeOptions);
            }
            $savedParam = 'fbo_rules_disabled=' . count($selectedOfferIds);
        } else {
            $bulkPriceSource = trim((string)($_POST['bulk_price_source'] ?? 'elastic'));
            $bulkPricingMode = $isWbMarketplace
                ? ozon_fbo_tool_normalize_pricing_mode((string)($_POST['bulk_pricing_mode'] ?? 'promotion_floor'), 'wb')
                : 'exact';
            $bulkMaxDiscountPercent = ozon_price_to_float((string)($_POST['bulk_max_discount_percent'] ?? ''));
            $bulkPriceMap = [
                'elastic' => ['field' => '_elastic_price', 'label' => 'Elastic Boosting'],
                'index_target' => ['field' => '_index_target_price', 'label' => 'уровень индекса'],
                'optimal' => ['field' => '_optimal_price', 'label' => 'оптимальная цена Price Tool'],
                'current' => ['field' => 'price', 'label' => 'установленная цена'],
            ];
            $bulkUseMaxDiscount = $bulkPriceSource === 'optimal_with_discount';
            $bulkUseFixedDiscount = $bulkPriceSource === 'fixed_discount';
            $bulkNeedsDiscountPercent = $bulkUseMaxDiscount || $bulkUseFixedDiscount;
            if (!$bulkNeedsDiscountPercent && !isset($bulkPriceMap[$bulkPriceSource])) {
                $bulkPriceSource = 'elastic';
            }
            if ($bulkNeedsDiscountPercent && ($bulkMaxDiscountPercent <= 0 || $bulkMaxDiscountPercent > 95)) {
                throw new RuntimeException($bulkUseFixedDiscount ? 'Укажи скидку от 0 до 95%.' : 'Укажи максимальную скидку от 0 до 95%.');
            }

            $bulkRows = ozon_fbo_tool_items($currentConnectionId, [
                'only_with_stock' => false,
                'offer_ids' => $selectedOfferIds,
                'limit' => max(1, count($selectedOfferIds)),
                'warehouse_id' => (string)($selectedWarehouse['warehouse_id'] ?? ''),
                'warehouse_key' => (string)($selectedWarehouse['warehouse_key'] ?? ''),
            ], $cfg);
            $bulkNeedsPriceTool = $bulkUseMaxDiscount || in_array($bulkPriceSource, ['elastic', 'index_target', 'optimal'], true);
            if ($bulkNeedsPriceTool) {
                $bulkFeeds = ozon_fbo_tool_price_feeds_for_connection($currentConnectionId, $cfg);
                $selectedFeedForBulk = $bulkFeeds ? ['__all_feeds' => $bulkFeeds] : null;
                $bulkRows = ozon_fbo_tool_enrich_rows_for_price_tool($currentConnectionId, $bulkRows, $selectedFeedForBulk, $cfg, true, $fboScopeOptions);
            }
            $bulkRowsByOffer = [];
            foreach ($bulkRows as $bulkRow) {
                $offerId = trim((string)($bulkRow['offer_id'] ?? ''));
                if ($offerId !== '') {
                    $bulkRowsByOffer[$offerId] = $bulkRow;
                }
            }

            $field = $bulkNeedsDiscountPercent ? '' : (string)$bulkPriceMap[$bulkPriceSource]['field'];
            $savedCount = 0;
            $savedOfferIds = [];
            $skipped = [];
            foreach ($selectedOfferIds as $offerId) {
                $bulkRow = is_array($bulkRowsByOffer[$offerId] ?? null) ? (array)$bulkRowsByOffer[$offerId] : [];
                if ($bulkUseMaxDiscount) {
                    $targetPrice = fbo_optimal_price_with_max_discount($bulkRow, $bulkMaxDiscountPercent);
                } elseif ($bulkUseFixedDiscount) {
                    $targetPrice = fbo_price_with_fixed_discount($bulkRow, $bulkMaxDiscountPercent);
                } else {
                    $targetPrice = isset($bulkRow[$field]) ? round((float)$bulkRow[$field], 2) : 0.0;
                }
                if ($targetPrice <= 0) {
                    $skipped[] = $offerId;
                    continue;
                }
                ozon_fbo_tool_save_price_rule($currentConnectionId, $offerId, $targetPrice, 0, ft_current_user(), $cfg, $fboScopeOptions, $bulkPricingMode);
                $savedCount++;
                $savedOfferIds[] = $offerId;
            }
            $savedParam = 'fbo_rules_bulk_saved=' . $savedCount;
            if ($skipped) {
                $savedParam .= '&fbo_rules_bulk_skipped=' . count($skipped);
            }
            if ($isOzonMarketplace && (string)($_POST['zero_fbs_after_save'] ?? '') === '1' && $savedOfferIds) {
                ozon_fbo_tool_set_zero_fbs_flag($currentConnectionId, $savedOfferIds, true, $cfg);
                $savedParam .= '&zero_fbs_enabled=' . count($savedOfferIds);
            }
        }

        $returnParams = [
            'connection_id' => $currentConnectionId,
            'q' => $q,
            'feed_id' => $requestedFeedId,
            'sort' => $sortKey,
            'dir' => $sortDir,
        ];
        if ($priceMinRaw !== '') {
            $returnParams['price_min'] = $priceMinRaw;
        }
        if ($priceMaxRaw !== '') {
            $returnParams['price_max'] = $priceMaxRaw;
        }
        if (!$onlyWithStock) {
            $returnParams['all'] = '1';
        }
        if ($requestedWarehouseKey !== '') {
            $returnParams['warehouse_key'] = $requestedWarehouseKey;
        }
        header('Location: ozon_fbo_tool.php?' . http_build_query($returnParams) . '&' . $savedParam, true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($postedAction, ['enable_zero_fbs', 'disable_zero_fbs'], true)) {
        if (!$currentMarketplaceReady || $currentMarketplace !== 'ozon') {
            throw new RuntimeException('FBO Tool доступен только для подключения Ozon.');
        }
        $offerId = trim((string)($_POST['offer_id'] ?? ''));
        if ($offerId === '') {
            throw new RuntimeException('Не удалось определить FBO-товар.');
        }
        ozon_fbo_tool_set_zero_fbs_flag($currentConnectionId, [$offerId], $postedAction === 'enable_zero_fbs', $cfg);
        $returnParams = [
            'connection_id' => $currentConnectionId,
            'q' => $q,
            'feed_id' => $requestedFeedId,
            'sort' => $sortKey,
            'dir' => $sortDir,
        ];
        if ($priceMinRaw !== '') {
            $returnParams['price_min'] = $priceMinRaw;
        }
        if ($priceMaxRaw !== '') {
            $returnParams['price_max'] = $priceMaxRaw;
        }
        if (!$onlyWithStock) {
            $returnParams['all'] = '1';
        }
        if ($requestedWarehouseKey !== '') {
            $returnParams['warehouse_key'] = $requestedWarehouseKey;
        }
        $savedParam = $postedAction === 'enable_zero_fbs' ? 'zero_fbs_enabled=1' : 'zero_fbs_disabled=1';
        header('Location: ozon_fbo_tool.php?' . http_build_query($returnParams) . '&' . $savedParam, true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($postedAction, ['save_fbo_price_rule', 'disable_fbo_price_rule', 'restore_regular_price'], true)) {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('FBO Tool недоступен для этого подключения.');
        }
        if ($isWbMarketplace && !$selectedWarehouse) {
            throw new RuntimeException('Выбери scope складов WB для FBO Tool.');
        }
        $offerId = trim((string)($_POST['offer_id'] ?? ''));
        if ($postedAction === 'save_fbo_price_rule') {
            $targetPrice = ozon_price_to_float((string)($_POST['target_price'] ?? ''));
            $pricingMode = $isWbMarketplace
                ? ozon_fbo_tool_normalize_pricing_mode((string)($_POST['pricing_mode'] ?? 'exact'), 'wb')
                : 'exact';
            ozon_fbo_tool_save_price_rule($currentConnectionId, $offerId, $targetPrice, 0, ft_current_user(), $cfg, $fboScopeOptions, $pricingMode);
            $savedParam = 'fbo_rule_saved=1&fbo_rule_mode=' . urlencode($pricingMode);
        } elseif ($postedAction === 'restore_regular_price') {
            $opId = fbo_queue_regular_price_restore(
                $cfg,
                $currentConnectionId,
                $currentMarketplace,
                [$offerId],
                ft_current_user(),
                $fboScopeOptions
            );
            header('Location: op.php?id=' . urlencode((string)$opId), true, 303);
            exit;
        } else {
            ozon_fbo_tool_disable_price_rule($currentConnectionId, $offerId, ft_current_user(), $cfg, $fboScopeOptions);
            $savedParam = 'fbo_rule_disabled=1';
        }
        $returnParams = [
            'connection_id' => $currentConnectionId,
            'q' => $q,
            'feed_id' => $requestedFeedId,
            'sort' => $sortKey,
            'dir' => $sortDir,
        ];
        if ($priceMinRaw !== '') {
            $returnParams['price_min'] = $priceMinRaw;
        }
        if ($priceMaxRaw !== '') {
            $returnParams['price_max'] = $priceMaxRaw;
        }
        if (!$onlyWithStock) {
            $returnParams['all'] = '1';
        }
        if ($requestedWarehouseKey !== '') {
            $returnParams['warehouse_key'] = $requestedWarehouseKey;
        }
        header('Location: ozon_fbo_tool.php?' . http_build_query($returnParams) . '&' . $savedParam, true, 303);
        exit;
    }
    if ((string)($_GET['refreshed'] ?? '') === '1') {
        $flash = 'FBO-остатки и текущие цены обновлены.';
    } elseif ((int)($_GET['fbo_selected_requested'] ?? 0) > 0) {
        $flash = 'Выбранные FBO-товары обновлены: ' . (int)($_GET['fbo_selected_refreshed'] ?? 0)
            . ' из ' . (int)$_GET['fbo_selected_requested'] . ' с активным FBO-остатком.';
    } elseif ((string)($_GET['fbo_rule_saved'] ?? '') === '1') {
        $flash = (string)($_GET['fbo_rule_mode'] ?? '') === 'promotion_floor'
            ? 'Минимальная цена WB для акций сохранена. Она действует только пока есть остаток на складе WB.'
            : 'FBO-цена сохранена. Она будет активной только пока есть FBO-остаток или пока ты не отключишь правило.';
    } elseif ((string)($_GET['fbo_rule_disabled'] ?? '') === '1') {
        $flash = 'FBO-цена отключена.';
    } elseif ((int)($_GET['fbo_rules_bulk_saved'] ?? 0) > 0) {
        $flash = 'FBO-правила сохранены: ' . (int)$_GET['fbo_rules_bulk_saved'] . '.';
        if ((int)($_GET['fbo_rules_bulk_skipped'] ?? 0) > 0) {
            $flash .= ' Пропущено без расчётной цены: ' . (int)$_GET['fbo_rules_bulk_skipped'] . '.';
        }
        if ((int)($_GET['zero_fbs_enabled'] ?? 0) > 0) {
            $flash .= ' Обнуление FBS при наличии FBO включено: ' . (int)$_GET['zero_fbs_enabled'] . '.';
        }
    } elseif ((int)($_GET['fbo_rules_disabled'] ?? 0) > 0) {
        $flash = 'FBO-правила отключены: ' . (int)$_GET['fbo_rules_disabled'] . '.';
    } elseif ((int)($_GET['zero_fbs_enabled'] ?? 0) > 0) {
        $flash = 'Обнуление FBS при наличии FBO включено: ' . (int)$_GET['zero_fbs_enabled'] . '.';
    } elseif ((int)($_GET['zero_fbs_disabled'] ?? 0) > 0) {
        $flash = 'Обнуление FBS при наличии FBO отключено: ' . (int)$_GET['zero_fbs_disabled'] . '.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($currentMarketplaceReady) {
    ozon_fbo_tool_annul_inactive_price_rules($currentConnectionId, 'fbo_tool_view', $cfg, [], $fboScopeOptions);
}

$summary = ozon_fbo_tool_summary($currentConnectionId, $cfg, $fboScopeOptions);
$actionsSummary = [];
if ($currentMarketplaceReady && $currentMarketplace === 'ozon') {
    $actionsSummaryCacheKey = 'fbo_actions_summary_' . $currentConnectionId;
    $actionsSummary = ozon_price_tool_cache_read($actionsSummaryCacheKey, 300) ?? [];
    if (!$actionsSummary) {
        $actionsSummary = ozon_actions_sync_summary($currentConnectionId, $cfg);
        ozon_price_tool_cache_write($actionsSummaryCacheKey, $actionsSummary);
    }
}
$priceFeeds = $currentMarketplaceReady ? ozon_fbo_tool_price_feeds_for_connection($currentConnectionId, $cfg) : [];
$selectedFeed = null;
if ($requestedFeedId > 0) {
    $selectedFeed = ozon_price_feed_get($requestedFeedId, $currentConnectionId, $cfg);
    if (!$selectedFeed) {
        $selectedFeed = ozon_price_feed_get($requestedFeedId, null, $cfg);
    }
    if (is_array($selectedFeed) && ozon_price_feed_supplier_is_archived($selectedFeed)) {
        $selectedFeed = null;
        $requestedFeedId = 0;
    }
} elseif ($priceFeeds) {
    $selectedFeed = ['__all_feeds' => $priceFeeds];
}

$baseFilters = [
    'only_with_stock' => $onlyWithStock,
    'q' => $q,
    'price_min' => $priceMin,
    'price_max' => $priceMax,
    'warehouse_id' => (string)($selectedWarehouse['warehouse_id'] ?? ''),
    'warehouse_key' => (string)($selectedWarehouse['warehouse_key'] ?? ''),
];
$valuationRows = $currentMarketplaceReady
    ? ozon_fbo_tool_items($currentConnectionId, array_merge($baseFilters, [
        'only_with_stock' => true,
        'limit' => 5000,
    ]), $cfg)
    : [];
$valuation = ozon_fbo_tool_valuation_summary_light($valuationRows);

$rows = [];
if ($currentMarketplaceReady) {
    $enrichedSortKeys = ['optimal', 'index_target', 'elastic', 'discount_price', 'discount_days', 'cost_total'];
    if (in_array($sortKey, $enrichedSortKeys, true)) {
        $rows = ozon_fbo_tool_items($currentConnectionId, $baseFilters + [
            'limit' => 5000,
        ], $cfg);
        $rows = ozon_fbo_tool_enrich_rows_for_price_tool($currentConnectionId, $rows, $selectedFeed, $cfg, false, $fboScopeOptions);
        $rows = ozon_fbo_tool_sort_rows($rows, $sortKey, $sortDir);
        $rows = array_slice($rows, 0, 300);
    } elseif ($sortKey === 'sale_total') {
        $rows = ozon_fbo_tool_items($currentConnectionId, $baseFilters + [
            'limit' => 5000,
        ], $cfg);
        foreach ($rows as &$row) {
            $saleUnit = ozon_fbo_tool_row_sale_price($row);
            $row['_sale_total'] = $saleUnit !== null ? round($saleUnit * ozon_fbo_tool_row_units($row), 2) : null;
        }
        unset($row);
        $rows = ozon_fbo_tool_sort_rows($rows, $sortKey, $sortDir);
        $rows = array_slice($rows, 0, 300);
        $rows = ozon_fbo_tool_enrich_rows_for_price_tool($currentConnectionId, $rows, $selectedFeed, $cfg, false, $fboScopeOptions);
    } else {
        $rows = ozon_fbo_tool_items($currentConnectionId, $baseFilters + [
            'sort' => $sortKey,
            'dir' => $sortDir,
            'limit' => 300,
        ], $cfg);
        $rows = ozon_fbo_tool_enrich_rows_for_price_tool($currentConnectionId, $rows, $selectedFeed, $cfg, false, $fboScopeOptions);
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fbo_fmt_int($value): string
{
    return number_format((int)$value, 0, '.', ' ');
}

function fbo_fmt_rub($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $num = (float)$value;
    if ($num <= 0) {
        return '—';
    }
    return number_format($num, 0, ',', ' ') . ' ₽';
}

function fbo_index_label(array $row): string
{
    $value = $row['price_index_value'] ?? null;
    $source = trim((string)($row['price_index_source'] ?? ''));
    if ($value === null || (float)$value <= 0) {
        return trim((string)($row['color_index'] ?? '')) !== '' ? (string)$row['color_index'] : '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ($source !== '' ? ' · ' . $source : '');
}

function fbo_sort_link(string $key, string $label, string $currentSort, string $currentDir, array $params): string
{
    $nextDir = ($currentSort === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $params['sort'] = $key;
    $params['dir'] = $nextDir;
    $indicator = $currentSort === $key ? ($currentDir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="sort-link" href="?' . h(http_build_query($params)) . '">' . h($label . $indicator) . '</a>';
}

function fbo_price_cell($value, string $note = ''): string
{
    $price = fbo_fmt_rub($value);
    if ($price === '—' || $note === '') {
        return $price === '—'
            ? '<span class="placeholder">—</span>'
            : '<span class="money-value">' . h($price) . '</span>';
    }
    return '<span class="money-value">' . h($price) . '</span><div class="cell-note">' . h($note) . '</div>';
}

function fbo_fmt_input_price($value): string
{
    if ($value === null || $value === '' || (float)$value <= 0) {
        return '';
    }
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

function fbo_state_hidden_inputs(array $params): string
{
    $html = '';
    foreach ($params as $key => $value) {
        $html .= '<input type="hidden" name="' . h((string)$key) . '" value="' . h((string)$value) . '">';
    }
    return $html;
}

function fbo_spawn_worker_for_operation(array $cfg, int $opId): void
{
    if (empty($cfg['worker']['auto_spawn'])) {
        return;
    }
    $datasetId = feedtools_global_ops_dataset_id();
    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);
    $spawnLogAbs = $outDir . '/spawn.log';
    @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);

    $php = $cfg['worker']['php_bin'] ?? PHP_BINARY;
    $script = $cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php');
    $cmd = escapeshellcmd((string)$php) . ' ' . escapeshellarg((string)$script)
        . ' --op_id=' . (int)$opId
        . ' > ' . escapeshellarg($spawnLogAbs) . ' 2>&1 &';
    @exec($cmd);
    ops_append_log_tail($opId, "spawnLogAbs: {$spawnLogAbs}\n", 200000);
    ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
}

function fbo_queue_regular_price_restore(
    array $cfg,
    int $connectionId,
    string $marketplace,
    array $offerIds,
    ?string $actor,
    array $scopeOptions = []
): int {
    $offerIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        throw new RuntimeException('Выбери хотя бы один товар с FBO-ценой.');
    }

    $rules = ozon_fbo_tool_price_rules_by_offer($connectionId, $offerIds, $cfg, $scopeOptions);
    $activeOfferIds = [];
    foreach ($offerIds as $offerId) {
        $rule = is_array($rules[$offerId] ?? null) ? (array)$rules[$offerId] : [];
        if (strtolower(trim((string)($rule['status'] ?? ''))) === 'active'
            && (float)($rule['target_price'] ?? 0) > 0) {
            $activeOfferIds[] = $offerId;
        }
    }
    if (!$activeOfferIds) {
        throw new RuntimeException('У выбранных товаров нет включённого управления ценой через FBO Tool.');
    }
    if ($marketplace !== 'ozon') {
        throw new RuntimeException('Немедленный возврат обычной цены пока доступен только для FBO Ozon.');
    }

    $feeds = ozon_fbo_tool_price_feeds_for_connection($connectionId, $cfg);
    $feedIds = array_values(array_unique(array_filter(array_map(
        static fn(array $feed): int => (int)($feed['id'] ?? 0),
        $feeds
    ), static fn(int $feedId): bool => $feedId > 0)));
    if (!$feedIds) {
        throw new RuntimeException(
            'Для подключения нет профилей Price Tool. Обычную цену пока нельзя рассчитать; FBO-правила оставлены включёнными.'
        );
    }

    $opType = $marketplace === 'wb' ? 'wb_push_selected_feeds' : 'ozon_push_selected_feeds';
    $opId = ops_create(feedtools_global_ops_dataset_id(), $opType, [
        'connection_id' => (string)$connectionId,
        'feed_ids_json' => json_encode($feedIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'offer_ids' => $activeOfferIds,
        'force_refresh' => '1',
        'push_all' => '1',
        'allow_cross_connection_feeds' => '0',
        'source' => 'ozon_fbo_restore_regular_prices',
    ], $actor);

    ops_append_log_tail(
        $opId,
        'Queued from FBO Tool: restore regular Price Tool prices, offers=' . count($activeOfferIds) . ".\n"
        . 'FBO price rules will be disabled only after Ozon accepts the regular prices.' . "\n",
        200000
    );
    fbo_spawn_worker_for_operation($cfg, $opId);
    return $opId;
}

function fbo_discount_price_default(array $row): ?float
{
    foreach (['_discount_price', '_elastic_price', '_index_target_price', '_optimal_price', 'price', 'min_price', 'marketing_seller_price'] as $key) {
        if (isset($row[$key]) && (float)$row[$key] > 0) {
            return round((float)$row[$key], 2);
        }
    }
    return null;
}

function fbo_current_unit_price(array $row): ?float
{
    foreach (['price', '_sale_unit_price', 'marketing_seller_price', 'min_price'] as $key) {
        if (isset($row[$key]) && (float)$row[$key] > 0) {
            return round((float)$row[$key], 2);
        }
    }
    return null;
}

function fbo_optimal_price_with_max_discount(array $row, float $maxDiscountPercent): ?float
{
    $current = fbo_current_unit_price($row);
    if ($current === null || $current <= 0) {
        return null;
    }

    $maxDiscountPercent = max(0.0, min(95.0, $maxDiscountPercent));
    $discountFloor = round($current * (1 - $maxDiscountPercent / 100), 2);
    $minPrice = isset($row['min_price']) && (float)$row['min_price'] > 0 ? round((float)$row['min_price'], 2) : 0.0;
    $floor = max($discountFloor, $minPrice);

    $candidates = [];
    foreach (['_elastic_price', '_index_target_price', '_optimal_price'] as $key) {
        if (isset($row[$key]) && (float)$row[$key] > 0) {
            $price = round((float)$row[$key], 2);
            if ($price <= $current) {
                $candidates[] = $price;
            }
        }
    }

    $target = $candidates ? min($candidates) : $discountFloor;
    $target = max($target, $floor);
    $target = min($target, $current);
    return $target > 0 ? round($target, 2) : null;
}

function fbo_price_with_fixed_discount(array $row, float $discountPercent): ?float
{
    $current = fbo_current_unit_price($row);
    if ($current === null || $current <= 0) {
        return null;
    }

    $discountPercent = max(0.0, min(95.0, $discountPercent));
    $target = $current * (1 - $discountPercent / 100);
    return $target > 0 ? round($target, 2) : null;
}

function fbo_rule_status_html(array $row): string
{
    $state = is_array($row['_fbo_price_rule_state'] ?? null) ? (array)$row['_fbo_price_rule_state'] : [];
    $rule = is_array($row['_fbo_price_rule'] ?? null) ? (array)$row['_fbo_price_rule'] : [];
    if (empty($state['has_rule'])) {
        return '<span class="placeholder">—</span>';
    }
    $isActive = !empty($state['is_active']);
    $label = (string)($state['status_label'] ?? '—');
    $daysActive = $state['days_active'] ?? null;
    $parts = [];
    if ($daysActive !== null) {
        $parts[] = 'с момента запуска правила';
    }
    $daysHtml = $daysActive !== null
        ? '<div class="days-value">' . h((string)(int)$daysActive) . '</div><div class="cell-note">дней активно</div>'
        : '';
    $pricingMode = ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), (string)($rule['marketplace'] ?? 'ozon'));
    $modeLabel = $pricingMode === 'promotion_floor' ? 'минимум для акции' : 'точная цена';
    return $daysHtml
        . '<span class="status-chip ' . ($isActive ? 'active' : 'paused') . '">' . h($label) . '</span>'
        . '<div class="cell-note">' . h($modeLabel . ($parts ? ' · ' . implode(' · ', $parts) : '')) . '</div>';
}

$sortParams = [
    'connection_id' => $currentConnectionId,
    'q' => $q,
    'feed_id' => $requestedFeedId,
    'price_min' => $priceMinRaw,
    'price_max' => $priceMaxRaw,
    'sort' => $sortKey,
    'dir' => $sortDir,
];
if (!$onlyWithStock) {
    $sortParams['all'] = '1';
}
if ($requestedWarehouseKey !== '') {
    $sortParams['warehouse_key'] = $requestedWarehouseKey;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — FBO Tool</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f8fb;
      --card: #ffffff;
      --card-soft: #f8fbff;
      --border: #d7e3f0;
      --text: #172033;
      --muted: #64748b;
      --blue: #2563eb;
      --green: #16a34a;
      --orange: #f97316;
      --danger: #dc2626;
      --shadow: 0 18px 42px rgba(15, 23, 42, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background: var(--bg);
      font-size: 16px;
    }
    .topbar, .page { width: min(1800px, calc(100% - 48px)); margin: 0 auto; }
    .topbar { padding: 24px 0 14px; }
    .back-link { color: var(--blue); text-decoration: none; font-weight: 800; }
    .current-connection-card, .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
    }
    .current-connection-card {
      margin-top: 14px;
      padding: 18px 20px;
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: center;
    }
    .current-connection-kicker, .muted { color: var(--muted); }
    .current-connection-title { font-size: 24px; font-weight: 900; margin: 3px 0; }
    .tab-stack { margin-top: 14px; display: grid; gap: 8px; }
    .tab-nav { display: flex; gap: 8px; flex-wrap: wrap; }
    .tab-link, .button-link, button {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      color: var(--text);
      padding: 11px 16px;
      text-decoration: none;
      font-weight: 850;
      cursor: pointer;
      font: inherit;
    }
    .tab-link.active, button.primary {
      background: #1f2937;
      color: #fff;
      border-color: #1f2937;
    }
    .button-link.secondary, button.secondary { background: #f8fbff; }
    .page { padding: 10px 0 40px; }
    .card { padding: 20px; margin-top: 16px; }
    .toolbar {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: center;
      flex-wrap: wrap;
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px;
      margin-top: 14px;
    }
    .stat {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 13px;
      background: var(--card-soft);
    }
    .stat-label { color: var(--muted); font-size: 13px; font-weight: 800; }
    .stat-value { font-size: 24px; font-weight: 950; margin-top: 5px; }
    .flash, .error {
      width: min(1800px, calc(100% - 48px));
      margin: 12px auto 0;
      border-radius: 14px;
      padding: 14px 16px;
      font-weight: 800;
    }
    .flash { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .filters {
      display: grid;
      grid-template-columns: minmax(220px, 1fr) minmax(220px, 320px) minmax(120px, 150px) minmax(120px, 150px) auto auto;
      gap: 10px;
      margin-top: 14px;
    }
    input[type="search"],
    input[type="number"],
    input[type="text"] {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      background: #fff;
    }
    select {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      background: #fff;
      color: var(--text);
    }
    .table-wrap {
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 16px;
      margin-top: 16px;
      background: #fff;
      max-height: calc(100vh - 340px);
    }
    table { border-collapse: separate; border-spacing: 0; min-width: 2380px; width: 100%; }
    th, td {
      padding: 12px;
      border-bottom: 1px solid #e5edf7;
      vertical-align: top;
      text-align: left;
      line-height: 1.18;
    }
    th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #f8fbff;
      color: #334155;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .03em;
      white-space: normal;
    }
    .sort-link { color: inherit; text-decoration: none; }
    .sort-link:hover { color: var(--blue); }
    .offer-id { font-weight: 900; white-space: nowrap; }
    .product-name { min-width: 280px; font-weight: 750; }
    .num { text-align: right; font-weight: 850; }
    .money-value { display: inline-block; white-space: nowrap; }
    .placeholder { color: var(--muted); }
    .cell-note { color: var(--muted); font-size: 12px; font-weight: 750; margin-top: 4px; white-space: normal; overflow-wrap: anywhere; line-height: 1.2; }
    .warn-note { color: #b45309; font-size: 12px; font-weight: 800; margin-top: 6px; max-width: 220px; white-space: normal; overflow-wrap: anywhere; line-height: 1.2; }
    .price-calc-cell { min-width: 160px; }
    .price-calc-cell .cell-note,
    .price-calc-cell .warn-note { margin-left: auto; }
    .discount-cell { min-width: 210px; }
    .days-cell { min-width: 150px; }
    .days-no-sales-cell { min-width: 130px; }
    .zero-fbs-cell { min-width: 170px; }
    .zero-fbs-form { display: grid; gap: 6px; justify-items: start; }
    .zero-fbs-note { color: var(--muted); font-size: 12px; font-weight: 750; line-height: 1.2; }
    .chip {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 4px 8px;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }
    .chip.hot { border-color: #fed7aa; background: #fff7ed; color: #c2410c; }
    .status-chip {
      display: inline-flex;
      border-radius: 999px;
      padding: 4px 8px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #334155;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }
    .status-chip.active { border-color: #bbf7d0; background: #ecfdf5; color: #166534; }
    .status-chip.paused { border-color: #fde68a; background: #fffbeb; color: #92400e; }
    .status-chip.danger { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
    .days-value {
      font-size: 22px;
      line-height: 1;
      font-weight: 950;
      margin-bottom: 5px;
    }
    .discount-form {
      display: grid;
      grid-template-columns: minmax(110px, 1fr);
      gap: 6px;
      min-width: 180px;
    }
    .discount-form input,
    .discount-form select {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 8px;
      font: inherit;
      font-size: 13px;
      background: #fff;
    }
    .discount-form .form-actions {
      grid-column: 1 / -1;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
    }
    .mini-button {
      border-radius: 10px;
      padding: 7px 9px;
      font-size: 12px;
      font-weight: 900;
    }
    .mini-button.danger {
      background: #fef2f2;
      border-color: #fecaca;
      color: #b91c1c;
    }
    .toolbar-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }
    .bulk-panel {
      margin-top: 14px;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: #f8fbff;
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }
    .bulk-group {
      display: inline-flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
      padding: 6px;
      border-radius: 14px;
      background: #fff;
      border: 1px solid #e5edf7;
    }
    .bulk-label {
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .03em;
      white-space: nowrap;
    }
    .bulk-panel .bulk-count {
      border-radius: 999px;
      padding: 7px 10px;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      font-size: 13px;
      font-weight: 900;
    }
    .bulk-panel select {
      padding: 9px 12px;
      min-width: 250px;
    }
    .bulk-panel input[type="number"] {
      width: 160px;
      padding: 9px 12px;
      border-radius: 12px;
    }
    .bulk-panel input[disabled] {
      opacity: .55;
      background: #eef4fb;
    }
    .bulk-panel button[disabled] {
      opacity: .45;
      cursor: not-allowed;
    }
    .fbo-modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 10000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, .42);
    }
    .fbo-modal-backdrop.is-open {
      display: flex;
    }
    .fbo-modal {
      width: min(520px, 100%);
      border: 1px solid #dbe7f6;
      border-radius: 22px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
      padding: 22px;
    }
    .fbo-modal h3 {
      margin: 0 0 10px;
      font-size: 22px;
    }
    .fbo-modal p {
      margin: 0;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.45;
    }
    .fbo-modal-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 18px;
    }
    .select-col {
      width: 42px;
      min-width: 42px;
      text-align: center;
    }
    .select-col input {
      width: 18px;
      height: 18px;
      accent-color: var(--blue);
    }
    @media (max-width: 760px) {
      .topbar, .page, .flash, .error { width: min(100% - 24px, 1800px); }
      .current-connection-card { align-items: stretch; flex-direction: column; }
      .filters { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'marketplace_connections.php?connection_id=' . urlencode((string)$currentConnectionId), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">FBO Tool</h1>
    <div class="current-connection-card">
      <div>
        <div class="current-connection-kicker">Текущий кабинет</div>
        <div class="current-connection-title"><?= h((string)($currentConnection['title'] ?? '—')) ?></div>
        <div class="muted">
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
        <a class="tab-link" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Price Tool</a>
        <a class="tab-link" href="orders_sync.php?connection_id=<?= h((string)$currentConnectionId) ?>">Orders Sync</a>
        <a class="tab-link" href="stocks_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Stocks Tool</a>
        <a class="tab-link" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a>
        <a class="tab-link active" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a>
      </div>
    </div>
  </div>

  <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <div class="page">
    <?php if (!$currentMarketplaceReady): ?>
      <div class="card">
        <h2><?= h($currentMarketplaceLabel) ?>: FBO Tool недоступен</h2>
        <div class="muted">Этот инструмент включается для подключений Ozon и WB.</div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="toolbar">
          <div>
            <h2 style="margin:0;"><?= $isWbMarketplace ? 'Остатки склада WB' : 'Остатки FBO Ozon' ?></h2>
            <div class="muted"><?= $isWbMarketplace ? 'Читаем остатки выбранного склада WB, текущие цены и расчёт Price Tool по FBO-схеме.' : 'Читаем товары с остатком FBO, текущие цены, индекс цены и расчёт Price Tool по FBO-схеме.' ?></div>
          </div>
          <div class="toolbar-actions">
            <?php if ($isWbMarketplace): ?>
              <form method="get" style="margin:0;">
                <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="feed_id" value="<?= h((string)$requestedFeedId) ?>">
                <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
                <input type="hidden" name="dir" value="<?= h($sortDir) ?>">
                <select name="warehouse_key" onchange="this.form.submit()" title="Склад WB для контроля остатков и FBO-цены">
                  <?php if (!$wbWarehouseOptions): ?><option value="">склады WB не найдены</option><?php endif; ?>
                  <?php foreach ($wbWarehouseOptions as $warehouseOption): ?>
                    <option value="<?= h((string)($warehouseOption['warehouse_key'] ?? '')) ?>" <?= (string)($warehouseOption['warehouse_key'] ?? '') === $requestedWarehouseKey ? 'selected' : '' ?>>
                      <?= h((string)($warehouseOption['warehouse_name'] ?? 'WB склад')) ?> · ID <?= h((string)($warehouseOption['warehouse_id'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <?php if ($selectedWarehouse): ?>
                <input type="hidden" name="warehouse_key" value="<?= h((string)($selectedWarehouse['warehouse_key'] ?? '')) ?>">
                <input type="hidden" name="warehouse_id" value="<?= h((string)($selectedWarehouse['warehouse_id'] ?? '')) ?>">
                <input type="hidden" name="warehouse_name" value="<?= h((string)($selectedWarehouse['warehouse_name'] ?? '')) ?>">
              <?php endif; ?>
              <button class="primary" type="submit" name="action" value="refresh_fbo"><?= $isWbMarketplace ? 'Обновить остатки WB' : 'Обновить FBO-остатки' ?></button>
            </form>
            <?php if ($isOzonMarketplace): ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                <button class="secondary" type="submit" name="action" value="sync_ozon_actions">Обновить акции Ozon</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="stats">
          <div class="stat">
            <div class="stat-label"><?= $isWbMarketplace ? 'Товаров с остатком WB' : 'Товаров с FBO остатком' ?></div>
            <div class="stat-value"><?= h(fbo_fmt_int((int)($summary['active_items'] ?? 0))) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label"><?= $isWbMarketplace ? 'Штук на складе WB' : 'Штук доступно FBO' ?></div>
            <div class="stat-value"><?= h(fbo_fmt_int((int)($summary['fbo_present'] ?? 0))) ?></div>
          </div>
          <?php if ($isOzonMarketplace): ?>
            <div class="stat">
              <div class="stat-label">Штук в резерве FBO</div>
              <div class="stat-value"><?= h(fbo_fmt_int((int)($summary['fbo_reserved'] ?? 0))) ?></div>
            </div>
          <?php endif; ?>
          <div class="stat">
            <div class="stat-label">Последнее обновление</div>
            <div class="stat-value" style="font-size:18px;"><?= h((string)($summary['last_refreshed_at'] ?? '—') ?: '—') ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Стоимость FBO по установленной цене</div>
            <div class="stat-value"><?= h(fbo_fmt_rub($valuation['sale_total'] ?? null)) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Себестоимость FBO</div>
            <div class="stat-value"><?= h(fbo_fmt_rub($valuation['cost_total'] ?? null)) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Закупка точная / оценочная</div>
            <div class="stat-value" style="font-size:18px;"><?= h(fbo_fmt_int((int)($valuation['exact_cost_items'] ?? 0)) . ' / ' . fbo_fmt_int((int)($valuation['estimated_cost_items'] ?? 0))) ?></div>
          </div>
          <?php if ($isOzonMarketplace): ?>
            <div class="stat">
              <div class="stat-label">Акций Ozon в кэше</div>
              <div class="stat-value" style="font-size:18px;"><?= h(fbo_fmt_int((int)($actionsSummary['actions_count'] ?? 0))) ?></div>
              <div class="cell-note"><?= h(trim((string)($actionsSummary['last_synced_at'] ?? '')) !== '' ? ('обновлено ' . (string)$actionsSummary['last_synced_at']) : 'нужно обновить акции') ?></div>
            </div>
          <?php endif; ?>
        </div>

        <form class="filters" method="get">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <?php if ($requestedWarehouseKey !== ''): ?><input type="hidden" name="warehouse_key" value="<?= h($requestedWarehouseKey) ?>"><?php endif; ?>
          <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
          <input type="hidden" name="dir" value="<?= h($sortDir) ?>">
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="поиск по артикулу или названию">
          <select name="feed_id" title="Источник расчётов Price Tool: себестоимость, оптимальная цена и схема установки FBO-цены">
            <option value="0">расчёт: все профили Price Tool</option>
            <?php foreach ($priceFeeds as $feed): ?>
              <option value="<?= h((string)($feed['id'] ?? 0)) ?>" <?= (int)($feed['id'] ?? 0) === $requestedFeedId ? 'selected' : '' ?>>
                <?php
                  $feedLabel = trim((string)($feed['name'] ?? '')) !== '' ? (string)$feed['name'] : ('Профиль #' . (int)($feed['id'] ?? 0));
                  $feedConnectionTitle = trim((string)($feed['_connection_title'] ?? ''));
                  if ($feedConnectionTitle !== '') {
                      $feedLabel .= ' · ' . $feedConnectionTitle;
                  }
                ?>
                <?= h($feedLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="price_min" value="<?= h($priceMinRaw) ?>" min="0" step="1" placeholder="цена от">
          <input type="number" name="price_max" value="<?= h($priceMaxRaw) ?>" min="0" step="1" placeholder="цена до">
          <label class="button-link secondary" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="all" value="1" <?= !$onlyWithStock ? 'checked' : '' ?>>
            показать нулевые
          </label>
          <button class="secondary" type="submit">Найти</button>
        </form>

        <form id="fboBulkForm" class="bulk-panel" method="post">
          <?= fbo_state_hidden_inputs($sortParams) ?>
          <input type="hidden" name="zero_fbs_after_save" value="0" data-fbo-zero-after-save>
          <span class="bulk-count" data-fbo-selected-count>выбрано: 0</span>
          <button class="mini-button secondary" type="submit" name="action" value="refresh_selected_fbo" data-fbo-bulk-button disabled>обновить выбранные</button>
          <div class="bulk-group">
            <span class="bulk-label"><?= $isWbMarketplace ? 'Цена WB FBO' : 'Установить сниженную цену' ?></span>
            <?php if ($isWbMarketplace): ?>
              <select name="bulk_pricing_mode" title="Минимум для акции снижает только допустимый порог участия; точная цена принудительно удерживает указанную цену">
                <option value="promotion_floor">Минимум для акции</option>
                <option value="exact">Точная цена продажи</option>
              </select>
            <?php endif; ?>
            <select name="bulk_price_source" title="Схема расчёта сохраняемой сниженной FBO-цены" data-fbo-bulk-source>
              <option value="elastic">Цена Elastic Boosting</option>
              <option value="optimal_with_discount">Оптимально в пределах скидки</option>
              <option value="fixed_discount">Фиксированная скидка от текущей цены</option>
              <option value="index_target">Цена для уровня индекса</option>
              <option value="optimal">Оптимальная цена Price Tool</option>
              <option value="current">Текущая установленная цена</option>
            </select>
            <input type="number" name="bulk_max_discount_percent" min="0" max="95" step="0.1" placeholder="макс. скидка, %" data-fbo-max-discount-input>
            <button class="secondary" type="submit" name="action" value="bulk_save_fbo_rules" data-fbo-bulk-button disabled>сохранить правила</button>
          </div>
          <div class="bulk-group">
            <span class="bulk-label">Выгрузка</span>
            <button class="primary" type="submit" name="action" value="push_selected_fbo_prices" data-fbo-bulk-button disabled>отправить установленные цены в <?= $isWbMarketplace ? 'WB' : 'Ozon' ?></button>
          </div>
          <?php if ($isOzonMarketplace): ?>
            <div class="bulk-group">
              <span class="bulk-label">Обычный Price Tool</span>
              <button class="secondary" type="submit" name="action" value="bulk_restore_regular_prices" data-fbo-bulk-button data-fbo-confirm-restore disabled>вернуть обычные цены</button>
            </div>
          <?php endif; ?>
          <?php if ($isOzonMarketplace): ?>
            <div class="bulk-group">
              <span class="bulk-label">FBS</span>
              <button class="secondary" type="submit" name="action" value="bulk_enable_zero_fbs" data-fbo-bulk-button disabled>обнулять FBS</button>
              <button class="mini-button danger" type="submit" name="action" value="bulk_disable_zero_fbs" data-fbo-bulk-button disabled>не обнулять FBS</button>
            </div>
          <?php endif; ?>
          <button class="mini-button danger" type="submit" name="action" value="bulk_disable_fbo_rules" data-fbo-bulk-button disabled title="Только отключить FBO-правило без немедленного пересчёта цены">только отключить FBO</button>
        </form>
        <?php if ($isOzonMarketplace): ?>
          <div class="fbo-modal-backdrop" data-fbo-zero-modal hidden>
            <div class="fbo-modal" role="dialog" aria-modal="true" aria-labelledby="fboZeroModalTitle">
              <h3 id="fboZeroModalTitle">Обнулять FBS-остатки?</h3>
              <p>Сниженная FBO-цена сохранится для выбранных товаров. Можно сразу включить правило, которое передаёт FBS-остаток 0, пока у товара есть остаток на FBO.</p>
              <div class="fbo-modal-actions">
                <button type="button" class="mini-button secondary" data-fbo-zero-no>Нет, только цены</button>
                <button type="button" class="secondary" data-fbo-zero-yes>Да, обнулять FBS</button>
                <button type="button" class="mini-button danger" data-fbo-zero-cancel>Отмена</button>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="select-col"><input type="checkbox" data-fbo-select-all title="выбрать видимые"></th>
                <th><?= fbo_sort_link('offer', 'Товар', $sortKey, $sortDir, $sortParams) ?></th>
                <th><?= fbo_sort_link('present', $isWbMarketplace ? 'Остаток WB' : 'Остаток FBO', $sortKey, $sortDir, $sortParams) ?></th>
                <?php if ($isOzonMarketplace): ?><th><?= fbo_sort_link('reserved', 'Резерв', $sortKey, $sortDir, $sortParams) ?></th><?php endif; ?>
                <th class="days-no-sales-cell"><?= fbo_sort_link('days_without_sales', 'Дней без продаж', $sortKey, $sortDir, $sortParams) ?></th>
                <?php if ($isOzonMarketplace): ?><th class="zero-fbs-cell">FBS при FBO</th><?php endif; ?>
                <th><?= fbo_sort_link('price', 'Установленная цена', $sortKey, $sortDir, $sortParams) ?></th>
                <th><?= fbo_sort_link('min_price', 'Min price', $sortKey, $sortDir, $sortParams) ?></th>
                <th><?= fbo_sort_link('index', 'Индекс цены', $sortKey, $sortDir, $sortParams) ?></th>
                <th class="price-calc-cell"><?= fbo_sort_link('optimal', 'Оптимальная цена Price Tool', $sortKey, $sortDir, $sortParams) ?></th>
                <th class="price-calc-cell"><?= fbo_sort_link('index_target', 'Цена для уровня индекса', $sortKey, $sortDir, $sortParams) ?></th>
                <th class="price-calc-cell"><?= fbo_sort_link('elastic', 'Цена Elastic Boosting', $sortKey, $sortDir, $sortParams) ?></th>
                <th class="discount-cell"><?= fbo_sort_link('discount_price', $isWbMarketplace ? 'FBO цена / минимум акции' : 'Сниженная FBO цена', $sortKey, $sortDir, $sortParams) ?></th>
                <th class="days-cell"><?= fbo_sort_link('discount_days', 'Дней сниженной цены', $sortKey, $sortDir, $sortParams) ?></th>
                <th><?= fbo_sort_link('sale_total', 'Сумма по установленной цене', $sortKey, $sortDir, $sortParams) ?></th>
                <th><?= fbo_sort_link('cost_total', 'Себестоимость', $sortKey, $sortDir, $sortParams) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="<?= $isOzonMarketplace ? '16' : '14' ?>" class="placeholder">Данных пока нет. Нажми «<?= $isWbMarketplace ? 'Обновить остатки WB' : 'Обновить FBO-остатки' ?>», чтобы загрузить товары.</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $row): ?>
                <?php
                  $discountPrice = fbo_discount_price_default($row);
                  $rule = is_array($row['_fbo_price_rule'] ?? null) ? (array)$row['_fbo_price_rule'] : [];
                  $rulePricingMode = $rule
                      ? ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), $currentMarketplace)
                      : ($isWbMarketplace ? 'promotion_floor' : 'exact');
                  $saleWarning = ozon_fbo_tool_sale_warning($row);
                ?>
                <tr>
                  <td class="select-col">
                    <input type="checkbox" name="selected_offer_ids[]" value="<?= h((string)$row['offer_id']) ?>" form="fboBulkForm" data-fbo-select-row>
                  </td>
                  <td>
                    <div class="offer-id"><?= h((string)$row['offer_id']) ?></div>
                    <div class="product-name"><?= h(trim((string)($row['name'] ?? '')) !== '' ? (string)$row['name'] : '—') ?></div>
                    <?php if ((int)($row['product_id'] ?? 0) > 0): ?><span class="chip">product <?= h((string)$row['product_id']) ?></span><?php endif; ?>
                    <?php if ($saleWarning): ?>
                      <div style="margin-top:6px;"><span class="status-chip danger"><?= h((string)($saleWarning['label'] ?? 'не продаётся')) ?></span></div>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= h(fbo_fmt_int((int)($row['fbo_present'] ?? 0))) ?></td>
                  <?php if ($isOzonMarketplace): ?><td class="num"><?= h(fbo_fmt_int((int)($row['fbo_reserved'] ?? 0))) ?></td><?php endif; ?>
                  <td class="num days-no-sales-cell">
                    <?php if ($row['days_without_sales'] !== null && $row['days_without_sales'] !== ''): ?>
                      <?= h(fbo_fmt_int((int)$row['days_without_sales'])) ?>
                      <?php if ($isOzonMarketplace): ?><div class="cell-note">Ozon</div><?php endif; ?>
                    <?php else: ?>
                      <span class="placeholder">—</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($isOzonMarketplace): ?>
                    <td class="zero-fbs-cell">
                      <form class="zero-fbs-form" method="post">
                        <?= fbo_state_hidden_inputs($sortParams) ?>
                        <input type="hidden" name="offer_id" value="<?= h((string)$row['offer_id']) ?>">
                        <?php if (!empty($row['zero_fbs_while_fbo'])): ?>
                          <span class="status-chip active">FBS = 0</span>
                          <div class="zero-fbs-note"><?= (int)($row['fbo_present'] ?? 0) > 0 ? 'пока есть FBO-остаток' : 'ждёт FBO-остаток' ?></div>
                          <button class="mini-button danger" type="submit" name="action" value="disable_zero_fbs">не обнулять</button>
                        <?php else: ?>
                          <span class="placeholder">обычный FBS</span>
                          <button class="mini-button secondary" type="submit" name="action" value="enable_zero_fbs">обнулять FBS</button>
                        <?php endif; ?>
                      </form>
                    </td>
                  <?php endif; ?>
                  <td class="num"><?= h(fbo_fmt_rub($row['price'] ?? null)) ?></td>
                  <td class="num"><?= h(fbo_fmt_rub($row['min_price'] ?? null)) ?></td>
                  <td><span class="chip hot"><?= h(fbo_index_label($row)) ?></span></td>
                  <td class="num price-calc-cell">
                    <?= fbo_price_cell($row['_optimal_price'] ?? null) ?>
                    <?php if (!empty($row['_price_tool_warning'])): ?><div class="warn-note"><?= h((string)$row['_price_tool_warning']) ?></div><?php endif; ?>
                  </td>
                  <td class="num price-calc-cell"><?= fbo_price_cell($row['_index_target_price'] ?? null, (string)($row['_index_target_label'] ?? '')) ?></td>
                  <td class="num price-calc-cell"><?= fbo_price_cell($row['_elastic_price'] ?? null, (string)($row['_elastic_label'] ?? '')) ?></td>
                  <td class="discount-cell">
                    <form class="discount-form" method="post">
                      <?= fbo_state_hidden_inputs($sortParams) ?>
                      <input type="hidden" name="offer_id" value="<?= h((string)$row['offer_id']) ?>">
                      <input name="target_price" inputmode="decimal" placeholder="цена" value="<?= h(fbo_fmt_input_price($discountPrice)) ?>" required>
                      <?php if ($isWbMarketplace): ?>
                        <select name="pricing_mode" title="Как использовать указанную цену">
                          <option value="promotion_floor" <?= $rulePricingMode === 'promotion_floor' ? 'selected' : '' ?>>минимум для акции</option>
                          <option value="exact" <?= $rulePricingMode === 'exact' ? 'selected' : '' ?>>точная цена</option>
                        </select>
                      <?php endif; ?>
                      <div class="form-actions">
                        <button class="mini-button primary" type="submit" name="action" value="save_fbo_price_rule">сохранить</button>
                        <?php if (!empty($rule)): ?>
                          <?php if ($isOzonMarketplace): ?><button class="mini-button secondary" type="submit" name="action" value="restore_regular_price" data-fbo-confirm-restore formnovalidate>обычная цена</button><?php endif; ?>
                          <button class="mini-button danger" type="submit" name="action" value="disable_fbo_price_rule" title="Только отключить FBO-правило без немедленного пересчёта цены" formnovalidate>откл</button>
                        <?php endif; ?>
                      </div>
                    </form>
                  </td>
                  <td class="days-cell"><?= fbo_rule_status_html($row) ?></td>
                  <td class="num"><?= fbo_price_cell($row['_sale_total'] ?? null, (string)($row['_sale_price_source'] ?? '')) ?></td>
                  <td class="num"><?= fbo_price_cell($row['_cost_total'] ?? null, !empty($row['_cost_estimated']) ? 'примерно' : 'из фида') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <script>
    (function () {
      const rows = Array.from(document.querySelectorAll('[data-fbo-select-row]'));
      const all = document.querySelector('[data-fbo-select-all]');
      const countEl = document.querySelector('[data-fbo-selected-count]');
      const buttons = Array.from(document.querySelectorAll('[data-fbo-bulk-button]'));
      const form = document.getElementById('fboBulkForm');
      const bulkSource = document.querySelector('[data-fbo-bulk-source]');
      const maxDiscountInput = document.querySelector('[data-fbo-max-discount-input]');
      const zeroFbsAfterSave = document.querySelector('[data-fbo-zero-after-save]');
      const zeroModal = document.querySelector('[data-fbo-zero-modal]');
      const zeroYes = document.querySelector('[data-fbo-zero-yes]');
      const zeroNo = document.querySelector('[data-fbo-zero-no]');
      const zeroCancel = document.querySelector('[data-fbo-zero-cancel]');
      const zeroPromptEnabled = <?= $isOzonMarketplace ? 'true' : 'false' ?>;
      let zeroPromptSubmitter = null;
      let zeroPromptApproved = false;
      const syncMaxDiscountInput = () => {
        if (!bulkSource || !maxDiscountInput) return;
        const fixedDiscount = bulkSource.value === 'fixed_discount';
        const enabled = bulkSource.value === 'optimal_with_discount' || fixedDiscount;
        maxDiscountInput.disabled = !enabled;
        maxDiscountInput.required = enabled;
        maxDiscountInput.placeholder = fixedDiscount ? 'скидка, %' : 'макс. скидка, %';
        maxDiscountInput.title = fixedDiscount
          ? 'Фиксированная скидка от текущей установленной цены'
          : 'Максимальная скидка от текущей установленной цены';
      };
      const update = () => {
        const selected = rows.filter((input) => input.checked).length;
        if (countEl) countEl.textContent = 'выбрано: ' + selected;
        buttons.forEach((button) => { button.disabled = selected === 0; });
        if (all) {
          all.checked = rows.length > 0 && selected === rows.length;
          all.indeterminate = selected > 0 && selected < rows.length;
        }
      };
      rows.forEach((input) => input.addEventListener('change', update));
      if (all) {
        all.addEventListener('change', () => {
          rows.forEach((input) => { input.checked = all.checked; });
          update();
        });
      }
      if (bulkSource) {
        bulkSource.addEventListener('change', syncMaxDiscountInput);
      }
      if (form && zeroFbsAfterSave) {
        const closeZeroPrompt = () => {
          if (!zeroModal) return;
          zeroModal.classList.remove('is-open');
          zeroModal.hidden = true;
          zeroPromptSubmitter = null;
        };
        const openZeroPrompt = (submitter) => {
          if (!zeroModal) return false;
          zeroPromptSubmitter = submitter;
          zeroModal.hidden = false;
          zeroModal.classList.add('is-open');
          if (zeroNo) zeroNo.focus();
          return true;
        };
        const submitBulkSave = (zeroFbsEnabled) => {
          zeroFbsAfterSave.value = zeroFbsEnabled ? '1' : '0';
          zeroPromptApproved = true;
          const submitter = zeroPromptSubmitter;
          closeZeroPrompt();
          if (submitter && typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter);
          } else {
            let actionInput = form.querySelector('[data-fbo-fallback-action]');
            if (!actionInput) {
              actionInput = document.createElement('input');
              actionInput.type = 'hidden';
              actionInput.name = 'action';
              actionInput.setAttribute('data-fbo-fallback-action', '1');
              form.appendChild(actionInput);
            }
            actionInput.value = 'bulk_save_fbo_rules';
            form.submit();
          }
        };
        form.addEventListener('submit', (event) => {
          const submitter = event.submitter;
          if (!submitter || submitter.value !== 'bulk_save_fbo_rules') {
            zeroFbsAfterSave.value = '0';
            return;
          }
          if (!zeroPromptEnabled) {
            zeroFbsAfterSave.value = '0';
            return;
          }
          if (zeroPromptApproved) {
            zeroPromptApproved = false;
            return;
          }
          const selected = rows.filter((input) => input.checked).length;
          if (selected === 0) return;
          if (openZeroPrompt(submitter)) {
            event.preventDefault();
            return;
          }
          zeroFbsAfterSave.value = window.confirm('Обнулять FBS-остатки для выбранных товаров, пока есть FBO-остаток?') ? '1' : '0';
        });
        if (zeroYes) zeroYes.addEventListener('click', () => submitBulkSave(true));
        if (zeroNo) zeroNo.addEventListener('click', () => submitBulkSave(false));
        if (zeroCancel) zeroCancel.addEventListener('click', closeZeroPrompt);
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && zeroModal && !zeroModal.hidden) {
            closeZeroPrompt();
          }
        });
      }
      document.querySelectorAll('[data-fbo-confirm-restore]').forEach((button) => {
        button.addEventListener('click', (event) => {
          if (!window.confirm('Отключить FBO-управление и сразу пересчитать выбранные товары по обычным настройкам Price Tool?')) {
            event.preventDefault();
          }
        });
      });
      syncMaxDiscountInput();
      update();
    })();
  </script>
</body>
</html>
