<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BundleOffer.php';

function yandex_price_tool_to_float($value): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $normalized = trim(str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string)$value));
    if ($normalized === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
        return 0.0;
    }
    return (float)$normalized;
}

function yandex_price_tool_context(array $connection): array
{
    $resolved = marketplace_connection_yandex_resolve_campaign($connection);
    $campaign = is_array($resolved['campaign'] ?? null) ? (array)$resolved['campaign'] : [];
    $campaignId = (int)($resolved['campaign_id'] ?? 0);
    $businessId = (int)($campaign['business']['id'] ?? ($campaign['businessId'] ?? 0));
    if ($campaignId <= 0) {
        throw new RuntimeException('Не удалось определить Campaign ID Яндекс Маркета.');
    }
    if ($businessId <= 0) {
        throw new RuntimeException('Яндекс API не вернул businessId для выбранного кабинета. Проверь права API-Key.');
    }
    $businessSettings = yandex_price_tool_fetch_business_settings($connection, $businessId);
    return [
        'campaign_id' => $campaignId,
        'business_id' => $businessId,
        'campaign' => $campaign,
        'placement_type' => strtoupper(trim((string)($campaign['placementType'] ?? ''))),
        'business_settings' => $businessSettings,
        'only_default_price' => (bool)($businessSettings['only_default_price'] ?? true),
        'currency' => (string)($businessSettings['currency'] ?? 'RUR'),
        'warnings' => array_values(array_filter((array)($businessSettings['warnings'] ?? []))),
    ];
}

function yandex_price_tool_fetch_business_settings(array $connection, int $businessId): array
{
    $fallback = [
        'only_default_price' => true,
        'currency' => 'RUR',
        'warnings' => [],
    ];
    if ($businessId <= 0) {
        return $fallback;
    }
    static $cache = [];
    $cacheKey = (string)((int)($connection['id'] ?? 0)) . ':' . $businessId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $body = marketplace_connection_yandex_request($connection, 'POST', '/v2/businesses/' . $businessId . '/settings', [], new stdClass());
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $settings = is_array($container['settings'] ?? null) ? (array)$container['settings'] : [];
        $fallback['only_default_price'] = (bool)($settings['onlyDefaultPrice'] ?? true);
        $currency = strtoupper(trim((string)($settings['currency'] ?? 'RUR')));
        $fallback['currency'] = $currency !== '' ? $currency : 'RUR';
    } catch (Throwable $e) {
        $fallback['warnings'][] = 'Не удалось получить настройки кабинета Яндекс Маркета: ' . $e->getMessage() . '. Используем режим цены для всех магазинов.';
    }

    return $cache[$cacheKey] = $fallback;
}

function yandex_price_tool_offer_id_to_marketplace(string $offerId): string
{
    return bundle_offer_yandex_offer_id_to_marketplace($offerId);
}

function yandex_price_tool_offer_id_to_internal(string $offerId, array $supplierCodes = []): string
{
    return bundle_offer_yandex_offer_id_to_internal($offerId, $supplierCodes);
}

function yandex_price_tool_load_feed_offers(array $feed): array
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

    $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
    foreach ($offers as &$offer) {
        if (!is_array($offer)) {
            continue;
        }
        $offer['offer_id'] = ozon_price_apply_supplier_code((string)($offer['offer_id'] ?? ''), $supplierCode);
    }
    unset($offer);
    return $offers;
}

function yandex_price_tool_request_paged(
    array $connection,
    string $method,
    string $path,
    array $query,
    array $payload,
    string $itemsKey,
    int $guardLimit = 50
): array {
    $items = [];
    $pageToken = '';
    $guard = 0;
    do {
        $pageQuery = $query;
        if ($pageToken !== '') {
            $pageQuery['pageToken'] = $pageToken;
        }
        $body = marketplace_connection_yandex_request($connection, $method, $path, $pageQuery, $payload);
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $pageItems = (array)($container[$itemsKey] ?? []);
        if (!$pageItems) {
            foreach (['offers', 'offerPrices', 'offerMappings', 'offerRecommendations', 'recommendations'] as $fallbackKey) {
                if ($fallbackKey === $itemsKey) {
                    continue;
                }
                if (!empty($container[$fallbackKey]) && is_array($container[$fallbackKey])) {
                    $pageItems = (array)$container[$fallbackKey];
                    break;
                }
            }
        }
        foreach ($pageItems as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        $pageToken = trim((string)($container['paging']['nextPageToken'] ?? ''));
        $guard++;
    } while ($pageToken !== '' && $guard < $guardLimit);
    return $items;
}

function yandex_price_tool_fetch_prices(array $connection, int $businessId, array $offerIds, ?array $context = null): array
{
    $remoteToInternal = [];
    foreach ($offerIds as $offerId) {
        $internal = trim((string)$offerId);
        if ($internal === '') {
            continue;
        }
        $remoteToInternal[yandex_price_tool_offer_id_to_marketplace($internal)] = $internal;
    }
    $result = [];
    foreach (array_chunk(array_keys($remoteToInternal), 200) as $chunk) {
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            '/v2/businesses/' . $businessId . '/offer-prices',
            [],
            ['offerIds' => array_values($chunk)]
        );
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $items = (array)($container['offers'] ?? ($container['offerPrices'] ?? []));
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $remote = trim((string)($item['offerId'] ?? ($item['offer']['offerId'] ?? '')));
            $internal = $remoteToInternal[$remote] ?? yandex_price_tool_offer_id_to_internal($remote);
            if ($internal !== '') {
                $item['price_scope'] = 'business';
                $result[$internal] = $item;
            }
        }
    }
    if (is_array($context) && empty($context['only_default_price']) && (int)($context['campaign_id'] ?? 0) > 0) {
        try {
            $campaignPrices = yandex_price_tool_fetch_campaign_prices($connection, (int)$context['campaign_id'], $offerIds);
            foreach ($campaignPrices as $internal => $campaignItem) {
                $defaultItem = is_array($result[$internal] ?? null) ? (array)$result[$internal] : [];
                $defaultPrice = is_array($defaultItem['price'] ?? null) ? (array)$defaultItem['price'] : [];
                $campaignPrice = is_array($campaignItem['price'] ?? null) ? (array)$campaignItem['price'] : [];
                $merged = $defaultItem;
                $merged['offerId'] = (string)($campaignItem['offerId'] ?? ($defaultItem['offerId'] ?? yandex_price_tool_offer_id_to_marketplace((string)$internal)));
                $merged['price'] = array_replace($defaultPrice, $campaignPrice);
                $merged['price_scope'] = 'campaign';
                $merged['campaign_price'] = $campaignItem;
                $result[(string)$internal] = $merged;
            }
        } catch (Throwable $e) {
            foreach ($result as &$item) {
                if (is_array($item)) {
                    $item['price_scope_warning'] = 'Не удалось получить цены конкретного магазина: ' . $e->getMessage() . '. Для расчёта использована цена кабинета.';
                }
            }
            unset($item);
        }
    }
    return $result;
}

