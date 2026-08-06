<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace_brand_dictionary.php';

function marketplace_brand_status_label(string $status): string
{
    return [
        'ok' => 'OK',
        'missing_brand' => 'ошибка бренда',
        'category_not_selected' => 'категория не выбрана',
        'category_mismatch' => 'категория не подходит',
        'not_found' => 'бренд не существует',
    ][$status] ?? $status;
}

function marketplace_brand_status_make(string $status, string $brandName = ''): array
{
    return [
        'status' => $status,
        'label' => marketplace_brand_status_label($status),
        'brand_name' => $brandName,
    ];
}

function marketplace_brand_status_norm(string $brandName): string
{
    $norm = marketplace_brand_dictionary_norm($brandName);
    return function_exists('mb_substr') ? mb_substr($norm, 0, 255, 'UTF-8') : substr($norm, 0, 255);
}

function marketplace_brand_status_ozon_category_ids(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['parent_id' => 0, 'leaf_id' => 0];
    }
    if (preg_match('~^(\d+)_(\d+)$~', $value, $m)) {
        return ['parent_id' => (int)$m[1], 'leaf_id' => (int)$m[2]];
    }
    if (ctype_digit($value)) {
        return ['parent_id' => (int)$value, 'leaf_id' => 0];
    }
    return ['parent_id' => 0, 'leaf_id' => 0];
}

function marketplace_brand_status_wb_subject_id(string $value): int
{
    $value = trim($value);
    $value = preg_replace('~^wb:subject:~', '', $value) ?? $value;
    return ctype_digit($value) ? (int)$value : 0;
}

function marketplace_brand_status_wb_parent_ids(array $subjectIds): array
{
    $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));
    if (!$subjectIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($subjectIds, 500) as $chunk) {
        $externalIds = array_map(static fn(int $id): string => 'wb:subject:' . $id, $chunk);
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $st = db()->prepare("
            SELECT external_id, meta_json
            FROM feedtools_taxonomy_categories
            WHERE source = 'wildberries'
              AND external_id IN ({$placeholders})
        ");
        $st->execute($externalIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $subjectId = (int)preg_replace('~^wb:subject:~', '', (string)($row['external_id'] ?? ''));
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
            $parentId = is_array($meta) ? (int)($meta['raw']['wb_parent_id'] ?? ($meta['wb_parent_id'] ?? 0)) : 0;
            if ($subjectId > 0 && $parentId > 0) {
                $out[$subjectId] = $parentId;
            }
        }
    }
    return $out;
}

function marketplace_brand_status_fetch_existing_norms(string $marketplace, array $norms): array
{
    $norms = array_values(array_unique(array_filter(array_map('strval', $norms), static fn(string $v): bool => $v !== '')));
    if (!$norms) {
        return [];
    }

    $table = $marketplace === 'wb' ? 'feedtools_wb_brands' : 'feedtools_ozon_brands';
    $out = [];
    foreach (array_chunk($norms, 1000) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT brand_id, brand_name, brand_name_norm
            FROM {$table}
            WHERE brand_name_norm IN ({$placeholders})
            ORDER BY brand_id ASC
        ");
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = (string)($row['brand_name_norm'] ?? '');
            if ($norm !== '' && !isset($out[$norm])) {
                $out[$norm] = [
                    'brand_id' => (int)($row['brand_id'] ?? 0),
                    'brand_name' => (string)($row['brand_name'] ?? ''),
                ];
            }
        }
    }
    return $out;
}

function marketplace_brand_status_fetch_ozon_fit(array $norms, array $parentIds, array $leafIds): array
{
    $norms = array_values(array_unique(array_filter(array_map('strval', $norms), static fn(string $v): bool => $v !== '')));
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
    $leafIds = array_values(array_unique(array_filter(array_map('intval', $leafIds))));
    if (!$norms || !$parentIds) {
        return [];
    }

    $fit = [];
    foreach (array_chunk($norms, 300) as $normChunk) {
        foreach (array_chunk($parentIds, 300) as $parentChunk) {
            $normPh = implode(',', array_fill(0, count($normChunk), '?'));
            $parentPh = implode(',', array_fill(0, count($parentChunk), '?'));
            $args = array_merge($normChunk, $parentChunk);
            $typeSql = 'c.type_id = 0';
            if ($leafIds) {
                $leafPh = implode(',', array_fill(0, count($leafIds), '?'));
                $typeSql = "(c.type_id = 0 OR c.type_id IN ({$leafPh}))";
                $args = array_merge($args, $leafIds);
            }
            $st = db()->prepare("
                SELECT b.brand_name_norm, b.brand_name, c.description_category_id, c.type_id
                FROM feedtools_ozon_brands b
                JOIN feedtools_ozon_brand_categories c ON c.brand_id = b.brand_id
                WHERE b.brand_name_norm IN ({$normPh})
                  AND c.description_category_id IN ({$parentPh})
                  AND {$typeSql}
            ");
            $st->execute($args);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $norm = (string)($row['brand_name_norm'] ?? '');
                $parentId = (int)($row['description_category_id'] ?? 0);
                $typeId = (int)($row['type_id'] ?? 0);
                if ($norm !== '' && $parentId > 0) {
                    $fit[$norm][$parentId][$typeId] = (string)($row['brand_name'] ?? '');
                }
            }
        }
    }
    return $fit;
}

