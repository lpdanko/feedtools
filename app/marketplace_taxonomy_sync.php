<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/taxonomy/OzonTaxonomy.php';
require_once __DIR__ . '/taxonomy/WildberriesTaxonomy.php';
require_once __DIR__ . '/marketplace_brand_dictionary.php';
require_once __DIR__ . '/wildberries/WildberriesClient.php';

function marketplace_sync_ozon_tree_to_lines(array $treeResult): array
{
    $lines = [];

    $walk = function (array $node, array $path) use (&$walk, &$lines): void {
        if (!empty($node['disabled'])) {
            return;
        }
        if (!isset($node['description_category_id'], $node['category_name'])) {
            return;
        }

        $descriptionCategoryId = (int)$node['description_category_id'];
        $categoryName = trim((string)$node['category_name']);
        if ($descriptionCategoryId <= 0 || $categoryName === '') {
            return;
        }
        $pathNext = array_merge($path, [$categoryName]);

        foreach ((array)($node['children'] ?? []) as $child) {
            if (!is_array($child) || !empty($child['disabled'])) {
                continue;
            }
            if (isset($child['type_id'], $child['type_name'])) {
                $typeId = (int)$child['type_id'];
                $typeName = trim((string)$child['type_name']);
                if ($typeId > 0 && $typeName !== '') {
                    $lines[] = implode(' > ', array_merge($pathNext, [$typeName])) . ' (' . $descriptionCategoryId . '_' . $typeId . ')';
                }
                continue;
            }
            if (isset($child['description_category_id'], $child['category_name'])) {
                $walk($child, $pathNext);
            }
        }
    };

    foreach ($treeResult as $root) {
        if (is_array($root)) {
            $walk($root, []);
        }
    }
    return $lines;
}

function marketplace_sync_refresh_ozon_taxonomy(array $oz, int $opId, callable $log): array
{
    $log("Ozon taxonomy: fetching category tree...\n");
    if ($opId > 0) {
        ops_update_progress($opId, 0, 100, 'taxonomy', 'Ozon: обновляем справочник категорий');
    }

    $resp = ozon_post_json($oz, '/v1/description-category/tree', (object)[]);
    $tree = is_array($resp['result'] ?? null) ? $resp['result'] : [];
    $lines = marketplace_sync_ozon_tree_to_lines($tree);
    if (!$lines) {
        throw new RuntimeException('Ozon taxonomy: API не вернул leaf-категории.');
    }

    $outFile = __DIR__ . '/../storage/taxonomies/ozon/categories_ozon.txt';
    ensure_dir(dirname($outFile));
    file_put_contents($outFile, implode("\n", $lines) . "\n");

    $summary = OzonTaxonomy::importFromFileWithProgress($outFile, $opId, $log, false);
    $log("Ozon taxonomy: categories refreshed, leaves=" . (int)($summary['leaves_parsed'] ?? 0) . "\n");

    return $summary + [
        'source' => 'ozon_api_tree',
        'generated_file' => $outFile,
        'generated_lines' => count($lines),
    ];
}

function marketplace_sync_refresh_wb_taxonomy(array $wbConfig, int $opId, callable $log): array
{
    $log("WB taxonomy: fetching categories...\n");
    if ($opId > 0) {
        ops_update_progress($opId, 0, 100, 'taxonomy', 'WB: обновляем справочник категорий');
    }
    $summary = WildberriesTaxonomy::importFromApiWithProgress($wbConfig, $opId, $log, false);
    $log("WB taxonomy: categories refreshed, subjects=" . (int)($summary['subjects'] ?? 0) . "\n");
    return $summary;
}

function marketplace_sync_used_ozon_categories(int $supplierId = 0, int $limit = 500): array
{
    $sql = "
        SELECT DISTINCT ozon_category
        FROM feedtools_supplier_products
        WHERE ozon_category <> ''
    ";
    $args = [];
    if ($supplierId > 0) {
        $sql .= " AND supplier_id = ?";
        $args[] = $supplierId;
    }
    $sql .= " ORDER BY ozon_category ASC LIMIT " . max(1, min(5000, $limit));
    $st = db()->prepare($sql);
    $st->execute($args);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $value = trim((string)($row['ozon_category'] ?? ''));
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

function marketplace_sync_used_wb_categories(int $supplierId = 0, int $limit = 500): array
{
    $sql = "
        SELECT DISTINCT wb_category
        FROM feedtools_supplier_products
        WHERE wb_category <> ''
    ";
    $args = [];
    if ($supplierId > 0) {
        $sql .= " AND supplier_id = ?";
        $args[] = $supplierId;
    }
    $sql .= " ORDER BY wb_category ASC LIMIT " . max(1, min(5000, $limit));
    $st = db()->prepare($sql);
    $st->execute($args);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $subjectId = (int)($row['wb_category'] ?? 0);
        if ($subjectId > 0) {
            $out[] = $subjectId;
        }
    }
    return array_values(array_unique($out));
}

