<?php
declare(strict_types=1);

require_once __DIR__ . '/WildberriesClient.php';
require_once __DIR__ . '/../wb_promotions.php';

function wb_price_tool_client(array $cfg, array $connection): WildberriesClient
{
    $wbCfg = (array)($cfg['wildberries'] ?? []);
    $wbCfg['api_token'] = trim((string)($connection['api_key'] ?? ($wbCfg['api_token'] ?? '')));
    $wbCfg['timeout_sec'] = max(5, (int)($connection['timeout_sec'] ?? ($wbCfg['timeout_sec'] ?? 30)));
    return new WildberriesClient($wbCfg);
}

function wb_price_tool_cache_dir(array $cfg): string
{
    $base = rtrim((string)($cfg['paths']['storage_dir'] ?? (__DIR__ . '/../../storage')), '/');
    $dir = $base . '/cache/wb_price_tool';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function wb_price_tool_goods_cache_path(array $cfg, array $connection): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/goods_' . $connectionId . '.json';
}

function wb_price_tool_clear_goods_cache(array $cfg, array $connection): void
{
    $path = wb_price_tool_goods_cache_path($cfg, $connection);
    if (is_file($path)) {
        @unlink($path);
    }
}

function wb_price_tool_cards_cache_path(array $cfg, array $connection): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/cards_' . $connectionId . '.json';
}

function wb_price_tool_cards_lookup_cache_path(array $cfg, array $connection): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/cards_lookup_' . $connectionId . '.json';
}

function wb_price_tool_commissions_cache_path(array $cfg, array $connection): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/commissions_' . $connectionId . '.json';
}

function wb_price_tool_box_tariffs_cache_path(array $cfg, array $connection, string $date): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/box_tariffs_' . $connectionId . '_' . preg_replace('~[^0-9-]+~', '', $date) . '.json';
}

function wb_price_tool_return_tariffs_cache_path(array $cfg, array $connection, string $date): string
{
    $connectionId = (int)($connection['id'] ?? 0);
    return wb_price_tool_cache_dir($cfg) . '/return_tariffs_' . $connectionId . '_' . preg_replace('~[^0-9-]+~', '', $date) . '.json';
}

function wb_price_tool_previous_tariffs_cache(array $cfg, array $connection, string $date, bool $returns = false, int $maxDays = 7): ?array
{
    try {
        $baseDate = new DateTimeImmutable($date);
    } catch (Throwable $e) {
        return null;
    }
    for ($daysBack = 1; $daysBack <= max(1, $maxDays); $daysBack++) {
        $candidateDate = $baseDate->modify('-' . $daysBack . ' day')->format('Y-m-d');
        $path = $returns
            ? wb_price_tool_return_tariffs_cache_path($cfg, $connection, $candidateDate)
            : wb_price_tool_box_tariffs_cache_path($cfg, $connection, $candidateDate);
        if (!is_file($path)) {
            continue;
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        $items = is_array($decoded['items'] ?? null) ? array_values(array_filter((array)$decoded['items'], 'is_array')) : [];
        if ($items) {
            return [
                'items' => $items,
                'date' => $candidateDate,
            ];
        }
    }
    return null;
}

function wb_price_tool_index_tariff_snapshot(array $items, string $requestedDate, string $dataDate = ''): array
{
    $indexed = wb_price_tool_index_tariff_warehouses($items);
    $dataDate = $dataDate !== '' ? $dataDate : $requestedDate;
    $indexed['requested_date'] = $requestedDate;
    $indexed['data_date'] = $dataDate;
    $indexed['fallback_used'] = $dataDate !== '' && $requestedDate !== '' && $dataDate !== $requestedDate;
    return $indexed;
}

function wb_price_tool_fetch_all_goods(array $cfg, array $connection, bool $forceRefresh = false, int $ttlSeconds = 300): array
{
    $cachePath = wb_price_tool_goods_cache_path($cfg, $connection);
    $now = time();
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded) && (int)($decoded['updated_at'] ?? 0) > ($now - $ttlSeconds) && is_array($decoded['items'] ?? null)) {
            return wb_price_tool_index_goods((array)$decoded['items']);
        }
    }

    $client = wb_price_tool_client($cfg, $connection);
    $items = $client->getAllGoodsWithPrices();
    @file_put_contents($cachePath, json_encode([
        'updated_at' => $now,
        'items' => array_values($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return wb_price_tool_index_goods($items);
}

function wb_price_tool_index_goods(array $items): array
{
    $byVendorCode = [];
    $byNmId = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $vendorCode = trim((string)($item['vendorCode'] ?? ''));
        $nmId = (int)($item['nmID'] ?? 0);
        if ($vendorCode !== '') {
            $byVendorCode[$vendorCode] = $item;
        }
        if ($nmId > 0) {
            $byNmId[$nmId] = $item;
        }
    }

    return [
        'items' => array_values(array_filter($items, 'is_array')),
        'by_vendor_code' => $byVendorCode,
        'by_nm_id' => $byNmId,
    ];
}

function wb_price_tool_fetch_all_cards(array $cfg, array $connection, bool $forceRefresh = false, int $ttlSeconds = 3600): array
{
    $cachePath = wb_price_tool_cards_cache_path($cfg, $connection);
    $now = time();
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded) && (int)($decoded['updated_at'] ?? 0) > ($now - $ttlSeconds) && is_array($decoded['items'] ?? null)) {
            return wb_price_tool_index_cards((array)$decoded['items']);
        }
    }

    $client = wb_price_tool_client($cfg, $connection);
    $items = $client->getAllCards();
    @file_put_contents($cachePath, json_encode([
        'updated_at' => $now,
        'items' => array_values($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return wb_price_tool_index_cards($items);
}

function wb_price_tool_index_cards(array $items): array
{
    $byVendorCode = [];
    $byNmId = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $vendorCode = trim((string)($item['vendorCode'] ?? ''));
        $nmId = (int)($item['nmID'] ?? 0);
        if ($vendorCode !== '') {
            $byVendorCode[$vendorCode] = $item;
        }
        if ($nmId > 0) {
            $byNmId[$nmId] = $item;
        }
    }

    return [
        'items' => array_values(array_filter($items, 'is_array')),
        'by_vendor_code' => $byVendorCode,
        'by_nm_id' => $byNmId,
    ];
}

function wb_price_tool_load_cards_lookup(array $cfg, array $connection): array
{
    $cachePath = wb_price_tool_cards_lookup_cache_path($cfg, $connection);
    if (!is_file($cachePath)) {
        return ['updated_at' => 0, 'by_vendor_code' => [], 'by_nm_id' => []];
    }

    $decoded = json_decode((string)@file_get_contents($cachePath), true);
    if (!is_array($decoded)) {
        return ['updated_at' => 0, 'by_vendor_code' => [], 'by_nm_id' => []];
    }

    return [
        'updated_at' => (int)($decoded['updated_at'] ?? 0),
        'by_vendor_code' => is_array($decoded['by_vendor_code'] ?? null) ? $decoded['by_vendor_code'] : [],
        'by_nm_id' => is_array($decoded['by_nm_id'] ?? null) ? $decoded['by_nm_id'] : [],
    ];
}

function wb_price_tool_save_cards_lookup(array $cfg, array $connection, array $lookup): void
{
    $cachePath = wb_price_tool_cards_lookup_cache_path($cfg, $connection);
    @file_put_contents($cachePath, json_encode([
        'updated_at' => time(),
        'by_vendor_code' => (array)($lookup['by_vendor_code'] ?? []),
        'by_nm_id' => (array)($lookup['by_nm_id'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function wb_price_tool_cards_lookup_merge(array &$lookup, array $cards): void
{
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        $vendorCode = trim((string)($card['vendorCode'] ?? ''));
        $nmId = (int)($card['nmID'] ?? 0);
        if ($vendorCode !== '') {
            $lookup['by_vendor_code'][$vendorCode] = $card;
        }
        if ($nmId > 0) {
            $lookup['by_nm_id'][(string)$nmId] = $card;
        }
    }
}

function wb_price_tool_card_matches_offer(array $card, array $offer, ?array $good): bool
{
    $cardNmId = (int)($card['nmID'] ?? 0);
    $goodNmId = (int)($good['nmID'] ?? 0);
    if ($cardNmId > 0 && $goodNmId > 0 && $cardNmId === $goodNmId) {
        return true;
    }

    $cardVendorCode = trim((string)($card['vendorCode'] ?? ''));
    if ($cardVendorCode === '') {
        return false;
    }

    foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
        if ($candidate === $cardVendorCode) {
            return true;
        }
    }

    return false;
}

function wb_price_tool_fetch_card_for_offer(
    array $cfg,
    array $connection,
    array $offer,
    ?array $good,
    bool $forceRefresh = false
): ?array {
    $lookup = wb_price_tool_load_cards_lookup($cfg, $connection);
    if (!$forceRefresh) {
        $goodNmId = (int)($good['nmID'] ?? 0);
        if ($goodNmId > 0 && isset($lookup['by_nm_id'][(string)$goodNmId]) && is_array($lookup['by_nm_id'][(string)$goodNmId])) {
            return $lookup['by_nm_id'][(string)$goodNmId];
        }
        foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
            if (isset($lookup['by_vendor_code'][$candidate]) && is_array($lookup['by_vendor_code'][$candidate])) {
                return $lookup['by_vendor_code'][$candidate];
            }
        }
    }

    $searchTerms = [];
    $goodNmId = (int)($good['nmID'] ?? 0);
    if ($goodNmId > 0) {
        $searchTerms[] = (string)$goodNmId;
    }
    foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $searchTerms[] = $candidate;
        }
    }
    $searchTerms = array_values(array_unique($searchTerms));
    if (!$searchTerms) {
        return null;
    }

    $client = wb_price_tool_client($cfg, $connection);
    foreach ($searchTerms as $searchTerm) {
        $response = $client->getCardsList([
            'sort' => ['ascending' => false],
            'cursor' => ['limit' => 10],
            'filter' => [
                'textSearch' => $searchTerm,
                'withPhoto' => -1,
            ],
        ]);
        $cards = is_array($response['cards'] ?? null) ? $response['cards'] : [];
        if ($cards) {
            wb_price_tool_cards_lookup_merge($lookup, $cards);
            wb_price_tool_save_cards_lookup($cfg, $connection, $lookup);
            foreach ($cards as $card) {
                if (is_array($card) && wb_price_tool_card_matches_offer($card, $offer, $good)) {
                    return $card;
                }
            }
        }
        usleep(650000);
    }

    return null;
}

function wb_price_tool_find_card_for_offer(array $offer, ?array $good, array $cardsIndex): ?array
{
    $byVendorCode = is_array($cardsIndex['by_vendor_code'] ?? null) ? $cardsIndex['by_vendor_code'] : [];
    $byNmId = is_array($cardsIndex['by_nm_id'] ?? null) ? $cardsIndex['by_nm_id'] : [];

    $nmId = (int)($good['nmID'] ?? 0);
    if ($nmId > 0 && isset($byNmId[$nmId]) && is_array($byNmId[$nmId])) {
        return $byNmId[$nmId];
    }

    foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
        if (isset($byVendorCode[$candidate]) && is_array($byVendorCode[$candidate])) {
            return $byVendorCode[$candidate];
        }
    }

    return null;
}

function wb_price_tool_fetch_commissions(array $cfg, array $connection, bool $forceRefresh = false, int $ttlSeconds = 21600): array
{
    $cachePath = wb_price_tool_commissions_cache_path($cfg, $connection);
    $now = time();
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded) && (int)($decoded['updated_at'] ?? 0) > ($now - $ttlSeconds) && is_array($decoded['items'] ?? null)) {
            return wb_price_tool_index_commissions((array)$decoded['items']);
        }
    }

    $client = wb_price_tool_client($cfg, $connection);
    $response = $client->getCommissions('ru');
    $items = is_array($response['report'] ?? null) ? $response['report'] : [];
    @file_put_contents($cachePath, json_encode([
        'updated_at' => $now,
        'items' => array_values($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return wb_price_tool_index_commissions($items);
}

function wb_price_tool_index_commissions(array $items): array
{
    $bySubjectId = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $subjectId = (int)($item['subjectID'] ?? 0);
        if ($subjectId > 0) {
            $bySubjectId[$subjectId] = $item;
        }
    }

    return [
        'items' => array_values(array_filter($items, 'is_array')),
        'by_subject_id' => $bySubjectId,
    ];
}

function wb_price_tool_fetch_box_tariffs(array $cfg, array $connection, string $date, bool $forceRefresh = false, int $ttlSeconds = 21600): array
{
    $cachePath = wb_price_tool_box_tariffs_cache_path($cfg, $connection, $date);
    $now = time();
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded) && (int)($decoded['updated_at'] ?? 0) > ($now - $ttlSeconds) && !empty($decoded['items']) && is_array($decoded['items'])) {
            return wb_price_tool_index_tariff_snapshot((array)$decoded['items'], $date, $date);
        }
    }

    $fetchError = null;
    try {
        $client = wb_price_tool_client($cfg, $connection);
        $response = $client->getBoxTariffs($date);
        $items = array_values(array_filter((array)($response['response']['data']['warehouseList'] ?? []), 'is_array'));
        if ($items) {
            @file_put_contents($cachePath, json_encode([
                'updated_at' => $now,
                'items' => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return wb_price_tool_index_tariff_snapshot($items, $date, $date);
        }
    } catch (Throwable $e) {
        $fetchError = $e;
    }
    $fallback = wb_price_tool_previous_tariffs_cache($cfg, $connection, $date, false, 7);
    if (is_array($fallback)) {
        return wb_price_tool_index_tariff_snapshot((array)$fallback['items'], $date, (string)$fallback['date']);
    }
    if ($fetchError !== null) {
        throw $fetchError;
    }
    return wb_price_tool_index_tariff_snapshot([], $date, $date);
}

function wb_price_tool_fetch_return_tariffs(array $cfg, array $connection, string $date, bool $forceRefresh = false, int $ttlSeconds = 21600): array
{
    $cachePath = wb_price_tool_return_tariffs_cache_path($cfg, $connection, $date);
    $now = time();
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded) && (int)($decoded['updated_at'] ?? 0) > ($now - $ttlSeconds) && !empty($decoded['items']) && is_array($decoded['items'])) {
            return wb_price_tool_index_tariff_snapshot((array)$decoded['items'], $date, $date);
        }
    }

    $fetchError = null;
    try {
        $client = wb_price_tool_client($cfg, $connection);
        $response = $client->getReturnTariffs($date);
        $items = array_values(array_filter((array)($response['response']['data']['warehouseList'] ?? []), 'is_array'));
        if ($items) {
            @file_put_contents($cachePath, json_encode([
                'updated_at' => $now,
                'items' => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return wb_price_tool_index_tariff_snapshot($items, $date, $date);
        }
    } catch (Throwable $e) {
        $fetchError = $e;
    }
    $fallback = wb_price_tool_previous_tariffs_cache($cfg, $connection, $date, true, 7);
    if (is_array($fallback)) {
        return wb_price_tool_index_tariff_snapshot((array)$fallback['items'], $date, (string)$fallback['date']);
    }
    if ($fetchError !== null) {
        throw $fetchError;
    }
    return wb_price_tool_index_tariff_snapshot([], $date, $date);
}

function wb_price_tool_index_tariff_warehouses(array $items): array
{
    $byName = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['warehouseName'] ?? ''));
        if ($name !== '') {
            $byName[$name] = $item;
        }
    }

    return [
        'items' => array_values(array_filter($items, 'is_array')),
        'by_name' => $byName,
    ];
}