function marketplace_brand_status_fetch_wb_fit(array $norms, array $subjectIds, array $parentIds): array
{
    $norms = array_values(array_unique(array_filter(array_map('strval', $norms), static fn(string $v): bool => $v !== '')));
    $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
    if (!$norms || (!$subjectIds && !$parentIds)) {
        return [];
    }

    $fit = [];
    foreach (array_chunk($norms, 300) as $normChunk) {
        $normPh = implode(',', array_fill(0, count($normChunk), '?'));
        $clauses = [];
        $args = $normChunk;
        if ($subjectIds) {
            $subjectPh = implode(',', array_fill(0, count($subjectIds), '?'));
            $clauses[] = "c.subject_id IN ({$subjectPh})";
            $args = array_merge($args, $subjectIds);
        }
        if ($parentIds) {
            $parentPh = implode(',', array_fill(0, count($parentIds), '?'));
            $clauses[] = "(c.subject_id = 0 AND c.parent_id IN ({$parentPh}))";
            $args = array_merge($args, $parentIds);
        }
        $st = db()->prepare("
            SELECT b.brand_name_norm, b.brand_name, c.subject_id, c.parent_id
            FROM feedtools_wb_brands b
            JOIN feedtools_wb_brand_categories c ON c.brand_id = b.brand_id
            WHERE b.brand_name_norm IN ({$normPh})
              AND (" . implode(' OR ', $clauses) . ")
        ");
        $st->execute($args);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = (string)($row['brand_name_norm'] ?? '');
            $subjectId = (int)($row['subject_id'] ?? 0);
            $parentId = (int)($row['parent_id'] ?? 0);
            if ($norm === '') {
                continue;
            }
            if ($subjectId > 0) {
                $fit[$norm]['subject'][$subjectId] = (string)($row['brand_name'] ?? '');
            } elseif ($parentId > 0) {
                $fit[$norm]['parent'][$parentId] = (string)($row['brand_name'] ?? '');
            }
        }
    }
    return $fit;
}

/**
 * @param array $products map: key => ['brand' => '', 'brand_ozon' => '', 'brand_wb' => '', 'ozon_category' => '', 'wb_category' => '']
 * @return array map: key => ['ozon' => status, 'wb' => status]
 */
