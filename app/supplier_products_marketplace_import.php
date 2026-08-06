<?php
declare(strict_types=1);

require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/supplier_products_marketplace_row_actions.php';
require_once __DIR__ . '/supplier_products_marketplace_push.php';

function supplier_marketplace_import_sources(): array
{
    return ['supplier_feed', 'url', 'upload', 'ozon_account', 'wb_account'];
}

function supplier_marketplace_import_is_marketplace_source(string $source): bool
{
    return in_array($source, ['ozon_account', 'wb_account'], true);
}

function supplier_marketplace_import_label(string $source): string
{
    if ($source === 'ozon_account') {
        return 'Личный кабинет Ozon';
    }
    if ($source === 'wb_account') {
        return 'Личный кабинет WB';
    }
    if ($source === 'url') {
        return 'Фид по ссылке';
    }
    if ($source === 'upload') {
        return 'Загруженный файл';
    }
    return 'Фид поставщика';
}

function supplier_marketplace_import_selected_set(array $options, string $supplierCode): array
{
    return supplier_products_selected_offer_id_set((array)($options['selected_offer_ids'] ?? []), $supplierCode);
}

function supplier_marketplace_import_offer_has_supplier_code(string $offerId, string $supplierCode): bool
{
    $offerId = trim($offerId);
    $supplierCode = suppliers_normalize_code($supplierCode);
    if ($offerId === '' || $supplierCode === '') {
        return false;
    }
    $suffix = '__' . $supplierCode;
    return substr($offerId, -strlen($suffix)) === $suffix;
}

function supplier_marketplace_import_product_rows(int $supplierId, string $supplierCode, array $options = []): array
{
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }
    $selectedSet = supplier_marketplace_import_selected_set($options, $supplierCode);
    if ($scope === 'selected' && !$selectedSet) {
        throw new RuntimeException('Для импорта только выбранных товаров сначала выбери товары в таблице.');
    }

    $st = db()->prepare("
        SELECT id, offer_key, offer_id, vendor_code
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$supplierId]);
    $rows = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($scope === 'selected' && !supplier_products_existing_product_is_selected($row, $selectedSet, $supplierCode)) {
            continue;
        }
        $offerId = trim((string)($row['offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = trim((string)($row['vendor_code'] ?? ''));
        }
        if ($offerId === '') {
            continue;
        }
        $row['market_offer_id'] = $offerId;
        $rows[] = $row;
    }
    return $rows;
}

