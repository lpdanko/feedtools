<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/marketplace_brand_status.php';
require_once __DIR__ . '/../app/supplier_products_marketplace_row_actions.php';

header('Content-Type: application/json; charset=utf-8');

function supplier_product_update_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function supplier_product_update_standard_brand_map(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT field_name, field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'standard'
          AND field_name IN ('brand', 'brand_ozon', 'brand_wb')
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $name = (string)($row['field_name'] ?? '');
        if ($name !== '' && !array_key_exists($name, $out)) {
            $out[$name] = (string)($row['field_value'] ?? '');
        }
    }
    return $out;
}

function supplier_product_update_attach_brand_statuses(array $product): array
{
    $productId = (int)($product['id'] ?? 0);
    $brand = trim((string)($product['brand_effective'] ?? $product['brand'] ?? ''));
    $standardBrands = supplier_product_update_standard_brand_map($productId);
    if (array_key_exists('brand', $standardBrands)) {
        $brand = trim((string)$standardBrands['brand']);
    }
    if ($brand === '') {
        $brand = trim((string)($product['brand'] ?? ''));
    }
    $brandOzon = array_key_exists('brand_ozon', $standardBrands) ? trim((string)$standardBrands['brand_ozon']) : trim((string)($product['brand_ozon_effective'] ?? ''));
    $brandWb = array_key_exists('brand_wb', $standardBrands) ? trim((string)$standardBrands['brand_wb']) : trim((string)($product['brand_wb_effective'] ?? ''));
    if ($productId > 0) {
        if (!array_key_exists('brand_ozon', $standardBrands) && $brandOzon === '') {
            $brandOzon = $brand;
        }
        if (!array_key_exists('brand_wb', $standardBrands) && $brandWb === '') {
            $brandWb = $brand;
        }
    }
    $product['brand_effective'] = $brand;
    $product['brand_ozon_effective'] = $brandOzon;
    $product['brand_wb_effective'] = $brandWb;
    $product['brand_statuses'] = marketplace_brand_status_for_product([
        'brand' => $brand,
        'brand_ozon' => $brandOzon,
        'brand_wb' => $brandWb,
        'ozon_category' => (string)($product['ozon_category'] ?? ''),
        'wb_category' => (string)($product['wb_category'] ?? ($product['wb_subject_id'] ?? '')),
    ]);
    $product['marketplace_enabled'] = supplier_products_normalize_marketplace_enabled($product['marketplace_enabled'] ?? 1);
    $product['stock_modifier'] = (int)($product['stock_modifier'] ?? 0);
    $product['price_modifier'] = supplier_products_normalize_price_modifier($product['price_modifier'] ?? '');
    $product['effective_stock'] = supplier_products_effective_stock_from_product($product);
    $product['effective_price'] = supplier_products_effective_price_from_product($product);
    return $product;
}

function supplier_product_update_attach_result_brand_statuses(array $result): array
{
    if (isset($result['product']) && is_array($result['product'])) {
        $result['product'] = supplier_product_update_attach_brand_statuses($result['product']);
    }
    if (isset($result['products']) && is_array($result['products'])) {
        foreach ($result['products'] as $idx => $product) {
            if (is_array($product)) {
                $result['products'][$idx] = supplier_product_update_attach_brand_statuses($product);
            }
        }
    }
    return $result;
}

