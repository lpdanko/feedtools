<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/marketplace_brand_dictionary.php';
require_once __DIR__ . '/marketplace_taxonomy_sync.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/wildberries/WildberriesPriceTool.php';

function supplier_brand_ops_connection(array $cfg, string $marketplace, int $requestedId): array
{
    if ($requestedId <= 0) {
        $st = db()->prepare("
            SELECT id
            FROM feedtools_marketplace_connections
            WHERE marketplace = ?
              AND is_active = 1
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $st->execute([$marketplace]);
        $requestedId = (int)($st->fetchColumn() ?: 0);
    }
    $connection = ozon_price_connection_get($requestedId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== $marketplace) {
        throw new RuntimeException($marketplace === 'ozon' ? 'Выбери подключение Ozon.' : 'Выбери подключение Wildberries.');
    }
    return $connection;
}

function supplier_brand_ops_ozon_cfg(array $cfg, array $ds, array $params): array
{
    $connectionId = (int)($params['ozon_connection_id'] ?? $params['connection_id'] ?? $ds['ozon_connection_id'] ?? 0);
    $connection = supplier_brand_ops_connection($cfg, 'ozon', $connectionId);
    return [
        (int)$connection['id'],
        [
            'client_id' => (string)($connection['client_id'] ?? ''),
            'api_key' => (string)($connection['api_key'] ?? ''),
            'base_url' => (string)($connection['base_url'] ?? 'https://api-seller.ozon.ru'),
            'timeout_sec' => max(10, (int)($connection['timeout_sec'] ?? 30)),
        ],
    ];
}

function supplier_brand_ops_wb_client(array $cfg, array $ds, array $params): array
{
    $connectionId = (int)($params['wb_connection_id'] ?? $params['connection_id'] ?? $ds['wb_connection_id'] ?? 0);
    $connection = supplier_brand_ops_connection($cfg, 'wb', $connectionId);
    return [(int)$connection['id'], wb_price_tool_client($cfg, $connection)];
}

function supplier_brand_ops_ozon_scope_key(int $descriptionCategoryId, int $typeId, int $attributeId): string
{
    return $descriptionCategoryId . ':' . $typeId . ':' . $attributeId;
}

function supplier_brand_ops_ozon_parent_scope_key(int $descriptionCategoryId, int $attributeId = 85): string
{
    return supplier_brand_ops_ozon_scope_key($descriptionCategoryId, 0, $attributeId);
}

function supplier_brand_ops_wb_scope_key(int $subjectId): string
{
    return (string)$subjectId;
}

function supplier_brand_ops_ozon_category_name(string $categoryValue): string
{
    if (!preg_match('~^(\d+)_([0-9]+)$~', $categoryValue, $m)) {
        return $categoryValue;
    }
    $st = db()->prepare("
        SELECT full_path
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id = ?
          AND ozon_leaf_id = ?
        LIMIT 1
    ");
    $st->execute([(int)$m[1], (int)$m[2]]);
    return (string)($st->fetchColumn() ?: $categoryValue);
}

function supplier_brand_ops_ozon_parent_category_name(int $descriptionCategoryId): string
{
    if ($descriptionCategoryId <= 0) {
        return '';
    }
    $st = db()->prepare("
        SELECT full_path
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id = ?
        ORDER BY full_path ASC
        LIMIT 1
    ");
    $st->execute([$descriptionCategoryId]);
    $fullPath = trim((string)($st->fetchColumn() ?: ''));
    if ($fullPath !== '') {
        $parts = array_values(array_filter(array_map('trim', explode('>', $fullPath)), static fn(string $part): bool => $part !== ''));
        if (count($parts) >= 2) {
            return $parts[0] . ' > ' . $parts[1] . ' (' . $descriptionCategoryId . ')';
        }
        if ($parts) {
            return $parts[0] . ' (' . $descriptionCategoryId . ')';
        }
    }
    return (string)$descriptionCategoryId;
}

function supplier_brand_ops_wb_category_name(int $subjectId): string
{
    if ($subjectId <= 0) {
        return '';
    }
    $st = db()->prepare("
        SELECT full_path
        FROM feedtools_taxonomy_categories
        WHERE source = 'wildberries'
          AND external_id = ?
        LIMIT 1
    ");
    $st->execute(['wb:subject:' . $subjectId]);
    return (string)($st->fetchColumn() ?: (string)$subjectId);
}

function supplier_brand_ops_ozon_parent_request_type_id(int $descriptionCategoryId): int
{
    if ($descriptionCategoryId <= 0) {
        return 0;
    }
    $st = db()->prepare("
        SELECT MIN(ozon_leaf_id)
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id = ?
          AND ozon_leaf_id > 0
    ");
    $st->execute([$descriptionCategoryId]);
    return (int)$st->fetchColumn();
}

function supplier_brand_ops_fetch_ozon_parent_scope(array $oz, int $descriptionCategoryId, int $requestTypeId, int $attributeId, string $categoryName, int $ttlDays, bool $force): array
{
    $scopeKey = supplier_brand_ops_ozon_parent_scope_key($descriptionCategoryId, $attributeId);
    if (!$force && marketplace_brand_scope_fetch_is_fresh('ozon', $scopeKey, $ttlDays)) {
        $row = marketplace_brand_scope_fetch_row('ozon', $scopeKey) ?: [];
        if ((int)($row['values_count'] ?? 0) > 0) {
            return ['status' => 'cached', 'values' => (int)($row['values_count'] ?? 0), 'error' => ''];
        }
    }

    $scope = [
        'category_value' => (string)$descriptionCategoryId,
        'category_name' => $categoryName,
        'description_category_id' => $descriptionCategoryId,
        'type_id' => 0,
        'attribute_id' => $attributeId,
    ];
    try {
        if ($requestTypeId <= 0) {
            throw new RuntimeException('Не найден type_id листовой категории для запроса брендов Ozon.');
        }
        $values = marketplace_ozon_brand_fetch_category_values($oz, (string)$descriptionCategoryId, $descriptionCategoryId, $requestTypeId, $attributeId, 200, true);
        marketplace_brand_scope_fetch_upsert('ozon', $scopeKey, $scope, 'ok', $values, '');
        return ['status' => 'fetched', 'values' => $values, 'error' => ''];
    } catch (Throwable $e) {
        marketplace_brand_scope_fetch_upsert('ozon', $scopeKey, $scope, 'error', 0, $e->getMessage());
        return ['status' => 'error', 'values' => 0, 'error' => $e->getMessage()];
    }
}

function supplier_brand_ops_fetch_wb_scope(
    WildberriesClient $client,
    int $subjectId,
    int $parentId,
    string $categoryName,
    int $ttlDays,
    bool $force,
    ?callable $log = null
): array
{
    $scopeKey = supplier_brand_ops_wb_scope_key($subjectId);
    if (!$force && marketplace_brand_scope_fetch_is_fresh('wb', $scopeKey, $ttlDays)) {
        $row = marketplace_brand_scope_fetch_row('wb', $scopeKey) ?: [];
        return ['status' => 'cached', 'values' => (int)($row['values_count'] ?? 0), 'error' => ''];
    }

    $scope = [
        'category_value' => (string)$subjectId,
        'category_name' => $categoryName,
        'subject_id' => $subjectId,
        'parent_id' => $parentId,
    ];
    try {
        $values = marketplace_wb_brand_fetch_subject_values(
            $client,
            $subjectId,
            (string)$subjectId,
            '',
            50,
            static function (int $attempt, int $delay, Throwable $e) use ($subjectId, $categoryName, $log): void {
                if ($log === null) {
                    return;
                }
                $log(
                    'WB limiter: категория ' . $subjectId . ' ' .
                    supplier_brand_ops_short_label($categoryName, 90) .
                    '; пауза ' . $delay . ' сек.; попытка ' . ($attempt + 1) . '/8; ' .
                    $e->getMessage() . "\n"
                );
            }
        );
        marketplace_brand_scope_fetch_upsert('wb', $scopeKey, $scope, 'ok', $values, '');
        return ['status' => 'fetched', 'values' => $values, 'error' => ''];
    } catch (Throwable $e) {
        marketplace_brand_scope_fetch_upsert('wb', $scopeKey, $scope, 'error', 0, $e->getMessage());
        return ['status' => 'error', 'values' => 0, 'error' => $e->getMessage()];
    }
}

function supplier_brand_ops_wb_sleep_between_brand_categories(array $res): void
{
    $status = (string)($res['status'] ?? '');
    if ($status === 'cached') {
        return;
    }
    if ($status === 'error' && !marketplace_wb_brand_is_rate_limit_error(new RuntimeException((string)($res['error'] ?? '')))) {
        usleep(250000);
        return;
    }
    usleep($status === 'error' ? 1500000 : 650000);
}

function supplier_brand_ops_index_status(string $marketplace): array
{
    marketplace_brand_dictionary_tables_ensure();
    if ($marketplace === 'ozon') {
        $total = (int)db()->query("
            SELECT COUNT(DISTINCT ozon_parent_id)
            FROM feedtools_taxonomy_categories
            WHERE source = 'ozon'
              AND is_leaf = 1
              AND ozon_parent_id > 0
              AND ozon_leaf_id > 0
        ")->fetchColumn();
        $ok = (int)db()->query("
            SELECT COUNT(DISTINCT description_category_id)
            FROM feedtools_ozon_brand_categories
            WHERE description_category_id > 0
              AND type_id = 0
        ")->fetchColumn();
        return ['marketplace' => 'ozon', 'total_scopes' => $total, 'ok_scopes' => $ok, 'complete' => $total > 0 && $ok >= $total];
    }

    $total = (int)db()->query("
        SELECT COUNT(DISTINCT external_id)
        FROM feedtools_taxonomy_categories
        WHERE source = 'wildberries'
          AND external_id LIKE 'wb:subject:%'
    ")->fetchColumn();
    $ok = (int)db()->query("
        SELECT COUNT(DISTINCT subject_id)
        FROM feedtools_marketplace_brand_scope_fetches
        WHERE marketplace = 'wb'
          AND status = 'ok'
          AND subject_id > 0
    ")->fetchColumn();
    return ['marketplace' => 'wb', 'total_scopes' => $total, 'ok_scopes' => $ok, 'complete' => $total > 0 && $ok >= $total];
}

function supplier_brand_ops_short_label(string $value, int $maxLen = 120): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    if ($value === '') {
        return 'без названия';
    }
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $maxLen) {
        return mb_substr($value, 0, max(1, $maxLen - 1), 'UTF-8') . '…';
    }
    if (!function_exists('mb_strlen') && strlen($value) > $maxLen) {
        return substr($value, 0, max(1, $maxLen - 1)) . '…';
    }
    return $value;
}

function supplier_brand_ops_full_progress_message(string $marketplaceLabel, int $done, int $total, string $categoryName, array $stats): string
{
    $cloned = (int)($stats['cloned'] ?? 0);
    $middle = 'API ' . (int)($stats['fetched'] ?? 0);
    if ($cloned > 0) {
        $middle .= ', из кэша ' . $cloned;
    }
    return sprintf(
        '%s: категория %d/%d, %s; %s, свежие %d, ошибки %d, брендов %d',
        $marketplaceLabel,
        $done,
        max(1, $total),
        supplier_brand_ops_short_label($categoryName, 80),
        $middle,
        (int)($stats['cached'] ?? 0),
        (int)($stats['errors'] ?? 0),
        (int)($stats['values'] ?? 0)
    );
}

function supplier_brand_ops_decode_category_selection(array $params): array
{
    $items = [];
    $json = trim((string)($params['category_values_json'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $value = trim((string)($item['value'] ?? $item['category_value'] ?? ''));
                    $label = trim((string)($item['label'] ?? ''));
                    if ($value !== '') {
                        $items[] = ['value' => $value, 'label' => $label];
                    }
                } else {
                    $value = trim((string)$item);
                    if ($value !== '') {
                        $items[] = ['value' => $value, 'label' => ''];
                    }
                }
            }
        }
    }

    $single = trim((string)($params['category_value'] ?? ''));
    if ($single !== '') {
        $items[] = ['value' => $single, 'label' => ''];
    }

    $out = [];
    $seen = [];
    foreach ($items as $item) {
        $value = trim((string)($item['value'] ?? ''));
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $out[] = $item;
    }
    return $out;
}

function supplier_brand_ops_expand_ozon_category_selection(array $params): array
{
    $items = supplier_brand_ops_decode_category_selection($params);
    $out = [];
    foreach ($items as $item) {
        $value = trim((string)($item['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, 'leaf:')) {
            $value = substr($value, 5);
        }
        if (preg_match('~^(\d+)_([0-9]+)$~', $value, $m)) {
            $parentId = (int)$m[1];
            if ($parentId > 0) {
                $out[(string)$parentId] = $parentId;
            }
            continue;
        }
        if (ctype_digit($value)) {
            $parentId = (int)$value;
            if ($parentId > 0) {
                $out[(string)$parentId] = $parentId;
            }
            continue;
        }
        if (str_starts_with($value, 'node:')) {
            $nodeId = (int)substr($value, 5);
            if ($nodeId <= 0) {
                continue;
            }
            $st = db()->prepare("
                SELECT id, full_path, is_leaf, ozon_parent_id, ozon_leaf_id
                FROM feedtools_taxonomy_categories
                WHERE id = ?
                  AND source = 'ozon'
                LIMIT 1
            ");
            $st->execute([$nodeId]);
            $node = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($node)) {
                continue;
            }
            if ((int)($node['is_leaf'] ?? 0) === 1) {
                $parentId = (int)($node['ozon_parent_id'] ?? 0);
                if ($parentId > 0) {
                    $out[(string)$parentId] = $parentId;
                }
                continue;
            }
            $prefix = trim((string)($node['full_path'] ?? ''));
            if ($prefix === '') {
                continue;
            }
            $children = db()->prepare("
                SELECT ozon_parent_id
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND ozon_parent_id > 0
                  AND ozon_leaf_id > 0
                  AND full_path LIKE ?
                GROUP BY ozon_parent_id
                ORDER BY MIN(full_path) ASC
            ");
            $children->execute([$prefix . ' > %']);
            while ($row = $children->fetch(PDO::FETCH_ASSOC)) {
                $parentId = (int)$row['ozon_parent_id'];
                if ($parentId > 0) {
                    $out[(string)$parentId] = $parentId;
                }
            }
        }
    }
    return array_values($out);
}

function supplier_brand_ops_expand_wb_category_selection(array $params): array
{
    $items = supplier_brand_ops_decode_category_selection($params);
    $out = [];
    foreach ($items as $item) {
        $value = trim((string)($item['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, 'leaf:')) {
            $value = substr($value, 5);
        }
        if (ctype_digit($value) && (int)$value > 0) {
            $out[(string)(int)$value] = (int)$value;
            continue;
        }
        if (str_starts_with($value, 'node:')) {
            $nodeId = (int)substr($value, 5);
            if ($nodeId <= 0) {
                continue;
            }
            $st = db()->prepare("
                SELECT id, external_id, parent_external_id, full_path, is_leaf
                FROM feedtools_taxonomy_categories
                WHERE id = ?
                  AND source = 'wildberries'
                LIMIT 1
            ");
            $st->execute([$nodeId]);
            $node = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($node)) {
                continue;
            }
            $externalId = (string)($node['external_id'] ?? '');
            if ((int)($node['is_leaf'] ?? 0) === 1) {
                if (preg_match('~^wb:subject:(\d+)$~', $externalId, $m)) {
                    $out[$m[1]] = (int)$m[1];
                }
                continue;
            }
            $prefix = trim((string)($node['full_path'] ?? ''));
            if ($prefix !== '') {
                $children = db()->prepare("
                    SELECT external_id
                    FROM feedtools_taxonomy_categories
                    WHERE source = 'wildberries'
                      AND is_leaf = 1
                      AND external_id LIKE 'wb:subject:%'
                      AND full_path LIKE ?
                    ORDER BY full_path ASC
                ");
                $children->execute([$prefix . ' > %']);
            } else {
                $children = db()->prepare("
                    SELECT external_id
                    FROM feedtools_taxonomy_categories
                    WHERE source = 'wildberries'
                      AND is_leaf = 1
                      AND parent_external_id = ?
                      AND external_id LIKE 'wb:subject:%'
                    ORDER BY full_path ASC
                ");
                $children->execute([$externalId]);
            }
            while ($row = $children->fetch(PDO::FETCH_ASSOC)) {
                if (preg_match('~^wb:subject:(\d+)$~', (string)($row['external_id'] ?? ''), $m)) {
                    $out[$m[1]] = (int)$m[1];
                }
            }
        }
    }
    return array_values($out);
}

function supplier_brand_ops_sync_ozon_all(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    [$connectionId, $oz] = supplier_brand_ops_ozon_cfg($cfg, $ds, $params);
    marketplace_brand_dictionary_tables_ensure();
    $refreshDays = max(0, min(3650, (int)($params['refresh_days'] ?? 30)));
    $force = (string)($params['force_refresh'] ?? '0') === '1' || $refreshDays <= 0;
    $maxScopes = max(0, (int)($params['max_scopes'] ?? 0));

    $rows = db()->query("
        SELECT ozon_parent_id, MIN(ozon_leaf_id) AS request_type_id, MIN(full_path) AS full_path, COUNT(DISTINCT ozon_leaf_id) AS leaf_count
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id > 0
          AND ozon_leaf_id > 0
        GROUP BY ozon_parent_id
        ORDER BY MIN(full_path) ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total = count($rows);
    $progressTotal = $maxScopes > 0 ? min($maxScopes, $total) : $total;
    $modeLabel = $force ? 'принудительно обновляю все выбранные категории' : "обновляю только категории старше {$refreshDays} дн.";
    $log("Ozon brands sync started\n");
    $log("Connection: #{$connectionId}\n");
    $log("Categories in taxonomy: {$total}; categories in this run: {$progressTotal}; mode: {$modeLabel}\n");
    ops_update_progress($opId, 0, max(1, $progressTotal), 'ozon_brands', "Ozon: старт, категорий {$progressTotal}");

    $stats = ['marketplace' => 'ozon', 'connection_id' => $connectionId, 'scopes_total' => $total, 'fetched' => 0, 'cached' => 0, 'errors' => 0, 'values' => 0, 'processed' => 0, 'cancelled' => false, 'error_samples' => []];
    $outDir = op_output_dir($cfg, (int)($ds['id'] ?? 0), $opId);
    $cancelFlag = $outDir . '/cancel.flag';

    foreach ($rows as $row) {
        if ($maxScopes > 0 && $stats['processed'] >= $maxScopes) {
            break;
        }
        clearstatcache(true, $cancelFlag);
        if (is_file($cancelFlag) || ops_is_cancel_requested($opId)) {
            $stats['cancelled'] = true;
            $log("Ozon brands sync cancelled by user after {$stats['processed']} categories.\n");
            break;
        }
        $stats['processed']++;
        $descriptionCategoryId = (int)($row['ozon_parent_id'] ?? 0);
        if ($descriptionCategoryId <= 0) {
            continue;
        }
        $categoryName = supplier_brand_ops_ozon_parent_category_name($descriptionCategoryId);
        $requestTypeId = (int)($row['request_type_id'] ?? 0);
        $attributeId = 85;
        $res = supplier_brand_ops_fetch_ozon_parent_scope(
            $oz,
            $descriptionCategoryId,
            $requestTypeId,
            $attributeId,
            $categoryName,
            $refreshDays,
            $force
        );
        $stats['values'] += (int)($res['values'] ?? 0);
        if (($res['status'] ?? '') === 'cached') {
            $stats['cached']++;
        } elseif (($res['status'] ?? '') === 'error') {
            $stats['errors']++;
            if (count($stats['error_samples']) < 20) {
                $stats['error_samples'][] = $descriptionCategoryId . ': ' . (string)($res['error'] ?? '');
            }
            $log("ERROR Ozon {$descriptionCategoryId} " . supplier_brand_ops_short_label($categoryName, 100) . ': ' . (string)($res['error'] ?? '') . "\n");
        } else {
            $stats['fetched']++;
        }
        $progressMsg = supplier_brand_ops_full_progress_message('Ozon', $stats['processed'], $progressTotal, $categoryName, $stats);
        if ($stats['processed'] % 10 === 0 || $stats['processed'] === $progressTotal || ($res['status'] ?? '') === 'error') {
            ops_update_progress($opId, min($stats['processed'], max(1, $progressTotal)), max(1, $progressTotal), 'ozon_brands', $progressMsg);
        }
        if ($stats['processed'] % 25 === 0 || ($res['status'] ?? '') === 'error') {
            $log($progressMsg . "\n");
        }
        if (($res['status'] ?? '') === 'fetched') {
            usleep(50000);
        }
    }

    $indexStatus = supplier_brand_ops_index_status('ozon');
    $report = [
        'title' => 'Полная синхронизация брендов Ozon',
        'items' => [
            'Категорий обработано: ' . $stats['processed'] . ' из ' . $total,
            'Загружено через API: ' . $stats['fetched'],
            'Пропущено свежих: ' . $stats['cached'],
            'Ошибок категорий: ' . $stats['errors'],
            'Значений брендов получено: ' . $stats['values'],
        ],
        'metrics' => ['stats' => $stats, 'index_status' => $indexStatus],
    ];
    $outputs = supplier_products_db_report_output($cfg, (int)($ds['id'] ?? 0), $opId, $report);
    $finishMsg = $stats['cancelled']
        ? 'Ozon: остановлено пользователем'
        : "Ozon: готово, обработано {$stats['processed']} категорий второго уровня, API {$stats['fetched']}, свежие {$stats['cached']}, ошибки {$stats['errors']}, брендов {$stats['values']}";
    $log($finishMsg . "\n");
    ops_update_progress(
        $opId,
        min($stats['processed'], max(1, $progressTotal)),
        max(1, $progressTotal),
        $stats['cancelled'] ? 'cancelled' : 'done',
        $finishMsg
    );
    return $outputs;
}

function supplier_brand_ops_sync_wb_all(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    [$connectionId, $client] = supplier_brand_ops_wb_client($cfg, $ds, $params);
    marketplace_brand_dictionary_tables_ensure();
    $refreshDays = max(0, min(3650, (int)($params['refresh_days'] ?? 30)));
    $force = (string)($params['force_refresh'] ?? '0') === '1' || $refreshDays <= 0;
    $maxScopes = max(0, (int)($params['max_scopes'] ?? 0));

    $rows = db()->query("
        SELECT external_id, MIN(full_path) AS full_path
        FROM feedtools_taxonomy_categories
        WHERE source = 'wildberries'
          AND external_id LIKE 'wb:subject:%'
        GROUP BY external_id
        ORDER BY full_path ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total = count($rows);
    $progressTotal = $maxScopes > 0 ? min($maxScopes, $total) : $total;
    $modeLabel = $force ? 'принудительно обновляю все выбранные категории' : "обновляю только категории старше {$refreshDays} дн.";
    $log("WB brands sync started\n");
    $log("Connection: #{$connectionId}\n");
    $log("Categories in taxonomy: {$total}; categories in this run: {$progressTotal}; mode: {$modeLabel}\n");
    ops_update_progress($opId, 0, max(1, $progressTotal), 'wb_brands', "WB: старт, категорий {$progressTotal}");

    $stats = ['marketplace' => 'wb', 'connection_id' => $connectionId, 'scopes_total' => $total, 'fetched' => 0, 'cached' => 0, 'errors' => 0, 'values' => 0, 'processed' => 0, 'cancelled' => false, 'error_samples' => []];
    $outDir = op_output_dir($cfg, (int)($ds['id'] ?? 0), $opId);
    $cancelFlag = $outDir . '/cancel.flag';

    foreach ($rows as $row) {
        if ($maxScopes > 0 && $stats['processed'] >= $maxScopes) {
            break;
        }
        clearstatcache(true, $cancelFlag);
        if (is_file($cancelFlag) || ops_is_cancel_requested($opId)) {
            $stats['cancelled'] = true;
            $log("WB brands sync cancelled by user after {$stats['processed']} categories.\n");
            break;
        }
        $externalId = (string)($row['external_id'] ?? '');
        if (!preg_match('~^wb:subject:(\d+)$~', $externalId, $m)) {
            continue;
        }
        $stats['processed']++;
        $subjectId = (int)$m[1];
        $parentId = marketplace_wb_parent_id_for_subject($subjectId);
        $categoryName = (string)($row['full_path'] ?? $subjectId);
        $res = supplier_brand_ops_fetch_wb_scope(
            $client,
            $subjectId,
            $parentId,
            $categoryName,
            $refreshDays,
            $force,
            $log
        );
        $stats['values'] += (int)($res['values'] ?? 0);
        if (($res['status'] ?? '') === 'cached') {
            $stats['cached']++;
        } elseif (($res['status'] ?? '') === 'error') {
            $stats['errors']++;
            if (count($stats['error_samples']) < 20) {
                $stats['error_samples'][] = $subjectId . ': ' . (string)($res['error'] ?? '');
            }
            $log("ERROR WB {$subjectId} " . supplier_brand_ops_short_label($categoryName, 100) . ': ' . (string)($res['error'] ?? '') . "\n");
        } else {
            $stats['fetched']++;
        }
        $progressMsg = supplier_brand_ops_full_progress_message('WB', $stats['processed'], $progressTotal, $categoryName, $stats);
        if ($stats['processed'] % 10 === 0 || $stats['processed'] === $progressTotal || ($res['status'] ?? '') === 'error') {
            ops_update_progress($opId, min($stats['processed'], max(1, $progressTotal)), max(1, $progressTotal), 'wb_brands', $progressMsg);
        }
        if ($stats['processed'] % 25 === 0 || ($res['status'] ?? '') === 'error') {
            $log($progressMsg . "\n");
        }
        if (($res['status'] ?? '') !== 'cached') {
            supplier_brand_ops_wb_sleep_between_brand_categories($res);
        }
    }

    $indexStatus = supplier_brand_ops_index_status('wb');
    $report = [
        'title' => 'Полная синхронизация брендов WB',
        'items' => [
            'Категорий обработано: ' . $stats['processed'] . ' из ' . $total,
            'Загружено категорий: ' . $stats['fetched'],
            'Пропущено свежих: ' . $stats['cached'],
            'Ошибок категорий: ' . $stats['errors'],
            'Значений брендов получено: ' . $stats['values'],
        ],
        'metrics' => ['stats' => $stats, 'index_status' => $indexStatus],
    ];
    $outputs = supplier_products_db_report_output($cfg, (int)($ds['id'] ?? 0), $opId, $report);
    $finishMsg = $stats['cancelled']
        ? 'WB: остановлено пользователем'
        : "WB: готово, обработано {$stats['processed']} категорий, загружено {$stats['fetched']}, свежие {$stats['cached']}, ошибки {$stats['errors']}, брендов {$stats['values']}";
    $log($finishMsg . "\n");
    ops_update_progress(
        $opId,
        min($stats['processed'], max(1, $progressTotal)),
        max(1, $progressTotal),
        $stats['cancelled'] ? 'cancelled' : 'done',
        $finishMsg
    );
    return $outputs;
}

function supplier_products_db_op_sync_ozon_brands_category(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    [$connectionId, $oz] = supplier_brand_ops_ozon_cfg($cfg, $ds, $params);
    $descriptionCategoryIds = supplier_brand_ops_expand_ozon_category_selection($params);
    if (!$descriptionCategoryIds) {
        throw new RuntimeException('Выбери одну или несколько категорий Ozon.');
    }
    $force = (string)($params['force_refresh'] ?? '1') !== '0';
    $refreshDays = max(0, min(3650, (int)($params['refresh_days'] ?? 30)));
    $total = count($descriptionCategoryIds);

    $log("Ozon category brands sync started\n");
    $log("Connection: #{$connectionId}; categories={$total}; force=" . ($force ? 'yes' : 'no') . "\n");
    ops_update_progress($opId, 0, max(1, $total), 'ozon_brands', "Ozon: загружаю бренды категорий 0/{$total}");

    $stats = [
        'marketplace' => 'ozon',
        'connection_id' => $connectionId,
        'categories_total' => $total,
        'processed' => 0,
        'fetched' => 0,
        'cached' => 0,
        'errors' => 0,
        'values' => 0,
        'error_samples' => [],
        'categories' => [],
    ];

    foreach ($descriptionCategoryIds as $descriptionCategoryId) {
        $descriptionCategoryId = (int)$descriptionCategoryId;
        if ($descriptionCategoryId <= 0) {
            continue;
        }
        $stats['processed']++;
        $attributeId = 85;
        $requestTypeId = supplier_brand_ops_ozon_parent_request_type_id($descriptionCategoryId);
        $categoryName = supplier_brand_ops_ozon_parent_category_name($descriptionCategoryId);
        ops_update_progress($opId, $stats['processed'] - 1, max(1, $total), 'ozon_brands', 'Ozon: ' . supplier_brand_ops_short_label($categoryName, 80));
        $res = supplier_brand_ops_fetch_ozon_parent_scope(
            $oz,
            $descriptionCategoryId,
            $requestTypeId,
            $attributeId,
            $categoryName,
            $refreshDays,
            $force
        );
        $scopeKey = supplier_brand_ops_ozon_parent_scope_key($descriptionCategoryId, $attributeId);
        $scopeRow = marketplace_brand_scope_fetch_row('ozon', $scopeKey);
        $brandCount = (int)($scopeRow['values_count'] ?? $res['values'] ?? 0);
        $stats['values'] += $brandCount;
        if (($res['status'] ?? '') === 'cached') {
            $stats['cached']++;
        } elseif (($res['status'] ?? '') === 'error') {
            $stats['errors']++;
            if (count($stats['error_samples']) < 20) {
                $stats['error_samples'][] = $descriptionCategoryId . ': ' . (string)($res['error'] ?? '');
            }
            $log("ERROR Ozon {$descriptionCategoryId} " . supplier_brand_ops_short_label($categoryName, 100) . ': ' . (string)($res['error'] ?? '') . "\n");
        } else {
            $stats['fetched']++;
        }
        if (count($stats['categories']) < 100) {
            $stats['categories'][] = [
                'category_value' => (string)$descriptionCategoryId,
                'category_name' => $categoryName,
                'status' => (string)($res['status'] ?? ''),
                'brands' => $brandCount,
            ];
        }
        ops_update_progress(
            $opId,
            $stats['processed'],
            max(1, $total),
            'ozon_brands',
            "Ozon: категорий второго уровня {$stats['processed']}/{$total}, API {$stats['fetched']}, свежие {$stats['cached']}, ошибки {$stats['errors']}, брендов {$stats['values']}"
        );
    }

    $log("Ozon category brands sync finished: categories={$stats['processed']}, fetched={$stats['fetched']}, cached={$stats['cached']}, errors={$stats['errors']}, brands={$stats['values']}\n");
    $report = [
        'title' => 'Обновление брендов категорий Ozon',
        'items' => [
            'Категорий обработано: ' . $stats['processed'] . ' из ' . $total,
            'Загружено через API: ' . $stats['fetched'],
            'Пропущено свежих: ' . $stats['cached'],
            'Ошибок категорий: ' . $stats['errors'],
            'Значений брендов получено: ' . $stats['values'],
        ],
        'metrics' => ['stats' => $stats],
    ];
    $outputs = supplier_products_db_report_output($cfg, (int)($ds['id'] ?? 0), $opId, $report);
    ops_update_progress($opId, $stats['processed'], max(1, $total), 'done', "Ozon: готово, категорий {$stats['processed']}, ошибки {$stats['errors']}, брендов {$stats['values']}");
    return $outputs;
}

function supplier_products_db_op_sync_wb_brands_category(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    [$connectionId, $client] = supplier_brand_ops_wb_client($cfg, $ds, $params);
    $subjectIds = supplier_brand_ops_expand_wb_category_selection($params);
    if (!$subjectIds) {
        throw new RuntimeException('Выбери одну или несколько категорий WB.');
    }
    $force = (string)($params['force_refresh'] ?? '1') !== '0';
    $refreshDays = max(0, min(3650, (int)($params['refresh_days'] ?? 30)));
    $total = count($subjectIds);

    $log("WB category brands sync started\n");
    $log("Connection: #{$connectionId}; categories={$total}; force=" . ($force ? 'yes' : 'no') . "\n");
    ops_update_progress($opId, 0, max(1, $total), 'wb_brands', "WB: загружаю бренды категорий 0/{$total}");

    $stats = [
        'marketplace' => 'wb',
        'connection_id' => $connectionId,
        'categories_total' => $total,
        'processed' => 0,
        'fetched' => 0,
        'cached' => 0,
        'errors' => 0,
        'values' => 0,
        'error_samples' => [],
        'categories' => [],
    ];

    foreach ($subjectIds as $subjectId) {
        $subjectId = (int)$subjectId;
        if ($subjectId <= 0) {
            continue;
        }
        $stats['processed']++;
        $parentId = marketplace_wb_parent_id_for_subject($subjectId);
        $categoryName = supplier_brand_ops_wb_category_name($subjectId);
        ops_update_progress($opId, $stats['processed'] - 1, max(1, $total), 'wb_brands', 'WB: ' . supplier_brand_ops_short_label($categoryName, 80));
        $res = supplier_brand_ops_fetch_wb_scope(
            $client,
            $subjectId,
            $parentId,
            $categoryName,
            $refreshDays,
            $force,
            $log
        );
        $scopeKey = supplier_brand_ops_wb_scope_key($subjectId);
        $scopeRow = marketplace_brand_scope_fetch_row('wb', $scopeKey);
        $brandCount = (int)($scopeRow['values_count'] ?? $res['values'] ?? 0);
        $stats['values'] += $brandCount;
        if (($res['status'] ?? '') === 'cached') {
            $stats['cached']++;
        } elseif (($res['status'] ?? '') === 'error') {
            $stats['errors']++;
            if (count($stats['error_samples']) < 20) {
                $stats['error_samples'][] = $subjectId . ': ' . (string)($res['error'] ?? '');
            }
            $log("ERROR WB {$subjectId} " . supplier_brand_ops_short_label($categoryName, 100) . ': ' . (string)($res['error'] ?? '') . "\n");
        } else {
            $stats['fetched']++;
        }
        if (count($stats['categories']) < 100) {
            $stats['categories'][] = [
                'subject_id' => $subjectId,
                'category_name' => $categoryName,
                'status' => (string)($res['status'] ?? ''),
                'brands' => $brandCount,
            ];
        }
        ops_update_progress(
            $opId,
            $stats['processed'],
            max(1, $total),
            'wb_brands',
            "WB: категорий {$stats['processed']}/{$total}, загружено {$stats['fetched']}, свежие {$stats['cached']}, ошибки {$stats['errors']}, брендов {$stats['values']}"
        );
        supplier_brand_ops_wb_sleep_between_brand_categories($res);
    }

    $log("WB category brands sync finished: categories={$stats['processed']}, fetched={$stats['fetched']}, cached={$stats['cached']}, errors={$stats['errors']}, brands={$stats['values']}\n");
    $report = [
        'title' => 'Обновление брендов категорий WB',
        'items' => [
            'Категорий обработано: ' . $stats['processed'] . ' из ' . $total,
            'Загружено категорий: ' . $stats['fetched'],
            'Пропущено свежих: ' . $stats['cached'],
            'Ошибок категорий: ' . $stats['errors'],
            'Значений брендов получено: ' . $stats['values'],
        ],
        'metrics' => ['stats' => $stats],
    ];
    $outputs = supplier_products_db_report_output($cfg, (int)($ds['id'] ?? 0), $opId, $report);
    ops_update_progress($opId, $stats['processed'], max(1, $total), 'done', "WB: готово, категорий {$stats['processed']}, ошибки {$stats['errors']}, брендов {$stats['values']}");
    return $outputs;
}

function supplier_products_db_op_sync_ozon_brands_full(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    return supplier_brand_ops_sync_ozon_all($cfg, $ds, $opId, $params, $log);
}

function supplier_products_db_op_sync_wb_brands_full(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    return supplier_brand_ops_sync_wb_all($cfg, $ds, $opId, $params, $log);
}
