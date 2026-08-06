<?php
declare(strict_types=1);

require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/supplier_products_marketplace_push.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/wildberries/WildberriesProducts.php';
require_once __DIR__ . '/wildberries/WildberriesPriceTool.php';
require_once __DIR__ . '/wildberries/WildberriesDictionaries.php';

function supplier_market_row_dataset_supplier_id(int $datasetId, array $cfg): int
{
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('DB-датасет поставщика не найден.');
    }
    return $supplierId;
}

function supplier_market_row_dataset(int $datasetId): array
{
    $st = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ? LIMIT 1");
    $st->execute([$datasetId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Датасет не найден.');
    }
    return $row;
}

function supplier_market_row_product(int $datasetId, int $productId, string $offerId, array $cfg): array
{
    $supplierId = supplier_market_row_dataset_supplier_id($datasetId, $cfg);
    $offerId = trim($offerId);
    if ($productId <= 0 && $offerId === '') {
        throw new RuntimeException('Не указан товар.');
    }

    $where = $productId > 0 ? 'id = ?' : 'offer_id = ?';
    $arg = $productId > 0 ? $productId : $offerId;
    $st = db()->prepare("
        SELECT *
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND {$where}
        LIMIT 1
    ");
    $st->execute([$supplierId, $arg]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Товар не найден.');
    }
    return $row;
}

function supplier_market_row_display_offer_id(array $product): string
{
    $offerId = trim((string)($product['offer_id'] ?? ''));
    if ($offerId !== '') {
        return $offerId;
    }
    return trim((string)($product['vendor_code'] ?? ''));
}

function supplier_market_row_connection(int $datasetId, string $marketplace, array $cfg, int $connectionOverrideId = 0): array
{
    $connectionId = max(0, $connectionOverrideId);
    if ($connectionId <= 0) {
        $dataset = supplier_market_row_dataset($datasetId);
        $column = $marketplace === 'wb' ? 'wb_connection_id' : 'ozon_connection_id';
        $connectionId = (int)($dataset[$column] ?? 0);
    }
    if ($connectionId <= 0) {
        throw new RuntimeException(($marketplace === 'wb' ? 'WB' : 'Ozon') . ': подключение не выбрано.');
    }
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $expected = $marketplace === 'wb' ? 'wb' : 'ozon';
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== $expected) {
        throw new RuntimeException(($marketplace === 'wb' ? 'WB' : 'Ozon') . ': подключение не найдено.');
    }
    return $connection;
}

function supplier_market_row_ozon_config(array $connection): array
{
    $oz = [
        'client_id' => (string)($connection['client_id'] ?? ''),
        'api_key' => (string)($connection['api_key'] ?? ''),
        'base_url' => (string)($connection['base_url'] ?? 'https://api-seller.ozon.ru'),
        'timeout_sec' => max(10, (int)($connection['timeout_sec'] ?? 30)),
    ];
    if (trim($oz['client_id']) === '' || trim($oz['api_key']) === '') {
        throw new RuntimeException('Ozon API: в подключении нет Client-Id или Api-Key.');
    }
    return $oz;
}

function supplier_market_row_set_field(int $supplierId, int $productId, string $kind, string $name, string $value, bool $deleteEmpty = false): bool
{
    $kind = supplier_products_validate_field_kind($kind);
    $name = supplier_products_validate_field_name($kind, $name);
    $value = trim($value);
    if ($kind === 'param' || $kind === 'wb_param') {
        $value = supplier_products_normalize_marketplace_field_value_for_product($productId, $kind, $name, $value);
    }
    if ($kind === 'standard') {
        return supplier_products_set_standard_field_value(db(), $supplierId, $productId, $name, $value);
    }

    $pdo = db();
    $st = $pdo->prepare("
        SELECT id, field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = ?
          AND field_name = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId, $kind, $name]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($value === '' && $deleteEmpty) {
        if ($rows) {
            $ids = array_values(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $rows)));
            if ($ids) {
                $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
                $del->execute($ids);
                return true;
            }
        }
        return false;
    }
    if ($value === '') {
        return false;
    }

    if ($rows) {
        $first = $rows[0];
        $changed = false;
        if ((string)($first['field_value'] ?? '') !== $value) {
            $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_value = ? WHERE id = ?");
            $upd->execute([$value, (int)$first['id']]);
            $changed = true;
        }
        $duplicateIds = [];
        foreach (array_slice($rows, 1) as $row) {
            $duplicateIds[] = (int)($row['id'] ?? 0);
        }
        $duplicateIds = array_values(array_filter($duplicateIds));
        if ($duplicateIds) {
            $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN (" . implode(',', array_fill(0, count($duplicateIds), '?')) . ")");
            $del->execute($duplicateIds);
            $changed = true;
        }
        return $changed;
    }

    $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
    $sort->execute([$productId]);
    $ins = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_fields (
            supplier_id, product_id, field_kind, field_name, field_value, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$supplierId, $productId, $kind, $name, $value, (int)$sort->fetchColumn()]);
    return true;
}

function supplier_market_row_update_product_summary(int $supplierId, int $productId, array $cfg): array
{
    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    return $fresh;
}

