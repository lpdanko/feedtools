<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg = require $root . '/app/config.php';
require_once $root . '/app/ozon_price_tool.php';

$requestedIds = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
$connections = ozon_price_connection_list($cfg, 'ozon');
if ($requestedIds) {
    $connections = array_values(array_filter(
        $connections,
        static fn(array $row): bool => in_array((int)($row['id'] ?? 0), $requestedIds, true)
    ));
}

function audit_dimension_liters(array $item): ?float
{
    $width = (float)($item['width'] ?? 0);
    $height = (float)($item['height'] ?? 0);
    $depth = (float)($item['depth'] ?? 0);
    if ($width <= 0 || $height <= 0 || $depth <= 0) {
        return null;
    }

    $divisor = match (strtolower(trim((string)($item['dimension_unit'] ?? 'mm')))) {
        'cm' => 1000.0,
        'm' => 0.001,
        default => 1000000.0,
    };
    return round(($width * $height * $depth) / $divisor, 4);
}

foreach ($connections as $connection) {
    $connectionId = (int)($connection['id'] ?? 0);
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($runtimeCfg);
    $cursor = '';
    $cheap = [];

    do {
        $payload = [
            'filter' => ['visibility' => 'ALL'],
            'limit' => 1000,
        ];
        if ($cursor !== '') {
            $payload['cursor'] = $cursor;
        }
        $response = ozon_post_json($oz, '/v5/product/info/prices', $payload);
        foreach ((array)($response['items'] ?? []) as $item) {
            $salePrice = (float)($item['price']['marketing_seller_price'] ?? 0);
            if ($salePrice <= 0) {
                $salePrice = (float)($item['price']['price'] ?? 0);
            }
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '' && $salePrice > 0 && $salePrice <= 300.0) {
                $cheap[$offerId] = $item + ['_sale_price' => $salePrice];
            }
        }
        $cursor = trim((string)($response['cursor'] ?? ''));
    } while ($cursor !== '');

    $dimensions = [];
    foreach (array_chunk(array_keys($cheap), 100) as $chunk) {
        $response = ozon_post_json($oz, '/v4/product/info/attributes', [
            'filter' => [
                'offer_id' => array_values($chunk),
                'visibility' => 'ALL',
            ],
            'limit' => count($chunk),
        ]);
        foreach ((array)($response['result'] ?? []) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $dimensions[$offerId] = $item;
            }
        }
    }

    $groups = [];
    $samples = [];
    foreach ($cheap as $offerId => $item) {
        $commissions = (array)($item['commissions'] ?? []);
        $liters = audit_dimension_liters((array)($dimensions[$offerId] ?? []));
        $direct = (float)($commissions['fbs_direct_flow_trans_min_amount'] ?? 0);
        $commission = (float)($commissions['sales_percent_fbs'] ?? 0);
        $key = number_format($direct, 2, '.', '');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'direct_flow_rub' => $direct,
                'count' => 0,
                'min_liters' => null,
                'max_liters' => null,
                'commission_rates' => [],
            ];
        }
        $groups[$key]['count']++;
        $groups[$key]['commission_rates'][(string)$commission] = true;
        if ($liters !== null) {
            $groups[$key]['min_liters'] = $groups[$key]['min_liters'] === null
                ? $liters
                : min((float)$groups[$key]['min_liters'], $liters);
            $groups[$key]['max_liters'] = $groups[$key]['max_liters'] === null
                ? $liters
                : max((float)$groups[$key]['max_liters'], $liters);
        }
        if (count($samples) < 20 || ($liters !== null && $liters > 1.5)) {
            $samples[] = [
                'offer_id' => $offerId,
                'price' => (float)$item['_sale_price'],
                'liters' => $liters,
                'volume_weight' => isset($item['volume_weight']) ? (float)$item['volume_weight'] : null,
                'commission_percent' => $commission,
                'last_mile_rub' => (float)($commissions['fbs_deliv_to_customer_amount'] ?? 0),
                'direct_flow_rub' => $direct,
                'first_mile_min_rub' => (float)($commissions['fbs_first_mile_min_amount'] ?? 0),
                'first_mile_max_rub' => (float)($commissions['fbs_first_mile_max_amount'] ?? 0),
            ];
        }
    }

    foreach ($groups as &$group) {
        $group['commission_rates'] = array_map('floatval', array_keys($group['commission_rates']));
        sort($group['commission_rates'], SORT_NUMERIC);
    }
    unset($group);
    uasort($groups, static fn(array $a, array $b): int => $a['direct_flow_rub'] <=> $b['direct_flow_rub']);
    usort($samples, static fn(array $a, array $b): int => ($a['liters'] ?? PHP_FLOAT_MAX) <=> ($b['liters'] ?? PHP_FLOAT_MAX));

    echo json_encode([
        'connection_id' => $connectionId,
        'connection' => (string)($connection['title'] ?? ''),
        'cheap_products' => count($cheap),
        'tariff_groups' => array_values($groups),
        'samples' => $samples,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
}