function yandex_price_tool_fetch_campaign_prices(array $connection, int $campaignId, array $offerIds): array
{
    if ($campaignId <= 0) {
        return [];
    }
    $remoteToInternal = [];
    foreach ($offerIds as $offerId) {
        $internal = trim((string)$offerId);
        if ($internal === '') {
            continue;
        }
        $remoteToInternal[yandex_price_tool_offer_id_to_marketplace($internal)] = $internal;
    }
    $result = [];
    foreach (array_chunk(array_keys($remoteToInternal), 500) as $chunk) {
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            '/v2/campaigns/' . $campaignId . '/offer-prices',
            [],
            ['offerIds' => array_values($chunk)]
        );
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $items = (array)($container['offers'] ?? ($container['offerPrices'] ?? []));
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $remote = trim((string)($item['offerId'] ?? ($item['id'] ?? ($item['offer']['offerId'] ?? ''))));
            $internal = $remoteToInternal[$remote] ?? yandex_price_tool_offer_id_to_internal($remote);
            if ($internal === '') {
                continue;
            }
            $item['offerId'] = $remote;
            $item['price_scope'] = 'campaign';
            $result[$internal] = $item;
        }
    }
    return $result;
}

function yandex_price_tool_fetch_offer_mappings(array $connection, int $businessId, array $offerIds): array
{
    $remoteToInternal = [];
    foreach ($offerIds as $offerId) {
        $internal = trim((string)$offerId);
        if ($internal === '') {
            continue;
        }
        $remoteToInternal[yandex_price_tool_offer_id_to_marketplace($internal)] = $internal;
    }
    $result = [];
    foreach (array_chunk(array_keys($remoteToInternal), 100) as $chunk) {
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            '/v2/businesses/' . $businessId . '/offer-mappings',
            [],
            ['offerIds' => array_values($chunk)]
        );
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $items = (array)($container['offerMappings'] ?? ($container['offers'] ?? []));
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $remote = trim((string)($item['offer']['offerId'] ?? ($item['offerId'] ?? '')));
            $internal = $remoteToInternal[$remote] ?? yandex_price_tool_offer_id_to_internal($remote);
            if ($internal !== '') {
                $result[$internal] = $item;
            }
        }
    }
    return $result;
}

function yandex_price_tool_fetch_recommendations(array $connection, int $businessId, array $offerIds): array
{
    $remoteToInternal = [];
    foreach ($offerIds as $offerId) {
        $internal = trim((string)$offerId);
        if ($internal === '') {
            continue;
        }
        $remoteToInternal[yandex_price_tool_offer_id_to_marketplace($internal)] = $internal;
    }
    $result = [];
    foreach (array_chunk(array_keys($remoteToInternal), 200) as $chunk) {
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            '/v2/businesses/' . $businessId . '/offers/recommendations',
            [],
            ['offerIds' => array_values($chunk)],
        );
        $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
        $items = (array)($container['offerRecommendations'] ?? ($container['recommendations'] ?? []));
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $remote = trim((string)($item['offer']['offerId'] ?? ($item['offerId'] ?? '')));
            $internal = $remoteToInternal[$remote] ?? yandex_price_tool_offer_id_to_internal($remote);
            if ($internal !== '') {
                $result[$internal] = $item;
            }
        }
    }
    return $result;
}

function yandex_price_tool_extract_price_value(?array $priceItem, string $field = 'value'): ?float
{
    if (!is_array($priceItem)) {
        return null;
    }
    $price = is_array($priceItem['price'] ?? null) ? (array)$priceItem['price'] : $priceItem;
    $value = yandex_price_tool_to_float($price[$field] ?? 0);
    return $value > 0 ? round($value, 2) : null;
}