function supplier_market_row_ozon_items(array $resp): array
{
    if (function_exists('supplier_push_ozon_response_items')) {
        return supplier_push_ozon_response_items($resp);
    }
    $items = $resp['result']['items'] ?? ($resp['items'] ?? []);
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

function supplier_market_row_ozon_product_id(array $oz, int $connectionId, string $clientId, string $offerId): int
{
    ozon_products_tables_ensure();
    $st = db()->prepare("
        SELECT product_id
        FROM feedtools_ozon_products
        WHERE connection_id = ?
          AND offer_id = ?
          AND product_id IS NOT NULL
        LIMIT 1
    ");
    $st->execute([$connectionId, $offerId]);
    $productId = (int)($st->fetchColumn() ?: 0);
    if ($productId > 0) {
        return $productId;
    }

    $resp = ozon_post_json($oz, '/v3/product/info/list', ['offer_id' => [$offerId]]);
    foreach (supplier_market_row_ozon_items($resp) as $item) {
        $itemOfferId = trim((string)($item['offer_id'] ?? ''));
        if ($itemOfferId !== $offerId) {
            continue;
        }
        $productId = (int)($item['id'] ?? ($item['product_id'] ?? 0));
        $sku = isset($item['sku']) && is_numeric($item['sku']) ? (int)$item['sku'] : null;
        if ($productId > 0) {
            $isArchived = (!empty($item['is_archived']) || !empty($item['archived'])) ? 1 : 0;
            $isAutoArchived = (function_exists('ozon_products_autoarchive_marker') && ozon_products_autoarchive_marker($item)) ? 1 : 0;
            $marketplaceStatus = function_exists('ozon_products_marketplace_status_from_info')
                ? ozon_products_marketplace_status_from_info($item, $isArchived === 1)
                : ($isArchived ? 'archived' : 'ready');
            $statuses = is_array($item['statuses'] ?? null) ? (array)$item['statuses'] : [];
            $statusName = trim((string)($statuses['status_name'] ?? ''));
            if ($statusName === '' && $isArchived) {
                $statusName = $isAutoArchived ? 'В автоархиве' : 'В архиве';
            }
            $rawJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ins = db()->prepare("
                INSERT INTO feedtools_ozon_products (
                    connection_id, ozon_client_id, offer_id, product_id, sku, is_active, is_archived, is_autoarchived,
                    marketplace_status, status_name, last_seen_at, raw_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    ozon_client_id = VALUES(ozon_client_id),
                    product_id = VALUES(product_id),
                    sku = VALUES(sku),
                    is_active = VALUES(is_active),
                    is_archived = VALUES(is_archived),
                    is_autoarchived = VALUES(is_autoarchived),
                    marketplace_status = VALUES(marketplace_status),
                    status_name = VALUES(status_name),
                    last_seen_at = NOW(),
                    raw_json = VALUES(raw_json),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $ins->execute([
                $connectionId,
                $clientId,
                $offerId,
                $productId,
                $sku,
                $isArchived ? 0 : 1,
                $isArchived,
                $isAutoArchived,
                $marketplaceStatus,
                $statusName,
                is_string($rawJson) ? $rawJson : null,
            ]);
            return $productId;
        }
    }

    db()->prepare("
        INSERT INTO feedtools_ozon_products (
            connection_id, ozon_client_id, offer_id, product_id, sku, is_active, is_archived, is_autoarchived,
            marketplace_status, status_description, last_seen_at
        ) VALUES (?, ?, ?, NULL, NULL, 0, 0, 0, 'not_created', '', NOW())
        ON DUPLICATE KEY UPDATE
            is_active = 0,
            is_archived = 0,
            is_autoarchived = 0,
            marketplace_status = 'not_created',
            status_description = '',
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$connectionId, $clientId, $offerId]);
    return 0;
}

function supplier_market_row_ozon_info_item(array $oz, string $offerId): ?array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return null;
    }
    $resp = ozon_post_json($oz, '/v3/product/info/list', ['offer_id' => [$offerId]]);
    foreach (supplier_market_row_ozon_items($resp) as $item) {
        if (trim((string)($item['offer_id'] ?? '')) === $offerId) {
            return $item;
        }
    }
    return null;
}

function supplier_market_row_ozon_images_from_items(array ...$items): array
{
    $out = [];
    $seen = [];
    $add = static function (string $url) use (&$out, &$seen): void {
        $url = trim($url);
        if ($url === '' || isset($seen[$url])) {
            return;
        }
        $seen[$url] = true;
        $out[] = $url;
    };

    foreach ($items as $item) {
        foreach (['primary_image', 'primaryImage', 'image'] as $key) {
            if (isset($item[$key]) && !is_array($item[$key])) {
                $add((string)$item[$key]);
            }
        }
    }
    foreach ($items as $item) {
        foreach (['images', 'pictures', 'photo'] as $key) {
            foreach (supplier_push_ozon_normalized_images($item[$key] ?? []) as $url) {
                $add($url);
            }
        }
    }
    return $out;
}

function supplier_market_row_ozon_attr_name(array $attr, array $metaById): string
{
    $attrId = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
    foreach (['name', 'attribute_name', 'attribute'] as $key) {
        $direct = trim((string)($attr[$key] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
    }
    $meta = $attrId > 0 ? (array)($metaById[$attrId] ?? []) : [];
    return trim((string)($meta['name'] ?? ''));
}

function supplier_market_row_ozon_meta_items_from_taxonomy(array $meta): array
{
    $candidates = [
        $meta['ozon_required_attributes_meta'] ?? null,
        $meta['attributes'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || !$candidate) {
            continue;
        }
        $items = [];
        foreach ($candidate as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        if ($items) {
            return $items;
        }
    }
    return [];
}

function supplier_market_row_ozon_meta_by_id(array $attrMeta): array
{
    $metaById = [];
    foreach ($attrMeta as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $id = (int)($meta['id'] ?? ($meta['attribute_id'] ?? 0));
        if ($id > 0) {
            $metaById[$id] = $meta;
        }
    }
    return $metaById;
}

function supplier_market_row_ozon_index_attr_meta_items(array $items, array &$out, ?array $wantedIds = null): void
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? ($item['attribute_id'] ?? 0));
        $name = trim((string)($item['name'] ?? ($item['attribute_name'] ?? '')));
        if ($id <= 0 || $name === '') {
            continue;
        }
        if ($wantedIds !== null && !isset($wantedIds[$id])) {
            continue;
        }
        if (!isset($out[$id]) || trim((string)($out[$id]['name'] ?? '')) === '') {
            $out[$id] = $item;
        }
    }
}

function supplier_market_row_ozon_cached_meta_by_ids(array $attrIds, int $descriptionCategoryId = 0): array
{
    $wanted = [];
    foreach ($attrIds as $attrId) {
        $attrId = (int)$attrId;
        if ($attrId > 0) {
            $wanted[$attrId] = true;
        }
    }
    if (!$wanted) {
        return [];
    }

    $out = [];
    static $parentCache = [];
    static $idCache = [];

    if ($descriptionCategoryId > 0) {
        if (!array_key_exists($descriptionCategoryId, $parentCache)) {
            $itemsById = [];
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND ozon_parent_id = ?
                  AND meta_json IS NOT NULL
                  AND meta_json <> ''
            ");
            $st->execute([$descriptionCategoryId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $meta = json_decode((string)($row['meta_json'] ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }
                supplier_market_row_ozon_index_attr_meta_items(
                    supplier_market_row_ozon_meta_items_from_taxonomy($meta),
                    $itemsById
                );
            }
            $parentCache[$descriptionCategoryId] = $itemsById;
        }

        foreach ($wanted as $attrId => $_) {
            if (isset($parentCache[$descriptionCategoryId][$attrId])) {
                $out[$attrId] = $parentCache[$descriptionCategoryId][$attrId];
            }
        }
    }

    $missing = [];
    foreach ($wanted as $attrId => $_) {
        if (isset($out[$attrId])) {
            continue;
        }
        if (array_key_exists($attrId, $idCache)) {
            if (is_array($idCache[$attrId])) {
                $out[$attrId] = $idCache[$attrId];
            }
            continue;
        }
        $missing[$attrId] = true;
    }

    if ($missing) {
        foreach (array_chunk(array_keys($missing), 20) as $chunk) {
            $clauses = [];
            $params = [];
            foreach ($chunk as $attrId) {
                $clauses[] = 'meta_json LIKE ?';
                $params[] = '%"id":' . (int)$attrId . '%';
                $clauses[] = 'meta_json LIKE ?';
                $params[] = '%"attribute_id":' . (int)$attrId . '%';
            }
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND meta_json IS NOT NULL
                  AND meta_json <> ''
                  AND (" . implode(' OR ', $clauses) . ")
                LIMIT 300
            ");
            $st->execute($params);

            $found = [];
            $wantedChunk = array_fill_keys(array_map('intval', $chunk), true);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $meta = json_decode((string)($row['meta_json'] ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }
                supplier_market_row_ozon_index_attr_meta_items(
                    supplier_market_row_ozon_meta_items_from_taxonomy($meta),
                    $found,
                    $wantedChunk
                );
                if (count($found) >= count($wantedChunk)) {
                    break;
                }
            }

            foreach ($chunk as $attrId) {
                $attrId = (int)$attrId;
                $idCache[$attrId] = $found[$attrId] ?? null;
                if (is_array($idCache[$attrId])) {
                    $out[$attrId] = $idCache[$attrId];
                }
            }
        }
    }

    return $out;
}

function supplier_market_row_ozon_should_fetch_attribute_values(string $name, int $attributeId, int $dictionaryId, string $description = ''): bool
{
    if ($dictionaryId <= 0 || $attributeId <= 0 || trim($name) === '') {
        return false;
    }
    $norm = supplier_push_norm_name($name);
    if (in_array($attributeId, [85], true)) {
        return false;
    }
    if (in_array($norm, [
        'бренд',
        'бренд товара',
        'brand',
    ], true)) {
        return false;
    }
    if (in_array($norm, ['тн вэд', 'тн вэд коды еаэс', 'tn ved', 'tnved'], true)) {
        return true;
    }
    $descriptionLower = str_replace('ё', 'е', supplier_push_lc($description));
    if (str_contains($descriptionLower, 'любой удобный формат')
        || str_contains($descriptionLower, 'можно указать только целое число')
        || str_contains($descriptionLower, 'десятичную дробь')) {
        return false;
    }
    if (str_contains($descriptionLower, 'выберите из списка')
        || str_contains($descriptionLower, 'одно значение')
        || str_contains($descriptionLower, 'списка')) {
        return true;
    }
    return in_array($norm, [
        'цвет',
        'цвет товара',
        'название цвета',
        'основной цвет',
        'материал',
        'материал изделия',
        'основной материал',
        'страна производства',
        'страна изготовитель',
        'страна производитель',
        'пол',
        'сезон',
    ], true);
}

function supplier_market_row_ozon_meta_needs_dictionary_values(array $attrMeta): bool
{
    $hasAttrMeta = false;
    $hasTnvedAttr = false;
    $hasMarkingAttr = false;
    foreach ($attrMeta as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hasAttrMeta = true;
        $name = trim((string)($row['name'] ?? ''));
        $id = (int)($row['id'] ?? ($row['attribute_id'] ?? 0));
        $dictionaryId = (int)($row['dictionary_id'] ?? 0);
        if ($name !== '' && supplier_push_ozon_is_marking_code_meta($row)) {
            $hasMarkingAttr = true;
        }
        if ($name !== '' && supplier_market_row_is_tnved_characteristic_name($name)) {
            $hasTnvedAttr = true;
            if ($dictionaryId > 0 && (empty($row['allowed_values']) || !is_array($row['allowed_values']))) {
                return true;
            }
        }
        if (!supplier_market_row_ozon_should_fetch_attribute_values($name, $id, $dictionaryId, (string)($row['description'] ?? ''))) {
            continue;
        }
        if (empty($row['allowed_values']) || !is_array($row['allowed_values'])) {
            return true;
        }
    }
    return ($hasAttrMeta && !$hasTnvedAttr) || ($hasTnvedAttr && !$hasMarkingAttr);
}

function supplier_market_row_wb_attribute_uses_directory(string $name): bool
{
    $norm = function_exists('wb_dict_norm_attr_name') ? wb_dict_norm_attr_name($name) : supplier_push_norm_name($name);
    return in_array($norm, [
        'цвет',
        'основной цвет',
        'цвет товара',
        'страна производства',
        'страна производитель',
        'сезон',
        'пол',
        'назначение по полу',
    ], true);
}

function supplier_market_row_wb_meta_needs_dictionary_values(array $attrMeta): bool
{
    foreach ($attrMeta as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '' && supplier_market_row_wb_attribute_uses_directory($name) && empty($row['allowed_values'])) {
            return true;
        }
    }
    return false;
}

function supplier_market_row_ozon_attribute_values(array $oz, int $descriptionCategoryId, int $typeId, int $attributeId, int $limitTotal = 500, string $language = 'RU'): array
{
    static $cache = [];
    if ($descriptionCategoryId <= 0 || $typeId <= 0 || $attributeId <= 0 || $limitTotal <= 0) {
        return [];
    }
    $cacheKey = $descriptionCategoryId . ':' . $typeId . ':' . $attributeId . ':' . $language . ':' . $limitTotal;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $values = [];
    $seen = [];
    $lastValueId = 0;
    $safety = 0;
    while ($safety++ < 20 && count($values) < $limitTotal) {
        $resp = ozon_post_json($oz, '/v1/description-category/attribute/values', [
            'description_category_id' => $descriptionCategoryId,
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'language' => $language,
            'last_value_id' => $lastValueId,
            'limit' => min(1000, max(1, $limitTotal - count($values))),
        ]);
        $result = $resp['result'] ?? [];
        if (!is_array($result)) {
            $result = [];
        }
        $items = (isset($result['values']) && is_array($result['values'])) ? $result['values'] : $result;
        if (!is_array($items) || !$items) {
            break;
        }

        $maxId = $lastValueId;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = trim((string)($item['value'] ?? ($item['name'] ?? '')));
            $key = supplier_push_norm_name($value);
            if ($value !== '' && $key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $values[] = $value;
                if (count($values) >= $limitTotal) {
                    break;
                }
            }
            $valueId = (int)($item['id'] ?? 0);
            if ($valueId > $maxId) {
                $maxId = $valueId;
            }
        }

        $hasNext = array_key_exists('has_next', $resp)
            ? (bool)$resp['has_next']
            : (bool)($result['has_next'] ?? false);
        if (!$hasNext || count($values) >= $limitTotal) {
            break;
        }
        $next = (int)($resp['last_value_id'] ?? ($result['last_value_id'] ?? $maxId));
        if ($next <= $lastValueId) {
            break;
        }
        $lastValueId = $next;
    }

    $cache[$cacheKey] = $values;
    return $values;
}

function supplier_market_row_ozon_attribute_values_search(array $oz, int $descriptionCategoryId, int $typeId, int $attributeId, string $query, int $limit = 100, string $language = 'RU'): array
{
    $query = trim($query);
    if ($descriptionCategoryId <= 0 || $typeId <= 0 || $attributeId <= 0 || $query === '') {
        return [];
    }
    $limit = max(1, min(1000, $limit));
    static $cache = [];
    $cacheKey = $descriptionCategoryId . ':' . $typeId . ':' . $attributeId . ':' . $language . ':' . $limit . ':' . supplier_push_norm_name($query);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $resp = ozon_post_json($oz, '/v1/description-category/attribute/values/search', [
        'description_category_id' => $descriptionCategoryId,
        'type_id' => $typeId,
        'attribute_id' => $attributeId,
        'value' => $query,
        'language' => $language,
        'limit' => $limit,
    ]);
    $result = $resp['result'] ?? [];
    if (!is_array($result)) {
        $result = [];
    }
    $items = (isset($result['values']) && is_array($result['values'])) ? $result['values'] : $result;

    $values = [];
    $seen = [];
    foreach ((array)$items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string)($item['value'] ?? ($item['name'] ?? '')));
        $key = supplier_push_norm_name($value);
        if ($value !== '' && $key !== '' && !isset($seen[$key])) {
            $seen[$key] = true;
            $values[] = $value;
        }
    }

    $cache[$cacheKey] = $values;
    return $values;
}