function marketplace_brand_status_for_products(array $products): array
{
    marketplace_brand_dictionary_tables_ensure();

    $norms = [];
    $ozonParents = [];
    $ozonLeafs = [];
    $wbSubjects = [];
    $prepared = [];

    foreach ($products as $key => $product) {
        if (!is_array($product)) {
            continue;
        }
        $brand = trim((string)($product['brand'] ?? ''));
        $hasOzonBrand = array_key_exists('brand_ozon', $product);
        $hasWbBrand = array_key_exists('brand_wb', $product);
        $ozonBrand = $hasOzonBrand ? trim((string)$product['brand_ozon']) : '';
        $wbBrand = $hasWbBrand ? trim((string)$product['brand_wb']) : '';
        if (!$hasOzonBrand) {
            $ozonBrand = $brand;
        }
        if (!$hasWbBrand) {
            $wbBrand = $brand;
        }
        $ozonNorm = marketplace_brand_status_norm($ozonBrand);
        $wbNorm = marketplace_brand_status_norm($wbBrand);
        $ozonIds = marketplace_brand_status_ozon_category_ids((string)($product['ozon_category'] ?? ''));
        $wbSubjectId = marketplace_brand_status_wb_subject_id((string)($product['wb_category'] ?? ''));
        $prepared[$key] = [
            'brand' => $brand,
            'brand_ozon' => $ozonBrand,
            'brand_wb' => $wbBrand,
            'ozon_norm' => $ozonNorm,
            'wb_norm' => $wbNorm,
            'ozon_parent_id' => (int)$ozonIds['parent_id'],
            'ozon_leaf_id' => (int)$ozonIds['leaf_id'],
            'wb_subject_id' => $wbSubjectId,
        ];
        if ($ozonNorm !== '') {
            $norms[$ozonNorm] = true;
        }
        if ($wbNorm !== '') {
            $norms[$wbNorm] = true;
        }
        if ((int)$ozonIds['parent_id'] > 0) {
            $ozonParents[(int)$ozonIds['parent_id']] = true;
        }
        if ((int)$ozonIds['leaf_id'] > 0) {
            $ozonLeafs[(int)$ozonIds['leaf_id']] = true;
        }
        if ($wbSubjectId > 0) {
            $wbSubjects[$wbSubjectId] = true;
        }
    }

    $normList = array_keys($norms);
    $ozonExisting = marketplace_brand_status_fetch_existing_norms('ozon', $normList);
    $wbExisting = marketplace_brand_status_fetch_existing_norms('wb', $normList);
    $wbParentBySubject = marketplace_brand_status_wb_parent_ids(array_keys($wbSubjects));
    $ozonFit = marketplace_brand_status_fetch_ozon_fit($normList, array_keys($ozonParents), array_keys($ozonLeafs));
    $wbFit = marketplace_brand_status_fetch_wb_fit($normList, array_keys($wbSubjects), array_values($wbParentBySubject));

    $out = [];
    foreach ($prepared as $key => $product) {
        $ozonNorm = (string)($product['ozon_norm'] ?? '');
        $wbNorm = (string)($product['wb_norm'] ?? '');

        $ozonStatus = 'not_found';
        $ozonBrandName = '';
        if ($ozonNorm === '') {
            $ozonStatus = 'missing_brand';
        } elseif ((int)$product['ozon_parent_id'] <= 0) {
            $ozonStatus = 'category_not_selected';
        } elseif (isset($ozonExisting[$ozonNorm])) {
            $ozonBrandName = (string)($ozonExisting[$ozonNorm]['brand_name'] ?? '');
            $parentId = (int)$product['ozon_parent_id'];
            $leafId = (int)$product['ozon_leaf_id'];
            $ok = $parentId > 0 && (
                isset($ozonFit[$ozonNorm][$parentId][0])
                || ($leafId > 0 && isset($ozonFit[$ozonNorm][$parentId][$leafId]))
            );
            $ozonStatus = $ok ? 'ok' : 'category_mismatch';
        }

        $wbStatus = 'not_found';
        $wbBrandName = '';
        if ($wbNorm === '') {
            $wbStatus = 'missing_brand';
        } elseif ((int)$product['wb_subject_id'] <= 0) {
            $wbStatus = 'category_not_selected';
        } elseif (isset($wbExisting[$wbNorm])) {
            $wbBrandName = (string)($wbExisting[$wbNorm]['brand_name'] ?? '');
            $subjectId = (int)$product['wb_subject_id'];
            $parentId = (int)($wbParentBySubject[$subjectId] ?? 0);
            $ok = $subjectId > 0 && (
                isset($wbFit[$wbNorm]['subject'][$subjectId])
                || ($parentId > 0 && isset($wbFit[$wbNorm]['parent'][$parentId]))
            );
            $wbStatus = $ok ? 'ok' : 'category_mismatch';
        }

        $out[$key] = [
            'ozon' => marketplace_brand_status_make($ozonStatus, $ozonBrandName),
            'wb' => marketplace_brand_status_make($wbStatus, $wbBrandName),
        ];
    }

    return $out;
}

function marketplace_brand_status_for_product(array $product): array
{
    $statuses = marketplace_brand_status_for_products(['product' => $product]);
    return (array)($statuses['product'] ?? []);
}