function supplier_marketplace_import_rows_for_source(int $supplierId, string $supplierCode, string $source, int $connectionId, array $options = []): array
{
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }

    $rows = [];
    $seen = [];
    foreach (supplier_marketplace_import_product_rows($supplierId, $supplierCode, $options) as $row) {
        $offerId = trim((string)($row['market_offer_id'] ?? ''));
        if ($offerId === '') {
            continue;
        }
        $seen[$offerId] = true;
        $row['market_source'] = 'supplier_db';
        $rows[] = $row;
    }

    if ($scope === 'selected' || $connectionId <= 0 || $supplierCode === '') {
        return $rows;
    }

    $pdo = db();
    if ($source === 'ozon_account') {
        ozon_products_tables_ensure();
        $st = $pdo->prepare("
            SELECT offer_id
            FROM feedtools_ozon_products
            WHERE connection_id = ?
              AND product_id IS NOT NULL
              AND offer_id LIKE ?
            ORDER BY offer_id ASC
        ");
        $st->execute([$connectionId, '%__' . $supplierCode]);
        while ($offerId = $st->fetchColumn()) {
            $offerId = trim((string)$offerId);
            if ($offerId === '' || isset($seen[$offerId]) || !supplier_marketplace_import_offer_has_supplier_code($offerId, $supplierCode)) {
                continue;
            }
            $seen[$offerId] = true;
            $rows[] = [
                'id' => 0,
                'offer_key' => $offerId,
                'offer_id' => $offerId,
                'vendor_code' => $offerId,
                'market_offer_id' => $offerId,
                'market_source' => 'ozon_cache',
            ];
        }
        return $rows;
    }

    if ($source === 'wb_account') {
        wb_products_ensure_table($pdo);
        $st = $pdo->prepare("
            SELECT vendor_code
            FROM feedtools_wb_products
            WHERE connection_id = ?
              AND vendor_code LIKE ?
              AND marketplace_status <> 'not_created'
              AND raw_json IS NOT NULL
              AND raw_json <> ''
            ORDER BY vendor_code ASC
        ");
        $st->execute([$connectionId, '%__' . $supplierCode]);
        while ($offerId = $st->fetchColumn()) {
            $offerId = trim((string)$offerId);
            if ($offerId === '' || isset($seen[$offerId]) || !supplier_marketplace_import_offer_has_supplier_code($offerId, $supplierCode)) {
                continue;
            }
            $seen[$offerId] = true;
            $rows[] = [
                'id' => 0,
                'offer_key' => $offerId,
                'offer_id' => $offerId,
                'vendor_code' => $offerId,
                'market_offer_id' => $offerId,
                'market_source' => 'wb_cache',
            ];
        }
    }

    return $rows;
}

function supplier_marketplace_import_record(string $offerId, string $rawOfferId, array $parsed, int $sortOrder, string $categoryPath = ''): array
{
    $offerId = trim($offerId);
    $rawOfferId = trim($rawOfferId);
    return [
        'sort_order' => $sortOrder,
        'offer_key' => mb_substr($offerId !== '' ? $offerId : ('__market_' . $sortOrder), 0, 191, 'UTF-8'),
        'offer_id' => mb_substr($offerId, 0, 191, 'UTF-8'),
        'raw_offer_id' => $rawOfferId,
        'parsed' => $parsed,
        'category_path' => $categoryPath,
    ];
}

function supplier_marketplace_import_set_standard_value(array &$parsed, string $name, string $value): void
{
    $name = trim($name);
    $value = trim($value);
    if ($name === '' || !supplier_products_is_standard_field_name($name)) {
        return;
    }
    if ($name === 'brand') {
        $parsed['brand'] = $value;
    }
    $maxSort = 0;
    foreach ((array)($parsed['fields'] ?? []) as $idx => $field) {
        $maxSort = max($maxSort, (int)($field['sort_order'] ?? 0));
        if ((string)($field['field_kind'] ?? '') === 'standard' && trim((string)($field['field_name'] ?? '')) === $name) {
            $parsed['fields'][$idx]['field_value'] = $value;
            return;
        }
    }
    $parsed['fields'][] = [
        'field_kind' => 'standard',
        'field_name' => $name,
        'field_value' => $value,
        'sort_order' => $maxSort + 10,
    ];
}

function supplier_marketplace_import_ozon_info_items(array $oz, array $offerIds, ?callable $progress = null): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $out = [];
    $done = 0;
    $total = count($offerIds);
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $resp = ozon_post_json($oz, '/v3/product/info/list', ['offer_id' => array_values($chunk)]);
        foreach (supplier_market_row_ozon_items($resp) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $item;
            }
        }
        $done += count($chunk);
        if ($progress) {
            $progress(min($done, $total), max(1, $total), 'ozon_info', 'Получаю данные Ozon');
        }
    }
    return $out;
}

function supplier_marketplace_import_ozon_brand(array $current, ?array $infoItem, array $metaById): string
{
    foreach ([$current, is_array($infoItem) ? $infoItem : []] as $source) {
        $brand = trim((string)($source['brand'] ?? ($source['brand_name'] ?? ($source['vendor'] ?? ''))));
        if ($brand !== '') {
            return $brand;
        }
    }
    foreach ((array)($current['attributes'] ?? []) as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $attrName = supplier_market_row_ozon_attr_name($attr, $metaById);
        if (!supplier_market_row_ozon_is_brand_attr($attr, $attrName)) {
            continue;
        }
        $values = supplier_market_row_attr_values($attr);
        if ($values) {
            return implode(', ', $values);
        }
    }
    return '';
}