function supplier_market_row_ozon_attribute_meta_for_name(array $meta, string $name): ?array
{
    $wanted = array_fill_keys(supplier_products_characteristic_alias_norms($name), true);
    if (!$wanted) {
        return null;
    }
    foreach (supplier_market_row_ozon_meta_items_from_taxonomy($meta) as $item) {
        $itemName = trim((string)($item['name'] ?? ($item['attribute_name'] ?? '')));
        if ($itemName === '') {
            continue;
        }
        foreach (supplier_products_characteristic_alias_norms($itemName) as $norm) {
            if (isset($wanted[$norm])) {
                return $item;
            }
        }
    }
    return null;
}

function supplier_market_row_is_tnved_characteristic_name(string $name): bool
{
    $norm = supplier_push_norm_name($name);
    return in_array($norm, ['тн вэд', 'тн вэд коды еаэс', 'tn ved', 'tnved'], true)
        || str_contains($norm, 'тн_вэд')
        || str_contains($norm, 'tnved');
}

function supplier_market_row_characteristic_value_matches_query(string $value, string $query): bool
{
    $value = trim($value);
    $query = trim($query);
    if ($query === '') {
        return true;
    }
    if ($value === '') {
        return false;
    }
    $valueDigits = preg_replace('~\D+~', '', $value) ?: '';
    $queryDigits = preg_replace('~\D+~', '', $query) ?: '';
    if (mb_strlen($queryDigits, 'UTF-8') >= 2 && $valueDigits !== '' && str_contains($valueDigits, $queryDigits)) {
        return true;
    }

    $valueNorm = supplier_push_norm_name($value);
    $tokens = preg_split('~[\s,;|]+~u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
    foreach ($tokens as $token) {
        $tokenNorm = supplier_push_norm_name((string)$token);
        if ($tokenNorm === '') {
            continue;
        }
        if (!str_contains($valueNorm, $tokenNorm)) {
            return false;
        }
    }
    return true;
}

function supplier_market_row_ozon_fetch_attr_meta(array $oz, int $descriptionCategoryId, int $typeId): array
{
    if ($descriptionCategoryId <= 0 || $typeId <= 0) {
        return [];
    }
    static $cache = [];
    $cacheKey = $descriptionCategoryId . ':' . $typeId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $resp = ozon_post_json($oz, '/v1/description-category/attribute', [
        'description_category_id' => $descriptionCategoryId,
        'type_id' => $typeId,
    ]);
    $items = $resp['result'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    if (isset($items['attributes']) && is_array($items['attributes'])) {
        $items = $items['attributes'];
    }

    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? ($item['attribute_id'] ?? 0));
        $name = trim((string)($item['name'] ?? ($item['attribute_name'] ?? '')));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $dictionaryId = (int)($item['dictionary_id'] ?? 0);
        $allowedValues = [];
        if (supplier_market_row_ozon_should_fetch_attribute_values($name, $id, $dictionaryId, (string)($item['description'] ?? ''))) {
            try {
                $limitTotal = supplier_market_row_is_tnved_characteristic_name($name) ? 1000 : 500;
                $allowedValues = supplier_market_row_ozon_attribute_values($oz, $descriptionCategoryId, $typeId, $id, $limitTotal, 'RU');
            } catch (Throwable $e) {
                $allowedValues = [];
            }
        }
        $out[] = [
            'id' => $id,
            'name' => $name,
            'description' => trim((string)($item['description'] ?? '')),
            'attribute_complex_id' => (int)($item['attribute_complex_id'] ?? ($item['complex_id'] ?? 0)),
            'required' => !empty($item['is_required']) || !empty($item['required']),
            'dictionary_id' => $dictionaryId,
            'type' => trim((string)($item['type'] ?? '')),
            'allowed_values' => $allowedValues,
            'selection_mode' => $allowedValues ? 'choose_one' : ($dictionaryId > 0 ? 'dictionary' : 'free'),
            'value_source' => $allowedValues ? 'ozon_dictionary' : ($dictionaryId > 0 ? 'ozon_dictionary' : 'free_text'),
        ];
    }
    $cache[$cacheKey] = $out;
    return $out;
}

