<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/ozon_actions.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
ozon_price_feeds_table_ensure($cfg);
ozon_actions_tables_ensure();

$actor = ft_current_user();
$feeds = [];
$currentFeed = ozon_price_feed_default();
$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
$isNewFeedMode = isset($_GET['new']) && $_GET['new'] === '1';
$cloneFeedId = $isNewFeedMode ? 0 : (int)($_GET['clone_feed_id'] ?? $_GET['clone'] ?? 0);
$isCloneMode = $cloneFeedId > 0;
$selectedId = ($isNewFeedMode || $isCloneMode) ? 0 : (int)($_GET['feed_id'] ?? $_POST['feed_id'] ?? 0);
$routeFeedId = $selectedId > 0 ? $selectedId : $cloneFeedId;
if ($routeFeedId > 0) {
    $routeFeed = ozon_price_feed_get($routeFeedId, null, $cfg);
    $routeConnectionId = (int)($routeFeed['connection_id'] ?? 0);
    if ($routeConnectionId > 0) {
        $requestedConnectionId = $routeConnectionId;
    }
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=price_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$isOzonMarketplace = $currentMarketplace === 'ozon';
$isWbMarketplace = $currentMarketplace === 'wb';
$isYandexMarketplace = $currentMarketplace === 'yandex_market';
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'feeds');
$cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
$flash = '';
$error = '';
$preview = null;
$previewMeta = [
    'download_bytes' => null,
    'final_url' => '',
];
$singleTest = null;
$singleTestPromoRows = [];
$singleTestStrategy = null;
$singleTestDesiredState = null;
$singleTestApplyReport = null;
$recentPushLogs = [];
$batchPushPreview = null;
$batchPushApplyReport = null;
$pushOfferIdsRaw = '';
$wbPreview = null;
$wbPreviewMeta = [
    'goods_total' => null,
];
$wbSingleTest = null;
$wbSingleApplyReport = null;
$wbBatchPushPreview = null;
$wbBatchPushApplyReport = null;
$wbTestArticleRaw = '';
$wbPushArticlesRaw = '';
$wbTariffWarehouseOptions = [];
$wbRuntimeWarning = '';
$yandexPreview = null;
$yandexSingleTest = null;
$yandexSingleApplyReport = null;
$yandexTestArticleRaw = '';
$supplierOptions = [];

$buildSingleTestContext = static function (array $cfg, array $currentFeed, string $offerIdRaw, string $purchaseCostRaw): array {
    $offerId = trim($offerIdRaw);
    $offerId = ozon_price_apply_supplier_code($offerId, (string)($currentFeed['supplier_code'] ?? ''));
    if ($offerId === '') {
        throw new RuntimeException('Укажи артикул товара для тестового расчёта.');
    }

    $purchaseCostRaw = trim($purchaseCostRaw);
    $purchaseCost = ozon_price_parse_cost_value($purchaseCostRaw);
    $feedOffer = null;
    if ($purchaseCost === null || $purchaseCost <= 0) {
        $feedOffer = ozon_price_feed_find_offer($currentFeed, $offerId);
        if (!is_array($feedOffer)) {
            throw new RuntimeException('Не удалось найти товар в текущем фиде. Укажи закупочную цену вручную или проверь артикул.');
        }
        $purchaseCost = isset($feedOffer['purchase_cost']) ? (float)$feedOffer['purchase_cost'] : null;
        $purchaseCostRaw = (string)($feedOffer['cost_raw'] ?? '');
    }
    if ($purchaseCost === null || $purchaseCost <= 0) {
        throw new RuntimeException('Не удалось получить корректную закупочную цену ни из фида, ни из ручного ввода.');
    }
    $ozonItems = ozon_price_fetch_price_items($cfg, [$offerId]);
    $item = $ozonItems[$offerId] ?? null;
    $offer = is_array($feedOffer) ? $feedOffer : [];
    $offer['offer_id'] = $offerId;
    $offer['purchase_cost'] = $purchaseCost;
    $offer['cost_raw'] = $purchaseCostRaw;
    $offer['warnings'] = is_array($offer['warnings'] ?? null) ? $offer['warnings'] : [];
    $calc = ozon_price_calculate_offer($currentFeed, $offer, $item);
    $oz = ozon_cfg_or_fail($cfg);
    $promoRows = ozon_price_calc_is_archived($calc)
        ? []
        : ozon_actions_rows_for_offer_or_product((int)($currentFeed['connection_id'] ?? 0), $offerId, (int)($calc['product_id'] ?? 0), $cfg);
    $strategy = ozon_price_build_promotion_strategy($calc, $promoRows, $currentFeed);
    $desiredState = ozon_price_build_desired_state($calc, $strategy, $promoRows, (int)($currentFeed['connection_id'] ?? 0), $cfg);
    $currentPriceSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, (float)($calc['ozon_price'] ?? 0));
    $recommendedSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, (float)($calc['recommended_price'] ?? 0));
    $currentMinSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, (float)($calc['ozon_min_price'] ?? 0));
    $recommendedMinBeforeIndexSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? 0));
    $recommendedMinSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, (float)($calc['recommended_min_price'] ?? 0));
    $marketingPrice = 0.0;
    $marketingIndexLevel = '';
    $marketingMode = (string)($strategy['mode'] ?? '');
    $marketingSource = '';
    if ($marketingMode === 'action' && !empty($strategy['best_action'])) {
        $marketingPrice = (float)($strategy['best_action']['recommended_action_price'] ?? 0);
        $marketingIndexLevel = (string)($strategy['best_action']['recommended_action_index_level'] ?? '');
        $marketingSource = (string)($strategy['best_action']['title'] ?? 'Акция Ozon');
    } elseif ($marketingMode === 'index' && !empty($strategy['index_strategy'])) {
        $marketingPrice = (float)($strategy['index_strategy']['final_price'] ?? 0);
        $marketingIndexLevel = (string)($strategy['index_strategy']['best_candidate']['index']['label'] ?? '');
        $marketingSource = 'Индекс цены';
    }
    if ($marketingPrice <= 0) {
        $marketingPrice = (float)($strategy['final_price'] ?? 0);
    }
    $marketingSnapshot = ozon_price_profit_snapshot($currentFeed, $item, $purchaseCost, $marketingPrice);

    return [
        'singleTest' => [
            'offer_id' => $offerId,
            'purchase_cost' => $purchaseCost,
            'calc' => $calc,
            'current_snapshot' => $currentPriceSnapshot,
            'recommended_snapshot' => $recommendedSnapshot,
            'current_min_snapshot' => $currentMinSnapshot,
            'recommended_min_before_index_snapshot' => $recommendedMinBeforeIndexSnapshot,
            'recommended_min_snapshot' => $recommendedMinSnapshot,
            'marketing_price' => $marketingPrice,
            'marketing_snapshot' => $marketingSnapshot,
            'marketing_index_level' => $marketingIndexLevel,
            'marketing_mode' => $marketingMode,
            'marketing_source' => $marketingSource,
        ],
        'promoRows' => $promoRows,
        'strategy' => $strategy,
        'desiredState' => $desiredState,
    ];
};

$buildBatchPushPreview = static function (array $cfg, array $currentFeed, string $offerIdsRaw): array {
    $requestedIds = [];
    foreach (preg_split('~[\r\n,;]+~u', $offerIdsRaw) ?: [] as $rawId) {
        $offerId = trim((string)$rawId);
        if ($offerId === '') {
            continue;
        }
        $requestedIds[] = ozon_price_apply_supplier_code($offerId, (string)($currentFeed['supplier_code'] ?? ''));
    }
    $requestedIds = array_values(array_unique(array_filter($requestedIds)));
    if (!$requestedIds) {
        throw new RuntimeException('Добавь список артикулов для ручной выгрузки в Ozon.');
    }

    $download = ozon_price_feed_fetch_remote_xml((string)$currentFeed['feed_url']);
    try {
        $feedOffers = ozon_price_parse_feed((string)$download['path'], (string)$currentFeed['cost_tag']);
    } finally {
        @unlink((string)$download['path']);
    }

    $feedMap = ozon_price_feed_offers_by_id($feedOffers, (string)($currentFeed['supplier_code'] ?? ''), (int)($currentFeed['supplier_id'] ?? 0));
    $selection = ozon_price_feed_offers_for_requested_ids($feedMap, $requestedIds);
    $foundOffers = (array)($selection['offers'] ?? []);
    $missingOfferIds = (array)($selection['missing_ids'] ?? []);

    $ozonItems = ozon_price_fetch_price_items($cfg, $requestedIds);
    $oz = ozon_cfg_or_fail($cfg);
    $rows = [];
    foreach ($foundOffers as $offer) {
        $calc = ozon_price_calculate_offer($currentFeed, $offer, $ozonItems[(string)$offer['offer_id']] ?? null);
        $promoRows = ozon_price_calc_is_archived($calc)
            ? []
            : ozon_actions_rows_for_offer_or_product((int)($currentFeed['connection_id'] ?? 0), (string)$offer['offer_id'], (int)($calc['product_id'] ?? 0), $cfg);
        $strategy = ozon_price_build_promotion_strategy($calc, $promoRows, $currentFeed);
        $desiredState = ozon_price_build_desired_state($calc, $strategy, $promoRows, (int)($currentFeed['connection_id'] ?? 0), $cfg);
        $rows[] = [
            'offer' => $offer,
            'calc' => $calc,
            'promo_rows' => $promoRows,
            'strategy' => $strategy,
            'desired_state' => $desiredState,
        ];
    }

    return [
        'requested_ids' => $requestedIds,
        'missing_ids' => $missingOfferIds,
        'rows' => $rows,
    ];
};

$wbLoadFeedOffers = static function (array $currentFeed): array {
    $offers = wb_price_tool_load_feed_offers($currentFeed);
    $supplierCode = trim((string)($currentFeed['supplier_code'] ?? ''));
    foreach ($offers as &$offer) {
        $offer['offer_id'] = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
    }
    unset($offer);
    return $offers;
};

$wbBuildOfferLookup = static function (array $offers): array {
    $lookup = [];
    foreach ($offers as $offer) {
        if (!is_array($offer)) {
            continue;
        }
        foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && !isset($lookup[$candidate])) {
                $lookup[$candidate] = $offer;
            }
        }
        $offerId = trim((string)($offer['offer_id'] ?? ''));
        $stripped = wb_price_tool_strip_supplier_suffix($offerId);
        if ($stripped !== '' && !isset($lookup[$stripped])) {
            $lookup[$stripped] = $offer;
        }
    }
    return $lookup;
};

$wbDesiredStateNeedsPush = static function (array $calc): bool {
    return wb_price_tool_desired_state_needs_push($calc);
};

$buildWbSingleTestContext = static function (
    array $cfg,
    array $currentConnection,
    array $currentFeed,
    string $articleRaw,
    ?array $goodsIndex = null
): array {
    $article = trim($articleRaw);
    if ($article === '') {
        throw new RuntimeException('Укажи артикул товара для тестового расчёта.');
    }

    $offer = wb_price_tool_find_offer_in_feed($currentFeed, $article);
    if (!is_array($offer)) {
        throw new RuntimeException('Не удалось найти товар в текущем XML-фиде по этому артикулу.');
    }

    $supplierCode = trim((string)($currentFeed['supplier_code'] ?? ''));
    $offer['offer_id'] = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
    $goodsIndex = $goodsIndex ?? wb_price_tool_fetch_all_goods($cfg, $currentConnection, false, 300);
    $runtime = wb_price_tool_runtime_context($cfg, $currentConnection, false);
    $good = wb_price_tool_find_good_for_offer($offer, $goodsIndex);
    $calc = wb_price_tool_calculate_offer($currentFeed, $offer, $good, $runtime);
    $runtimeExpenses = is_array($calc['runtime_expenses'] ?? null) ? $calc['runtime_expenses'] : null;

    $currentSnapshot = null;
    $currentEffectivePrice = (float)($calc['current_club_discounted_price'] ?? 0) > 0
        ? (float)$calc['current_club_discounted_price']
        : (float)($calc['current_discounted_price'] ?? 0);
    if (($calc['purchase_cost'] ?? null) !== null && $currentEffectivePrice > 0) {
        $currentSnapshot = wb_price_tool_profit_snapshot($currentFeed, (float)$calc['purchase_cost'], $currentEffectivePrice, $runtimeExpenses);
    }
    $recommendedSnapshot = null;
    if (($calc['purchase_cost'] ?? null) !== null && (float)($calc['recommended_effective_sale_price'] ?? 0) > 0) {
        $recommendedSnapshot = wb_price_tool_profit_snapshot($currentFeed, (float)$calc['purchase_cost'], (float)$calc['recommended_effective_sale_price'], $runtimeExpenses);
    }
    $baseSnapshot = null;
    if (($calc['purchase_cost'] ?? null) !== null && (float)($calc['recommended_base_effective_sale_price'] ?? 0) > 0) {
        $baseSnapshot = wb_price_tool_profit_snapshot($currentFeed, (float)$calc['purchase_cost'], (float)$calc['recommended_base_effective_sale_price'], $runtimeExpenses);
    }
    $minSnapshot = null;
    if (($calc['purchase_cost'] ?? null) !== null && (float)($calc['recommended_min_effective_sale_price'] ?? 0) > 0) {
        $minSnapshot = wb_price_tool_profit_snapshot($currentFeed, (float)$calc['purchase_cost'], (float)$calc['recommended_min_effective_sale_price'], $runtimeExpenses);
    }

    return [
        'calc' => $calc,
        'current_snapshot' => $currentSnapshot,
        'recommended_snapshot' => $recommendedSnapshot,
        'base_snapshot' => $baseSnapshot,
        'min_snapshot' => $minSnapshot,
        'goods_index' => $goodsIndex,
    ];
};

$buildWbBatchPushPreview = static function (
    array $cfg,
    array $currentConnection,
    array $currentFeed,
    string $articleListRaw
 ) use ($wbLoadFeedOffers, $wbBuildOfferLookup, $wbDesiredStateNeedsPush): array {
    $requestedIds = [];
    foreach (preg_split('~[\r\n,;]+~u', $articleListRaw) ?: [] as $rawId) {
        $candidate = trim((string)$rawId);
        if ($candidate !== '') {
            $requestedIds[] = $candidate;
        }
    }
    $requestedIds = array_values(array_unique($requestedIds));
    if (!$requestedIds) {
        throw new RuntimeException('Добавь список артикулов для ручной выгрузки в WB.');
    }

    $feedOffers = $wbLoadFeedOffers($currentFeed);
    $offerLookup = $wbBuildOfferLookup($feedOffers);
    $feedMap = ozon_price_feed_offers_by_id($feedOffers, (string)($currentFeed['supplier_code'] ?? ''), (int)($currentFeed['supplier_id'] ?? 0));
    $goodsIndex = wb_price_tool_fetch_all_goods($cfg, $currentConnection, false, 300);
    $runtime = wb_price_tool_runtime_context($cfg, $currentConnection, false);

    $rows = [];
    $missingIds = [];
    foreach ($requestedIds as $requestedId) {
        $lookupKey = trim($requestedId);
        $lookupStripped = wb_price_tool_strip_supplier_suffix($lookupKey);
        $normalizedLookupKey = ozon_price_apply_supplier_code($lookupKey, (string)($currentFeed['supplier_code'] ?? ''));
        $offer = ozon_price_feed_offer_for_requested_id($feedMap, $normalizedLookupKey);
        if (!is_array($offer)) {
            $offer = $offerLookup[$lookupKey] ?? ($lookupStripped !== '' ? ($offerLookup[$lookupStripped] ?? null) : null);
        }
        if (!is_array($offer)) {
            $missingIds[] = $requestedId;
            continue;
        }
        $good = wb_price_tool_find_good_for_offer($offer, $goodsIndex);
        $calc = wb_price_tool_calculate_offer($currentFeed, $offer, $good, $runtime);
        $rows[] = [
            'offer' => $offer,
            'calc' => $calc,
            'needs_push' => $wbDesiredStateNeedsPush($calc),
        ];
    }

    return [
        'requested_ids' => $requestedIds,
        'missing_ids' => $missingIds,
        'rows' => $rows,
        'goods_index' => $goodsIndex,
        'runtime' => $runtime,
    ];
};

$buildYandexCalcContext = static function (
    array $currentConnection,
    array $currentFeed,
    ?array $onlyOfferIds = null
): array {
    $context = yandex_price_tool_context($currentConnection);
    $offers = yandex_price_tool_load_feed_offers($currentFeed);
    $offersById = ozon_price_feed_offers_by_id($offers, (string)($currentFeed['supplier_code'] ?? ''), (int)($currentFeed['supplier_id'] ?? 0));
    if (is_array($onlyOfferIds)) {
        $wanted = [];
        foreach ($onlyOfferIds as $rawId) {
            $id = ozon_price_apply_supplier_code(trim((string)$rawId), (string)($currentFeed['supplier_code'] ?? ''));
            if ($id !== '') {
                $wanted[] = $id;
            }
        }
        $selection = ozon_price_feed_offers_for_requested_ids($offersById, $wanted);
        $offersById = [];
        foreach ((array)($selection['offers'] ?? []) as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $offerId = trim((string)($offer['offer_id'] ?? ''));
            if ($offerId !== '') {
                $offersById[$offerId] = $offer;
            }
        }
    }
    $offerIds = array_keys($offersById);
    if (!$offerIds) {
        throw new RuntimeException('Не удалось найти товары в XML-фиде для расчёта Яндекс Маркета.');
    }

    $pricesById = yandex_price_tool_fetch_prices($currentConnection, (int)$context['business_id'], $offerIds, $context);
    $mappingsById = yandex_price_tool_fetch_offer_mappings($currentConnection, (int)$context['business_id'], $offerIds);
    $recommendationsById = yandex_price_tool_fetch_recommendations($currentConnection, (int)$context['business_id'], $offerIds);
    $referencePrices = [];
    foreach ($offerIds as $offerId) {
        $purchase = (float)($offersById[$offerId]['purchase_cost'] ?? 0);
        $referencePrices[$offerId] = yandex_price_tool_extract_price_value($pricesById[$offerId] ?? null, 'value') ?? max(1.0, $purchase * 1.5);
    }
    $tariffWarnings = [];
    try {
        $initialTariffsById = yandex_price_tool_calculate_tariffs($currentConnection, $context, $currentFeed, $offersById, $mappingsById, $referencePrices);
    } catch (Throwable $tariffError) {
        $initialTariffsById = [];
        $tariffWarnings[] = 'Первичный расчёт тарифов Яндекса недоступен: ' . $tariffError->getMessage();
    }
    $targetPrices = [];
    foreach ($offerIds as $offerId) {
        $calc = yandex_price_tool_calculate_offer(
            $currentFeed,
            $offersById[$offerId],
            $pricesById[$offerId] ?? null,
            $mappingsById[$offerId] ?? null,
            $recommendationsById[$offerId] ?? null,
            $initialTariffsById[$offerId] ?? null
        );
        $targetPrices[$offerId] = max(1.0, (float)($calc['recommended_price'] ?? $referencePrices[$offerId]));
    }
    try {
        $targetTariffsById = yandex_price_tool_calculate_tariffs($currentConnection, $context, $currentFeed, $offersById, $mappingsById, $targetPrices);
    } catch (Throwable $tariffError) {
        $targetTariffsById = [];
        $tariffWarnings[] = 'Расчёт тарифов Яндекса по целевым ценам недоступен: ' . $tariffError->getMessage();
    }
    $rows = [];
    foreach ($offerIds as $offerId) {
        $calc = yandex_price_tool_calculate_offer(
            $currentFeed,
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
        $rows[] = [
            'offer' => $offersById[$offerId],
            'calc' => $calc,
            'needs_push' => yandex_price_tool_desired_state_needs_push($calc),
        ];
    }
    return [
        'context' => $context,
        'rows' => $rows,
        'stats' => [
            'offers_total' => count($offerIds),
            'api_prices' => count($pricesById),
            'api_mappings' => count($mappingsById),
            'api_recommendations' => count($recommendationsById),
            'api_tariffs' => max(count($initialTariffsById), count($targetTariffsById)),
        ],
    ];
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' профиль фида Price Tool пока недоступен. На этой странице можно только посмотреть контекст подключения и вернуться к общим настройкам.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_feed') {
            $deleteId = (int)($_POST['id'] ?? 0);
            ozon_price_feed_delete($deleteId, $currentConnectionId, $cfg);
            header('Location: ozon_price_tool_feed.php?new=1&connection_id=' . urlencode((string)$currentConnectionId) . '&deleted=1', true, 303);
            exit;
        }
        if ($action === 'save_feed') {
            $savedId = ozon_price_feed_save($_POST, $actor, $currentConnectionId, $cfg);
            header('Location: ozon_price_tool_feed.php?feed_id=' . urlencode((string)$savedId) . '&connection_id=' . urlencode((string)$currentConnectionId) . '&saved=1', true, 303);
            exit;
        }
        if ($isWbMarketplace && $action === 'preview_feed') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $feedOffers = $wbLoadFeedOffers($currentFeed);
            $goodsIndex = wb_price_tool_fetch_all_goods($cfg, $currentConnection, false, 300);
            $runtime = wb_price_tool_runtime_context($cfg, $currentConnection, false);
            $wbPreviewMeta['goods_total'] = count((array)($goodsIndex['items'] ?? []));
            $wbPreview = wb_price_tool_calculate_preview($currentFeed, $feedOffers, $goodsIndex, $runtime);
        } elseif ($isOzonMarketplace && $action === 'preview_feed') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $download = ozon_price_feed_fetch_remote_xml((string)$currentFeed['feed_url']);
            $previewMeta['download_bytes'] = (int)$download['bytes'];
            $previewMeta['final_url'] = (string)$download['final_url'];

            try {
                $feedOffers = ozon_price_parse_feed((string)$download['path'], (string)$currentFeed['cost_tag']);
                $supplierCode = trim((string)($currentFeed['supplier_code'] ?? ''));
                if ($supplierCode !== '') {
                    foreach ($feedOffers as &$feedOffer) {
                        $feedOffer['offer_id'] = ozon_price_apply_supplier_code((string)($feedOffer['offer_id'] ?? ''), $supplierCode);
                    }
                    unset($feedOffer);
                }
            } finally {
                @unlink((string)$download['path']);
            }

            $offerIds = [];
            foreach ($feedOffers as $offer) {
                $offerId = trim((string)($offer['offer_id'] ?? ''));
                if ($offerId !== '') {
                    $offerIds[] = $offerId;
                }
            }
            $ozonItems = ozon_price_fetch_price_items($cfg, $offerIds);
            $preview = ozon_price_calculate_preview($currentFeed, $feedOffers, $ozonItems);
        } elseif ($isYandexMarketplace && $action === 'preview_feed') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $yandexPreview = $buildYandexCalcContext($currentConnection, $currentFeed, null);
        }
        if ($isWbMarketplace && $action === 'test_single_offer') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $wbTestArticleRaw = (string)($_POST['test_offer_id'] ?? '');
            $context = $buildWbSingleTestContext(
                $cfg,
                $currentConnection,
                $currentFeed,
                $wbTestArticleRaw
            );
            $wbSingleTest = $context;
        } elseif ($isOzonMarketplace && $action === 'test_single_offer') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $context = $buildSingleTestContext(
                $cfg,
                $currentFeed,
                (string)($_POST['test_offer_id'] ?? ''),
                (string)($_POST['test_purchase_cost'] ?? '')
            );
            $singleTest = $context['singleTest'];
            $singleTestPromoRows = $context['promoRows'];
            $singleTestStrategy = $context['strategy'];
            $singleTestDesiredState = $context['desiredState'];
        } elseif ($isYandexMarketplace && $action === 'test_single_offer') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $yandexTestArticleRaw = (string)($_POST['test_offer_id'] ?? '');
            $context = $buildYandexCalcContext($currentConnection, $currentFeed, [$yandexTestArticleRaw]);
            $row = is_array($context['rows'][0] ?? null) ? (array)$context['rows'][0] : null;
            if ($row === null) {
                throw new RuntimeException('Не удалось построить расчёт Яндекс Маркета по этому артикулу.');
            }
            $yandexSingleTest = ['context' => $context['context'], 'row' => $row, 'stats' => $context['stats']];
        }
        if ($isWbMarketplace && $action === 'apply_single_offer_to_wb') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $wbTestArticleRaw = (string)($_POST['test_offer_id'] ?? '');
            $context = $buildWbSingleTestContext(
                $cfg,
                $currentConnection,
                $currentFeed,
                $wbTestArticleRaw
            );
            $wbSingleTest = $context;
            $desiredState = is_array($context['calc']['desired_state'] ?? null) ? (array)$context['calc']['desired_state'] : null;
            if ($desiredState === null) {
                throw new RuntimeException('По этому товару пока нечего отправлять в WB: проверь предупреждения и корректность расчёта.');
            }
            wb_promotions_record_pricing_decision($currentConnectionId, (int)($currentFeed['id'] ?? 0), (array)$context['calc']);
            $wbSingleApplyReport = wb_price_tool_apply_updates($cfg, $currentConnection, [$desiredState]);
            $flash = empty($wbSingleApplyReport['errors'] ?? [])
                ? 'Изменения по товару отправлены в WB.'
                : 'WB принял изменение с частичными предупреждениями. Ниже есть подробности.';
        } elseif ($isOzonMarketplace && $action === 'apply_single_offer_to_ozon') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $context = $buildSingleTestContext(
                $cfg,
                $currentFeed,
                (string)($_POST['test_offer_id'] ?? ''),
                (string)($_POST['test_purchase_cost'] ?? '')
            );
            $singleTest = $context['singleTest'];
            $singleTestPromoRows = $context['promoRows'];
            $singleTestStrategy = $context['strategy'];
            $singleTestDesiredState = $context['desiredState'];
            $singleTestApplyReport = ozon_price_apply_desired_state($cfg, $currentFeed, $singleTestDesiredState, $actor);
            $flash = empty($singleTestApplyReport['errors'])
                ? 'Изменения по товару отправлены в Ozon.'
                : 'Часть изменений отправилась в Ozon с ошибками. Ниже есть подробности.';
        } elseif ($isYandexMarketplace && $action === 'apply_single_offer_to_yandex') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $yandexTestArticleRaw = (string)($_POST['test_offer_id'] ?? '');
            $context = $buildYandexCalcContext($currentConnection, $currentFeed, [$yandexTestArticleRaw]);
            $row = is_array($context['rows'][0] ?? null) ? (array)$context['rows'][0] : null;
            if ($row === null) {
                throw new RuntimeException('Не удалось построить расчёт Яндекс Маркета по этому артикулу.');
            }
            $yandexSingleTest = ['context' => $context['context'], 'row' => $row, 'stats' => $context['stats']];
            $calc = is_array($row['calc'] ?? null) ? (array)$row['calc'] : [];
            $desiredState = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;
            if ($desiredState === null) {
                throw new RuntimeException('По этому товару пока нечего отправлять в Яндекс Маркет: проверь предупреждения и корректность расчёта.');
            }
            $yandexSingleApplyReport = yandex_price_tool_apply_updates($cfg, $currentConnection, [$desiredState]);
            $flash = empty($yandexSingleApplyReport['errors'] ?? [])
                ? 'Изменения по товару отправлены в Яндекс Маркет.'
                : 'Яндекс принял изменение с частичными предупреждениями. Ниже есть подробности.';
        }
        if ($isWbMarketplace && $action === 'build_batch_push_preview') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $wbPushArticlesRaw = (string)($_POST['push_offer_ids'] ?? '');
            $wbBatchPushPreview = $buildWbBatchPushPreview($cfg, $currentConnection, $currentFeed, $wbPushArticlesRaw);
        } elseif ($isOzonMarketplace && $action === 'build_batch_push_preview') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $pushOfferIdsRaw = (string)($_POST['push_offer_ids'] ?? '');
            $batchPushPreview = $buildBatchPushPreview($cfg, $currentFeed, $pushOfferIdsRaw);
        }
        if ($isWbMarketplace && $action === 'apply_batch_offer_list') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $wbPushArticlesRaw = (string)($_POST['push_offer_ids'] ?? '');
            $wbBatchPushPreview = $buildWbBatchPushPreview($cfg, $currentConnection, $currentFeed, $wbPushArticlesRaw);
            $desiredStates = [];
            foreach ((array)($wbBatchPushPreview['rows'] ?? []) as $batchRow) {
                $calc = (array)($batchRow['calc'] ?? []);
                if (!$wbDesiredStateNeedsPush($calc)) {
                    continue;
                }
                $desiredState = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;
                if ($desiredState !== null) {
                    wb_promotions_record_pricing_decision($currentConnectionId, (int)($currentFeed['id'] ?? 0), $calc);
                    $desiredStates[] = $desiredState;
                }
            }
            $wbBatchPushApplyReport = wb_price_tool_apply_updates($cfg, $currentConnection, $desiredStates);
            $flash = empty($wbBatchPushApplyReport['errors'] ?? [])
                ? 'Список товаров отправлен в WB.'
                : 'WB принял список с частичными предупреждениями. Ниже есть подробности.';
        } elseif ($isOzonMarketplace && $action === 'apply_batch_offer_list') {
            $currentFeed = ozon_price_feed_get((int)($_POST['feed_id'] ?? 0), $currentConnectionId, $cfg) ?: ozon_price_feed_default();
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
            $selectedId = (int)($currentFeed['id'] ?? 0);
            $pushOfferIdsRaw = (string)($_POST['push_offer_ids'] ?? '');
            $batchPushPreview = $buildBatchPushPreview($cfg, $currentFeed, $pushOfferIdsRaw);
            $batchPushApplyReport = [
                'rows' => [],
                'errors' => [],
            ];
            foreach ((array)($batchPushPreview['rows'] ?? []) as $batchRow) {
                try {
                    $batchPushApplyReport['rows'][] = ozon_price_apply_desired_state(
                        $cfg,
                        $currentFeed,
                        (array)$batchRow['desired_state'],
                        $actor
                    );
                } catch (Throwable $applyError) {
                    $batchPushApplyReport['errors'][] = ((string)($batchRow['offer']['offer_id'] ?? '')) . ': ' . $applyError->getMessage();
                }
            }
            $flash = $batchPushApplyReport['errors']
                ? 'Список товаров отправился в Ozon с частичными ошибками. Ниже есть подробности.'
                : 'Список товаров отправлен в Ozon.';
        }
    }

    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    if (isset($_GET['saved']) && $_GET['saved'] === '1') {
        $flash = 'Ценовой профиль сохранён.';
    } elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
        $flash = 'Ценовой профиль удалён.';
    }
    if ($selectedId > 0) {
        $selectedFeed = ozon_price_feed_get($selectedId, $currentConnectionId, $cfg) ?: ozon_price_feed_get($selectedId, null, $cfg);
        if (is_array($selectedFeed) && ozon_price_feed_supplier_is_archived($selectedFeed)) {
            $error = 'Этот ценовой профиль связан с поставщиком в архиве и больше не доступен в службах.';
            $selectedId = 0;
        } elseif (is_array($selectedFeed)) {
            $currentFeed = $selectedFeed;
            $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
            $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
            $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
        }
    } elseif ($isCloneMode) {
        $sourceFeed = ozon_price_feed_get($cloneFeedId, $currentConnectionId, $cfg) ?: ozon_price_feed_get($cloneFeedId, null, $cfg);
        if (is_array($sourceFeed) && ozon_price_feed_supplier_is_archived($sourceFeed)) {
            $error = 'Нельзя копировать ценовой профиль поставщика из архива.';
        } elseif (is_array($sourceFeed)) {
            $currentFeed = $sourceFeed;
            $currentFeed['id'] = 0;
            $currentFeed['name'] = '';
            $currentFeed['supplier_id'] = 0;
            $currentFeed['feed_url'] = '';
            $currentFeed['supplier_code'] = '';
            $currentFeed['connection_id'] = $currentConnectionId;
            $flash = 'Создан новый профиль на основе текущих настроек. Выбери поставщика и заполни название профиля.';
        } else {
            $error = 'Не удалось найти профиль, настройки которого нужно скопировать.';
        }
    } elseif ($feeds && !$isNewFeedMode) {
        $currentFeed = $feeds[0];
        $selectedId = (int)$currentFeed['id'];
        $currentConnectionId = (int)($currentFeed['connection_id'] ?? $currentConnectionId);
        $currentConnection = ozon_price_connection_resolve($currentConnectionId, $cfg);
        $cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
    }
    if ($isOzonMarketplace && (int)($currentFeed['id'] ?? 0) > 0) {
        $recentPushLogs = ozon_price_push_log_recent_for_feed((int)$currentFeed['id'], 10, $currentConnectionId, $cfg);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    if ($selectedId > 0 && (($currentFeed['id'] ?? 0) <= 0)) {
        $selectedFeed = ozon_price_feed_get($selectedId, $currentConnectionId, $cfg) ?: ozon_price_feed_get($selectedId, null, $cfg);
        if (is_array($selectedFeed) && !ozon_price_feed_supplier_is_archived($selectedFeed)) {
            $currentFeed = $selectedFeed;
        }
    }
    if ($isOzonMarketplace && (int)($currentFeed['id'] ?? 0) > 0) {
        $recentPushLogs = ozon_price_push_log_recent_for_feed((int)$currentFeed['id'], 10, $currentConnectionId, $cfg);
    }
}