function wb_price_tool_tariff_date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
}

function wb_price_tool_runtime_context(array $cfg, array $connection, bool $forceRefresh = false, bool $includeFullCards = true): array
{
    $date = wb_price_tool_tariff_date();
    return [
        'cards' => $includeFullCards
            ? wb_price_tool_fetch_all_cards($cfg, $connection, $forceRefresh, 3600)
            : wb_price_tool_load_cards_lookup($cfg, $connection),
        'commissions' => wb_price_tool_fetch_commissions($cfg, $connection, $forceRefresh, 21600),
        'box_tariffs' => wb_price_tool_fetch_box_tariffs($cfg, $connection, $date, $forceRefresh, 21600),
        'return_tariffs' => wb_price_tool_fetch_return_tariffs($cfg, $connection, $date, $forceRefresh, 21600),
        'date' => $date,
        'cfg' => $cfg,
        'connection' => $connection,
    ];
}

function wb_price_tool_tariff_warehouse_options(array $runtime): array
{
    $items = is_array($runtime['box_tariffs']['items'] ?? null) ? $runtime['box_tariffs']['items'] : [];
    $options = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['warehouseName'] ?? ''));
        if ($name === '') {
            continue;
        }
        $geo = trim((string)($item['geoName'] ?? ''));
        $options[$name] = $geo !== '' ? ($name . ' · ' . $geo) : $name;
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
}

function wb_price_tool_load_feed_offers(array $feed): array
{
    $download = ozon_price_feed_fetch_remote_xml((string)($feed['feed_url'] ?? ''));
    try {
        $offers = ozon_price_parse_feed((string)$download['path'], (string)($feed['cost_tag'] ?? ''));
    } finally {
        @unlink((string)$download['path']);
    }
    if (function_exists('ozon_price_feed_offers_by_id')) {
        return array_values(ozon_price_feed_offers_by_id(
            $offers,
            (string)($feed['supplier_code'] ?? ''),
            (int)($feed['supplier_id'] ?? 0)
        ));
    }
    return $offers;
}

function wb_price_tool_strip_supplier_suffix($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $pos = strpos($value, '__');
    if ($pos === false) {
        return $value;
    }
    return substr($value, 0, $pos);
}

function wb_price_tool_offer_match_candidates(array $offer): array
{
    $candidates = [];
    foreach ([
        (string)($offer['vendorCode'] ?? ''),
        (string)($offer['offer_id'] ?? ''),
        wb_price_tool_strip_supplier_suffix((string)($offer['offer_id'] ?? '')),
    ] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $candidates[$candidate] = true;
        }
    }
    return array_keys($candidates);
}

function wb_price_tool_find_good_for_offer(array $offer, array $goodsIndex): ?array
{
    $byVendorCode = is_array($goodsIndex['by_vendor_code'] ?? null) ? $goodsIndex['by_vendor_code'] : [];
    foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
        if (isset($byVendorCode[$candidate]) && is_array($byVendorCode[$candidate])) {
            return $byVendorCode[$candidate];
        }
    }
    return null;
}

function wb_price_tool_current_price(array $good): ?float
{
    $sizes = is_array($good['sizes'] ?? null) ? $good['sizes'] : [];
    $first = is_array($sizes[0] ?? null) ? $sizes[0] : [];
    $price = isset($first['price']) ? (float)$first['price'] : 0.0;
    return $price > 0 ? round($price, 2) : null;
}

function wb_price_tool_current_discounted_price(array $good): ?float
{
    $sizes = is_array($good['sizes'] ?? null) ? $good['sizes'] : [];
    $first = is_array($sizes[0] ?? null) ? $sizes[0] : [];
    $price = isset($first['discountedPrice']) ? (float)$first['discountedPrice'] : 0.0;
    return $price > 0 ? round($price, 2) : null;
}

function wb_price_tool_current_club_discounted_price(array $good): ?float
{
    $sizes = is_array($good['sizes'] ?? null) ? $good['sizes'] : [];
    $first = is_array($sizes[0] ?? null) ? $sizes[0] : [];
    $price = isset($first['clubDiscountedPrice']) ? (float)$first['clubDiscountedPrice'] : 0.0;
    return $price > 0 ? round($price, 2) : null;
}

function wb_price_tool_current_club_discount(array $good): ?float
{
    return isset($good['clubDiscount']) ? round((float)$good['clubDiscount'], 2) : null;
}

function wb_price_tool_runtime_enabled(array $settings): bool
{
    return true;
}

function wb_price_tool_parse_number($value): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $normalized = trim(str_replace([' ', ','], ['', '.'], (string)$value));
    if ($normalized === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
        return 0.0;
    }
    return (float)$normalized;
}

function wb_price_tool_card_volume_liters(?array $card): ?float
{
    if (!is_array($card)) {
        return null;
    }
    $dimensions = is_array($card['dimensions'] ?? null) ? $card['dimensions'] : [];
    $length = wb_price_tool_parse_number($dimensions['length'] ?? null);
    $width = wb_price_tool_parse_number($dimensions['width'] ?? null);
    $height = wb_price_tool_parse_number($dimensions['height'] ?? null);
    if ($length <= 0 || $width <= 0 || $height <= 0) {
        return null;
    }
    return round(($length * $width * $height) / 1000.0, 4);
}

function wb_price_tool_tariff_amount(float $firstLiterRub, float $nextLiterRub, ?float $volumeLiters): ?float
{
    if ($firstLiterRub <= 0) {
        return null;
    }
    $volumeLiters = $volumeLiters !== null && $volumeLiters > 0 ? $volumeLiters : 1.0;
    $extraLiters = max(0.0, $volumeLiters - 1.0);
    return round($firstLiterRub + ($nextLiterRub * $extraLiters), 2);
}

function wb_price_tool_resolve_tariff_amount(array $row, array $fieldPairs, ?float $volumeLiters): ?array
{
    foreach ($fieldPairs as $pair) {
        if (!is_array($pair) || count($pair) < 2) {
            continue;
        }
        $baseField = (string)$pair[0];
        $literField = (string)$pair[1];
        $amount = wb_price_tool_tariff_amount(
            wb_price_tool_parse_number($row[$baseField] ?? null),
            wb_price_tool_parse_number($row[$literField] ?? null),
            $volumeLiters
        );
        if ($amount !== null && $amount > 0) {
            return [
                'amount' => $amount,
                'base_field' => $baseField,
                'liter_field' => $literField,
            ];
        }
    }
    return null;
}

function wb_price_tool_commission_field_for_scheme(array $settings): string
{
    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    return $scheme === 'fbo' ? 'kgvpSupplier' : 'kgvpMarketplace';
}

function wb_price_tool_resolve_marketplace_expenses(array $settings, ?array $good, ?array $card, array $runtime): array
{
    $warnings = [];
    $breakdown = [
        'source' => 'api',
        'subject_id' => is_array($card) ? (int)($card['subjectID'] ?? 0) : 0,
        'commission_field' => null,
        'commission_source' => 'missing_api',
        'tariff_date' => (string)($runtime['date'] ?? ''),
        'tariff_warehouse_name' => '',
        'tariff_geo_name' => '',
        'volume_liters' => wb_price_tool_card_volume_liters($card),
        'delivery_source' => 'missing_api',
        'delivery_rub' => 0.0,
        'return_tariff_source' => 'none',
        'return_tariff_rub' => null,
        'return_tariff_note' => '',
    ];

    $commissionPercent = 0.0;
    $deliveryRub = 0.0;

    if (!empty($runtime['box_tariffs']['fallback_used'])) {
        $fallbackDate = (string)($runtime['box_tariffs']['data_date'] ?? '');
        $warnings[] = 'WB вернул пустой список тарифов на текущую дату; временно используется последний непустой снимок'
            . ($fallbackDate !== '' ? ' от ' . $fallbackDate : '') . '.';
        $breakdown['tariff_data_date'] = $fallbackDate;
        $breakdown['tariff_fallback_used'] = 1;
    }

    $commissionField = wb_price_tool_commission_field_for_scheme($settings);
    $breakdown['commission_field'] = $commissionField;
    $subjectId = (int)($breakdown['subject_id'] ?? 0);
    $commissionMap = is_array($runtime['commissions']['by_subject_id'] ?? null) ? $runtime['commissions']['by_subject_id'] : [];
    if ($subjectId > 0 && isset($commissionMap[$subjectId]) && is_array($commissionMap[$subjectId])) {
        $commissionPercent = max(0.0, wb_price_tool_parse_number($commissionMap[$subjectId][$commissionField] ?? null));
        $breakdown['commission_source'] = 'api';
    } else {
        $warnings[] = 'WB API не вернул комиссию по subjectID. Резервная комиссия не используется.';
    }

    $warehouseName = trim((string)($settings['wb_tariff_warehouse_name'] ?? ''));
    $boxTariffs = is_array($runtime['box_tariffs']['by_name'] ?? null) ? $runtime['box_tariffs']['by_name'] : [];
    if ($warehouseName === '' && $boxTariffs) {
        $warehouseName = (string)array_key_first($boxTariffs);
    }
    if ($warehouseName !== '' && isset($boxTariffs[$warehouseName]) && is_array($boxTariffs[$warehouseName])) {
        $tariffRow = $boxTariffs[$warehouseName];
        $breakdown['tariff_warehouse_name'] = $warehouseName;
        $breakdown['tariff_geo_name'] = trim((string)($tariffRow['geoName'] ?? ''));
        $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
        $fieldPairs = $scheme === 'fbo'
            ? [
                ['boxDeliveryBase', 'boxDeliveryLiter'],
                ['boxDeliveryMarketplaceBase', 'boxDeliveryMarketplaceLiter'],
            ]
            : [
                ['boxDeliveryMarketplaceBase', 'boxDeliveryMarketplaceLiter'],
                ['boxDeliveryBase', 'boxDeliveryLiter'],
            ];
        $resolvedDelivery = wb_price_tool_resolve_tariff_amount(
            $tariffRow,
            $fieldPairs,
            $breakdown['volume_liters'] !== null ? (float)$breakdown['volume_liters'] : null
        );
        if (is_array($resolvedDelivery)) {
            $deliveryRub = (float)$resolvedDelivery['amount'];
            $breakdown['delivery_source'] = 'api';
            $breakdown['delivery_field_base'] = (string)$resolvedDelivery['base_field'];
            $breakdown['delivery_field_liter'] = (string)$resolvedDelivery['liter_field'];
        } else {
            $warnings[] = 'WB API не вернул корректный тариф логистики для выбранного склада.';
        }
    } else {
        $warnings[] = 'WB API не вернул тарифный склад для расчёта логистики.';
    }

    $returnTariffs = is_array($runtime['return_tariffs']['by_name'] ?? null) ? $runtime['return_tariffs']['by_name'] : [];
    if ($warehouseName !== '' && isset($returnTariffs[$warehouseName]) && is_array($returnTariffs[$warehouseName])) {
        $returnRow = $returnTariffs[$warehouseName];
        $returnTariffRub = wb_price_tool_tariff_amount(
            wb_price_tool_parse_number($returnRow['deliveryDumpSupOfficeBase'] ?? null),
            wb_price_tool_parse_number($returnRow['deliveryDumpSupOfficeLiter'] ?? null),
            $breakdown['volume_liters'] !== null ? (float)$breakdown['volume_liters'] : null
        );
        if ($returnTariffRub !== null && $returnTariffRub > 0) {
            $breakdown['return_tariff_source'] = 'api';
            $breakdown['return_tariff_rub'] = $returnTariffRub;
            $breakdown['return_tariff_note'] = 'Тариф возврата продавцу через ПВЗ/офис WB получен из API и учитывается в рисках, если включён блок потерь.';
        }
    }

    return [
        'commission_percent' => $commissionPercent,
        'delivery_rub' => $deliveryRub,
        'breakdown' => $breakdown,
        'warnings' => $warnings,
    ];
}

function wb_price_tool_normalize_discount_percent($value, float $max = 99.0, bool $club = false): float
{
    $percent = max(0.0, min($max, wb_price_tool_parse_number($value)));
    if ($club && $percent > 0.0 && $percent < 3.0) {
        return 3.0;
    }
    return round($percent, 2);
}