function yandex_price_tool_extract_price_bool(?array $priceItem, string $field): ?bool
{
    if (!is_array($priceItem)) {
        return null;
    }
    $price = is_array($priceItem['price'] ?? null) ? (array)$priceItem['price'] : $priceItem;
    if (!array_key_exists($field, $price)) {
        return null;
    }
    return filter_var($price[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$price[$field];
}

function yandex_price_tool_mapping_category_id(?array $mapping): int
{
    if (!is_array($mapping)) {
        return 0;
    }
    foreach ([
        $mapping['offer']['marketCategoryId'] ?? null,
        $mapping['mapping']['marketCategoryId'] ?? null,
        $mapping['marketCategoryId'] ?? null,
    ] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function yandex_price_tool_mapping_dimensions(?array $mapping, array $offer): array
{
    $source = is_array($mapping['offer']['weightDimensions'] ?? null)
        ? (array)$mapping['offer']['weightDimensions']
        : (is_array($mapping['mapping']['weightDimensions'] ?? null) ? (array)$mapping['mapping']['weightDimensions'] : []);
    return [
        'length' => max(0.0, yandex_price_tool_to_float($source['length'] ?? ($offer['length'] ?? 0))),
        'width' => max(0.0, yandex_price_tool_to_float($source['width'] ?? ($offer['width'] ?? 0))),
        'height' => max(0.0, yandex_price_tool_to_float($source['height'] ?? ($offer['height'] ?? 0))),
        'weight' => max(0.0, yandex_price_tool_to_float($source['weight'] ?? ($offer['weight'] ?? 0))),
    ];
}

function yandex_price_tool_tariff_offer_payload(array $offer, ?array $mapping, float $price): array
{
    $categoryId = yandex_price_tool_mapping_category_id($mapping);
    if ($categoryId <= 0) {
        $categoryId = (int)($offer['category_id'] ?? 0);
    }
    $dims = yandex_price_tool_mapping_dimensions($mapping, $offer);
    return [
        'categoryId' => max(0, $categoryId),
        'price' => max(1, (int)round($price)),
        'length' => round(max(1.0, (float)$dims['length']), 3),
        'width' => round(max(1.0, (float)$dims['width']), 3),
        'height' => round(max(1.0, (float)$dims['height']), 3),
        'weight' => round(max(0.01, (float)$dims['weight']), 3),
        'quantity' => 1,
    ];
}

function yandex_price_tool_selling_program(array $settings, array $context): string
{
    $scheme = strtoupper(trim((string)($settings['fulfillment_scheme'] ?? '')));
    if (in_array($scheme, ['FBS', 'FBY', 'DBS', 'EXPRESS'], true)) {
        return $scheme;
    }
    $placement = strtoupper(trim((string)($context['placement_type'] ?? '')));
    return in_array($placement, ['FBS', 'FBY', 'DBS', 'EXPRESS'], true) ? $placement : 'FBS';
}

function yandex_price_tool_calculate_tariffs(
    array $connection,
    array $context,
    array $settings,
    array $offersById,
    array $mappingsById,
    array $pricesById
): array {
    $result = [];
    $ids = array_keys($offersById);

    $fetchChunk = static function (array $chunkIds) use (&$fetchChunk, &$result, $connection, $context, $offersById, $mappingsById, $pricesById): void {
        $chunkIds = array_values(array_filter(array_map('strval', $chunkIds), static fn(string $offerId): bool => $offerId !== ''));
        if (!$chunkIds) {
            return;
        }
        $remoteIndex = [];
        $payloadOffers = [];
        foreach ($chunkIds as $offerId) {
            $offer = is_array($offersById[$offerId] ?? null) ? (array)$offersById[$offerId] : [];
            $mapping = is_array($mappingsById[$offerId] ?? null) ? (array)$mappingsById[$offerId] : null;
            $price = max(1.0, (float)($pricesById[$offerId] ?? 0));
            $payloadOffers[] = yandex_price_tool_tariff_offer_payload($offer, $mapping, $price);
            $remoteIndex[] = $offerId;
        }
        if (!$payloadOffers) {
            return;
        }
        try {
            $body = marketplace_connection_yandex_request($connection, 'POST', '/v2/tariffs/calculate', [], [
                'parameters' => [
                    'campaignId' => (int)$context['campaign_id'],
                    'frequency' => 'DAILY',
                    'paymentDelayWeeks' => 0,
                ],
                'offers' => $payloadOffers,
            ]);
            $container = is_array($body['result'] ?? null) ? (array)$body['result'] : $body;
            $tariffItems = (array)($container['offers'] ?? ($container['offerTariffs'] ?? []));
            foreach ($tariffItems as $index => $tariffRow) {
                $offerId = (string)($remoteIndex[$index] ?? '');
                if ($offerId !== '' && is_array($tariffRow)) {
                    $result[$offerId] = $tariffRow;
                }
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $unknownCategoryIds = [];
            if (preg_match('/Unknown categories:\\s*([0-9,\\s]+)/i', $message, $m)) {
                foreach (preg_split('/\\D+/', (string)$m[1]) ?: [] as $idRaw) {
                    $id = (int)$idRaw;
                    if ($id > 0) {
                        $unknownCategoryIds[$id] = true;
                    }
                }
            }
            if ($unknownCategoryIds) {
                $retryIds = [];
                foreach ($chunkIds as $offerId) {
                    $mapping = is_array($mappingsById[$offerId] ?? null) ? (array)$mappingsById[$offerId] : null;
                    $categoryId = yandex_price_tool_mapping_category_id($mapping);
                    if ($categoryId > 0 && isset($unknownCategoryIds[$categoryId])) {
                        $result[$offerId] = ['_tariff_error' => 'Яндекс не рассчитал тарифы для категории ' . $categoryId . '.'];
                    } else {
                        $retryIds[] = $offerId;
                    }
                }
                if ($retryIds && count($retryIds) < count($chunkIds)) {
                    $fetchChunk($retryIds);
                    return;
                }
            }
            if (count($chunkIds) > 1) {
                $splitSize = max(1, (int)ceil(count($chunkIds) / 2));
                foreach (array_chunk($chunkIds, $splitSize) as $subChunkIds) {
                    $fetchChunk($subChunkIds);
                }
                return;
            }
            $offerId = (string)($chunkIds[0] ?? '');
            if ($offerId !== '') {
                $result[$offerId] = ['_tariff_error' => $message];
            }
        }
    };

    foreach (array_chunk($ids, 100) as $chunkIds) {
        $fetchChunk($chunkIds);
    }
    return $result;
}

function yandex_price_tool_tariff_reference_price(?array $tariffRow, float $fallback): float
{
    if (!is_array($tariffRow)) {
        return max(0.0, $fallback);
    }
    $candidates = [
        $tariffRow['offer']['price'] ?? null,
        $tariffRow['offer']['price']['value'] ?? null,
        $tariffRow['price'] ?? null,
        $tariffRow['price']['value'] ?? null,
        $tariffRow['request']['price'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $value = is_array($candidate) ? yandex_price_tool_to_float($candidate['value'] ?? 0) : yandex_price_tool_to_float($candidate);
        if ($value > 0) {
            return $value;
        }
    }
    return max(0.0, $fallback);
}

function yandex_price_tool_tariff_type_is_price_dependent(string $type): bool
{
    return in_array($type, [
        'AGENCY_COMMISSION',
        'FEE',
        'PAYMENT_TRANSFER',
        'CASH_ONLY_ORDER_PERCENT',
    ], true);
}

function yandex_price_tool_resolve_marketplace_expenses(array $settings, array $offer, ?array $mapping, ?array $tariffRow, float $referencePrice): array
{
    $warnings = [];
    $tariffError = is_array($tariffRow) ? trim((string)($tariffRow['_tariff_error'] ?? '')) : '';
    if ($tariffError !== '') {
        $warnings[] = 'Не удалось получить тарифы Яндекс Маркета: ' . $tariffError;
    }
    $tariffs = is_array($tariffRow['tariffs'] ?? null) ? (array)$tariffRow['tariffs'] : [];
    $tariffReferencePrice = yandex_price_tool_tariff_reference_price($tariffRow, $referencePrice);
    $platformRub = 0.0;
    $logisticsRub = 0.0;
    $otherRub = 0.0;
    $variableRub = 0.0;
    $rows = [];
    foreach ($tariffs as $tariff) {
        if (!is_array($tariff)) {
            continue;
        }
        $type = strtoupper(trim((string)($tariff['type'] ?? 'OTHER')));
        $amount = yandex_price_tool_to_float($tariff['amount'] ?? 0);
        $parameters = is_array($tariff['parameters'] ?? null) ? (array)$tariff['parameters'] : [];
        $isPriceDependent = yandex_price_tool_tariff_type_is_price_dependent($type);
        $hasRelativeValue = false;
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $name = strtolower(trim((string)($parameter['name'] ?? '')));
            $value = strtolower(trim((string)($parameter['value'] ?? '')));
            $valueType = strtolower(trim((string)($parameter['valueType'] ?? '')));
            $priceDependence = strtoupper(trim((string)($parameter['priceDependence'] ?? '')));
            if ($valueType === 'relative' || ($name === 'valuetype' && $value === 'relative')) {
                $hasRelativeValue = true;
            }
            if ($priceDependence !== '' || $name === 'pricedependence') {
                $isPriceDependent = true;
            }
        }
        $isLogistics = in_array($type, ['DELIVERY_TO_CUSTOMER', 'CROSSREGIONAL_DELIVERY', 'EXPRESS_DELIVERY', 'SORTING', 'MIDDLE_MILE'], true);
        $isVariable = $isPriceDependent || (!$isLogistics && $hasRelativeValue);
        if ($isLogistics) {
            $logisticsRub += $amount;
        } elseif (in_array($type, ['AGENCY_COMMISSION', 'PAYMENT_TRANSFER', 'FEE'], true)) {
            $platformRub += $amount;
            if ($isVariable) {
                $variableRub += $amount;
            }
        } else {
            $otherRub += $amount;
            if ($isVariable) {
                $variableRub += $amount;
            }
        }
        $rows[] = [
            'type' => $type,
            'amount_rub' => round($amount, 2),
            'variable' => $isVariable,
            'parameters' => $parameters,
        ];
    }
    if (!$tariffs) {
        $warnings[] = 'Яндекс API не вернул тарифы для товара. Расходы площадки в расчёте равны нулю.';
    }
    $fixedRub = max(0.0, $platformRub + $logisticsRub + $otherRub - $variableRub);
    $variablePercent = $tariffReferencePrice > 0 ? ($variableRub / $tariffReferencePrice * 100.0) : 0.0;
    $dims = yandex_price_tool_mapping_dimensions($mapping, $offer);
    return [
        'variable_percent' => max(0.0, $variablePercent),
        'fixed_rub' => max(0.0, $fixedRub),
        'breakdown' => [
            'source' => $tariffs ? 'api' : 'missing_api',
            'tariff_total_rub' => round($platformRub + $logisticsRub + $otherRub, 2),
            'tariff_reference_price' => round($tariffReferencePrice, 2),
            'tariff_variable_rub' => round($variableRub, 2),
            'tariff_fixed_rub' => round($fixedRub, 2),
            'tariff_platform_rub' => round($platformRub, 2),
            'tariff_logistics_rub' => round($logisticsRub, 2),
            'tariff_other_rub' => round($otherRub, 2),
            'variable_percent' => round($variablePercent, 4),
            'category_id' => yandex_price_tool_mapping_category_id($mapping),
            'length' => $dims['length'],
            'width' => $dims['width'],
            'height' => $dims['height'],
            'weight' => $dims['weight'],
            'tariffs' => $rows,
        ],
        'warnings' => $warnings,
    ];
}

function yandex_price_tool_profit_snapshot(array $settings, float $purchaseCost, float $salePrice, ?array $runtimeExpenses = null): array
{
    $runtimeExpenses = is_array($runtimeExpenses) ? $runtimeExpenses : [];
    $variablePercent = max(0.0, (float)($runtimeExpenses['variable_percent'] ?? 0));
    $fixedMarketplaceRub = max(0.0, (float)($runtimeExpenses['fixed_rub'] ?? 0));
    $manualFixedRub = max(0.0, (float)($settings['fulfillment_markup_rub'] ?? 0));
    $manualFixedPercent = max(0.0, (float)($settings['fulfillment_markup_percent'] ?? 0));
    $promotionPercent = max(0.0, (float)($settings['promotion_percent'] ?? 0));
    $creditPercent = max(0.0, (float)($settings['credit_percent'] ?? 0));
    $extraPercent = max(0.0, (float)($settings['extra_expenses_percent'] ?? 0));
    $insurancePercent = max(0.0, (float)($settings['insurance_percent'] ?? 0));
    $boostPercent = 0.0;
    $variableCostsRub = $salePrice * (($variablePercent + $manualFixedPercent + $promotionPercent + $creditPercent + $extraPercent + $insurancePercent + $boostPercent) / 100.0);

    $includeReturnsInCost = !empty($settings['include_returns_in_cost']);
    $nonbuyoutPercent = max(0.0, (float)($settings['nonbuyout_percent'] ?? 0));
    $returnResellablePercent = max(0.0, (float)($settings['return_resellable_percent'] ?? 0));
    $returnNonresellablePercent = max(0.0, (float)($settings['return_nonresellable_percent'] ?? 0));
    $issueTotalPercent = min(95.0, $nonbuyoutPercent + $returnResellablePercent + $returnNonresellablePercent);
    $keptPercent = max(5.0, 100.0 - $issueTotalPercent);
    $nonbuyoutFactor = $includeReturnsInCost ? ($nonbuyoutPercent / $keptPercent) : 0.0;
    $returnResellableFactor = $includeReturnsInCost ? ($returnResellablePercent / $keptPercent) : 0.0;
    $returnNonresellableFactor = $includeReturnsInCost ? ($returnNonresellablePercent / $keptPercent) : 0.0;
    $nonbuyoutProcessingRub = max(0.0, (float)($settings['nonbuyout_processing_rub'] ?? 0));
    $returnProcessingRub = max(0.0, (float)($settings['return_processing_rub'] ?? 0));
    $nonbuyoutCost = ($fixedMarketplaceRub + $nonbuyoutProcessingRub) * $nonbuyoutFactor;
    $returnResellableCost = ($fixedMarketplaceRub + $returnProcessingRub) * $returnResellableFactor;
    $returnNonresellableCost = ($fixedMarketplaceRub + $returnProcessingRub + $purchaseCost) * $returnNonresellableFactor;
    $issueCost = $includeReturnsInCost ? ($nonbuyoutCost + $returnResellableCost + $returnNonresellableCost) : 0.0;

    $profitBeforeTax = $salePrice - $purchaseCost - $manualFixedRub - $fixedMarketplaceRub - $variableCostsRub - $issueCost;
    $taxMode = strtolower(trim((string)($settings['tax_mode'] ?? 'none')));
    $taxPercent = max(0.0, (float)($settings['tax_percent'] ?? 0));
    $vatPercent = max(0.0, (float)($settings['vat_percent'] ?? 0));
    $profitTaxPercent = max(0.0, (float)($settings['profit_tax_percent'] ?? 0));
    $taxRub = $taxMode === 'usn_income'
        ? $salePrice * ($taxPercent / 100.0)
        : ($taxMode === 'usn_income_expense' ? max(0.0, $profitBeforeTax) * ($taxPercent / 100.0) : 0.0);
    $vatRub = $salePrice * ($vatPercent / 100.0);
    $profitAfterBaseTaxes = $profitBeforeTax - $taxRub - $vatRub;
    $profitTaxRub = max(0.0, $profitAfterBaseTaxes) * ($profitTaxPercent / 100.0);
    $profitRub = $profitAfterBaseTaxes - $profitTaxRub;

    $tariffBreakdown = is_array($runtimeExpenses['breakdown'] ?? null) ? (array)$runtimeExpenses['breakdown'] : [];
    return [
        'profit_rub' => round($profitRub, 2),
        'profit_on_cost_percent' => $purchaseCost > 0 ? round(($profitRub / $purchaseCost) * 100.0, 2) : 0.0,
        'breakdown' => array_replace($tariffBreakdown, [
            'sale_price' => round($salePrice, 2),
            'purchase_cost' => round($purchaseCost, 2),
            'manual_fixed_costs_rub' => round($manualFixedRub, 2),
            'manual_fixed_percent' => round($manualFixedPercent, 2),
            'marketplace_fixed_rub' => round($fixedMarketplaceRub, 2),
            'marketplace_variable_percent' => round($variablePercent, 4),
            'marketplace_variable_rub' => round($salePrice * ($variablePercent / 100.0), 2),
            'promotion_percent' => round($promotionPercent, 2),
            'credit_percent' => round($creditPercent, 2),
            'extra_percent' => round($extraPercent, 2),
            'insurance_percent' => round($insurancePercent, 2),
            'boost_bid_percent' => round($boostPercent, 2),
            'variable_costs_rub' => round($variableCostsRub, 2),
            'include_returns_in_cost' => $includeReturnsInCost ? 1 : 0,
            'issue_total_percent' => round($issueTotalPercent, 2),
            'issue_cost' => round($issueCost, 2),
            'nonbuyout_cost' => round($nonbuyoutCost, 2),
            'return_resellable_cost' => round($returnResellableCost, 2),
            'return_nonresellable_cost' => round($returnNonresellableCost, 2),
            'tax_mode' => $taxMode,
            'tax_percent' => round($taxPercent, 2),
            'tax_rub' => round($taxRub, 2),
            'vat_percent' => round($vatPercent, 2),
            'vat_rub' => round($vatRub, 2),
            'profit_tax_percent' => round($profitTaxPercent, 2),
            'profit_tax_rub' => round($profitTaxRub, 2),
            'profit_before_tax_rub' => round($profitBeforeTax, 2),
        ]),
    ];
}

function yandex_price_tool_target_profit_rub(array $settings, float $purchaseCost, bool $forMinPrice = false): float
{
    $targetProfitPercent = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, $forMinPrice);
    $minKey = $forMinPrice ? 'min_target_profit_min_rub' : 'target_profit_min_rub';
    return max($purchaseCost * ($targetProfitPercent / 100.0), max(0.0, (float)($settings[$minKey] ?? 0)));
}

function yandex_price_tool_solve_sale_price(array $settings, float $purchaseCost, float $targetProfitRub, ?array $runtimeExpenses = null): ?array
{
    $low = 0.01;
    $high = max(1.0, $purchaseCost + $targetProfitRub + 100.0);
    $snapshot = yandex_price_tool_profit_snapshot($settings, $purchaseCost, $high, $runtimeExpenses);
    for ($guard = 0; $guard < 24 && (float)($snapshot['profit_rub'] ?? 0.0) < $targetProfitRub; $guard++) {
        $high *= 1.5;
        $snapshot = yandex_price_tool_profit_snapshot($settings, $purchaseCost, $high, $runtimeExpenses);
    }
    if ((float)($snapshot['profit_rub'] ?? 0.0) < $targetProfitRub) {
        return null;
    }
    for ($i = 0; $i < 48; $i++) {
        $mid = ($low + $high) / 2.0;
        $midSnapshot = yandex_price_tool_profit_snapshot($settings, $purchaseCost, $mid, $runtimeExpenses);
        if ((float)($midSnapshot['profit_rub'] ?? 0.0) >= $targetProfitRub) {
            $high = $mid;
            $snapshot = $midSnapshot;
        } else {
            $low = $mid;
        }
    }
    return ['sale_price' => round($high, 2), 'snapshot' => $snapshot];
}

function yandex_price_tool_build_desired_state(array $settings, array $calc): ?array
{
    $offerId = trim((string)($calc['offer_id'] ?? ''));
    $price = (float)($calc['recommended_price'] ?? 0);
    if ($offerId === '' || $price <= 0) {
        return null;
    }
    $discountBase = 0;
    $strikeDiscount = max(0.0, min(95.0, (float)($settings['strike_discount_percent'] ?? 0)));
    if ($strikeDiscount > 0) {
        $discountBase = (int)ceil($price / (1.0 - $strikeDiscount / 100.0));
        if ($discountBase <= (int)round($price)) {
            $discountBase = 0;
        }
    }
    $isExcludedFromBestsellers = !empty($calc['excluded_from_bestsellers']);
    $minimumForBestseller = $isExcludedFromBestsellers ? 0.0 : (float)($calc['recommended_min_price'] ?? 0);
    return [
        'offer_id' => $offerId,
        'remote_offer_id' => yandex_price_tool_offer_id_to_marketplace($offerId),
        'price_scope' => (string)($calc['price_scope'] ?? 'business'),
        'excluded_from_bestsellers' => $isExcludedFromBestsellers,
        'price' => max(1, (int)round($price)),
        'currency_id' => 'RUR',
        'discount_base' => max(0, $discountBase),
        'minimum_for_bestseller' => $minimumForBestseller > 0 ? max(1, (int)round($minimumForBestseller)) : 0,
        'current_price' => $calc['current_price'] !== null ? (int)round((float)$calc['current_price']) : null,
        'current_discount_base' => $calc['current_discount_base'] !== null ? (int)round((float)$calc['current_discount_base']) : null,
        'current_minimum_for_bestseller' => $calc['current_minimum_for_bestseller'] !== null ? (int)round((float)$calc['current_minimum_for_bestseller']) : null,
        'boost_bid' => null,
    ];
}

function yandex_price_tool_recommendation_threshold(?array $recommendation, string $key): ?float
{
    if (!is_array($recommendation)) {
        return null;
    }
    $thresholds = is_array($recommendation['recommendation']['competitivenessThresholds'] ?? null)
        ? (array)$recommendation['recommendation']['competitivenessThresholds']
        : [];
    $raw = $thresholds[$key] ?? 0;
    $value = is_array($raw) ? yandex_price_tool_to_float($raw['value'] ?? 0) : yandex_price_tool_to_float($raw);
    return $value > 0 ? round($value, 2) : null;
}

function yandex_price_tool_calculate_offer(
    array $settings,
    array $offer,
    ?array $priceItem,
    ?array $mapping,
    ?array $recommendation,
    ?array $tariffRow
): array {
    $offerId = trim((string)($offer['offer_id'] ?? ''));
    $purchaseCost = isset($offer['purchase_cost']) ? (float)$offer['purchase_cost'] : null;
    $warnings = array_values(array_filter((array)($offer['warnings'] ?? [])));
    $currentPrice = yandex_price_tool_extract_price_value($priceItem, 'value');
    $currentDiscountBase = yandex_price_tool_extract_price_value($priceItem, 'discountBase');
    $currentMinimum = yandex_price_tool_extract_price_value($priceItem, 'minimumForBestseller');
    $excludedFromBestsellers = yandex_price_tool_extract_price_bool($priceItem, 'excludedFromBestsellers') === true;
    $priceScope = is_array($priceItem) ? trim((string)($priceItem['price_scope'] ?? 'business')) : 'business';
    if (!in_array($priceScope, ['business', 'campaign'], true)) {
        $priceScope = 'business';
    }
    $referencePrice = $currentPrice !== null && $currentPrice > 0 ? $currentPrice : max(1.0, (float)$purchaseCost * 1.5);
    $runtimeExpenses = yandex_price_tool_resolve_marketplace_expenses($settings, $offer, $mapping, $tariffRow, $referencePrice);
    foreach ((array)($runtimeExpenses['warnings'] ?? []) as $warning) {
        $warning = trim((string)$warning);
        if ($warning !== '') {
            $warnings[] = $warning;
        }
    }

    $row = [
        'offer_id' => $offerId,
        'remote_offer_id' => yandex_price_tool_offer_id_to_marketplace($offerId),
        'name' => (string)($offer['name'] ?? ''),
        'purchase_cost' => $purchaseCost,
        'purchase_cost_raw' => (string)($offer['cost_raw'] ?? ''),
        'current_price' => $currentPrice,
        'current_discount_base' => $currentDiscountBase,
        'current_minimum_for_bestseller' => $currentMinimum,
        'price_scope' => $priceScope,
        'excluded_from_bestsellers' => $excludedFromBestsellers,
        'recommended_price' => null,
        'recommended_min_price' => null,
        'profit_rub' => null,
        'profit_percent' => null,
        'competitiveness' => (string)($recommendation['offer']['competitiveness'] ?? ''),
        'optimal_price' => yandex_price_tool_recommendation_threshold($recommendation, 'optimalPrice'),
        'average_price' => yandex_price_tool_recommendation_threshold($recommendation, 'averagePrice'),
        'status' => 'ok',
        'warnings' => $warnings,
        'desired_state' => null,
        'runtime_expenses' => $runtimeExpenses,
        'breakdown' => [],
    ];
    if ($offerId === '') {
        $row['warnings'][] = 'В фиде не удалось определить offer_id.';
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
    if (!is_array($priceItem)) {
        $row['warnings'][] = 'Товар не найден в списке цен Яндекс Маркета по offerId. Проверь формат артикула: для Яндекса внутренний `__` отправляется как `000`.';
        $row['status'] = 'warn';
    }
    if (is_array($priceItem) && trim((string)($priceItem['price_scope_warning'] ?? '')) !== '') {
        $row['warnings'][] = trim((string)$priceItem['price_scope_warning']);
    }
    if ($excludedFromBestsellers) {
        $row['warnings'][] = 'Яндекс пометил товар как исключённый из «Бестселлеров Маркета»: minimumForBestseller будет рассчитан для справки, но не отправится.';
    }
    if (!is_array($mapping)) {
        $row['warnings'][] = 'Яндекс API не вернул карточку/маппинг товара: категория и габариты могут быть неполными.';
    }

    $targetProfitRub = yandex_price_tool_target_profit_rub($settings, $purchaseCost, false);
    $solved = yandex_price_tool_solve_sale_price($settings, $purchaseCost, $targetProfitRub, $runtimeExpenses);
    if (!is_array($solved)) {
        $row['warnings'][] = 'Не удалось подобрать цену с заданной целевой прибылью.';
        $row['status'] = $row['status'] === 'error' ? 'error' : 'warn';
        return $row;
    }
    $salePrice = ozon_price_apply_modifier((float)$solved['sale_price'], $settings, false);
    $supplierPriceModifier = trim((string)($offer['supplier_price_modifier'] ?? ''));
    if ($supplierPriceModifier !== '' && function_exists('supplier_products_apply_price_modifier')) {
        $salePrice = supplier_products_apply_price_modifier((float)$salePrice, $supplierPriceModifier) ?? $salePrice;
    }
    $salePrice = ozon_price_round($salePrice, (string)($settings['rounding_mode'] ?? 'rub'));
    $minProfitRub = yandex_price_tool_target_profit_rub($settings, $purchaseCost, true);
    $minSolved = yandex_price_tool_solve_sale_price($settings, $purchaseCost, $minProfitRub, $runtimeExpenses);
    $minPrice = is_array($minSolved) ? (float)$minSolved['sale_price'] : $salePrice;
    $minPrice = ozon_price_apply_modifier($minPrice, $settings, true);
    if ($supplierPriceModifier !== '' && function_exists('supplier_products_apply_price_modifier')) {
        $minPrice = supplier_products_apply_price_modifier((float)$minPrice, $supplierPriceModifier) ?? $minPrice;
    }
    $minPrice = ozon_price_round($minPrice, (string)($settings['rounding_mode'] ?? 'rub'));

    $optimalPrice = (float)($row['optimal_price'] ?? 0);
    if (!empty($settings['min_price_index_step_enabled']) && $optimalPrice > 0 && $optimalPrice < $minPrice) {
        $thresholdSnapshot = yandex_price_tool_profit_snapshot($settings, $purchaseCost, $optimalPrice, $runtimeExpenses);
        if ((float)($thresholdSnapshot['profit_rub'] ?? 0) >= $minProfitRub) {
            $minPrice = ozon_price_round($optimalPrice, (string)($settings['rounding_mode'] ?? 'rub'));
            $row['warnings'][] = 'minimumForBestseller снижен до рекомендованной Яндексом оптимальной цены, потому что целевая минимальная прибыль сохраняется.';
        }
    }
    $snapshot = yandex_price_tool_profit_snapshot($settings, $purchaseCost, $salePrice, $runtimeExpenses);
    $row['recommended_price'] = round($salePrice, 2);
    $row['recommended_min_price'] = round(min($salePrice, $minPrice), 2);
    $row['profit_rub'] = (float)($snapshot['profit_rub'] ?? 0.0);
    $row['profit_percent'] = (float)($snapshot['profit_on_cost_percent'] ?? 0.0);
    $row['breakdown'] = is_array($snapshot['breakdown'] ?? null) ? (array)$snapshot['breakdown'] : [];
    $row['breakdown']['target_profit_rub'] = round($targetProfitRub, 2);
    $row['breakdown']['min_target_profit_rub'] = round($minProfitRub, 2);
    $row['breakdown']['target_profit_percent_effective'] = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, false);
    $row['breakdown']['min_target_profit_percent_effective'] = ozon_price_resolve_target_profit_percent($settings, $purchaseCost, true);
    $row['breakdown']['supplier_price_modifier'] = $supplierPriceModifier;
    $row['desired_state'] = yandex_price_tool_build_desired_state($settings, $row);
    return $row;
}

function yandex_price_tool_desired_state_needs_push(array $calc): bool
{
    $state = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : null;
    if ($state === null) {
        return false;
    }
    if ($state['current_price'] === null) {
        return true;
    }
    if ((int)$state['current_price'] !== (int)$state['price']) {
        return true;
    }
    if ((int)($state['current_discount_base'] ?? 0) !== (int)($state['discount_base'] ?? 0)) {
        return true;
    }
    if (empty($state['excluded_from_bestsellers']) && (int)($state['current_minimum_for_bestseller'] ?? 0) !== (int)($state['minimum_for_bestseller'] ?? 0)) {
        return true;
    }
    return $state['boost_bid'] !== null;
}

function yandex_price_tool_build_update_offer_payload(array $row, bool $includeBasePrice, bool $includeMinimum): ?array
{
    $remoteOfferId = trim((string)($row['remote_offer_id'] ?? ''));
    if ($remoteOfferId === '') {
        return null;
    }
    $price = [];
    if ($includeBasePrice) {
        $price['value'] = (int)$row['price'];
        $price['currencyId'] = (string)($row['currency_id'] ?? 'RUR');
        if ((int)($row['discount_base'] ?? 0) > 0) {
            $price['discountBase'] = (int)$row['discount_base'];
        }
    }
    if ($includeMinimum && empty($row['excluded_from_bestsellers']) && (int)($row['minimum_for_bestseller'] ?? 0) > 0) {
        $price['minimumForBestseller'] = (int)$row['minimum_for_bestseller'];
    }
    if (!$price) {
        return null;
    }
    return [
        'offerId' => $remoteOfferId,
        'price' => $price,
    ];
}

function yandex_price_tool_apply_updates(array $cfg, array $connection, array $desiredStates): array
{
    $context = yandex_price_tool_context($connection);
    $businessId = (int)$context['business_id'];
    $campaignId = (int)$context['campaign_id'];
    $useCampaignPriceScope = empty($context['only_default_price']) && $campaignId > 0;
    $desiredStates = array_values(array_filter($desiredStates, static fn($row): bool => is_array($row) && trim((string)($row['remote_offer_id'] ?? '')) !== ''));
    if (!$desiredStates) {
        return ['accepted' => 0, 'uploads' => [], 'errors' => ['Нет товаров для отправки в Яндекс Маркет.']];
    }
    $report = ['accepted' => 0, 'uploads' => [], 'errors' => []];
    $acceptedOfferIds = [];

    $send = static function (string $path, array $rows, string $kind, bool $includeBasePrice, bool $includeMinimum, int $chunkSize) use ($connection, &$report, &$acceptedOfferIds): void {
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $offers = [];
            foreach ($chunk as $row) {
                $payload = yandex_price_tool_build_update_offer_payload((array)$row, $includeBasePrice, $includeMinimum);
                if ($payload !== null) {
                    $offers[] = $payload;
                }
            }
            if (!$offers) {
                continue;
            }
            try {
                marketplace_connection_yandex_request($connection, 'POST', $path, [], ['offers' => $offers]);
                foreach ($offers as $offerPayload) {
                    $acceptedOfferIds[(string)($offerPayload['offerId'] ?? '')] = true;
                }
                $report['accepted'] = count(array_filter(array_keys($acceptedOfferIds), static fn(string $offerId): bool => $offerId !== ''));
                $report['uploads'][] = ['kind' => $kind, 'items' => count($offers), 'status' => 'accepted'];
            } catch (Throwable $e) {
                $report['errors'][] = $kind . ': ' . $e->getMessage();
            }
        }
    };

    if ($useCampaignPriceScope) {
        $campaignRows = [];
        $businessMinRows = [];
        $businessDefaultRows = [];
        foreach ($desiredStates as $row) {
            $scope = (string)($row['price_scope'] ?? 'business');
            if ($scope === 'campaign') {
                $campaignRows[] = $row;
                if (empty($row['excluded_from_bestsellers']) && (int)($row['minimum_for_bestseller'] ?? 0) > 0) {
                    $businessMinRows[] = $row;
                }
            } else {
                $businessDefaultRows[] = $row;
            }
        }
        $send('/v2/campaigns/' . $campaignId . '/offer-prices/updates', $campaignRows, 'prices_campaign', true, false, 500);
        $send('/v2/businesses/' . $businessId . '/offer-prices/updates', $businessDefaultRows, 'prices_business', true, true, 500);
        $send('/v2/businesses/' . $businessId . '/offer-prices/updates', $businessMinRows, 'minimum_for_bestseller', false, true, 500);
        return $report;
    }

    foreach (array_chunk($desiredStates, 500) as $chunk) {
        $offers = [];
        foreach ($chunk as $row) {
            $payload = yandex_price_tool_build_update_offer_payload((array)$row, true, true);
            if ($payload !== null) {
                $offers[] = $payload;
            }
        }
        if (!$offers) {
            continue;
        }
        try {
            marketplace_connection_yandex_request($connection, 'POST', '/v2/businesses/' . $businessId . '/offer-prices/updates', [], ['offers' => $offers]);
            foreach ($offers as $offerPayload) {
                $acceptedOfferIds[(string)($offerPayload['offerId'] ?? '')] = true;
            }
            $report['accepted'] = count(array_filter(array_keys($acceptedOfferIds), static fn(string $offerId): bool => $offerId !== ''));
            $report['uploads'][] = ['kind' => 'prices', 'items' => count($offers), 'status' => 'accepted'];
        } catch (Throwable $e) {
            $report['errors'][] = 'Цены: ' . $e->getMessage();
        }
    }
    return $report;
}