if ($isWbMarketplace && $currentMarketplaceReady) {
    try {
        $wbRuntime = wb_price_tool_runtime_context($cfg, $currentConnection, false, false);
        $wbTariffWarehouseOptions = wb_price_tool_tariff_warehouse_options($wbRuntime);
    } catch (Throwable $wbRuntimeError) {
        $wbRuntimeWarning = $wbRuntimeError->getMessage();
    }
}
$supplierOptions = suppliers_list(true, $cfg);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_rub($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' ₽';
}

function fmt_percent($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . '%';
}

function price_tool_snapshot_total_costs(?array $snapshot): ?float
{
    if (!is_array($snapshot)) {
        return null;
    }
    $breakdown = is_array($snapshot['breakdown'] ?? null) ? (array)$snapshot['breakdown'] : [];
    if (!isset($breakdown['sale_price'], $breakdown['purchase_cost'], $snapshot['profit_rub'])) {
        return null;
    }
    $total = (float)$breakdown['sale_price'] - (float)$breakdown['purchase_cost'] - (float)$snapshot['profit_rub'];
    return round(max(0.0, $total), 2);
}

function price_tool_cost_source_label(string $source, string $marketplace): string
{
    $source = strtolower(trim($source));
    if ($source === 'api') {
        return $marketplace . ' API';
    }
    if ($source === 'missing_api') {
        return 'API не вернул тарифы';
    }
    if ($source === 'manual') {
        return 'Настройки профиля';
    }
    return $source !== '' ? $source : 'Настройки профиля';
}

function price_tool_fbo_adjustment(array $desiredState): ?array
{
    $adjustment = is_array($desiredState['price_adjustment'] ?? null) ? (array)$desiredState['price_adjustment'] : null;
    if (!is_array($adjustment) || (string)($adjustment['type'] ?? '') !== 'fbo') {
        return null;
    }
    return $adjustment;
}

function render_fbo_adjustment_card(array $desiredState): string
{
    $adjustment = price_tool_fbo_adjustment($desiredState);
    if (!is_array($adjustment)) {
        return '';
    }
    $source = trim((string)($adjustment['source_line'] ?? ''));
    return '<div class="fbo-adjustment-card">'
        . '<div class="fbo-adjustment-head"><span class="fbo-badge">FBO</span><strong>Применено правило снижения цены FBO</strong></div>'
        . '<div class="fbo-adjustment-grid">'
        . '<div><span>Обычный price</span><b>' . h(fmt_rub($adjustment['regular_price_before'] ?? null)) . '</b></div>'
        . '<div><span>Итоговый price</span><b>' . h(fmt_rub($adjustment['regular_price_after'] ?? null)) . '</b></div>'
        . '<div><span>Обычный min price</span><b>' . h(fmt_rub($adjustment['min_price_before'] ?? null)) . '</b></div>'
        . '<div><span>Итоговый min price</span><b>' . h(fmt_rub($adjustment['min_price_after'] ?? null)) . '</b></div>'
        . '</div>'
        . ($source !== '' ? '<div class="muted" style="margin-top:8px;">' . h($source) . '</div>' : '')
        . '</div>';
}

function fmt_index_value($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ');
}

function index_level_key($value): string
{
    $raw = mb_strtolower(trim((string)$value));
    return match ($raw) {
        'super', 'супер-выгодный' => 'super',
        'green', 'good', 'выгодный' => 'good',
        'yellow', 'moderate', 'умеренный' => 'moderate',
        'red', 'bad', 'невыгодный' => 'bad',
        'without_index', 'без индекса' => 'without',
        default => $raw === '' || $raw === '—' ? 'without' : 'without',
    };
}

function index_level_label($value): string
{
    return match (index_level_key($value)) {
        'super' => 'Супер-выгодный',
        'good' => 'Выгодный',
        'moderate' => 'Умеренный',
        'bad' => 'Невыгодный',
        default => 'Без индекса',
    };
}

function render_index_badge($value): string
{
    $key = index_level_key($value);
    $label = index_level_label($value);
    $icon = match ($key) {
        'super' => '<svg class="index-icon-svg index-icon-super" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M10.92 7.899a1.25 1.25 0 0 1 1.66 1.869L8.83 13.1a1.25 1.25 0 0 1-1.66 0L3.42 9.768a1.25 1.25 0 1 1 1.66-1.868L8 10.495l2.92-2.596Z"/><path fill="currentColor" d="M10.92 2.899a1.25 1.25 0 0 1 1.66 1.869L8.83 8.1a1.25 1.25 0 0 1-1.66 0L3.42 4.768A1.25 1.25 0 1 1 5.08 2.9L8 5.495l2.92-2.596Z"/></svg>',
        'good' => '<svg class="index-icon-svg index-icon-good" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M10.92 7.899a1.25 1.25 0 0 1 1.66 1.869L8.83 13.1a1.25 1.25 0 0 1-1.66 0L3.42 9.768a1.25 1.25 0 1 1 1.66-1.868L8 10.495l2.92-2.596Z"/><path fill="currentColor" d="M10.92 2.899a1.25 1.25 0 0 1 1.66 1.869L8.83 8.1a1.25 1.25 0 0 1-1.66 0L3.42 4.768A1.25 1.25 0 1 1 5.08 2.9L8 5.495l2.92-2.596Z"/></svg>',
        'moderate' => '<svg class="index-icon-svg index-icon-moderate" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M10.92 5.399a1.25 1.25 0 0 1 1.66 1.869L8.83 10.6a1.25 1.25 0 0 1-1.66 0L3.42 7.268A1.25 1.25 0 1 1 5.08 5.4L8 7.995l2.92-2.596Z"/></svg>',
        'bad' => '<svg class="index-icon-svg index-icon-bad" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M10.92 10.601a1.25 1.25 0 0 0 1.66-1.869L8.83 5.4a1.25 1.25 0 0 0-1.66 0L3.42 8.732a1.25 1.25 0 1 0 1.66 1.868L8 8.005l2.92 2.596Z"/><path fill="currentColor" d="M10.92 15.601a1.25 1.25 0 0 0 1.66-1.869L8.83 10.4a1.25 1.25 0 0 0-1.66 0l-3.75 3.332a1.25 1.25 0 1 0 1.66 1.868L8 13.005l2.92 2.596Z"/></svg>',
        default => '',
    };
    return '<span class="index-pill index-' . h($key) . '">'
        . ($icon !== '' ? '<span class="index-icon" aria-hidden="true">' . $icon . '</span>' : '')
        . '<span class="index-text">' . h($label) . '</span>'
        . '</span>';
}

function fmt_bytes_simple($bytes): string
{
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, 2, ',', ' ') . ' ' . $units[$i];
}

function fmt_input_decimal($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return str_replace('.', ',', number_format((float)$value, 2, '.', ''));
}

function fmt_plain_decimal($value, int $decimals = 3): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, $decimals, ',', ' ');
}

function profit_range_rows_for_view(array $feed, string $key): array
{
    $rows = $feed[$key] ?? [];
    if (!is_array($rows) || !$rows) {
        return [['from' => '', 'to' => '', 'percent' => '']];
    }
    return array_map(static function (array $row): array {
        return [
            'from' => $row['from'] === null ? '' : fmt_input_decimal($row['from']),
            'to' => ($row['to'] ?? null) === null ? '' : fmt_input_decimal($row['to']),
            'percent' => fmt_input_decimal($row['percent'] ?? 0),
        ];
    }, $rows);
}

$targetRangeRows = profit_range_rows_for_view($currentFeed, 'target_profit_ranges');
$minTargetRangeRows = profit_range_rows_for_view($currentFeed, 'min_target_profit_ranges');

function shipment_mix_base_adjustment_percent(array $feed): float
{
    $rules = [
        'ship_0_12_percent' => -3.0,
        'ship_12_24_percent' => -2.0,
        'ship_24_36_percent' => 1.0,
        'ship_36_48_percent' => 2.0,
        'ship_48_plus_percent' => 3.0,
    ];
    $sum = 0.0;
    foreach ($rules as $key => $rate) {
        $sum += max(0.0, (float)($feed[$key] ?? 0)) * $rate / 100.0;
    }
    return round($sum, 4);
}

function shipment_mix_total_percent(array $feed): float
{
    $sum = 0.0;
    foreach (['ship_0_12_percent', 'ship_12_24_percent', 'ship_24_36_percent', 'ship_36_48_percent', 'ship_48_plus_percent'] as $key) {
        $sum += max(0.0, (float)($feed[$key] ?? 0));
    }
    return round($sum, 2);
}