function wb_price_tool_effective_sale_price(float $salePrice, float $clubDiscountPercent): float
{
    $salePrice = max(0.0, $salePrice);
    $clubDiscountPercent = wb_price_tool_normalize_discount_percent($clubDiscountPercent, 31.0, true);
    if ($clubDiscountPercent <= 0.0) {
        return round($salePrice, 2);
    }
    return round($salePrice * (1.0 - ($clubDiscountPercent / 100.0)), 2);
}

function wb_price_tool_discount_for_target_sale(float $listPrice, float $targetSalePrice): int
{
    $listPrice = max(0.0, $listPrice);
    $targetSalePrice = max(0.0, $targetSalePrice);
    if ($listPrice <= 0.0 || $targetSalePrice >= $listPrice) {
        return 0;
    }

    $rawDiscount = (1.0 - ($targetSalePrice / $listPrice)) * 100.0;
    return (int)max(0, min(99, (int)ceil($rawDiscount - 0.000001)));
}

function wb_price_tool_payload_sale_price(int $price, int $discount): float
{
    $price = max(0, $price);
    $discount = max(0, min(99, $discount));
    return round($price * (1.0 - ($discount / 100.0)), 2);
}

function wb_price_tool_gradual_price_step_factor(): float
{
    // WB rejects price uploads when the new sale price is more than twice lower
    // than the current one. Keep the step slightly above 50% to survive rounding.
    return 0.51;
}

function wb_price_tool_gradual_price_max_steps(): int
{
    return 1;
}

function wb_price_tool_gradual_price_step_sleep_sec(): int
{
    return 0;
}

function wb_price_tool_limit_gradual_price_decrease(array $row): array
{
    $currentPrice = isset($row['current_price']) ? (int)round((float)$row['current_price']) : 0;
    $currentDiscount = isset($row['current_discount']) ? (int)round((float)$row['current_discount']) : 0;
    $desiredPrice = isset($row['price']) ? (int)round((float)$row['price']) : 0;
    $desiredDiscount = isset($row['discount']) ? (int)round((float)$row['discount']) : 0;
    $desiredClubDiscount = isset($row['club_discount']) ? (int)round((float)$row['club_discount']) : 0;

    if ($currentPrice <= 0 || $desiredPrice <= 0) {
        return $row;
    }

    $currentSalePrice = wb_price_tool_payload_sale_price($currentPrice, $currentDiscount);
    $desiredSalePrice = wb_price_tool_payload_sale_price($desiredPrice, $desiredDiscount);
    $minimumAllowedSalePrice = $currentSalePrice * wb_price_tool_gradual_price_step_factor();
    if ($currentSalePrice <= 0.0 || $desiredSalePrice + 0.01 >= $minimumAllowedSalePrice) {
        return $row;
    }

    $nextStepSalePrice = max(1.0, ceil($minimumAllowedSalePrice));
    $uploadPrice = $currentPrice;
    $uploadDiscount = wb_price_tool_discount_for_target_sale((float)$uploadPrice, $nextStepSalePrice);
    while ($uploadDiscount > 0) {
        $limitedSalePrice = wb_price_tool_payload_sale_price($uploadPrice, $uploadDiscount);
        if ($limitedSalePrice + 0.01 >= $minimumAllowedSalePrice) {
            break;
        }
        $uploadDiscount--;
    }

    $limitedSalePrice = wb_price_tool_payload_sale_price($uploadPrice, $uploadDiscount);
    if ($limitedSalePrice <= 0.0 || $limitedSalePrice <= $desiredSalePrice + 0.01) {
        return $row;
    }

    $clubFactor = $desiredClubDiscount > 0
        ? max(0.01, 1.0 - ($desiredClubDiscount / 100.0))
        : 1.0;

    $row['original_price_before_gradual_limit'] = $desiredPrice;
    $row['original_discount_before_gradual_limit'] = $desiredDiscount;
    $row['original_sale_price_before_gradual_limit'] = $desiredSalePrice;
    $row['gradual_limit_current_sale_price'] = $currentSalePrice;
    $row['gradual_limit_sale_price'] = $limitedSalePrice;
    $row['gradual_limit_reason'] = 'WB может отправлять резкое снижение цены в карантин, поэтому цена снижается постепенно.';
    $row['price'] = $uploadPrice;
    $row['discount'] = $uploadDiscount;
    $row['target_sale_price'] = $limitedSalePrice;
    $row['target_club_sale_price'] = round($limitedSalePrice * $clubFactor, 2);
    $row['target_effective_sale_price'] = round($limitedSalePrice * $clubFactor, 2);
    $row['target_list_price'] = (float)$uploadPrice;
    $row['gradual_price_limit'] = 1;

    return $row;
}

function wb_price_tool_profit_snapshot(array $settings, float $purchaseCost, float $salePrice, ?array $runtimeExpenses = null): array
{
    $runtimeExpenses = is_array($runtimeExpenses) ? $runtimeExpenses : [];
    $commissionPercent = max(0.0, (float)($runtimeExpenses['commission_percent'] ?? 0));
    $marketingPercent = max(0.0, (float)($settings['promotion_percent'] ?? 0));
    $creditPercent = max(0.0, (float)($settings['credit_percent'] ?? 0));
    $extraPercent = max(0.0, (float)($settings['extra_expenses_percent'] ?? 0));
    $insurancePercent = max(0.0, (float)($settings['insurance_percent'] ?? 0));
    $manualFixedCostsRub = max(0.0, (float)($settings['fulfillment_markup_rub'] ?? 0));
    $deliveryRub = max(0.0, (float)($runtimeExpenses['delivery_rub'] ?? 0));
    $fixedCostsRub = $manualFixedCostsRub + $deliveryRub;
    $returnTariffRub = max(0.0, (float)($runtimeExpenses['breakdown']['return_tariff_rub'] ?? 0));
    $includeReturnsInCost = !empty($settings['include_returns_in_cost']);
    $nonbuyoutPercent = max(0.0, (float)($settings['nonbuyout_percent'] ?? 0));
    $resellableReturnPercent = max(0.0, (float)($settings['return_resellable_percent'] ?? 0));
    $nonresellableReturnPercent = max(0.0, (float)($settings['return_nonresellable_percent'] ?? 0));
    $nonbuyoutProcessingRub = max(0.0, (float)($settings['nonbuyout_processing_rub'] ?? 0));
    $returnProcessingRub = max(0.0, (float)($settings['return_processing_rub'] ?? 0));
    $issueTotalPercent = min(95.0, $nonbuyoutPercent + $resellableReturnPercent + $nonresellableReturnPercent);
    $keptPercent = max(5.0, 100.0 - $issueTotalPercent);
    $nonbuyoutFactor = $includeReturnsInCost ? ($nonbuyoutPercent / $keptPercent) : 0.0;
    $resellableReturnFactor = $includeReturnsInCost ? ($resellableReturnPercent / $keptPercent) : 0.0;
    $nonresellableReturnFactor = $includeReturnsInCost ? ($nonresellableReturnPercent / $keptPercent) : 0.0;
    $nonbuyoutOneOrderCost = $deliveryRub + $returnTariffRub + $nonbuyoutProcessingRub;
    $resellableReturnOneOrderCost = $returnTariffRub + $returnProcessingRub;
    $nonresellableReturnOneOrderCost = $returnTariffRub + $returnProcessingRub + $purchaseCost;
    $nonbuyoutCost = $nonbuyoutOneOrderCost * $nonbuyoutFactor;
    $resellableReturnCost = $resellableReturnOneOrderCost * $resellableReturnFactor;
    $nonresellableReturnCost = $nonresellableReturnOneOrderCost * $nonresellableReturnFactor;
    $issueCost = $includeReturnsInCost ? ($nonbuyoutCost + $resellableReturnCost + $nonresellableReturnCost) : 0.0;

    $commissionRub = $salePrice * ($commissionPercent / 100.0);
    $marketingRub = $salePrice * ($marketingPercent / 100.0);
    $creditRub = $salePrice * ($creditPercent / 100.0);
    $extraRub = $salePrice * ($extraPercent / 100.0);
    $insuranceRub = $salePrice * ($insurancePercent / 100.0);

    $profitBeforeTax = $salePrice - $purchaseCost - $fixedCostsRub - $commissionRub - $marketingRub - $creditRub - $extraRub - $insuranceRub - $issueCost;

    $taxMode = strtolower(trim((string)($settings['tax_mode'] ?? 'none')));
    $taxPercent = max(0.0, (float)($settings['tax_percent'] ?? 0));
    $vatPercent = max(0.0, (float)($settings['vat_percent'] ?? 0));
    $profitTaxPercent = max(0.0, (float)($settings['profit_tax_percent'] ?? 0));

    $taxRub = 0.0;
    if ($taxMode === 'usn_income') {
        $taxRub = $salePrice * ($taxPercent / 100.0);
    } elseif ($taxMode === 'usn_income_expense') {
        $taxRub = max(0.0, $profitBeforeTax) * ($taxPercent / 100.0);
    }
    $vatRub = $salePrice * ($vatPercent / 100.0);
    $profitAfterBaseTaxes = $profitBeforeTax - $taxRub - $vatRub;
    $profitTaxRub = max(0.0, $profitAfterBaseTaxes) * ($profitTaxPercent / 100.0);
    $profitRub = $profitAfterBaseTaxes - $profitTaxRub;
    $totalCostsRub = $salePrice - $profitRub;

    return [
        'sale_price' => round($salePrice, 2),
        'profit_rub' => round($profitRub, 2),
        'profit_on_cost_percent' => $purchaseCost > 0 ? round(($profitRub / $purchaseCost) * 100.0, 2) : 0.0,
        'total_costs_rub' => round(max(0.0, $totalCostsRub), 2),
        'breakdown' => [
            'sale_price' => round($salePrice, 2),
            'purchase_cost' => round($purchaseCost, 2),
            'manual_fixed_costs_rub' => round($manualFixedCostsRub, 2),
            'marketplace_delivery_rub' => round($deliveryRub, 2),
            'fixed_costs_rub' => round($fixedCostsRub, 2),
            'commission_percent' => round($commissionPercent, 2),
            'commission_rub' => round($commissionRub, 2),
            'commission_source' => (string)($runtimeExpenses['breakdown']['commission_source'] ?? 'manual'),
            'commission_field' => (string)($runtimeExpenses['breakdown']['commission_field'] ?? ''),
            'tariff_warehouse_name' => (string)($runtimeExpenses['breakdown']['tariff_warehouse_name'] ?? ''),
            'tariff_geo_name' => (string)($runtimeExpenses['breakdown']['tariff_geo_name'] ?? ''),
            'tariff_date' => (string)($runtimeExpenses['breakdown']['tariff_date'] ?? ''),
            'delivery_source' => (string)($runtimeExpenses['breakdown']['delivery_source'] ?? 'manual'),
            'delivery_field_base' => (string)($runtimeExpenses['breakdown']['delivery_field_base'] ?? ''),
            'delivery_field_liter' => (string)($runtimeExpenses['breakdown']['delivery_field_liter'] ?? ''),
            'subject_id' => (int)($runtimeExpenses['breakdown']['subject_id'] ?? 0),
            'volume_liters' => isset($runtimeExpenses['breakdown']['volume_liters']) ? round((float)$runtimeExpenses['breakdown']['volume_liters'], 4) : null,
            'return_tariff_source' => (string)($runtimeExpenses['breakdown']['return_tariff_source'] ?? 'none'),
            'return_tariff_rub' => isset($runtimeExpenses['breakdown']['return_tariff_rub']) ? round((float)$runtimeExpenses['breakdown']['return_tariff_rub'], 2) : null,
            'return_tariff_note' => (string)($runtimeExpenses['breakdown']['return_tariff_note'] ?? ''),
            'include_returns_in_cost' => $includeReturnsInCost ? 1 : 0,
            'nonbuyout_percent' => round($nonbuyoutPercent, 2),
            'return_resellable_percent' => round($resellableReturnPercent, 2),
            'return_nonresellable_percent' => round($nonresellableReturnPercent, 2),
            'issue_total_percent' => round($issueTotalPercent, 2),
            'kept_percent' => round($keptPercent, 2),
            'nonbuyout_processing_rub' => round($nonbuyoutProcessingRub, 2),
            'return_processing_rub' => round($returnProcessingRub, 2),
            'nonbuyout_factor_percent' => round($nonbuyoutFactor * 100.0, 4),
            'return_resellable_factor_percent' => round($resellableReturnFactor * 100.0, 4),
            'return_nonresellable_factor_percent' => round($nonresellableReturnFactor * 100.0, 4),
            'nonbuyout_one_order_cost' => round($nonbuyoutOneOrderCost, 2),
            'return_resellable_one_order_cost' => round($resellableReturnOneOrderCost, 2),
            'return_nonresellable_one_order_cost' => round($nonresellableReturnOneOrderCost, 2),
            'nonbuyout_cost' => round($nonbuyoutCost, 2),
            'return_resellable_cost' => round($resellableReturnCost, 2),
            'return_nonresellable_cost' => round($nonresellableReturnCost, 2),
            'issue_cost' => round($issueCost, 2),
            'marketing_percent' => round($marketingPercent, 2),
            'marketing_rub' => round($marketingRub, 2),
            'credit_percent' => round($creditPercent, 2),
            'credit_rub' => round($creditRub, 2),
            'extra_percent' => round($extraPercent, 2),
            'extra_rub' => round($extraRub, 2),
            'insurance_percent' => round($insurancePercent, 2),
            'insurance_rub' => round($insuranceRub, 2),
            'tax_mode' => $taxMode,
            'tax_percent' => round($taxPercent, 2),
            'tax_rub' => round($taxRub, 2),
            'vat_percent' => round($vatPercent, 2),
            'vat_rub' => round($vatRub, 2),
            'profit_tax_percent' => round($profitTaxPercent, 2),
            'profit_tax_rub' => round($profitTaxRub, 2),
            'profit_before_tax_rub' => round($profitBeforeTax, 2),
            'profit_after_base_taxes_rub' => round($profitAfterBaseTaxes, 2),
        ],
    ];
}