function marketplace_sync_ozon_brand_attribute_id(string $categoryValue): int
{
    if (!preg_match('~^(\d+)_([0-9]+)$~', trim($categoryValue), $m)) {
        return 85;
    }
    $st = db()->prepare("
        SELECT meta_json
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id = ?
          AND ozon_leaf_id = ?
        LIMIT 1
    ");
    $st->execute([(int)$m[1], (int)$m[2]]);
    $meta = json_decode((string)($st->fetchColumn() ?: '{}'), true);
    $attrs = is_array($meta['ozon_required_attributes_meta'] ?? null) ? $meta['ozon_required_attributes_meta'] : [];
    foreach ($attrs as $key => $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $name = marketplace_brand_dictionary_norm((string)($attr['name'] ?? $key));
        if (($name === 'бренд' || $name === 'бренд товара') && (int)($attr['id'] ?? 0) > 0) {
            return (int)$attr['id'];
        }
    }
    return 85;
}

function marketplace_sync_refresh_ozon_brands_for_supplier(array $oz, int $supplierId, int $opId, callable $log): array
{
    $categories = marketplace_sync_used_ozon_categories($supplierId);
    $parentIds = [];
    $requestTypeByParent = [];
    foreach ($categories as $categoryValue) {
        if (preg_match('~^(\d+)_([0-9]+)$~', $categoryValue, $m)) {
            $parentId = (int)$m[1];
            $typeId = (int)$m[2];
            $parentIds[(string)$parentId] = $parentId;
            if ($parentId > 0 && $typeId > 0 && !isset($requestTypeByParent[$parentId])) {
                $requestTypeByParent[$parentId] = $typeId;
            }
        }
    }
    $parentIds = array_values($parentIds);
    $done = 0;
    $values = 0;
    $errors = [];
    $total = count($parentIds);
    if ($total === 0) {
        $log("Ozon brands: no used supplier parent categories.\n");
        return ['categories' => 0, 'values' => 0, 'errors' => []];
    }

    foreach ($parentIds as $descriptionCategoryId) {
        $done++;
        if ($opId > 0) {
            ops_update_progress($opId, $done, max(1, $total), 'brands', "Ozon: бренды {$done}/{$total}");
        }
        $descriptionCategoryId = (int)$descriptionCategoryId;
        $attributeId = 85;
        $requestTypeId = (int)($requestTypeByParent[$descriptionCategoryId] ?? 0);
        try {
            if ($requestTypeId <= 0) {
                throw new RuntimeException('Не найден type_id листовой категории для запроса брендов Ozon.');
            }
            $fetched = marketplace_ozon_brand_fetch_category_values(
                $oz,
                (string)$descriptionCategoryId,
                $descriptionCategoryId,
                $requestTypeId,
                $attributeId,
                200,
                true
            );
            $values += $fetched;
            $scopeNameSt = db()->prepare("
                SELECT full_path
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND ozon_parent_id = ?
                ORDER BY full_path ASC
                LIMIT 1
            ");
            $scopeNameSt->execute([$descriptionCategoryId]);
            marketplace_brand_scope_fetch_upsert('ozon', $descriptionCategoryId . ':0:' . $attributeId, [
                'category_value' => (string)$descriptionCategoryId,
                'category_name' => (string)($scopeNameSt->fetchColumn() ?: (string)$descriptionCategoryId),
                'description_category_id' => $descriptionCategoryId,
                'type_id' => 0,
                'attribute_id' => $attributeId,
            ], 'ok', $fetched, '');
        } catch (Throwable $e) {
            marketplace_brand_scope_fetch_upsert('ozon', $descriptionCategoryId . ':0:' . $attributeId, [
                'category_value' => (string)$descriptionCategoryId,
                'category_name' => (string)$descriptionCategoryId,
                'description_category_id' => $descriptionCategoryId,
                'type_id' => 0,
                'attribute_id' => $attributeId,
            ], 'error', 0, $e->getMessage());
            $errors[] = $descriptionCategoryId . ': ' . $e->getMessage();
        }
    }
    $log("Ozon brands: categories={$total}, values={$values}, errors=" . count($errors) . "\n");
    return ['categories' => $total, 'values' => $values, 'errors' => $errors];
}

function marketplace_sync_refresh_wb_brands_for_supplier(array $wbConfig, int $supplierId, int $opId, callable $log): array
{
    $subjects = marketplace_sync_used_wb_categories($supplierId);
    $done = 0;
    $values = 0;
    $errors = [];
    $total = count($subjects);
    if ($total === 0) {
        $log("WB brands: no used supplier subjects.\n");
        return ['subjects' => 0, 'values' => 0, 'errors' => []];
    }

    $client = new WildberriesClient($wbConfig);
    foreach ($subjects as $subjectId) {
        $done++;
        if ($opId > 0) {
            ops_update_progress($opId, $done, max(1, $total), 'brands', "WB: бренды {$done}/{$total}");
        }
        try {
            $subjectId = (int)$subjectId;
            $fetched = marketplace_wb_brand_fetch_subject_values($client, $subjectId, (string)$subjectId, '');
            $values += $fetched;
            $scopeNameSt = db()->prepare("
                SELECT full_path
                FROM feedtools_taxonomy_categories
                WHERE source = 'wildberries'
                  AND external_id = ?
                LIMIT 1
            ");
            $scopeNameSt->execute(['wb:subject:' . $subjectId]);
            marketplace_brand_scope_fetch_upsert('wb', (string)$subjectId, [
                'category_value' => (string)$subjectId,
                'category_name' => (string)($scopeNameSt->fetchColumn() ?: (string)$subjectId),
                'subject_id' => $subjectId,
                'parent_id' => marketplace_wb_parent_id_for_subject($subjectId),
            ], 'ok', $fetched, '');
        } catch (Throwable $e) {
            $errors[] = $subjectId . ': ' . $e->getMessage();
        }
    }
    $log("WB brands: subjects={$total}, values={$values}, errors=" . count($errors) . "\n");
    return ['subjects' => $total, 'values' => $values, 'errors' => $errors];
}
