<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/wildberries/WildberriesClient.php';

function marketplace_brand_dictionary_norm(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$value);
    $value = preg_replace('/\s+/u', ' ', (string)$value);
    return trim((string)$value);
}

function marketplace_brand_dictionary_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function marketplace_brand_dictionary_compact_raw(array $raw): array
{
    $out = [];
    foreach (['id', 'value', 'info'] as $key) {
        if (array_key_exists($key, $raw)) {
            $out[$key] = $raw[$key];
        }
    }
    return $out ?: $raw;
}

function marketplace_brand_dictionary_tables_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_brands (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_id BIGINT UNSIGNED NOT NULL,
            brand_name VARCHAR(255) NOT NULL DEFAULT '',
            brand_name_norm VARCHAR(255) NOT NULL DEFAULT '',
            raw_json LONGTEXT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ozon_brand_id (brand_id),
            KEY idx_ozon_brand_name_norm (brand_name_norm)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_brand_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_id BIGINT UNSIGNED NOT NULL,
            description_category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_value VARCHAR(191) NOT NULL DEFAULT '',
            category_scope VARCHAR(24) NOT NULL DEFAULT 'leaf',
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ozon_brand_category (brand_id, description_category_id, type_id),
            KEY idx_ozon_category_brand (description_category_id, type_id, brand_id),
            KEY idx_ozon_category_value (category_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_brands (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_id BIGINT UNSIGNED NOT NULL,
            brand_name VARCHAR(255) NOT NULL DEFAULT '',
            brand_name_norm VARCHAR(255) NOT NULL DEFAULT '',
            raw_json LONGTEXT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wb_brand_id (brand_id),
            KEY idx_wb_brand_name_norm (brand_name_norm)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_brand_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_id BIGINT UNSIGNED NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_value VARCHAR(191) NOT NULL DEFAULT '',
            category_scope VARCHAR(24) NOT NULL DEFAULT 'subject',
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wb_brand_category (brand_id, subject_id, parent_id),
            KEY idx_wb_subject_brand (subject_id, brand_id),
            KEY idx_wb_parent_brand (parent_id, brand_id),
            KEY idx_wb_category_value (category_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_brand_scope_fetches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace VARCHAR(32) NOT NULL DEFAULT '',
            scope_key VARCHAR(191) NOT NULL DEFAULT '',
            category_value VARCHAR(191) NOT NULL DEFAULT '',
            category_name TEXT NULL,
            description_category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attribute_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT '',
            values_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            error_text TEXT NULL,
            fetched_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_brand_scope_fetch (marketplace, scope_key),
            KEY idx_brand_scope_status (marketplace, status, fetched_at),
            KEY idx_brand_scope_category (marketplace, category_value),
            KEY idx_brand_scope_ozon (description_category_id, type_id, attribute_id),
            KEY idx_brand_scope_wb (subject_id, parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function marketplace_brand_scope_fetch_upsert(string $marketplace, string $scopeKey, array $scope, string $status, int $valuesCount = 0, string $errorText = ''): void
{
    $marketplace = trim($marketplace);
    $scopeKey = trim($scopeKey);
    if ($marketplace === '' || $scopeKey === '') {
        return;
    }
    marketplace_brand_dictionary_tables_ensure();
    db()->prepare("
        INSERT INTO feedtools_marketplace_brand_scope_fetches (
            marketplace, scope_key, category_value, category_name,
            description_category_id, type_id, attribute_id, subject_id, parent_id,
            status, values_count, error_text, fetched_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            category_value = VALUES(category_value),
            category_name = VALUES(category_name),
            description_category_id = VALUES(description_category_id),
            type_id = VALUES(type_id),
            attribute_id = VALUES(attribute_id),
            subject_id = VALUES(subject_id),
            parent_id = VALUES(parent_id),
            status = VALUES(status),
            values_count = VALUES(values_count),
            error_text = VALUES(error_text),
            fetched_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $marketplace,
        mb_substr($scopeKey, 0, 191, 'UTF-8'),
        mb_substr((string)($scope['category_value'] ?? ''), 0, 191, 'UTF-8'),
        (string)($scope['category_name'] ?? ''),
        max(0, (int)($scope['description_category_id'] ?? 0)),
        max(0, (int)($scope['type_id'] ?? 0)),
        max(0, (int)($scope['attribute_id'] ?? 0)),
        max(0, (int)($scope['subject_id'] ?? 0)),
        max(0, (int)($scope['parent_id'] ?? 0)),
        mb_substr($status, 0, 32, 'UTF-8'),
        max(0, $valuesCount),
        $errorText !== '' ? mb_substr($errorText, 0, 2000, 'UTF-8') : null,
    ]);
}

function marketplace_brand_scope_fetch_row(string $marketplace, string $scopeKey): ?array
{
    marketplace_brand_dictionary_tables_ensure();
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_brand_scope_fetches
        WHERE marketplace = ?
          AND scope_key = ?
        LIMIT 1
    ");
    $st->execute([trim($marketplace), trim($scopeKey)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function marketplace_brand_scope_fetch_is_fresh(string $marketplace, string $scopeKey, int $ttlDays): bool
{
    if ($ttlDays <= 0) {
        return false;
    }
    $row = marketplace_brand_scope_fetch_row($marketplace, $scopeKey);
    if (!is_array($row) || (string)($row['status'] ?? '') !== 'ok') {
        return false;
    }
    $fetchedAt = trim((string)($row['fetched_at'] ?? ''));
    if ($fetchedAt === '') {
        return false;
    }
    $ts = strtotime($fetchedAt);
    return $ts !== false && $ts >= (time() - $ttlDays * 86400);
}

function marketplace_ozon_brand_upsert(
    int $descriptionCategoryId,
    int $typeId,
    string $categoryValue,
    int $brandId,
    string $brandName,
    array $raw
): void {
    marketplace_ozon_brand_upsert_many($descriptionCategoryId, $typeId, $categoryValue, [[
        'id' => $brandId,
        'value' => $brandName,
        'raw' => $raw,
    ]]);
}

function marketplace_ozon_brand_upsert_many(
    int $descriptionCategoryId,
    int $typeId,
    string $categoryValue,
    array $items
): int {
    if ($descriptionCategoryId <= 0 || $typeId < 0 || !$items) {
        return 0;
    }
    marketplace_brand_dictionary_tables_ensure();
    $pdo = db();
    $brandStmt = $pdo->prepare("
        INSERT INTO feedtools_ozon_brands
            (brand_id, brand_name, brand_name_norm, raw_json, fetched_at)
        VALUES
            (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            brand_name = VALUES(brand_name),
            brand_name_norm = VALUES(brand_name_norm),
            raw_json = VALUES(raw_json),
            fetched_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ");
    $categoryStmt = $pdo->prepare("
        INSERT INTO feedtools_ozon_brand_categories
            (brand_id, description_category_id, type_id, category_value, category_scope, fetched_at)
        VALUES
            (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            category_value = VALUES(category_value),
            category_scope = VALUES(category_scope),
            fetched_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ");
    $startedTx = !$pdo->inTransaction();
    if ($startedTx) {
        $pdo->beginTransaction();
    }
    $count = 0;
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = is_array($item['raw'] ?? null) ? $item['raw'] : $item;
            $brandId = (int)($item['id'] ?? $raw['id'] ?? 0);
            $brandName = trim((string)($item['value'] ?? $raw['value'] ?? ''));
            if ($brandId <= 0 || $brandName === '') {
                continue;
            }
            $brandStmt->execute([
                $brandId,
                mb_substr($brandName, 0, 255, 'UTF-8'),
                mb_substr(marketplace_brand_dictionary_norm($brandName), 0, 255, 'UTF-8'),
                marketplace_brand_dictionary_json(marketplace_brand_dictionary_compact_raw($raw)),
            ]);
            $categoryStmt->execute([
                $brandId,
                $descriptionCategoryId,
                $typeId,
                mb_substr(trim($categoryValue), 0, 191, 'UTF-8'),
                $typeId > 0 ? 'leaf' : 'parent',
            ]);
            $count++;
        }
        if ($startedTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $count;
}

function marketplace_wb_brand_upsert(int $subjectId, string $categoryValue, int $brandId, string $brandName, array $raw, int $parentId = 0): void
{
    marketplace_wb_brand_upsert_many($subjectId, $categoryValue, [[
        'id' => $brandId,
        'name' => $brandName,
        'raw' => $raw,
    ]], $parentId);
}

function marketplace_wb_brand_upsert_many(int $subjectId, string $categoryValue, array $items, int $parentId = 0): int
{
    if (($subjectId <= 0 && $parentId <= 0) || !$items) {
        return 0;
    }
    marketplace_brand_dictionary_tables_ensure();
    $pdo = db();
    $brandStmt = $pdo->prepare("
        INSERT INTO feedtools_wb_brands
            (brand_id, brand_name, brand_name_norm, raw_json, fetched_at)
        VALUES
            (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            brand_name = VALUES(brand_name),
            brand_name_norm = VALUES(brand_name_norm),
            raw_json = VALUES(raw_json),
            fetched_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ");
    $categoryStmt = $pdo->prepare("
        INSERT INTO feedtools_wb_brand_categories
            (brand_id, subject_id, parent_id, category_value, category_scope, fetched_at)
        VALUES
            (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            category_value = VALUES(category_value),
            category_scope = VALUES(category_scope),
            fetched_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ");
    $startedTx = !$pdo->inTransaction();
    if ($startedTx) {
        $pdo->beginTransaction();
    }
    $count = 0;
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = is_array($item['raw'] ?? null) ? $item['raw'] : $item;
            $brandId = (int)($item['id'] ?? $raw['id'] ?? 0);
            $brandName = trim((string)($item['name'] ?? $item['value'] ?? $raw['name'] ?? $raw['value'] ?? ''));
            if ($brandId <= 0 || $brandName === '') {
                continue;
            }
            $brandStmt->execute([
                $brandId,
                mb_substr($brandName, 0, 255, 'UTF-8'),
                mb_substr(marketplace_brand_dictionary_norm($brandName), 0, 255, 'UTF-8'),
                marketplace_brand_dictionary_json(marketplace_brand_dictionary_compact_raw($raw)),
            ]);
            $categoryStmt->execute([
                $brandId,
                max(0, $subjectId),
                max(0, $parentId),
                mb_substr(trim($categoryValue), 0, 191, 'UTF-8'),
                $subjectId > 0 ? 'subject' : 'parent',
            ]);
            $count++;
        }
        if ($startedTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $count;
}

function marketplace_ozon_brand_find_parent(int $descriptionCategoryId, string $brandName): ?array
{
    $norm = marketplace_brand_dictionary_norm($brandName);
    if ($descriptionCategoryId <= 0 || $norm === '') {
        return null;
    }
    marketplace_brand_dictionary_tables_ensure();

    $st = db()->prepare("
        SELECT b.*, c.description_category_id, c.type_id, c.category_value, c.category_scope
        FROM feedtools_ozon_brands b
        JOIN feedtools_ozon_brand_categories c ON c.brand_id = b.brand_id
        WHERE c.description_category_id = ?
          AND c.type_id = 0
          AND b.brand_name_norm = ?
        ORDER BY b.brand_id ASC
        LIMIT 1
    ");
    $st->execute([$descriptionCategoryId, mb_substr($norm, 0, 255, 'UTF-8')]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function marketplace_ozon_brand_find(int $descriptionCategoryId, int $typeId, string $brandName): ?array
{
    return marketplace_ozon_brand_find_parent($descriptionCategoryId, $brandName);
}

function marketplace_wb_parent_id_for_subject(int $subjectId): int
{
    if ($subjectId <= 0) {
        return 0;
    }
    $st = db()->prepare("
        SELECT meta_json
        FROM feedtools_taxonomy_categories
        WHERE source = 'wildberries'
          AND external_id = ?
        LIMIT 1
    ");
    $st->execute(['wb:subject:' . $subjectId]);
    $meta = json_decode((string)($st->fetchColumn() ?: '{}'), true);
    return is_array($meta) ? (int)($meta['raw']['wb_parent_id'] ?? ($meta['wb_parent_id'] ?? 0)) : 0;
}

function marketplace_wb_brand_find(int $subjectId, string $brandName): ?array
{
    $norm = marketplace_brand_dictionary_norm($brandName);
    if ($subjectId <= 0 || $norm === '') {
        return null;
    }
    marketplace_brand_dictionary_tables_ensure();

    $st = db()->prepare("
        SELECT b.*, c.subject_id, c.parent_id, c.category_value, c.category_scope
        FROM feedtools_wb_brands b
        JOIN feedtools_wb_brand_categories c ON c.brand_id = b.brand_id
        WHERE c.subject_id = ?
          AND b.brand_name_norm = ?
        ORDER BY c.subject_id DESC, b.brand_id ASC
        LIMIT 1
    ");
    $st->execute([
        $subjectId,
        mb_substr($norm, 0, 255, 'UTF-8'),
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return $row;
    }

    $parentId = marketplace_wb_parent_id_for_subject($subjectId);
    if ($parentId > 0) {
        $st = db()->prepare("
            SELECT b.*, c.subject_id, c.parent_id, c.category_value, c.category_scope
            FROM feedtools_wb_brands b
            JOIN feedtools_wb_brand_categories c ON c.brand_id = b.brand_id
            WHERE c.subject_id = ?
              AND c.parent_id = ?
              AND b.brand_name_norm = ?
            ORDER BY c.subject_id DESC, b.brand_id ASC
            LIMIT 1
        ");
        $st->execute([
            0,
            $parentId,
            mb_substr($norm, 0, 255, 'UTF-8'),
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }
    return null;
}

function marketplace_ozon_brand_response_values(array $resp): array
{
    $result = $resp['result'] ?? [];
    if (!is_array($result)) {
        return [];
    }
    if (isset($result['values']) && is_array($result['values'])) {
        return array_values(array_filter($result['values'], 'is_array'));
    }
    $items = [];
    foreach ($result as $row) {
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    return $items;
}

function marketplace_ozon_brand_response_has_next(array $resp): bool
{
    if (array_key_exists('has_next', $resp)) {
        return (bool)$resp['has_next'];
    }
    $result = $resp['result'] ?? null;
    return is_array($result) && array_key_exists('has_next', $result) ? (bool)$result['has_next'] : false;
}

function marketplace_ozon_brand_response_last_value_id(array $resp, array $items, int $fallback): int
{
    if (isset($resp['last_value_id'])) {
        return (int)$resp['last_value_id'];
    }
    $result = $resp['result'] ?? null;
    if (is_array($result) && isset($result['last_value_id'])) {
        return (int)$result['last_value_id'];
    }
    for ($i = count($items) - 1; $i >= 0; $i--) {
        if (!is_array($items[$i] ?? null)) {
            continue;
        }
        $id = (int)($items[$i]['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }
    return $fallback;
}

function marketplace_ozon_brand_fetch_category_values(
    array $oz,
    string $categoryValue,
    int $descriptionCategoryId,
    int $typeId,
    int $attributeId,
    int $maxPages = 50,
    bool $storeParentScope = false
): int {
    if ($descriptionCategoryId <= 0 || $typeId < 0 || $attributeId <= 0) {
        return 0;
    }

    $lastValueId = 0;
    $count = 0;
    $seen = [];
    // In practice the current endpoint caps a page at 2000 values even when a
    // larger limit is requested. Keep it explicit and page by last value id.
    $limit = 2000;
    for ($page = 1; $page <= $maxPages; $page++) {
        $resp = ozon_post_json($oz, '/v1/description-category/attribute/values', [
            'description_category_id' => $descriptionCategoryId,
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'language' => 'RU',
            'last_value_id' => $lastValueId,
            'limit' => $limit,
        ]);
        $items = marketplace_ozon_brand_response_values($resp);
        if (!$items) {
            break;
        }
        $pageItems = [];
        foreach ($items as $item) {
            $brandName = trim((string)($item['value'] ?? ''));
            $brandId = (int)($item['id'] ?? 0);
            if ($brandId <= 0 || $brandName === '' || isset($seen[$brandId])) {
                continue;
            }
            $seen[$brandId] = true;
            $pageItems[] = $item;
        }
        if ($pageItems) {
            $storeTypeId = $storeParentScope ? 0 : $typeId;
            $storeCategoryValue = $storeParentScope ? (string)$descriptionCategoryId : $categoryValue;
            $count += marketplace_ozon_brand_upsert_many($descriptionCategoryId, $storeTypeId, $storeCategoryValue, $pageItems);
        }
        $hasNext = marketplace_ozon_brand_response_has_next($resp);
        if (!$hasNext) {
            break;
        }
        $next = marketplace_ozon_brand_response_last_value_id($resp, $items, $lastValueId);
        if ($next <= $lastValueId) {
            break;
        }
        $lastValueId = $next;
    }
    return $count;
}

function marketplace_ozon_brand_search_category_value(
    array $oz,
    string $categoryValue,
    int $descriptionCategoryId,
    int $typeId,
    int $attributeId,
    string $brandName,
    int $limit = 50
): ?array {
    $brandName = trim($brandName);
    if ($brandName === '' || $descriptionCategoryId <= 0 || $typeId <= 0 || $attributeId <= 0) {
        return null;
    }

    $existing = marketplace_ozon_brand_find_parent($descriptionCategoryId, $brandName);
    if (is_array($existing)) {
        return $existing;
    }

    static $searched = [];
    $norm = marketplace_brand_dictionary_norm($brandName);
    $cacheKey = $descriptionCategoryId . ':0:' . $attributeId . ':' . $norm;
    if (isset($searched[$cacheKey])) {
        return marketplace_ozon_brand_find_parent($descriptionCategoryId, $brandName);
    }
    $searched[$cacheKey] = true;

    $resp = ozon_post_json($oz, '/v1/description-category/attribute/values/search', [
        'description_category_id' => $descriptionCategoryId,
        'type_id' => $typeId,
        'attribute_id' => $attributeId,
        'value' => $brandName,
        'limit' => max(1, min(100, $limit)),
    ]);
    foreach (marketplace_ozon_brand_response_values($resp) as $item) {
        $candidateName = trim((string)($item['value'] ?? ''));
        $brandId = (int)($item['id'] ?? 0);
        if ($brandId > 0 && $candidateName !== '') {
            marketplace_ozon_brand_upsert($descriptionCategoryId, 0, (string)$descriptionCategoryId, $brandId, $candidateName, $item);
        }
    }

    $found = marketplace_ozon_brand_find_parent($descriptionCategoryId, $brandName);
    return $found;
}

function marketplace_ozon_brand_resolve(
    ?array $oz,
    string $categoryValue,
    int $descriptionCategoryId,
    int $typeId,
    int $attributeId,
    string $brandName
): ?array {
    $brandName = trim($brandName);
    if ($brandName === '' || $descriptionCategoryId <= 0 || $attributeId <= 0) {
        return null;
    }
    $row = marketplace_ozon_brand_find_parent($descriptionCategoryId, $brandName);
    if (is_array($row)) {
        return $row;
    }
    if ($oz !== null) {
        $row = marketplace_ozon_brand_search_category_value($oz, $categoryValue, $descriptionCategoryId, $typeId, $attributeId, $brandName);
        if (is_array($row)) {
            return $row;
        }
    }
    return null;
}

function marketplace_wb_brand_search_subject_value(
    WildberriesClient $client,
    int $subjectId,
    string $categoryValue,
    string $brandName
): ?array {
    $brandName = trim($brandName);
    if ($subjectId <= 0 || $brandName === '') {
        return null;
    }
    $existing = marketplace_wb_brand_find($subjectId, $brandName);
    if (is_array($existing)) {
        return $existing;
    }
    marketplace_wb_brand_fetch_subject_values($client, $subjectId, $categoryValue, $brandName, 3);
    $found = marketplace_wb_brand_find($subjectId, $brandName);
    return $found;
}

function marketplace_wb_brand_response_items(array $resp): array
{
    $candidates = [
        $resp['brands'] ?? null,
        $resp['data']['brands'] ?? null,
        $resp['data'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $items = [];
        foreach ($candidate as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        if ($items) {
            return $items;
        }
    }
    return [];
}

function marketplace_wb_brand_is_rate_limit_error(Throwable $e): bool
{
    $message = $e->getMessage();
    return str_contains($message, 'HTTP 429') || stripos($message, 'global limiter') !== false || stripos($message, 'rate limit') !== false;
}

function marketplace_wb_brand_rate_limit_delay(int $attempt): int
{
    $seconds = min(90, 8 * max(1, $attempt));
    return $seconds + random_int(0, 3);
}

function marketplace_wb_brand_fetch_subject_values(
    WildberriesClient $client,
    int $subjectId,
    string $categoryValue = '',
    string $searchName = '',
    int $maxPages = 10,
    ?callable $onRetry = null
): int {
    if ($subjectId <= 0) {
        return 0;
    }

    $next = 0;
    $count = 0;
    $parentId = marketplace_wb_parent_id_for_subject($subjectId);
    $seen = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $resp = null;
        try {
            for ($attempt = 1; $attempt <= 8; $attempt++) {
                try {
                    $resp = $client->getBrands($subjectId, 1000, $next, $searchName);
                    break;
                } catch (Throwable $e) {
                    if (!marketplace_wb_brand_is_rate_limit_error($e) || $attempt >= 8) {
                        throw $e;
                    }
                    $delay = marketplace_wb_brand_rate_limit_delay($attempt);
                    if ($onRetry !== null) {
                        $onRetry($attempt, $delay, $e);
                    }
                    sleep($delay);
                }
            }
        } catch (Throwable $e) {
            if (trim($searchName) === '') {
                throw $e;
            }
            return 0;
        }
        if (!is_array($resp)) {
            break;
        }
        $items = marketplace_wb_brand_response_items($resp);
        if (!$items) {
            break;
        }
        $pageItems = [];
        foreach ($items as $item) {
            $brandName = trim((string)($item['name'] ?? ''));
            $brandId = (int)($item['id'] ?? 0);
            if ($brandId <= 0 || $brandName === '' || isset($seen[$brandId])) {
                continue;
            }
            $seen[$brandId] = true;
            $pageItems[] = $item;
        }
        if ($pageItems) {
            $count += marketplace_wb_brand_upsert_many($subjectId, $categoryValue, $pageItems, $parentId);
        }
        $nextValue = (int)($resp['next'] ?? ($resp['data']['next'] ?? 0));
        if ($nextValue <= 0 || $nextValue === $next) {
            break;
        }
        $next = $nextValue;
    }
    return $count;
}

function marketplace_wb_brand_resolve(
    ?WildberriesClient $client,
    int $subjectId,
    string $brandName,
    string $categoryValue = ''
): ?array {
    $brandName = trim($brandName);
    if ($brandName === '' || $subjectId <= 0) {
        return null;
    }
    $row = marketplace_wb_brand_find($subjectId, $brandName);
    if (is_array($row)) {
        return $row;
    }
    if ($client !== null) {
        marketplace_wb_brand_fetch_subject_values($client, $subjectId, $categoryValue, $brandName, 3);
        $row = marketplace_wb_brand_find($subjectId, $brandName);
        if (is_array($row)) {
            return $row;
        }
        marketplace_wb_brand_fetch_subject_values($client, $subjectId, $categoryValue, '', 10);
        $row = marketplace_wb_brand_find($subjectId, $brandName);
        if (is_array($row)) {
            return $row;
        }
    }
    return null;
}