function wb_price_tool_target_profit_rub(array $settings, float $purchaseCost, bool $forMinPrice = false): float
{
    $targetProfitPercent = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, $forMinPrice);
    $targetProfitMinRub = function_exists('ozon_price_resolve_target_profit_min_rub')
        ? ozon_price_resolve_target_profit_min_rub($settings, $forMinPrice)
        : max(0.0, (float)($settings[$forMinPrice ? 'min_target_profit_min_rub' : 'target_profit_min_rub'] ?? 0));
    return max($purchaseCost * ($targetProfitPercent / 100.0), $targetProfitMinRub);
}

function wb_price_tool_solve_sale_price(array $settings, float $purchaseCost, float $targetProfitRub, ?array $runtimeExpenses = null): ?array
{
    $low = 0.01;
    $high = max(1.0, $purchaseCost + $targetProfitRub + 100.0);
    $snapshot = wb_price_tool_profit_snapshot($settings, $purchaseCost, $high, $runtimeExpenses);

    for ($guard = 0; $guard < 24 && (float)($snapshot['profit_rub'] ?? 0.0) < $targetProfitRub; $guard++) {
        $high *= 1.5;
        $snapshot = wb_price_tool_profit_snapshot($settings, $purchaseCost, $high, $runtimeExpenses);
    }

    if ((float)($snapshot['profit_rub'] ?? 0.0) < $targetProfitRub) {
        return null;
    }

    for ($i = 0; $i < 48; $i++) {
        $mid = ($low + $high) / 2.0;
        $midSnapshot = wb_price_tool_profit_snapshot($settings, $purchaseCost, $mid, $runtimeExpenses);
        if ((float)($midSnapshot['profit_rub'] ?? 0.0) >= $targetProfitRub) {
            $high = $mid;
            $snapshot = $midSnapshot;
        } else {
            $low = $mid;
        }
    }

    return [
        'sale_price' => round($high, 2),
        'snapshot' => $snapshot,
    ];
}

function wb_price_tool_build_desired_state(array $settings, array $calc): ?array
{
    $nmId = (int)($calc['nm_id'] ?? 0);
    $effectiveSalePrice = (float)($calc['recommended_effective_sale_price'] ?? ($calc['recommended_sale_price'] ?? 0));
    if ($nmId <= 0 || $effectiveSalePrice <= 0) {
        return null;
    }

    $discountPercent = wb_price_tool_normalize_discount_percent($settings['wb_discount_percent'] ?? 0, 99.0, false);
    $clubDiscountPercent = wb_price_tool_normalize_discount_percent($settings['wb_club_discount_percent'] ?? 0, 31.0, true);
    $uploadDiscount = (int)round($discountPercent);
    $uploadClubDiscount = (int)round($clubDiscountPercent);
    $normalSalePrice = $uploadClubDiscount > 0
        ? ($effectiveSalePrice / (1.0 - ($uploadClubDiscount / 100.0)))
        : $effectiveSalePrice;
    $maxSalePrice = max(0.0, (float)($calc['max_sale_price'] ?? 0));
    $fixedListPrice = max(0.0, (float)($calc['fixed_list_price'] ?? 0));
    $usesFixedListPrice = $fixedListPrice > 0.0;
    $priceCappedByPromotion = false;
    if ($maxSalePrice > 0 && $normalSalePrice > $maxSalePrice) {
        $normalSalePrice = $maxSalePrice;
        $priceCappedByPromotion = true;
    }

    if ($usesFixedListPrice) {
        $listPrice = $fixedListPrice;
        $uploadPrice = max(1, (int)ceil($fixedListPrice));
        $uploadDiscount = wb_price_tool_discount_for_target_sale((float)$uploadPrice, $normalSalePrice);
    } else {
        $listPrice = $uploadDiscount > 0
            ? ($normalSalePrice / (1.0 - ($uploadDiscount / 100.0)))
            : $normalSalePrice;

        $uploadPrice = max(1, $priceCappedByPromotion ? (int)floor($listPrice) : (int)ceil($listPrice));
        if ($maxSalePrice > 0) {
            $maxUploadPrice = $uploadDiscount > 0
                ? (int)floor($maxSalePrice / (1.0 - ($uploadDiscount / 100.0)))
                : (int)floor($maxSalePrice);
            if ($maxUploadPrice > 0) {
                $uploadPrice = min($uploadPrice, $maxUploadPrice);
            }
        }
    }
    $actualSalePrice = round($uploadPrice * (1.0 - ($uploadDiscount / 100.0)), 2);
    $clubSalePrice = $uploadClubDiscount > 0
        ? round($actualSalePrice * (1.0 - ($uploadClubDiscount / 100.0)), 2)
        : $actualSalePrice;
    $actualEffectiveSalePrice = $uploadClubDiscount > 0 ? $clubSalePrice : $actualSalePrice;

    return [
        'nm_id' => $nmId,
        'price' => $uploadPrice,
        'discount' => $uploadDiscount,
        'club_discount' => $uploadClubDiscount,
        'target_sale_price' => $actualSalePrice,
        'target_club_sale_price' => $clubSalePrice,
        'target_effective_sale_price' => $actualEffectiveSalePrice,
        'target_list_price' => (float)$uploadPrice,
        'raw_target_effective_sale_price' => round($effectiveSalePrice, 2),
        'raw_target_sale_price' => round($normalSalePrice, 2),
        'raw_target_list_price' => round($listPrice, 2),
        'fixed_base_price' => $usesFixedListPrice ? (float)$uploadPrice : null,
        'discount_source' => $usesFixedListPrice ? 'calculated_from_base_price' : 'configured',
        'promotion_max_sale_price' => $maxSalePrice > 0 ? round($maxSalePrice, 2) : null,
        'price_capped_by_promotion' => $priceCappedByPromotion ? 1 : 0,
    ];
}