$fieldHelp = [
    'supplier_id' => 'Общий поставщик задаёт название, код и ссылку на XML. В Price Tool здесь остаются только настройки расчёта.',
    'feed_url' => 'Источник XML. По этой ссылке сервис скачивает фид и ищет товары для расчёта.',
    'cost_tag' => 'Название XML-тега, из которого берётся закупочная цена товара.',
    'supplier_code' => 'Необязательный код поставщика. Если заполнен, сервис добавляет его к offer_id в формате __код, если такого суффикса ещё нет.',
    'fulfillment_scheme' => 'Выбирает, какие Ozon-комиссии и логистические тарифы использовать в формуле: FBS или FBO.',
    'target_profit_percent' => 'Целевой доход для обычной цены. Считается как процент от закупочной цены.',
    'min_target_profit_percent' => 'То же самое для минимальной цены. Отличается только этот процент, остальные расходы общие.',
    'target_profit_min_rub' => 'Нижний порог дохода для обычной цены. В формуле берётся максимум из процента от закупки и этой суммы в рублях.',
    'min_target_profit_min_rub' => 'Нижний порог дохода для минимальной цены. В формуле берётся максимум из процента от закупки и этой суммы в рублях.',
    'min_price_index_step_enabled' => 'Если включено, сервис может дополнительно снизить min price ради перехода на следующий уровень индекса цены. Снижение допускается только если до нужного уровня хватает не более 5%, а итоговая цена ставится на порог перехода минус 10 ₽.',
    'action_pricing_enabled' => 'Если включено, сервис будет рассчитывать акционную цену, искать подходящие акции Ozon и строить итоговый маркетинговый план через акции. Если выключено, для установки цены используется только индекс цены.',
    'target_profit_ranges' => 'Необязательные диапазоны закупочной цены. Если товар попал в диапазон, сервис возьмёт этот процент вместо базового значения для обычной цены. При спорной границе используется первая подходящая строка сверху вниз.',
    'min_target_profit_ranges' => 'Та же логика для min price. Если диапазон не подошёл, сервис использует базовый процент min price. При спорной границе используется первая подходящая строка сверху вниз.',
    'rounding_mode' => 'Применяется в самом конце, уже после всех расходов и целевой прибыли.',
    'price_modifier_mode' => 'Необязательный финальный модификатор для обычной цены: можно сдвинуть результат на процент или на фиксированную сумму.',
    'price_modifier_value' => 'Значение модификатора обычной цены. Можно указывать положительное или отрицательное число.',
    'price_modifier_min_mode' => 'Необязательный финальный модификатор для минимальной цены: можно сдвинуть результат на процент или на фиксированную сумму.',
    'price_modifier_min_value' => 'Значение модификатора минимальной цены. Можно указывать положительное или отрицательное число.',
    'fulfillment_markup_rub' => 'Фиксированный расход в рублях на упаковку товара на складе. Добавляется к постоянным расходам.',
    'fulfillment_markup_percent' => 'Дополнительный расход на упаковку склада как процент от финальной цены. Добавляется к процентным расходам.',
    'nonbuyout_processing_rub' => 'Тариф Ozon за обработку невыкупа. Для FBS по умолчанию ставим 50 ₽, но значение можно скорректировать под ваш тариф.',
    'return_processing_rub' => 'Тариф Ozon за обработку возврата. Для FBS по умолчанию ставим 50 ₽ как консервативное значение из диапазона 15–50 ₽.',
    'ship_0_12_percent' => 'Доля заказов, которые отгружаются в первые 12 часов. Для них к комиссии применяется скидка 3%.',
    'ship_12_24_percent' => 'Доля заказов, которые отгружаются за 12–24 часа. Для них к комиссии применяется скидка 2%.',
    'ship_24_36_percent' => 'Доля заказов, которые отгружаются за 24–36 часов. Для них считается штраф 1%, но не меньше 50 ₽.',
    'ship_36_48_percent' => 'Доля заказов, которые отгружаются за 36–48 часов. Для них считается штраф 2%, но не меньше 100 ₽.',
    'ship_48_plus_percent' => 'Доля заказов, которые отгружаются позже 48 часов. Для них считается штраф 3%, но не меньше 100 ₽.',
    'nonbuyout_percent' => 'Процент невыкупов. Для FBS считаем упаковку, прямую логистику, первую милю, обратную логистику и обработку невыкупа. Затем эти расходы распределяются на успешные продажи.',
    'return_resellable_percent' => 'Процент возвратов с повторной продажей. Для FBS считаем упаковку, последнюю милю, прямую логистику, первую милю, обратную логистику и обработку возврата.',
    'return_nonresellable_percent' => 'Процент безвозвратных потерь. Для них считаются обратная логистика, обработка и потери в закупочной цене товара.',
    'include_returns_in_cost' => 'Если включено, сервис распределяет ожидаемые потери на успешные продажи и добавляет их в расчёт цены.',
    'promotion_percent' => 'Дополнительный процент от финальной цены. Добавляется к переменным расходам в формуле.',
    'credit_percent' => 'Дополнительный процент от финальной цены на стоимость кредита/рассрочки.',
    'extra_expenses_percent' => 'Дополнительные прочие расходы в процентах от финальной цены. Добавляются к переменным расходам.',
    'strike_discount_percent' => 'Если указано, сервис дополнительно рассчитывает зачёркнутую цену так, чтобы скидка до обычной цены была примерно на этот процент.',
    'tax_mode' => 'Налоговый режим для расчёта. УСН доходы считается от выручки, а УСН доходы-расходы — от положительной прибыли.',
    'tax_percent' => 'Ставка выбранного налогового режима. Для УСН доходы это процент от выручки, для УСН доходы-расходы — процент от положительной прибыли.',
    'vat_percent' => 'Если нужно, добавляем НДС как процент от цены продажи.',
    'profit_tax_percent' => 'Дополнительный налог на прибыль. Считается от положительной прибыли после остальных расходов и налогов.',
    'insurance_percent' => 'Добавляется к переменным расходам как дополнительный процент от финальной цены.',
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>FeedTools — Price Tool</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      max-width: 1460px;
      margin: 24px auto;
      padding: 0 16px 40px;
      background: #f8fafc;
      color: #111827;
    }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 1000;
      display: inline-flex;
      align-items: center;
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid #f59e0b;
      background: rgba(255, 251, 235, 0.97);
      color: #92400e;
      box-shadow: 0 12px 28px rgba(146, 64, 14, .14);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .topbar, .card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 18px;
      box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
      min-width: 0;
    }
    .workspace-block {
      margin-bottom: 18px;
      border: 1px solid #dbe7f6;
      border-radius: 22px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
      overflow: hidden;
    }
    .workspace-block > summary {
      list-style: none;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      padding: 20px 22px 16px;
      cursor: pointer;
      user-select: none;
    }
    .workspace-block > summary::-webkit-details-marker {
      display: none;
    }
    .workspace-block > summary::after {
      content: "▾";
      flex: 0 0 auto;
      font-size: 18px;
      line-height: 1;
      color: #475569;
      margin-top: 6px;
      transition: transform 0.18s ease;
    }
    .workspace-block:not([open]) > summary::after {
      transform: rotate(-90deg);
    }
    .workspace-block-title-wrap {
      display: grid;
      gap: 6px;
      min-width: 0;
    }
    .workspace-block-title {
      font-size: 30px;
      line-height: 1.05;
      font-weight: 900;
      color: #1e293b;
    }
    .workspace-block-subtitle {
      color: #64748b;
      line-height: 1.5;
      max-width: 980px;
    }
    .workspace-block-body {
      padding: 0 18px 18px;
    }
    .status-modal {
      position: fixed;
      inset: 0;
      z-index: 5000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.48);
      backdrop-filter: blur(4px);
    }
    .status-modal.is-open {
      display: flex;
    }
    .status-modal-card {
      width: min(560px, 100%);
      padding: 28px 28px 24px;
      border-radius: 24px;
      border: 1px solid rgba(191, 219, 254, 0.9);
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
    }
    .status-modal-title {
      margin: 0 0 10px;
      font-size: 30px;
      line-height: 1.05;
      font-weight: 900;
      color: #0f172a;
    }
    .status-modal-text {
      margin: 0;
      color: #64748b;
      line-height: 1.6;
      font-size: 18px;
    }
    .status-modal-stage {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-top: 22px;
      padding: 16px 18px;
      border-radius: 18px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1d4ed8;
      font-weight: 700;
      font-size: 18px;
    }
    .status-modal-spinner {
      width: 22px;
      height: 22px;
      border-radius: 999px;
      border: 3px solid rgba(37, 99, 235, 0.18);
      border-top-color: #2563eb;
      animation: status-modal-spin 0.9s linear infinite;
      flex: 0 0 auto;
    }
    .status-modal-note {
      margin-top: 16px;
      color: #94a3b8;
      font-size: 14px;
      line-height: 1.5;
    }
    @keyframes status-modal-spin {
      to {
        transform: rotate(360deg);
      }
    }
    .workspace-block-body > .card:last-child {
      margin-bottom: 0;
    }
    .topbar {
      padding: 20px 22px;
      margin-bottom: 18px;
    }
    .card {
      padding: 18px 20px;
      margin-bottom: 18px;
    }
    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 18px;
      align-items: start;
    }
    .layout > * {
      min-width: 0;
    }
    .feeds-list {
      display: grid;
      gap: 10px;
    }
    .feed-item {
      display: block;
      padding: 14px;
      border-radius: 14px;
      border: 1px solid #e2e8f0;
      background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
      text-decoration: none;
      color: #111827;
    }
    .feed-item.active {
      border-color: #0f766e;
      box-shadow: 0 10px 24px rgba(15, 118, 110, 0.10);
    }
    .muted {
      color: #64748b;
    }
    form.settings-form {
      display: grid;
      gap: 18px;
    }
    .grid {
      display: grid;
      gap: 14px;
      grid-template-columns: repeat(3, minmax(220px, 1fr));
    }
    .section {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    }
    .section h3 {
      margin: 0 0 6px;
      font-size: 18px;
    }
    .section > .muted {
      margin-bottom: 14px;
      line-height: 1.45;
    }
    .section.section-shipment {
      border-color: #bfdbfe;
      background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%);
      box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.06);
    }
    .section.section-shipment h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section.section-shipment h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #2563eb;
      box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.10);
      flex: 0 0 auto;
    }
    .section.section-risk {
      border-color: #fed7aa;
      background: linear-gradient(180deg, #fffaf5 0%, #fff6ed 100%);
      box-shadow: inset 0 0 0 1px rgba(249, 115, 22, 0.06);
    }
    .section.section-risk h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section.section-risk h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #f97316;
      box-shadow: 0 0 0 6px rgba(249, 115, 22, 0.10);
      flex: 0 0 auto;
    }
    .section.section-target {
      border-color: #bbf7d0;
      background: linear-gradient(180deg, #f7fff9 0%, #eefcf3 100%);
      box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.06);
    }
    .section.section-target h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section.section-target h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #16a34a;
      box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.10);
      flex: 0 0 auto;
    }
    .section.section-costs {
      border-color: #c7d2fe;
      background: linear-gradient(180deg, #f8faff 0%, #f1f5ff 100%);
      box-shadow: inset 0 0 0 1px rgba(79, 70, 229, 0.06);
    }
    .section.section-costs h3,
    .section.section-extra h3,
    .section.section-tax h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section.section-costs h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #4f46e5;
      box-shadow: 0 0 0 6px rgba(79, 70, 229, 0.10);
      flex: 0 0 auto;
    }
    .section.section-extra {
      border-color: #bfdbfe;
      background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%);
      box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.06);
    }
    .section.section-extra h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #2563eb;
      box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.10);
      flex: 0 0 auto;
    }
    .section.section-tax {
      border-color: #fde68a;
      background: linear-gradient(180deg, #fffdf5 0%, #fff9e8 100%);
      box-shadow: inset 0 0 0 1px rgba(217, 119, 6, 0.06);
    }
    .section.section-tax h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #d97706;
      box-shadow: 0 0 0 6px rgba(217, 119, 6, 0.10);
      flex: 0 0 auto;
    }
    .field {
      display: grid;
      gap: 7px;
      align-content: start;
    }
    .section.section-promo {
      border-color: #bae6fd;
      background: linear-gradient(180deg, #f8fdff 0%, #eefaff 100%);
      box-shadow: inset 0 0 0 1px rgba(14, 165, 233, 0.06);
    }
    .section.section-promo h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section.section-promo h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #0ea5e9;
      box-shadow: 0 0 0 6px rgba(14, 165, 233, 0.10);
      flex: 0 0 auto;
    }
    .promo-list {
      display: grid;
      gap: 12px;
      margin-top: 12px;
    }
    .promo-item {
      border: 1px solid #dbeafe;
      border-radius: 14px;
      padding: 14px;
      background: #fff;
    }
    .promo-grid {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(5, minmax(120px, 1fr));
      margin-top: 10px;
    }
    .promo-kv {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      background: #f8fafc;
    }
    .promo-kv .label {
      font-size: 12px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 6px;
    }
    .promo-kv .value {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.1;
    }
    label {
      font-size: 13px;
      color: #475569;
      font-weight: 600;
    }
    input[type=text], input[type=url], input[type=number], select {
      width: 100%;
      box-sizing: border-box;
      padding: 11px 12px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      font: inherit;
      background: #fff;
      color: #111827;
    }
    .field small {
      color: #64748b;
      line-height: 1.35;
      min-height: 54px;
    }
    .formula-box {
      border: 1px dashed #cbd5e1;
      border-radius: 14px;
      padding: 14px 16px;
      background: #f8fafc;
      line-height: 1.6;
    }
    .formula-box code {
      display: inline-block;
      margin: 2px 0;
    }
    .shipment-summary {
      margin: 2px 0 14px;
      padding: 14px;
      border: 1px solid #dbeafe;
      border-radius: 14px;
      background: linear-gradient(180deg, #f8fbff 0%, #f1f7ff 100%);
      display: grid;
      gap: 10px;
    }
    .shipment-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .shipment-chip {
      display: inline-flex;
      align-items: center;
      padding: 8px 12px;
      border-radius: 999px;
      background: #e2e8f0;
      color: #0f172a;
      font-size: 13px;
      font-weight: 700;
    }
    .shipment-chip.ok {
      background: #dcfce7;
      color: #166534;
    }
    .shipment-chip.warn {
      background: #fef3c7;
      color: #92400e;
    }
    .shipment-chip.error {
      background: #fee2e2;
      color: #991b1b;
    }
    .shipment-note {
      color: #475569;
      line-height: 1.45;
      font-size: 13px;
    }
    .range-editors {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }
    .target-subgrid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }
    .target-box {
      border: 1px solid #d9efe0;
      border-radius: 14px;
      padding: 14px;
      background: rgba(255, 255, 255, 0.78);
      display: grid;
      gap: 12px;
    }
    .target-box h4 {
      margin: 0;
      font-size: 16px;
    }
    .index-step-box {
      border: 1px solid #86efac;
      border-radius: 16px;
      padding: 14px 16px;
      background: linear-gradient(180deg, #f0fdf4 0%, #ecfdf5 100%);
      box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.06);
      display: grid;
      gap: 8px;
    }
    .index-step-box h5 {
      margin: 0;
      font-size: 15px;
      color: #166534;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .index-step-box h5::before {
      content: "";
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: #16a34a;
      box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.10);
      flex: 0 0 auto;
    }
    .index-step-box .muted {
      margin: 0;
      line-height: 1.45;
    }
    .index-step-toggle {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-weight: 700;
      color: #14532d;
    }
    .index-step-toggle input {
      margin-top: 4px;
    }
    .modifier-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }
    .modifier-box {
      border: 1px solid #dbeafe;
      border-radius: 14px;
      padding: 14px;
      background: #f8fbff;
      display: grid;
      gap: 12px;
    }
    .modifier-box h4 {
      margin: 0;
      font-size: 16px;
    }
    .range-box {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px;
      background: #f8fafc;
    }
    .range-box h4 {
      margin: 0 0 6px;
      font-size: 15px;
    }
    .range-head,
    .range-row {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr auto;
      gap: 10px;
      align-items: end;
    }
    .range-head {
      margin: 12px 0 8px;
      font-size: 12px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .range-row {
      margin-bottom: 10px;
    }
    .range-row input {
      min-width: 0;
    }
    .range-remove {
      padding: 11px 12px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #0f172a;
      cursor: pointer;
      font: inherit;
    }
    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    button, .button-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 16px;
      border-radius: 11px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      cursor: pointer;
      text-decoration: none;
      font: inherit;
    }
    button.secondary, .button-link.secondary {
      background: #fff;
      color: #0f172a;
      border-color: #cbd5e1;
    }
    button.danger, .button-link.danger {
      background: #fff1f2;
      color: #b42318;
      border-color: #fecdd3;
    }
    .flash {
      padding: 12px 14px;
      border-radius: 12px;
      margin-bottom: 14px;
      border: 1px solid #bbf7d0;
      background: #f0fdf4;
      color: #166534;
    }
    .error {
      padding: 12px 14px;
      border-radius: 12px;
      margin-bottom: 14px;
      border: 1px solid #fecaca;
      background: #fef2f2;
      color: #991b1b;
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }
    .preview-toolbar {
      display: grid;
      gap: 12px;
      grid-template-columns: 1.6fr repeat(4, minmax(180px, .8fr));
      margin: 0 0 14px;
      align-items: end;
    }
    .preview-toolbar .field {
      margin: 0;
    }
    .stat {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .stat .label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #64748b;
      margin-bottom: 8px;
    }
    .stat .value {
      font-size: 24px;
      font-weight: 700;
    }
    .hero-reference {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 16px;
    }
    .hero-compare {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-top: 16px;
    }
    .hero-card {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .hero-card.current {
      border-color: #cbd5e1;
    }
    .hero-card.recommended {
      border-color: #0f766e;
      box-shadow: 0 14px 30px rgba(15, 118, 110, .08);
    }
    .hero-card.marketing {
      border-color: #16a34a;
      box-shadow: 0 14px 30px rgba(22, 163, 74, .10);
      background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
    }
    .hero-card.compact {
      padding: 14px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .hero-title {
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #64748b;
      margin-bottom: 10px;
      font-weight: 700;
    }
    .hero-price {
      font-size: 34px;
      font-weight: 800;
      line-height: 1.1;
      margin-bottom: 12px;
    }
    .hero-card.compact .hero-title {
      margin-bottom: 8px;
    }
    .hero-card.compact .hero-price {
      font-size: 24px;
      margin-bottom: 10px;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }
    .hero-compact-meta {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 8px;
    }
    .hero-metric {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 12px;
      background: rgba(255,255,255,.8);
    }
    .hero-metric .label {
      font-size: 11px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 6px;
    }
    .hero-metric .value {
      font-size: 20px;
      font-weight: 700;
    }
    .hero-card.compact .hero-metric {
      padding: 10px;
    }
    .hero-card.compact .hero-metric .label {
      font-size: 10px;
      margin-bottom: 4px;
    }
    .hero-card.compact .hero-metric .value {
      font-size: 16px;
    }
    .index-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      line-height: 1.15;
      white-space: normal;
      color: #111827;
    }
    .index-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 16px;
      flex: 0 0 auto;
    }
    .index-icon-svg {
      display: block;
      width: 16px;
      height: 16px;
    }
    .index-icon-super {
      color: #4f46e5;
    }
    .index-icon-good {
      color: #22c55e;
    }
    .index-icon-moderate {
      color: #d97706;
    }
    .index-icon-bad {
      color: #ef5b52;
    }
    .index-text {
      display: inline-block;
      color: #111827;
    }
    .index-without .index-text {
      color: #94a3b8;
    }
    .table-index-badge {
      white-space: normal;
      line-height: 1.25;
    }
    .delta-banner {
      margin-top: 14px;
      padding: 12px 14px;
      border-radius: 14px;
      background: #ecfeff;
      border: 1px solid #a5f3fc;
      color: #155e75;
      font-weight: 600;
    }
    .fbo-adjustment-card {
      margin: 14px 0;
      padding: 14px;
      border-radius: 16px;
      border: 1px solid #bfdbfe;
      background: linear-gradient(180deg, #eff6ff 0%, #f8fbff 100%);
    }
    .fbo-adjustment-head {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 12px;
    }
    .fbo-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      border: 1px solid #93c5fd;
      background: #dbeafe;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 900;
      padding: 4px 8px;
      letter-spacing: .04em;
    }
    .fbo-adjustment-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 10px;
    }
    .fbo-adjustment-grid div {
      border: 1px solid #dbeafe;
      border-radius: 12px;
      padding: 10px 12px;
      background: rgba(255,255,255,.85);
    }
    .fbo-adjustment-grid span {
      display: block;
      color: #64748b;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 4px;
    }
    .fbo-adjustment-grid b {
      font-size: 20px;
    }
    .inline-note {
      color: #64748b;
      font-size: 12px;
      line-height: 1.35;
      margin-top: 4px;
    }
    .strategy-card {
      border: 1px solid #bbf7d0;
      border-radius: 18px;
      padding: 18px;
      background: linear-gradient(180deg, #f7fff9 0%, #eefcf3 100%);
      margin-top: 18px;
    }
    .strategy-card h3 {
      margin: 0 0 8px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .strategy-card h3::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #16a34a;
      box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.10);
      flex: 0 0 auto;
    }
    .strategy-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 14px;
    }
    .strategy-kv {
      border: 1px solid #dbeafe;
      border-radius: 14px;
      padding: 14px;
      background: rgba(255,255,255,0.9);
    }
    .strategy-kv .label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #64748b;
      margin-bottom: 8px;
    }
    .strategy-kv .value {
      font-size: 30px;
      font-weight: 800;
      line-height: 1.1;
    }
    .strategy-note {
      margin-top: 12px;
      padding: 12px 14px;
      border-radius: 14px;
      background: #ffffff;
      border: 1px solid #d1fae5;
      color: #166534;
      line-height: 1.5;
    }
    .strategy-action-list {
      display: grid;
      gap: 12px;
      margin-top: 14px;
    }
    .strategy-action-item {
      border: 1px solid #dbeafe;
      border-radius: 14px;
      padding: 14px;
      background: #fff;
    }
    .strategy-action-item.preferred {
      border-color: #0f766e;
      box-shadow: 0 14px 28px rgba(15, 118, 110, 0.10);
    }
    .strategy-action-top {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: flex-start;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }
    .strategy-badge {
      display: inline-flex;
      align-items: center;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid #bbf7d0;
      background: #f0fdf4;
      color: #166534;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .03em;
      text-transform: uppercase;
    }
    .trace-layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 16px;
      margin-top: 18px;
    }
    .trace-card {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      overflow: hidden;
      background: #fff;
      width: 100%;
    }
    .trace-card h3 {
      margin: 0;
      padding: 14px 16px;
      font-size: 16px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    .trace-card table {
      font-size: 12px;
      table-layout: fixed;
    }
    .trace-card th,
    .trace-card td {
      padding: 8px 7px;
      word-break: break-word;
    }
    .trace-stage {
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #475569;
      font-size: 11px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: #fff;
    }
    th, td {
      padding: 10px 8px;
      border-bottom: 1px solid #e5e7eb;
      text-align: left;
      vertical-align: top;
    }
    th {
      position: sticky;
      top: 0;
      background: #f8fafc;
      z-index: 1;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #475569;
    }
    .table-wrap {
      overflow: auto;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      max-height: 70vh;
    }
    .status-ok {
      color: #166534;
      font-weight: 700;
    }
    .status-warn {
      color: #92400e;
      font-weight: 700;
    }
    .warning-list {
      margin: 0;
      padding-left: 18px;
      color: #92400e;
    }
    details {
      cursor: pointer;
    }
    code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 2px 6px;
    }
    @media (max-width: 1120px) {
      .layout {
        grid-template-columns: 1fr;
      }
      .grid {
        grid-template-columns: 1fr 1fr;
      }
      .hero-reference,
      .hero-compare {
        grid-template-columns: 1fr;
      }
      .hero-compact-meta {
        grid-template-columns: 1fr;
      }
      .trace-layout {
        grid-template-columns: 1fr;
      }
      .preview-toolbar {
        grid-template-columns: 1fr 1fr;
      }
      .range-editors {
        grid-template-columns: 1fr;
      }
      .target-subgrid {
        grid-template-columns: 1fr;
      }
      .modifier-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 760px) {
      .grid {
        grid-template-columns: 1fr;
      }
      .preview-toolbar {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'ozon_price_tool.php?connection_id=' . urlencode((string)$currentConnectionId), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start;">
      <div>
        <h1 style="margin:0 0 8px;">Ценовой профиль Price Tool</h1>
        <div class="muted">Здесь живут настройки расчёта для выбранного поставщика, быстрый тест товара и предпросмотр. Набор параметров и шаги расчёта зависят от выбранного маркетплейса. Текущий кабинет: <?= h(ozon_price_connection_title_short($currentConnection)) ?>.</div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="button-link secondary" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">К общим настройкам</a>
        <a class="button-link secondary" href="suppliers.php">Поставщики</a>
        <a class="button-link" href="ozon_price_tool_feed.php?new=1&connection_id=<?= h((string)$currentConnectionId) ?>">Новый профиль</a>
        <?php if ((int)($currentFeed['id'] ?? 0) > 0): ?>
          <a class="button-link secondary" href="ozon_price_tool_feed.php?clone_feed_id=<?= h((string)($currentFeed['id'] ?? 0)) ?>&connection_id=<?= h((string)$currentConnectionId) ?>">Копировать настройки</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (!$currentMarketplaceReady): ?>
    <div class="layout">
      <div>
        <div class="card">
          <h2 style="margin:0 0 8px;">Ценовой профиль для <?= h($currentMarketplaceLabel) ?> пока в подготовке</h2>
          <div class="muted" style="margin-bottom:16px;">
            Подключение уже можно хранить и выбирать в интерфейсе, но сам профиль фида, калькулятор и выгрузка пока работают только для Ozon.
          </div>
          <div class="stats">
            <div class="stat">
              <div class="label">Текущее подключение</div>
              <div class="value" style="font-size:24px;"><?= h(ozon_price_connection_title_short($currentConnection)) ?></div>
            </div>
            <div class="stat">
              <div class="label">Маркетплейс</div>
              <div class="value" style="font-size:24px;"><?= h($currentMarketplaceLabel) ?></div>
            </div>
            <div class="stat">
              <div class="label">Статус Price Tool</div>
              <div class="value" style="font-size:24px;">Скоро</div>
            </div>
          </div>
          <div class="muted" style="margin-top:16px;">
            Уже сейчас можно подготовить подключение, а рабочие расчёты, акции, выгрузку цен и автоматизацию для этого маркетплейса мы подключим следующим этапом.
          </div>
          <div class="actions" style="margin-top:18px;">
            <a class="button-link secondary" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Вернуться к общим настройкам</a>
            <a class="button-link secondary" href="ozon_price_tool_automations.php?connection_id=<?= h((string)$currentConnectionId) ?>">Открыть автоматизацию</a>
          </div>
        </div>
      </div>
    </div>
  <?php elseif ($isWbMarketplace): ?>
  <div class="layout">
    <div>
      <details class="workspace-block" data-storage-key="settings" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Настройки WB</div>
            <div class="workspace-block-subtitle">В этой ветке Price Tool мы берём закупку из XML, считаем целевую цену для Wildberries и всегда тянем комиссию, логистику и возвратные тарифы напрямую из WB API.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <h2 style="margin-top:0;">Ценовой профиль WB</h2>
            <form class="settings-form" method="post">
              <input id="feedAction" type="hidden" name="action" value="save_feed">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <input type="hidden" name="id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">

              <div class="section">
                <h3>Источник данных</h3>
                <div class="muted">Этот блок задаёт XML-фид и то, как из него читаются закупка и артикул поставщика.</div>
                <div class="grid">
                  <div class="field">
                    <label for="name">Название профиля</label>
                    <input id="name" name="name" type="text" value="<?= h((string)($currentFeed['name'] ?? '')) ?>" required>
                  </div>
                  <div class="field">
                    <label for="supplier_id">Поставщик</label>
                    <select id="supplier_id" name="supplier_id" required>
                      <option value="">Выбери поставщика</option>
                      <?php foreach ($supplierOptions as $supplier): ?>
                        <?php
                          $supplierId = (int)($supplier['id'] ?? 0);
                          $supplierLabel = trim((string)($supplier['name'] ?? ''));
                          $supplierCode = trim((string)($supplier['supplier_code'] ?? ''));
                          if ($supplierCode !== '') {
                              $supplierLabel .= ' · ' . $supplierCode;
                          }
                        ?>
                        <option value="<?= h((string)$supplierId) ?>" <?= ((int)($currentFeed['supplier_id'] ?? 0) === $supplierId) ? 'selected' : '' ?>>
                          <?= h($supplierLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small><?= h($fieldHelp['supplier_id']) ?> <a href="suppliers.php">Открыть раздел поставщиков</a></small>
                  </div>
                  <div class="field">
                    <label for="cost_tag">Тег закупочной цены</label>
                    <input id="cost_tag" name="cost_tag" type="text" value="<?= h((string)($currentFeed['cost_tag'] ?? '')) ?>" required>
                  </div>
                  <div class="field">
                    <label>Источник данных</label>
                    <div class="muted" style="font-size:14px;">
                      <?php if ((int)($currentFeed['supplier_id'] ?? 0) > 0): ?>
                        Поставщик: <?= h((string)($currentFeed['supplier_name'] ?? '')) ?><br>
                        Код: <code><?= h((string)($currentFeed['supplier_code'] ?? '')) ?></code><br>
                        XML: <a href="<?= h((string)($currentFeed['feed_url'] ?? '')) ?>" target="_blank" rel="noopener">открыть источник</a>
                      <?php else: ?>
                        Выбери поставщика, чтобы подтянуть код и XML-источник.
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="section section-target">
                <h3>Экономика WB</h3>
                <div class="muted">Здесь задаётся, как считать цену для WB: комиссия, логистика и тариф возврата всегда берутся из API Wildberries, а в профиле остаются только настройки цены, скидок и доходности.</div>
                <div class="grid">
                  <div class="field">
                    <label for="wb_tariff_warehouse_name">Тарифный склад WB</label>
                    <select id="wb_tariff_warehouse_name" name="wb_tariff_warehouse_name">
                      <option value="">Выбрать тарифный склад автоматически</option>
                      <?php foreach ($wbTariffWarehouseOptions as $warehouseName => $warehouseLabel): ?>
                        <option value="<?= h((string)$warehouseName) ?>" <?= (($currentFeed['wb_tariff_warehouse_name'] ?? '') === (string)$warehouseName) ? 'selected' : '' ?>><?= h((string)$warehouseLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>По этому складу WB отдаёт тариф логистики, который попадёт в формулу цены.</small>
                  </div>
                  <div class="field">
                    <label for="wb_discount_percent">Скидка продавца WB, %</label>
                    <input id="wb_discount_percent" name="wb_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_discount_percent'] ?? '0.00')) ?>">
                    <small>Эта скидка используется при расчёте списка на WB: сервис поднимает базовую цену так, чтобы после скидки обычный покупатель видел целевую цену продажи.</small>
                  </div>
                  <div class="field">
                    <label for="wb_club_discount_percent">Скидка WB Клуба, %</label>
                    <input id="wb_club_discount_percent" name="wb_club_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_club_discount_percent'] ?? '0.00')) ?>">
                    <small>Дополнительная скидка для подписчиков WB Клуба. Допустимы значения 0 или от 3% до 31%. В текущей модели это отдельный маркетинговый слой поверх основной цены продажи.</small>
                  </div>
                  <div class="field">
                    <label class="checkbox-line">
                      <input type="checkbox" name="wb_promotion_pricing_enabled" value="1" <?= !empty($currentFeed['wb_promotion_pricing_enabled']) ? 'checked' : '' ?>>
                      <span>Учитывать акции WB в расчёте</span>
                    </label>
                    <small>Если товар есть в активной акции WB из календаря или XLSX, сервис сравнит плановую цену акции с нижней границей: min price минус запас. Подходящая акция получит цену под участие, неподходящая оставит товар на базовой цене.</small>
                  </div>
                  <div class="field">
                    <label for="wb_promotion_max_plan_discount_percent">Максимальная скидка акции WB, %</label>
                    <input id="wb_promotion_max_plan_discount_percent" name="wb_promotion_max_plan_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_promotion_max_plan_discount_percent'] ?? '60.00')) ?>">
                    <small>Контрольный порог риска: если выбранная акция требует скидку выше него, сервис покажет предупреждение, но главным ограничителем остаётся min price.</small>
                  </div>
                  <div class="field">
                    <label for="wb_promotion_min_margin_percent">Запас вниз от min price для акции, %</label>
                    <input id="wb_promotion_min_margin_percent" name="wb_promotion_min_margin_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_promotion_min_margin_percent'] ?? '5.00')) ?>">
                    <small>Акция выбирается, если её плановая цена не ниже min price минус этот запас. Например, при min price 5 000 ₽ и запасе 5% нижняя граница будет 4 750 ₽.</small>
                  </div>
                  <div class="field">
                    <label for="wb_future_promo_discount_mode">Ожидаемая скидка будущей автоакции</label>
                    <select id="wb_future_promo_discount_mode" name="wb_future_promo_discount_mode">
                      <?php foreach ([
                        'auto' => 'Авто по сохранённым акциям',
                        'manual' => 'Задать вручную',
                      ] as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= (($currentFeed['wb_future_promo_discount_mode'] ?? 'auto') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>Используется для диагностики будущих автоакций и предупреждений. Текущая базовая цена заранее не поднимается: цена меняется только под активную подходящую акцию.</small>
                  </div>
                  <div class="field">
                    <label for="wb_future_promo_discount_percent">Ручная ожидаемая скидка, %</label>
                    <input id="wb_future_promo_discount_percent" name="wb_future_promo_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_future_promo_discount_percent'] ?? '0.00')) ?>">
                    <small>Используется только в ручном режиме. Это финальная скидка будущей автоакции, без дополнительного буфера.</small>
                  </div>
                  <div class="field">
                    <label for="wb_future_promo_discount_buffer_percent">Буфер к авто-прогнозу, %</label>
                    <input id="wb_future_promo_discount_buffer_percent" name="wb_future_promo_discount_buffer_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['wb_future_promo_discount_buffer_percent'] ?? '2.00')) ?>">
                    <small>В авто-режиме сервис берёт статистику плановых скидок по автоакциям и добавляет этот запас.</small>
                  </div>
                  <div class="field">
                    <label for="wb_future_promo_prepare_days">Подготовка к будущей автоакции, дней</label>
                    <input id="wb_future_promo_prepare_days" name="wb_future_promo_prepare_days" type="number" min="0" max="60" step="1" value="<?= h((string)(int)($currentFeed['wb_future_promo_prepare_days'] ?? 7)) ?>">
                    <small>Показывает будущие акции в расчёте и предупреждениях. Сервис не поднимает текущую цену заранее, чтобы товар не висел по защитной завышенной цене до старта акции.</small>
                  </div>
                  <div class="field">
                    <label class="checkbox-line">
                      <input type="checkbox" name="wb_promotion_action_upload_enabled" value="1" <?= !empty($currentFeed['wb_promotion_action_upload_enabled']) ? 'checked' : '' ?>>
                      <span>Добавлять в обычные акции WB через API</span>
                    </label>
                    <small>Для автоакций WB участие задаётся ценой и скидкой. Для обычных акций сервис дополнительно отправит подходящий товар в акцию, если API это позволяет.</small>
                  </div>
                  <div class="field">
                    <label for="rounding_mode">Округление</label>
                    <select id="rounding_mode" name="rounding_mode">
                      <?php foreach ([
                        'rub' => 'До 1 ₽',
                        '5rub' => 'До 5 ₽ вверх',
                        '10rub' => 'До 10 ₽ вверх',
                        'end9' => 'Окончание на 9',
                        'end90' => 'Окончание на 90',
                        'end99' => 'Окончание на 99',
                      ] as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= (($currentFeed['rounding_mode'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="target-subgrid" style="grid-column:1 / -1;">
                    <div class="target-box">
                      <h4>Базовая цена WB</h4>
                      <div class="muted">Высокая цена, на которой товар остаётся без подходящей акции. Это верхний сценарий с отдельной доходностью.</div>
                      <div class="field">
                        <label for="target_profit_percent">Доход базовой цены, % от закупки</label>
                        <input id="target_profit_percent" name="target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_percent'] ?? '20.00')) ?>">
                        <small><?= h($fieldHelp['target_profit_percent']) ?></small>
                      </div>
                      <div class="field">
                        <label for="target_profit_min_rub">Минимальный доход базовой цены, ₽</label>
                        <input id="target_profit_min_rub" name="target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_min_rub'] ?? '0.00')) ?>">
                        <small><?= h($fieldHelp['target_profit_min_rub']) ?></small>
                      </div>
                      <div class="range-box" data-range-editor="target">
                        <h4>Диапазоны базовой цены</h4>
                        <div class="muted"><?= h($fieldHelp['target_profit_ranges']) ?></div>
                        <div class="range-head">
                          <div>От закупки</div>
                          <div>До закупки</div>
                          <div>Доход, %</div>
                          <div></div>
                        </div>
                        <div data-range-rows>
                          <?php foreach ($targetRangeRows as $rangeRow): ?>
                            <div class="range-row">
                              <input type="text" inputmode="decimal" name="target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                              <input type="text" inputmode="decimal" name="target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                              <input type="text" inputmode="decimal" name="target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="20">
                              <button type="button" class="range-remove" data-range-remove>×</button>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <div class="actions">
                          <button type="button" class="secondary" data-range-add="target">Добавить диапазон</button>
                        </div>
                      </div>
                    </div>
                    <div class="target-box">
                      <h4>Минимальная цена WB</h4>
                      <div class="muted">Нижний порог продажи. Акция WB подходит только если её плановая цена не ниже этого расчёта.</div>
                      <div class="field">
                        <label for="min_target_profit_percent">Доход min price, % от закупки</label>
                        <input id="min_target_profit_percent" name="min_target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_percent'] ?? '10.00')) ?>">
                        <small><?= h($fieldHelp['min_target_profit_percent']) ?></small>
                      </div>
                      <div class="field">
                        <label for="min_target_profit_min_rub">Минимальный доход min price, ₽</label>
                        <input id="min_target_profit_min_rub" name="min_target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_min_rub'] ?? '0.00')) ?>">
                        <small><?= h($fieldHelp['min_target_profit_min_rub']) ?></small>
                      </div>
                      <div class="range-box" data-range-editor="min_target">
                        <h4>Диапазоны min price</h4>
                        <div class="muted"><?= h($fieldHelp['min_target_profit_ranges']) ?></div>
                        <div class="range-head">
                          <div>От закупки</div>
                          <div>До закупки</div>
                          <div>Доход, %</div>
                          <div></div>
                        </div>
                        <div data-range-rows>
                          <?php foreach ($minTargetRangeRows as $rangeRow): ?>
                            <div class="range-row">
                              <input type="text" inputmode="decimal" name="min_target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                              <input type="text" inputmode="decimal" name="min_target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                              <input type="text" inputmode="decimal" name="min_target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="10">
                              <button type="button" class="range-remove" data-range-remove>×</button>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <div class="actions">
                          <button type="button" class="secondary" data-range-add="min_target">Добавить диапазон</button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php if ($wbRuntimeWarning !== ''): ?>
                  <div class="card" style="margin-top:14px; padding:14px; background:#fffaf3; border-color:#fed7aa;">
                    <strong>WB API сейчас недоступен для чтения тарифов.</strong>
                    <div class="muted" style="margin-top:6px;"><?= h($wbRuntimeWarning) ?></div>
                  </div>
                <?php else: ?>
                  <div class="muted" style="margin-top:12px;">
                    Сервис подтягивает:
                    <strong>комиссию категории</strong> через <code>/tariffs/commission</code>,
                    <strong>логистику</strong> через <code>/tariffs/box</code>,
                    а карточку товара и его категорию — через <code>/content/v2/get/cards/list</code>.
                  </div>
                <?php endif; ?>
                <div class="card" style="margin-top:14px; padding:14px; background:#f8fbff;">
                  <strong>Ранжирование и акции WB</strong>
                  <div class="muted" style="margin-top:6px;">
                    На WB нет прямого Ozon-style индекса цены в публичном API. На выдачу сильнее влияют итоговая цена, участие в акциях, скорость доставки, объём продаж и скидка WB Клуба. В этой ветке Price Tool мы управляем ценой, скидкой продавца и WB Клубом; участие в акциях WB подключено как следующий отдельный слой, чтобы не перегружать обычную выгрузку цен.
                  </div>
                </div>
              </div>

              <div class="section section-extra">
                <h3>Финальный сдвиг цены</h3>
                <div class="muted">После расчёта доходности можно отдельно сдвинуть базовую цену и min price. Это удобно, если базу нужно искусственно поднять выше, а нижний порог оставить более осторожным.</div>
                <div class="target-subgrid">
                  <div class="target-box">
                    <h4>Сдвиг базовой цены</h4>
                    <div class="field">
                      <label for="price_modifier_mode">Режим модификатора</label>
                      <select id="price_modifier_mode" name="price_modifier_mode">
                        <option value="none" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не применять</option>
                        <option value="percent" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'percent') ? 'selected' : '' ?>>Изменить на %</option>
                        <option value="fixed" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Изменить на ₽</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="price_modifier_value">Значение модификатора</label>
                      <input id="price_modifier_value" name="price_modifier_value" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['price_modifier_value'] ?? '0.00')) ?>" placeholder="Например: 5 или -100">
                    </div>
                  </div>
                  <div class="target-box">
                    <h4>Сдвиг min price</h4>
                    <div class="field">
                      <label for="price_modifier_min_mode">Режим модификатора min price</label>
                      <select id="price_modifier_min_mode" name="price_modifier_min_mode">
                        <option value="none" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не применять</option>
                        <option value="percent" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'percent') ? 'selected' : '' ?>>Изменить на %</option>
                        <option value="fixed" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Изменить на ₽</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="price_modifier_min_value">Значение модификатора min price</label>
                      <input id="price_modifier_min_value" name="price_modifier_min_value" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['price_modifier_min_value'] ?? '0.00')) ?>" placeholder="Например: -3 или -50">
                    </div>
                  </div>
                </div>
              </div>

              <div class="section section-costs">
                <h3>Прочие расходы</h3>
                <div class="muted">Эти расходы учитываются в формуле WB как фиксированные или процентные надбавки к продаже.</div>
                <div class="grid">
                  <div class="field">
                    <label for="fulfillment_markup_rub">Фиксированные расходы, ₽</label>
                    <input id="fulfillment_markup_rub" name="fulfillment_markup_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['fulfillment_markup_rub'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="promotion_percent">Продвижение, %</label>
                    <input id="promotion_percent" name="promotion_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['promotion_percent'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="credit_percent">Кредит/рассрочка, %</label>
                    <input id="credit_percent" name="credit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['credit_percent'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="extra_expenses_percent">Прочие расходы, %</label>
                    <input id="extra_expenses_percent" name="extra_expenses_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['extra_expenses_percent'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="insurance_percent">Страховые взносы, %</label>
                    <input id="insurance_percent" name="insurance_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['insurance_percent'] ?? '0.00')) ?>">
                  </div>
                </div>
              </div>

              <div class="section section-risk">
                <h3>Невыкупы, возвраты и потери WB</h3>
                <div class="muted">Этот блок распределяет ожидаемые потери на успешные продажи. Логистика возврата берётся из WB API, а проценты и обработки задаются вручную, потому что WB не отдаёт универсальную прогнозную модель выкупа по API.</div>
                <div class="grid">
                  <div class="field">
                    <label class="index-step-toggle" style="margin-top:28px;">
                      <input type="checkbox" name="include_returns_in_cost" value="1" <?= !empty($currentFeed['include_returns_in_cost']) ? 'checked' : '' ?>>
                      <span>Учитывать потери в цене</span>
                    </label>
                    <small>Если выключено, проценты ниже сохраняются, но не влияют на расчёт цены.</small>
                  </div>
                  <div class="field">
                    <label for="nonbuyout_percent">Невыкупы, %</label>
                    <input id="nonbuyout_percent" name="nonbuyout_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_percent'] ?? '0.00')) ?>">
                    <small>Расход: доставка до покупателя + возвратный тариф WB + ручная обработка невыкупа.</small>
                  </div>
                  <div class="field">
                    <label for="return_resellable_percent">Возвраты с повторной продажей, %</label>
                    <input id="return_resellable_percent" name="return_resellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_resellable_percent'] ?? '0.00')) ?>">
                    <small>Расход: возвратный тариф WB + ручная обработка возврата.</small>
                  </div>
                  <div class="field">
                    <label for="return_nonresellable_percent">Безвозвратные потери, %</label>
                    <input id="return_nonresellable_percent" name="return_nonresellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_nonresellable_percent'] ?? '0.00')) ?>">
                    <small>Расход: возвратный тариф WB + обработка + закупочная стоимость товара.</small>
                  </div>
                  <div class="field">
                    <label for="nonbuyout_processing_rub">Обработка невыкупа WB, ₽</label>
                    <input id="nonbuyout_processing_rub" name="nonbuyout_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_processing_rub'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="return_processing_rub">Обработка возврата WB, ₽</label>
                    <input id="return_processing_rub" name="return_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_processing_rub'] ?? '0.00')) ?>">
                  </div>
                </div>
              </div>

              <div class="section section-tax">
                <h3>Налоги</h3>
                <div class="muted">Налоговый блок здесь такой же, как в общем Price Tool: можно учитывать УСН, НДС и налог на прибыль.</div>
                <div class="grid">
                  <div class="field">
                    <label for="tax_mode">Налоговый режим</label>
                    <select id="tax_mode" name="tax_mode">
                      <option value="none" <?= (($currentFeed['tax_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не учитывать</option>
                      <option value="usn_income" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income') ? 'selected' : '' ?>>УСН доходы</option>
                      <option value="usn_income_expense" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income_expense') ? 'selected' : '' ?>>УСН доходы-расходы</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="tax_percent">Ставка налога, %</label>
                    <input id="tax_percent" name="tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['tax_percent'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="vat_percent">НДС, %</label>
                    <input id="vat_percent" name="vat_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['vat_percent'] ?? '0.00')) ?>">
                  </div>
                  <div class="field">
                    <label for="profit_tax_percent">Налог на прибыль, %</label>
                    <input id="profit_tax_percent" name="profit_tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['profit_tax_percent'] ?? '0.00')) ?>">
                  </div>
                </div>
              </div>

              <div class="actions">
                <button type="submit">Сохранить настройки</button>
                <?php if ((int)($currentFeed['id'] ?? 0) > 0): ?>
                  <button
                    type="button"
                    class="danger"
                    onclick="if (confirm('Удалить этот профиль фида?')) { document.getElementById('feedAction').value = 'delete_feed'; this.form.submit(); }"
                  >Удалить профиль</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="calculator" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Тест одного артикула</div>
            <div class="workspace-block-subtitle">Здесь можно проверить один товар по артикулу или <code>offer_id</code>, посмотреть текущую цену WB и понять, что именно отправится при ручной выгрузке.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <form method="post" class="settings-form" style="margin-top:0;">
              <input type="hidden" name="action" value="test_single_offer">
              <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <div class="grid">
                <div class="field">
                  <label for="test_offer_id">Артикул / offer_id</label>
                  <input id="test_offer_id" name="test_offer_id" type="text" value="<?= h($wbTestArticleRaw !== '' ? $wbTestArticleRaw : (string)($_POST['test_offer_id'] ?? '')) ?>" placeholder="Например: 00616 или 00616__22">
                </div>
                <div class="field">
                  <label>&nbsp;</label>
                  <div class="actions">
                    <button type="submit">Показать расчёт</button>
                  </div>
                </div>
              </div>
            </form>

            <?php if ($wbSingleTest !== null): ?>
              <?php
                $wbCalc = (array)($wbSingleTest['calc'] ?? []);
                $wbCurrentSnapshot = is_array($wbSingleTest['current_snapshot'] ?? null) ? $wbSingleTest['current_snapshot'] : null;
                $wbRecommendedSnapshot = is_array($wbSingleTest['recommended_snapshot'] ?? null) ? $wbSingleTest['recommended_snapshot'] : null;
                $wbBaseSnapshot = is_array($wbSingleTest['base_snapshot'] ?? null) ? $wbSingleTest['base_snapshot'] : null;
                $wbMinSnapshot = is_array($wbSingleTest['min_snapshot'] ?? null) ? $wbSingleTest['min_snapshot'] : null;
                $wbDesiredState = is_array($wbCalc['desired_state'] ?? null) ? $wbCalc['desired_state'] : null;
                $wbBreakdown = is_array($wbCalc['breakdown'] ?? null) ? $wbCalc['breakdown'] : [];
                $wbCurrentBreakdown = is_array($wbCurrentSnapshot['breakdown'] ?? null) ? (array)$wbCurrentSnapshot['breakdown'] : [];
                $wbRecommendedBreakdown = is_array($wbRecommendedSnapshot['breakdown'] ?? null) ? (array)$wbRecommendedSnapshot['breakdown'] : [];
                $wbBaseBreakdown = is_array($wbBaseSnapshot['breakdown'] ?? null) ? (array)$wbBaseSnapshot['breakdown'] : [];
                $wbMinBreakdown = is_array($wbMinSnapshot['breakdown'] ?? null) ? (array)$wbMinSnapshot['breakdown'] : [];
                $wbCurrentCosts = price_tool_snapshot_total_costs($wbCurrentSnapshot);
                $wbRecommendedCosts = price_tool_snapshot_total_costs($wbRecommendedSnapshot);
                $wbBaseCosts = price_tool_snapshot_total_costs($wbBaseSnapshot);
                $wbMinCosts = price_tool_snapshot_total_costs($wbMinSnapshot);
                $wbDeltaSale = ($wbCalc['recommended_sale_price'] !== null && $wbCalc['current_discounted_price'] !== null)
                  ? ((float)$wbCalc['recommended_sale_price'] - (float)$wbCalc['current_discounted_price'])
                  : null;
                $wbScenarioRows = [
                  [
                    'title' => 'Сейчас',
                    'sale' => ((float)($wbCalc['current_club_discounted_price'] ?? 0) > 0) ? ($wbCalc['current_club_discounted_price'] ?? null) : ($wbCalc['current_discounted_price'] ?? null),
                    'normal_sale' => $wbCalc['current_discounted_price'] ?? null,
                    'club_sale' => $wbCalc['current_club_discounted_price'] ?? null,
                    'list' => $wbCalc['current_price'] ?? null,
                    'discount' => $wbCalc['current_discount'] ?? null,
                    'club_discount' => $wbCalc['current_club_discount'] ?? null,
                    'snapshot' => $wbCurrentSnapshot,
                    'costs' => $wbCurrentCosts,
                  ],
                  [
                    'title' => 'Базовая',
                    'sale' => $wbCalc['recommended_base_effective_sale_price'] ?? null,
                    'normal_sale' => $wbCalc['recommended_base_sale_price'] ?? null,
                    'club_sale' => $wbCalc['recommended_base_club_sale_price'] ?? null,
                    'list' => $wbCalc['recommended_base_list_price'] ?? null,
                    'discount' => $wbCalc['recommended_base_discount'] ?? ($currentFeed['wb_discount_percent'] ?? null),
                    'club_discount' => $wbCalc['recommended_base_club_discount'] ?? ($currentFeed['wb_club_discount_percent'] ?? null),
                    'snapshot' => $wbBaseSnapshot,
                    'costs' => $wbBaseCosts,
                  ],
                  [
                    'title' => 'Min price',
                    'sale' => $wbCalc['recommended_min_effective_sale_price'] ?? null,
                    'normal_sale' => $wbCalc['recommended_min_sale_price'] ?? null,
                    'club_sale' => $wbCalc['recommended_min_club_sale_price'] ?? null,
                    'list' => $wbCalc['recommended_min_list_price'] ?? null,
                    'discount' => $wbCalc['recommended_min_discount'] ?? null,
                    'club_discount' => $wbCalc['recommended_min_club_discount'] ?? ($currentFeed['wb_club_discount_percent'] ?? null),
                    'snapshot' => $wbMinSnapshot,
                    'costs' => $wbMinCosts,
                  ],
                  [
                    'title' => 'Итог',
                    'sale' => $wbCalc['recommended_effective_sale_price'] ?? null,
                    'normal_sale' => $wbCalc['recommended_sale_price'] ?? null,
                    'club_sale' => $wbCalc['recommended_club_sale_price'] ?? null,
                    'list' => $wbDesiredState['target_list_price'] ?? null,
                    'discount' => $wbDesiredState['discount'] ?? null,
                    'club_discount' => $wbDesiredState['club_discount'] ?? null,
                    'snapshot' => $wbRecommendedSnapshot,
                    'costs' => $wbRecommendedCosts,
                  ],
                ];
                $wbCostComparisonRows = [
                  ['label' => 'Выручка расчёта', 'key' => 'sale_price'],
                  ['label' => 'Закупка', 'key' => 'purchase_cost'],
                  ['label' => 'Фиксированные расходы', 'key' => 'manual_fixed_costs_rub'],
                  ['label' => 'Логистика WB', 'key' => 'marketplace_delivery_rub'],
                  ['label' => 'Комиссия WB', 'key' => 'commission_rub'],
                  ['label' => 'Продвижение', 'key' => 'marketing_rub'],
                  ['label' => 'Кредит/рассрочка', 'key' => 'credit_rub'],
                  ['label' => 'Прочие %', 'key' => 'extra_rub'],
                  ['label' => 'Страховые', 'key' => 'insurance_rub'],
                  ['label' => 'Потери и возвраты', 'key' => 'issue_cost'],
                  ['label' => 'Налоги', 'key' => '__taxes'],
                  ['label' => 'Итого расходов', 'key' => '__total_costs'],
                  ['label' => 'Доход', 'key' => '__profit'],
                ];
              ?>
              <div class="stats" style="margin-top:18px;">
                <div class="stat">
                  <div class="label">Артикул</div>
                  <div class="value" style="font-size:20px;"><?= h((string)($wbCalc['offer_id'] ?? '—')) ?></div>
                </div>
                <div class="stat">
                  <div class="label">WB nmID</div>
                  <div class="value" style="font-size:20px;"><?= h((string)($wbCalc['nm_id'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Закупка</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['purchase_cost'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Текущая цена WB</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['current_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Текущая цена продажи</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['current_discounted_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Цена WB Клуба сейчас</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['current_club_discounted_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Итоговая продажа через скидку</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_sale_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Фиксированная база WB</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_base_list_price'] ?? null)) ?></div>
                  <div class="muted">продажа <?= h(fmt_rub($wbCalc['recommended_base_sale_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Прогноз автоакции</div>
                  <div class="value"><?= h(fmt_percent($wbBreakdown['future_promo_discount_percent'] ?? null)) ?></div>
                  <?php if (!empty($wbBreakdown['promotion_pricing_enabled'])): ?>
                    <div class="muted">
                      <?= h((string)($wbBreakdown['future_promo_discount_source'] ?? 'none')) ?> ·
                      <?= h((string)($wbBreakdown['future_promo_discount_sample_size'] ?? 0)) ?> знач. ·
                      уверенность <?= h((string)($wbBreakdown['future_promo_discount_confidence'] ?? 'none')) ?>
                    </div>
                  <?php else: ?>
                    <div class="muted">акции отключены</div>
                  <?php endif; ?>
                </div>
                <div class="stat">
                  <div class="label">Min price WB</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_min_sale_price'] ?? null)) ?></div>
                  <?php if ((float)($wbCalc['recommended_min_effective_sale_price'] ?? 0) > 0 && (float)($wbCalc['recommended_min_effective_sale_price'] ?? 0) !== (float)($wbCalc['recommended_min_sale_price'] ?? 0)): ?>
                    <div class="muted">эффективно <?= h(fmt_rub($wbCalc['recommended_min_effective_sale_price'] ?? null)) ?></div>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($wbDeltaSale !== null): ?>
                <div class="delta-banner">
                  Разница с текущей ценой продажи WB: <b><?= h(fmt_rub($wbDeltaSale)) ?></b>
                  <?php if ($wbDeltaSale > 0): ?>
                    — расчёт предлагает поднять цену.
                  <?php elseif ($wbDeltaSale < 0): ?>
                    — расчёт предлагает снизить цену.
                  <?php else: ?>
                    — текущая цена уже совпадает с расчётной.
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="hero-reference">
                <div class="hero-card compact current">
                  <div class="hero-title">Текущая цена WB</div>
                  <div class="hero-price"><?= h(fmt_rub($wbCalc['current_price'] ?? null)) ?></div>
                  <div class="hero-compact-meta">
                    <div class="hero-metric">
                      <div class="label">Продажа</div>
                      <div class="value"><?= h(fmt_rub($wbCalc['current_discounted_price'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Скидка</div>
                      <div class="value"><?= h(fmt_percent($wbCalc['current_discount'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Доход</div>
                      <div class="value"><?= h(fmt_rub($wbCurrentSnapshot['profit_rub'] ?? null)) ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card compact current">
                  <div class="hero-title">Текущая цена WB Клуба</div>
                  <div class="hero-price"><?= h(fmt_rub($wbCalc['current_club_discounted_price'] ?? null)) ?></div>
                  <div class="hero-compact-meta">
                    <div class="hero-metric">
                      <div class="label">Скидка клуба</div>
                      <div class="value"><?= h(fmt_percent($wbCalc['current_club_discount'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">К закупке</div>
                      <div class="value"><?= h(fmt_percent($wbCurrentSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Расходы</div>
                      <div class="value"><?= h(fmt_rub($wbCurrentCosts)) ?></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="hero-compare">
                <div class="hero-card recommended">
                  <div class="hero-title">Итоговая продажа WB</div>
                  <div class="hero-price"><?= h(fmt_rub($wbCalc['recommended_sale_price'] ?? null)) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">Доход, ₽</div>
                      <div class="value"><?= h(fmt_rub($wbRecommendedSnapshot['profit_rub'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Доход к закупке</div>
                      <div class="value"><?= h(fmt_percent($wbRecommendedSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Суммарные расходы</div>
                      <div class="value"><?= h(fmt_rub($wbRecommendedCosts)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Источник тарифов</div>
                      <div class="value" style="font-size:18px;"><?= h(price_tool_cost_source_label((string)($wbBreakdown['commission_source'] ?? 'manual'), 'WB')) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">База без акции</div>
                      <div class="value"><?= h(fmt_rub($wbCalc['recommended_base_sale_price'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Минимум</div>
                      <div class="value"><?= h(fmt_rub($wbCalc['recommended_min_sale_price'] ?? null)) ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card recommended">
                  <div class="hero-title">Фиксированная базовая цена WB</div>
                  <div class="hero-price"><?= h(fmt_rub($wbDesiredState['target_list_price'] ?? null)) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">Скидка продавца</div>
                      <div class="value"><?= h(fmt_percent($wbDesiredState['discount'] ?? ($currentFeed['wb_discount_percent'] ?? null))) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Получится цена</div>
                      <div class="value"><?= h(fmt_rub($wbDesiredState['target_sale_price'] ?? ($wbCalc['recommended_sale_price'] ?? null))) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Текущая база WB</div>
                      <div class="value"><?= h(fmt_rub($wbCalc['current_price'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Изменится</div>
                      <div class="value"><?= !empty($wbCalc['desired_state']) && wb_price_tool_desired_state_needs_push($wbCalc) ? 'Да' : 'Нет' ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card marketing">
                  <div class="hero-title">Цена WB Клуба</div>
                  <div class="hero-price"><?= h(fmt_rub($wbCalc['recommended_club_sale_price'] ?? null)) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">Скидка клуба</div>
                      <div class="value"><?= h(fmt_percent($wbDesiredState['club_discount'] ?? ($currentFeed['wb_club_discount_percent'] ?? null))) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Текущая цена клуба</div>
                      <div class="value"><?= h(fmt_rub($wbCalc['current_club_discounted_price'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Low turnover</div>
                      <div class="value" style="font-size:18px;"><?= !empty($wbCalc['is_bad_turnover']) ? 'Да' : 'Нет' ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">nmID</div>
                      <div class="value" style="font-size:18px;"><?= h((string)($wbCalc['nm_id'] ?? 0)) ?></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="strategy-card">
                <h3>Сценарии цены WB</h3>
                <div class="muted">Сравнение текущей цены, фиксированной базовой цены WB, нижнего порога и итоговой продажи через скидку продавца.</div>
                <div class="table-wrap" style="margin-top:12px;">
                  <table>
                    <thead>
                      <tr>
                        <th>Сценарий</th>
                        <th>Эффективная продажа</th>
                        <th>Обычная продажа</th>
                        <th>WB Клуб</th>
                        <th>База WB</th>
                        <th>Скидка продавца</th>
                        <th>Доход</th>
                        <th>Доход к закупке</th>
                        <th>Расходы</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($wbScenarioRows as $scenarioRow): ?>
                        <?php $scenarioSnapshot = is_array($scenarioRow['snapshot'] ?? null) ? (array)$scenarioRow['snapshot'] : []; ?>
                        <tr>
                          <td><strong><?= h((string)$scenarioRow['title']) ?></strong></td>
                          <td><?= h(fmt_rub($scenarioRow['sale'] ?? null)) ?></td>
                          <td><?= h(fmt_rub($scenarioRow['normal_sale'] ?? null)) ?></td>
                          <td><?= h(fmt_rub($scenarioRow['club_sale'] ?? null)) ?></td>
                          <td><?= h(fmt_rub($scenarioRow['list'] ?? null)) ?></td>
                          <td>
                            <?= h(fmt_percent($scenarioRow['discount'] ?? null)) ?>
                            <?php if (($scenarioRow['club_discount'] ?? null) !== null && (float)($scenarioRow['club_discount'] ?? 0) > 0): ?>
                              <div class="muted">клуб <?= h(fmt_percent($scenarioRow['club_discount'] ?? null)) ?></div>
                            <?php endif; ?>
                          </td>
                          <td><?= h(fmt_rub($scenarioSnapshot['profit_rub'] ?? null)) ?></td>
                          <td><?= h(fmt_percent($scenarioSnapshot['profit_on_cost_percent'] ?? null)) ?></td>
                          <td><?= h(fmt_rub($scenarioRow['costs'] ?? ($scenarioSnapshot['total_costs_rub'] ?? null))) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="strategy-card">
                <h3>Расходы по сценариям WB</h3>
                <div class="muted">Разложение показывает, какие расходы участвуют в расчёте. Одни и те же расходы применяются и к базовой цене, и к min price; меняется только целевой доход.</div>
                <div class="table-wrap" style="margin-top:12px;">
                  <table>
                    <thead>
                      <tr>
                        <th>Статья</th>
                        <th>Сейчас</th>
                        <th>Базовая</th>
                        <th>Min price</th>
                        <th>Итог</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($wbCostComparisonRows as $costRow): ?>
                        <tr>
                          <td><?= h((string)$costRow['label']) ?></td>
                          <?php foreach ([$wbCurrentSnapshot, $wbBaseSnapshot, $wbMinSnapshot, $wbRecommendedSnapshot] as $scenarioSnapshot): ?>
                            <?php
                              $scenarioBreakdown = is_array($scenarioSnapshot['breakdown'] ?? null) ? (array)$scenarioSnapshot['breakdown'] : [];
                              $key = (string)$costRow['key'];
                              if ($key === '__taxes') {
                                  $value = ((float)($scenarioBreakdown['tax_rub'] ?? 0)) + ((float)($scenarioBreakdown['vat_rub'] ?? 0)) + ((float)($scenarioBreakdown['profit_tax_rub'] ?? 0));
                              } elseif ($key === '__total_costs') {
                                  $value = $scenarioSnapshot['total_costs_rub'] ?? price_tool_snapshot_total_costs(is_array($scenarioSnapshot) ? $scenarioSnapshot : null);
                              } elseif ($key === '__profit') {
                                  $value = $scenarioSnapshot['profit_rub'] ?? null;
                              } else {
                                  $value = $scenarioBreakdown[$key] ?? null;
                              }
                            ?>
                            <td><?= h(fmt_rub($value)) ?></td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="strategy-card">
                <h3>Как получилась цена WB</h3>
                <div class="muted">Сервис отдельно считает фиксированную базовую цену WB, min price и нижнюю границу с запасом. Акция WB выбирается, если её плановая цена не ниже этой границы; фактическая цена в акции достигается скидкой продавца от базовой цены.</div>
                <div class="strategy-grid">
                  <div class="strategy-kv">
                    <div class="label">Закупка из фида</div>
                    <div class="value"><?= h(fmt_rub($wbCalc['purchase_cost'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Доход базовой цены</div>
                    <div class="value"><?= h(fmt_rub($wbBreakdown['target_profit_rub'] ?? null)) ?></div>
                    <div class="muted">или <?= h(fmt_percent($wbBreakdown['target_profit_percent_effective'] ?? null)) ?> к закупке</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Доход min price</div>
                    <div class="value"><?= h(fmt_rub($wbBreakdown['target_min_profit_rub'] ?? null)) ?></div>
                    <div class="muted">или <?= h(fmt_percent($wbBreakdown['target_min_profit_percent_effective'] ?? null)) ?> к закупке</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Комиссия WB</div>
                    <div class="value"><?= h(fmt_rub($wbRecommendedBreakdown['commission_rub'] ?? null)) ?></div>
                    <div class="muted"><?= h(fmt_percent($wbBreakdown['commission_percent'] ?? null)) ?> · <?= h(price_tool_cost_source_label((string)($wbBreakdown['commission_source'] ?? 'manual'), 'WB')) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Логистика WB</div>
                    <div class="value"><?= h(fmt_rub($wbRecommendedBreakdown['marketplace_delivery_rub'] ?? null)) ?></div>
                    <div class="muted"><?= h(price_tool_cost_source_label((string)($wbBreakdown['delivery_source'] ?? 'manual'), 'WB')) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Продвижение и прочие %</div>
                    <div class="value"><?= h(fmt_rub(((float)($wbRecommendedBreakdown['marketing_rub'] ?? 0)) + ((float)($wbRecommendedBreakdown['credit_rub'] ?? 0)) + ((float)($wbRecommendedBreakdown['extra_rub'] ?? 0)) + ((float)($wbRecommendedBreakdown['insurance_rub'] ?? 0)))) ?></div>
                    <div class="muted">продвижение <?= h(fmt_percent($wbBreakdown['marketing_percent'] ?? null)) ?>, кредит <?= h(fmt_percent($wbBreakdown['credit_percent'] ?? null)) ?>, прочие <?= h(fmt_percent($wbBreakdown['extra_percent'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Потери и возвраты</div>
                    <div class="value"><?= h(!empty($wbBreakdown['include_returns_in_cost']) ? fmt_rub($wbRecommendedBreakdown['issue_cost'] ?? null) : 'Не учитываются') ?></div>
                    <div class="muted">возвратный тариф <?= h(fmt_rub($wbBreakdown['return_tariff_rub'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Налоги</div>
                    <div class="value"><?= h(fmt_rub(((float)($wbRecommendedBreakdown['tax_rub'] ?? 0)) + ((float)($wbRecommendedBreakdown['vat_rub'] ?? 0)) + ((float)($wbRecommendedBreakdown['profit_tax_rub'] ?? 0)))) ?></div>
                    <div class="muted"><?= h((string)($wbBreakdown['tax_mode'] ?? 'none')) ?> · НДС <?= h(fmt_percent($wbBreakdown['vat_percent'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Что отправится в WB</div>
                    <div class="value" style="font-size:20px; line-height:1.35;">
                      базовая цена <?= h(fmt_rub($wbDesiredState['target_list_price'] ?? null)) ?><br>
                      скидка продавца <?= h(fmt_percent($wbDesiredState['discount'] ?? null)) ?><br>
                      клуб <?= h(fmt_percent($wbDesiredState['club_discount'] ?? null)) ?>
                    </div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">База прибыли</div>
                    <div class="value" style="font-size:20px; line-height:1.35;"><?= (($wbBreakdown['profit_price_basis'] ?? '') === 'wb_club_price') ? 'Цена WB Клуба' : 'Обычная цена WB' ?></div>
                    <div class="muted">прибыль считается от цены после скидки продавца и скидки клуба</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Будущая автоакция</div>
                    <?php if (!empty($wbBreakdown['promotion_pricing_enabled']) && (float)($wbBreakdown['future_promo_discount_percent'] ?? 0) > 0): ?>
                      <div class="value"><?= h(fmt_percent($wbBreakdown['future_promo_discount_percent'] ?? null)) ?></div>
                      <div class="muted">
                        режим <?= h((string)($wbBreakdown['future_promo_discount_mode'] ?? 'auto')) ?>,
                        источник <?= h((string)($wbBreakdown['future_promo_discount_source'] ?? 'none')) ?>,
                        уверенность <?= h((string)($wbBreakdown['future_promo_discount_confidence'] ?? 'none')) ?>,
                        база <?= h(fmt_percent($wbBreakdown['future_promo_discount_base_percent'] ?? null)) ?>
                        <?php if ((float)($wbBreakdown['future_promo_discount_buffer_percent'] ?? 0) > 0): ?>
                          + буфер <?= h(fmt_percent($wbBreakdown['future_promo_discount_buffer_percent'] ?? null)) ?>
                        <?php endif; ?>
                        <br>
                        выборка: товар <?= h((string)($wbBreakdown['future_promo_discount_product_sample_size'] ?? 0)) ?>,
                        категория <?= h((string)($wbBreakdown['future_promo_discount_subject_sample_size'] ?? 0)) ?>,
                        кабинет <?= h((string)($wbBreakdown['future_promo_discount_connection_sample_size'] ?? 0)) ?>;
                        p50 <?= h(fmt_percent($wbBreakdown['future_promo_discount_p50_percent'] ?? null)) ?>,
                        p75 <?= h(fmt_percent($wbBreakdown['future_promo_discount_p75_percent'] ?? null)) ?>,
                        p85 <?= h(fmt_percent($wbBreakdown['future_promo_discount_p85_percent'] ?? null)) ?>
                      </div>
                    <?php elseif (!empty($wbBreakdown['promotion_pricing_enabled'])): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">нет данных</div>
                      <div class="muted">синхронизируй автоакции или задай процент вручную</div>
                    <?php else: ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">отключена</div>
                    <?php endif; ?>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Акция WB</div>
                    <?php if (!empty($wbDesiredState['promotion_id'])): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;"><?= h((string)($wbDesiredState['promotion_name'] ?? ('#' . (string)$wbDesiredState['promotion_id']))) ?></div>
                      <div class="muted">
                        <?= (($wbDesiredState['promotion_timing'] ?? '') === 'future') ? 'будущая' : 'активная' ?>,
                        план <?= h(fmt_rub($wbDesiredState['promotion_plan_price_before_recalc'] ?? null)) ?>,
                        нижняя граница <?= h(fmt_rub($wbDesiredState['promotion_target_effective_sale_price'] ?? ($wbBreakdown['promotion_target_effective_sale_price'] ?? null))) ?>,
                        действие <?= (($wbDesiredState['promotion_action'] ?? '') === 'upload_to_promotion') ? 'добавить через API' : 'цена/скидка' ?>
                        <?php if (($wbDesiredState['promotion_timing'] ?? '') === 'future' && trim((string)($wbDesiredState['promotion_start_datetime'] ?? '')) !== ''): ?>
                          <br>старт <?= h((string)$wbDesiredState['promotion_start_datetime']) ?>, подготовка за <?= h((string)($wbBreakdown['promotion_future_prepare_days'] ?? 0)) ?> дн.
                        <?php endif; ?>
                      </div>
                    <?php elseif (($wbBreakdown['promotion_pricing_status'] ?? '') === 'blocked_below_min_price'): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">не подходит</div>
                      <div class="muted">плановая цена ниже нижней границы с запасом</div>
                    <?php elseif (($wbBreakdown['promotion_pricing_status'] ?? '') === 'blocked_below_target_margin'): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">старый запас</div>
                      <div class="muted">старое решение по правилу запаса <?= h(fmt_rub($wbBreakdown['promotion_target_effective_sale_price'] ?? null)) ?></div>
                    <?php elseif (($wbBreakdown['promotion_pricing_status'] ?? '') === 'future_available'): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">есть будущая акция</div>
                      <div class="muted">
                        <?= h((string)($wbBreakdown['promotion_candidate_name'] ?? '')) ?>
                        <?php if (trim((string)($wbBreakdown['promotion_candidate_start_datetime'] ?? '')) !== ''): ?>
                          · старт <?= h((string)$wbBreakdown['promotion_candidate_start_datetime']) ?>
                        <?php endif; ?>
                        <br>до старта она не участвует в текущем расчёте цены
                      </div>
                    <?php elseif (!empty($wbBreakdown['promotion_pricing_enabled'])): ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">нет подходящей акции</div>
                      <div class="muted">
                        активных <?= h((string)($wbBreakdown['promotion_active_count'] ?? 0)) ?>,
                        будущих в окне <?= h((string)($wbBreakdown['promotion_future_count'] ?? 0)) ?>;
                        окно подготовки <?= h((string)($wbBreakdown['promotion_future_prepare_days'] ?? 0)) ?> дн.
                      </div>
                    <?php else: ?>
                      <div class="value" style="font-size:20px; line-height:1.35;">отключены</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="strategy-grid">
                <div class="strategy-kv">
                  <div class="label">Базовая цена WB</div>
                  <div class="value"><?= h(fmt_rub($wbDesiredState['target_list_price'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Базовая продажа</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_base_sale_price'] ?? null)) ?></div>
                  <div class="muted">без выбранной акции</div>
                </div>
                <div class="strategy-kv">
                  <div class="label">База до прогноза</div>
                  <div class="value"><?= h(fmt_rub($wbBreakdown['recommended_base_effective_sale_price_before_future_promo'] ?? null)) ?></div>
                  <?php if (!empty($wbBreakdown['future_promo_base_lift_applied'])): ?>
                    <div class="muted">поднята под будущую автоакцию</div>
                  <?php else: ?>
                    <div class="muted">будущая акция не поднимает текущую цену</div>
                  <?php endif; ?>
                </div>
                <div class="strategy-kv">
                  <div class="label">Ориентир будущей акции</div>
                  <div class="value"><?= h(fmt_rub($wbBreakdown['future_promo_required_effective_sale_price'] ?? null)) ?></div>
                  <div class="muted">расчётная база <?= h(fmt_rub($wbBreakdown['future_promo_required_list_price'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Min price</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_min_sale_price'] ?? null)) ?></div>
                  <div class="muted">эффективно <?= h(fmt_rub($wbCalc['recommended_min_effective_sale_price'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Скидка продавца</div>
                  <div class="value"><?= h(fmt_percent($wbDesiredState['discount'] ?? null)) ?></div>
                  <?php if (!empty($wbDesiredState['promotion_id'])): ?>
                    <div class="muted">рассчитана от базовой цены для акции <?= h((string)($wbDesiredState['promotion_name'] ?? ('#' . (string)$wbDesiredState['promotion_id']))) ?></div>
                  <?php elseif (!empty($wbBreakdown['promotion_pricing_enabled'])): ?>
                    <div class="muted">акция для товара не выбрана</div>
                  <?php endif; ?>
                </div>
                <div class="strategy-kv">
                  <div class="label">Скидка WB Клуба</div>
                  <div class="value"><?= h(fmt_percent($wbDesiredState['club_discount'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Цена для WB Клуба</div>
                  <div class="value"><?= h(fmt_rub($wbCalc['recommended_club_sale_price'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Доход сейчас</div>
                  <div class="value"><?= h(fmt_rub($wbCurrentSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Доход после расчёта</div>
                  <div class="value"><?= h(fmt_rub($wbRecommendedSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Доходность по диапазону</div>
                  <div class="value"><?= h(fmt_percent($wbBreakdown['target_profit_percent_effective'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Доходность min price</div>
                  <div class="value"><?= h(fmt_percent($wbBreakdown['target_min_profit_percent_effective'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Модификатор базовой</div>
                  <div class="value" style="font-size:18px;"><?= h((string)($wbBreakdown['price_modifier_mode'] ?? ($currentFeed['price_modifier_mode'] ?? 'none'))) ?></div>
                  <div class="muted"><?= h(fmt_plain_decimal($currentFeed['price_modifier_value'] ?? null, 2)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Модификатор min price</div>
                  <div class="value" style="font-size:18px;"><?= h((string)($currentFeed['price_modifier_min_mode'] ?? 'none')) ?></div>
                  <div class="muted"><?= h(fmt_plain_decimal($currentFeed['price_modifier_min_value'] ?? null, 2)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Комиссия</div>
                  <div class="value"><?= h(fmt_percent($wbBreakdown['commission_percent'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Логистика WB</div>
                  <div class="value"><?= h(fmt_rub($wbBreakdown['marketplace_delivery_rub'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Возвратный тариф WB</div>
                  <div class="value"><?= h(fmt_rub($wbBreakdown['return_tariff_rub'] ?? null)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Потери в цене</div>
                  <div class="value"><?= h(!empty($wbBreakdown['include_returns_in_cost']) ? fmt_rub($wbBreakdown['issue_cost'] ?? null) : 'Не учитываются') ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Источник расходов</div>
                  <div class="value" style="font-size:18px;"><?= h(($wbBreakdown['commission_source'] ?? 'manual') === 'api' ? 'WB API' : 'Резерв профиля') ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Категория / subjectID</div>
                  <div class="value" style="font-size:18px;"><?= h((string)($wbBreakdown['subject_id'] ?? 0)) ?></div>
                </div>
                <div class="strategy-kv">
                  <div class="label">Low turnover</div>
                  <div class="value" style="font-size:18px;"><?= !empty($wbCalc['is_bad_turnover']) ? 'Да' : 'Нет' ?></div>
                </div>
              </div>

              <?php if (!empty($wbBreakdown['include_returns_in_cost'])): ?>
                <div class="card" style="margin-top:16px; padding:14px; background:#fffdf7; border-color:#fde68a;">
                  <h3 style="margin:0 0 8px;">Как учтены риски WB</h3>
                  <div class="muted">
                    Невыкупы: <?= h(fmt_percent($wbBreakdown['nonbuyout_percent'] ?? null)) ?>,
                    распределено <?= h(fmt_rub($wbBreakdown['nonbuyout_cost'] ?? null)) ?>.
                    Возвраты с повторной продажей: <?= h(fmt_percent($wbBreakdown['return_resellable_percent'] ?? null)) ?>,
                    распределено <?= h(fmt_rub($wbBreakdown['return_resellable_cost'] ?? null)) ?>.
                    Безвозвратные потери: <?= h(fmt_percent($wbBreakdown['return_nonresellable_percent'] ?? null)) ?>,
                    распределено <?= h(fmt_rub($wbBreakdown['return_nonresellable_cost'] ?? null)) ?>.
                  </div>
                </div>
              <?php endif; ?>

              <?php if (($wbBreakdown['tariff_warehouse_name'] ?? '') !== '' || ($wbBreakdown['volume_liters'] ?? null) !== null): ?>
                <div class="card" style="margin-top:16px; padding:14px; background:#f8fbff; border-color:#bfdbfe;">
                  <h3 style="margin:0 0 8px;">Данные WB API по этому товару</h3>
                  <div class="muted">
                    Тарифный склад: <strong><?= h((string)($wbBreakdown['tariff_warehouse_name'] ?? '—')) ?></strong>
                    <?php if (($wbBreakdown['tariff_geo_name'] ?? '') !== ''): ?>
                      · <?= h((string)$wbBreakdown['tariff_geo_name']) ?>
                    <?php endif; ?>
                    · Дата тарифов: <?= h((string)($wbBreakdown['tariff_date'] ?? '—')) ?>
                    <?php if (($wbBreakdown['volume_liters'] ?? null) !== null): ?>
                      · Объём: <?= h(fmt_plain_decimal($wbBreakdown['volume_liters'], 3)) ?> л
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!empty($wbCalc['warnings'])): ?>
                <div class="card" style="margin-top:16px; padding:16px; background:#fffaf3; border-color:#fed7aa;">
                  <h3 style="margin:0 0 8px;">Предупреждения</h3>
                  <ul class="warning-list">
                    <?php foreach ((array)$wbCalc['warnings'] as $warning): ?>
                      <li><?= h((string)$warning) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <?php if ($wbDesiredState !== null): ?>
                <div class="actions" style="margin-top:16px;">
                  <form method="post">
                    <input type="hidden" name="action" value="apply_single_offer_to_wb">
                    <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
                    <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                    <input type="hidden" name="test_offer_id" value="<?= h((string)($wbCalc['offer_id'] ?? '')) ?>">
                    <button type="submit">Отправить цену и скидки в WB</button>
                  </form>
                </div>
              <?php endif; ?>

              <?php if ($wbSingleApplyReport !== null): ?>
                <div class="card" style="margin-top:16px; padding:16px;">
                  <h3 style="margin:0 0 8px;">Результат отправки в WB</h3>
                  <div class="muted">Принято товаров: <?= h((string)($wbSingleApplyReport['accepted'] ?? 0)) ?></div>
                  <?php if (!empty($wbSingleApplyReport['uploads'])): ?>
                    <div class="table-wrap" style="margin-top:12px;">
                      <table>
                        <thead>
                          <tr>
                            <th>Тип</th>
                            <th>Upload</th>
                            <th>Статус</th>
                            <th>Товаров</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ((array)$wbSingleApplyReport['uploads'] as $upload): ?>
                            <?php
                              $uploadKind = (string)($upload['kind'] ?? 'price_discount');
                              $uploadKindLabel = $uploadKind === 'club_discount' ? 'WB Клуб' : ($uploadKind === 'promotion_upload' ? 'Акция WB' : 'Цена и скидка');
                            ?>
                            <tr>
                              <td><?= h($uploadKindLabel) ?></td>
                              <td>#<?= h((string)($upload['upload_id'] ?? 0)) ?></td>
                              <td><?= h((string)($upload['status'] ?? 'accepted')) ?></td>
                              <td><?= h((string)($upload['items'] ?? 0)) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($wbSingleApplyReport['errors'])): ?>
                    <div class="card" style="margin-top:12px; padding:14px; background:#fff1f2; border-color:#fecdd3;">
                      <h3 style="margin:0 0 8px;">Предупреждения WB</h3>
                      <ul class="warning-list" style="color:#b42318;">
                        <?php foreach ((array)$wbSingleApplyReport['errors'] as $errorLine): ?>
                          <li><?= h((string)$errorLine) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="manual-push" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Ручная выгрузка списка</div>
            <div class="workspace-block-subtitle">Если нужно, можно выбрать несколько артикулов, посмотреть итоговые цены и отправить только этот список в WB.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <form method="post" class="settings-form" style="margin-top:0;">
              <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <div class="field">
                <label for="push_offer_ids">Артикулы для ручной отправки</label>
                <textarea id="push_offer_ids" name="push_offer_ids" placeholder="Например:&#10;00616&#10;00632__22"><?= h($wbPushArticlesRaw !== '' ? $wbPushArticlesRaw : (string)($_POST['push_offer_ids'] ?? '')) ?></textarea>
                <small>Можно перечислять артикулы через новую строку, запятую или точку с запятой.</small>
              </div>
              <div class="actions">
                <button type="submit" name="action" value="build_batch_push_preview">Подготовить выгрузку</button>
                <button type="submit" name="action" value="apply_batch_offer_list" class="secondary">Отправить цены и скидки в WB</button>
              </div>
            </form>

            <?php if ($wbBatchPushPreview !== null): ?>
              <?php
                $wbBatchRows = (array)($wbBatchPushPreview['rows'] ?? []);
                $wbBatchReady = 0;
                $wbBatchNeedPush = 0;
                foreach ($wbBatchRows as $batchRow) {
                    $calcRow = (array)($batchRow['calc'] ?? []);
                    if (is_array($calcRow['desired_state'] ?? null)) {
                        $wbBatchReady++;
                    }
                    if (!empty($batchRow['needs_push'])) {
                        $wbBatchNeedPush++;
                    }
                }
              ?>
              <div class="stats" style="margin-top:18px;">
                <div class="stat">
                  <div class="label">Запрошено</div>
                  <div class="value"><?= h((string)count((array)($wbBatchPushPreview['requested_ids'] ?? []))) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Найдено в фиде</div>
                  <div class="value"><?= h((string)count($wbBatchRows)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Готово к расчёту</div>
                  <div class="value"><?= h((string)$wbBatchReady) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Нужно отправить</div>
                  <div class="value"><?= h((string)$wbBatchNeedPush) ?></div>
                </div>
              </div>
              <?php if (!empty($wbBatchPushPreview['missing_ids'])): ?>
                <div class="card" style="margin-top:16px; padding:14px; background:#fffaf3; border-color:#fed7aa;">
                  <h3 style="margin:0 0 8px;">Не найдено в XML-фиде</h3>
                  <div class="muted"><?= h(implode(', ', array_map('strval', (array)$wbBatchPushPreview['missing_ids']))) ?></div>
                </div>
              <?php endif; ?>
              <div class="table-wrap" style="margin-top:16px;">
                <table>
                  <thead>
                    <tr>
                      <th>Артикул</th>
                      <th>nmID</th>
                      <th>Закупка</th>
                      <th>Текущая цена</th>
                      <th>Сейчас продаётся</th>
                      <th>База</th>
                      <th>Min price</th>
                      <th>Итог</th>
                      <th>База WB</th>
                      <th>Скидка продавца</th>
                      <th>Акция</th>
                      <th>Статус</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($wbBatchRows as $batchRow): ?>
                      <?php $calcRow = (array)($batchRow['calc'] ?? []); $desiredState = is_array($calcRow['desired_state'] ?? null) ? $calcRow['desired_state'] : null; ?>
                      <tr>
                        <td><code><?= h((string)($calcRow['offer_id'] ?? '')) ?></code></td>
                        <td><?= h((string)($calcRow['nm_id'] ?? 0)) ?></td>
                        <td><?= h(fmt_rub($calcRow['purchase_cost'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($calcRow['current_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($calcRow['current_discounted_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($calcRow['recommended_base_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($calcRow['recommended_min_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($calcRow['recommended_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($desiredState['target_list_price'] ?? null)) ?></td>
                        <td><?= h(fmt_percent($desiredState['discount'] ?? null)) ?></td>
                        <td>
                          <?php if (!empty($desiredState['promotion_id'])): ?>
                            <?= h((string)($desiredState['promotion_name'] ?? ('#' . (string)$desiredState['promotion_id']))) ?>
                          <?php elseif (($calcRow['breakdown']['promotion_pricing_status'] ?? '') === 'blocked_below_min_price'): ?>
                            <span class="status-warn">ниже границы</span>
                          <?php elseif (($calcRow['breakdown']['promotion_pricing_status'] ?? '') === 'blocked_below_target_margin'): ?>
                            <span class="status-warn">старый запас</span>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($batchRow['needs_push'])): ?>
                            <span class="status-ok">Будет отправлен</span>
                          <?php elseif ($desiredState !== null): ?>
                            <span class="muted">Без изменений</span>
                          <?php else: ?>
                            <span style="color:#b42318; font-weight:700;">Пропуск</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php if ($wbBatchPushApplyReport !== null): ?>
              <div class="card" style="margin-top:16px; padding:16px;">
                <h3 style="margin:0 0 8px;">Результат отправки списка</h3>
                <div class="muted">Принято товаров: <?= h((string)($wbBatchPushApplyReport['accepted'] ?? 0)) ?></div>
                <?php if (!empty($wbBatchPushApplyReport['uploads'])): ?>
                  <div class="table-wrap" style="margin-top:12px;">
                    <table>
                      <thead>
                        <tr>
                          <th>Тип</th>
                          <th>Upload</th>
                          <th>Статус</th>
                          <th>Товаров</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ((array)$wbBatchPushApplyReport['uploads'] as $upload): ?>
                          <?php
                            $uploadKind = (string)($upload['kind'] ?? 'price_discount');
                            $uploadKindLabel = $uploadKind === 'club_discount' ? 'WB Клуб' : ($uploadKind === 'promotion_upload' ? 'Акция WB' : 'Цена и скидка');
                          ?>
                          <tr>
                            <td><?= h($uploadKindLabel) ?></td>
                            <td>#<?= h((string)($upload['upload_id'] ?? 0)) ?></td>
                            <td><?= h((string)($upload['status'] ?? 'accepted')) ?></td>
                            <td><?= h((string)($upload['items'] ?? 0)) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
                <?php if (!empty($wbBatchPushApplyReport['errors'])): ?>
                  <div class="card" style="margin-top:12px; padding:14px; background:#fff1f2; border-color:#fecdd3;">
                    <h3 style="margin:0 0 8px;">Предупреждения WB</h3>
                    <ul class="warning-list" style="color:#b42318;">
                      <?php foreach ((array)$wbBatchPushApplyReport['errors'] as $errorLine): ?>
                        <li><?= h((string)$errorLine) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="preview" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Предпросмотр всего фида</div>
            <div class="workspace-block-subtitle">Проверка по всему XML-фиду: сколько товаров совпало с кабинетом WB, сколько готово к обновлению и какие карточки будут пропущены.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <form method="post" class="settings-form" style="margin-top:0;">
              <input type="hidden" name="action" value="preview_feed">
              <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <div class="actions">
                <button type="submit">Построить предпросмотр WB</button>
              </div>
            </form>

            <?php if ($wbPreview !== null): ?>
              <div class="stats" style="margin-top:18px;">
                <div class="stat">
                  <div class="label">Товаров в фиде</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['offers_total'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Совпало с WB</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['matched'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Готово к отправке</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['ready'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Предупреждений</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['warnings'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Товаров в кабинете WB</div>
                  <div class="value"><?= h((string)($wbPreviewMeta['goods_total'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Комиссия из API</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['api_commission'] ?? 0)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Логистика из API</div>
                  <div class="value"><?= h((string)($wbPreview['stats']['api_delivery'] ?? 0)) ?></div>
                </div>
              </div>
              <div class="table-wrap" style="margin-top:16px;">
                <table>
                  <thead>
                    <tr>
                      <th>Артикул</th>
                      <th>nmID</th>
                      <th>Закупка</th>
                      <th>Текущая цена</th>
                      <th>Сейчас продаётся</th>
                      <th>База</th>
                      <th>Min price</th>
                      <th>Итог</th>
                      <th>База WB</th>
                      <th>Скидка продавца</th>
                      <th>Акция</th>
                      <th>Доход, ₽</th>
                      <th>Статус</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ((array)($wbPreview['rows'] ?? []) as $row): ?>
                      <?php $desiredState = is_array($row['desired_state'] ?? null) ? $row['desired_state'] : null; ?>
                      <tr>
                        <td>
                          <code><?= h((string)($row['offer_id'] ?? '')) ?></code>
                          <?php if (!empty($row['name'])): ?>
                            <div class="muted" style="margin-top:4px;"><?= h((string)$row['name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= h((string)($row['nm_id'] ?? 0)) ?></td>
                        <td><?= h(fmt_rub($row['purchase_cost'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['current_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['current_discounted_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['recommended_base_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['recommended_min_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['recommended_sale_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($desiredState['target_list_price'] ?? null)) ?></td>
                        <td><?= h(fmt_percent($desiredState['discount'] ?? null)) ?></td>
                        <td>
                          <?php if (!empty($desiredState['promotion_id'])): ?>
                            <?= h((string)($desiredState['promotion_name'] ?? ('#' . (string)$desiredState['promotion_id']))) ?>
                          <?php elseif (($row['breakdown']['promotion_pricing_status'] ?? '') === 'blocked_below_min_price'): ?>
                            <span class="status-warn">ниже границы</span>
                          <?php elseif (($row['breakdown']['promotion_pricing_status'] ?? '') === 'blocked_below_target_margin'): ?>
                            <span class="status-warn">старый запас</span>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td><?= h(fmt_rub($row['profit_rub'] ?? null)) ?></td>
                        <td>
                          <?php if (($row['status'] ?? '') === 'ok'): ?>
                            <span class="status-ok">Готов</span>
                          <?php elseif (($row['status'] ?? '') === 'warn'): ?>
                            <span class="status-warn">Проверить</span>
                          <?php else: ?>
                            <span style="color:#b42318; font-weight:700;">Ошибка</span>
                          <?php endif; ?>
                          <?php if (!empty($row['warnings'])): ?>
                            <ul class="warning-list" style="margin-top:8px;">
                              <?php foreach ((array)$row['warnings'] as $warning): ?>
                                <li><?= h((string)$warning) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </details>
    </div>
  </div>
  <?php elseif ($isYandexMarketplace): ?>
  <div class="layout">
    <div>
      <details class="workspace-block" data-storage-key="settings" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Настройки Яндекс Маркета</div>
            <div class="workspace-block-subtitle">Расчёт берёт закупку из XML, цены, рекомендации, категории, габариты и тарифы площадки — из Partner API Яндекс Маркета.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <h2 style="margin-top:0;">Ценовой профиль Яндекс</h2>
            <form class="settings-form" method="post">
              <input id="feedAction" type="hidden" name="action" value="save_feed">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <input type="hidden" name="id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">

              <div class="section">
                <h3>Источник данных</h3>
                <div class="grid">
                  <div class="field">
                    <label for="name">Название профиля</label>
                    <input id="name" name="name" type="text" value="<?= h((string)($currentFeed['name'] ?? '')) ?>" required>
                  </div>
                  <div class="field">
                    <label for="supplier_id">Поставщик</label>
                    <select id="supplier_id" name="supplier_id" required>
                      <option value="">Выбери поставщика</option>
                      <?php foreach ($supplierOptions as $supplier): ?>
                        <?php
                          $supplierId = (int)($supplier['id'] ?? 0);
                          $supplierLabel = trim((string)($supplier['name'] ?? ''));
                          $supplierCode = trim((string)($supplier['supplier_code'] ?? ''));
                          if ($supplierCode !== '') {
                              $supplierLabel .= ' · ' . $supplierCode;
                          }
                        ?>
                        <option value="<?= h((string)$supplierId) ?>" <?= ((int)($currentFeed['supplier_id'] ?? 0) === $supplierId) ? 'selected' : '' ?>>
                          <?= h($supplierLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small>Для Яндекса внутренний артикул <code>артикул__код</code> при отправке автоматически превращается в <code>артикул000код</code>.</small>
                  </div>
                  <div class="field">
                    <label for="cost_tag">Тег закупочной цены</label>
                    <input id="cost_tag" name="cost_tag" type="text" value="<?= h((string)($currentFeed['cost_tag'] ?? '')) ?>" required>
                  </div>
                  <div class="field">
                    <label>XML поставщика</label>
                    <div class="muted" style="font-size:14px;">
                      <?php if ((int)($currentFeed['supplier_id'] ?? 0) > 0): ?>
                        Поставщик: <?= h((string)($currentFeed['supplier_name'] ?? '')) ?><br>
                        Код: <code><?= h((string)($currentFeed['supplier_code'] ?? '')) ?></code><br>
                        XML: <a href="<?= h((string)($currentFeed['feed_url'] ?? '')) ?>" target="_blank" rel="noopener">открыть источник</a>
                      <?php else: ?>
                        Выбери поставщика, чтобы подтянуть код и XML-источник.
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="section section-target">
                <h3>Целевая цена</h3>
                <div class="muted">Сервис отправляет цену через <code>offer-prices/updates</code>. Если в кабинете включены цены по магазинам, обычная цена уходит в магазин, а <code>minimumForBestseller</code> — в бизнес-цену. Тарифы берутся из API Яндекса по campaignId, категории, габаритам и весу.</div>
                <div class="grid">
                  <div class="field">
                    <label for="fulfillment_scheme">Схема продажи</label>
                    <select id="fulfillment_scheme" name="fulfillment_scheme">
                      <?php foreach (['fbs' => 'FBS', 'fby' => 'FBY', 'dbs' => 'DBS', 'express' => 'Express'] as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= (($currentFeed['fulfillment_scheme'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>Используется для понятного профиля расчёта. Тарифы Яндекса запрашиваются по текущему campaignId.</small>
                  </div>
                  <div class="field">
                    <label for="rounding_mode">Округление</label>
                    <select id="rounding_mode" name="rounding_mode">
                      <?php foreach ([
                        'rub' => 'До 1 ₽',
                        '5rub' => 'До 5 ₽ вверх',
                        '10rub' => 'До 10 ₽ вверх',
                        'end9' => 'Окончание на 9',
                        'end90' => 'Окончание на 90',
                        'end99' => 'Окончание на 99',
                      ] as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= (($currentFeed['rounding_mode'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small><?= h($fieldHelp['rounding_mode']) ?></small>
                  </div>
                  <div class="field">
                    <label for="strike_discount_percent">Размер скидки для зачёркнутой цены, %</label>
                    <input id="strike_discount_percent" name="strike_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['strike_discount_percent'] ?? '0.00')) ?>">
                    <small>Если заполнено, сервис отправит <code>discountBase</code> выше текущей цены.</small>
                  </div>
                </div>

                <div class="target-subgrid">
                  <div class="target-box">
                    <h4>Обычная цена</h4>
                    <div class="muted">Целевая прибыль для основной цены продажи на Яндекс Маркете.</div>
                    <div class="field">
                      <label for="target_profit_percent">Доход для цены, % от закупки</label>
                      <input id="target_profit_percent" name="target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_percent'] ?? '20.00')) ?>">
                      <small><?= h($fieldHelp['target_profit_percent']) ?></small>
                    </div>
                    <div class="field">
                      <label for="target_profit_min_rub">Минимальный доход, ₽</label>
                      <input id="target_profit_min_rub" name="target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_min_rub'] ?? '0.00')) ?>">
                      <small><?= h($fieldHelp['target_profit_min_rub']) ?></small>
                    </div>
                    <div class="range-box" data-range-editor="target">
                      <h4>Диапазоны для обычной цены</h4>
                      <div class="muted"><?= h($fieldHelp['target_profit_ranges']) ?></div>
                      <div class="range-head">
                        <div>От закупки</div>
                        <div>До закупки</div>
                        <div>Доход, %</div>
                        <div></div>
                      </div>
                      <div data-range-rows>
                        <?php foreach ($targetRangeRows as $rangeRow): ?>
                          <div class="range-row">
                            <input type="text" inputmode="decimal" name="target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                            <input type="text" inputmode="decimal" name="target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                            <input type="text" inputmode="decimal" name="target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="20">
                            <button type="button" class="range-remove" data-range-remove>×</button>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <div class="actions">
                        <button type="button" class="secondary" data-range-add="target">Добавить диапазон</button>
                      </div>
                    </div>
                  </div>

                  <div class="target-box">
                    <h4>minimumForBestseller</h4>
                    <div class="muted">Минимальная цена для участия в механиках Маркета. Считается отдельно, чтобы можно было держать более мягкую прибыль на нижней границе.</div>
                    <div class="field">
                      <label for="min_target_profit_percent">Доход для minimumForBestseller, %</label>
                      <input id="min_target_profit_percent" name="min_target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_percent'] ?? '10.00')) ?>">
                      <small>Процент от закупочной цены для нижней цены Яндекса.</small>
                    </div>
                    <div class="field">
                      <label for="min_target_profit_min_rub">Мин. доход для minimumForBestseller, ₽</label>
                      <input id="min_target_profit_min_rub" name="min_target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_min_rub'] ?? '0.00')) ?>">
                      <small>Рублёвый минимум дохода для <code>minimumForBestseller</code>.</small>
                    </div>
                    <div class="range-box" data-range-editor="min_target">
                      <h4>Диапазоны для minimumForBestseller</h4>
                      <div class="muted">Можно задать отдельную доходность по диапазонам закупочной цены.</div>
                      <div class="range-head">
                        <div>От закупки</div>
                        <div>До закупки</div>
                        <div>Доход, %</div>
                        <div></div>
                      </div>
                      <div data-range-rows>
                        <?php foreach ($minTargetRangeRows as $rangeRow): ?>
                          <div class="range-row">
                            <input type="text" inputmode="decimal" name="min_target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                            <input type="text" inputmode="decimal" name="min_target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                            <input type="text" inputmode="decimal" name="min_target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="10">
                            <button type="button" class="range-remove" data-range-remove>×</button>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <div class="actions">
                        <button type="button" class="secondary" data-range-add="min_target">Добавить диапазон</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="section section-target">
                <h3>Инструменты цены</h3>
                <div class="muted">Здесь включается только автоматическая подстройка <code>minimumForBestseller</code> по рекомендациям Яндекса. Автоматическое продвижение и платёжные условия сервис больше не отправляет и не считает отдельно.</div>
                <div class="target-subgrid">
                  <div class="target-box">
                    <h4>Рекомендации Яндекса</h4>
                    <label class="index-step-toggle">
                      <input type="checkbox" name="min_price_index_step_enabled" value="1" <?= !empty($currentFeed['min_price_index_step_enabled']) ? 'checked' : '' ?>>
                      <span>Подстраивать minimumForBestseller по рекомендациям Яндекса</span>
                    </label>
                    <small>Если API отдаёт оптимальную цену и прибыль сохраняется, minimumForBestseller опускается до этой рекомендации.</small>
                  </div>
                  <div class="target-box">
                    <h4>Ручной учёт продвижения</h4>
                    <div class="muted">Продвижение, отсрочки выплат и похожие расходы теперь учитываются вручную через поля «Маркетинг», «Прочие расходы» или фиксированные расходы ниже.</div>
                  </div>
                </div>
              </div>

              <div class="section section-costs">
                <h3>Расходы на закупку, упаковку и отправку</h3>
                <div class="muted">Тарифы площадки сервис получает из API Яндекса. Здесь добавляются внутренние расходы, которые API не знает: упаковка, обработка, ручной расход на платёжные условия и другие постоянные затраты.</div>
                <div class="grid">
                  <div class="field">
                    <label for="fulfillment_markup_rub">Фиксированные расходы, ₽</label>
                    <input id="fulfillment_markup_rub" name="fulfillment_markup_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['fulfillment_markup_rub'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['fulfillment_markup_rub']) ?></small>
                  </div>
                  <div class="field">
                    <label for="fulfillment_markup_percent">Доп. расход, %</label>
                    <input id="fulfillment_markup_percent" name="fulfillment_markup_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['fulfillment_markup_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['fulfillment_markup_percent']) ?></small>
                  </div>
                </div>
              </div>

              <div class="section section-risk">
                <h3>Потери и риски продаж</h3>
                <div class="muted">Невыкупы, возвраты и потери можно учитывать в цене, если включить переключатель. Расходы считаются поверх тарифов Яндекса.</div>
                <div class="grid">
                  <div class="field">
                    <label class="index-step-toggle" style="margin-top:28px;">
                      <input type="checkbox" name="include_returns_in_cost" value="1" <?= !empty($currentFeed['include_returns_in_cost']) ? 'checked' : '' ?>>
                      <span>Учитывать потери в цене</span>
                    </label>
                    <small><?= h($fieldHelp['include_returns_in_cost']) ?></small>
                  </div>
                  <div class="field">
                    <label for="nonbuyout_percent">Невыкупы, %</label>
                    <input id="nonbuyout_percent" name="nonbuyout_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['nonbuyout_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="return_resellable_percent">Возвраты с повторной продажей, %</label>
                    <input id="return_resellable_percent" name="return_resellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_resellable_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['return_resellable_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="return_nonresellable_percent">Безвозвратные потери, %</label>
                    <input id="return_nonresellable_percent" name="return_nonresellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_nonresellable_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['return_nonresellable_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="nonbuyout_processing_rub">Обработка невыкупа, ₽</label>
                    <input id="nonbuyout_processing_rub" name="nonbuyout_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_processing_rub'] ?? '50.00')) ?>">
                    <small><?= h($fieldHelp['nonbuyout_processing_rub']) ?></small>
                  </div>
                  <div class="field">
                    <label for="return_processing_rub">Обработка возврата, ₽</label>
                    <input id="return_processing_rub" name="return_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_processing_rub'] ?? '50.00')) ?>">
                    <small><?= h($fieldHelp['return_processing_rub']) ?></small>
                  </div>
                </div>
              </div>

              <div class="section section-extra">
                <h3>Прочие процентные расходы</h3>
                <div class="muted">Сюда можно вручную заложить стоимость денег, продвижение и любые расходы, которые должны расти вместе с итоговой ценой.</div>
                <div class="grid">
                  <div class="field">
                    <label for="promotion_percent">Маркетинг, %</label>
                    <input id="promotion_percent" name="promotion_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['promotion_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['promotion_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="credit_percent">Кредит/рассрочка, %</label>
                    <input id="credit_percent" name="credit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['credit_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['credit_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="extra_expenses_percent">Прочие расходы, %</label>
                    <input id="extra_expenses_percent" name="extra_expenses_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['extra_expenses_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['extra_expenses_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="insurance_percent">Резерв/страхование, %</label>
                    <input id="insurance_percent" name="insurance_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['insurance_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['insurance_percent']) ?></small>
                  </div>
                </div>
              </div>

              <div class="section section-tax">
                <h3>Налоги</h3>
                <div class="muted">Налоговый блок работает так же, как в Ozon Price Tool: УСН доходы считается от выручки, УСН доходы-расходы — от положительной прибыли, НДС и налог на прибыль можно добавить отдельно.</div>
                <div class="grid">
                  <div class="field">
                    <label for="tax_mode">Налоговый режим</label>
                    <select id="tax_mode" name="tax_mode">
                      <option value="none" <?= (($currentFeed['tax_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не учитывать</option>
                      <option value="usn_income" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income') ? 'selected' : '' ?>>УСН доходы</option>
                      <option value="usn_income_expense" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income_expense') ? 'selected' : '' ?>>УСН доходы-расходы</option>
                    </select>
                    <small><?= h($fieldHelp['tax_mode']) ?></small>
                  </div>
                  <div class="field">
                    <label for="tax_percent">Ставка налога, %</label>
                    <input id="tax_percent" name="tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['tax_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['tax_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="vat_percent">НДС, %</label>
                    <input id="vat_percent" name="vat_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['vat_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['vat_percent']) ?></small>
                  </div>
                  <div class="field">
                    <label for="profit_tax_percent">Налог на прибыль, %</label>
                    <input id="profit_tax_percent" name="profit_tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['profit_tax_percent'] ?? '0.00')) ?>">
                    <small><?= h($fieldHelp['profit_tax_percent']) ?></small>
                  </div>
                </div>
              </div>

              <div class="actions">
                <button type="submit">Сохранить настройки</button>
                <?php if ((int)($currentFeed['id'] ?? 0) > 0): ?>
                  <button
                    type="button"
                    class="danger"
                    onclick="if (confirm('Удалить этот профиль фида?')) { document.getElementById('feedAction').value = 'delete_feed'; this.form.submit(); }"
                  >Удалить профиль</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="calculator" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Тест одного артикула</div>
            <div class="workspace-block-subtitle">Проверяет один товар: текущую цену Яндекса, рекомендации, тарифы, расчёт прибыли и payload для отправки.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <form method="post" class="settings-form" style="margin-top:0;">
              <input type="hidden" name="action" value="test_single_offer">
              <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <div class="grid">
                <div class="field">
                  <label for="test_offer_id">Артикул / offer_id</label>
                  <input id="test_offer_id" name="test_offer_id" type="text" value="<?= h($yandexTestArticleRaw !== '' ? $yandexTestArticleRaw : (string)($_POST['test_offer_id'] ?? '')) ?>" placeholder="Например: 00615__22">
                </div>
                <div class="field">
                  <label>&nbsp;</label>
                  <div class="actions">
                    <button type="submit">Показать расчёт</button>
                  </div>
                </div>
              </div>
            </form>

            <?php if ($yandexSingleTest !== null): ?>
              <?php
                $yandexRow = (array)($yandexSingleTest['row'] ?? []);
                $yandexCalc = (array)($yandexRow['calc'] ?? []);
                $yandexDesired = is_array($yandexCalc['desired_state'] ?? null) ? (array)$yandexCalc['desired_state'] : null;
                $yandexBreakdown = is_array($yandexCalc['breakdown'] ?? null) ? (array)$yandexCalc['breakdown'] : [];
                $yandexRuntimeExpenses = is_array($yandexCalc['runtime_expenses'] ?? null) ? (array)$yandexCalc['runtime_expenses'] : null;
                $yandexCurrentSnapshot = (($yandexCalc['purchase_cost'] ?? null) !== null && (float)($yandexCalc['current_price'] ?? 0) > 0)
                  ? yandex_price_tool_profit_snapshot($currentFeed, (float)$yandexCalc['purchase_cost'], (float)$yandexCalc['current_price'], $yandexRuntimeExpenses)
                  : null;
                $yandexRecommendedSnapshot = (($yandexCalc['purchase_cost'] ?? null) !== null && (float)($yandexCalc['recommended_price'] ?? 0) > 0)
                  ? yandex_price_tool_profit_snapshot($currentFeed, (float)$yandexCalc['purchase_cost'], (float)$yandexCalc['recommended_price'], $yandexRuntimeExpenses)
                  : null;
                $yandexMinSnapshot = (($yandexCalc['purchase_cost'] ?? null) !== null && (float)($yandexCalc['recommended_min_price'] ?? 0) > 0)
                  ? yandex_price_tool_profit_snapshot($currentFeed, (float)$yandexCalc['purchase_cost'], (float)$yandexCalc['recommended_min_price'], $yandexRuntimeExpenses)
                  : null;
                $yandexRecommendedBreakdown = is_array($yandexRecommendedSnapshot['breakdown'] ?? null) ? (array)$yandexRecommendedSnapshot['breakdown'] : [];
                $yandexCurrentCosts = price_tool_snapshot_total_costs($yandexCurrentSnapshot);
                $yandexRecommendedCosts = price_tool_snapshot_total_costs($yandexRecommendedSnapshot);
                $yandexMinCosts = price_tool_snapshot_total_costs($yandexMinSnapshot);
                $yandexDeltaPrice = ($yandexCalc['recommended_price'] !== null && $yandexCalc['current_price'] !== null)
                  ? ((float)$yandexCalc['recommended_price'] - (float)$yandexCalc['current_price'])
                  : null;
              ?>
              <div class="stats" style="margin-top:18px;">
                <div class="stat"><div class="label">Артикул</div><div class="value" style="font-size:20px;"><?= h((string)($yandexCalc['offer_id'] ?? '—')) ?></div></div>
                <div class="stat"><div class="label">Артикул Яндекс</div><div class="value" style="font-size:20px;"><?= h((string)($yandexCalc['remote_offer_id'] ?? '—')) ?></div></div>
                <div class="stat"><div class="label">Закупка</div><div class="value"><?= h(fmt_rub($yandexCalc['purchase_cost'] ?? null)) ?></div></div>
                <div class="stat"><div class="label">Текущая цена</div><div class="value"><?= h(fmt_rub($yandexCalc['current_price'] ?? null)) ?></div></div>
                <div class="stat"><div class="label">Рекомендуемая цена</div><div class="value"><?= h(fmt_rub($yandexCalc['recommended_price'] ?? null)) ?></div></div>
                <div class="stat"><div class="label">minimumForBestseller</div><div class="value"><?= !empty($yandexCalc['excluded_from_bestsellers']) ? 'не отправляется' : h(fmt_rub($yandexCalc['recommended_min_price'] ?? null)) ?></div></div>
                <div class="stat"><div class="label">Доход</div><div class="value"><?= h(fmt_rub($yandexCalc['profit_rub'] ?? null)) ?></div></div>
                <div class="stat"><div class="label">Режим цены</div><div class="value" style="font-size:20px;"><?= (($yandexCalc['price_scope'] ?? '') === 'campaign') ? 'магазин' : 'кабинет' ?></div></div>
                <div class="stat"><div class="label">Привлекательность</div><div class="value" style="font-size:20px;"><?= h((string)($yandexCalc['competitiveness'] ?: '—')) ?></div></div>
              </div>

              <?php if ($yandexDeltaPrice !== null): ?>
                <div class="delta-banner">
                  Разница с текущей ценой Яндекс Маркета: <b><?= h(fmt_rub($yandexDeltaPrice)) ?></b>
                  <?php if ($yandexDeltaPrice > 0): ?>
                    — расчёт предлагает поднять цену.
                  <?php elseif ($yandexDeltaPrice < 0): ?>
                    — расчёт предлагает снизить цену.
                  <?php else: ?>
                    — текущая цена уже совпадает с расчётной.
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="hero-reference">
                <div class="hero-card compact current">
                  <div class="hero-title">Текущая цена Яндекс Маркет</div>
                  <div class="hero-price"><?= h(fmt_rub($yandexCalc['current_price'] ?? null)) ?></div>
                  <div class="hero-compact-meta">
                    <div class="hero-metric">
                      <div class="label">Доход</div>
                      <div class="value"><?= h(fmt_rub($yandexCurrentSnapshot['profit_rub'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">К закупке</div>
                      <div class="value"><?= h(fmt_percent($yandexCurrentSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Расходы</div>
                      <div class="value"><?= h(fmt_rub($yandexCurrentCosts)) ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card compact current">
                  <div class="hero-title">Текущий minimumForBestseller</div>
                  <div class="hero-price"><?= h(fmt_rub($yandexCalc['current_minimum_for_bestseller'] ?? null)) ?></div>
                  <div class="hero-compact-meta">
                    <div class="hero-metric">
                      <div class="label">Зачеркнутая</div>
                      <div class="value"><?= h(fmt_rub($yandexCalc['current_discount_base'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Оптимальная</div>
                      <div class="value"><?= h(fmt_rub($yandexCalc['optimal_price'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Средняя</div>
                      <div class="value"><?= h(fmt_rub($yandexCalc['average_price'] ?? null)) ?></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="hero-compare">
                <div class="hero-card recommended">
                  <div class="hero-title">Расчётная цена Яндекс</div>
                  <div class="hero-price"><?= h(fmt_rub($yandexCalc['recommended_price'] ?? null)) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">Доход, ₽</div>
                      <div class="value"><?= h(fmt_rub($yandexRecommendedSnapshot['profit_rub'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Доход к закупке</div>
                      <div class="value"><?= h(fmt_percent($yandexRecommendedSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Суммарные расходы</div>
                      <div class="value"><?= h(fmt_rub($yandexRecommendedCosts)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Источник тарифов</div>
                      <div class="value" style="font-size:18px;"><?= h(price_tool_cost_source_label((string)($yandexBreakdown['source'] ?? ''), 'Яндекс')) ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card recommended">
                  <div class="hero-title">Расчётный minimumForBestseller</div>
                  <div class="hero-price"><?= !empty($yandexCalc['excluded_from_bestsellers']) ? 'не отправляется' : h(fmt_rub($yandexCalc['recommended_min_price'] ?? null)) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">Доход, ₽</div>
                      <div class="value"><?= h(fmt_rub($yandexMinSnapshot['profit_rub'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Доход к закупке</div>
                      <div class="value"><?= h(fmt_percent($yandexMinSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Суммарные расходы</div>
                      <div class="value"><?= h(fmt_rub($yandexMinCosts)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Цель minimum</div>
                      <div class="value"><?= h(fmt_rub($yandexBreakdown['min_target_profit_rub'] ?? null)) ?></div>
                    </div>
                  </div>
                </div>

                <div class="hero-card marketing">
                  <div class="hero-title">Итог для отправки</div>
                  <div class="hero-price"><?= h(fmt_rub($yandexDesired['price'] ?? ($yandexCalc['recommended_price'] ?? null))) ?></div>
                  <div class="hero-grid">
                    <div class="hero-metric">
                      <div class="label">minimumForBestseller</div>
                      <div class="value"><?= !empty($yandexCalc['excluded_from_bestsellers']) ? 'не отправляется' : h(fmt_rub($yandexDesired['minimum_for_bestseller'] ?? ($yandexCalc['recommended_min_price'] ?? null))) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">discountBase</div>
                      <div class="value"><?= h(fmt_rub($yandexDesired['discount_base'] ?? null)) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Remote offerId</div>
                      <div class="value" style="font-size:18px;"><?= h((string)($yandexCalc['remote_offer_id'] ?? '—')) ?></div>
                    </div>
                    <div class="hero-metric">
                      <div class="label">Нужно отправить</div>
                      <div class="value"><?= $yandexDesired !== null && yandex_price_tool_desired_state_needs_push($yandexCalc) ? 'Да' : 'Нет' ?></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="strategy-grid">
                <div class="strategy-kv"><div class="label">Тарифы Яндекса</div><div class="value"><?= h(fmt_rub($yandexBreakdown['tariff_total_rub'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Логистика</div><div class="value"><?= h(fmt_rub($yandexBreakdown['tariff_logistics_rub'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Комиссии и услуги</div><div class="value"><?= h(fmt_rub($yandexBreakdown['tariff_platform_rub'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Ценозависимая часть</div><div class="value"><?= h(fmt_percent($yandexBreakdown['marketplace_variable_percent'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Цена расчёта тарифов</div><div class="value"><?= h(fmt_rub($yandexBreakdown['tariff_reference_price'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Оптимальная цена Яндекса</div><div class="value"><?= h(fmt_rub($yandexCalc['optimal_price'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Средняя цена Яндекса</div><div class="value"><?= h(fmt_rub($yandexCalc['average_price'] ?? null)) ?></div></div>
                <div class="strategy-kv"><div class="label">Категория API</div><div class="value" style="font-size:18px;"><?= h((string)($yandexBreakdown['category_id'] ?? 0)) ?></div></div>
                <div class="strategy-kv"><div class="label">Источник расходов</div><div class="value" style="font-size:18px;"><?= h(($yandexBreakdown['source'] ?? '') === 'api' ? 'Яндекс API' : 'Нет тарифов') ?></div></div>
              </div>

              <div class="strategy-card">
                <h3>Как получилась цена Яндекс Маркета</h3>
                <div class="muted">Расчёт использует закупку из XML, тарифы Яндекса по товару, целевую доходность профиля и рекомендации Маркета по конкурентности цены.</div>
                <div class="strategy-grid">
                  <div class="strategy-kv">
                    <div class="label">Закупка из фида</div>
                    <div class="value"><?= h(fmt_rub($yandexCalc['purchase_cost'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Целевой доход</div>
                    <div class="value"><?= h(fmt_rub($yandexBreakdown['target_profit_rub'] ?? null)) ?></div>
                    <div class="muted">или <?= h(fmt_percent($yandexBreakdown['target_profit_percent_effective'] ?? null)) ?> к закупке</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Минимальный доход для minimum</div>
                    <div class="value"><?= h(fmt_rub($yandexBreakdown['min_target_profit_rub'] ?? null)) ?></div>
                    <div class="muted">или <?= h(fmt_percent($yandexBreakdown['min_target_profit_percent_effective'] ?? null)) ?> к закупке</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Фиксированные тарифы API</div>
                    <div class="value"><?= h(fmt_rub($yandexRecommendedBreakdown['marketplace_fixed_rub'] ?? null)) ?></div>
                    <div class="muted">логистика <?= h(fmt_rub($yandexBreakdown['tariff_logistics_rub'] ?? null)) ?>, услуги <?= h(fmt_rub($yandexBreakdown['tariff_platform_rub'] ?? null)) ?>, прочие <?= h(fmt_rub($yandexBreakdown['tariff_other_rub'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Ценозависимые тарифы</div>
                    <div class="value"><?= h(fmt_rub($yandexRecommendedBreakdown['variable_costs_rub'] ?? null)) ?></div>
                    <div class="muted">API <?= h(fmt_percent($yandexBreakdown['marketplace_variable_percent'] ?? ($yandexBreakdown['variable_percent'] ?? null))) ?> + ручные проценты профиля</div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Потери и возвраты</div>
                    <div class="value"><?= h(!empty($yandexBreakdown['include_returns_in_cost']) ? fmt_rub($yandexRecommendedBreakdown['issue_cost'] ?? null) : 'Не учитываются') ?></div>
                    <div class="muted">доля рисков <?= h(fmt_percent($yandexBreakdown['issue_total_percent'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Налоги</div>
                    <div class="value"><?= h(fmt_rub(((float)($yandexRecommendedBreakdown['tax_rub'] ?? 0)) + ((float)($yandexRecommendedBreakdown['vat_rub'] ?? 0)) + ((float)($yandexRecommendedBreakdown['profit_tax_rub'] ?? 0)))) ?></div>
                    <div class="muted"><?= h((string)($yandexBreakdown['tax_mode'] ?? 'none')) ?> · НДС <?= h(fmt_percent($yandexBreakdown['vat_percent'] ?? null)) ?></div>
                  </div>
                  <div class="strategy-kv">
                    <div class="label">Что отправится в Яндекс</div>
                    <div class="value" style="font-size:20px; line-height:1.35;">
                      price <?= h(fmt_rub($yandexDesired['price'] ?? null)) ?><br>
                      minimum <?= !empty($yandexCalc['excluded_from_bestsellers']) ? 'не отправляется' : h(fmt_rub($yandexDesired['minimum_for_bestseller'] ?? null)) ?><br>
                      old price <?= h(fmt_rub($yandexDesired['discount_base'] ?? null)) ?>
                    </div>
                  </div>
                </div>
              </div>

              <?php $yandexTariffRows = is_array($yandexBreakdown['tariffs'] ?? null) ? (array)$yandexBreakdown['tariffs'] : []; ?>
              <?php if ($yandexTariffRows): ?>
                <div class="card" style="margin-top:16px; padding:16px; background:#f8fbff; border-color:#bfdbfe;">
                  <h3 style="margin:0 0 8px;">Тарифные строки Яндекс API</h3>
                  <div class="table-wrap" style="margin-top:12px;">
                    <table>
                      <thead>
                        <tr>
                          <th>Тип тарифа</th>
                          <th>Сумма</th>
                          <th>Ценозависимый</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($yandexTariffRows as $tariffRow): ?>
                          <?php if (!is_array($tariffRow)) continue; ?>
                          <tr>
                            <td><?= h((string)($tariffRow['type'] ?? 'OTHER')) ?></td>
                            <td><?= h(fmt_rub($tariffRow['amount_rub'] ?? null)) ?></td>
                            <td><?= !empty($tariffRow['variable']) ? 'Да' : 'Нет' ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endif; ?>
              <?php if (!empty($yandexCalc['warnings'])): ?>
                <div class="card" style="margin-top:16px; padding:16px; background:#fffaf3; border-color:#fed7aa;">
                  <h3 style="margin:0 0 8px;">Предупреждения</h3>
                  <ul class="warning-list">
                    <?php foreach ((array)$yandexCalc['warnings'] as $warning): ?>
                      <li><?= h((string)$warning) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($yandexDesired !== null): ?>
                <div class="actions" style="margin-top:16px;">
                  <form method="post">
                    <input type="hidden" name="action" value="apply_single_offer_to_yandex">
                    <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
                    <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                    <input type="hidden" name="test_offer_id" value="<?= h((string)($yandexCalc['offer_id'] ?? '')) ?>">
                    <button type="submit">Отправить цену в Яндекс</button>
                  </form>
                </div>
              <?php endif; ?>
              <?php if ($yandexSingleApplyReport !== null): ?>
                <div class="card" style="margin-top:16px; padding:16px;">
                  <h3 style="margin:0 0 8px;">Результат отправки в Яндекс</h3>
                  <div class="muted">Принято товаров: <?= h((string)($yandexSingleApplyReport['accepted'] ?? 0)) ?></div>
                  <?php if (!empty($yandexSingleApplyReport['errors'])): ?>
                    <ul class="warning-list" style="margin-top:8px;">
                      <?php foreach ((array)$yandexSingleApplyReport['errors'] as $errorLine): ?>
                        <li><?= h((string)$errorLine) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="preview" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Предпросмотр всего фида</div>
            <div class="workspace-block-subtitle">Проверка по всему XML-фиду: совпадение с кабинетом, наличие тарифов и список товаров, которые будут отправлены.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
          <div class="card">
            <form method="post" class="settings-form" style="margin-top:0;">
              <input type="hidden" name="action" value="preview_feed">
              <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <div class="actions">
                <button type="submit">Построить предпросмотр Яндекс</button>
              </div>
            </form>
            <?php if ($yandexPreview !== null): ?>
              <div class="stats" style="margin-top:18px;">
                <div class="stat"><div class="label">Товаров в фиде</div><div class="value"><?= h((string)($yandexPreview['stats']['offers_total'] ?? 0)) ?></div></div>
                <div class="stat"><div class="label">Цены API</div><div class="value"><?= h((string)($yandexPreview['stats']['api_prices'] ?? 0)) ?></div></div>
                <div class="stat"><div class="label">Карточки API</div><div class="value"><?= h((string)($yandexPreview['stats']['api_mappings'] ?? 0)) ?></div></div>
                <div class="stat"><div class="label">Рекомендации</div><div class="value"><?= h((string)($yandexPreview['stats']['api_recommendations'] ?? 0)) ?></div></div>
                <div class="stat"><div class="label">Тарифы</div><div class="value"><?= h((string)($yandexPreview['stats']['api_tariffs'] ?? 0)) ?></div></div>
                <div class="stat"><div class="label">Режим цены</div><div class="value" style="font-size:20px;"><?= empty($yandexPreview['context']['only_default_price']) ? 'магазин' : 'кабинет' ?></div></div>
              </div>
              <?php foreach ((array)($yandexPreview['context']['warnings'] ?? []) as $contextWarning): ?>
                <div class="notice" style="margin-top:12px;"><?= h((string)$contextWarning) ?></div>
              <?php endforeach; ?>
              <div class="table-wrap" style="margin-top:16px;">
                <table>
                  <thead>
                    <tr>
                      <th>Артикул</th>
                      <th>Закупка</th>
                      <th>Текущая цена</th>
                      <th>Расчётная цена</th>
                      <th>minimumForBestseller</th>
                      <th>Режим</th>
                      <th>Тарифы</th>
                      <th>Доход</th>
                      <th>Статус</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ((array)($yandexPreview['rows'] ?? []) as $previewRow): ?>
                      <?php $row = (array)($previewRow['calc'] ?? []); ?>
                      <tr>
                        <td><code><?= h((string)($row['offer_id'] ?? '')) ?></code><div class="muted"><?= h((string)($row['remote_offer_id'] ?? '')) ?></div></td>
                        <td><?= h(fmt_rub($row['purchase_cost'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['current_price'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['recommended_price'] ?? null)) ?></td>
                        <td><?= !empty($row['excluded_from_bestsellers']) ? 'не отправляется' : h(fmt_rub($row['recommended_min_price'] ?? null)) ?></td>
                        <td><?= (($row['price_scope'] ?? '') === 'campaign') ? 'магазин' : 'кабинет' ?></td>
                        <td><?= h(fmt_rub($row['breakdown']['tariff_total_rub'] ?? null)) ?></td>
                        <td><?= h(fmt_rub($row['profit_rub'] ?? null)) ?></td>
                        <td>
                          <?php if (!empty($previewRow['needs_push'])): ?>
                            <span class="status-ok">Будет отправлен</span>
                          <?php elseif (($row['desired_state'] ?? null) !== null): ?>
                            <span class="muted">Без изменений</span>
                          <?php else: ?>
                            <span style="color:#b42318; font-weight:700;">Пропуск</span>
                          <?php endif; ?>
                          <?php if (!empty($row['warnings'])): ?>
                            <ul class="warning-list" style="margin-top:8px;">
                              <?php foreach ((array)$row['warnings'] as $warning): ?>
                                <li><?= h((string)$warning) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </details>
    </div>
  </div>
  <?php else: ?>
  <div class="layout">
    <div>
      <details class="workspace-block" data-storage-key="settings" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Настройки</div>
            <div class="workspace-block-subtitle">Здесь собраны все параметры расчёта этого фида: источник данных, целевая доходность, расходы, риски, налоги и правила продвижения.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
      <div class="card">
        <h2 style="margin-top:0;">Настройки расчёта</h2>
        <form class="settings-form" method="post">
          <input id="feedAction" type="hidden" name="action" value="save_feed">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">

          <div class="section">
            <h3>Источник данных</h3>
            <div class="muted">Этот блок отвечает за то, откуда сервис берёт список товаров и закупочную цену.</div>
            <div class="grid">
              <div class="field">
                <label for="name">Название профиля</label>
                <input id="name" name="name" type="text" value="<?= h((string)($currentFeed['name'] ?? '')) ?>" required>
              </div>
              <div class="field">
                <label for="supplier_id">Поставщик</label>
                <select id="supplier_id" name="supplier_id" required>
                  <option value="">Выбери поставщика</option>
                  <?php foreach ($supplierOptions as $supplier): ?>
                    <?php
                      $supplierId = (int)($supplier['id'] ?? 0);
                      $supplierLabel = trim((string)($supplier['name'] ?? ''));
                      $supplierCode = trim((string)($supplier['supplier_code'] ?? ''));
                      if ($supplierCode !== '') {
                          $supplierLabel .= ' · ' . $supplierCode;
                      }
                    ?>
                    <option value="<?= h((string)$supplierId) ?>" <?= ((int)($currentFeed['supplier_id'] ?? 0) === $supplierId) ? 'selected' : '' ?>>
                      <?= h($supplierLabel) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small><?= h($fieldHelp['supplier_id']) ?> <a href="suppliers.php">Открыть раздел поставщиков</a></small>
              </div>
              <div class="field">
                <label for="cost_tag">Тег закупочной цены</label>
                <input id="cost_tag" name="cost_tag" type="text" value="<?= h((string)($currentFeed['cost_tag'] ?? '')) ?>" required>
                <small><?= h($fieldHelp['cost_tag']) ?></small>
              </div>
              <div class="field">
                <label>Источник данных</label>
                <div class="muted" style="font-size:14px;">
                  <?php if ((int)($currentFeed['supplier_id'] ?? 0) > 0): ?>
                    Поставщик: <?= h((string)($currentFeed['supplier_name'] ?? '')) ?><br>
                    Код: <code><?= h((string)($currentFeed['supplier_code'] ?? '')) ?></code><br>
                    XML: <a href="<?= h((string)($currentFeed['feed_url'] ?? '')) ?>" target="_blank" rel="noopener">открыть источник</a>
                  <?php else: ?>
                    Выбери поставщика, чтобы подтянуть код и XML-источник.
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Модификатор цены</h3>
            <div class="muted">Этот небольшой блок позволяет уже после базового расчёта сдвинуть все итоговые цены вверх или вниз на процент или на фиксированную сумму.</div>
            <div class="modifier-grid">
              <div class="modifier-box">
                <h4>Обычная цена</h4>
                <div class="field">
                  <label for="price_modifier_mode">Модификатор обычной цены</label>
                  <select id="price_modifier_mode" name="price_modifier_mode">
                    <option value="none" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не применять</option>
                    <option value="percent" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'percent') ? 'selected' : '' ?>>Изменить на %</option>
                    <option value="fixed" <?= (($currentFeed['price_modifier_mode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Изменить на ₽</option>
                  </select>
                  <small><?= h($fieldHelp['price_modifier_mode']) ?></small>
                </div>
                <div class="field">
                  <label for="price_modifier_value">Значение для обычной цены</label>
                  <input id="price_modifier_value" name="price_modifier_value" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['price_modifier_value'] ?? '0.00')) ?>" placeholder="Например: 5 или -100">
                  <small><?= h($fieldHelp['price_modifier_value']) ?></small>
                </div>
              </div>
              <div class="modifier-box">
                <h4>Минимальная цена</h4>
                <div class="field">
                  <label for="price_modifier_min_mode">Модификатор минимальной цены</label>
                  <select id="price_modifier_min_mode" name="price_modifier_min_mode">
                    <option value="none" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не применять</option>
                    <option value="percent" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'percent') ? 'selected' : '' ?>>Изменить на %</option>
                    <option value="fixed" <?= (($currentFeed['price_modifier_min_mode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Изменить на ₽</option>
                  </select>
                  <small><?= h($fieldHelp['price_modifier_min_mode']) ?></small>
                </div>
                <div class="field">
                  <label for="price_modifier_min_value">Значение для минимальной цены</label>
                  <input id="price_modifier_min_value" name="price_modifier_min_value" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['price_modifier_min_value'] ?? '0.00')) ?>" placeholder="Например: 3 или -50">
                  <small><?= h($fieldHelp['price_modifier_min_value']) ?></small>
                </div>
              </div>
            </div>
          </div>

          <?php $targetRangeRows = profit_range_rows_for_view($currentFeed, 'target_profit_ranges'); ?>
          <?php $minTargetRangeRows = profit_range_rows_for_view($currentFeed, 'min_target_profit_ranges'); ?>

          <div class="section section-target">
            <h3>Целевая цена</h3>
            <div class="muted">Здесь задаётся желаемая прибыль и то, как итоговая цена округляется после расчёта.</div>
            <div class="grid">
              <div class="field">
                <label for="fulfillment_scheme">Схема Ozon</label>
                <select id="fulfillment_scheme" name="fulfillment_scheme">
                  <option value="fbs" <?= (($currentFeed['fulfillment_scheme'] ?? '') === 'fbs') ? 'selected' : '' ?>>FBS</option>
                  <option value="fbo" <?= (($currentFeed['fulfillment_scheme'] ?? '') === 'fbo') ? 'selected' : '' ?>>FBO</option>
                </select>
                <small><?= h($fieldHelp['fulfillment_scheme']) ?></small>
              </div>
              <div class="field">
                <label for="rounding_mode">Округление</label>
                <select id="rounding_mode" name="rounding_mode">
                  <?php foreach ([
                    'rub' => 'До 1 ₽',
                    '5rub' => 'До 5 ₽ вверх',
                    '10rub' => 'До 10 ₽ вверх',
                    'end9' => 'Окончание на 9',
                    'end90' => 'Окончание на 90',
                    'end99' => 'Окончание на 99',
                  ] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= (($currentFeed['rounding_mode'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <small><?= h($fieldHelp['rounding_mode']) ?></small>
              </div>
              <div class="field">
                <label for="strike_discount_percent">Размер скидки для зачёркнутой цены, %</label>
                <input id="strike_discount_percent" name="strike_discount_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['strike_discount_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['strike_discount_percent']) ?></small>
              </div>
            </div>

            <div class="target-subgrid">
              <div class="target-box">
                <h4>Обычная цена</h4>
                <div class="muted">Этот подблок задаёт целевую прибыль для основной цены продажи.</div>
                <div class="field">
                  <label for="target_profit_percent">Доход для обычной цены, % от закупки</label>
                  <input id="target_profit_percent" name="target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_percent'] ?? '20.00')) ?>">
                  <small><?= h($fieldHelp['target_profit_percent']) ?></small>
                </div>
                <div class="field">
                  <label for="target_profit_min_rub">Минимальный доход для обычной цены, ₽</label>
                  <input id="target_profit_min_rub" name="target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['target_profit_min_rub'] ?? '0.00')) ?>">
                  <small><?= h($fieldHelp['target_profit_min_rub']) ?></small>
                </div>
                <div class="range-box" data-range-editor="target">
                  <h4>Диапазоны для обычной цены</h4>
                  <div class="muted"><?= h($fieldHelp['target_profit_ranges']) ?></div>
                  <div class="range-head">
                    <div>От закупки</div>
                    <div>До закупки</div>
                    <div>Доход, %</div>
                    <div></div>
                  </div>
                  <div data-range-rows>
                    <?php foreach ($targetRangeRows as $rangeRow): ?>
                      <div class="range-row">
                        <input type="text" inputmode="decimal" name="target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                        <input type="text" inputmode="decimal" name="target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                        <input type="text" inputmode="decimal" name="target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="20">
                        <button type="button" class="range-remove" data-range-remove>×</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="actions">
                    <button type="button" class="secondary" data-range-add="target">Добавить диапазон</button>
                  </div>
                </div>
              </div>

              <div class="target-box">
                <h4>Минимальная цена</h4>
                <div class="muted">Этот подблок задаёт целевую прибыль, рублёвый минимум дохода и, при необходимости, дополнительное снижение <code>min price</code> ради перехода на следующий уровень индекса цены.</div>
                <div class="field">
                  <label for="min_target_profit_percent">Доход для min price, % от закупки</label>
                  <input id="min_target_profit_percent" name="min_target_profit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_percent'] ?? '10.00')) ?>">
                  <small><?= h($fieldHelp['min_target_profit_percent']) ?></small>
                </div>
                <div class="field">
                  <label for="min_target_profit_min_rub">Минимальный доход для min price, ₽</label>
                  <input id="min_target_profit_min_rub" name="min_target_profit_min_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['min_target_profit_min_rub'] ?? '0.00')) ?>">
                  <small><?= h($fieldHelp['min_target_profit_min_rub']) ?></small>
                </div>
                <div class="range-box" data-range-editor="min_target">
                  <h4>Диапазоны для min price</h4>
                  <div class="muted"><?= h($fieldHelp['min_target_profit_ranges']) ?></div>
                  <div class="range-head">
                    <div>От закупки</div>
                    <div>До закупки</div>
                    <div>Доход, %</div>
                    <div></div>
                  </div>
                  <div data-range-rows>
                    <?php foreach ($minTargetRangeRows as $rangeRow): ?>
                      <div class="range-row">
                        <input type="text" inputmode="decimal" name="min_target_range_from[]" value="<?= h((string)$rangeRow['from']) ?>" placeholder="0">
                        <input type="text" inputmode="decimal" name="min_target_range_to[]" value="<?= h((string)$rangeRow['to']) ?>" placeholder="пусто = и выше">
                        <input type="text" inputmode="decimal" name="min_target_range_percent[]" value="<?= h((string)$rangeRow['percent']) ?>" placeholder="10">
                        <button type="button" class="range-remove" data-range-remove>×</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="actions">
                    <button type="button" class="secondary" data-range-add="min_target">Добавить диапазон</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="section section-target">
            <h3>Инструменты продвижения</h3>
            <div class="muted">Здесь ты управляешь, какие механики сервис вообще может использовать для установки итоговой цены: переход по индексу цены для <code>min price</code> и продвижение через акции Ozon.</div>
            <div class="target-subgrid">
              <div class="target-box">
                <h4>Переход по индексу цены</h4>
                <div class="muted">Эта опция влияет только на <code>min price</code> и позволяет дополнительно снизить его, если это даёт следующую ступень индекса в пределах допустимого снижения.</div>
                <label class="index-step-toggle">
                  <input type="checkbox" name="min_price_index_step_enabled" value="1" <?= !empty($currentFeed['min_price_index_step_enabled']) ? 'checked' : '' ?>>
                  <span>Применять переход по индексу цены для min price</span>
                </label>
                <small><?= h($fieldHelp['min_price_index_step_enabled']) ?></small>
              </div>
              <div class="target-box">
                <h4>Акционная цена и акции Ozon</h4>
                <div class="muted">Если опция включена, сервис ищет подходящие акции, рассчитывает акционную цену и строит итоговый маркетинговый сценарий через акции. Если выключена, цена определяется без акций.</div>
                <label class="index-step-toggle">
                  <input type="checkbox" name="action_pricing_enabled" value="1" <?= !empty($currentFeed['action_pricing_enabled']) ? 'checked' : '' ?>>
                  <span>Рассчитывать акционную цену и добавлять товар в акции</span>
                </label>
                <small><?= h($fieldHelp['action_pricing_enabled']) ?></small>
              </div>
            </div>
          </div>

          <div class="section section-costs">
            <h3>Расходы на закупку, упаковку и отправку</h3>
            <div class="muted">Здесь собраны расходы, которые напрямую относятся к себестоимости товара, упаковке на складе и подготовке отправки. Часть из них фиксированная, часть считается как процент от итоговой цены.</div>
            <div class="grid">
              <div class="field">
                <label for="fulfillment_markup_rub">Расходы на упаковку на складе, ₽</label>
                <input id="fulfillment_markup_rub" name="fulfillment_markup_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['fulfillment_markup_rub'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['fulfillment_markup_rub']) ?></small>
              </div>
              <div class="field">
                <label for="fulfillment_markup_percent">Расходы на упаковку на складе, %</label>
                <input id="fulfillment_markup_percent" name="fulfillment_markup_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['fulfillment_markup_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['fulfillment_markup_percent']) ?></small>
              </div>
            </div>
          </div>

          <div class="section section-shipment">
            <h3>Скорость отгрузки и поправка к комиссии</h3>
            <div class="muted">Этот блок отдельно считает ожидаемую среднюю скидку или штраф к комиссии Ozon в зависимости от того, как быстро ты обычно отгружаешь заказы.</div>
            <div
              class="shipment-summary"
              id="shipmentSummary"
              data-initial-total="<?= h(fmt_input_decimal(shipment_mix_total_percent($currentFeed))) ?>"
              data-initial-adjustment="<?= h(fmt_input_decimal(shipment_mix_base_adjustment_percent($currentFeed))) ?>"
            >
              <div class="shipment-chips">
                <div class="shipment-chip" id="shipmentSumChip">Сумма окон: <?= h(fmt_input_decimal(shipment_mix_total_percent($currentFeed))) ?>%</div>
                <div class="shipment-chip" id="shipmentAdjChip">Средняя поправка к комиссии: <?= h(fmt_input_decimal(shipment_mix_base_adjustment_percent($currentFeed))) ?>%</div>
              </div>
              <div class="shipment-note" id="shipmentSummaryNote">Быстрые окна уменьшают комиссию, поздние увеличивают её. Для окон 24+ часов минимальные штрафы 50/100 ₽ могут сделать реальную поправку выше на дешёвых товарах.</div>
            </div>
            <div class="grid">
              <div class="field">
                <label for="ship_0_12_percent">Отгрузка 0–12 часов, %</label>
                <input id="ship_0_12_percent" name="ship_0_12_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['ship_0_12_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['ship_0_12_percent']) ?></small>
              </div>
              <div class="field">
                <label for="ship_12_24_percent">Отгрузка 12–24 часа, %</label>
                <input id="ship_12_24_percent" name="ship_12_24_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['ship_12_24_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['ship_12_24_percent']) ?></small>
              </div>
              <div class="field">
                <label for="ship_24_36_percent">Отгрузка 24–36 часов, %</label>
                <input id="ship_24_36_percent" name="ship_24_36_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['ship_24_36_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['ship_24_36_percent']) ?></small>
              </div>
              <div class="field">
                <label for="ship_36_48_percent">Отгрузка 36–48 часов, %</label>
                <input id="ship_36_48_percent" name="ship_36_48_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['ship_36_48_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['ship_36_48_percent']) ?></small>
              </div>
              <div class="field">
                <label for="ship_48_plus_percent">Отгрузка 48+ часов, %</label>
                <input id="ship_48_plus_percent" name="ship_48_plus_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['ship_48_plus_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['ship_48_plus_percent']) ?></small>
              </div>
            </div>
          </div>

          <div class="section section-risk">
            <h3>Потери и риски продаж</h3>
            <div class="muted">Этот блок отдельно собирает потери: невыкупы, возвраты и безвозвратные потери. Здесь же задаются тарифы Ozon на обработку невыкупа и возврата.</div>
            <div class="grid">
              <div class="field">
                <label for="nonbuyout_percent">Невыкупы, %</label>
                <input id="nonbuyout_percent" name="nonbuyout_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['nonbuyout_percent']) ?></small>
              </div>
              <div class="field">
                <label for="return_resellable_percent">Возвраты, %</label>
                <input id="return_resellable_percent" name="return_resellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_resellable_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['return_resellable_percent']) ?></small>
              </div>
              <div class="field">
                <label for="return_nonresellable_percent">Безвозвратные потери, %</label>
                <input id="return_nonresellable_percent" name="return_nonresellable_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_nonresellable_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['return_nonresellable_percent']) ?></small>
              </div>
              <div class="field">
                <label for="nonbuyout_processing_rub">Обработка невыкупа Ozon, ₽</label>
                <input id="nonbuyout_processing_rub" name="nonbuyout_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['nonbuyout_processing_rub'] ?? '50.00')) ?>">
                <small><?= h($fieldHelp['nonbuyout_processing_rub']) ?></small>
              </div>
              <div class="field">
                <label for="return_processing_rub">Обработка возврата Ozon, ₽</label>
                <input id="return_processing_rub" name="return_processing_rub" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['return_processing_rub'] ?? '50.00')) ?>">
                <small><?= h($fieldHelp['return_processing_rub']) ?></small>
              </div>
            </div>
          </div>

          <div class="section section-extra">
            <h3>Прочие процентные расходы</h3>
            <div class="muted">Здесь остаются обычные процентные расходы, которые добавляются к формуле, но не относятся ни к скорости отгрузки, ни к потерям продаж.</div>
            <div class="grid">
              <div class="field">
                <label for="promotion_percent">Наценка на продвижение, %</label>
                <input id="promotion_percent" name="promotion_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['promotion_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['promotion_percent']) ?></small>
              </div>
              <div class="field">
                <label for="credit_percent">Процент по кредиту, %</label>
                <input id="credit_percent" name="credit_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['credit_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['credit_percent']) ?></small>
              </div>
              <div class="field">
                <label for="extra_expenses_percent">Доп. расходы, % от финальной цены</label>
                <input id="extra_expenses_percent" name="extra_expenses_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['extra_expenses_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['extra_expenses_percent']) ?></small>
              </div>
              <div class="field">
                <label for="insurance_percent">Страховые взносы, %</label>
                <input id="insurance_percent" name="insurance_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['insurance_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['insurance_percent']) ?></small>
              </div>
            </div>
          </div>

          <div class="section section-tax">
            <h3>Налоги</h3>
            <div class="muted">Этот блок теперь участвует в формуле: УСН доходы считается от выручки, УСН доходы-расходы — от положительной прибыли, НДС и налог на прибыль можно добавить отдельно.</div>
            <div class="grid">
              <div class="field">
                <label for="tax_mode">Налоговый режим</label>
                <select id="tax_mode" name="tax_mode">
                  <option value="none" <?= (($currentFeed['tax_mode'] ?? '') === 'none') ? 'selected' : '' ?>>Не учитывать</option>
                  <option value="usn_income" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income') ? 'selected' : '' ?>>УСН доходы</option>
                  <option value="usn_income_expense" <?= (($currentFeed['tax_mode'] ?? '') === 'usn_income_expense') ? 'selected' : '' ?>>УСН доходы-расходы</option>
                </select>
                <small><?= h($fieldHelp['tax_mode']) ?></small>
              </div>
              <div class="field">
                <label for="tax_percent">Ставка налога, %</label>
                <input id="tax_percent" name="tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['tax_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['tax_percent']) ?></small>
              </div>
              <div class="field">
                <label for="vat_percent">НДС, %</label>
                <input id="vat_percent" name="vat_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['vat_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['vat_percent']) ?></small>
              </div>
              <div class="field">
                <label for="profit_tax_percent">Налог на прибыль, %</label>
                <input id="profit_tax_percent" name="profit_tax_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($currentFeed['profit_tax_percent'] ?? '0.00')) ?>">
                <small><?= h($fieldHelp['profit_tax_percent']) ?></small>
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Как сейчас считается цена</h3>
            <details class="formula-details">
              <summary>Показать формулу расчёта</summary>
              <div class="formula-box" style="margin-top:12px;">
                <div><b>1.</b> Берём закупку из XML по тегу <code><?= h((string)($currentFeed['cost_tag'] ?? 'cost_tag')) ?></code>.</div>
                <div><b>2.</b> Добавляем постоянные расходы: фиксированную упаковку на складе, фиксированную часть логистики и ожидаемые потери по невыкупам, возвратам и безвозвратным потерям.</div>
                <div><b>3.</b> Пересчитываем ценозависимые расходы: комиссия Ozon, эквайринг, процентную упаковку на складе и только ту часть логистики, которую Ozon реально считает от цены товара.</div>
                <div><b>4.</b> Поправку к комиссии за скорость отгрузки считаем как ожидаемую среднюю скидку/штраф по долям заказов в окнах 0–12, 12–24, 24–36, 36–48 и 48+ часов.</div>
                <div><b>5.</b> Для <code>FBS</code> доставку до клиента берём из текущего ответа Ozon API, а магистраль считаем средневзвешенно по географии: 25% Москва, 25% Санкт-Петербург и 50% регионы.</div>
                <div><b>6.</b> Для <code>FBO</code> оставляем фиксированный нижний тариф логистики <code>min</code>, а средневзвешенную надбавку сверх него масштабируем вместе с ценой по тому же географическому миксу.</div>
                <div><b>7.</b> Целевой доход берём из процента по закупке, а затем сравниваем с рублёвым минимумом: в расчёт идёт большее из этих двух значений.</div>
                <div><b>8.</b> Невыкупы, возвраты и безвозвратные потери сначала переводим в коэффициенты распределения на успешные продажи, чтобы не смешивать разные сценарии.</div>
                <div><b>9.</b> Считаем отдельно обычную цену и <code>min_price</code>: для минимальной цены используются свой процент дохода, свой рублёвый минимум дохода и свой финальный модификатор.</div>
                <div><b>10.</b> Если включён переход по индексу цены, после расчёта <code>min price</code> смотрим, можно ли в пределах 5% перейти на следующий уровень индекса. Если да, ставим цену на порог перехода минус 10 ₽.</div>
                <div><b>11.</b> Налоги считаем по выбранному режиму: УСН доходы — от выручки, УСН доходы-расходы — от положительной прибыли, НДС и налог на прибыль добавляются отдельными строками.</div>
                <div><b>12.</b> В самом конце округляем цену по выбранному правилу.</div>
                <div><b>13.</b> Если задан модификатор, после базового расчёта сдвигаем итоговые цены на указанный процент или фиксированную сумму.</div>
              </div>
            </details>
          </div>

          <div class="actions">
            <button type="submit">Сохранить настройки</button>
            <?php if ((int)($currentFeed['id'] ?? 0) > 0): ?>
              <button
                type="button"
                class="danger"
                onclick="if (confirm('Удалить этот профиль фида?')) { document.getElementById('feedAction').value = 'delete_feed'; this.form.submit(); }"
              >Удалить профиль</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="calculator" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Калькулятор</div>
            <div class="workspace-block-subtitle">Зона для проверки одного товара: быстрый тест, пошаговый расчёт, индекс цены, маркетинговая стратегия и локальные акции Ozon по выбранному offer_id.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
      <div class="card">
        <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
          <div>
            <h2 style="margin:0 0 8px;">Быстрый тест товара</h2>
            <div class="muted">Введи артикул товара. Закупочную цену можно указать вручную или оставить пустой: тогда сервис попробует взять её из текущего фида по этому `offer_id`.</div>
          </div>
        </div>

        <form method="post" class="settings-form" style="margin-top:14px;">
          <input type="hidden" name="action" value="test_single_offer">
          <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <div class="grid">
            <div class="field">
              <label for="test_offer_id">Артикул товара</label>
              <input id="test_offer_id" name="test_offer_id" type="text" value="<?= h((string)($_POST['test_offer_id'] ?? '')) ?>" placeholder="Например: 00616__22">
            </div>
            <div class="field">
              <label for="test_purchase_cost">Закупочная цена</label>
              <input id="test_purchase_cost" name="test_purchase_cost" type="text" inputmode="decimal" value="<?= h((string)($_POST['test_purchase_cost'] ?? '')) ?>" placeholder="Необязательно — можно взять из фида">
            </div>
            <div class="field">
              <label>&nbsp;</label>
              <div class="actions">
                <button type="submit">Показать расчёт</button>
              </div>
            </div>
          </div>
        </form>

        <?php if ($singleTest !== null): ?>
          <?php
            $calc = $singleTest['calc'];
            $currentSnapshot = $singleTest['current_snapshot'];
            $recommendedSnapshot = $singleTest['recommended_snapshot'];
            $currentMinSnapshot = $singleTest['current_min_snapshot'];
            $recommendedMinBeforeIndexSnapshot = $singleTest['recommended_min_before_index_snapshot'];
            $recommendedMinSnapshot = $singleTest['recommended_min_snapshot'];
            $marketingPrice = (float)($singleTest['marketing_price'] ?? 0);
            $marketingSnapshot = is_array($singleTest['marketing_snapshot'] ?? null) ? $singleTest['marketing_snapshot'] : null;
            $marketingIndexLevel = (string)($singleTest['marketing_index_level'] ?? '');
            $marketingMode = (string)($singleTest['marketing_mode'] ?? '');
            $marketingSource = (string)($singleTest['marketing_source'] ?? '');
            $promotionStrategy = $singleTestStrategy ?? ozon_price_build_promotion_strategy($calc, $singleTestPromoRows, $currentFeed);
            $deltaPrice = ($calc['recommended_price'] !== null && $calc['ozon_price'] !== null)
              ? ((float)$calc['recommended_price'] - (float)$calc['ozon_price'])
              : null;
            $deltaMinPrice = ($calc['recommended_min_price'] !== null && $calc['ozon_min_price'] !== null)
              ? ((float)$calc['recommended_min_price'] - (float)$calc['ozon_min_price'])
              : null;
          ?>
          <div class="stats" style="margin-top:18px;">
            <div class="stat">
              <div class="label">Артикул</div>
              <div class="value" style="font-size:20px;"><?= h((string)$singleTest['offer_id']) ?></div>
            </div>
            <div class="stat">
              <div class="label">Закупка</div>
              <div class="value"><?= h(fmt_rub($singleTest['purchase_cost'])) ?></div>
            </div>
            <div class="stat">
              <div class="label">Текущая цена Ozon</div>
              <div class="value"><?= h(fmt_rub($calc['ozon_price'])) ?></div>
            </div>
            <div class="stat">
              <div class="label">Рекомендованная цена</div>
              <div class="value"><?= h(fmt_rub($calc['recommended_price'])) ?></div>
            </div>
            <div class="stat">
              <div class="label">Рекомендованный min price</div>
              <div class="value"><?= h(fmt_rub($calc['recommended_min_price'])) ?></div>
            </div>
          </div>

          <?php if ($deltaPrice !== null): ?>
            <div class="delta-banner">
              Разница с текущей ценой: <b><?= h(fmt_rub($deltaPrice)) ?></b>
              <?php if ($deltaPrice > 0): ?>
                — расчёт предлагает поднять цену.
              <?php elseif ($deltaPrice < 0): ?>
                — расчёт предлагает снизить цену.
              <?php else: ?>
                — текущая цена уже совпадает с расчётной.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($deltaMinPrice !== null): ?>
            <div class="delta-banner" style="margin-top:12px;">
              Разница с текущим min price: <b><?= h(fmt_rub($deltaMinPrice)) ?></b>
              <?php if ($deltaMinPrice > 0): ?>
                — расчёт предлагает поднять минимальную цену.
              <?php elseif ($deltaMinPrice < 0): ?>
                — расчёт предлагает снизить минимальную цену.
              <?php else: ?>
                — текущий min price уже совпадает с расчётным.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="hero-reference">
            <div class="hero-card compact current">
              <div class="hero-title">Текущая цена Ozon</div>
              <div class="hero-price"><?= h(fmt_rub($calc['ozon_price'])) ?></div>
              <div class="hero-compact-meta">
                <div class="hero-metric">
                  <div class="label">Доход</div>
                  <div class="value"><?= h(fmt_rub($currentSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">К закупке</div>
                  <div class="value"><?= h(fmt_percent($currentSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Индекс</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_current_price_level'] ?? '') ?: ($calc['color_index'] ?: '—')) ?></div>
                </div>
              </div>
            </div>

            <div class="hero-card compact current">
              <div class="hero-title">Текущий min price Ozon</div>
              <div class="hero-price"><?= h(fmt_rub($calc['ozon_min_price'])) ?></div>
              <div class="hero-compact-meta">
                <div class="hero-metric">
                  <div class="label">Доход</div>
                  <div class="value"><?= h(fmt_rub($currentMinSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">К закупке</div>
                  <div class="value"><?= h(fmt_percent($currentMinSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Индекс</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_current_min_price_level'] ?? '') ?: '—') ?></div>
                </div>
              </div>
            </div>
          </div>

          <div class="hero-compare">
            <div class="hero-card recommended">
              <div class="hero-title">Расчётная цена</div>
              <div class="hero-price"><?= h(fmt_rub($calc['recommended_price'])) ?></div>
              <div class="hero-grid">
                <div class="hero-metric">
                  <div class="label">Доход, ₽</div>
                  <div class="value"><?= h(fmt_rub($recommendedSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Доход к закупке</div>
                  <div class="value"><?= h(fmt_percent($recommendedSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Суммарные расходы</div>
                  <div class="value"><?= h(fmt_rub($recommendedSnapshot['total_costs_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Индекс цены</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_recommended_price_level'] ?? '') ?: '—') ?></div>
                </div>
              </div>
            </div>

            <div class="hero-card recommended">
              <div class="hero-title">Расчётный min price</div>
              <div class="hero-price"><?= h(fmt_rub($calc['breakdown']['recommended_min_price_before_index_step'] ?? null)) ?></div>
              <div class="hero-grid">
                <div class="hero-metric">
                  <div class="label">Доход, ₽</div>
                  <div class="value"><?= h(fmt_rub($recommendedMinBeforeIndexSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Доход к закупке</div>
                  <div class="value"><?= h(fmt_percent($recommendedMinBeforeIndexSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Суммарные расходы</div>
                  <div class="value"><?= h(fmt_rub($recommendedMinBeforeIndexSnapshot['total_costs_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Индекс цены</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_recommended_min_price_before_index_level'] ?? '') ?: '—') ?></div>
                </div>
              </div>
            </div>

            <div class="hero-card marketing">
              <div class="hero-title">Итоговая маркетинговая цена</div>
              <div class="hero-price"><?= h(fmt_rub($marketingPrice > 0 ? $marketingPrice : null)) ?></div>
              <div class="hero-grid">
                <div class="hero-metric">
                  <div class="label">Доход, ₽</div>
                  <div class="value"><?= h(fmt_rub($marketingSnapshot['profit_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Доход к закупке</div>
                  <div class="value"><?= h(fmt_percent($marketingSnapshot['profit_on_cost_percent'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Суммарные расходы</div>
                  <div class="value"><?= h(fmt_rub($marketingSnapshot['total_costs_rub'] ?? null)) ?></div>
                </div>
                <div class="hero-metric">
                  <div class="label">Расчётный индекс</div>
                  <div class="value"><?= render_index_badge($marketingIndexLevel !== '' ? $marketingIndexLevel : '—') ?></div>
                </div>
              </div>
              <?php if ($marketingSource !== ''): ?>
                <div class="muted" style="margin-top:12px;">
                  Источник цены: <?= h($marketingSource) ?><?= $marketingMode !== '' ? ' · ' . h($marketingMode === 'action' ? 'через акцию' : 'через индекс цены') : '' ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (
            !empty($calc['breakdown']['price_index_benchmark'])
            || !empty($calc['breakdown']['price_index_metrics'])
            || !empty($calc['breakdown']['price_index_transition_enabled'])
          ): ?>
            <div class="section section-target" style="margin-top:16px;">
              <h3 style="margin-bottom:8px;">Индекс цены и переход для min price</h3>
              <div class="muted" style="margin-bottom:14px;">Здесь видно три источника индекса Ozon, общий уровень по ним и можно ли отдельным шагом дожать `min price` до следующего общего уровня.</div>
              <div class="stats">
                <div class="stat">
                  <div class="label">Базовая цена сравнения</div>
                  <div class="value"><?= h(fmt_rub($calc['breakdown']['price_index_benchmark'] ?? null)) ?></div>
                  <div class="muted"><?= h((string)($calc['breakdown']['price_index_benchmark_source'] ?? '—')) ?><?= !empty($calc['breakdown']['price_index_benchmark_method']) ? ' · ' . h((string)$calc['breakdown']['price_index_benchmark_method']) : '' ?></div>
                </div>
                <div class="stat">
                  <div class="label">Цена покупателя Ozon</div>
                  <div class="value"><?= h(fmt_rub($calc['breakdown']['price_index_reference_effective_price'] ?? null)) ?></div>
                  <div class="muted">рассчитана из индекса</div>
                </div>
                <div class="stat">
                  <div class="label">Скидка Ozon</div>
                  <div class="value"><?= h(fmt_percent($calc['breakdown']['price_index_discount_percent'] ?? null)) ?></div>
                  <div class="muted">коэффициент <?= h(fmt_plain_decimal($calc['breakdown']['price_index_discount_factor'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Расчётный уровень min price</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_recommended_min_price_before_index_level'] ?? '') ?: '—') ?></div>
                </div>
                <div class="stat">
                  <div class="label">Статус перехода</div>
                  <div class="value"><?= !empty($calc['breakdown']['price_index_transition_enabled']) ? (!empty($calc['breakdown']['price_index_transition_applied']) ? 'Применён' : 'Не применён') : 'Не применён' ?></div>
                  <div class="muted"><?= !empty($calc['breakdown']['price_index_transition_enabled']) ? 'Автоснижение включено.' : 'Автоснижение выключено, ниже показан только расчёт порога.' ?></div>
                </div>
                <div class="stat">
                  <div class="label">Следующий уровень</div>
                  <div class="value"><?= render_index_badge(($calc['breakdown']['price_index_next_level'] ?? '') ?: '—') ?></div>
                </div>
                <div class="stat">
                  <div class="label">Порог min price</div>
                  <div class="value"><?= h(fmt_rub($calc['breakdown']['price_index_threshold_price'] ?? null)) ?></div>
                  <div class="muted">покупатель: <?= h(fmt_rub($calc['breakdown']['price_index_threshold_effective_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Целевой min price</div>
                  <div class="value"><?= h(fmt_rub($calc['breakdown']['price_index_transition_target_price'] ?? null)) ?></div>
                  <div class="muted">покупатель: <?= h(fmt_rub($calc['breakdown']['price_index_transition_target_effective_price'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Снижение для перехода</div>
                  <div class="value"><?= h(fmt_percent($calc['breakdown']['price_index_transition_drop_percent'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Индекс по текущему min price</div>
                  <div class="value"><?= h(fmt_index_value($calc['breakdown']['price_index_current_min_price_value'] ?? null)) ?></div>
                  <div class="muted">покупатель: <?= h(fmt_rub($calc['breakdown']['price_index_current_min_price_effective'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Индекс по расчётному min price</div>
                  <div class="value"><?= h(fmt_index_value($calc['breakdown']['price_index_recommended_min_price_before_index_value'] ?? null)) ?></div>
                  <div class="muted">покупатель: <?= h(fmt_rub($calc['breakdown']['price_index_recommended_min_price_before_index_effective'] ?? null)) ?></div>
                </div>
                <div class="stat">
                  <div class="label">Min price до индекса</div>
                  <div class="value"><?= h(fmt_rub($calc['breakdown']['recommended_min_price_before_index_step'] ?? null)) ?></div>
                </div>
              </div>
              <?php $indexMetrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : []; ?>
              <?php if ($indexMetrics): ?>
                <div style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px;">
                  <?php foreach ($indexMetrics as $metric): ?>
                    <?php if (!is_array($metric)) continue; ?>
                    <div class="stat" style="min-height:auto;">
                      <div class="label"><?= h((string)($metric['label'] ?? $metric['source'] ?? 'Источник')) ?></div>
                      <div class="value"><?= h(fmt_rub($metric['comparison_price'] ?? null)) ?></div>
                      <div class="muted">индекс <?= h(fmt_index_value($metric['api_index_value'] ?? null)) ?> · <?= render_index_badge($metric['api_level_label'] ?? $metric['api_level'] ?? '—') ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php
            $desiredStateForPlan = $singleTestDesiredState ?? ozon_price_build_desired_state($calc, $promotionStrategy, $singleTestPromoRows, (int)($currentFeed['connection_id'] ?? 0), $cfg);
            $strategyFboAdjustment = price_tool_fbo_adjustment($desiredStateForPlan);
          ?>
          <div class="strategy-card">
            <h3>Итоговый план цены и продвижения</h3>
            <div class="muted">Здесь сервис уже сводит экономику, индекс цены и акции в один итог: что передавать в обычную цену, что в `min price` и нужно ли добавлять товар в акцию.</div>
            <?php if (is_array($strategyFboAdjustment)): ?>
              <div class="strategy-note">Ниже показан обычный расчёт Price Tool без FBO-правила. Итоговая цена для выгрузки будет снижена правилом FBO в блоке подготовки выгрузки.</div>
            <?php endif; ?>
            <div class="strategy-grid">
              <div class="strategy-kv">
                <div class="label">Обычная цена для отправки</div>
                <div class="value"><?= h(fmt_rub($promotionStrategy['final_price'] ?? null)) ?></div>
              </div>
              <div class="strategy-kv">
                <div class="label">Min price для отправки</div>
                <div class="value"><?= h(fmt_rub($promotionStrategy['final_min_price'] ?? null)) ?></div>
              </div>
              <div class="strategy-kv">
                <div class="label">Режим продвижения</div>
                <div class="value" style="font-size:22px;">
                  <?php if (($promotionStrategy['mode'] ?? '') === 'action'): ?>
                    Через акцию
                  <?php elseif (($promotionStrategy['mode'] ?? '') === 'index'): ?>
                    Через индекс цены
                  <?php else: ?>
                    Экономическая цена
                  <?php endif; ?>
                </div>
              </div>
              <div class="strategy-kv">
                <div class="label">Нижняя допустимая граница</div>
                <div class="value" style="font-size:22px;"><?= h(fmt_rub(($promotionStrategy['base_min_price'] ?? 0) > 0 ? (($promotionStrategy['base_min_price'] ?? 0) * 0.95) : null)) ?></div>
              </div>
            </div>
            <div class="strategy-note"><?= h((string)($promotionStrategy['reason'] ?? '')) ?></div>

            <?php if (($promotionStrategy['mode'] ?? '') === 'action' && !empty($promotionStrategy['actions'])): ?>
              <div class="strategy-action-list">
                <?php foreach ((array)$promotionStrategy['actions'] as $actionPlan): ?>
                  <div class="strategy-action-item<?= !empty($actionPlan['preferred']) ? ' preferred' : '' ?>">
                    <div class="strategy-action-top">
                      <div>
                        <div style="font-weight:800; font-size:18px;"><?= h((string)($actionPlan['title'] ?? 'Акция Ozon')) ?></div>
                        <div class="muted" style="margin-top:4px;"><?= h((string)($actionPlan['reason'] ?? '')) ?></div>
                      </div>
                      <?php if (!empty($actionPlan['preferred'])): ?>
                        <span class="strategy-badge">Основной вариант</span>
                      <?php endif; ?>
                    </div>
                    <div class="stats" style="margin-bottom:0;">
                      <div class="stat">
                        <div class="label">Цена для акции</div>
                        <div class="value"><?= h(fmt_rub($actionPlan['recommended_action_price'] ?? null)) ?></div>
                      </div>
                      <div class="stat">
                        <div class="label">Откуда взяли цену</div>
                        <div class="value" style="font-size:20px;"><?= h((string)($actionPlan['recommended_action_source'] ?? '—')) ?></div>
                      </div>
                      <div class="stat">
                        <div class="label">Индекс в акции</div>
                        <div class="value"><?= render_index_badge((string)($actionPlan['recommended_action_index_level'] ?? '—')) ?></div>
                      </div>
                      <div class="stat">
                        <div class="label">Бустинг акции</div>
                        <div class="value" style="font-size:20px;"><?= h(fmt_percent($actionPlan['recommended_action_boost'] ?? null)) ?></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php elseif (($promotionStrategy['mode'] ?? '') === 'index' && !empty($promotionStrategy['index_strategy'])): ?>
              <?php $indexStrategy = (array)$promotionStrategy['index_strategy']; ?>
              <div class="strategy-action-list">
                <div class="strategy-action-item preferred">
                  <div class="strategy-action-top">
                    <div>
                      <div style="font-weight:800; font-size:18px;">Итоговая обычная цена через индекс</div>
                      <div class="muted" style="margin-top:4px;"><?= h((string)($indexStrategy['reason'] ?? '')) ?></div>
                    </div>
                    <span class="strategy-badge">Без акции</span>
                  </div>
                  <div class="stats" style="margin-bottom:0;">
                    <div class="stat">
                      <div class="label">Цена для отправки</div>
                      <div class="value"><?= h(fmt_rub($indexStrategy['final_price'] ?? null)) ?></div>
                    </div>
                    <div class="stat">
                      <div class="label">Уровень индекса</div>
                      <div class="value"><?= render_index_badge((string)(($indexStrategy['best_candidate']['index']['label'] ?? '') ?: '—')) ?></div>
                    </div>
                    <div class="stat">
                      <div class="label">Числовой индекс</div>
                      <div class="value" style="font-size:20px;"><?= h(fmt_index_value($indexStrategy['best_candidate']['index']['value'] ?? null)) ?></div>
                    </div>
                    <div class="stat">
                      <div class="label">Цена сравнения</div>
                      <div class="value" style="font-size:20px;"><?= h(fmt_rub($indexStrategy['comparison_price'] ?? null)) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <?php
            $desiredState = $desiredStateForPlan;
            $actionSummary = (array)($desiredState['summary'] ?? []);
            $fboAdjustment = price_tool_fbo_adjustment($desiredState);
          ?>
          <div class="section section-promo" style="margin-top:16px;">
            <h3>Подготовка выгрузки в Ozon</h3>
            <div class="muted" style="margin-bottom:14px;">Ниже сервис показывает именно тот `diff`, который будет отправлен в Ozon при ручной или массовой выгрузке: обычная цена, `min price`, акции для добавления или обновления и акции для удаления.</div>
            <?= render_fbo_adjustment_card($desiredState) ?>
            <div class="stats">
              <div class="stat">
                <div class="label">Обычная цена в Ozon</div>
                <div class="value"><?= h(fmt_rub($desiredState['regular_price'] ?? null)) ?></div>
                <div class="muted">
                  <?= !empty($desiredState['price_changed']) ? 'Будет обновлена' : 'Совпадает с текущей' ?>
                  <?php if (is_array($fboAdjustment)): ?>
                    <br>обычный расчёт: <?= h(fmt_rub($fboAdjustment['regular_price_before'] ?? null)) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="stat">
                <div class="label">Min price в Ozon</div>
                <div class="value"><?= h(fmt_rub($desiredState['min_price'] ?? null)) ?></div>
                <div class="muted">
                  <?= !empty($desiredState['min_price_changed']) ? 'Будет обновлён' : 'Совпадает с текущим' ?>
                  <?php if (is_array($fboAdjustment)): ?>
                    <br>обычный расчёт: <?= h(fmt_rub($fboAdjustment['min_price_before'] ?? null)) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="stat">
                <div class="label">Добавить / обновить в акциях</div>
                <div class="value"><?= h((string)((int)($actionSummary['actions_add_count'] ?? 0) + (int)($actionSummary['actions_update_count'] ?? 0))) ?></div>
                <div class="muted"><?= h(((int)($actionSummary['actions_add_count'] ?? 0)) . ' новых, ' . ((int)($actionSummary['actions_update_count'] ?? 0)) . ' обновить') ?></div>
              </div>
              <div class="stat">
                <div class="label">Удалить из акций</div>
                <div class="value"><?= h((string)(int)($actionSummary['actions_remove_count'] ?? 0)) ?></div>
                <div class="muted">Текущие акции, которые не входят в выбранную стратегию.</div>
              </div>
              <div class="stat">
                <div class="label"><?= is_array($fboAdjustment) ? 'FBO-правило' : 'Глобальное форсирование' ?></div>
                <div class="value" style="font-size:20px;"><?= !empty($desiredState['force_rule']['label']) ? h((string)$desiredState['force_rule']['label']) : '—' ?></div>
                <div class="muted"><?= !empty($desiredState['force_rule']['source_line']) ? h((string)$desiredState['force_rule']['source_line']) : 'Нет FBO-правила или глобального правила для артикула, категории или бренда.' ?></div>
              </div>
            </div>

            <div class="promo-list" style="margin-top:14px;">
              <?php if (!empty($desiredState['actions_upsert'])): ?>
                <?php foreach ((array)$desiredState['actions_upsert'] as $actionRow): ?>
                  <div class="promo-item">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                      <div>
                        <div style="font-weight:700;"><?= h((string)($actionRow['title'] ?? 'Акция Ozon')) ?></div>
                        <div class="muted" style="margin-top:4px;"><?= (($actionRow['change_type'] ?? '') === 'update') ? 'Обновим цену участия' : 'Добавим товар в акцию' ?></div>
                      </div>
                      <div class="muted">action_id <?= h((string)($actionRow['action_id'] ?? '')) ?></div>
                    </div>
                    <div class="promo-grid">
                      <div class="promo-kv">
                        <div class="label">Цена для акции</div>
                        <div class="value"><?= h(fmt_rub($actionRow['action_price'] ?? null)) ?></div>
                        <?php if (is_array($fboAdjustment) && isset($actionRow['action_price_before_force'])): ?>
                          <div class="muted" style="margin-top:4px;">обычный расчёт: <?= h(fmt_rub($actionRow['action_price_before_force'] ?? null)) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Источник</div>
                        <div class="value" style="font-size:18px;"><?= h((string)($actionRow['source'] ?? '—')) ?></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Бустинг</div>
                        <div class="value" style="font-size:18px;"><?= h(fmt_percent($actionRow['boost'] ?? null)) ?></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Индекс в акции</div>
                        <div class="value"><?= render_index_badge((string)($actionRow['index_level'] ?? '—')) ?></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <?php if (!empty($desiredState['actions_remove'])): ?>
                <?php foreach ((array)$desiredState['actions_remove'] as $actionRow): ?>
                  <div class="promo-item" style="border-color:#fecaca; background:#fff7f7;">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                      <div>
                        <div style="font-weight:700;"><?= h((string)($actionRow['title'] ?? 'Акция Ozon')) ?></div>
                        <div class="muted" style="margin-top:4px;">
                          <?= h((string)($actionRow['remove_reason'] ?? 'Удалим товар из акции, потому что текущая цена участия ниже допустимой.')) ?>
                        </div>
                        <?php if (!empty($actionRow['current_action_price']) || !empty($actionRow['allowed_floor_price'])): ?>
                          <div class="muted" style="margin-top:4px;">
                            Текущая цена в акции: <?= h(fmt_rub($actionRow['current_action_price'] ?? null)) ?>
                            · Нижняя допустимая граница: <?= h(fmt_rub($actionRow['allowed_floor_price'] ?? null)) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="muted">action_id <?= h((string)($actionRow['action_id'] ?? '')) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <?php if (empty($desiredState['actions_upsert']) && empty($desiredState['actions_remove'])): ?>
                <div class="promo-item">
                  <div class="muted">По акциям ничего менять не нужно: итоговая стратегия уже совпадает с текущим состоянием товара.</div>
                </div>
              <?php endif; ?>
            </div>

            <div class="muted" style="margin-top:14px;">Этот блок только показывает, что именно сервис подготовил для отправки. Само применение выполняется через блок `Ручная выгрузка цен в Ozon` ниже или через массовое обновление фидов на общей странице.</div>
          </div>

          <?php
            $renderTraceComparison = static function (array $snapshots, string $title): void {
              $normalized = [];
              foreach ($snapshots as $snapshot) {
                if (!is_array($snapshot['snapshot'] ?? null) || empty($snapshot['snapshot']['trace']['rows'])) {
                  continue;
                }
                $normalized[] = $snapshot;
              }
              if (!$normalized) {
                echo '<div class="trace-card"><h3>' . h($title) . '</h3><div style="padding:14px 16px;" class="muted">Нет данных для подробной расшифровки.</div></div>';
                return;
              }

              $baseTrace = $normalized[0]['snapshot']['trace'];
              $baseRows = $baseTrace['rows'] ?? [];
              echo '<div class="trace-card">';
              echo '<h3>' . h($title) . '</h3>';
              echo '<div class="table-wrap" style="max-height:none; border:none; border-radius:0; padding:0 0 8px;">';
              echo '<table>';
              echo '<thead>';
              echo '<tr>';
              echo '<th rowspan="2">Этап</th><th rowspan="2">Что добавлено</th><th rowspan="2">Комментарий</th>';
              foreach ($normalized as $item) {
                echo '<th colspan="2">' . h((string)$item['label']) . '</th>';
              }
              echo '</tr>';
              echo '<tr>';
              foreach ($normalized as $item) {
                echo '<th>%</th><th>+ ₽</th>';
              }
              echo '</tr>';
              echo '</thead><tbody>';

              foreach ($baseRows as $idx => $step) {
                echo '<tr>';
                echo '<td class="trace-stage">' . h((string)($step['stage'] ?? '')) . '</td>';
                echo '<td>' . h((string)($step['label'] ?? '')) . '</td>';
                echo '<td>' . h((string)($step['note'] ?? '')) . '</td>';
                foreach ($normalized as $item) {
                  $row = $item['snapshot']['trace']['rows'][$idx] ?? null;
                  echo '<td>' . h(($row && array_key_exists('rate_percent', $row) && $row['rate_percent'] !== null) ? fmt_percent($row['rate_percent']) : '—') . '</td>';
                  echo '<td>' . h($row ? fmt_rub($row['amount_rub'] ?? null) : '—') . '</td>';
                }
                echo '</tr>';
              }

              echo '</tbody><tfoot>';
              $summaryRows = [
                'Цена продажи' => ['key' => 'sale_price_rub', 'formatter' => 'fmt_rub'],
                'Все расходы' => ['key' => 'total_costs_rub', 'formatter' => 'fmt_rub'],
                'Доход, ₽' => ['key' => 'profit_rub', 'formatter' => 'fmt_rub'],
                'Доход к закупке' => ['key' => 'profit_on_cost_percent', 'formatter' => 'fmt_percent'],
              ];
              foreach ($summaryRows as $summaryLabel => $meta) {
                echo '<tr>';
                  echo '<td colspan="3" style="font-weight:700; background:#f8fafc;">' . h($summaryLabel) . '</td>';
                foreach ($normalized as $item) {
                  $trace = $item['snapshot']['trace'] ?? [];
                  $value = $trace[$meta['key']] ?? null;
                  $formatted = $meta['formatter']($value);
                  echo '<td colspan="2" style="font-weight:700; background:#f8fafc;">' . h($formatted) . '</td>';
                }
                echo '</tr>';
              }
              echo '</tfoot></table></div></div>';
            };
          ?>

          <div class="trace-layout">
            <?php $renderTraceComparison([
              ['label' => 'Расчётная обычная цена', 'snapshot' => $recommendedSnapshot],
              ['label' => 'Расчётная минимальная цена', 'snapshot' => $recommendedMinSnapshot],
              ['label' => 'Итоговая маркетинговая цена', 'snapshot' => $marketingSnapshot],
            ], 'Таблица расчёта по итоговым ценам'); ?>
          </div>

          <?php if (!empty($calc['warnings'])): ?>
            <div class="error" style="margin-top:16px;">
              <b>Предупреждения по товару:</b>
              <ul class="warning-list" style="margin-top:8px; color:#991b1b;">
                <?php foreach ($calc['warnings'] as $warning): ?>
                  <li><?= h((string)$warning) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="section section-promo" style="margin-top:16px;">
            <h3>Акции Ozon по этому товару из локальной БД</h3>
            <div class="muted">Ниже показывается уже сохранённая локальная выборка по акциям. Если данных мало или они устарели, сначала запусти синхронизацию акций выше.</div>
            <?php if (!$singleTestPromoRows): ?>
              <p class="muted" style="margin-top:12px;">Для этого товара в локальной БД пока нет записей по акциям.</p>
            <?php else: ?>
              <div class="promo-list">
                <?php foreach ($singleTestPromoRows as $promoRow): ?>
                  <div class="promo-item">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                      <div>
                        <div style="font-weight:700;"><?= h((string)($promoRow['title'] ?? 'Акция Ozon')) ?></div>
                        <div class="muted" style="margin-top:4px;">
                          <?= ((string)($promoRow['source_type'] ?? '') === 'participating') ? 'Уже участвует' : 'Может участвовать' ?>
                          · action_id <?= h((string)($promoRow['action_id'] ?? '')) ?>
                        </div>
                      </div>
                      <div class="muted"><?= h((string)($promoRow['synced_at'] ?? '')) ?></div>
                    </div>
                    <div class="promo-grid">
                      <div class="promo-kv">
                        <div class="label">Обычная цена</div>
                        <div class="value"><?= h(fmt_rub($promoRow['price'] ?? null)) ?></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Цена в акции</div>
                        <div class="value"><?= h(fmt_rub($promoRow['action_price'] ?? null)) ?></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Макс. цена входа</div>
                        <div class="value"><?= h(fmt_rub($promoRow['max_action_price'] ?? null)) ?></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Мин. буст / цена</div>
                        <div class="value" style="font-size:18px;"><?= h(fmt_percent($promoRow['min_boost'] ?? null)) ?><br><span style="font-size:24px;"><?= h(fmt_rub($promoRow['price_min_elastic'] ?? null)) ?></span></div>
                      </div>
                      <div class="promo-kv">
                        <div class="label">Макс. буст / цена</div>
                        <div class="value" style="font-size:18px;"><?= h(fmt_percent($promoRow['max_boost'] ?? null)) ?><br><span style="font-size:24px;"><?= h(fmt_rub($promoRow['price_max_elastic'] ?? null)) ?></span></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
        </div>
      </details>

      <details class="workspace-block" data-storage-key="export" open>
        <summary>
          <div class="workspace-block-title-wrap">
            <div class="workspace-block-title">Выгрузка</div>
            <div class="workspace-block-subtitle">Здесь живут инструменты отправки в Ozon: ручная выгрузка списком, предпросмотр всего фида и журнал последних отправок.</div>
          </div>
        </summary>
        <div class="workspace-block-body">
      <div class="card">
        <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
          <div>
            <h2 style="margin:0 0 8px;">Ручная выгрузка цен в Ozon</h2>
            <div class="muted">Вставь список артикулов, и сервис подготовит только по ним ручную выгрузку: какие `price` и `min_price` отправим, в какие акции добавим товар и из каких акций уберём.</div>
          </div>
        </div>

        <form method="post" class="settings-form" style="margin-top:14px;">
          <input type="hidden" name="feed_id" value="<?= h((string)($currentFeed['id'] ?? 0)) ?>">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <div class="field">
            <label for="push_offer_ids">Список артикулов для выгрузки</label>
            <textarea id="push_offer_ids" name="push_offer_ids" rows="5" placeholder="Один offer_id на строку, например:&#10;00632__22&#10;107862__16"><?= h($pushOfferIdsRaw) ?></textarea>
            <div class="field-help">Можно вставлять по одному артикулу на строку, а также через запятую или `;`.</div>
          </div>
          <div class="actions">
            <button type="submit" name="action" value="build_batch_push_preview">Подготовить выгрузку</button>
            <?php if ($batchPushPreview !== null): ?>
              <button type="submit" name="action" value="apply_batch_offer_list">Отправить список в Ozon</button>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($batchPushPreview !== null): ?>
          <div class="stats" style="margin-top:16px;">
            <div class="stat">
              <div class="label">Запрошено артикулов</div>
              <div class="value"><?= h((string)count((array)($batchPushPreview['requested_ids'] ?? []))) ?></div>
            </div>
            <div class="stat">
              <div class="label">Подготовлено к выгрузке</div>
              <div class="value"><?= h((string)count((array)($batchPushPreview['rows'] ?? []))) ?></div>
            </div>
            <div class="stat">
              <div class="label">Не найдены в XML</div>
              <div class="value"><?= h((string)count((array)($batchPushPreview['missing_ids'] ?? []))) ?></div>
            </div>
          </div>

          <?php if (!empty($batchPushPreview['missing_ids'])): ?>
            <div class="error" style="margin-top:12px;">
              <b>Не найдены в XML:</b>
              <div style="margin-top:8px;"><?= h(implode(', ', (array)$batchPushPreview['missing_ids'])) ?></div>
            </div>
          <?php endif; ?>

          <div class="table-wrap" style="margin-top:14px;">
            <table>
              <thead>
                <tr>
                  <th>offer_id</th>
                  <th>Обычная цена</th>
                  <th>Min price</th>
                  <th>Режим</th>
                  <th>Акции +/−</th>
                  <th>Что отправим</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ((array)($batchPushPreview['rows'] ?? []) as $batchRow): ?>
                  <?php
                    $desired = (array)($batchRow['desired_state'] ?? []);
                    $summary = (array)($desired['summary'] ?? []);
                    $batchFboAdjustment = price_tool_fbo_adjustment($desired);
                  ?>
                  <tr>
                    <td><code><?= h((string)($desired['offer_id'] ?? '')) ?></code></td>
                    <td>
                      <?= h(fmt_rub($desired['regular_price'] ?? null)) ?>
                      <?php if (is_array($batchFboAdjustment)): ?>
                        <div class="inline-note">FBO: обычный расчёт <?= h(fmt_rub($batchFboAdjustment['regular_price_before'] ?? null)) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= h(fmt_rub($desired['min_price'] ?? null)) ?>
                      <?php if (is_array($batchFboAdjustment)): ?>
                        <div class="inline-note">FBO: обычный расчёт <?= h(fmt_rub($batchFboAdjustment['min_price_before'] ?? null)) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= h((string)($desired['mode'] ?? 'base')) ?></td>
                    <td><?= h('+' . ((int)($summary['actions_add_count'] ?? 0) + (int)($summary['actions_update_count'] ?? 0)) . ', -' . (int)($summary['actions_remove_count'] ?? 0)) ?></td>
                    <td>
                      <details>
                        <summary>Показать</summary>
                        <div class="muted" style="margin-top:8px; line-height:1.55;">
                          <?= render_fbo_adjustment_card($desired) ?>
                          price: <?= h(fmt_rub($desired['regular_price'] ?? null)) ?><br>
                          min_price: <?= h(fmt_rub($desired['min_price'] ?? null)) ?><br>
                          <?php if (!empty($desired['force_rule']['label'])): ?>
                            <?= is_array($batchFboAdjustment) ? 'FBO-правило' : 'Глобальное форсирование' ?>: <?= h((string)$desired['force_rule']['source_line']) ?><br>
                          <?php endif; ?>
                          <?php if (!empty($desired['actions_upsert'])): ?>
                            Добавить / обновить акции:<br>
                            <?php foreach ((array)$desired['actions_upsert'] as $actionRow): ?>
                              • <?= h((string)($actionRow['title'] ?? 'Акция')) ?> — <?= h(fmt_rub($actionRow['action_price'] ?? null)) ?>
                              <?php if (is_array($batchFboAdjustment) && isset($actionRow['action_price_before_force'])): ?>
                                <span class="inline-note">обычный расчёт <?= h(fmt_rub($actionRow['action_price_before_force'] ?? null)) ?></span>
                              <?php endif; ?>
                              <br>
                            <?php endforeach; ?>
                          <?php endif; ?>
                          <?php if (!empty($desired['actions_remove'])): ?>
                            Удалить из акций:<br>
                            <?php foreach ((array)$desired['actions_remove'] as $actionRow): ?>
                              • <?= h((string)($actionRow['title'] ?? 'Акция')) ?><br>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </div>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if (is_array($batchPushApplyReport)): ?>
            <div class="stats" style="margin-top:14px;">
              <div class="stat">
                <div class="label">Отправлено товаров</div>
                <div class="value"><?= h((string)count((array)($batchPushApplyReport['rows'] ?? []))) ?></div>
              </div>
              <div class="stat">
                <div class="label">Ошибки</div>
                <div class="value"><?= h((string)count((array)($batchPushApplyReport['errors'] ?? []))) ?></div>
              </div>
            </div>
            <?php if (!empty($batchPushApplyReport['errors'])): ?>
              <div class="error" style="margin-top:12px;">
                <b>Ошибки пакетной отправки:</b>
                <ul class="warning-list" style="margin-top:8px; color:#991b1b;">
                  <?php foreach ((array)$batchPushApplyReport['errors'] as $applyError): ?>
                    <li><?= h((string)$applyError) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
          <div>
            <h2 style="margin:0 0 8px;">Предпросмотр расчёта</h2>
            <div class="muted">Сервис скачает XML по ссылке, возьмёт закупку из указанного тега, подтянет тарифы Ozon API и рассчитает обычную цену и min price. Пока без обновления в Ozon.</div>
          </div>
          <?php if (!empty($currentFeed['id'])): ?>
            <form method="post" data-preview-status-form>
              <input type="hidden" name="action" value="preview_feed">
              <input type="hidden" name="feed_id" value="<?= h((string)$currentFeed['id']) ?>">
              <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
              <button type="submit">Пересчитать по этому фиду</button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($preview !== null): ?>
          <div class="stats" style="margin-top:16px;">
            <div class="stat">
              <div class="label">Товаров в XML</div>
              <div class="value"><?= h((string)$preview['stats']['offers_total']) ?></div>
            </div>
            <div class="stat">
              <div class="label">Рассчитано</div>
              <div class="value"><?= h((string)$preview['stats']['calc_ok']) ?></div>
            </div>
            <div class="stat">
              <div class="label">С предупреждениями</div>
              <div class="value"><?= h((string)$preview['stats']['warnings']) ?></div>
            </div>
            <div class="stat">
              <div class="label">Ошибки</div>
              <div class="value"><?= h((string)$preview['stats']['errors']) ?></div>
            </div>
            <div class="stat">
              <div class="label">Размер XML</div>
              <div class="value"><?= h($previewMeta['download_bytes'] !== null ? fmt_bytes_simple((int)$previewMeta['download_bytes']) : '—') ?></div>
            </div>
          </div>

          <?php if ($previewMeta['final_url'] !== ''): ?>
            <div class="muted" style="margin-bottom:12px;">Источник XML: <code><?= h($previewMeta['final_url']) ?></code></div>
          <?php endif; ?>

          <div class="preview-toolbar">
            <div class="field">
              <label for="previewSearch">Поиск по offer_id или названию</label>
              <input id="previewSearch" type="text" placeholder="Например: 00616 или аккумулятор">
            </div>
            <div class="field">
              <label for="previewStatus">Показывать строки</label>
              <select id="previewStatus">
                <option value="all">Все</option>
                <option value="warn">Только с предупреждениями</option>
                <option value="ok">Только без предупреждений</option>
              </select>
            </div>
            <div class="field">
              <label for="previewSort">Сортировка</label>
              <select id="previewSort">
                <option value="offer_id">По offer_id</option>
                <option value="delta_price_desc">По росту цены вниз</option>
                <option value="delta_price_asc">По снижению цены вниз</option>
                <option value="recommended_price_desc">По рекомендованной цене ↓</option>
                <option value="recommended_price_asc">По рекомендованной цене ↑</option>
                <option value="purchase_cost_desc">По закупке ↓</option>
                <option value="purchase_cost_asc">По закупке ↑</option>
              </select>
            </div>
            <div class="field">
              <label for="previewLimit">Показывать строк</label>
              <select id="previewLimit">
                <option value="50">50</option>
                <option value="100" selected>100</option>
                <option value="250">250</option>
                <option value="500">500</option>
                <option value="all">Все</option>
              </select>
            </div>
            <div class="field">
              <label>&nbsp;</label>
              <div class="muted" id="previewCounter">Показано: <?= h((string)count($preview['rows'])) ?> из <?= h((string)count($preview['rows'])) ?></div>
            </div>
          </div>

          <div class="table-wrap">
            <table id="previewTable">
              <thead>
                <tr>
                  <th>offer_id</th>
                  <th>Название</th>
                  <th>product_id</th>
                  <th>Закупка</th>
                  <th>Текущая цена Ozon</th>
                  <th>Текущий min_price</th>
                  <th>Marketing seller price</th>
                  <th>Рекоменд. цена</th>
                  <th>Рекоменд. min_price</th>
                  <th>Δ к текущей цене</th>
                  <th>Δ к текущему min_price</th>
                  <th>Зачёркнутая цена</th>
                  <th>Комиссия</th>
                  <th>Эквайринг, ₽</th>
                  <th>Эквайринг</th>
                  <th>Индекс цены</th>
                  <th>Объёмный вес</th>
                    <th>База до % расходов</th>
                  <th>Статус</th>
                  <th>Предупреждения</th>
                  <th>Расшифровка</th>
                </tr>
              </thead>
              <tbody id="previewTableBody">
                <?php foreach ($preview['rows'] as $row): ?>
                  <?php
                    $deltaPrice = ($row['recommended_price'] !== null && $row['ozon_price'] !== null)
                      ? ((float)$row['recommended_price'] - (float)$row['ozon_price'])
                      : null;
                    $deltaMinPrice = ($row['recommended_min_price'] !== null && $row['ozon_min_price'] !== null)
                      ? ((float)$row['recommended_min_price'] - (float)$row['ozon_min_price'])
                      : null;
                    $searchHaystack = mb_strtolower(trim((string)$row['offer_id'] . ' ' . (string)$row['name']));
                  ?>
                  <tr
                    data-search="<?= h($searchHaystack) ?>"
                    data-status="<?= h(!empty($row['warnings']) ? 'warn' : 'ok') ?>"
                    data-offer-id="<?= h((string)$row['offer_id']) ?>"
                    data-purchase-cost="<?= h((string)($row['purchase_cost'] ?? '')) ?>"
                    data-recommended-price="<?= h((string)($row['recommended_price'] ?? '')) ?>"
                    data-delta-price="<?= h((string)($deltaPrice ?? '')) ?>"
                  >
                    <td><code><?= h((string)$row['offer_id']) ?></code></td>
                    <td><?= h((string)$row['name']) ?></td>
                    <td><?= h((string)($row['product_id'] ?? '—')) ?></td>
                    <td><?= h(fmt_rub($row['purchase_cost'])) ?></td>
                    <td><?= h(fmt_rub($row['ozon_price'])) ?></td>
                    <td><?= h(fmt_rub($row['ozon_min_price'])) ?></td>
                    <td><?= h(fmt_rub($row['marketing_seller_price'] ?? null)) ?></td>
                    <td><?= h(fmt_rub($row['recommended_price'])) ?></td>
                    <td><?= h(fmt_rub($row['recommended_min_price'])) ?></td>
                    <td><?= h($deltaPrice !== null ? fmt_rub($deltaPrice) : '—') ?></td>
                    <td><?= h($deltaMinPrice !== null ? fmt_rub($deltaMinPrice) : '—') ?></td>
                    <td><?= h(fmt_rub($row['strike_price'] ?? null)) ?></td>
                    <td><?= h(fmt_percent($row['commission_percent'])) ?></td>
                    <td><?= h(fmt_rub($row['acquiring_rub'] ?? null)) ?></td>
                    <td><?= h(fmt_percent($row['acquiring_rate_percent'])) ?></td>
                    <td class="table-index-badge"><?= render_index_badge(($row['breakdown']['price_index_current_price_level'] ?? '') ?: ($row['color_index'] ?: '—')) ?></td>
                    <td><?= h($row['volume_weight'] !== null ? fmt_plain_decimal($row['volume_weight']) : '—') ?></td>
                    <td><?= h(fmt_rub($row['fixed_costs_rub'])) ?></td>
                    <td class="<?= ($row['status'] === 'ok') ? 'status-ok' : 'status-warn' ?>">
                      <?= h($row['status'] === 'ok' ? 'ok' : 'warning') ?>
                    </td>
                    <td>
                      <?php if (!empty($row['warnings'])): ?>
                        <ul class="warning-list">
                          <?php foreach ($row['warnings'] as $warning): ?>
                            <li><?= h((string)$warning) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($row['breakdown'])): ?>
                        <details>
                          <summary>Показать</summary>
                          <div class="muted" style="margin-top:8px; line-height:1.55;">
                            Закупка: <?= h(fmt_rub($row['breakdown']['purchase_cost'] ?? null)) ?><br>
                            Доставка до клиента: <?= h(fmt_rub($row['breakdown']['delivery_to_customer'] ?? null)) ?>
                            <?php if (!empty($row['breakdown']['delivery_to_customer_rate_percent'])): ?>
                              (<?= h(fmt_percent($row['breakdown']['delivery_to_customer_rate_percent'])) ?>)
                            <?php endif; ?><br>
                            <span class="muted"><?= h((string)($row['breakdown']['delivery_to_customer_note'] ?? '')) ?></span><br>
                            Магистраль: <?= h(fmt_rub($row['breakdown']['direct_flow'] ?? null)) ?>
                            <?php if (!empty($row['breakdown']['direct_flow_rate_percent'])): ?>
                              (<?= h(fmt_percent($row['breakdown']['direct_flow_rate_percent'])) ?>)
                            <?php endif; ?><br>
                            <span class="muted"><?= h((string)($row['breakdown']['direct_flow_note'] ?? '')) ?></span><br>
                            Диапазон магистрали из API: min <?= h(fmt_rub($row['breakdown']['direct_flow_min'] ?? null)) ?> / max <?= h(fmt_rub($row['breakdown']['direct_flow_max_current'] ?? null)) ?><br>
                            Первая миля: <?= h(fmt_rub($row['breakdown']['first_mile'] ?? null)) ?><br>
                            Тариф возврата Ozon: <?= h(fmt_rub($row['breakdown']['return_flow_tariff'] ?? null)) ?><br>
                            Обработка невыкупа Ozon: <?= h(fmt_rub($row['breakdown']['nonbuyout_processing_rub'] ?? null)) ?><br>
                            Обработка возврата Ozon: <?= h(fmt_rub($row['breakdown']['return_processing_rub'] ?? null)) ?><br>
                            Упаковка на складе, %: <?= h(fmt_percent($row['breakdown']['packaging_percent'] ?? null)) ?><br>
                            Упаковка на складе, ₽: <?= h(fmt_rub($row['breakdown']['packaging_percent_rub'] ?? null)) ?><br>
                            Успешные продажи после потерь: <?= h(fmt_percent($row['breakdown']['kept_percent'] ?? null)) ?><br>
                            Невыкупы: коэффициент распределения <?= h(fmt_percent($row['breakdown']['nonbuyout_factor_percent'] ?? null)) ?><br>
                            Невыкуп: фиксированные расходы одного случая <?= h(fmt_rub($row['breakdown']['nonbuyout_base_one_order_cost'] ?? null)) ?><br>
                            <?php if ((float)($row['breakdown']['nonbuyout_variable_cost'] ?? 0) > 0): ?>
                              Невыкуп: ценозависимая часть <?= h(fmt_rub($row['breakdown']['nonbuyout_variable_cost'] ?? null)) ?>
                              (<?= h(fmt_percent($row['breakdown']['nonbuyout_variable_rate_percent'] ?? null)) ?>)<br>
                            <?php endif; ?>
                            <?php if (abs((float)($row['breakdown']['nonbuyout_resale_delta_cost'] ?? 0)) > 0.0001): ?>
                              Невыкуп: дельта FBO против текущей схемы <?= h(fmt_rub($row['breakdown']['nonbuyout_resale_delta_cost'] ?? null)) ?><br>
                            <?php endif; ?>
                            Невыкуп: стоимость одного случая <?= h(fmt_rub($row['breakdown']['nonbuyout_one_order_cost'] ?? null)) ?><br>
                            Невыкупы суммарно: <?= h(fmt_rub($row['breakdown']['nonbuyout_cost'] ?? null)) ?><br>
                            Возвраты: коэффициент распределения <?= h(fmt_percent($row['breakdown']['return_resellable_factor_percent'] ?? null)) ?><br>
                            Возвраты: логистика и обработка <?= h(fmt_rub($row['breakdown']['return_resellable_logistics_cost'] ?? null)) ?><br>
                            <?php if ((float)($row['breakdown']['return_resellable_variable_cost'] ?? 0) > 0): ?>
                              Возвраты: ценозависимая часть <?= h(fmt_rub($row['breakdown']['return_resellable_variable_cost'] ?? null)) ?>
                              (<?= h(fmt_percent($row['breakdown']['return_resellable_variable_rate_percent'] ?? null)) ?>)<br>
                            <?php endif; ?>
                            <?php if (abs((float)($row['breakdown']['return_resellable_resale_delta_cost'] ?? 0)) > 0.0001): ?>
                              Возвраты: дельта FBO против текущей схемы <?= h(fmt_rub($row['breakdown']['return_resellable_resale_delta_cost'] ?? null)) ?><br>
                            <?php endif; ?>
                            Возвраты суммарно: <?= h(fmt_rub($row['breakdown']['return_resellable_cost'] ?? null)) ?><br>
                            Безвозвратные потери: коэффициент распределения <?= h(fmt_percent($row['breakdown']['return_nonresellable_factor_percent'] ?? null)) ?><br>
                            Безвозвратные потери: логистика и обработка <?= h(fmt_rub($row['breakdown']['return_nonresellable_logistics_cost'] ?? null)) ?><br>
                            <?php if ((float)($row['breakdown']['return_nonresellable_variable_cost'] ?? 0) > 0): ?>
                              Безвозвратные потери: ценозависимая часть <?= h(fmt_rub($row['breakdown']['return_nonresellable_variable_cost'] ?? null)) ?>
                              (<?= h(fmt_percent($row['breakdown']['return_nonresellable_variable_rate_percent'] ?? null)) ?>)<br>
                            <?php endif; ?>
                            Безвозвратные потери: потери в закупке <?= h(fmt_rub($row['breakdown']['return_nonresellable_purchase_loss'] ?? null)) ?><br>
                            Потери суммарно: <?= h(fmt_rub($row['breakdown']['issue_cost'] ?? null)) ?><br>
                            Фикс. настройки: <?= h(fmt_rub($row['breakdown']['settings_fixed'] ?? null)) ?><br>
                            Комиссия: <?= h(fmt_percent($row['breakdown']['commission_percent'] ?? null)) ?><br>
                            Базовая комиссия, ₽: <?= h(fmt_rub($row['breakdown']['commission_base_rub'] ?? null)) ?><br>
                            Поправка за скорость отгрузки, ₽: <?= h(fmt_rub($row['breakdown']['commission_time_adjustment_rub'] ?? null)) ?><br>
                            Поправка за скорость отгрузки, %: <?= h(fmt_percent($row['breakdown']['commission_time_adjustment_percent'] ?? null)) ?><br>
                            <?php foreach ((array)($row['breakdown']['shipment_adjustment_rows'] ?? []) as $adjRow): ?>
                              <?= h(((string)($adjRow['type'] ?? '') === 'discount' ? 'Скидка' : 'Штраф') . ' ' . (string)($adjRow['label'] ?? '')) ?>:
                              <?= h(fmt_rub($adjRow['amount_rub'] ?? null)) ?>
                              (доля <?= h(fmt_percent($adjRow['share_percent'] ?? null)) ?>,
                              ставка <?= h(fmt_percent($adjRow['rate_percent'] ?? null)) ?>
                              <?php if (!empty($adjRow['min_rub'])): ?>, минимум <?= h(fmt_rub($adjRow['min_rub'] ?? null)) ?><?php endif; ?>)
                              <br>
                            <?php endforeach; ?>
                            Эквайринг, ₽: <?= h(fmt_rub($row['breakdown']['acquiring_rub'] ?? null)) ?><br>
                            Эквайринг: <?= h(fmt_percent($row['breakdown']['acquiring_rate_percent'] ?? null)) ?><br>
                            Ценозависимая часть логистики: <?= h(fmt_percent($row['breakdown']['price_sensitive_logistics_rate_percent'] ?? null)) ?><br>
                            Процентные расходы суммарно: <?= h(fmt_percent($row['breakdown']['variable_rate_percent'] ?? null)) ?><br>
                            Налоги суммарно: <?= h(fmt_percent($row['breakdown']['tax_rate_percent'] ?? null)) ?><br>
                            УСН / налог с выручки, ₽: <?= h(fmt_rub($row['breakdown']['revenue_tax_rub'] ?? null)) ?><br>
                            УСН доходы-расходы, ₽: <?= h(fmt_rub($row['breakdown']['income_expense_tax_rub'] ?? null)) ?><br>
                            НДС, ₽: <?= h(fmt_rub($row['breakdown']['vat_rub'] ?? null)) ?><br>
                            Налог на прибыль, ₽: <?= h(fmt_rub($row['breakdown']['profit_tax_rub'] ?? null)) ?><br>
                            Применён доход обычной цены: <?= h(fmt_percent($row['breakdown']['target_profit_percent_applied'] ?? null)) ?><br>
                            Применён доход min price: <?= h(fmt_percent($row['breakdown']['target_min_profit_percent_applied'] ?? null)) ?><br>
                            Минимум дохода обычной цены: <?= h(fmt_rub($row['breakdown']['target_profit_min_rub'] ?? null)) ?><br>
                            Минимум дохода min price: <?= h(fmt_rub($row['breakdown']['target_min_profit_min_rub'] ?? null)) ?><br>
                            Доход обычной цены: <?= h(fmt_rub($row['breakdown']['target_profit_rub'] ?? null)) ?><br>
                            Доход min price: <?= h(fmt_rub($row['breakdown']['target_min_profit_rub'] ?? null)) ?><br>
                            Цена до модификатора: <?= h(fmt_rub($row['breakdown']['recommended_price_before_modifier'] ?? null)) ?><br>
                            Min price до модификатора: <?= h(fmt_rub($row['breakdown']['recommended_min_price_before_modifier'] ?? null)) ?><br>
                            Модификатор обычной цены: <?= h((string)($row['breakdown']['price_modifier_mode'] ?? 'none')) ?> / <?= h(fmt_plain_decimal($row['breakdown']['price_modifier_value'] ?? null)) ?><br>
                            Модификатор min price: <?= h((string)($row['breakdown']['price_modifier_min_mode'] ?? 'none')) ?> / <?= h(fmt_plain_decimal($row['breakdown']['price_modifier_min_value'] ?? null)) ?><br>
                            <?php if (trim((string)($row['breakdown']['supplier_price_modifier'] ?? '')) !== ''): ?>
                              Модификатор товара: <?= h((string)$row['breakdown']['supplier_price_modifier']) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($row['breakdown']['price_index_transition_enabled'])): ?>
                              Переход по индексу цены: <?= !empty($row['breakdown']['price_index_transition_applied']) ? 'применён' : 'не применён' ?><br>
                              Min price после модификатора, до индекса: <?= h(fmt_rub($row['breakdown']['recommended_min_price_before_index_step'] ?? null)) ?><br>
                              Конкурентная цена индекса: <?= h(fmt_rub($row['breakdown']['price_index_benchmark'] ?? null)) ?> (<?= h((string)($row['breakdown']['price_index_benchmark_source'] ?? '—')) ?>)<br>
                              Цена покупателя Ozon из индекса: <?= h(fmt_rub($row['breakdown']['price_index_reference_effective_price'] ?? null)) ?><br>
                              Расчётная скидка Ozon: <?= h(fmt_percent($row['breakdown']['price_index_discount_percent'] ?? null)) ?><br>
                              Текущий уровень для min price: <?= render_index_badge($row['breakdown']['price_index_current_level'] ?? '—') ?><br>
                              Следующий уровень: <?= render_index_badge($row['breakdown']['price_index_next_level'] ?? '—') ?><br>
                              Порог перехода по min price: <?= h(fmt_rub($row['breakdown']['price_index_threshold_price'] ?? null)) ?>, цена покупателя <?= h(fmt_rub($row['breakdown']['price_index_threshold_effective_price'] ?? null)) ?><br>
                              Целевой min price по индексу: <?= h(fmt_rub($row['breakdown']['price_index_transition_target_price'] ?? null)) ?>, цена покупателя <?= h(fmt_rub($row['breakdown']['price_index_transition_target_effective_price'] ?? null)) ?><br>
                              Снижение для перехода: <?= h(fmt_percent($row['breakdown']['price_index_transition_drop_percent'] ?? null)) ?><br>
                            <?php endif; ?>
                            Индекс цены Ozon: <?= render_index_badge(($row['breakdown']['price_index_current_price_level'] ?? '') ?: ($row['breakdown']['color_index'] ?? '—')) ?><br>
                            Объёмный вес: <?= h(isset($row['breakdown']['volume_weight']) && $row['breakdown']['volume_weight'] !== null ? fmt_plain_decimal($row['breakdown']['volume_weight']) : '—') ?>
                          </div>
                        </details>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="muted" style="margin-top:14px;">Сохрани профиль и нажми “Пересчитать по этому фиду”, чтобы увидеть расчёт.</div>
        <?php endif; ?>
      </div>

      <?php if ($recentPushLogs): ?>
        <div class="card">
          <h2 style="margin:0 0 8px;">Последние ручные выгрузки в Ozon</h2>
          <div class="muted" style="margin-bottom:14px;">Журнал показывает не только факт отправки, но и какие именно `price`, `min_price` и акции мы отправляли в Ozon.</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Когда</th>
                  <th>Пользователь</th>
                  <th>offer_id</th>
                  <th>Статус</th>
                  <th>price</th>
                  <th>min_price</th>
                  <th>Акции +/−</th>
                  <th>Подробности</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentPushLogs as $logRow): ?>
                  <?php
                    $desired = json_decode((string)($logRow['desired_state_json'] ?? ''), true);
                    if (!is_array($desired)) $desired = [];
                    $result = json_decode((string)($logRow['result_json'] ?? ''), true);
                    if (!is_array($result)) $result = [];
                    $summary = (array)($desired['summary'] ?? []);
                  ?>
                  <tr>
                    <td><?= h((string)($logRow['created_at'] ?? '')) ?></td>
                    <td><?= h((string)($logRow['actor'] ?? '—')) ?></td>
                    <td><code><?= h((string)($logRow['offer_id'] ?? '')) ?></code></td>
                    <td><?= h((string)($logRow['status'] ?? '')) ?></td>
                    <td><?= h(fmt_rub($desired['regular_price'] ?? null)) ?></td>
                    <td><?= h(fmt_rub($desired['min_price'] ?? null)) ?></td>
                    <td><?= h('+' . (int)($summary['actions_add_count'] ?? 0) . '/' . (int)($summary['actions_update_count'] ?? 0) . ', -' . (int)($summary['actions_remove_count'] ?? 0)) ?></td>
                    <td>
                      <details>
                        <summary>Показать</summary>
                        <div class="muted" style="margin-top:8px; line-height:1.55;">
                          Отправили обычную цену: <?= h(fmt_rub($desired['regular_price'] ?? null)) ?><br>
                          Отправили min_price: <?= h(fmt_rub($desired['min_price'] ?? null)) ?><br>
                          <?php if (!empty($desired['force_rule']['label'])): ?>
                            Глобальное форсирование: <?= h((string)$desired['force_rule']['source_line']) ?><br>
                          <?php endif; ?>
                          <?php if (!empty($desired['actions_upsert'])): ?>
                            Добавили / обновили акции:<br>
                            <?php foreach ((array)$desired['actions_upsert'] as $actionRow): ?>
                              • <?= h((string)($actionRow['title'] ?? 'Акция')) ?> — <?= h(fmt_rub($actionRow['action_price'] ?? null)) ?><br>
                            <?php endforeach; ?>
                          <?php endif; ?>
                          <?php if (!empty($desired['actions_remove'])): ?>
                            Удалили из акций:<br>
                            <?php foreach ((array)$desired['actions_remove'] as $actionRow): ?>
                              • <?= h((string)($actionRow['title'] ?? 'Акция')) ?><br>
                            <?php endforeach; ?>
                          <?php endif; ?>
                          <?php if (!empty($result['errors'])): ?>
                            Ошибки:<br>
                            <?php foreach ((array)$result['errors'] as $errorText): ?>
                              • <?= h((string)$errorText) ?><br>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </div>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
        </div>
      </details>
    </div>
  </div>
  <?php endif; ?>
  <div class="status-modal" id="previewStatusModal" aria-hidden="true">
    <div class="status-modal-card" role="status" aria-live="polite" aria-atomic="true">
      <h2 class="status-modal-title">Считаем предпросмотр</h2>
      <p class="status-modal-text">Сервис уже работает с этим фидом. Сейчас он скачивает XML, читает товары и собирает расчёт по ценам.</p>
      <div class="status-modal-stage">
        <span class="status-modal-spinner" aria-hidden="true"></span>
        <span id="previewStatusModalStage">Скачиваем XML и читаем товары фида…</span>
      </div>
      <div class="status-modal-note">Окно закроется само после загрузки страницы с готовым предпросмотром.</div>
    </div>
  </div>
  <script>
    (function () {
      const workspaceBlocks = Array.from(document.querySelectorAll('.workspace-block[data-storage-key]'));
      workspaceBlocks.forEach((block) => {
        const storageKey = block.getAttribute('data-storage-key');
        if (!storageKey) return;
        try {
          const savedState = localStorage.getItem(`ozon-price-tool-feed:${storageKey}`);
          if (savedState === 'closed') {
            block.removeAttribute('open');
          } else if (savedState === 'open') {
            block.setAttribute('open', 'open');
          }
        } catch (error) {
          // Ignore storage errors and fall back to markup defaults.
        }
        block.addEventListener('toggle', () => {
          try {
            localStorage.setItem(`ozon-price-tool-feed:${storageKey}`, block.open ? 'open' : 'closed');
          } catch (error) {
            // Ignore storage errors, the UI can still work without persistence.
          }
        });
      });

      const createRangeRow = (prefix) => {
        const row = document.createElement('div');
        row.className = 'range-row';
        row.innerHTML = `
          <input type="text" inputmode="decimal" name="${prefix}_range_from[]" placeholder="0">
          <input type="text" inputmode="decimal" name="${prefix}_range_to[]" placeholder="пусто = и выше">
          <input type="text" inputmode="decimal" name="${prefix}_range_percent[]" placeholder="${prefix === 'target' ? '20' : '10'}">
          <button type="button" class="range-remove" data-range-remove>×</button>
        `;
        return row;
      };

      document.querySelectorAll('[data-range-add]').forEach((button) => {
        button.addEventListener('click', () => {
          const prefix = button.getAttribute('data-range-add');
          const box = button.closest('[data-range-editor]');
          const rows = box?.querySelector('[data-range-rows]');
          if (!rows || !prefix) return;
          rows.appendChild(createRangeRow(prefix));
        });
      });

      document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.hasAttribute('data-range-remove')) return;
        const row = target.closest('.range-row');
        const rowsWrap = target.closest('[data-range-rows]');
        if (!row || !rowsWrap) return;
        const rows = rowsWrap.querySelectorAll('.range-row');
        if (rows.length <= 1) {
          row.querySelectorAll('input').forEach((input) => {
            input.value = '';
          });
          return;
        }
        row.remove();
      });

      const shipmentInputs = [
        'ship_0_12_percent',
        'ship_12_24_percent',
        'ship_24_36_percent',
        'ship_36_48_percent',
        'ship_48_plus_percent',
      ].map((id) => document.getElementById(id)).filter(Boolean);
      const shipmentSummary = document.getElementById('shipmentSummary');
      const shipmentSumChip = document.getElementById('shipmentSumChip');
      const shipmentAdjChip = document.getElementById('shipmentAdjChip');
      const shipmentSummaryNote = document.getElementById('shipmentSummaryNote');

      function parseLocalizedNum(value) {
        const n = parseFloat(String(value || '').replace(/\s+/g, '').replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
      }

      function fmtLocalized(value, decimals = 2) {
        return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value);
      }

      function updateShipmentSummary() {
        if (!shipmentSummary || !shipmentSumChip || !shipmentAdjChip || !shipmentSummaryNote) return;
        const values = {
          ship_0_12_percent: parseLocalizedNum(document.getElementById('ship_0_12_percent')?.value),
          ship_12_24_percent: parseLocalizedNum(document.getElementById('ship_12_24_percent')?.value),
          ship_24_36_percent: parseLocalizedNum(document.getElementById('ship_24_36_percent')?.value),
          ship_36_48_percent: parseLocalizedNum(document.getElementById('ship_36_48_percent')?.value),
          ship_48_plus_percent: parseLocalizedNum(document.getElementById('ship_48_plus_percent')?.value),
        };
        const total = Object.values(values).reduce((sum, value) => sum + Math.max(0, value), 0);
        const adjustment =
          (-3 * Math.max(0, values.ship_0_12_percent)
          -2 * Math.max(0, values.ship_12_24_percent)
          +1 * Math.max(0, values.ship_24_36_percent)
          +2 * Math.max(0, values.ship_36_48_percent)
          +3 * Math.max(0, values.ship_48_plus_percent)) / 100;
        const lateShare = Math.max(0, values.ship_24_36_percent) + Math.max(0, values.ship_36_48_percent) + Math.max(0, values.ship_48_plus_percent);

        shipmentSumChip.textContent = `Сумма окон: ${fmtLocalized(total)}%`;
        shipmentAdjChip.textContent = `Средняя поправка к комиссии: ${fmtLocalized(adjustment)}%`;
        shipmentSumChip.className = 'shipment-chip';
        shipmentAdjChip.className = 'shipment-chip';

        if (Math.abs(total) < 0.0001) {
          shipmentSumChip.classList.add('warn');
          shipmentSummaryNote.textContent = 'Если оставить все окна по нулям, поправка к комиссии за скорость отгрузки применяться не будет.';
        } else if (Math.abs(total - 100) <= 0.05) {
          shipmentSumChip.classList.add('ok');
          shipmentSummaryNote.textContent = lateShare > 0
            ? 'Распределение сходится в 100%. Для окон 24+ часов минимальные штрафы 50/100 ₽ могут сделать фактическую поправку выше на дешёвых товарах.'
            : 'Распределение сходится в 100%. Быстрые окна дадут скидку к комиссии, штрафные окна сейчас не участвуют.';
        } else if (total < 100) {
          shipmentSumChip.classList.add('warn');
          shipmentSummaryNote.textContent = `Распределение пока неполное: не хватает ${fmtLocalized(100 - total)}%. Сохранять такой профиль нельзя.`;
        } else {
          shipmentSumChip.classList.add('error');
          shipmentSummaryNote.textContent = `Распределение превышает 100% на ${fmtLocalized(total - 100)}%. Нужно уменьшить доли окон отгрузки.`;
        }

        if (Math.abs(adjustment) < 0.0001) {
          shipmentAdjChip.classList.add('warn');
        } else if (adjustment < 0) {
          shipmentAdjChip.classList.add('ok');
        } else {
          shipmentAdjChip.classList.add('error');
        }
      }

      shipmentInputs.forEach((input) => {
        input.addEventListener('input', updateShipmentSummary);
      });
      updateShipmentSummary();

      const previewForm = document.querySelector('[data-preview-status-form]');
      const previewModal = document.getElementById('previewStatusModal');
      const previewModalStage = document.getElementById('previewStatusModalStage');
      const previewStages = [
        'Скачиваем XML и читаем товары фида…',
        'Ищем закупку и готовим список товаров для запроса…',
        'Запрашиваем цены и тарифы Ozon API…',
        'Собираем расчёт обычной цены и min price…',
      ];
      let previewStageTimer = null;

      if (previewForm && previewModal && previewModalStage) {
        previewForm.addEventListener('submit', () => {
          previewModal.classList.add('is-open');
          previewModal.setAttribute('aria-hidden', 'false');
          let stageIndex = 0;
          previewModalStage.textContent = previewStages[stageIndex];
          if (previewStageTimer) {
            window.clearInterval(previewStageTimer);
          }
          previewStageTimer = window.setInterval(() => {
            stageIndex = (stageIndex + 1) % previewStages.length;
            previewModalStage.textContent = previewStages[stageIndex];
          }, 1800);
        });
      }

      const tableBody = document.getElementById('previewTableBody');
      if (!tableBody) return;
      const searchInput = document.getElementById('previewSearch');
      const statusSelect = document.getElementById('previewStatus');
      const sortSelect = document.getElementById('previewSort');
      const limitSelect = document.getElementById('previewLimit');
      const counter = document.getElementById('previewCounter');
      const rows = Array.from(tableBody.querySelectorAll('tr[data-offer-id]'));

      function parseNum(value) {
        const n = parseFloat(String(value || '').replace(',', '.'));
        return Number.isFinite(n) ? n : null;
      }

      function sortRows(list, mode) {
        const collator = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
        const sorted = list.slice();
        sorted.sort((a, b) => {
          const get = (row, key) => row.dataset[key] || '';
          const compareNum = (key, dir) => {
            const av = parseNum(get(a, key));
            const bv = parseNum(get(b, key));
            if (av === null && bv === null) return 0;
            if (av === null) return 1;
            if (bv === null) return -1;
            return dir * (av - bv);
          };
          switch (mode) {
            case 'delta_price_desc': return compareNum('deltaPrice', -1);
            case 'delta_price_asc': return compareNum('deltaPrice', 1);
            case 'recommended_price_desc': return compareNum('recommendedPrice', -1);
            case 'recommended_price_asc': return compareNum('recommendedPrice', 1);
            case 'purchase_cost_desc': return compareNum('purchaseCost', -1);
            case 'purchase_cost_asc': return compareNum('purchaseCost', 1);
            case 'offer_id':
            default:
              return collator.compare(get(a, 'offerId'), get(b, 'offerId'));
          }
        });
        return sorted;
      }

      function applyFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = statusSelect?.value || 'all';
        const mode = sortSelect?.value || 'offer_id';
        const limitRaw = limitSelect?.value || '100';
        const limit = limitRaw === 'all' ? Number.POSITIVE_INFINITY : parseInt(limitRaw, 10);

        let visible = rows.filter((row) => {
          const haystack = (row.dataset.search || '').toLowerCase();
          if (query && !haystack.includes(query)) return false;
          if (status !== 'all' && (row.dataset.status || '') !== status) return false;
          return true;
        });

        visible = sortRows(visible, mode);
        const visibleSet = new Set(visible.slice(0, limit));

        rows.forEach((row) => {
          row.style.display = visibleSet.has(row) ? '' : 'none';
        });

        if (counter) {
          counter.textContent = 'Показано: ' + Math.min(visible.length, Number.isFinite(limit) ? limit : visible.length) + ' из ' + rows.length;
        }

        visible.forEach((row) => tableBody.appendChild(row));
      }

      [searchInput, statusSelect, sortSelect, limitSelect].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
      });
      applyFilters();
    })();
  </script>
</body>
</html>