function supplier_market_row_ozon_tnved_allowed_values_from_attr_meta(array $attrMeta): array
{
    $out = [];
    $seen = [];
    foreach ($attrMeta as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '' || !supplier_market_row_is_tnved_characteristic_name($name)) {
            continue;
        }
        foreach ((array)($item['allowed_values'] ?? []) as $value) {
            $value = trim((string)$value);
            $key = supplier_push_norm_name($value);
            if ($value === '' || $key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $value;
        }
    }
    return $out;
}

function supplier_market_row_ozon_cache_attr_meta(string $categoryValue, int $descriptionCategoryId, int $typeId, array $attrMeta): void
{
    if ($categoryValue === '' || $descriptionCategoryId <= 0 || $typeId <= 0 || !$attrMeta) {
        return;
    }
    if (!preg_match('~^(\d+)_([0-9]+)$~', $categoryValue, $m)) {
        return;
    }
    $st = db()->prepare("
        SELECT id, meta_json
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id = ?
          AND ozon_leaf_id = ?
        LIMIT 1
    ");
    $st->execute([(int)$m[1], (int)$m[2]]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }

    $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $lines = [];
    $stored = [];
    foreach ($attrMeta as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        $id = (int)($item['id'] ?? 0);
        if ($name === '' || $id <= 0) {
            continue;
        }
        $key = supplier_push_norm_name($name);
        if ($key === '' || isset($stored[$key])) {
            continue;
        }
        $lines[] = $name;
        $stored[$key] = $item;
    }
    if (!$stored) {
        return;
    }
    $meta['ozon_description_category_id'] = $descriptionCategoryId;
    $meta['ozon_type_id'] = $typeId;
    $meta['ozon_required_attributes'] = $lines;
    $meta['ozon_required_attributes_meta'] = $stored;
    $tnvedAllowedValues = supplier_market_row_ozon_tnved_allowed_values_from_attr_meta($stored);
    $meta['ozon_tnved_allowed_values'] = $tnvedAllowedValues;
    $meta['ozon_tnved_allowed_values_count'] = count($tnvedAllowedValues);
    $meta['ozon_tnved_allowed_values_updated_at'] = date('c');

    db()->prepare("UPDATE feedtools_taxonomy_categories SET meta_json = ?, updated_at = NOW() WHERE id = ?")
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$row['id']]);
}

function supplier_market_row_ozon_meta_by_id_for_category(array $oz, string $categoryValue, int $descriptionCategoryId, int $typeId, array $currentAttrs = []): array
{
    $attrMeta = [];
    if ($categoryValue !== '') {
        $meta = supplier_push_taxonomy_meta('ozon', $categoryValue);
        $attrMeta = supplier_market_row_ozon_meta_items_from_taxonomy($meta);
    }
    $metaById = supplier_market_row_ozon_meta_by_id($attrMeta);

    $currentAttrIds = [];
    foreach ($currentAttrs as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $attrId = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
        if ($attrId <= 0) {
            continue;
        }
        $currentAttrIds[$attrId] = true;
    }
    if ($currentAttrIds) {
        foreach (supplier_market_row_ozon_cached_meta_by_ids(array_keys($currentAttrIds), $descriptionCategoryId) as $id => $meta) {
            if (!isset($metaById[(int)$id])) {
                $metaById[(int)$id] = $meta;
            }
        }
        $attrMeta = array_values($metaById);
    }

    $needsDictionaryValues = supplier_market_row_ozon_meta_needs_dictionary_values($attrMeta);
    $hasMissingCurrentNames = false;
    foreach ($currentAttrs as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $attrId = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
        if ($attrId <= 0) {
            continue;
        }
        $directName = trim((string)($attr['name'] ?? ($attr['attribute_name'] ?? ($attr['attribute'] ?? ''))));
        if ($directName === '' && !isset($metaById[$attrId])) {
            $hasMissingCurrentNames = true;
            break;
        }
    }
    static $failedFetchPairs = [];
    $fetchPairKey = $descriptionCategoryId . ':' . $typeId;
    if ((!$metaById || $hasMissingCurrentNames || $needsDictionaryValues)
        && $descriptionCategoryId > 0
        && $typeId > 0
        && empty($failedFetchPairs[$fetchPairKey])) {
        try {
            $fetchedMeta = supplier_market_row_ozon_fetch_attr_meta($oz, $descriptionCategoryId, $typeId);
        } catch (Throwable) {
            $failedFetchPairs[$fetchPairKey] = true;
            foreach (supplier_market_row_ozon_cached_meta_by_ids(array_keys($currentAttrIds), $descriptionCategoryId) as $id => $meta) {
                if (!isset($metaById[(int)$id])) {
                    $metaById[(int)$id] = $meta;
                }
            }
            return $metaById;
        }
        if ($fetchedMeta) {
            $attrMeta = $fetchedMeta;
            $metaById = supplier_market_row_ozon_meta_by_id($attrMeta);
            foreach (supplier_market_row_ozon_cached_meta_by_ids(array_keys($currentAttrIds), $descriptionCategoryId) as $id => $meta) {
                if (!isset($metaById[(int)$id])) {
                    $metaById[(int)$id] = $meta;
                }
            }
            supplier_market_row_ozon_cache_attr_meta($categoryValue, $descriptionCategoryId, $typeId, $attrMeta);
        }
    }
    return $metaById;
}