function wb_price_tool_calculate_offer(array $settings, array $offer, ?array $good, ?array $runtime = null): array
{
    $offerId = trim((string)($offer['offer_id'] ?? ''));
    $purchaseCost = isset($offer['purchase_cost']) ? (float)$offer['purchase_cost'] : null;
    $warnings = array_values(array_filter((array)($offer['warnings'] ?? [])));

    $row = [
        'offer_id' => $offerId,
        'vendor_code' => trim((string)($offer['vendorCode'] ?? '')),
        'name' => (string)($offer['name'] ?? ''),
        'purchase_cost' => $purchaseCost,
        'purchase_cost_raw' => (string)($offer['cost_raw'] ?? ''),
        'nm_id' => 0,
        'current_price' => null,
        'current_discount' => null,
        'current_discounted_price' => null,
        'current_club_discount' => null,
        'current_club_discounted_price' => null,
        'recommended_sale_price' => null,
        'recommended_club_sale_price' => null,
        'recommended_base_sale_price' => null,
        'recommended_base_club_sale_price' => null,
        'recommended_base_effective_sale_price' => null,
        'recommended_base_list_price' => null,
        'recommended_base_discount' => null,
        'recommended_base_club_discount' => null,
        'recommended_min_sale_price' => null,
        'recommended_min_club_sale_price' => null,
        'recommended_min_effective_sale_price' => null,
        'recommended_min_discount' => null,
        'recommended_min_club_discount' => null,
        'recommended_list_price' => null,
        'recommended_discount' => null,
        'recommended_club_discount' => null,
        'profit_rub' => null,
        'profit_percent' => null,
        'editable_size_price' => false,
        'status' => 'ok',
        'warnings' => $warnings,
        'desired_state' => null,
        'promotion_decision' => null,
        'breakdown' => [],
        'card' => null,
        'runtime_expenses' => null,
    ];

    if ($offerId === '' && $row['vendor_code'] === '') {
        $row['warnings'][] = 'В фиде не удалось определить артикул товара.';
        $row['status'] = 'error';
        return $row;
    }

    if (function_exists('ozon_price_offer_blocked_by_feed_price') && ozon_price_offer_blocked_by_feed_price($offer)) {
        $row['warnings'][] = 'Цена товара в фиде отсутствует или равна 0: Price Tool не обновляет цену.';
        $row['status'] = 'warn';
        return $row;
    }

    if ($purchaseCost === null || $purchaseCost <= 0) {
        $row['warnings'][] = 'Нет корректной закупочной цены.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!is_array($good)) {
        $row['warnings'][] = 'Товар не найден в кабинете WB по vendorCode/артикулу.';
        $row['status'] = 'warn';
        return $row;
    }

    $row['nm_id'] = (int)($good['nmID'] ?? 0);
    $row['current_price'] = wb_price_tool_current_price($good);
    $row['current_discounted_price'] = wb_price_tool_current_discounted_price($good);
    $row['current_discount'] = isset($good['discount']) ? round((float)$good['discount'], 2) : null;
    $row['current_club_discount'] = wb_price_tool_current_club_discount($good);
    $row['current_club_discounted_price'] = wb_price_tool_current_club_discounted_price($good);
    $row['editable_size_price'] = !empty($good['editableSizePrice']);
    $row['is_bad_turnover'] = !empty($good['isBadTurnover']);

    if ($row['editable_size_price']) {
        $row['warnings'][] = 'У товара включены разные цены по размерам. В первой версии WB Price Tool такие карточки только читаются и не обновляются.';
        $row['status'] = 'warn';
        return $row;
    }

    $runtime = is_array($runtime) ? $runtime : [];
    $cardsIndex = is_array($runtime['cards'] ?? null) ? $runtime['cards'] : ['by_vendor_code' => [], 'by_nm_id' => []];
    $card = wb_price_tool_find_card_for_offer($offer, $good, $cardsIndex);
    if (!is_array($card) && is_array($runtime['cfg'] ?? null) && is_array($runtime['connection'] ?? null)) {
        try {
            $card = wb_price_tool_fetch_card_for_offer($runtime['cfg'], $runtime['connection'], $offer, $good, false);
        } catch (Throwable $cardLookupError) {
            $row['warnings'][] = 'Не удалось получить карточку WB для категории товара: ' . $cardLookupError->getMessage();
        }
    }
    $row['card'] = is_array($card) ? [
        'subject_id' => (int)($card['subjectID'] ?? 0),
        'subject_name' => (string)($card['subjectName'] ?? ''),
        'vendor_code' => (string)($card['vendorCode'] ?? ''),
    ] : null;
    $marketplaceExpenses = wb_price_tool_resolve_marketplace_expenses($settings, $good, $card, $runtime);
    $row['runtime_expenses'] = $marketplaceExpenses;
    foreach ((array)($marketplaceExpenses['warnings'] ?? []) as $warning) {
        $warning = trim((string)$warning);
        if ($warning !== '') {
            $row['warnings'][] = $warning;
        }
    }
    if (!empty($row['is_bad_turnover'])) {
        $row['warnings'][] = 'WB пометил товар как low turnover (`isBadTurnover`). Это может ухудшать видимость товара в выдаче.';
    }

    $targetProfitRub = wb_price_tool_target_profit_rub($settings, $purchaseCost, false);
    $minTargetProfitRub = wb_price_tool_target_profit_rub($settings, $purchaseCost, true);
    $solved = wb_price_tool_solve_sale_price($settings, $purchaseCost, $targetProfitRub, $marketplaceExpenses);
    $minSolved = wb_price_tool_solve_sale_price($settings, $purchaseCost, $minTargetProfitRub, $marketplaceExpenses);
    if (!is_array($solved) || !is_array($minSolved)) {
        $row['warnings'][] = 'Не удалось подобрать цену с заданной целевой прибылью.';
        $row['status'] = 'warn';
        return $row;
    }

    if ((float)($marketplaceExpenses['commission_percent'] ?? 0) <= 0.0) {
        $row['warnings'][] = 'Комиссия WB не найдена: расчет цены остановлен, чтобы не отправить ложную цену.';
        $row['status'] = 'warn';
        return $row;
    }
    if ((float)($marketplaceExpenses['delivery_rub'] ?? 0) <= 0.0) {
        $row['warnings'][] = 'Логистика WB не найдена: расчет цены остановлен, чтобы не отправить ложную цену.';
        $row['status'] = 'warn';
        return $row;
    }

    $baseEffectiveSalePrice = ozon_price_apply_modifier((float)($solved['sale_price'] ?? 0), $settings, false);
    $minEffectiveSalePrice = ozon_price_apply_modifier((float)($minSolved['sale_price'] ?? 0), $settings, true);
    $supplierPriceModifier = trim((string)($offer['supplier_price_modifier'] ?? ''));
    if ($supplierPriceModifier !== '' && function_exists('supplier_products_apply_price_modifier')) {
        $baseEffectiveSalePrice = supplier_products_apply_price_modifier((float)$baseEffectiveSalePrice, $supplierPriceModifier) ?? $baseEffectiveSalePrice;
        $minEffectiveSalePrice = supplier_products_apply_price_modifier((float)$minEffectiveSalePrice, $supplierPriceModifier) ?? $minEffectiveSalePrice;
    }
    $baseEffectiveSalePrice = ozon_price_round((float)$baseEffectiveSalePrice, (string)($settings['rounding_mode'] ?? 'rub'));
    $minEffectiveSalePrice = ozon_price_round((float)$minEffectiveSalePrice, (string)($settings['rounding_mode'] ?? 'rub'));
    if ($minEffectiveSalePrice > $baseEffectiveSalePrice) {
        $row['warnings'][] = 'Минимальная цена WB получилась выше базовой: проверь проценты доходности, расчёт использует минимум как базовую цену.';
        $baseEffectiveSalePrice = $minEffectiveSalePrice;
    }

    $fboForceRule = null;
    $fboForcedEffectiveSalePrice = 0.0;
    $fboPricingMode = '';
    try {
        $runtimeCfgForFbo = is_array($runtime['cfg'] ?? null) ? (array)$runtime['cfg'] : [];
        $connectionForFbo = is_array($runtime['connection'] ?? null) ? (array)$runtime['connection'] : [];
        $connectionIdForFbo = (int)($settings['connection_id'] ?? ($connectionForFbo['id'] ?? 0));
        if ($connectionIdForFbo > 0 && $offerId !== '') {
            require_once __DIR__ . '/../ozon_fbo_tool.php';
            if (function_exists('ozon_fbo_tool_active_force_rule_for_offer')) {
                $fboForceRule = ozon_fbo_tool_active_force_rule_for_offer($connectionIdForFbo, $offerId, $runtimeCfgForFbo);
            }
        }
    } catch (Throwable $fboRuleError) {
        $fboForceRule = null;
    }
    if (is_array($fboForceRule) && (float)($fboForceRule['value'] ?? 0) > 0) {
        $fboForcedEffectiveSalePrice = round((float)$fboForceRule['value'], 2);
        $fboPricingMode = (string)($fboForceRule['mode'] ?? '') === 'promotion_floor' ? 'promotion_floor' : 'exact';
    }

    $clubDiscountPercent = wb_price_tool_normalize_discount_percent($settings['wb_club_discount_percent'] ?? 0, 31.0, true);
    $clubFactor = max(0.01, 1.0 - ($clubDiscountPercent / 100.0));
    $promotionReservePercent = max(0.0, min(50.0, (float)($settings['wb_promotion_min_margin_percent'] ?? 5.0)));
    $promotionTargetEffectiveSalePrice = ozon_price_round(
        $minEffectiveSalePrice * (1.0 - ($promotionReservePercent / 100.0)),
        (string)($settings['rounding_mode'] ?? 'rub')
    );
    if ($fboPricingMode === 'promotion_floor' && $fboForcedEffectiveSalePrice > 0.0) {
        $promotionTargetEffectiveSalePrice = min($minEffectiveSalePrice, $fboForcedEffectiveSalePrice);
    }
    $promotionMarginPercent = $minEffectiveSalePrice > 0.0
        ? max(0.0, (1.0 - ($promotionTargetEffectiveSalePrice / $minEffectiveSalePrice)) * 100.0)
        : $promotionReservePercent;
    $promotionDecision = [
        'status' => 'disabled',
        'row' => null,
        'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
        'target_effective_sale_price' => round($promotionTargetEffectiveSalePrice, 2),
    ];
    $promotionMaxSalePrice = 0.0;
    $selectedEffectiveSalePrice = $baseEffectiveSalePrice;
    $baseEffectiveSalePriceBeforeFuturePromo = $baseEffectiveSalePrice;
    $futurePrepareDays = max(0, min(60, (int)($settings['wb_future_promo_prepare_days'] ?? 7)));
    $futurePromoDiscountInfo = [
        'mode' => 'auto',
        'discount_percent' => 0.0,
        'base_discount_percent' => 0.0,
        'buffer_percent' => 0.0,
        'sample_size' => 0,
        'source' => 'disabled',
        'percentile' => 0,
        'min_seen_percent' => 0.0,
        'p50_percent' => 0.0,
        'p75_percent' => 0.0,
        'p85_percent' => 0.0,
        'max_seen_percent' => 0.0,
        'product_sample_size' => 0,
        'subject_sample_size' => 0,
        'connection_sample_size' => 0,
        'subject' => '',
        'confidence' => 'none',
    ];
    $futurePromoRequiredListPrice = null;
    $futurePromoRequiredEffectiveSalePrice = null;
    if (!empty($settings['wb_promotion_pricing_enabled'])) {
        $futureMode = strtolower(trim((string)($settings['wb_future_promo_discount_mode'] ?? 'auto')));
        if (!in_array($futureMode, ['auto', 'manual'], true)) {
            $futureMode = 'auto';
        }
        $futurePromoDiscountInfo['mode'] = $futureMode;
        if ($futureMode === 'manual') {
            $manualDiscount = max(0.0, min(85.0, (float)($settings['wb_future_promo_discount_percent'] ?? 0)));
            $futurePromoDiscountInfo = array_replace($futurePromoDiscountInfo, [
                'discount_percent' => round($manualDiscount, 2),
                'base_discount_percent' => round($manualDiscount, 2),
                'buffer_percent' => 0.0,
                'sample_size' => 0,
                'source' => 'manual',
                'percentile' => 0,
                'max_seen_percent' => round($manualDiscount, 2),
            ]);
        } else {
            $connectionId = (int)($settings['connection_id'] ?? 0);
            $futurePromoDiscountInfo = array_replace(
                $futurePromoDiscountInfo,
                wb_promotions_expected_auto_discount(
                    $connectionId,
                    (int)$row['nm_id'],
                    (float)($settings['wb_future_promo_discount_buffer_percent'] ?? 2.0),
                    [
                        'subject' => (string)((is_array($row['card'] ?? null) ? $row['card'] : [])['subject_name'] ?? ''),
                        'days_back' => 180,
                        'days_ahead' => max(60, $futurePrepareDays),
                    ]
                )
            );
            $futurePromoDiscountInfo['mode'] = 'auto';
        }

        $futureDiscountPercent = max(0.0, min(85.0, (float)($futurePromoDiscountInfo['discount_percent'] ?? 0)));
        if ($futureDiscountPercent > 0.0) {
            $futurePromoFactor = max(0.01, 1.0 - ($futureDiscountPercent / 100.0));
            $futurePromoRequiredNormalSalePrice = $promotionTargetEffectiveSalePrice / $clubFactor;
            $futurePromoRequiredListPrice = $futurePromoRequiredNormalSalePrice / $futurePromoFactor;
            $configuredDiscount = (int)round(wb_price_tool_normalize_discount_percent($settings['wb_discount_percent'] ?? 0, 99.0, false));
            $configuredDiscountFactor = max(0.01, 1.0 - ($configuredDiscount / 100.0));
            $futurePromoRequiredEffectiveSalePrice = ozon_price_round(
                $futurePromoRequiredListPrice * $configuredDiscountFactor * $clubFactor,
                (string)($settings['rounding_mode'] ?? 'rub')
            );
        }
    }
    $baseDesiredState = wb_price_tool_build_desired_state($settings, [
        'nm_id' => $row['nm_id'],
        'recommended_effective_sale_price' => $baseEffectiveSalePrice,
    ]);
    $baseListPrice = $baseDesiredState !== null ? (float)($baseDesiredState['target_list_price'] ?? 0) : 0.0;
    $minDesiredState = wb_price_tool_build_desired_state($settings, [
        'nm_id' => $row['nm_id'],
        'recommended_effective_sale_price' => $minEffectiveSalePrice,
        'fixed_list_price' => $baseListPrice,
    ]);
    $baseNormalSalePrice = $baseDesiredState !== null
        ? (float)($baseDesiredState['target_sale_price'] ?? ($baseEffectiveSalePrice / $clubFactor))
        : ($baseEffectiveSalePrice / $clubFactor);
    $desiredState = $baseDesiredState;
    if (!empty($settings['wb_promotion_pricing_enabled'])) {
        $connectionId = (int)($settings['connection_id'] ?? 0);
        $maxPlanDiscount = max(0.0, min(99.0, (float)($settings['wb_promotion_max_plan_discount_percent'] ?? 60)));
        $promotionDecision = wb_promotions_pricing_decision(
            $connectionId,
            (int)$row['nm_id'],
            $maxPlanDiscount,
            [],
            $minEffectiveSalePrice,
            $clubDiscountPercent,
            $promotionTargetEffectiveSalePrice,
            $futurePrepareDays
        );
        $promotionRow = is_array($promotionDecision['row'] ?? null) ? (array)$promotionDecision['row'] : null;
        $promotionStatus = (string)($promotionDecision['status'] ?? '');
        if ((int)($promotionDecision['stale_auto_count'] ?? 0) > 0) {
            $row['warnings'][] = sprintf(
                'WB автоакции: %d активных строк с плановыми ценами старше 30 часов пропущены, чтобы не использовать данные, которые не обновились после очередной ночной синхронизации.',
                (int)$promotionDecision['stale_auto_count']
            );
        }
        if (in_array($promotionStatus, ['selected', 'future_selected'], true) && $promotionRow !== null) {
            $promotionMaxSalePrice = max(0.0, (float)($promotionRow['plan_price'] ?? 0));
            $promotionPlanEffectiveSalePrice = max(0.0, (float)($promotionDecision['row_plan_effective_price'] ?? 0));
            $selectedEffectiveSalePrice = $minEffectiveSalePrice > 0.0
                ? min($baseEffectiveSalePrice, $minEffectiveSalePrice)
                : $baseEffectiveSalePrice;
            if ($promotionPlanEffectiveSalePrice > 0.0) {
                $selectedEffectiveSalePrice = min($selectedEffectiveSalePrice, $promotionPlanEffectiveSalePrice);
            }
            if ($promotionTargetEffectiveSalePrice > 0.0) {
                $selectedEffectiveSalePrice = max($selectedEffectiveSalePrice, $promotionTargetEffectiveSalePrice);
            }
            $selectedEffectiveSalePrice = ozon_price_round((float)$selectedEffectiveSalePrice, (string)($settings['rounding_mode'] ?? 'rub'));
            $candidateDesiredState = null;
            for ($roundingBumpRub = 0; $roundingBumpRub <= 5; $roundingBumpRub++) {
                $candidateEffectiveSalePrice = $selectedEffectiveSalePrice + $roundingBumpRub;
                if ($promotionPlanEffectiveSalePrice > 0.0) {
                    $candidateEffectiveSalePrice = min($candidateEffectiveSalePrice, $promotionPlanEffectiveSalePrice);
                }
                $candidateDesiredState = wb_price_tool_build_desired_state($settings, [
                    'nm_id' => $row['nm_id'],
                    'recommended_effective_sale_price' => $candidateEffectiveSalePrice,
                    'max_sale_price' => $promotionMaxSalePrice,
                ]);
                if (
                    $candidateDesiredState !== null
                    && (float)($candidateDesiredState['target_effective_sale_price'] ?? 0) + 0.01 >= $promotionTargetEffectiveSalePrice
                ) {
                    $selectedEffectiveSalePrice = (float)($candidateDesiredState['target_effective_sale_price'] ?? $candidateEffectiveSalePrice);
                    break;
                }
            }
            if ($candidateDesiredState !== null && (float)($candidateDesiredState['target_effective_sale_price'] ?? 0) + 0.01 >= $promotionTargetEffectiveSalePrice) {
                $desiredState = $candidateDesiredState;
            } else {
                $promotionDecision['status'] = 'blocked_discount_rounding_below_min_price';
                $row['warnings'][] = sprintf(
                    'WB акция "%s" требует цену %.2f ₽, но при фиксированной базовой цене %.2f ₽ целая скидка продавца опускает фактическую цену ниже нижней границы %.2f ₽; расчёт оставил базовую цену.',
                    (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                    $promotionMaxSalePrice,
                    $baseListPrice,
                    $promotionTargetEffectiveSalePrice
                );
                $promotionMaxSalePrice = 0.0;
                $selectedEffectiveSalePrice = $baseEffectiveSalePrice;
            }
            if (in_array((string)($promotionDecision['status'] ?? ''), ['selected', 'future_selected'], true) && !empty($promotionDecision['over_discount_limit'])) {
                $row['warnings'][] = sprintf(
                    'WB акция "%s" указала плановую скидку %.2f%%, выше контрольного порога %.2f%%; акция всё равно выбрана, потому что плановая цена не ниже нижней границы с запасом. Фактическая скидка продавца считается от нашей фиксированной базовой цены.',
                    (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                    (float)($promotionRow['plan_discount'] ?? 0),
                    $maxPlanDiscount
                );
            }
        } elseif (($promotionDecision['status'] ?? '') === 'blocked_below_target_margin' && $promotionRow !== null) {
            $row['warnings'][] = sprintf(
                'WB акция "%s" была заблокирована старым правилом запаса: плановая цена %.2f ₽, min price %.2f ₽, прежний порог %.2f ₽.',
                (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                (float)($promotionDecision['row_plan_effective_price'] ?? ($promotionRow['plan_price'] ?? 0)),
                $minEffectiveSalePrice,
                $promotionTargetEffectiveSalePrice
            );
        } elseif (($promotionDecision['status'] ?? '') === 'blocked_below_min_price' && $promotionRow !== null) {
            $blockedPlanEffectivePrice = (float)($promotionDecision['row_plan_effective_price'] ?? ($promotionRow['plan_price'] ?? 0));
            $fallbackDesiredState = null;
            if ($fboPricingMode !== 'promotion_floor' && $promotionTargetEffectiveSalePrice > 0.0) {
                $fallbackDesiredState = wb_price_tool_build_desired_state($settings, [
                    'nm_id' => $row['nm_id'],
                    'recommended_effective_sale_price' => $promotionTargetEffectiveSalePrice,
                ]);
            }
            if (
                $fallbackDesiredState !== null
                && (float)($fallbackDesiredState['target_effective_sale_price'] ?? 0) > 0.0
                && (float)($fallbackDesiredState['target_effective_sale_price'] ?? 0) + 0.01 < (float)($desiredState['target_effective_sale_price'] ?? $baseEffectiveSalePrice)
            ) {
                $desiredState = $fallbackDesiredState;
                $desiredState['promotion_action'] = 'near_min_price';
                $selectedEffectiveSalePrice = (float)($fallbackDesiredState['target_effective_sale_price'] ?? $promotionTargetEffectiveSalePrice);
                $promotionDecision['action'] = 'near_min_price';
                $promotionDecision['fallback_effective_sale_price'] = round($selectedEffectiveSalePrice, 2);
                $row['warnings'][] = sprintf(
                    'WB акция "%s" даёт плановую цену %.2f ₽, ниже нижней границы с запасом %.2f ₽; товар не проходит в акцию, поэтому цена снижена до %.2f ₽ вместо базовой %.2f ₽.',
                    (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                    $blockedPlanEffectivePrice,
                    $promotionTargetEffectiveSalePrice,
                    $selectedEffectiveSalePrice,
                    (float)($baseDesiredState['target_effective_sale_price'] ?? $baseEffectiveSalePrice)
                );
            } else {
                $row['warnings'][] = sprintf(
                    'WB акция "%s" даёт плановую цену %.2f ₽, ниже нижней границы с запасом %.2f ₽; расчёт оставил базовую цену.',
                    (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                    $blockedPlanEffectivePrice,
                    $promotionTargetEffectiveSalePrice
                );
            }
        } elseif (($promotionDecision['status'] ?? '') === 'future_available' && $promotionRow !== null) {
            $row['warnings'][] = sprintf(
                'WB акция "%s" ещё не началась%s: товар пока не может участвовать в ней, поэтому текущая цена не ограничивается её плановой ценой. Подготовка к будущим автоакциям учитывается отдельным прогнозом скидки.',
                (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                trim((string)($promotionRow['start_datetime'] ?? '')) !== '' ? ' (старт ' . (string)$promotionRow['start_datetime'] . ')' : ''
            );
        }
    }

    if ($fboPricingMode === 'exact' && $fboForcedEffectiveSalePrice > 0.0) {
        $fboDesiredState = wb_price_tool_build_desired_state($settings, [
            'nm_id' => $row['nm_id'],
            'recommended_effective_sale_price' => $fboForcedEffectiveSalePrice,
        ]);
        if ($fboDesiredState !== null) {
            $desiredState = $fboDesiredState;
            $selectedEffectiveSalePrice = (float)($fboDesiredState['target_effective_sale_price'] ?? $fboForcedEffectiveSalePrice);
            $promotionMaxSalePrice = 0.0;
            $promotionDecision = [
                'status' => 'fbo_override',
                'row' => null,
                'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
                'target_effective_sale_price' => round($fboForcedEffectiveSalePrice, 2),
            ];
            $row['warnings'][] = 'WB FBO Tool удерживает сниженную цену, пока есть остаток на выбранном складе WB.';
        }
    } elseif ($fboPricingMode === 'promotion_floor' && $fboForcedEffectiveSalePrice > 0.0) {
        if (!empty($settings['wb_promotion_pricing_enabled'])) {
            $row['warnings'][] = sprintf(
                'WB FBO Tool разрешает участие в акции до %.2f ₽, пока есть остаток на складе WB; вне подходящей акции действует обычная цена Price Tool.',
                $promotionTargetEffectiveSalePrice
            );
        } else {
            $row['warnings'][] = 'Для FBO-минимума акции включи «Подготовку цен для автоакций WB» в профиле Price Tool; до этого действует обычная цена.';
        }
    }
    $currentEffectiveSalePrice = 0.0;
    if ((float)($row['current_club_discounted_price'] ?? 0) > 0.0) {
        $currentEffectiveSalePrice = (float)$row['current_club_discounted_price'];
    } elseif ((float)($row['current_discounted_price'] ?? 0) > 0.0) {
        $currentEffectiveSalePrice = (float)$row['current_discounted_price'];
    }
    if (
        $fboPricingMode !== 'exact'
        && $currentEffectiveSalePrice > 0.0
        && $promotionTargetEffectiveSalePrice > 0.0
        && $currentEffectiveSalePrice + 0.01 < $promotionTargetEffectiveSalePrice
        && $baseDesiredState !== null
    ) {
        $desiredState = $baseDesiredState;
        $selectedEffectiveSalePrice = (float)($baseDesiredState['target_effective_sale_price'] ?? $baseEffectiveSalePrice);
        $promotionMaxSalePrice = 0.0;
        $promotionDecision['status'] = 'blocked_current_price_below_floor';
        $promotionDecision['reason'] = 'Текущая фактическая цена WB ниже нижней границы с запасом.';
        $row['warnings'][] = sprintf(
            'Текущая фактическая цена WB %.2f ₽ ниже нижней границы с запасом %.2f ₽; Price Tool игнорирует акционное ограничение и возвращает базовую безопасную цену %.2f ₽.',
            $currentEffectiveSalePrice,
            $promotionTargetEffectiveSalePrice,
            $selectedEffectiveSalePrice
        );
    }

    if ($desiredState === null) {
        $row['warnings'][] = 'Не удалось собрать цену и скидки для отправки в WB.';
        $row['status'] = 'warn';
        return $row;
    }
    $actualEffectiveSalePrice = (float)($desiredState['target_effective_sale_price'] ?? $selectedEffectiveSalePrice);
    $snapshot = wb_price_tool_profit_snapshot($settings, $purchaseCost, $actualEffectiveSalePrice, $marketplaceExpenses);

    $row['recommended_sale_price'] = (float)($desiredState['target_sale_price'] ?? 0.0);
    $row['recommended_club_sale_price'] = (float)($desiredState['target_club_sale_price'] ?? 0.0);
    $row['recommended_effective_sale_price'] = $actualEffectiveSalePrice;
    $row['recommended_list_price'] = (float)($desiredState['target_list_price'] ?? 0.0);
    $row['recommended_discount'] = isset($desiredState['discount']) ? (float)$desiredState['discount'] : null;
    $row['recommended_club_discount'] = isset($desiredState['club_discount']) ? (float)$desiredState['club_discount'] : null;
    if ($baseDesiredState !== null) {
        $row['recommended_base_sale_price'] = (float)($baseDesiredState['target_sale_price'] ?? 0.0);
        $row['recommended_base_club_sale_price'] = (float)($baseDesiredState['target_club_sale_price'] ?? 0.0);
        $row['recommended_base_effective_sale_price'] = (float)($baseDesiredState['target_effective_sale_price'] ?? $baseEffectiveSalePrice);
        $row['recommended_base_list_price'] = (float)($baseDesiredState['target_list_price'] ?? 0.0);
        $row['recommended_base_discount'] = isset($baseDesiredState['discount']) ? (float)$baseDesiredState['discount'] : null;
        $row['recommended_base_club_discount'] = isset($baseDesiredState['club_discount']) ? (float)$baseDesiredState['club_discount'] : null;
    }
    $row['recommended_base_effective_sale_price_before_future_promo'] = round($baseEffectiveSalePriceBeforeFuturePromo, 2);
    $row['recommended_min_effective_sale_price'] = $minDesiredState !== null
        ? (float)($minDesiredState['target_effective_sale_price'] ?? $minEffectiveSalePrice)
        : $minEffectiveSalePrice;
    $row['recommended_min_sale_price'] = $minDesiredState !== null
        ? (float)($minDesiredState['target_sale_price'] ?? round($minEffectiveSalePrice / $clubFactor, 2))
        : round($minEffectiveSalePrice / $clubFactor, 2);
    $row['recommended_min_club_sale_price'] = $minDesiredState !== null
        ? (float)($minDesiredState['target_club_sale_price'] ?? ($clubDiscountPercent > 0 ? round($minEffectiveSalePrice, 2) : $row['recommended_min_sale_price']))
        : ($clubDiscountPercent > 0 ? round($minEffectiveSalePrice, 2) : $row['recommended_min_sale_price']);
    $row['recommended_min_list_price'] = $minDesiredState !== null ? (float)($minDesiredState['target_list_price'] ?? 0.0) : null;
    $row['recommended_min_discount'] = $minDesiredState !== null && isset($minDesiredState['discount']) ? (float)$minDesiredState['discount'] : null;
    $row['recommended_min_club_discount'] = $minDesiredState !== null && isset($minDesiredState['club_discount']) ? (float)$minDesiredState['club_discount'] : null;
    $row['profit_rub'] = (float)($snapshot['profit_rub'] ?? 0.0);
    $row['profit_percent'] = (float)($snapshot['profit_on_cost_percent'] ?? 0.0);
    $row['desired_state'] = $desiredState;
    if (in_array((string)($promotionDecision['status'] ?? ''), ['selected', 'future_selected'], true) && is_array($promotionDecision['row'] ?? null)) {
        $promotionRow = (array)$promotionDecision['row'];
        $promotionType = (string)($promotionRow['promotion_type'] ?? '');
        $promotionSourceType = (string)($promotionRow['source_type'] ?? '');
        $promotionTiming = (string)($promotionRow['promotion_timing'] ?? ($promotionDecision['promotion_timing'] ?? 'active'));
        $promotionUploadEnabled = !empty($settings['wb_promotion_action_upload_enabled']);
        $row['desired_state']['promotion_id'] = (int)($promotionRow['promotion_id'] ?? 0);
        $row['desired_state']['promotion_name'] = (string)($promotionRow['promotion_name'] ?? '');
        $row['desired_state']['promotion_type'] = $promotionType;
        $row['desired_state']['promotion_timing'] = $promotionTiming;
        $row['desired_state']['promotion_start_datetime'] = (string)($promotionRow['start_datetime'] ?? '');
        $row['desired_state']['promotion_end_datetime'] = (string)($promotionRow['end_datetime'] ?? '');
        $row['desired_state']['promotion_source_type'] = $promotionSourceType;
        $row['desired_state']['promotion_plan_discount'] = round((float)($promotionRow['plan_discount'] ?? 0), 2);
        $row['desired_state']['promotion_plan_price_before_recalc'] = round((float)($promotionRow['plan_price'] ?? 0), 2);
        $row['desired_state']['promotion_plan_effective_sale_price'] = round((float)($promotionDecision['row_plan_effective_price'] ?? ($promotionRow['plan_price'] ?? 0)), 2);
        $row['desired_state']['promotion_target_effective_sale_price'] = round($promotionTargetEffectiveSalePrice, 2);
        $row['desired_state']['promotion_min_effective_sale_price'] = round($minEffectiveSalePrice, 2);
        $row['desired_state']['promotion_action'] = (
            $promotionUploadEnabled
            && $promotionType !== 'auto'
            && $promotionSourceType === 'candidate'
            && (int)($row['desired_state']['promotion_id'] ?? 0) > 0
        ) ? 'upload_to_promotion' : 'price_only';
        if ($promotionTiming === 'future') {
            $row['warnings'][] = sprintf(
                'Расчёт WB готовит товар к будущей акции "%s" с %s: базовая цена %.2f ₽ остаётся фиксированной, скидка продавца %.0f%% даёт цену %.2f ₽ при плановой цене акции %.2f ₽.',
                (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                (string)($promotionRow['start_datetime'] ?? 'будущей даты'),
                (float)($row['desired_state']['target_list_price'] ?? 0),
                (float)($row['desired_state']['discount'] ?? 0),
                (float)($row['desired_state']['target_sale_price'] ?? 0),
                (float)($promotionRow['plan_price'] ?? 0)
            );
        } else {
            $row['warnings'][] = sprintf(
                'Расчёт WB выбрал акцию "%s": базовая цена %.2f ₽ остаётся фиксированной, скидка продавца %.0f%% даёт цену %.2f ₽ при плановой цене акции %.2f ₽.',
                (string)($promotionRow['promotion_name'] ?? ('#' . (string)($promotionRow['promotion_id'] ?? ''))),
                (float)($row['desired_state']['target_list_price'] ?? 0),
                (float)($row['desired_state']['discount'] ?? 0),
                (float)($row['desired_state']['target_sale_price'] ?? 0),
                (float)($promotionRow['plan_price'] ?? 0)
            );
        }
        if (!empty($row['desired_state']['price_capped_by_promotion'])) {
            $row['warnings'][] = sprintf(
                'Цена продажи ограничена плановой ценой акции: %.2f ₽.',
                (float)($promotionRow['plan_price'] ?? 0)
            );
        }
        if (!empty($promotionDecision['below_min_within_reserve']) || !empty($promotionDecision['below_target_margin'])) {
            $row['warnings'][] = sprintf(
                'Плановая цена акции ниже min price %.2f ₽, но не ниже нижней границы с запасом %.2f ₽; цена применена в рамках допустимого запаса.',
                $minEffectiveSalePrice,
                $promotionTargetEffectiveSalePrice
            );
        }
        if (($row['desired_state']['promotion_action'] ?? '') === 'upload_to_promotion') {
            $row['warnings'][] = 'После установки цены сервис отправит товар в выбранную обычную акцию WB через API.';
        } elseif ($promotionType === 'auto') {
            $row['warnings'][] = $promotionTiming === 'future'
                ? 'Это будущая автоакция WB: явного добавления через API нет, сервис не поднимает текущую цену заранее и использует её только как плановый ориентир.'
                : 'Это автоакция WB: явного добавления через API нет, участие управляется ценой и скидкой продавца.';
        }
    }
    if (is_array($row['desired_state'])) {
        $row['desired_state']['current_price'] = $row['current_price'] !== null ? (int)round((float)$row['current_price']) : null;
        $row['desired_state']['current_discount'] = $row['current_discount'] !== null ? (int)round((float)$row['current_discount']) : null;
        $row['desired_state']['current_club_discount'] = $row['current_club_discount'] !== null ? (int)round((float)$row['current_club_discount']) : null;
    }
    $row['breakdown'] = is_array($snapshot['breakdown'] ?? null) ? $snapshot['breakdown'] : [];
    $row['breakdown']['target_profit_rub'] = round($targetProfitRub, 2);
    $row['breakdown']['target_min_profit_rub'] = round($minTargetProfitRub, 2);
    $row['breakdown']['target_profit_percent_effective'] = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, false);
    $row['breakdown']['target_min_profit_percent_effective'] = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, true);
    $row['breakdown']['target_profit_min_rub'] = function_exists('ozon_price_resolve_target_profit_min_rub')
        ? round(ozon_price_resolve_target_profit_min_rub($settings, false), 2)
        : round(max(0.0, (float)($settings['target_profit_min_rub'] ?? 0)), 2);
    $row['breakdown']['target_min_profit_min_rub'] = function_exists('ozon_price_resolve_target_profit_min_rub')
        ? round(ozon_price_resolve_target_profit_min_rub($settings, true), 2)
        : round(max(0.0, (float)($settings['min_target_profit_min_rub'] ?? 0)), 2);
    $row['breakdown']['api_expenses_enabled'] = wb_price_tool_runtime_enabled($settings);
    $row['breakdown']['discount_percent_configured'] = wb_price_tool_normalize_discount_percent($settings['wb_discount_percent'] ?? 0, 99.0, false);
    $row['breakdown']['discount_percent_applied'] = isset($desiredState['discount']) ? round((float)$desiredState['discount'], 2) : wb_price_tool_normalize_discount_percent($settings['wb_discount_percent'] ?? 0, 99.0, false);
    $row['breakdown']['discount_source'] = (string)($desiredState['discount_source'] ?? 'configured');
    $row['breakdown']['fixed_base_price'] = isset($desiredState['fixed_base_price']) ? $desiredState['fixed_base_price'] : null;
    $row['breakdown']['club_discount_percent_configured'] = wb_price_tool_normalize_discount_percent($settings['wb_club_discount_percent'] ?? 0, 31.0, true);
    $row['breakdown']['promotion_pricing_enabled'] = !empty($settings['wb_promotion_pricing_enabled']) ? 1 : 0;
    $row['breakdown']['promotion_pricing_status'] = (string)($promotionDecision['status'] ?? 'disabled');
    $row['breakdown']['promotion_max_plan_discount_percent'] = round((float)($settings['wb_promotion_max_plan_discount_percent'] ?? 60), 2);
    $row['breakdown']['promotion_min_margin_percent'] = round($promotionMarginPercent, 2);
    $row['breakdown']['promotion_reserve_percent'] = round($promotionReservePercent, 2);
    $row['breakdown']['promotion_target_effective_sale_price'] = round($promotionTargetEffectiveSalePrice, 2);
    $row['breakdown']['promotion_floor_effective_sale_price'] = round($promotionTargetEffectiveSalePrice, 2);
    $row['breakdown']['promotion_max_sale_price'] = $promotionMaxSalePrice > 0 ? round($promotionMaxSalePrice, 2) : null;
    $row['breakdown']['promotion_all_count'] = (int)($promotionDecision['all_count'] ?? 0);
    $row['breakdown']['promotion_active_count'] = (int)($promotionDecision['active_count'] ?? 0);
    $row['breakdown']['promotion_future_count'] = (int)($promotionDecision['future_count'] ?? 0);
    $row['breakdown']['promotion_future_prepare_days'] = $futurePrepareDays;
    $row['breakdown']['promotion_timing'] = (string)($promotionDecision['promotion_timing'] ?? ((is_array($promotionDecision['row'] ?? null) ? (string)(($promotionDecision['row']['promotion_timing'] ?? '')) : '')));
    if (is_array($promotionDecision['row'] ?? null)) {
        $promotionDecisionRow = (array)$promotionDecision['row'];
        $row['breakdown']['promotion_candidate_id'] = (int)($promotionDecisionRow['promotion_id'] ?? 0);
        $row['breakdown']['promotion_candidate_name'] = (string)($promotionDecisionRow['promotion_name'] ?? '');
        $row['breakdown']['promotion_candidate_start_datetime'] = (string)($promotionDecisionRow['start_datetime'] ?? '');
        $row['breakdown']['promotion_candidate_end_datetime'] = (string)($promotionDecisionRow['end_datetime'] ?? '');
    }
    $row['breakdown']['promotion_eligible_count'] = (int)($promotionDecision['eligible_count'] ?? 0);
    $row['breakdown']['promotion_safe_below_target_count'] = (int)($promotionDecision['safe_but_below_target_count'] ?? 0);
    $row['breakdown']['promotion_unsafe_count'] = (int)($promotionDecision['unsafe_count'] ?? 0);
    $row['breakdown']['promotion_over_discount_limit'] = !empty($promotionDecision['over_discount_limit']) ? 1 : 0;
    $row['breakdown']['promotion_selected_plan_effective_price'] = isset($promotionDecision['row_plan_effective_price'])
        ? round((float)$promotionDecision['row_plan_effective_price'], 2)
        : null;
    $row['breakdown']['promotion_price_capped'] = !empty($desiredState['price_capped_by_promotion']) ? 1 : 0;
    $row['breakdown']['recommended_base_effective_sale_price'] = round($baseEffectiveSalePrice, 2);
    $row['breakdown']['recommended_base_effective_sale_price_before_future_promo'] = round($baseEffectiveSalePriceBeforeFuturePromo, 2);
    $row['breakdown']['future_promo_discount_mode'] = (string)($futurePromoDiscountInfo['mode'] ?? 'auto');
    $row['breakdown']['future_promo_discount_source'] = (string)($futurePromoDiscountInfo['source'] ?? 'none');
    $row['breakdown']['future_promo_discount_percent'] = round((float)($futurePromoDiscountInfo['discount_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_base_percent'] = round((float)($futurePromoDiscountInfo['base_discount_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_buffer_percent'] = round((float)($futurePromoDiscountInfo['buffer_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_sample_size'] = (int)($futurePromoDiscountInfo['sample_size'] ?? 0);
    $row['breakdown']['future_promo_discount_percentile'] = (int)($futurePromoDiscountInfo['percentile'] ?? 0);
    $row['breakdown']['future_promo_discount_confidence'] = (string)($futurePromoDiscountInfo['confidence'] ?? 'none');
    $row['breakdown']['future_promo_discount_subject'] = (string)($futurePromoDiscountInfo['subject'] ?? '');
    $row['breakdown']['future_promo_discount_product_sample_size'] = (int)($futurePromoDiscountInfo['product_sample_size'] ?? 0);
    $row['breakdown']['future_promo_discount_subject_sample_size'] = (int)($futurePromoDiscountInfo['subject_sample_size'] ?? 0);
    $row['breakdown']['future_promo_discount_connection_sample_size'] = (int)($futurePromoDiscountInfo['connection_sample_size'] ?? 0);
    $row['breakdown']['future_promo_discount_min_seen_percent'] = round((float)($futurePromoDiscountInfo['min_seen_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_p50_percent'] = round((float)($futurePromoDiscountInfo['p50_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_p75_percent'] = round((float)($futurePromoDiscountInfo['p75_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_p85_percent'] = round((float)($futurePromoDiscountInfo['p85_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_discount_max_seen_percent'] = round((float)($futurePromoDiscountInfo['max_seen_percent'] ?? 0), 2);
    $row['breakdown']['future_promo_required_list_price'] = $futurePromoRequiredListPrice !== null ? round((float)$futurePromoRequiredListPrice, 2) : null;
    $row['breakdown']['future_promo_required_effective_sale_price'] = $futurePromoRequiredEffectiveSalePrice !== null ? round((float)$futurePromoRequiredEffectiveSalePrice, 2) : null;
    $row['breakdown']['future_promo_base_lift_applied'] = $baseEffectiveSalePrice > $baseEffectiveSalePriceBeforeFuturePromo ? 1 : 0;
    $row['breakdown']['recommended_min_effective_sale_price'] = round($minEffectiveSalePrice, 2);
    $row['breakdown']['recommended_min_sale_price'] = round($minEffectiveSalePrice / $clubFactor, 2);
    $row['breakdown']['supplier_price_modifier'] = $supplierPriceModifier;
    $row['breakdown']['profit_price_basis'] = ((float)$row['breakdown']['club_discount_percent_configured'] > 0.0) ? 'wb_club_price' : 'sale_price';
    if (is_array($fboForceRule)) {
        $fboRule = is_array($fboForceRule['fbo_rule'] ?? null) ? (array)$fboForceRule['fbo_rule'] : [];
        $row['desired_state']['fbo_rule'] = 1;
        $row['desired_state']['fbo_rule_target_price'] = round($fboForcedEffectiveSalePrice, 2);
        $row['desired_state']['fbo_rule_pricing_mode'] = $fboPricingMode;
        $row['desired_state']['fbo_rule_warehouse_id'] = (string)($fboRule['warehouse_id'] ?? '');
        $row['desired_state']['fbo_rule_warehouse_name'] = (string)($fboRule['warehouse_name'] ?? '');
        $row['breakdown']['fbo_rule_active'] = 1;
        $row['breakdown']['fbo_rule_target_price'] = round($fboForcedEffectiveSalePrice, 2);
        $row['breakdown']['fbo_rule_pricing_mode'] = $fboPricingMode;
        $row['breakdown']['fbo_rule_units'] = (int)($fboForceRule['fbo_units'] ?? 0);
        $row['breakdown']['fbo_rule_warehouse_name'] = (string)($fboRule['warehouse_name'] ?? '');
    }
    $currentDiscountedPrice = (float)($row['current_discounted_price'] ?? 0);
    if (($row['breakdown']['include_returns_in_cost'] ?? 0) && (float)($row['breakdown']['return_tariff_rub'] ?? 0) <= 0) {
        $row['warnings'][] = 'Риски возвратов включены, но тариф возврата WB не найден. В расчёте учтены только ручные обработки и закупка для безвозвратных потерь.';
    }
    $row['promotion_decision'] = array_replace($promotionDecision, [
        'target_effective_sale_price' => round($promotionTargetEffectiveSalePrice, 2),
        'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
        'action' => is_array($row['desired_state'] ?? null) ? (string)($row['desired_state']['promotion_action'] ?? 'base_price') : 'base_price',
    ]);

    return $row;
}

function wb_price_tool_desired_state_needs_push(array $calc): bool
{
    $desiredState = is_array($calc['desired_state'] ?? null) ? $calc['desired_state'] : null;
    if ($desiredState === null) {
        return false;
    }
    if ((string)($desiredState['promotion_action'] ?? '') === 'upload_to_promotion') {
        return true;
    }
    $currentPrice = isset($calc['current_price']) ? (int)round((float)$calc['current_price']) : null;
    $currentDiscount = isset($calc['current_discount']) ? (int)round((float)$calc['current_discount']) : null;
    $currentClubDiscount = isset($calc['current_club_discount']) ? (int)round((float)$calc['current_club_discount']) : 0;
    return $currentPrice !== (int)($desiredState['price'] ?? 0)
        || $currentDiscount !== (int)($desiredState['discount'] ?? 0)
        || $currentClubDiscount !== (int)($desiredState['club_discount'] ?? 0);
}

function wb_price_tool_calculate_preview(array $settings, array $feedOffers, array $goodsIndex, ?array $runtime = null): array
{
    $rows = [];
    $stats = [
        'offers_total' => count($feedOffers),
        'matched' => 0,
        'ready' => 0,
        'warnings' => 0,
        'errors' => 0,
        'api_commission' => 0,
        'api_delivery' => 0,
    ];

    foreach ($feedOffers as $offer) {
        $good = wb_price_tool_find_good_for_offer($offer, $goodsIndex);
        $row = wb_price_tool_calculate_offer($settings, $offer, $good, $runtime);
        $rows[] = $row;
        if ($good !== null) {
            $stats['matched']++;
        }
        if ($row['desired_state'] !== null) {
            $stats['ready']++;
        }
        if (($row['breakdown']['commission_source'] ?? '') === 'api') {
            $stats['api_commission']++;
        }
        if (($row['breakdown']['delivery_source'] ?? '') === 'api') {
            $stats['api_delivery']++;
        }
        if ($row['status'] === 'warn') {
            $stats['warnings']++;
        } elseif ($row['status'] === 'error') {
            $stats['errors']++;
        }
    }

    return [
        'rows' => $rows,
        'stats' => $stats,
    ];
}

function wb_price_tool_find_offer_in_feed(array $feed, string $articleRaw): ?array
{
    $article = trim($articleRaw);
    if ($article === '') {
        return null;
    }
    $offers = wb_price_tool_load_feed_offers($feed);
    $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
    if (function_exists('ozon_price_feed_offers_by_id') && function_exists('ozon_price_feed_offer_for_requested_id')) {
        $feedMap = ozon_price_feed_offers_by_id($offers, $supplierCode, (int)($feed['supplier_id'] ?? 0));
        $requestedId = ozon_price_apply_supplier_code($article, $supplierCode);
        $derivedOffer = ozon_price_feed_offer_for_requested_id($feedMap, $requestedId);
        if (is_array($derivedOffer)) {
            return $derivedOffer;
        }
    }

    foreach ($offers as $offer) {
        foreach (wb_price_tool_offer_match_candidates($offer) as $candidate) {
            if ($candidate === $article || wb_price_tool_strip_supplier_suffix($candidate) === wb_price_tool_strip_supplier_suffix($article)) {
                return $offer;
            }
        }
    }
    return null;
}

function wb_price_tool_upload_wait(WildberriesClient $client, int $uploadId, int $maxSeconds = 8): array
{
    $deadline = time() + max(1, $maxSeconds);
    $lastState = [];
    while (time() <= $deadline) {
        try {
            $state = $client->getProcessedUploadState($uploadId);
            $data = is_array($state['data'] ?? null) ? $state['data'] : [];
            $status = (int)($data['status'] ?? 0);
            if ($status > 0) {
                $lastState = $state;
                if (in_array($status, [3, 4, 5, 6], true)) {
                    return ['phase' => 'processed', 'state' => $state];
                }
            }
        } catch (Throwable $e) {
            // Ignore while the task is still moving between queues.
        }

        try {
            $state = $client->getUnprocessedUploadState($uploadId);
            $data = is_array($state['data'] ?? null) ? $state['data'] : [];
            if ((int)($data['status'] ?? 0) > 0) {
                $lastState = $state;
                usleep(800000);
                continue;
            }
        } catch (Throwable $e) {
            // Ignore and keep polling processed state.
        }

        usleep(800000);
    }

    return ['phase' => 'timeout', 'state' => $lastState];
}

function wb_price_tool_is_already_set_upload_error(Throwable $error): bool
{
    $message = mb_strtolower($error->getMessage(), 'UTF-8');
    return str_contains($message, 'specified prices and discounts are already set')
        || str_contains($message, 'specified discounts are already set');
}

function wb_price_tool_apply_price_discount_upload(WildberriesClient $client, array $chunk, array &$report, int $stepNo): void
{
    $payload = array_map(static function (array $row): array {
        return [
            'nmID' => (int)$row['nm_id'],
            'price' => (int)$row['price'],
            'discount' => (int)$row['discount'],
        ];
    }, $chunk);

    try {
        $response = $client->uploadPricesAndDiscounts($payload);
    } catch (Throwable $error) {
        if (!wb_price_tool_is_already_set_upload_error($error)) {
            throw $error;
        }
        $report['upload_items_sent'] = (int)($report['upload_items_sent'] ?? 0) + count($chunk);
        $report['uploads'][] = [
            'upload_id' => 0,
            'already_exists' => true,
            'items' => count($chunk),
            'status' => 'already_set',
            'kind' => 'price_discount',
            'step' => $stepNo,
            'details' => [],
        ];
        return;
    }
    $uploadId = (int)($response['data']['id'] ?? 0);
    $alreadyExists = !empty($response['data']['alreadyExists']);
    $uploadReport = [
        'upload_id' => $uploadId,
        'already_exists' => $alreadyExists,
        'items' => count($chunk),
        'status' => 'accepted',
        'kind' => 'price_discount',
        'step' => $stepNo,
        'details' => [],
    ];
    $report['upload_items_sent'] = (int)($report['upload_items_sent'] ?? 0) + count($chunk);

    if ($uploadId > 0) {
        $wait = wb_price_tool_upload_wait($client, $uploadId, 20);
        $phase = (string)($wait['phase'] ?? '');
        $state = is_array($wait['state'] ?? null) ? $wait['state'] : [];
        $stateData = is_array($state['data'] ?? null) ? $state['data'] : [];
        $status = (int)($stateData['status'] ?? 0);
        if ($phase === 'processed' && in_array($status, [5, 6], true)) {
            $uploadReport['status'] = $status === 5 ? 'partial' : 'error';
            try {
                $details = $client->getProcessedUploadDetails($uploadId, 1000, 0);
                $historyGoods = is_array($details['data']['historyGoods'] ?? null) ? $details['data']['historyGoods'] : [];
                foreach ($historyGoods as $good) {
                    if (!is_array($good)) {
                        continue;
                    }
                    $errorText = trim((string)($good['errorText'] ?? ''));
                    if ($errorText === '') {
                        continue;
                    }
                    $uploadReport['details'][] = [
                        'nm_id' => (int)($good['nmID'] ?? 0),
                        'vendor_code' => (string)($good['vendorCode'] ?? ''),
                        'error' => $errorText,
                    ];
                    $report['errors'][] = trim(((string)($good['vendorCode'] ?? '')) . ': ' . $errorText, ': ');
                }
            } catch (Throwable $detailError) {
                $report['errors'][] = 'Не удалось получить детали upload #' . $uploadId . ': ' . $detailError->getMessage();
            }
        } elseif ($phase === 'processed' && $status === 4) {
            $uploadReport['status'] = 'cancelled';
            $report['errors'][] = 'WB отменил upload #' . $uploadId . '.';
        } elseif ($phase === 'timeout') {
            $uploadReport['status'] = 'processing';
        } else {
            $uploadReport['status'] = 'processed';
        }
    }

    $report['uploads'][] = $uploadReport;
}

function wb_price_tool_apply_updates(array $cfg, array $connection, array $desiredStates): array
{
    $client = wb_price_tool_client($cfg, $connection);
    $desiredStates = array_values(array_filter($desiredStates, static fn($row): bool => is_array($row) && (int)($row['nm_id'] ?? 0) > 0));
    if (!$desiredStates) {
        return [
            'accepted' => 0,
            'uploads' => [],
            'errors' => ['Нет товаров для отправки в WB.'],
        ];
    }

    $report = [
        'accepted' => 0,
        'uploads' => [],
        'errors' => [],
        'gradual_limited' => 0,
        'gradual_step_items' => 0,
        'gradual_steps' => 0,
        'price_upload_steps' => 0,
        'upload_items_sent' => 0,
        'gradual_step_factor' => wb_price_tool_gradual_price_step_factor(),
        'gradual_step_sleep_sec' => wb_price_tool_gradual_price_step_sleep_sec(),
        'gradual_limited_examples' => [],
    ];

    $remainingStates = $desiredStates;
    $gradualLimitedKeys = [];
    $stoppedByUploadError = false;
    for ($stepNo = 1; $remainingStates && $stepNo <= wb_price_tool_gradual_price_max_steps(); $stepNo++) {
        $stepStates = [];
        $nextStates = [];
        $hadGradualLimit = false;

        foreach ($remainingStates as $row) {
            $limited = wb_price_tool_limit_gradual_price_decrease($row);
            if (!empty($limited['gradual_price_limit'])) {
                $hadGradualLimit = true;
                $report['gradual_step_items']++;
                $key = (int)($limited['nm_id'] ?? 0) > 0
                    ? 'nm:' . (int)$limited['nm_id']
                    : 'offer:' . (string)($limited['offer_id'] ?? $limited['vendor_code'] ?? '');
                $gradualLimitedKeys[$key] = true;
                $report['gradual_limited'] = count($gradualLimitedKeys);
                $report['gradual_steps'] = max((int)$report['gradual_steps'], $stepNo);
                if (count($report['gradual_limited_examples']) < 10) {
                    $report['gradual_limited_examples'][] = [
                        'step' => $stepNo,
                        'vendor_code' => (string)($limited['offer_id'] ?? $limited['vendor_code'] ?? ''),
                        'nm_id' => (int)($limited['nm_id'] ?? 0),
                        'from_sale_price' => round((float)($limited['gradual_limit_current_sale_price'] ?? 0), 2),
                        'planned_sale_price' => round((float)($limited['original_sale_price_before_gradual_limit'] ?? 0), 2),
                        'sent_sale_price' => round((float)($limited['gradual_limit_sale_price'] ?? 0), 2),
                        'sent_discount' => (int)($limited['discount'] ?? 0),
                    ];
                }

                $next = $row;
                $next['current_price'] = (int)($limited['price'] ?? 0);
                $next['current_discount'] = (int)($limited['discount'] ?? 0);
                $next['current_discounted_price'] = wb_price_tool_payload_sale_price(
                    (int)($limited['price'] ?? 0),
                    (int)($limited['discount'] ?? 0)
                );
                $nextStates[] = $next;
            }
            $stepStates[] = $limited;
        }

        if (!$stepStates) {
            break;
        }

        $errorsBeforeStep = count($report['errors']);
        foreach (array_chunk($stepStates, 1000) as $chunk) {
            wb_price_tool_apply_price_discount_upload($client, $chunk, $report, $stepNo);
        }
        $report['price_upload_steps'] = max((int)$report['price_upload_steps'], $stepNo);
        if ($stepNo === 1) {
            $report['accepted'] = count($desiredStates);
        }

        if (count($report['errors']) > $errorsBeforeStep) {
            $stoppedByUploadError = true;
            $remainingStates = [];
            break;
        }
        $remainingStates = $nextStates;
        $sleepSec = wb_price_tool_gradual_price_step_sleep_sec();
        if ($remainingStates && $hadGradualLimit && $sleepSec > 0) {
            sleep($sleepSec);
        }
    }

    if ($remainingStates && !$stoppedByUploadError) {
        $report['gradual_remaining'] = count($remainingStates);
        if (wb_price_tool_gradual_price_max_steps() > 1) {
            $report['errors'][] = 'WB Price Tool остановил ступенчатое снижение после '
                . wb_price_tool_gradual_price_max_steps()
                . ' шагов, часть товаров ещё не дошла до целевой цены.';
        }
    }

    $clubPayload = array_values(array_map(static function (array $row): array {
        return [
            'nmID' => (int)$row['nm_id'],
            'clubDiscount' => (int)($row['club_discount'] ?? 0),
        ];
    }, array_values(array_filter($desiredStates, static function (array $row): bool {
        return (int)($row['club_discount'] ?? 0) !== (int)($row['current_club_discount'] ?? 0);
    }))));

    foreach (array_chunk($clubPayload, 1000) as $clubChunk) {
        if (!$clubChunk) {
            continue;
        }
        try {
            $clubResponse = $client->uploadClubDiscounts($clubChunk);
        } catch (Throwable $clubError) {
            if (!wb_price_tool_is_already_set_upload_error($clubError)) {
                throw $clubError;
            }
            $report['uploads'][] = [
                'upload_id' => 0,
                'already_exists' => true,
                'items' => count($clubChunk),
                'status' => 'already_set',
                'kind' => 'club_discount',
                'details' => [],
            ];
            continue;
        }
        $clubUploadId = (int)($clubResponse['data']['id'] ?? 0);
        $clubAlreadyExists = !empty($clubResponse['data']['alreadyExists']);
        $clubUploadReport = [
            'upload_id' => $clubUploadId,
            'already_exists' => $clubAlreadyExists,
            'items' => count($clubChunk),
            'status' => 'accepted',
            'kind' => 'club_discount',
            'details' => [],
        ];

        if ($clubUploadId > 0) {
            $wait = wb_price_tool_upload_wait($client, $clubUploadId, 20);
            $phase = (string)($wait['phase'] ?? '');
            $state = is_array($wait['state'] ?? null) ? $wait['state'] : [];
            $stateData = is_array($state['data'] ?? null) ? $state['data'] : [];
            $status = (int)($stateData['status'] ?? 0);
            if ($phase === 'processed' && in_array($status, [5, 6], true)) {
                $clubUploadReport['status'] = $status === 5 ? 'partial' : 'error';
                try {
                    $details = $client->getProcessedUploadDetails($clubUploadId, 1000, 0);
                    $historyGoods = is_array($details['data']['historyGoods'] ?? null) ? $details['data']['historyGoods'] : [];
                    foreach ($historyGoods as $good) {
                        if (!is_array($good)) {
                            continue;
                        }
                        $errorText = trim((string)($good['errorText'] ?? ''));
                        if ($errorText === '') {
                            continue;
                        }
                        $clubUploadReport['details'][] = [
                            'nm_id' => (int)($good['nmID'] ?? 0),
                            'vendor_code' => (string)($good['vendorCode'] ?? ''),
                            'error' => $errorText,
                        ];
                        $report['errors'][] = trim(((string)($good['vendorCode'] ?? '')) . ': ' . $errorText, ': ');
                    }
                } catch (Throwable $detailError) {
                    $report['errors'][] = 'Не удалось получить детали WB Club upload #' . $clubUploadId . ': ' . $detailError->getMessage();
                }
            } elseif ($phase === 'processed' && $status === 4) {
                $clubUploadReport['status'] = 'cancelled';
                $report['errors'][] = 'WB отменил upload скидок WB Клуба #' . $clubUploadId . '.';
            } elseif ($phase === 'timeout') {
                $clubUploadReport['status'] = 'processing';
            } else {
                $clubUploadReport['status'] = 'processed';
            }
        }

        $report['uploads'][] = $clubUploadReport;
    }

    $promotionGroups = [];
    foreach ($desiredStates as $state) {
        if ((string)($state['promotion_action'] ?? '') !== 'upload_to_promotion') {
            continue;
        }
        $promotionId = (int)($state['promotion_id'] ?? 0);
        $nmId = (int)($state['nm_id'] ?? 0);
        if ($promotionId <= 0 || $nmId <= 0) {
            continue;
        }
        if (!isset($promotionGroups[$promotionId])) {
            $promotionGroups[$promotionId] = [
                'promotion_id' => $promotionId,
                'promotion_name' => (string)($state['promotion_name'] ?? ('WB action #' . $promotionId)),
                'nm_ids' => [],
            ];
        }
        $promotionGroups[$promotionId]['nm_ids'][] = $nmId;
    }

    foreach ($promotionGroups as $group) {
        $promotionId = (int)($group['promotion_id'] ?? 0);
        $promotionName = (string)($group['promotion_name'] ?? ('WB action #' . $promotionId));
        $nmIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            (array)($group['nm_ids'] ?? [])
        ), static fn(int $value): bool => $value > 0)));
        foreach (array_chunk($nmIds, 1000) as $chunk) {
            $uploadReport = [
                'upload_id' => 0,
                'already_exists' => false,
                'items' => count($chunk),
                'status' => 'accepted',
                'kind' => 'promotion_upload',
                'promotion_id' => $promotionId,
                'promotion_name' => $promotionName,
                'details' => [],
            ];
            try {
                $response = $client->uploadPromotionNomenclatures($promotionId, $chunk, true);
                $data = is_array($response['data'] ?? null) ? (array)$response['data'] : [];
                $uploadReport['upload_id'] = (int)($data['uploadID'] ?? ($data['id'] ?? 0));
                $uploadReport['already_exists'] = !empty($data['alreadyExists']);
                if ((int)$uploadReport['upload_id'] <= 0 && !$uploadReport['already_exists']) {
                    $uploadReport['status'] = 'accepted_no_upload_id';
                }
            } catch (Throwable $promotionError) {
                $uploadReport['status'] = 'error';
                $uploadReport['details'][] = ['error' => $promotionError->getMessage()];
                $report['errors'][] = 'WB акция "' . $promotionName . '": не удалось отправить товары в акцию: ' . $promotionError->getMessage();
            }
            $report['uploads'][] = $uploadReport;
        }
    }

    if ((int)($report['accepted'] ?? 0) > 0) {
        wb_price_tool_clear_goods_cache($cfg, $connection);
    }

    return $report;
}