function supplier_marketplace_import_source_data_from_ozon(int $datasetId, int $supplierId, int $connectionId, array $cfg, array $options = []): array
{
    $progress = is_callable($options['progress'] ?? null) ? $options['progress'] : null;
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $connectionId);
    $connectionId = (int)$connection['id'];
    $rows = supplier_marketplace_import_rows_for_source($supplierId, $supplierCode, 'ozon_account', $connectionId, $options);
    if (!$rows) {
        return supplier_marketplace_import_empty_source('ozon_account');
    }
    $oz = supplier_market_row_ozon_config($connection);
    $offerIds = array_values(array_unique(array_map(static fn($row): string => (string)$row['market_offer_id'], $rows)));
    if ($progress) {
        $progress(0, max(1, count($offerIds)), 'ozon_attributes', 'Получаю атрибуты Ozon');
    }
    $currentItems = supplier_push_ozon_fetch_current_items($oz, $offerIds, $progress);
    $infoItems = [];
    try {
        $infoItems = supplier_marketplace_import_ozon_info_items($oz, $offerIds, $progress);
    } catch (Throwable $e) {
        $infoItems = [];
    }

    $records = [];
    $sort = 0;
    $rowsDone = 0;
    $rowsTotal = count($rows);
    foreach ($rows as $row) {
        $rowsDone++;
        $offerId = trim((string)($row['market_offer_id'] ?? ''));
        $current = $currentItems[$offerId] ?? ($infoItems[$offerId] ?? null);
        if (!is_array($current)) {
            if ($progress && ($rowsDone % 100 === 0 || $rowsDone === $rowsTotal)) {
                $progress($rowsDone, max(1, $rowsTotal), 'ozon_parse', 'Готовлю товары Ozon');
            }
            continue;
        }
        $infoItem = is_array($infoItems[$offerId] ?? null) ? $infoItems[$offerId] : null;
        $categoryValue = '';
        $parentId = (int)($current['description_category_id'] ?? 0);
        $typeId = (int)($current['type_id'] ?? 0);
        if ($parentId > 0 && $typeId > 0) {
            $categoryValue = $parentId . '_' . $typeId;
        }

        $tags = [
            'vendorCode' => $offerId,
        ];
        $name = trim((string)($current['name'] ?? ($infoItem['name'] ?? '')));
        if ($name !== '') {
            $tags['name'] = $name;
        }
        foreach ([$current, is_array($infoItem) ? $infoItem : []] as $descriptionSource) {
            $description = trim((string)($descriptionSource['description'] ?? ''));
            if ($description !== '') {
                $tags['description'] = $description;
                break;
            }
        }
        if ($categoryValue !== '') {
            $tags['ozon_category'] = $categoryValue;
        }

        $dimensionUnit = trim((string)($current['dimension_unit'] ?? 'mm')) ?: 'mm';
        $weightUnit = trim((string)($current['weight_unit'] ?? 'g')) ?: 'g';
        $length = supplier_market_row_ozon_dimension_cm($current['depth'] ?? null, $dimensionUnit);
        $width = supplier_market_row_ozon_dimension_cm($current['width'] ?? null, $dimensionUnit);
        $height = supplier_market_row_ozon_dimension_cm($current['height'] ?? null, $dimensionUnit);
        $weight = supplier_market_row_ozon_weight_text($current['weight'] ?? null, $weightUnit);

        $metaById = supplier_market_row_ozon_meta_by_id_for_category(
            $oz,
            $categoryValue,
            $parentId,
            $typeId,
            (array)($current['attributes'] ?? [])
        );

        $params = [];
        foreach ((array)($current['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $attrName = supplier_market_row_ozon_attr_name($attr, $metaById);
            $values = supplier_market_row_attr_values($attr);
            if (!$values || supplier_market_row_ozon_is_brand_attr($attr, $attrName)) {
                continue;
            }
            if (supplier_market_row_ozon_is_model_attr($attr, $attrName)) {
                $tags['same_model'] = implode(', ', $values);
                continue;
            }
            if ($attrName === '') {
                continue;
            }
            $norm = supplier_push_norm_name($attrName);
            if (in_array($norm, ['аннотация', 'описание', 'описание товара'], true) && trim((string)($tags['description'] ?? '')) === '') {
                $tags['description'] = implode(', ', $values);
                continue;
            }
            $params[$attrName] = $values;
        }

        $parsed = supplier_products_parsed_from_source_parts(
            $offerId,
            $tags,
            supplier_market_row_ozon_images_from_items(...array_values(array_filter([$infoItem, $current], 'is_array'))),
            $params,
            []
        );
        $brand = supplier_marketplace_import_ozon_brand($current, $infoItem, $metaById);
        if ($brand !== '') {
            supplier_marketplace_import_set_standard_value($parsed, 'brand_ozon', $brand);
        }
        foreach (['length' => $length, 'width' => $width, 'height' => $height, 'weight' => $weight] as $field => $value) {
            if ($value !== '') {
                supplier_marketplace_import_set_standard_value($parsed, $field, $value);
            }
        }
        $records[] = supplier_marketplace_import_record($offerId, $offerId, $parsed, ++$sort);
        if ($progress && ($rowsDone % 100 === 0 || $rowsDone === $rowsTotal)) {
            $progress($rowsDone, max(1, $rowsTotal), 'ozon_parse', 'Готовлю товары Ozon');
        }
    }

    return supplier_marketplace_import_source_from_records('ozon_account', $records);
}

function supplier_marketplace_import_wb_card_from_cache(int $connectionId, string $vendorCode): ?array
{
    return supplier_market_row_wb_cached_card($connectionId, $vendorCode, true);
}

function supplier_marketplace_import_source_data_from_wb(int $datasetId, int $supplierId, int $connectionId, array $cfg, array $options = []): array
{
    $progress = is_callable($options['progress'] ?? null) ? $options['progress'] : null;
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $connection = supplier_market_row_connection($datasetId, 'wb', $cfg, $connectionId);
    $connectionId = (int)$connection['id'];
    $rows = supplier_marketplace_import_rows_for_source($supplierId, $supplierCode, 'wb_account', $connectionId, $options);
    if (!$rows) {
        return supplier_marketplace_import_empty_source('wb_account');
    }
    $client = null;
    $useLiveLookup = (string)($options['scope'] ?? 'all') === 'selected' && count($rows) <= 50;
    if ($useLiveLookup) {
        $client = wb_price_tool_client($cfg, $connection);
    }

    $records = [];
    $sort = 0;
    $done = 0;
    $total = count($rows);
    if ($progress) {
        $progress(0, max(1, $total), 'wb', 'Получаю данные WB');
    }
    foreach ($rows as $row) {
        $done++;
        $vendorCode = trim((string)($row['market_offer_id'] ?? ''));
        $card = null;
        if ($client instanceof WildberriesClient) {
            try {
                $card = supplier_push_wb_fetch_card($client, $vendorCode);
            } catch (Throwable $e) {
                $card = null;
            }
        }
        if (!is_array($card)) {
            $card = supplier_marketplace_import_wb_card_from_cache($connectionId, $vendorCode);
        }
        if (!is_array($card)) {
            if ($progress && ($done % 100 === 0 || $done === $total)) {
                $progress($done, max(1, $total), 'wb', 'Готовлю товары WB');
            }
            continue;
        }

        $tags = [
            'vendorCode' => $vendorCode,
        ];
        $title = trim((string)($card['title'] ?? ''));
        if ($title !== '') {
            $tags['name'] = $title;
        }
        $description = trim((string)($card['description'] ?? ''));
        if ($description !== '') {
            $tags['description'] = $description;
        }
        $subjectId = (int)($card['subjectID'] ?? 0);
        if ($subjectId > 0) {
            $tags['wb_category'] = (string)$subjectId;
        }

        $photos = [];
        foreach ((array)($card['photos'] ?? []) as $photo) {
            $url = is_array($photo)
                ? trim((string)($photo['big'] ?? ($photo['c516x688'] ?? ($photo['url'] ?? ''))))
                : trim((string)$photo);
            if ($url !== '') {
                $photos[] = $url;
            }
        }
        $wbParams = [];
        foreach ((array)($card['characteristics'] ?? []) as $char) {
            if (!is_array($char)) {
                continue;
            }
            $name = trim((string)($char['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = $char['value'] ?? null;
            if (is_array($value)) {
                $values = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $value), static fn($v) => $v !== ''));
            } else {
                $single = trim((string)$value);
                $values = $single === '' ? [] : [$single];
            }
            if ($values) {
                if (supplier_market_row_is_model_attr_name($name)) {
                    $tags['same_model'] = implode(', ', $values);
                    continue;
                }
                $wbParams[$name] = $values;
            }
        }

        $parsed = supplier_products_parsed_from_source_parts($vendorCode, $tags, $photos, [], $wbParams);
        $brand = trim((string)($card['brand'] ?? ''));
        if ($brand !== '') {
            supplier_marketplace_import_set_standard_value($parsed, 'brand_wb', $brand);
        }
        $dims = is_array($card['dimensions'] ?? null) ? (array)$card['dimensions'] : [];
        foreach (['length' => 'length', 'width' => 'width', 'height' => 'height'] as $field => $key) {
            $value = supplier_market_row_format_number($dims[$key] ?? null);
            if ($value !== '') {
                supplier_marketplace_import_set_standard_value($parsed, $field, $value);
            }
        }
        $weight = supplier_market_row_format_number($dims['weightBrutto'] ?? null);
        if ($weight !== '') {
            supplier_marketplace_import_set_standard_value($parsed, 'weight', $weight . ' кг');
        }
        $records[] = supplier_marketplace_import_record($vendorCode, $vendorCode, $parsed, ++$sort);
        if ($progress && ($done % 100 === 0 || $done === $total)) {
            $progress($done, max(1, $total), 'wb', 'Готовлю товары WB');
        }
    }

    return supplier_marketplace_import_source_from_records('wb_account', $records);
}

function supplier_marketplace_import_empty_source(string $sourceType): array
{
    return supplier_marketplace_import_source_from_records($sourceType, []);
}

function supplier_marketplace_import_source_from_records(string $sourceType, array $records): array
{
    return [
        'records' => array_values($records),
        'categories_json' => '',
        'source_sha256' => '',
        'source_bytes' => 0,
        'warnings_json' => '[]',
        'offers_count' => count($records),
        'source_type' => $sourceType,
    ];
}

function supplier_marketplace_import_source_data(int $datasetId, int $supplierId, string $source, int $connectionId, array $cfg, array $options = []): array
{
    if ($source === 'ozon_account') {
        return supplier_marketplace_import_source_data_from_ozon($datasetId, $supplierId, $connectionId, $cfg, $options);
    }
    if ($source === 'wb_account') {
        return supplier_marketplace_import_source_data_from_wb($datasetId, $supplierId, $connectionId, $cfg, $options);
    }
    throw new RuntimeException('Неподдерживаемый источник маркетплейса.');
}

function supplier_marketplace_import_source_info(int $datasetId, int $supplierId, string $source, int $connectionId, array $cfg, array $options = []): array
{
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $rows = supplier_marketplace_import_rows_for_source($supplierId, $supplierCode, $source, $connectionId, $options);
    $offerIds = array_values(array_filter(array_unique(array_map(static fn($row): string => (string)($row['market_offer_id'] ?? ''), $rows))));
    if (!$offerIds) {
        return [
            'type' => $source,
            'label' => supplier_marketplace_import_label($source),
            'items_count' => 0,
        ];
    }

    $pdo = db();
    if ($source === 'ozon_account') {
        $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $connectionId);
        ozon_products_tables_ensure();
        $count = 0;
        foreach (array_chunk($offerIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("
                SELECT COUNT(*)
                FROM feedtools_ozon_products
                WHERE connection_id = ?
                  AND offer_id IN ({$ph})
                  AND product_id IS NOT NULL
            ");
            $st->execute(array_merge([(int)$connection['id']], $chunk));
            $count += (int)$st->fetchColumn();
        }
        return [
            'type' => 'ozon_account',
            'label' => supplier_marketplace_import_label('ozon_account'),
            'items_count' => $count,
            'connection_id' => (int)$connection['id'],
        ];
    }

    $connection = supplier_market_row_connection($datasetId, 'wb', $cfg, $connectionId);
    wb_products_ensure_table($pdo);
    $count = 0;
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM feedtools_wb_products
            WHERE connection_id = ?
              AND vendor_code IN ({$ph})
              AND marketplace_status <> 'not_created'
              AND raw_json IS NOT NULL
              AND raw_json <> ''
        ");
        $st->execute(array_merge([(int)$connection['id']], $chunk));
        $count += (int)$st->fetchColumn();
    }
    return [
        'type' => 'wb_account',
        'label' => supplier_marketplace_import_label('wb_account'),
        'items_count' => $count,
        'connection_id' => (int)$connection['id'],
    ];
}