function supplier_product_update_offer_ids_from_payload($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map(static function ($item): string {
        return trim((string)$item);
    }, $value), static fn(string $item): bool => $item !== ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    supplier_product_update_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

try {
    $action = trim((string)($payload['action'] ?? ''));
    if ($action === 'update_basic') {
        $productId = (int)($payload['product_id'] ?? 0);
        $field = trim((string)($payload['field'] ?? ''));
        $value = (string)($payload['value'] ?? '');
        $product = supplier_products_update_basic_field($productId, $field, $value, $cfg);
        supplier_product_update_json([
            'ok' => true,
            'product' => supplier_product_update_attach_brand_statuses($product),
        ]);
    }

    if ($action === 'offer_controls_update') {
        $productId = (int)($payload['product_id'] ?? 0);
        $product = supplier_products_update_offer_controls(
            $productId,
            $payload['marketplace_enabled'] ?? 1,
            $payload['stock_modifier'] ?? 0,
            $payload['price_modifier'] ?? '',
            $cfg
        );
        supplier_product_update_json([
            'ok' => true,
            'product' => supplier_product_update_attach_brand_statuses($product),
        ]);
    }

    if ($action === 'bulk_offer_controls_update') {
        @set_time_limit(0);
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = supplier_product_update_offer_ids_from_payload($payload['offer_ids'] ?? []);
        $scope = (string)($payload['scope'] ?? 'selected');
        $updates = $payload['updates'] ?? [];
        if (!is_array($updates)) {
            $updates = [];
        }
        $result = supplier_products_bulk_update_offer_controls($datasetId, $offerIds, $updates, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'field_add') {
        $productId = (int)($payload['product_id'] ?? 0);
        $kind = trim((string)($payload['kind'] ?? 'param'));
        $name = (string)($payload['name'] ?? '');
        $value = (string)($payload['value'] ?? '');
        $result = supplier_products_add_field($productId, $kind, $name, $value, $cfg);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'field_update') {
        $fieldId = (int)($payload['field_id'] ?? 0);
        $name = (string)($payload['name'] ?? '');
        $value = (string)($payload['value'] ?? '');
        $result = supplier_products_update_field($fieldId, $name, $value, $cfg);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'same_model_set') {
        $productId = (int)($payload['product_id'] ?? 0);
        $value = (string)($payload['value'] ?? '');
        $result = supplier_products_set_same_model($productId, $value, $cfg);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'category_set') {
        $productId = (int)($payload['product_id'] ?? 0);
        $source = trim((string)($payload['source'] ?? ''));
        $value = (string)($payload['value'] ?? '');
        $result = supplier_products_set_category_field($productId, $source, $value, $cfg);
        $attrsResult = null;
        $connectionOverrideId = (int)($payload['connection_id'] ?? 0);
        if ($productId > 0 && trim($value) !== '') {
            $st = db()->prepare("SELECT supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
            $st->execute([$productId]);
            $supplierId = (int)($st->fetchColumn() ?: 0);
            if ($supplierId > 0) {
                $datasetId = supplier_products_dataset_id($supplierId, $cfg);
                $attrsResult = supplier_market_row_ensure_category_attributes_for_value($datasetId, $source, $value, $cfg, $connectionOverrideId);
            }
        }
        if (is_array($attrsResult)) {
            $result['category_attributes'] = $attrsResult;
        }
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'category_bulk_set') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $source = trim((string)($payload['source'] ?? ''));
        $value = (string)($payload['value'] ?? '');
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $result = supplier_products_set_category_field_bulk($datasetId, $offerIds, $source, $value, $cfg);
        if ($datasetId > 0 && trim($value) !== '') {
            $connectionOverrideId = (int)($payload['connection_id'] ?? 0);
            $result['category_attributes'] = supplier_market_row_ensure_category_attributes_for_value($datasetId, $source, $value, $cfg, $connectionOverrideId);
        }
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'tnved_bulk_values') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $query = trim((string)($payload['query'] ?? ''));
        $connectionId = (int)($payload['connection_id'] ?? 0);

        $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
        if ($supplierId <= 0) {
            throw new RuntimeException('Датасет поставщика не найден.');
        }
        $products = supplier_products_ids_for_selected_offers($supplierId, $offerIds, $cfg);
        $values = [];
        $seen = [];
        $usedProducts = 0;
        $categoryValues = supplier_products_bulk_category_values_for_products(array_keys($products));
        $searchedCategories = [];
        foreach (array_keys($products) as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $categoryValue = trim((string)(($categoryValues[$productId] ?? [])['ozon'] ?? ''));
            if ($categoryValue === '' || isset($searchedCategories[$categoryValue])) {
                continue;
            }
            $searchedCategories[$categoryValue] = true;
            $tnvedFieldName = supplier_products_tnved_field_name_for_product($productId, $categoryValues, $cfg);
            $items = supplier_market_row_characteristic_allowed_values_for_product(
                $productId,
                'ozon',
                $tnvedFieldName,
                $cfg,
                $connectionId,
                $query
            );
            if ($items) {
                $usedProducts++;
            }
            foreach ($items as $value) {
                $value = trim((string)$value);
                $key = supplier_products_characteristic_norm_value($value);
                if ($value === '' || $key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $values[] = $value;
                if (count($values) >= 300) {
                    break 2;
                }
            }
            if ($usedProducts >= 8 && count($values) >= 60) {
                break;
            }
        }

        supplier_product_update_json([
            'ok' => true,
            'found' => count($products),
            'used_products' => $usedProducts,
            'values' => array_map(static fn($value): array => ['value' => (string)$value], $values),
        ]);
    }

    if ($action === 'tnved_bulk_set') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $value = (string)($payload['value'] ?? '');
        $result = supplier_products_set_tnved_code_bulk($datasetId, $offerIds, $value, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'bulk_replace_text') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $target = $payload['target'] ?? [];
        if (!is_array($target)) {
            $target = [];
        }
        $oldText = (string)($payload['old_text'] ?? '');
        $newText = (string)($payload['new_text'] ?? '');
        $scope = (string)($payload['scope'] ?? 'selected');
        $result = supplier_products_bulk_replace_text($datasetId, $offerIds, $target, $oldText, $newText, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'bulk_replace_values') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $target = $payload['target'] ?? [];
        if (!is_array($target)) {
            $target = [];
        }
        $scope = (string)($payload['scope'] ?? 'selected');
        $result = supplier_products_bulk_replace_values($datasetId, $offerIds, $target, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'bulk_copy_field_options') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $scope = (string)($payload['scope'] ?? 'selected');
        $result = supplier_products_bulk_copy_field_options($datasetId, $offerIds, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'bulk_copy_field_values') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $target = $payload['target'] ?? [];
        if (!is_array($target)) {
            $target = [];
        }
        $source = $payload['source'] ?? [];
        if (!is_array($source)) {
            $source = [];
        }
        $scope = (string)($payload['scope'] ?? 'selected');
        $skipEmpty = !isset($payload['skip_empty']) || (string)$payload['skip_empty'] !== '0';
        $result = supplier_products_bulk_copy_field_values($datasetId, $offerIds, $target, $source, $cfg, $scope, $skipEmpty);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'bulk_replace_characteristics') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $scope = (string)($payload['scope'] ?? 'selected');
        $result = supplier_products_bulk_replace_characteristics($datasetId, $offerIds, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'brand_bulk_replace_preview') {
        $productId = (int)($payload['product_id'] ?? 0);
        $marketplace = (string)($payload['marketplace'] ?? '');
        $oldBrand = (string)($payload['old_brand'] ?? '');
        $newBrand = (string)($payload['new_brand'] ?? '');
        $result = supplier_products_brand_bulk_replace_preview($productId, $marketplace, $oldBrand, $newBrand, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'brand_bulk_replace_by_category') {
        $productId = (int)($payload['product_id'] ?? 0);
        $marketplace = (string)($payload['marketplace'] ?? '');
        $oldBrand = (string)($payload['old_brand'] ?? '');
        $newBrand = (string)($payload['new_brand'] ?? '');
        $result = supplier_products_brand_bulk_replace_by_category($productId, $marketplace, $oldBrand, $newBrand, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'marketplace_characteristic_values') {
        $productId = (int)($payload['product_id'] ?? 0);
        $source = (string)($payload['source'] ?? '');
        $name = (string)($payload['name'] ?? '');
        $connectionId = (int)($payload['connection_id'] ?? 0);
        $query = (string)($payload['query'] ?? '');
        $values = supplier_market_row_characteristic_allowed_values_for_product($productId, $source, $name, $cfg, $connectionId, $query);
        if (!$values) {
            $values = supplier_products_marketplace_characteristic_allowed_values_for_product($productId, $source, $name, $cfg);
        }
        supplier_product_update_json([
            'ok' => true,
            'values' => array_map(static fn($value): array => ['value' => (string)$value], $values),
        ]);
    }

    if ($action === 'marketplace_row_action') {
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $productId = (int)($payload['product_id'] ?? 0);
        $offerId = (string)($payload['offer_id'] ?? '');
        $marketplace = (string)($payload['marketplace'] ?? '');
        $mode = (string)($payload['mode'] ?? '');
        $connectionId = (int)($payload['connection_id'] ?? 0);
        $result = supplier_market_row_action($datasetId, $productId, $offerId, $marketplace, $mode, $cfg, $connectionId);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'bundle_create') {
        $productId = (int)($payload['product_id'] ?? 0);
        $qty = (int)($payload['qty'] ?? 0);
        $name = (string)($payload['name'] ?? '');
        $result = supplier_products_create_bundle($productId, $qty, $name, [
            'length' => (string)($payload['length'] ?? ''),
            'width' => (string)($payload['width'] ?? ''),
            'height' => (string)($payload['height'] ?? ''),
            'weight' => (string)($payload['weight'] ?? ''),
        ], $cfg);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    if ($action === 'bulk_bundle_create') {
        @set_time_limit(0);
        $datasetId = (int)($payload['dataset_id'] ?? 0);
        $offerIds = $payload['offer_ids'] ?? [];
        if (is_string($offerIds)) {
            $decoded = json_decode($offerIds, true);
            $offerIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($offerIds)) {
            $offerIds = [];
        }
        $qty = (int)($payload['qty'] ?? 0);
        $scope = (string)($payload['scope'] ?? 'selected');
        $options = $payload['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        $result = supplier_products_bulk_create_bundles($datasetId, $offerIds, $qty, $options, $cfg, $scope);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'field_delete') {
        $fieldId = (int)($payload['field_id'] ?? 0);
        $result = supplier_products_delete_field($fieldId, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'picture_delete') {
        $productId = (int)($payload['product_id'] ?? 0);
        $index = (int)($payload['index'] ?? -1);
        $result = supplier_products_delete_picture($productId, $index, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'picture_reorder') {
        $productId = (int)($payload['product_id'] ?? 0);
        $pictures = $payload['pictures'] ?? [];
        if (!is_array($pictures)) {
            $pictures = [];
        }
        $result = supplier_products_set_picture_order($productId, $pictures, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'picture_add_url') {
        $productId = (int)($payload['product_id'] ?? 0);
        $url = (string)($payload['url'] ?? '');
        $result = supplier_products_add_picture_url($productId, $url, $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'picture_upload') {
        $productId = (int)($payload['product_id'] ?? 0);
        $result = supplier_products_upload_picture_file($productId, $_FILES['picture'] ?? [], $cfg);
        supplier_product_update_json(['ok' => true] + $result);
    }

    if ($action === 'video_cover_delete') {
        $productId = (int)($payload['product_id'] ?? 0);
        $result = supplier_products_delete_video_cover($productId, $cfg);
        supplier_product_update_json(['ok' => true] + supplier_product_update_attach_result_brand_statuses($result));
    }

    supplier_product_update_json(['ok' => false, 'error' => 'Неизвестное действие.'], 400);
} catch (Throwable $e) {
    supplier_product_update_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