function supplier_market_row_category_meta_names(array $meta, string $source): array
{
    $lineKey = $source === 'ozon' ? 'ozon_required_attributes' : 'wb_required_attributes';
    $metaKey = $source === 'ozon' ? 'ozon_required_attributes_meta' : 'wb_characteristics_meta';
    $names = [];
    foreach ((array)($meta[$lineKey] ?? []) as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    foreach ((array)($meta[$metaKey] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    return array_keys($names);
}

function supplier_market_row_category_row_for_value(string $source, string $categoryValue): ?array
{
    $categoryValue = trim($categoryValue);
    if ($categoryValue === '') {
        return null;
    }

    if ($source === 'ozon') {
        if (preg_match('~^leaf:(\d+_\d+)$~', $categoryValue, $m)) {
            $categoryValue = (string)$m[1];
        }
        if (preg_match('~^(\d+)_(\d+)$~', $categoryValue, $m)) {
            $st = db()->prepare("
                SELECT *
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND ozon_parent_id = ?
                  AND ozon_leaf_id = ?
                LIMIT 1
            ");
            $st->execute([(int)$m[1], (int)$m[2]]);
        } else {
            $st = db()->prepare("
                SELECT *
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND (name = ? OR full_path = ? OR full_path LIKE ?)
                ORDER BY full_path ASC
                LIMIT 1
            ");
            $st->execute([$categoryValue, $categoryValue, '% > ' . $categoryValue]);
        }
    } elseif ($source === 'wildberries') {
        if (preg_match('~^wb:(?:subject|parent):(\d+)$~', $categoryValue, $m)) {
            $categoryValue = (string)$m[1];
        }
        if (ctype_digit($categoryValue)) {
            $st = db()->prepare("
                SELECT *
                FROM feedtools_taxonomy_categories
                WHERE source = 'wildberries'
                  AND external_id = ?
                ORDER BY is_leaf DESC
                LIMIT 1
            ");
            $st->execute(['wb:subject:' . $categoryValue]);
        } else {
            $st = db()->prepare("
                SELECT *
                FROM feedtools_taxonomy_categories
                WHERE source = 'wildberries'
                  AND is_leaf = 1
                  AND (external_id = ? OR name = ? OR full_path = ? OR full_path LIKE ?)
                ORDER BY full_path ASC
                LIMIT 1
            ");
            $st->execute([$categoryValue, $categoryValue, $categoryValue, '% > ' . $categoryValue]);
        }
    } else {
        return null;
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function supplier_market_row_save_category_meta(int $categoryId, array $meta): void
{
    if ($categoryId <= 0) {
        return;
    }
    db()->prepare("UPDATE feedtools_taxonomy_categories SET meta_json = ?, updated_at = NOW() WHERE id = ?")
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $categoryId]);
}

function supplier_market_row_cache_wb_characteristics_meta(int $categoryId, array $meta, array $items): array
{
    $lines = [];
    $stored = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = supplier_push_norm_name($name);
        if ($key === '' || isset($stored[$key])) {
            continue;
        }
        $row = [
            'name' => $name,
            'id' => (int)($item['charcID'] ?? ($item['id'] ?? 0)),
            'required' => !empty($item['required']),
            'unit' => trim((string)($item['unitName'] ?? ($item['unit'] ?? ''))),
            'max_count' => (int)($item['maxCount'] ?? 0),
            'popular' => !empty($item['popular']),
            'charc_type' => (int)($item['charcType'] ?? 0),
            'is_variable' => !empty($item['isVariable']),
            'subject_id' => (int)($item['subjectID'] ?? 0),
            'subject_name' => trim((string)($item['subjectName'] ?? '')),
            'allowed_values' => array_values((array)($item['allowed_values'] ?? [])),
            'selection_mode' => trim((string)($item['selection_mode'] ?? '')),
            'value_source' => trim((string)($item['value_source'] ?? '')),
        ];
        $stored[$key] = $row;
        $lines[] = $name;
    }
    if ($stored) {
        $meta['wb_required_attributes'] = $lines;
        $meta['wb_characteristics_meta'] = $stored;
        supplier_market_row_save_category_meta($categoryId, $meta);
    }
    return $meta;
}

function supplier_market_row_ensure_category_attributes_for_value(int $datasetId, string $source, string $categoryValue, array $cfg, int $connectionOverrideId = 0): array
{
    $source = trim($source);
    if ($source === 'wb') {
        $source = 'wildberries';
    }
    if (!in_array($source, ['ozon', 'wildberries'], true) || trim($categoryValue) === '') {
        return ['ok' => false, 'source' => $source, 'names_count' => 0];
    }

    try {
        $row = supplier_market_row_category_row_for_value($source, $categoryValue);
        if (!is_array($row)) {
            return ['ok' => false, 'source' => $source, 'names_count' => 0, 'error' => 'category_not_found'];
        }
        $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $existing = supplier_market_row_category_meta_names($meta, $source);
        $needsDictionaryRefresh = ($source === 'ozon'
            && supplier_market_row_ozon_meta_needs_dictionary_values(supplier_market_row_ozon_meta_items_from_taxonomy($meta)))
            || ($source === 'wildberries'
                && supplier_market_row_wb_meta_needs_dictionary_values(array_values((array)($meta['wb_characteristics_meta'] ?? []))));
        if ($existing && !$needsDictionaryRefresh) {
            return ['ok' => true, 'source' => $source, 'names_count' => count($existing), 'cached' => true];
        }

        if ($source === 'ozon') {
            $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $connectionOverrideId);
            $oz = supplier_market_row_ozon_config($connection);
            $categoryPair = (string)($row['ozon_parent_id'] ?? '') . '_' . (string)($row['ozon_leaf_id'] ?? '');
            $descriptionCategoryId = (int)($meta['ozon_description_category_id'] ?? ($row['ozon_parent_id'] ?? 0));
            $typeId = (int)($meta['ozon_type_id'] ?? ($row['ozon_leaf_id'] ?? 0));
            $attempts = [
                ['desc' => $descriptionCategoryId, 'type' => $typeId],
                ['desc' => $typeId, 'type' => $descriptionCategoryId],
            ];
            $best = [];
            $bestDesc = 0;
            $bestType = 0;
            $errors = [];
            foreach ($attempts as $attempt) {
                try {
                    $attrs = supplier_market_row_ozon_fetch_attr_meta($oz, (int)$attempt['desc'], (int)$attempt['type']);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                if (count($attrs) > count($best)) {
                    $best = $attrs;
                    $bestDesc = (int)$attempt['desc'];
                    $bestType = (int)$attempt['type'];
                }
            }
            if ($best) {
                supplier_market_row_ozon_cache_attr_meta($categoryPair, $bestDesc, $bestType, $best);
                return ['ok' => true, 'source' => $source, 'names_count' => count($best), 'cached' => false];
            }
            if ($errors) {
                return ['ok' => false, 'source' => $source, 'names_count' => 0, 'error' => implode(' | ', array_slice($errors, 0, 2))];
            }
        } else {
            $externalId = (string)($row['external_id'] ?? '');
            $subjectId = preg_match('~^wb:subject:(\d+)$~', $externalId, $m) ? (int)$m[1] : (int)$categoryValue;
            if ($subjectId <= 0) {
                return ['ok' => false, 'source' => $source, 'names_count' => 0, 'error' => 'bad_subject_id'];
            }
            $connection = supplier_market_row_connection($datasetId, 'wb', $cfg, $connectionOverrideId);
            $client = wb_price_tool_client($cfg, $connection);
            $resp = $client->getSubjectCharacteristics($subjectId);
            $items = $resp['data'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            if (function_exists('wb_dict_enrich_characteristic_meta')) {
                foreach ($items as $idx => $item) {
                    if (is_array($item)) {
                        $items[$idx] = wb_dict_enrich_characteristic_meta($client, $item);
                    }
                }
            }
            $meta = supplier_market_row_cache_wb_characteristics_meta((int)$row['id'], $meta, $items);
            $names = supplier_market_row_category_meta_names($meta, $source);
            if ($names) {
                return ['ok' => true, 'source' => $source, 'names_count' => count($names), 'cached' => false];
            }
        }

        return ['ok' => false, 'source' => $source, 'names_count' => 0, 'error' => 'empty_attributes'];
    } catch (Throwable $e) {
        return ['ok' => false, 'source' => $source, 'names_count' => 0, 'error' => $e->getMessage()];
    }
}

function supplier_market_row_characteristic_allowed_values_for_product(int $productId, string $source, string $name, array $cfg = [], int $connectionOverrideId = 0, string $query = ''): array
{
    $source = $source === 'wb' ? 'wildberries' : trim($source);
    $name = trim($name);
    if ($productId <= 0 || $name === '' || !in_array($source, ['ozon', 'wildberries'], true)) {
        return [];
    }

    $st = db()->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        return [];
    }

    $datasetId = 0;
    $fetchConnectionId = max(0, $connectionOverrideId);
    $categoryValues = supplier_products_bulk_category_values_for_products([$productId]);
    $categoryValue = $source === 'ozon'
        ? (string)(($categoryValues[$productId] ?? [])['ozon'] ?? '')
        : (string)(($categoryValues[$productId] ?? [])['wb'] ?? '');
    if (trim($categoryValue) === '') {
        return [];
    }

    $supplierId = (int)($product['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        try {
            $datasetId = supplier_products_dataset_id($supplierId, $cfg);
            if ($source === 'ozon' && $fetchConnectionId <= 0) {
                $dataset = supplier_market_row_dataset($datasetId);
                $fetchConnectionId = (int)($dataset['ozon_connection_id'] ?? 0);
                if ($fetchConnectionId <= 0) {
                    $fetchConnectionId = ozon_price_connection_default_id($cfg);
                }
            }
            supplier_market_row_ensure_category_attributes_for_value($datasetId, $source, $categoryValue, $cfg, $fetchConnectionId);
        } catch (Throwable $e) {
            // Если API сейчас недоступен, вернем то, что уже есть в кеше.
        }
    }

    $cachedValues = supplier_products_marketplace_characteristic_allowed_values_for_product($productId, $source, $name, $cfg);
    $query = trim($query);
    $cachedValuesForQuery = $query === ''
        ? $cachedValues
        : array_values(array_filter(
            $cachedValues,
            static fn($value): bool => supplier_market_row_characteristic_value_matches_query((string)$value, $query)
        ));
    if ($source !== 'ozon' || $query === '' || $supplierId <= 0) {
        return $cachedValuesForQuery;
    }

    try {
        if ($datasetId <= 0) {
            $datasetId = supplier_products_dataset_id($supplierId, $cfg);
        }
        $meta = supplier_products_taxonomy_meta_for_category_value('ozon', $categoryValue);
        $descriptionCategoryId = (int)($meta['ozon_description_category_id'] ?? 0);
        $typeId = (int)($meta['ozon_type_id'] ?? 0);
        if (($descriptionCategoryId <= 0 || $typeId <= 0) && preg_match('~^(\d+)_(\d+)$~', $categoryValue, $m)) {
            $descriptionCategoryId = $descriptionCategoryId > 0 ? $descriptionCategoryId : (int)$m[1];
            $typeId = $typeId > 0 ? $typeId : (int)$m[2];
        }
        $attr = supplier_market_row_ozon_attribute_meta_for_name($meta, $name);
        $attributeId = is_array($attr) ? (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0)) : 0;
        $dictionaryId = is_array($attr) ? (int)($attr['dictionary_id'] ?? 0) : 0;
        if ($descriptionCategoryId > 0 && $typeId > 0 && $attributeId > 0 && $dictionaryId > 0) {
            $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $fetchConnectionId);
            $searchLimit = supplier_market_row_is_tnved_characteristic_name($name) ? 300 : 100;
            $searchedValues = supplier_market_row_ozon_attribute_values_search(
                supplier_market_row_ozon_config($connection),
                $descriptionCategoryId,
                $typeId,
                $attributeId,
                $query,
                $searchLimit,
                'RU'
            );
            if ($searchedValues) {
                $merged = [];
                foreach (array_merge($searchedValues, $cachedValuesForQuery) as $value) {
                    $value = trim((string)$value);
                    $key = supplier_push_norm_name($value);
                    if ($value !== '' && $key !== '' && !isset($merged[$key])) {
                        $merged[$key] = $value;
                    }
                }
                return array_values($merged);
            }
        }
    } catch (Throwable $e) {
        // Живой поиск значений не должен ломать редактирование характеристики.
    }

    return $cachedValuesForQuery;
}

function supplier_market_row_ozon_is_brand_attr(array $attr, string $attrName): bool
{
    $attrId = (int)($attr['id'] ?? 0);
    if ($attrId === 85) {
        return true;
    }
    $norm = supplier_push_norm_name($attrName);
    return in_array($norm, ['бренд', 'бренд товара'], true);
}

function supplier_market_row_is_model_attr_name(string $attrName): bool
{
    $norm = supplier_push_norm_name($attrName);
    if ($norm === '') {
        return false;
    }
    $aliases = [
        'Модель',
        'Модель товара',
        'Название модели',
        'Название модели (для объединения в одну карточку)',
        'Название модели для объединения в одну карточку',
        'Объединить в похожие товары',
        'Название группы',
    ];
    foreach ($aliases as $alias) {
        foreach (supplier_push_attribute_alias_names($alias) as $candidate) {
            if ($norm === supplier_push_norm_name((string)$candidate)) {
                return true;
            }
        }
    }
    return false;
}

function supplier_market_row_ozon_is_model_attr(array $attr, string $attrName): bool
{
    $attrId = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
    if ($attrId === 9048) {
        return true;
    }
    return supplier_market_row_is_model_attr_name($attrName);
}

function supplier_market_row_refresh_ozon_status(int $datasetId, array $product, array $cfg, int $connectionOverrideId = 0): array
{
    $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $connectionOverrideId);
    $connectionId = (int)$connection['id'];
    $oz = supplier_market_row_ozon_config($connection);
    $offerId = supplier_market_row_display_offer_id($product);
    if ($offerId === '') {
        throw new RuntimeException('Ozon: у товара нет offer_id.');
    }
    $productId = supplier_market_row_ozon_product_id($oz, $connectionId, (string)$connection['client_id'], $offerId);
    if ($productId > 0) {
        ozon_products_refresh_status_details($oz, $connectionId, (string)$connection['client_id'], [$productId]);
    }
    return ['marketplace' => 'ozon', 'offer_id' => $offerId, 'product_id' => $productId];
}

function supplier_market_row_wb_vendor_code(array $product): string
{
    $offerId = trim((string)($product['offer_id'] ?? ''));
    return $offerId !== '' ? $offerId : trim((string)($product['vendor_code'] ?? ''));
}

function supplier_market_row_wb_cached_row(int $connectionId, string $vendorCode): ?array
{
    wb_products_ensure_table(db());
    $vendorCode = trim($vendorCode);
    if ($connectionId <= 0 || $vendorCode === '') {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_wb_products
        WHERE connection_id = ?
          AND vendor_code = ?
        LIMIT 1
    ");
    $st->execute([$connectionId, $vendorCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function supplier_market_row_wb_cached_card(int $connectionId, string $vendorCode, bool $includeErrorCards = false): ?array
{
    $row = supplier_market_row_wb_cached_row($connectionId, $vendorCode);
    if (!is_array($row)) {
        return null;
    }
    $status = (string)($row['marketplace_status'] ?? '');
    if ($status === 'not_created' || ($status === 'error' && !$includeErrorCards)) {
        return null;
    }
    $raw = json_decode((string)($row['raw_json'] ?? ''), true);
    if (is_array($raw) && trim((string)($raw['vendorCode'] ?? ($raw['vendor_code'] ?? ''))) === trim($vendorCode)) {
        return $raw;
    }
    return null;
}

function supplier_market_row_wb_fetch_card_for_product(WildberriesClient $client, int $connectionId, string $vendorCode): ?array
{
    $card = supplier_push_wb_fetch_card($client, $vendorCode);
    if (is_array($card)) {
        return $card;
    }
    $cached = supplier_market_row_wb_cached_card($connectionId, $vendorCode);
    if (is_array($cached) && (int)($cached['nmID'] ?? 0) > 0) {
        return $cached;
    }
    return null;
}

function supplier_market_row_wb_error_item(WildberriesClient $client, string $vendorCode): ?array
{
    $cursor = ['limit' => 100];
    for ($page = 1; $page <= 30; $page++) {
        $resp = $client->contentPost('/content/v2/cards/error/list', [
            'cursor' => $cursor,
            'order' => ['ascending' => true],
        ], 'content');
        $items = $resp['data']['items'] ?? ($resp['items'] ?? []);
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $foundCode = wb_products_find_first_key_recursive($item, ['vendorCode', 'vendor_code', 'vendorСode']);
            if (trim((string)$foundCode) === $vendorCode) {
                return $item;
            }
        }
        $respCursor = $resp['data']['cursor'] ?? ($resp['cursor'] ?? []);
        if (!is_array($respCursor) || empty($respCursor['next'])) {
            break;
        }
        $updatedAt = trim((string)($respCursor['updatedAt'] ?? ''));
        $batchUUID = trim((string)($respCursor['batchUUID'] ?? ''));
        if ($updatedAt === '' || $batchUUID === '') {
            break;
        }
        $cursor = [
            'limit' => 100,
            'updatedAt' => $updatedAt,
            'batchUUID' => $batchUUID,
        ];
    }
    return null;
}

function supplier_market_row_mark_wb_not_created(int $connectionId, string $vendorCode): void
{
    wb_products_ensure_table(db());
    db()->prepare("
        INSERT INTO feedtools_wb_products (
            connection_id, vendor_code, nm_id, imt_id, subject_id, is_active,
            marketplace_status, status_text, is_trash, raw_json, last_seen_at
        ) VALUES (?, ?, NULL, NULL, NULL, 0, 'not_created', '', 0, NULL, NOW())
        ON DUPLICATE KEY UPDATE
            is_active = 0,
            marketplace_status = 'not_created',
            status_text = '',
            is_trash = 0,
            raw_json = NULL,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$connectionId, $vendorCode]);
}

function supplier_market_row_refresh_wb_status(int $datasetId, array $product, array $cfg, int $connectionOverrideId = 0): array
{
    $connection = supplier_market_row_connection($datasetId, 'wb', $cfg, $connectionOverrideId);
    $connectionId = (int)$connection['id'];
    $client = wb_price_tool_client($cfg, $connection);
    $vendorCode = supplier_market_row_wb_vendor_code($product);
    if ($vendorCode === '') {
        throw new RuntimeException('WB: у товара нет vendorCode.');
    }

    $seenAt = date('Y-m-d H:i:s');
    $card = supplier_push_wb_fetch_card($client, $vendorCode);
    if (is_array($card)) {
        $rows = wb_products_card_rows([$card], 'ready', 1, 0, '');
        wb_products_upsert_rows(db(), $rows, $seenAt, $connectionId);
        return ['marketplace' => 'wb', 'vendor_code' => $vendorCode, 'found' => 'card'];
    }

    $errorItem = supplier_market_row_wb_error_item($client, $vendorCode);
    if (is_array($errorItem)) {
        $rows = wb_products_error_rows([$errorItem]);
        wb_products_upsert_rows(db(), $rows, $seenAt, $connectionId);
        return ['marketplace' => 'wb', 'vendor_code' => $vendorCode, 'found' => 'error'];
    }

    $cachedRow = supplier_market_row_wb_cached_row($connectionId, $vendorCode);
    if (is_array($cachedRow) && (string)($cachedRow['marketplace_status'] ?? '') !== 'not_created') {
        return [
            'marketplace' => 'wb',
            'vendor_code' => $vendorCode,
            'found' => 'cache',
            'status' => (string)($cachedRow['marketplace_status'] ?? ''),
        ];
    }

    supplier_market_row_mark_wb_not_created($connectionId, $vendorCode);
    return ['marketplace' => 'wb', 'vendor_code' => $vendorCode, 'found' => 'none'];
}

function supplier_market_row_attr_values(array $attr): array
{
    $values = [];
    foreach ((array)($attr['values'] ?? []) as $value) {
        if (is_array($value)) {
            $text = trim((string)($value['value'] ?? ($value['name'] ?? '')));
        } else {
            $text = trim((string)$value);
        }
        if ($text !== '') {
            $values[$text] = true;
        }
    }
    return array_keys($values);
}

function supplier_market_row_format_number($value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $num = (float)$value;
    if (abs($num - round($num)) < 0.00001) {
        return (string)(int)round($num);
    }
    return rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
}

function supplier_market_row_ozon_dimension_cm($value, string $unit): string
{
    if (!is_numeric($value) || (float)$value <= 0) {
        return '';
    }
    $num = (float)$value;
    $unit = strtolower(trim($unit));
    if ($unit === 'mm' || $unit === 'мм') {
        $num = $num / 10.0;
    }
    return supplier_market_row_format_number($num);
}

function supplier_market_row_ozon_weight_text($value, string $unit): string
{
    if (!is_numeric($value) || (float)$value <= 0) {
        return '';
    }
    $unit = strtolower(trim($unit));
    if ($unit === 'kg' || $unit === 'кг') {
        return supplier_market_row_format_number((float)$value) . ' кг';
    }
    return supplier_market_row_format_number((float)$value) . ' г';
}

function supplier_market_row_import_from_ozon(int $datasetId, array $product, array $cfg, int $connectionOverrideId = 0): array
{
    $supplierId = supplier_market_row_dataset_supplier_id($datasetId, $cfg);
    $productId = (int)$product['id'];
    $connection = supplier_market_row_connection($datasetId, 'ozon', $cfg, $connectionOverrideId);
    $connectionId = (int)$connection['id'];
    $oz = supplier_market_row_ozon_config($connection);
    $offerId = supplier_market_row_display_offer_id($product);
    if ($offerId === '') {
        throw new RuntimeException('Ozon: у товара нет offer_id.');
    }
    $current = supplier_push_ozon_fetch_current_items($oz, [$offerId])[$offerId] ?? null;
    $infoItem = null;
    try {
        $infoItem = supplier_market_row_ozon_info_item($oz, $offerId);
    } catch (Throwable $e) {
        if (!is_array($current)) {
            throw $e;
        }
    }
    if (!is_array($current) && is_array($infoItem)) {
        $current = $infoItem;
    }
    if (!is_array($current)) {
        supplier_market_row_refresh_ozon_status($datasetId, $product, $cfg, $connectionOverrideId);
        throw new RuntimeException('Ozon: карточка товара не найдена.');
    }

    $categoryValue = '';
    $parentId = (int)($current['description_category_id'] ?? 0);
    $typeId = (int)($current['type_id'] ?? 0);
    if ($parentId > 0 && $typeId > 0) {
        $categoryValue = $parentId . '_' . $typeId;
        supplier_market_row_set_field($supplierId, $productId, 'tag', 'ozon_category', $categoryValue, true);
    }

    $name = trim((string)($current['name'] ?? ''));
    if ($name !== '') {
        db()->prepare("UPDATE feedtools_supplier_products SET name = ? WHERE id = ?")->execute([$name, $productId]);
    }
    foreach ([$current, is_array($infoItem) ? $infoItem : []] as $descriptionSource) {
        $description = trim((string)($descriptionSource['description'] ?? ''));
        if ($description !== '') {
            db()->prepare("UPDATE feedtools_supplier_products SET description_html = ? WHERE id = ?")->execute([$description, $productId]);
            break;
        }
    }
    foreach ([$current, is_array($infoItem) ? $infoItem : []] as $brandSource) {
        $brandText = trim((string)($brandSource['brand'] ?? ($brandSource['brand_name'] ?? ($brandSource['vendor'] ?? ''))));
        if ($brandText !== '') {
            supplier_market_row_set_field($supplierId, $productId, 'standard', 'brand_ozon', $brandText);
            break;
        }
    }

    $dimensionUnit = trim((string)($current['dimension_unit'] ?? 'mm')) ?: 'mm';
    $weightUnit = trim((string)($current['weight_unit'] ?? 'g')) ?: 'g';
    $length = supplier_market_row_ozon_dimension_cm($current['depth'] ?? null, $dimensionUnit);
    $width = supplier_market_row_ozon_dimension_cm($current['width'] ?? null, $dimensionUnit);
    $height = supplier_market_row_ozon_dimension_cm($current['height'] ?? null, $dimensionUnit);
    $weight = supplier_market_row_ozon_weight_text($current['weight'] ?? null, $weightUnit);
    foreach (['length' => $length, 'width' => $width, 'height' => $height, 'weight' => $weight] as $field => $value) {
        if ($value !== '') {
            supplier_market_row_set_field($supplierId, $productId, 'standard', $field, $value);
        }
    }

    $imageSources = is_array($infoItem) ? [$infoItem, $current] : [$current];
    $images = supplier_market_row_ozon_images_from_items(...$imageSources);
    if ($images) {
        db()->prepare("UPDATE feedtools_supplier_products SET pictures_json = ? WHERE id = ?")
            ->execute([json_encode(array_values($images), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $productId]);
    }

    $metaById = supplier_market_row_ozon_meta_by_id_for_category(
        $oz,
        $categoryValue,
        $parentId,
        $typeId,
        (array)($current['attributes'] ?? [])
    );

    foreach ((array)($current['attributes'] ?? []) as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $attrId = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
        if ($attrId <= 0) {
            continue;
        }
        $values = supplier_market_row_attr_values($attr);
        if (!$values) {
            continue;
        }
        $valueText = implode(', ', $values);
        $attrName = supplier_market_row_ozon_attr_name($attr, $metaById);
        if (supplier_market_row_ozon_is_brand_attr($attr, $attrName)) {
            supplier_market_row_set_field($supplierId, $productId, 'standard', 'brand_ozon', $valueText);
            continue;
        }
        if (supplier_market_row_ozon_is_model_attr($attr, $attrName)) {
            supplier_market_row_set_field($supplierId, $productId, 'tag', 'same_model', $valueText);
            continue;
        }
        if ($attrName === '') {
            continue;
        }
        $norm = supplier_push_norm_name($attrName);
        if (in_array($norm, ['аннотация', 'описание', 'описание товара'], true)) {
            db()->prepare("UPDATE feedtools_supplier_products SET description_html = ? WHERE id = ?")->execute([$valueText, $productId]);
            continue;
        }
        supplier_market_row_set_field($supplierId, $productId, 'param', $attrName, $valueText);
    }

    supplier_market_row_refresh_ozon_status($datasetId, $product, $cfg, $connectionOverrideId);
    return supplier_market_row_update_product_summary($supplierId, $productId, $cfg);
}

function supplier_market_row_import_from_wb(int $datasetId, array $product, array $cfg, int $connectionOverrideId = 0): array
{
    $supplierId = supplier_market_row_dataset_supplier_id($datasetId, $cfg);
    $productId = (int)$product['id'];
    $connection = supplier_market_row_connection($datasetId, 'wb', $cfg, $connectionOverrideId);
    $client = wb_price_tool_client($cfg, $connection);
    $vendorCode = supplier_market_row_wb_vendor_code($product);
    if ($vendorCode === '') {
        throw new RuntimeException('WB: у товара нет vendorCode.');
    }
    $connectionId = (int)$connection['id'];
    $card = supplier_market_row_wb_fetch_card_for_product($client, $connectionId, $vendorCode);
    if (!is_array($card)) {
        supplier_market_row_refresh_wb_status($datasetId, $product, $cfg, $connectionOverrideId);
        throw new RuntimeException('WB: карточка товара не найдена.');
    }

    $title = trim((string)($card['title'] ?? ''));
    if ($title !== '') {
        db()->prepare("UPDATE feedtools_supplier_products SET name = ? WHERE id = ?")->execute([$title, $productId]);
    }
    $description = trim((string)($card['description'] ?? ''));
    if ($description !== '') {
        db()->prepare("UPDATE feedtools_supplier_products SET description_html = ? WHERE id = ?")->execute([$description, $productId]);
    }
    $brand = trim((string)($card['brand'] ?? ''));
    if ($brand !== '') {
        supplier_market_row_set_field($supplierId, $productId, 'standard', 'brand_wb', $brand);
    }
    $subjectId = (int)($card['subjectID'] ?? 0);
    if ($subjectId > 0) {
        supplier_market_row_set_field($supplierId, $productId, 'tag', 'wb_category', (string)$subjectId, true);
    }
    $dims = is_array($card['dimensions'] ?? null) ? (array)$card['dimensions'] : [];
    $dimensionMap = [
        'length' => $dims['length'] ?? null,
        'width' => $dims['width'] ?? null,
        'height' => $dims['height'] ?? null,
    ];
    foreach ($dimensionMap as $field => $value) {
        $text = supplier_market_row_format_number($value);
        if ($text !== '') {
            supplier_market_row_set_field($supplierId, $productId, 'standard', $field, $text);
        }
    }
    $weight = supplier_market_row_format_number($dims['weightBrutto'] ?? null);
    if ($weight !== '') {
        supplier_market_row_set_field($supplierId, $productId, 'standard', 'weight', $weight . ' кг');
    }
    $photos = [];
    foreach ((array)($card['photos'] ?? []) as $photo) {
        if (is_array($photo)) {
            $url = trim((string)($photo['big'] ?? ($photo['c516x688'] ?? ($photo['url'] ?? ''))));
        } else {
            $url = trim((string)$photo);
        }
        if ($url !== '') {
            $photos[$url] = true;
        }
    }
    if ($photos) {
        db()->prepare("UPDATE feedtools_supplier_products SET pictures_json = ? WHERE id = ?")
            ->execute([json_encode(array_keys($photos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $productId]);
    }
    foreach ((array)($card['characteristics'] ?? []) as $char) {
        if (!is_array($char)) {
            continue;
        }
        $name = trim((string)($char['name'] ?? ''));
        $value = $char['value'] ?? null;
        if ($name === '') {
            continue;
        }
        if (is_array($value)) {
            $vals = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $value), static fn($v) => $v !== ''));
            $valueText = implode(', ', $vals);
        } else {
            $valueText = trim((string)$value);
        }
        if ($valueText !== '') {
            if (supplier_market_row_is_model_attr_name($name)) {
                supplier_market_row_set_field($supplierId, $productId, 'tag', 'same_model', $valueText);
                continue;
            }
            supplier_market_row_set_field($supplierId, $productId, 'wb_param', $name, $valueText);
        }
    }

    supplier_market_row_refresh_wb_status($datasetId, $product, $cfg, $connectionOverrideId);
    return supplier_market_row_update_product_summary($supplierId, $productId, $cfg);
}

function supplier_market_row_push(int $datasetId, array $product, string $marketplace, array $cfg, int $connectionOverrideId = 0): array
{
    $supplierId = supplier_market_row_dataset_supplier_id($datasetId, $cfg);
    $connection = supplier_market_row_connection($datasetId, $marketplace, $cfg, $connectionOverrideId);
    $offerId = supplier_market_row_display_offer_id($product);
    if ($offerId === '') {
        throw new RuntimeException('У товара нет offer_id.');
    }
    $params = [
        'connection_id' => (int)$connection['id'],
        'offer_ids' => [$offerId],
        'offer_ids_json' => json_encode([$offerId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'fields_json' => json_encode(array_keys(supplier_push_all_content_fields()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $log = static function (string $line): void {};
    if ($marketplace === 'ozon') {
        return supplier_push_ozon($cfg, $datasetId, $supplierId, 0, $params, $log, supplier_push_all_content_fields());
    }
    if ($marketplace === 'wb') {
        return supplier_push_wb($cfg, $datasetId, $supplierId, 0, $params, $log, supplier_push_all_content_fields());
    }
    throw new RuntimeException('Неподдерживаемый маркетплейс.');
}

function supplier_market_row_action(int $datasetId, int $productId, string $offerId, string $marketplace, string $mode, array $cfg, int $connectionOverrideId = 0): array
{
    $marketplace = strtolower(trim($marketplace));
    if ($marketplace === 'wildberries') {
        $marketplace = 'wb';
    }
    if (!in_array($marketplace, ['ozon', 'wb'], true)) {
        throw new RuntimeException('Неподдерживаемый маркетплейс.');
    }
    $product = supplier_market_row_product($datasetId, $productId, $offerId, $cfg);

    if ($mode === 'status') {
        $result = $marketplace === 'ozon'
            ? supplier_market_row_refresh_ozon_status($datasetId, $product, $cfg, $connectionOverrideId)
            : supplier_market_row_refresh_wb_status($datasetId, $product, $cfg, $connectionOverrideId);
        return ['action' => $mode, 'marketplace' => $marketplace, 'result' => $result];
    }
    if ($mode === 'pull') {
        $fresh = $marketplace === 'ozon'
            ? supplier_market_row_import_from_ozon($datasetId, $product, $cfg, $connectionOverrideId)
            : supplier_market_row_import_from_wb($datasetId, $product, $cfg, $connectionOverrideId);
        return ['action' => $mode, 'marketplace' => $marketplace, 'product' => $fresh];
    }
    if ($mode === 'push') {
        $result = supplier_market_row_push($datasetId, $product, $marketplace, $cfg, $connectionOverrideId);
        return ['action' => $mode, 'marketplace' => $marketplace, 'result' => $result];
    }

    throw new RuntimeException('Неизвестное действие маркетплейса.');
}