function marketplace_brand_suggestions(string $query, int $limit = 24, string $ozonCategory = '', string $wbCategory = '', string $marketplace = ''): array
{
    marketplace_brand_dictionary_tables_ensure();
    $limit = max(1, min(50, $limit));
    $marketplace = strtolower(trim($marketplace));
    if (!in_array($marketplace, ['', 'ozon', 'wb', 'wildberries'], true)) {
        $marketplace = '';
    }
    if ($marketplace === 'wildberries') {
        $marketplace = 'wb';
    }
    $norm = marketplace_brand_status_norm($query);
    $like = $norm !== '' ? '%' . str_replace(['%', '_'], ['\\%', '\\_'], $norm) . '%' : '%';

    $rows = [];
    $sources = ['ozon' => true, 'wb' => true];

    $ozonIds = marketplace_brand_status_ozon_category_ids($ozonCategory);
    $ozonParentId = (int)($ozonIds['parent_id'] ?? 0);
    $ozonLeafId = (int)($ozonIds['leaf_id'] ?? 0);
    if ($marketplace !== 'wb' && $ozonParentId > 0) {
        $typeSql = 'c.type_id = 0';
        $args = [$ozonParentId];
        if ($ozonLeafId > 0) {
            $typeSql = '(c.type_id = 0 OR c.type_id = ?)';
            $args[] = $ozonLeafId;
        }
        $args = array_merge($args, [$like, $norm, $norm . '%']);
        $sql = "
            SELECT DISTINCT b.brand_id, b.brand_name, b.brand_name_norm
            FROM feedtools_ozon_brands b
            JOIN feedtools_ozon_brand_categories c ON c.brand_id = b.brand_id
            WHERE c.description_category_id = ?
              AND {$typeSql}
              AND b.brand_name_norm LIKE ? ESCAPE '\\\\'
            ORDER BY
              CASE
                WHEN b.brand_name_norm = ? THEN 0
                WHEN b.brand_name_norm LIKE ? ESCAPE '\\\\' THEN 1
                ELSE 2
              END ASC,
              CHAR_LENGTH(b.brand_name_norm) ASC,
              b.brand_name ASC
            LIMIT " . (int)($limit * 2);
        $st = db()->prepare($sql);
        $st->execute($args);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $row['_source'] = 'ozon';
            $rows[] = $row;
        }
    }

    $wbSubjectId = marketplace_brand_status_wb_subject_id($wbCategory);
    if ($marketplace !== 'ozon' && $wbSubjectId > 0) {
        $wbParentBySubject = marketplace_brand_status_wb_parent_ids([$wbSubjectId]);
        $wbParentId = (int)($wbParentBySubject[$wbSubjectId] ?? 0);
        $where = 'c.subject_id = ?';
        $args = [$wbSubjectId];
        if ($wbParentId > 0) {
            $where = '(c.subject_id = ? OR (c.subject_id = 0 AND c.parent_id = ?))';
            $args[] = $wbParentId;
        }
        $args = array_merge($args, [$like, $norm, $norm . '%']);
        $sql = "
            SELECT DISTINCT b.brand_id, b.brand_name, b.brand_name_norm
            FROM feedtools_wb_brands b
            JOIN feedtools_wb_brand_categories c ON c.brand_id = b.brand_id
            WHERE {$where}
              AND b.brand_name_norm LIKE ? ESCAPE '\\\\'
            ORDER BY
              CASE
                WHEN b.brand_name_norm = ? THEN 0
                WHEN b.brand_name_norm LIKE ? ESCAPE '\\\\' THEN 1
                ELSE 2
              END ASC,
              CHAR_LENGTH(b.brand_name_norm) ASC,
              b.brand_name ASC
            LIMIT " . (int)($limit * 2);
        $st = db()->prepare($sql);
        $st->execute($args);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $row['_source'] = 'wb';
            $rows[] = $row;
        }
    }

    $byNorm = [];
    foreach ($rows as $row) {
        $brandNorm = (string)($row['brand_name_norm'] ?? '');
        $brandName = trim((string)($row['brand_name'] ?? ''));
        $source = (string)($row['_source'] ?? '');
        if ($brandNorm === '' || $brandName === '' || !isset($sources[$source])) {
            continue;
        }
        if (!isset($byNorm[$brandNorm])) {
            $byNorm[$brandNorm] = [
                'name' => $brandName,
                'norm' => $brandNorm,
                'sources' => [],
                'score' => 2,
                'len' => function_exists('mb_strlen') ? mb_strlen($brandNorm, 'UTF-8') : strlen($brandNorm),
            ];
        }
        if ($source === 'ozon') {
            $byNorm[$brandNorm]['name'] = $brandName;
        }
        $byNorm[$brandNorm]['sources'][$source] = true;
        if ($norm !== '') {
            if ($brandNorm === $norm) {
                $byNorm[$brandNorm]['score'] = min((int)$byNorm[$brandNorm]['score'], 0);
            } elseif (strncmp($brandNorm, $norm, strlen($norm)) === 0) {
                $byNorm[$brandNorm]['score'] = min((int)$byNorm[$brandNorm]['score'], 1);
            }
        }
    }

    $items = array_values($byNorm);
    usort($items, static function (array $a, array $b): int {
        $cmp = ((int)($a['score'] ?? 2) <=> (int)($b['score'] ?? 2));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = ((int)($a['len'] ?? 0) <=> (int)($b['len'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $out = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        $sourceMap = (array)($item['sources'] ?? []);
        $itemSources = [];
        foreach (['ozon', 'wb'] as $source) {
            if (!empty($sourceMap[$source])) {
                $itemSources[] = $source;
            }
        }
        $out[] = [
            'name' => (string)($item['name'] ?? ''),
            'sources' => $itemSources,
        ];
    }
    return $out;
}
