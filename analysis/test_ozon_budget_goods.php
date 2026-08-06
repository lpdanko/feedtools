<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/ozon_price_tool.php';

function budget_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function budget_assert_amount(float $expected, float $actual, string $message): void
{
    budget_assert(abs($expected - $actual) < 0.001, $message . ': expected ' . $expected . ', got ' . $actual);
}

$dimensions = [
    'depth' => 100,
    'width' => 50,
    'height' => 20,
    'dimension_unit' => 'mm',
];
$volume = ozon_price_budget_goods_volume_liters($dimensions);
budget_assert(is_array($volume), 'Dimensions must produce volume');
budget_assert_amount(0.1, (float)$volume['value'], 'Millimetres must convert to litres');
budget_assert($volume['source'] === 'ozon_dimensions', 'Volume source must be Ozon dimensions');
budget_assert(
    ozon_price_budget_goods_volume_liters(['volume_weight' => 0.1]) === null,
    'volume_weight must not be treated as litres'
);

$cheapItem = $dimensions + [
    'price' => [
        'price' => '192',
        'marketing_seller_price' => '192',
    ],
    'commissions' => [
        'sales_percent_fbs' => 20,
        'sales_percent_fbo' => 20,
        'fbs_deliv_to_customer_amount' => 25,
        'fbs_direct_flow_trans_min_amount' => 17.28,
        'fbs_direct_flow_trans_max_amount' => 17.28,
        'fbs_first_mile_min_amount' => 0,
        'fbs_first_mile_max_amount' => 10,
        'fbo_deliv_to_customer_amount' => 25,
        'fbo_direct_flow_trans_min_amount' => 17.28,
        'fbo_direct_flow_trans_max_amount' => 17.28,
    ],
];

$profile = ozon_price_budget_goods_tariff_profile($cheapItem, 192, 'fbs');
budget_assert(is_array($profile), 'Cheap item must have a tariff profile');
budget_assert_amount(20, (float)$profile['commission_percent'], '100.01-300 commission');
budget_assert_amount(17.28, (float)$profile['cluster_logistics_amount'], 'Current API direct logistics');
budget_assert($profile['logistics_source'] === 'ozon_api_current_tariff', 'Current API tariff must take priority');

$profile100 = ozon_price_budget_goods_tariff_profile($cheapItem, 100, 'fbs');
budget_assert_amount(14, (float)$profile100['commission_percent'], 'Up to 100 commission');
budget_assert(ozon_price_budget_goods_tariff_profile($cheapItem, 300.01, 'fbs') === null, 'Above 300 must not use cheap tariff');
$cheapCommission = ozon_price_sales_commission_profile($cheapItem, 'fbs', 192);
budget_assert_amount(20, (float)$cheapCommission['percent'], 'Cheap commission helper');
$fallbackStandardCommission = ozon_price_sales_commission_profile($cheapItem, 'fbs', 450);
budget_assert_amount(50, (float)$fallbackStandardCommission['percent'], 'FBS standard commission fallback');
$typedStandardItem = $cheapItem + ['standard_sales_percent_fbs' => 37];
$typedStandardCommission = ozon_price_sales_commission_profile($typedStandardItem, 'fbs', 450);
budget_assert_amount(37, (float)$typedStandardCommission['percent'], 'Same-type standard commission');

$schemeCosts = ozon_price_project_scheme_costs($cheapItem, 'fbs', 192);
budget_assert_amount(25, (float)$schemeCosts['delivery_to_customer'], 'Last mile must remain separate');
budget_assert_amount(17.28, (float)$schemeCosts['direct_flow'], 'Cheap direct logistics must remain separate');
budget_assert_amount(10, (float)$schemeCosts['first_mile'], 'FBS first mile conservative amount');

$crossingItem = $dimensions + [
    'price' => ['price' => '450', 'marketing_seller_price' => '450'],
    'commissions' => $cheapItem['commissions'],
];
$crossingProfile = ozon_price_budget_goods_tariff_profile($crossingItem, 299, 'fbs');
budget_assert(is_array($crossingProfile), 'Crossing below 300 must use the dimension tariff');
budget_assert_amount(17.28, (float)$crossingProfile['cluster_logistics_amount'], 'Crossing tariff at 0.1 litre');
budget_assert($crossingProfile['logistics_source'] === 'ozon_dimensions_tariff', 'Crossing must use dimensions');

$inactiveItem = $crossingItem;
$inactiveItem['price'] = ['price' => '275', 'marketing_seller_price' => '0'];
$inactiveItem['commissions'] = [
    'sales_percent_fbs' => 0,
    'fbs_deliv_to_customer_amount' => 0,
    'fbs_direct_flow_trans_min_amount' => 0,
    'fbs_direct_flow_trans_max_amount' => 0,
    'fbs_first_mile_min_amount' => 0,
    'fbs_first_mile_max_amount' => 0,
];
$inactiveCosts = ozon_price_project_scheme_costs($inactiveItem, 'fbs', 275);
budget_assert_amount(25, (float)$inactiveCosts['delivery_to_customer'], 'Inactive cheap item last-mile fallback');
budget_assert_amount(17.28, (float)$inactiveCosts['direct_flow'], 'Inactive cheap item dimension tariff');
budget_assert_amount(10, (float)$inactiveCosts['first_mile'], 'Inactive cheap item FBS first-mile fallback');

$standardTransitionItem = $cheapItem + [
    'standard_fbs_deliv_to_customer_amount' => 25,
    'standard_fbs_direct_flow_trans_min_amount' => 74.9,
    'standard_fbs_direct_flow_trans_max_amount' => 74.9,
    'standard_fbs_first_mile_max_amount' => 10,
    'standard_fbs_return_flow_amount' => 98,
];
$standardTransitionCosts = ozon_price_project_scheme_costs($standardTransitionItem, 'fbs', 450);
budget_assert_amount(25, (float)$standardTransitionCosts['delivery_to_customer'], 'Standard-price last mile');
budget_assert_amount(74.9, (float)$standardTransitionCosts['direct_flow'], 'Standard-price weighted direct logistics');
budget_assert_amount(10, (float)$standardTransitionCosts['first_mile'], 'Standard-price first mile');
budget_assert_amount(98, (float)$standardTransitionCosts['return_flow'], 'Standard-price return logistics');

budget_assert_amount(29.48, (float)ozon_price_budget_goods_logistics_amount(1.8), '1.5-2 litre tariff');
budget_assert_amount(31.52, (float)ozon_price_budget_goods_logistics_amount(2.9), '2-3 litre tariff');

echo "OK: Ozon budget-goods pricing regression passed.\n";
