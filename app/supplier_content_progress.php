<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/wildberries/WildberriesProducts.php';

function supplier_content_progress_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . "\0" . $column;
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $st->execute([$table, $column]);
        $cache[$key] = (int)$st->fetchColumn() > 0;
    } catch (Throwable) {
        $cache[$key] = false;
    }
    return (bool)$cache[$key];
}

function supplier_content_progress_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
    static $cache = [];
    $key = $table . "\0" . $indexName;
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $st->execute([$table, $indexName]);
        $cache[$key] = (int)$st->fetchColumn() > 0;
    } catch (Throwable) {
        $cache[$key] = false;
    }
    return (bool)$cache[$key];
}

function supplier_content_progress_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!supplier_content_progress_table_has_column($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
}

function supplier_content_progress_add_index_if_missing(PDO $pdo, string $table, string $indexName, string $definition): void
{
    if (!supplier_content_progress_table_has_index($pdo, $table, $indexName)) {
        $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
    }
}

function supplier_content_progress_tables_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    suppliers_table_ensure($cfg);
    supplier_products_tables_ensure($cfg);
    ozon_products_tables_ensure($cfg);
    wb_products_ensure_table(db());

    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_content_assessments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            offer_id VARCHAR(191) NOT NULL DEFAULT '',
            vendor_code VARCHAR(191) NOT NULL DEFAULT '',
            marketplace VARCHAR(32) NOT NULL DEFAULT '',
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            upload_status VARCHAR(32) NOT NULL DEFAULT 'not_uploaded',
            normalized_status VARCHAR(32) NOT NULL DEFAULT 'not_uploaded',
            is_uploaded TINYINT(1) NOT NULL DEFAULT 0,
            is_ready TINYINT(1) NOT NULL DEFAULT 0,
            is_sellable TINYINT(1) NOT NULL DEFAULT 0,
            marketplace_stage_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            card_quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            issue_penalty DECIMAL(8,2) NOT NULL DEFAULT 0,
            issues_json LONGTEXT NULL,
            quality_breakdown_json LONGTEXT NULL,
            metrics_json LONGTEXT NULL,
            assessed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_supplier_product_marketplace (supplier_id, product_id, marketplace, connection_id),
            KEY idx_supplier_marketplace_status (supplier_id, marketplace, normalized_status),
            KEY idx_supplier_quality (supplier_id, card_quality_score),
            KEY idx_supplier_assessed (supplier_id, assessed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_content_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            captured_at DATETIME NOT NULL,
            capture_date DATE NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'all',
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            products_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            not_uploaded_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ready_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sellable_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            error_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revision_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            archived_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            critical_issues_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fixable_issues_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            avg_card_quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            upload_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            sellable_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            error_health_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            marketplace_completion_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            content_progress_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            data_confidence_level VARCHAR(16) NOT NULL DEFAULT 'medium',
            data_confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            metrics_json LONGTEXT NULL,
            issue_breakdown_json LONGTEXT NULL,
            score_breakdown_json LONGTEXT NULL,
            data_warnings_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_supplier_marketplace_captured (supplier_id, marketplace, connection_id, captured_at),
            KEY idx_capture_date_supplier (capture_date, supplier_id),
            KEY idx_score_captured (content_progress_score, captured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_content_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            period_date DATE NOT NULL,
            actor_user VARCHAR(191) NULL,
            actor_kind VARCHAR(32) NOT NULL DEFAULT 'system',
            supplier_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            offer_id VARCHAR(191) NOT NULL DEFAULT '',
            marketplace VARCHAR(32) NOT NULL DEFAULT '',
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            op_id BIGINT UNSIGNED NULL,
            op_type VARCHAR(191) NULL,
            event_type VARCHAR(64) NOT NULL DEFAULT '',
            from_value VARCHAR(191) NULL,
            to_value VARCHAR(191) NULL,
            issue_code VARCHAR(64) NULL,
            content_points DECIMAL(8,2) NOT NULL DEFAULT 0,
            details_json LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_period_supplier (period_date, supplier_id),
            KEY idx_actor_period (actor_user, period_date),
            KEY idx_supplier_product (supplier_id, product_id),
            KEY idx_op_id (op_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (suppliers_table_exists($pdo, 'feedtools_datasets')) {
        supplier_content_progress_add_column_if_missing($pdo, 'feedtools_datasets', 'ozon_connection_id', 'ozon_connection_id BIGINT UNSIGNED NULL DEFAULT NULL');
        supplier_content_progress_add_column_if_missing($pdo, 'feedtools_datasets', 'wb_connection_id', 'wb_connection_id BIGINT UNSIGNED NULL DEFAULT NULL');
    }

    $done = true;
}

function supplier_content_progress_json($value, array $fallback = []): array
{
    if (is_array($value)) {
        return $value;
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function supplier_content_progress_clamp(float $value, float $min = 0.0, float $max = 100.0): float
{
    return max($min, min($max, $value));
}

function supplier_content_progress_round(float $value): float
{
    return round(supplier_content_progress_clamp($value), 2);
}

function supplier_content_progress_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function supplier_content_progress_plain_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('~\s+~u', ' ', $text));
}

function supplier_content_progress_text_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function supplier_content_progress_connection_default_id(string $marketplace): int
{
    $marketplace = $marketplace === 'wb' ? 'wb' : 'ozon';
    try {
        $st = db()->prepare("
            SELECT id
            FROM feedtools_marketplace_connections
            WHERE marketplace = ?
            ORDER BY is_active DESC, sort_order ASC, id ASC
            LIMIT 1
        ");
        $st->execute([$marketplace]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

function supplier_content_progress_resolve_connections(int $supplierId, array $cfg = []): array
{
    supplier_content_progress_tables_ensure($cfg);

    $datasetId = 0;
    try {
        $meta = supplier_products_meta_get($supplierId, $cfg);
        $datasetId = (int)($meta['dataset_id'] ?? 0);
    } catch (Throwable) {
        $datasetId = 0;
    }

    $ozonConnectionId = 0;
    $wbConnectionId = 0;
    if ($datasetId > 0) {
        try {
            $st = db()->prepare("SELECT ozon_connection_id, wb_connection_id FROM feedtools_datasets WHERE id = ? LIMIT 1");
            $st->execute([$datasetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $ozonConnectionId = (int)($row['ozon_connection_id'] ?? 0);
            $wbConnectionId = (int)($row['wb_connection_id'] ?? 0);
        } catch (Throwable) {
            $ozonConnectionId = 0;
            $wbConnectionId = 0;
        }
    }

    if ($ozonConnectionId <= 0) {
        $ozonConnectionId = supplier_content_progress_connection_default_id('ozon');
    }
    if ($wbConnectionId <= 0) {
        $wbConnectionId = supplier_content_progress_connection_default_id('wb');
    }

    return [
        'dataset_id' => $datasetId,
        'ozon_connection_id' => $ozonConnectionId,
        'wb_connection_id' => $wbConnectionId,
        'target_marketplaces' => ['ozon', 'wb'],
    ];
}

function supplier_content_progress_offer_candidates(array $product, string $supplierCode): array
{
    $candidates = [];
    foreach ([
        (string)($product['offer_id'] ?? ''),
        suppliers_apply_supplier_code((string)($product['offer_id'] ?? ''), $supplierCode),
        (string)($product['vendor_code'] ?? ''),
        suppliers_apply_supplier_code((string)($product['vendor_code'] ?? ''), $supplierCode),
    ] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $candidates[$candidate] = true;
        }
    }
    return array_keys($candidates);
}

function supplier_content_progress_query_map(string $sql, array $ids): array
{
    $map = [];
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => trim($id) !== '')));
    if (!$ids) {
        return $map;
    }
    foreach (array_chunk($ids, 900) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare(str_replace('__IDS__', $placeholders, $sql));
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $key = trim((string)($row['_key'] ?? ''));
            if ($key !== '') {
                $map[$key] = $row;
            }
        }
    }
    return $map;
}

function supplier_content_progress_marketplace_maps(array $products, string $supplierCode, array $connections): array
{
    $allIds = [];
    foreach ($products as $product) {
        foreach (supplier_content_progress_offer_candidates($product, $supplierCode) as $candidate) {
            $allIds[$candidate] = true;
        }
    }
    $ids = array_keys($allIds);

    $ozon = [];
    $ozonConnectionId = (int)($connections['ozon_connection_id'] ?? 0);
    if ($ozonConnectionId > 0 && $ids) {
        $ozon = supplier_content_progress_query_map("
            SELECT
                offer_id AS _key,
                offer_id,
                marketplace_status,
                status_name,
                status_description,
                status_failed,
                moderate_status,
                validation_status,
                content_rating,
                raw_json,
                is_active,
                is_archived,
                last_seen_at
            FROM feedtools_ozon_products
            WHERE connection_id = {$ozonConnectionId} AND offer_id IN (__IDS__)
        ", $ids);
    }

    $wb = [];
    $wbConnectionId = (int)($connections['wb_connection_id'] ?? 0);
    if ($wbConnectionId > 0 && $ids) {
        $wb = supplier_content_progress_query_map("
            SELECT
                vendor_code AS _key,
                vendor_code,
                marketplace_status,
                status_text,
                quality_score,
                quality_recommendations_json,
                raw_json,
                is_active,
                is_trash,
                last_seen_at
            FROM feedtools_wb_products
            WHERE connection_id = {$wbConnectionId} AND vendor_code IN (__IDS__)
        ", $ids);
    }

    return ['ozon' => $ozon, 'wb' => $wb];
}

function supplier_content_progress_find_marketplace_row(array $product, array $map, string $supplierCode): ?array
{
    foreach (supplier_content_progress_offer_candidates($product, $supplierCode) as $candidate) {
        if (isset($map[$candidate]) && is_array($map[$candidate])) {
            return $map[$candidate];
        }
    }
    return null;
}

function supplier_content_progress_price(array $product): float
{
    $value = $product['price_original'] ?? null;
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    return is_numeric($value) ? (float)$value : 0.0;
}

function supplier_content_progress_stock(array $product): int
{
    return max(0, (int)($product['stock_qty'] ?? 0), (int)($product['count_qty'] ?? 0));
}

function supplier_content_progress_picture_count(array $product): int
{
    $pictures = supplier_content_progress_json($product['pictures_json'] ?? null);
    $count = 0;
    foreach ($pictures as $picture) {
        if (trim((string)$picture) !== '') {
            $count++;
        }
    }
    return $count;
}

function supplier_content_progress_param_count(array $product): int
{
    $params = supplier_content_progress_json($product['params_json'] ?? null);
    $count = 0;
    $walk = static function ($value) use (&$walk, &$count): void {
        if (is_array($value)) {
            if (array_key_exists('name', $value) && array_key_exists('value', $value)) {
                if (trim((string)$value['name']) !== '' && trim((string)$value['value']) !== '') {
                    $count++;
                }
                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        }
    };
    $walk($params);
    return $count;
}

function supplier_content_progress_load_products(int $supplierId): array
{
    $st = db()->prepare("
        SELECT
            p.*,
            COALESCE(f.fields_count, 0) AS _fields_count,
            COALESCE(f.dimension_fields_count, 0) AS _dimension_fields_count
        FROM feedtools_supplier_products p
        LEFT JOIN (
            SELECT
                product_id,
                COUNT(*) AS fields_count,
                SUM(CASE WHEN field_kind = 'standard'
                    AND field_name IN ('weight', 'length', 'width', 'height')
                    AND TRIM(COALESCE(field_value, '')) <> '' THEN 1 ELSE 0 END) AS dimension_fields_count
            FROM feedtools_supplier_product_fields
            WHERE supplier_id = ?
              AND TRIM(COALESCE(field_value, '')) <> ''
            GROUP BY product_id
        ) f ON f.product_id = p.id
        WHERE p.supplier_id = ?
        ORDER BY p.sort_order ASC, p.id ASC
    ");
    $st->execute([$supplierId, $supplierId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function supplier_content_progress_normalize_marketplace_status(string $marketplace, ?array $row, array $product): array
{
    if ($row === null) {
        return [
            'normalized_status' => 'not_uploaded',
            'upload_status' => 'not_uploaded',
            'is_uploaded' => 0,
            'is_ready' => 0,
            'is_sellable' => 0,
            'title' => '',
        ];
    }

    $status = trim((string)($row['marketplace_status'] ?? ''));
    $isArchived = $marketplace === 'wb'
        ? ((int)($row['is_trash'] ?? 0) === 1)
        : ((int)($row['is_archived'] ?? 0) === 1);
    $isActive = (int)($row['is_active'] ?? 0) === 1;

    if ($status === '' && $isArchived) {
        $status = 'archived';
    } elseif ($status === '' && $isActive) {
        $status = 'ready';
    } elseif ($status === '') {
        $status = 'uploaded';
    }

    if ($status === 'not_created' || $status === 'not_uploaded') {
        $normalized = 'not_uploaded';
    } elseif ($status === 'archived' || $isArchived) {
        $normalized = 'archived';
    } elseif ($status === 'error') {
        $normalized = 'error';
    } elseif ($status === 'revision') {
        $normalized = 'revision';
    } elseif ($status === 'ready') {
        $normalized = (supplier_content_progress_price($product) > 0 && supplier_content_progress_stock($product) > 0)
            ? 'sellable'
            : 'ready_not_sellable';
    } else {
        $normalized = 'uploaded_not_ready';
    }

    $isUploaded = $normalized !== 'not_uploaded' ? 1 : 0;
    $isReady = in_array($normalized, ['ready_not_sellable', 'sellable'], true) ? 1 : 0;
    $isSellable = $normalized === 'sellable' ? 1 : 0;

    return [
        'normalized_status' => $normalized,
        'upload_status' => $isUploaded ? 'uploaded' : 'not_uploaded',
        'is_uploaded' => $isUploaded,
        'is_ready' => $isReady,
        'is_sellable' => $isSellable,
        'title' => $marketplace === 'wb'
            ? (string)($row['status_text'] ?? '')
            : trim((string)($row['status_name'] ?? '') . ' ' . (string)($row['status_description'] ?? '')),
    ];
}

function supplier_content_progress_stage_score(string $normalizedStatus): float
{
    return [
        'not_uploaded' => 0.0,
        'archived' => 15.0,
        'error' => 25.0,
        'revision' => 40.0,
        'uploaded_not_ready' => 55.0,
        'ready_not_sellable' => 75.0,
        'sellable' => 100.0,
    ][$normalizedStatus] ?? 55.0;
}

function supplier_content_progress_marketplace_signal_score(string $marketplace, ?array $row): ?float
{
    if ($row === null) {
        return null;
    }
    if ($marketplace === 'ozon') {
        $value = $row['content_rating'] ?? null;
        if (function_exists('ozon_products_content_rating_number')) {
            $rating = ozon_products_content_rating_number($value);
            if ($rating !== null) {
                return supplier_content_progress_clamp((float)$rating);
            }
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        return is_numeric($value) ? supplier_content_progress_clamp((float)$value) : null;
    }

    $value = $row['quality_score'] ?? null;
    if (function_exists('wb_products_quality_number')) {
        $score = wb_products_quality_number($value);
        if ($score !== null) {
            return supplier_content_progress_clamp($score * 10.0);
        }
    }
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    return is_numeric($value) ? supplier_content_progress_clamp((float)$value * 10.0) : null;
}

function supplier_content_progress_calculate_card_quality(array $product, string $marketplace, ?array $row, array $state): array
{
    $name = trim((string)($product['name'] ?? ''));
    $brand = trim((string)($product['brand'] ?? ''));
    $description = supplier_content_progress_plain_text((string)($product['description_html'] ?? ''));
    $pictures = supplier_content_progress_picture_count($product);
    $paramsCount = supplier_content_progress_param_count($product);
    $fieldsCount = (int)($product['_fields_count'] ?? 0);
    $dimensionFields = (int)($product['_dimension_fields_count'] ?? 0);
    $category = trim((string)($product[$marketplace === 'wb' ? 'wb_category' : 'ozon_category'] ?? ''));

    $breakdown = [
        'identity' => 0.0,
        'category' => 0.0,
        'description' => 0.0,
        'images' => 0.0,
        'attributes' => 0.0,
        'marketplace_signal' => null,
        'caps' => [],
        'penalties' => [],
    ];

    if ($name !== '') {
        $breakdown['identity'] += 8.0;
    }
    if ($brand !== '') {
        $breakdown['identity'] += 7.0;
    }
    if ($category !== '') {
        $breakdown['category'] = 20.0;
    }
    if ($description !== '') {
        $breakdown['description'] += 8.0;
    }
    if (supplier_content_progress_text_len($description) >= 120) {
        $breakdown['description'] += 7.0;
    }
    if ($pictures >= 1) {
        $breakdown['images'] += 10.0;
    }
    if ($pictures >= 3) {
        $breakdown['images'] += 6.0;
    }
    if ($pictures >= 5) {
        $breakdown['images'] += 4.0;
    }
    if ($paramsCount > 0 || $fieldsCount > 0) {
        $breakdown['attributes'] += 8.0;
    }
    if ($dimensionFields >= 2) {
        $breakdown['attributes'] += 6.0;
    }
    if ($paramsCount >= 5 || $fieldsCount >= 8) {
        $breakdown['attributes'] += 6.0;
    }

    $signal = supplier_content_progress_marketplace_signal_score($marketplace, $row);
    $baseWithoutSignal = (float)$breakdown['identity']
        + (float)$breakdown['category']
        + (float)$breakdown['description']
        + (float)$breakdown['images']
        + (float)$breakdown['attributes'];

    if ($signal !== null) {
        $breakdown['marketplace_signal'] = $signal;
        $score = $baseWithoutSignal + ($signal * 0.10);
    } else {
        $score = $baseWithoutSignal > 0 ? (($baseWithoutSignal / 90.0) * 100.0) : 0.0;
    }

    if ($name === '') {
        $score = min($score, 50.0);
        $breakdown['caps'][] = 'missing_title';
    }
    if ($category === '') {
        $score = min($score, 70.0);
        $breakdown['caps'][] = 'missing_category';
    }
    if ($pictures <= 0) {
        $score = min($score, 65.0);
        $breakdown['caps'][] = 'missing_images';
    }
    if ($brand === '') {
        $score -= 8.0;
        $breakdown['penalties'][] = 'missing_brand';
    }
    if ($description === '') {
        $score -= 10.0;
        $breakdown['penalties'][] = 'missing_description';
    }
    if (($state['normalized_status'] ?? '') === 'error') {
        $score -= 15.0;
        $breakdown['penalties'][] = 'marketplace_error';
    } elseif (($state['normalized_status'] ?? '') === 'revision') {
        $score -= 8.0;
        $breakdown['penalties'][] = 'marketplace_revision';
    } elseif (($state['normalized_status'] ?? '') === 'archived') {
        $score -= 20.0;
        $breakdown['penalties'][] = 'marketplace_archived';
    }

    return [
        'score' => supplier_content_progress_round($score),
        'breakdown' => $breakdown,
    ];
}

function supplier_content_progress_issue(string $code, string $label, string $severity, string $fixability, string $marketplace): array
{
    $weight = ['critical' => 8.0, 'major' => 4.0, 'minor' => 1.5][$severity] ?? 1.5;
    return [
        'code' => $code,
        'label' => $label,
        'severity' => $severity,
        'fixability' => $fixability,
        'marketplace' => $marketplace,
        'weight' => $weight,
    ];
}

function supplier_content_progress_detect_issues(array $product, string $marketplace, array $state): array
{
    $issues = [];
    $name = trim((string)($product['name'] ?? ''));
    $brand = trim((string)($product['brand'] ?? ''));
    $description = supplier_content_progress_plain_text((string)($product['description_html'] ?? ''));
    $pictures = supplier_content_progress_picture_count($product);
    $category = trim((string)($product[$marketplace === 'wb' ? 'wb_category' : 'ozon_category'] ?? ''));
    $prefix = $marketplace === 'wb' ? 'wb' : 'ozon';

    if (($state['normalized_status'] ?? '') === 'not_uploaded') {
        $issues[] = supplier_content_progress_issue('not_uploaded_' . $prefix, ($marketplace === 'wb' ? 'WB' : 'Ozon') . ': товар не загружен', 'major', 'manual', $marketplace);
    }
    if ($category === '') {
        $issues[] = supplier_content_progress_issue('missing_' . $prefix . '_category', 'Нет категории ' . ($marketplace === 'wb' ? 'WB' : 'Ozon'), 'major', 'semi_auto', $marketplace);
    }
    if ($brand === '') {
        $issues[] = supplier_content_progress_issue('missing_brand', 'Нет бренда', 'major', 'semi_auto', 'all');
    }
    if ($name === '') {
        $issues[] = supplier_content_progress_issue('missing_title', 'Нет названия', 'critical', 'semi_auto', 'all');
    } elseif (supplier_content_progress_text_len($name) < 12) {
        $issues[] = supplier_content_progress_issue('weak_title', 'Короткое название', 'minor', 'semi_auto', 'all');
    }
    if ($description === '') {
        $issues[] = supplier_content_progress_issue('missing_description', 'Нет описания', 'major', 'semi_auto', 'all');
    } elseif (supplier_content_progress_text_len($description) < 80) {
        $issues[] = supplier_content_progress_issue('weak_description', 'Слабое описание', 'minor', 'semi_auto', 'all');
    }
    if ($pictures <= 0) {
        $issues[] = supplier_content_progress_issue('missing_images', 'Нет фото', 'critical', 'manual', 'all');
    } elseif ($pictures < 3) {
        $issues[] = supplier_content_progress_issue('low_images_count', 'Мало фото', 'minor', 'manual', 'all');
    }
    if (supplier_content_progress_price($product) <= 0) {
        $issues[] = supplier_content_progress_issue('missing_price', 'Нет цены', 'major', 'external', 'all');
    }
    if (supplier_content_progress_stock($product) <= 0) {
        $issues[] = supplier_content_progress_issue('missing_stock', 'Нет остатка', 'major', 'external', 'all');
    }

    $normalized = (string)($state['normalized_status'] ?? '');
    if ($normalized === 'error') {
        $issues[] = supplier_content_progress_issue($prefix . '_error', ($marketplace === 'wb' ? 'WB' : 'Ozon') . ': ошибка карточки', 'critical', 'manual', $marketplace);
    } elseif ($normalized === 'revision') {
        $issues[] = supplier_content_progress_issue($prefix . '_revision', ($marketplace === 'wb' ? 'WB' : 'Ozon') . ': доработка', 'major', 'manual', $marketplace);
    } elseif ($normalized === 'archived') {
        $issues[] = supplier_content_progress_issue($prefix . '_archived', ($marketplace === 'wb' ? 'WB' : 'Ozon') . ': архив', 'major', 'manual', $marketplace);
    }

    return $issues;
}

function supplier_content_progress_assess_product(array $product, string $marketplace, ?array $marketplaceRow, array $connections): array
{
    $state = supplier_content_progress_normalize_marketplace_status($marketplace, $marketplaceRow, $product);
    $stageScore = supplier_content_progress_stage_score((string)$state['normalized_status']);
    $quality = supplier_content_progress_calculate_card_quality($product, $marketplace, $marketplaceRow, $state);
    $issues = supplier_content_progress_detect_issues($product, $marketplace, $state);
    $issuePenalty = 0.0;
    foreach ($issues as $issue) {
        $issuePenalty += (float)($issue['weight'] ?? 0.0);
    }
    $connectionId = $marketplace === 'wb'
        ? (int)($connections['wb_connection_id'] ?? 0)
        : (int)($connections['ozon_connection_id'] ?? 0);

    return [
        'supplier_id' => (int)$product['supplier_id'],
        'product_id' => (int)$product['id'],
        'offer_id' => (string)($product['offer_id'] ?? ''),
        'vendor_code' => (string)($product['vendor_code'] ?? ''),
        'marketplace' => $marketplace,
        'connection_id' => $connectionId,
        'upload_status' => (string)$state['upload_status'],
        'normalized_status' => (string)$state['normalized_status'],
        'is_uploaded' => (int)$state['is_uploaded'],
        'is_ready' => (int)$state['is_ready'],
        'is_sellable' => (int)$state['is_sellable'],
        'marketplace_stage_score' => $stageScore,
        'card_quality_score' => (float)$quality['score'],
        'issue_penalty' => $issuePenalty,
        'issues' => $issues,
        'quality_breakdown' => $quality['breakdown'],
        'metrics' => [
            'price' => supplier_content_progress_price($product),
            'stock' => supplier_content_progress_stock($product),
            'pictures_count' => supplier_content_progress_picture_count($product),
            'params_count' => supplier_content_progress_param_count($product),
            'fields_count' => (int)($product['_fields_count'] ?? 0),
            'status_title' => (string)($state['title'] ?? ''),
        ],
    ];
}

function supplier_content_progress_upsert_assessments(int $supplierId, array $assessments): void
{
    db()->prepare("DELETE FROM feedtools_supplier_content_assessments WHERE supplier_id = ?")->execute([$supplierId]);
    if (!$assessments) {
        return;
    }
    $st = db()->prepare("
        INSERT INTO feedtools_supplier_content_assessments (
            supplier_id, product_id, offer_id, vendor_code, marketplace, connection_id,
            upload_status, normalized_status, is_uploaded, is_ready, is_sellable,
            marketplace_stage_score, card_quality_score, issue_penalty,
            issues_json, quality_breakdown_json, metrics_json, assessed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    foreach ($assessments as $row) {
        $st->execute([
            (int)$row['supplier_id'],
            (int)$row['product_id'],
            (string)$row['offer_id'],
            (string)$row['vendor_code'],
            (string)$row['marketplace'],
            (int)$row['connection_id'],
            (string)$row['upload_status'],
            (string)$row['normalized_status'],
            (int)$row['is_uploaded'],
            (int)$row['is_ready'],
            (int)$row['is_sellable'],
            (float)$row['marketplace_stage_score'],
            (float)$row['card_quality_score'],
            (float)$row['issue_penalty'],
            json_encode($row['issues'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($row['quality_breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($row['metrics'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function supplier_content_progress_calculate_summary(int $supplierId, array $products, array $assessments, array $connections, array $cfg = []): array
{
    $productsTotal = count($products);
    $targetProductIds = [];
    $outOfStockProductIds = [];
    foreach ($products as $product) {
        $productId = (int)($product['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        if (supplier_content_progress_stock($product) > 0) {
            $targetProductIds[$productId] = true;
        } else {
            $outOfStockProductIds[$productId] = true;
        }
    }
    $targetProductsTotal = count($targetProductIds);
    $outOfStockTotal = count($outOfStockProductIds);
    $targetMarketplaces = ['ozon', 'wb'];
    $marketplaceTargetTotal = $targetProductsTotal * count($targetMarketplaces);

    $byProduct = [];
    $byMarketplace = [
        'ozon' => ['uploaded' => 0, 'ready' => 0, 'sellable' => 0, 'error' => 0, 'revision' => 0, 'archived' => 0, 'stage_sum' => 0.0, 'quality_sum' => 0.0, 'count' => 0],
        'wb' => ['uploaded' => 0, 'ready' => 0, 'sellable' => 0, 'error' => 0, 'revision' => 0, 'archived' => 0, 'stage_sum' => 0.0, 'quality_sum' => 0.0, 'count' => 0],
    ];
    $issueBreakdown = [];
    $severityCounts = ['critical' => 0, 'major' => 0, 'minor' => 0];
    $fixableIssuesTotal = 0;
    $issueWeightTotal = 0.0;
    $qualitySum = 0.0;
    $stageSum = 0.0;

    foreach ($assessments as $a) {
        $pid = (int)$a['product_id'];
        $mp = (string)$a['marketplace'];
        if (!isset($targetProductIds[$pid])) {
            continue;
        }
        if (!isset($byProduct[$pid])) {
            $byProduct[$pid] = [
                'uploaded_any' => false,
                'uploaded_all' => true,
                'sellable_any' => false,
                'sellable_all' => true,
                'ready_any' => false,
                'has_error' => false,
            ];
        }
        $isUploaded = !empty($a['is_uploaded']);
        $isReady = !empty($a['is_ready']);
        $isSellable = !empty($a['is_sellable']);
        $status = (string)($a['normalized_status'] ?? '');

        $byProduct[$pid]['uploaded_any'] = $byProduct[$pid]['uploaded_any'] || $isUploaded;
        $byProduct[$pid]['uploaded_all'] = $byProduct[$pid]['uploaded_all'] && $isUploaded;
        $byProduct[$pid]['sellable_any'] = $byProduct[$pid]['sellable_any'] || $isSellable;
        $byProduct[$pid]['sellable_all'] = $byProduct[$pid]['sellable_all'] && $isSellable;
        $byProduct[$pid]['ready_any'] = $byProduct[$pid]['ready_any'] || $isReady;
        $byProduct[$pid]['has_error'] = $byProduct[$pid]['has_error'] || in_array($status, ['error', 'revision'], true);

        if (isset($byMarketplace[$mp])) {
            $byMarketplace[$mp]['count']++;
            $byMarketplace[$mp]['uploaded'] += $isUploaded ? 1 : 0;
            $byMarketplace[$mp]['ready'] += $isReady ? 1 : 0;
            $byMarketplace[$mp]['sellable'] += $isSellable ? 1 : 0;
            $byMarketplace[$mp]['error'] += $status === 'error' ? 1 : 0;
            $byMarketplace[$mp]['revision'] += $status === 'revision' ? 1 : 0;
            $byMarketplace[$mp]['archived'] += $status === 'archived' ? 1 : 0;
            $byMarketplace[$mp]['stage_sum'] += (float)$a['marketplace_stage_score'];
            $byMarketplace[$mp]['quality_sum'] += (float)$a['card_quality_score'];
        }

        $qualitySum += (float)$a['card_quality_score'];
        $stageSum += (float)$a['marketplace_stage_score'];
        foreach ((array)($a['issues'] ?? []) as $issue) {
            $code = (string)($issue['code'] ?? 'unknown');
            if (!isset($issueBreakdown[$code])) {
                $issueBreakdown[$code] = [
                    'code' => $code,
                    'label' => (string)($issue['label'] ?? $code),
                    'severity' => (string)($issue['severity'] ?? 'minor'),
                    'fixability' => (string)($issue['fixability'] ?? 'manual'),
                    'marketplace' => (string)($issue['marketplace'] ?? 'all'),
                    'count' => 0,
                ];
            }
            $issueBreakdown[$code]['count']++;
            $severity = (string)($issue['severity'] ?? 'minor');
            if (isset($severityCounts[$severity])) {
                $severityCounts[$severity]++;
            }
            if (in_array((string)($issue['fixability'] ?? ''), ['auto', 'semi_auto', 'manual'], true)) {
                $fixableIssuesTotal++;
            }
            $issueWeightTotal += (float)($issue['weight'] ?? 1.5);
        }
    }

    $uploadedAny = 0;
    $uploadedAll = 0;
    $sellableAny = 0;
    $sellableAll = 0;
    $readyAny = 0;
    $productsWithErrors = 0;
    foreach ($products as $product) {
        if (!isset($targetProductIds[(int)$product['id']])) {
            continue;
        }
        $state = $byProduct[(int)$product['id']] ?? [
            'uploaded_any' => false,
            'uploaded_all' => false,
            'sellable_any' => false,
            'sellable_all' => false,
            'ready_any' => false,
            'has_error' => false,
        ];
        $uploadedAny += !empty($state['uploaded_any']) ? 1 : 0;
        $uploadedAll += !empty($state['uploaded_all']) ? 1 : 0;
        $sellableAny += !empty($state['sellable_any']) ? 1 : 0;
        $sellableAll += !empty($state['sellable_all']) ? 1 : 0;
        $readyAny += !empty($state['ready_any']) ? 1 : 0;
        $productsWithErrors += !empty($state['has_error']) ? 1 : 0;
    }

    foreach ($byMarketplace as &$row) {
        $count = max(1, (int)$row['count']);
        $row['completion_score'] = supplier_content_progress_round((float)$row['stage_sum'] / $count);
        $row['avg_quality_score'] = supplier_content_progress_round((float)$row['quality_sum'] / $count);
        $row['coverage_percent'] = $targetProductsTotal > 0 ? supplier_content_progress_round(((int)$row['uploaded'] / $targetProductsTotal) * 100.0) : 0.0;
        $row['sellable_percent'] = $targetProductsTotal > 0 ? supplier_content_progress_round(((int)$row['sellable'] / $targetProductsTotal) * 100.0) : 0.0;
    }
    unset($row);

    $uploadedAnyPercent = $targetProductsTotal > 0 ? ($uploadedAny / $targetProductsTotal) * 100.0 : 0.0;
    $uploadedAllPercent = $targetProductsTotal > 0 ? ($uploadedAll / $targetProductsTotal) * 100.0 : 0.0;
    $sellableAnyPercent = $targetProductsTotal > 0 ? ($sellableAny / $targetProductsTotal) * 100.0 : 0.0;
    $sellableAllPercent = $targetProductsTotal > 0 ? ($sellableAll / $targetProductsTotal) * 100.0 : 0.0;
    $avgCardQuality = $marketplaceTargetTotal > 0 ? $qualitySum / $marketplaceTargetTotal : 0.0;
    $marketplaceCompletion = $marketplaceTargetTotal > 0 ? $stageSum / $marketplaceTargetTotal : 0.0;
    $uploadScore = ($uploadedAnyPercent * 0.55) + ($uploadedAllPercent * 0.25) + ($marketplaceCompletion * 0.20);
    $sellableScore = ($sellableAnyPercent * 0.70) + ($sellableAllPercent * 0.30);
    $errorPenalty = $marketplaceTargetTotal > 0 ? (($issueWeightTotal / ($marketplaceTargetTotal * 8.0)) * 100.0) : 0.0;
    $errorHealthScore = 100.0 - $errorPenalty;
    $contentScore = ($uploadScore * 0.35) + ($sellableScore * 0.25) + ($avgCardQuality * 0.25) + ($errorHealthScore * 0.15);

    $caps = [];
    $criticalPercent = $marketplaceTargetTotal > 0 ? (($severityCounts['critical'] / $marketplaceTargetTotal) * 100.0) : 0.0;
    if ($productsTotal <= 0) {
        $contentScore = 0.0;
        $caps[] = 'no_products';
    } elseif ($targetProductsTotal <= 0) {
        $contentScore = 0.0;
        $caps[] = 'no_in_stock_products';
    }
    if ($uploadedAnyPercent < 10.0) {
        $contentScore = min($contentScore, 35.0);
        $caps[] = 'low_upload_coverage';
    }
    if ($sellableAnyPercent <= 0.0) {
        $contentScore = min($contentScore, 70.0);
        $caps[] = 'no_sellable_products';
    }
    if ($criticalPercent > 30.0) {
        $contentScore = min($contentScore, 65.0);
        $caps[] = 'many_critical_issues';
    }

    $dataConfidence = supplier_content_progress_calculate_data_confidence($supplierId, [
        'products_total' => $productsTotal,
        'uploaded_any_percent' => $uploadedAnyPercent,
    ], $connections, $cfg);

    uasort($issueBreakdown, static function (array $a, array $b): int {
        $countCmp = ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
        if ($countCmp !== 0) return $countCmp;
        return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    $mainReasons = supplier_content_progress_main_reasons([
        'products_total' => $productsTotal,
        'target_products_total' => $targetProductsTotal,
        'out_of_stock_total' => $outOfStockTotal,
        'uploaded_any' => $uploadedAny,
        'uploaded_all' => $uploadedAll,
        'sellable_any' => $sellableAny,
        'by_marketplace' => $byMarketplace,
        'issue_breakdown' => array_values($issueBreakdown),
    ]);

    return [
        'model_version' => 'content_stock_target_v3',
        'products_total' => $productsTotal,
        'target_products_total' => $targetProductsTotal,
        'out_of_stock_total' => $outOfStockTotal,
        'marketplace_target_total' => $marketplaceTargetTotal,
        'uploaded_any_total' => $uploadedAny,
        'uploaded_all_total' => $uploadedAll,
        'not_uploaded_total' => max(0, $targetProductsTotal - $uploadedAny),
        'target_not_uploaded_total' => max(0, $targetProductsTotal - $uploadedAny),
        'ready_any_total' => $readyAny,
        'sellable_any_total' => $sellableAny,
        'sellable_all_total' => $sellableAll,
        'products_with_errors_total' => $productsWithErrors,
        'critical_issues_total' => $severityCounts['critical'],
        'major_issues_total' => $severityCounts['major'],
        'minor_issues_total' => $severityCounts['minor'],
        'fixable_issues_total' => $fixableIssuesTotal,
        'uploaded_any_percent' => supplier_content_progress_round($uploadedAnyPercent),
        'uploaded_all_percent' => supplier_content_progress_round($uploadedAllPercent),
        'sellable_any_percent' => supplier_content_progress_round($sellableAnyPercent),
        'sellable_all_percent' => supplier_content_progress_round($sellableAllPercent),
        'avg_card_quality_score' => supplier_content_progress_round($avgCardQuality),
        'marketplace_completion_score' => supplier_content_progress_round($marketplaceCompletion),
        'upload_score' => supplier_content_progress_round($uploadScore),
        'sellable_score' => supplier_content_progress_round($sellableScore),
        'error_health_score' => supplier_content_progress_round($errorHealthScore),
        'content_progress_score' => supplier_content_progress_round($contentScore),
        'caps_applied' => $caps,
        'main_reasons' => $mainReasons,
        'by_marketplace' => $byMarketplace,
        'issue_breakdown' => array_values($issueBreakdown),
        'data_confidence' => $dataConfidence,
    ];
}

function supplier_content_progress_main_reasons(array $metrics): array
{
    $reasons = [];
    $productsTotal = max(0, (int)($metrics['products_total'] ?? 0));
    $targetProductsTotal = max(0, (int)($metrics['target_products_total'] ?? $productsTotal));
    $outOfStockTotal = max(0, (int)($metrics['out_of_stock_total'] ?? 0));
    if ($productsTotal <= 0) {
        return ['Нет товаров поставщика в FeedTools'];
    }
    if ($targetProductsTotal <= 0) {
        return ['Нет товаров с остатком для текущей выгрузки'];
    }
    foreach ((array)($metrics['by_marketplace'] ?? []) as $marketplace => $row) {
        $notUploaded = max(0, $targetProductsTotal - (int)($row['uploaded'] ?? 0));
        if ($notUploaded > 0) {
            $reasons[] = $notUploaded . ' товаров не загружено на ' . ($marketplace === 'wb' ? 'WB' : 'Ozon');
        }
        if ((int)($row['error'] ?? 0) > 0) {
            $reasons[] = (int)$row['error'] . ' товаров с ошибками ' . ($marketplace === 'wb' ? 'WB' : 'Ozon');
        }
        if ((int)($row['revision'] ?? 0) > 0) {
            $reasons[] = (int)$row['revision'] . ' товаров на доработке ' . ($marketplace === 'wb' ? 'WB' : 'Ozon');
        }
    }
    foreach (array_slice((array)($metrics['issue_breakdown'] ?? []), 0, 4) as $issue) {
        $label = trim((string)($issue['label'] ?? ''));
        $count = (int)($issue['count'] ?? 0);
        if ($label !== '' && $count > 0) {
            $reasons[] = $label . ': ' . $count;
        }
    }
    if ($outOfStockTotal > 0) {
        $reasons[] = $outOfStockTotal . ' товаров без остатка не входят в цель выгрузки';
    }
    return array_values(array_slice(array_unique($reasons), 0, 6));
}

function supplier_content_progress_count_label($value): string
{
    return number_format((int)$value, 0, '.', ' ');
}

function supplier_content_progress_percent(float $value, float $total): float
{
    if ($total <= 0.0) {
        return 0.0;
    }
    return supplier_content_progress_round(($value / $total) * 100.0);
}

function supplier_content_progress_percent_label(float $value): string
{
    if ($value > 0.0 && $value < 1.0) {
        return '<1%';
    }
    $decimals = $value > 0.0 && $value < 10.0 ? 1 : 0;
    return number_format($value, $decimals, '.', ' ') . '%';
}

function supplier_content_progress_issue_count(array $metrics, array $codes): int
{
    $wanted = array_fill_keys($codes, true);
    $total = 0;
    foreach ((array)($metrics['issue_breakdown'] ?? []) as $issue) {
        $code = (string)($issue['code'] ?? '');
        if (isset($wanted[$code])) {
            $total += (int)($issue['count'] ?? 0);
        }
    }
    return $total;
}

function supplier_content_progress_management_priority(array $snapshot, array $metrics, array $delta = []): array
{
    $targetProducts = max(0, (int)($metrics['target_products_total'] ?? 0));
    $productsTotal = max(0, (int)($metrics['products_total'] ?? ($snapshot['products_total'] ?? 0)));
    if ($productsTotal <= 0) {
        return [
            'score' => 0.0,
            'label' => 'Нет данных',
            'class' => 'gray',
            'focus' => 'Импорт',
            'focus_key' => 'data',
            'reasons' => ['Нет товаров поставщика в FeedTools'],
            'parts' => [],
        ];
    }
    if ($targetProducts <= 0) {
        return [
            'score' => 0.0,
            'label' => 'Выгрузка не нужна',
            'class' => 'gray',
            'focus' => 'Нет остатка',
            'focus_key' => 'stock',
            'reasons' => ['Нет товаров с остатком для текущей выгрузки'],
            'parts' => [],
        ];
    }

    $marketplaceTarget = max(1, (int)($metrics['marketplace_target_total'] ?? ($targetProducts * 2)));
    $progress = (float)($snapshot['content_progress_score'] ?? ($metrics['content_progress_score'] ?? 0.0));
    $quality = (float)($snapshot['avg_card_quality_score'] ?? ($metrics['avg_card_quality_score'] ?? 0.0));
    $notUploaded = max(0, (int)($snapshot['not_uploaded_total'] ?? ($metrics['not_uploaded_total'] ?? 0)));
    $uploadedAny = max(0, (int)($metrics['uploaded_any_total'] ?? max(0, $targetProducts - $notUploaded)));
    $sellableAny = max(0, (int)($snapshot['sellable_total'] ?? ($metrics['sellable_any_total'] ?? 0)));
    $productsWithErrors = max(0, (int)($metrics['products_with_errors_total'] ?? 0));
    $criticalIssues = max(0, (int)($metrics['critical_issues_total'] ?? 0));

    $coreIssueCount = supplier_content_progress_issue_count($metrics, [
        'missing_title',
        'missing_brand',
        'missing_description',
        'missing_images',
        'missing_ozon_category',
        'missing_wb_category',
        'missing_price',
    ]);
    $qualityIssueCount = supplier_content_progress_issue_count($metrics, [
        'weak_title',
        'weak_description',
        'low_images_count',
    ]);

    $notUploadedPercent = supplier_content_progress_percent((float)$notUploaded, (float)$targetProducts);
    $uploadedAnyPercent = (float)($metrics['uploaded_any_percent'] ?? supplier_content_progress_percent((float)$uploadedAny, (float)$targetProducts));
    $sellablePercent = (float)($metrics['sellable_any_percent'] ?? supplier_content_progress_percent((float)$sellableAny, (float)$targetProducts));
    $errorProductPercent = supplier_content_progress_percent((float)$productsWithErrors, (float)$targetProducts);
    $criticalPercent = supplier_content_progress_percent((float)$criticalIssues, (float)$marketplaceTarget);
    $coreIssuePercent = supplier_content_progress_percent((float)$coreIssueCount, (float)$marketplaceTarget);
    $qualityIssuePercent = supplier_content_progress_percent((float)$qualityIssueCount, (float)$marketplaceTarget);
    $sellableGapPercent = max(0.0, $uploadedAnyPercent - $sellablePercent);

    $focusScores = [
        'upload' => $notUploadedPercent * 1.15,
        'errors' => ($errorProductPercent * 1.05) + ($criticalPercent * 1.8),
        'core' => $coreIssuePercent * 1.25,
        'sellable' => ($sellableGapPercent * 0.9) + ($uploadedAnyPercent > 20.0 && $sellablePercent < 10.0 ? 20.0 : 0.0),
        'quality' => max(0.0, 76.0 - $quality) * 1.2 + ($qualityIssuePercent * 0.8),
    ];
    arsort($focusScores);
    $focusKey = (string)array_key_first($focusScores);
    if ((float)reset($focusScores) < 8.0 && $progress >= 82.0) {
        $focusKey = 'support';
    }
    $focusLabels = [
        'upload' => 'Загрузка',
        'errors' => 'Ошибки и доработка',
        'core' => 'Базовые данные',
        'sellable' => 'Продаваемость',
        'quality' => 'Качество карточек',
        'support' => 'Поддержка',
    ];

    $pressure = 0.0;
    $pressure += max(0.0, 100.0 - $progress) * 0.42;
    $pressure += $notUploadedPercent * 0.22;
    $pressure += $errorProductPercent * 0.15;
    $pressure += $coreIssuePercent * 0.10;
    $pressure += max(0.0, 78.0 - $quality) * 0.12;
    $pressure += $sellableGapPercent * 0.10;
    if ($uploadedAny <= 0) {
        $pressure += 12.0;
    } elseif ($sellableAny <= 0) {
        $pressure += 8.0;
    }
    if (!empty($delta['available']) && (float)($delta['content_progress_score'] ?? 0.0) < -1.0) {
        $pressure += min(8.0, abs((float)$delta['content_progress_score']) * 1.5);
    }

    $unresolvedWork = max($notUploaded, $productsWithErrors, (int)ceil($coreIssueCount / 2));
    $volumeFactor = 1.0;
    if ($targetProducts <= 5) {
        $volumeFactor = 0.75;
    } elseif ($targetProducts <= 20) {
        $volumeFactor = 0.85;
    } elseif ($targetProducts <= 100) {
        $volumeFactor = 0.95;
    }
    $pressure = ($pressure * $volumeFactor) + min(8.0, log10(max(1, $unresolvedWork) + 1) * 2.6);
    $priorityScore = supplier_content_progress_round($pressure);

    if ($priorityScore >= 65.0) {
        $label = 'Срочно';
        $class = 'red';
    } elseif ($priorityScore >= 48.0) {
        $label = 'Высокий';
        $class = 'yellow';
    } elseif ($priorityScore >= 28.0) {
        $label = 'Средний';
        $class = 'blue';
    } else {
        $label = 'Поддерживать';
        $class = 'green';
    }

    $reasons = [];
    if ($notUploaded > 0) {
        $reasons[] = 'не загружено ' . supplier_content_progress_count_label($notUploaded) . ' / ' . supplier_content_progress_percent_label($notUploadedPercent);
    }
    if ($productsWithErrors > 0) {
        $reasons[] = 'ошибки у ' . supplier_content_progress_count_label($productsWithErrors) . ' / ' . supplier_content_progress_percent_label($errorProductPercent);
    }
    if ($coreIssueCount > 0) {
        $reasons[] = 'базовые пробелы: ' . supplier_content_progress_count_label($coreIssueCount);
    }
    if ($sellableGapPercent >= 15.0) {
        $reasons[] = 'продается ' . supplier_content_progress_percent_label($sellablePercent) . ' из цели';
    }
    if ($quality < 70.0) {
        $reasons[] = 'качество ' . number_format($quality, 1, '.', ' ');
    }
    if (!empty($delta['available']) && (float)($delta['content_progress_score'] ?? 0.0) < -0.01) {
        $reasons[] = 'динамика ' . number_format((float)$delta['content_progress_score'], 1, '.', ' ') . ' п.';
    }
    if (!$reasons) {
        $reasons[] = $priorityScore < 32.0 ? 'критичных просадок нет' : 'нужна проверка состояния';
    }

    return [
        'score' => $priorityScore,
        'label' => $label,
        'class' => $class,
        'focus' => (string)($focusLabels[$focusKey] ?? 'Контент'),
        'focus_key' => $focusKey,
        'reasons' => array_values(array_slice($reasons, 0, 5)),
        'parts' => [
            'progress_gap' => supplier_content_progress_round(max(0.0, 100.0 - $progress)),
            'not_uploaded_percent' => $notUploadedPercent,
            'error_product_percent' => $errorProductPercent,
            'core_issue_percent' => $coreIssuePercent,
            'sellable_gap_percent' => supplier_content_progress_round($sellableGapPercent),
            'quality_gap' => supplier_content_progress_round(max(0.0, 78.0 - $quality)),
            'volume_factor' => $volumeFactor,
        ],
    ];
}

function supplier_content_progress_last_seen(string $marketplace, int $connectionId): string
{
    if ($connectionId <= 0) {
        return '';
    }
    try {
        if ($marketplace === 'wb') {
            $st = db()->prepare("SELECT MAX(last_seen_at) FROM feedtools_wb_products WHERE connection_id = ?");
        } else {
            $st = db()->prepare("SELECT MAX(last_seen_at) FROM feedtools_ozon_products WHERE connection_id = ?");
        }
        $st->execute([$connectionId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

function supplier_content_progress_age_days(string $datetime): ?int
{
    $datetime = trim($datetime);
    if ($datetime === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($datetime);
        $now = new DateTimeImmutable('now');
        return max(0, (int)$dt->diff($now)->format('%a'));
    } catch (Throwable) {
        return null;
    }
}

function supplier_content_progress_calculate_data_confidence(int $supplierId, array $metrics, array $connections, array $cfg = []): array
{
    $score = 100.0;
    $warnings = [];
    $meta = [];
    try {
        $meta = supplier_products_meta_get($supplierId, $cfg);
    } catch (Throwable) {
        $meta = [];
    }
    $importedAt = (string)($meta['last_imported_at'] ?? '');
    $importAge = supplier_content_progress_age_days($importedAt);
    if ($importAge === null) {
        $score -= 15.0;
        $warnings[] = 'Нет даты последнего импорта поставщика';
    } elseif ($importAge > 7) {
        $score -= 20.0;
        $warnings[] = 'Импорт поставщика старше 7 дней';
    } elseif ($importAge > 2) {
        $score -= 8.0;
        $warnings[] = 'Импорт поставщика старше 2 дней';
    }

    foreach (['ozon' => 'ozon_connection_id', 'wb' => 'wb_connection_id'] as $marketplace => $key) {
        $connectionId = (int)($connections[$key] ?? 0);
        $label = $marketplace === 'wb' ? 'WB' : 'Ozon';
        if ($connectionId <= 0) {
            $score -= 20.0;
            $warnings[] = 'Нет подключения ' . $label;
            continue;
        }
        $lastSeen = supplier_content_progress_last_seen($marketplace, $connectionId);
        $age = supplier_content_progress_age_days($lastSeen);
        if ($age === null) {
            $score -= 15.0;
            $warnings[] = 'Нет синхронизированных статусов ' . $label;
        } elseif ($age > 7) {
            $score -= 25.0;
            $warnings[] = 'Статусы ' . $label . ' старше 7 дней';
        } elseif ($age > 3) {
            $score -= 10.0;
            $warnings[] = 'Статусы ' . $label . ' старше 3 дней';
        }
    }

    $score = supplier_content_progress_clamp($score);
    $level = $score >= 80.0 ? 'high' : ($score >= 50.0 ? 'medium' : 'low');
    return [
        'score' => supplier_content_progress_round($score),
        'level' => $level,
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function supplier_content_progress_capture_snapshot(int $supplierId, array $cfg = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!$supplier) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $products = supplier_content_progress_load_products($supplierId);
    $connections = supplier_content_progress_resolve_connections($supplierId, $cfg);
    $maps = supplier_content_progress_marketplace_maps($products, (string)($supplier['supplier_code'] ?? ''), $connections);

    $assessments = [];
    foreach ($products as $product) {
        foreach (['ozon', 'wb'] as $marketplace) {
            $row = supplier_content_progress_find_marketplace_row($product, (array)($maps[$marketplace] ?? []), (string)($supplier['supplier_code'] ?? ''));
            $assessments[] = supplier_content_progress_assess_product($product, $marketplace, $row, $connections);
        }
    }

    supplier_content_progress_upsert_assessments($supplierId, $assessments);
    $summary = supplier_content_progress_calculate_summary($supplierId, $products, $assessments, $connections, $cfg);

    $metricsJson = json_encode($summary, JSON_UNESCAPED_UNICODE);
    $scoreBreakdown = [
        'content_progress_score' => $summary['content_progress_score'],
        'upload_score' => $summary['upload_score'],
        'sellable_score' => $summary['sellable_score'],
        'avg_card_quality_score' => $summary['avg_card_quality_score'],
        'error_health_score' => $summary['error_health_score'],
        'marketplace_completion_score' => $summary['marketplace_completion_score'],
        'caps_applied' => $summary['caps_applied'],
        'main_reasons' => $summary['main_reasons'],
        'data_confidence' => $summary['data_confidence']['level'] ?? 'medium',
    ];

    $st = db()->prepare("
        INSERT INTO feedtools_supplier_content_snapshots (
            captured_at, capture_date, supplier_id, marketplace, connection_id,
            products_total, uploaded_total, not_uploaded_total, ready_total, sellable_total,
            error_total, revision_total, archived_total, critical_issues_total, fixable_issues_total,
            avg_card_quality_score, upload_score, sellable_score, error_health_score,
            marketplace_completion_score, content_progress_score, data_confidence_level, data_confidence_score,
            metrics_json, issue_breakdown_json, score_breakdown_json, data_warnings_json
        ) VALUES (
            NOW(), CURDATE(), ?, 'all', 0,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?
        )
    ");
    $byMarketplace = (array)($summary['by_marketplace'] ?? []);
    $errorTotal = (int)($byMarketplace['ozon']['error'] ?? 0) + (int)($byMarketplace['wb']['error'] ?? 0);
    $revisionTotal = (int)($byMarketplace['ozon']['revision'] ?? 0) + (int)($byMarketplace['wb']['revision'] ?? 0);
    $archivedTotal = (int)($byMarketplace['ozon']['archived'] ?? 0) + (int)($byMarketplace['wb']['archived'] ?? 0);
    $st->execute([
        $supplierId,
        (int)$summary['products_total'],
        (int)$summary['uploaded_any_total'],
        (int)$summary['not_uploaded_total'],
        (int)$summary['ready_any_total'],
        (int)$summary['sellable_any_total'],
        $errorTotal,
        $revisionTotal,
        $archivedTotal,
        (int)$summary['critical_issues_total'],
        (int)$summary['fixable_issues_total'],
        (float)$summary['avg_card_quality_score'],
        (float)$summary['upload_score'],
        (float)$summary['sellable_score'],
        (float)$summary['error_health_score'],
        (float)$summary['marketplace_completion_score'],
        (float)$summary['content_progress_score'],
        (string)($summary['data_confidence']['level'] ?? 'medium'),
        (float)($summary['data_confidence']['score'] ?? 0.0),
        $metricsJson,
        json_encode($summary['issue_breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($scoreBreakdown, JSON_UNESCAPED_UNICODE),
        json_encode($summary['data_confidence']['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);

    $summary['snapshot_id'] = (int)db()->lastInsertId();
    $summary['supplier'] = $supplier;
    $summary['connections'] = $connections;
    return $summary;
}

function supplier_content_progress_latest_snapshot(int $supplierId): ?array
{
    supplier_content_progress_tables_ensure();
    $st = db()->prepare("
        SELECT *
        FROM feedtools_supplier_content_snapshots
        WHERE supplier_id = ? AND marketplace = 'all'
        ORDER BY captured_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$supplierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function supplier_content_progress_snapshot_today_exists(int $supplierId): bool
{
    supplier_content_progress_tables_ensure();
    $st = db()->prepare("
        SELECT metrics_json
        FROM feedtools_supplier_content_snapshots
        WHERE supplier_id = ? AND marketplace = 'all' AND capture_date = CURDATE()
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$supplierId]);
    $raw = (string)($st->fetchColumn() ?: '');
    if ($raw === '') {
        return false;
    }
    $metrics = supplier_content_progress_json($raw);
    return (string)($metrics['model_version'] ?? '') === 'content_stock_target_v3';
}

function supplier_content_progress_parse_period(array $input): array
{
    $preset = trim((string)($input['preset'] ?? '7d'));
    $today = new DateTimeImmutable('today');
    $make = static function (DateTimeImmutable $from, DateTimeImmutable $to, string $preset): array {
        return [
            'preset' => $preset,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            'from_ts' => $from->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
            'to_ts' => $to->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        ];
    };
    if ($preset === 'today') return $make($today, $today, $preset);
    if ($preset === '30d') return $make($today->modify('-29 days'), $today, $preset);
    if ($preset === '90d') return $make($today->modify('-89 days'), $today, $preset);
    if ($preset === 'custom') {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)($input['from'] ?? ''))) ?: $today->modify('-6 days');
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)($input['to'] ?? ''))) ?: $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return $make($from, $to, $preset);
    }
    return $make($today->modify('-6 days'), $today, '7d');
}

function supplier_content_progress_snapshot_for_compare(int $supplierId, string $datetime, string $direction): ?array
{
    $op = $direction === 'after' ? '>=' : '<=';
    $order = $direction === 'after' ? 'ASC' : 'DESC';
    $st = db()->prepare("
        SELECT *
        FROM feedtools_supplier_content_snapshots
        WHERE supplier_id = ?
          AND marketplace = 'all'
          AND captured_at {$op} ?
        ORDER BY captured_at {$order}, id {$order}
        LIMIT 1
    ");
    $st->execute([$supplierId, $datetime]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function supplier_content_progress_snapshot_delta(int $supplierId, array $period): array
{
    $start = supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['from_ts'], 'after')
        ?: supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['from_ts'], 'before');
    $end = supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['to_ts'], 'before');
    if (!$start || !$end) {
        return ['available' => false];
    }
    $keys = [
        'products_total',
        'uploaded_total',
        'ready_total',
        'sellable_total',
        'error_total',
        'revision_total',
        'avg_card_quality_score',
        'content_progress_score',
    ];
    $delta = ['available' => true, 'from_snapshot_id' => (int)$start['id'], 'to_snapshot_id' => (int)$end['id']];
    foreach ($keys as $key) {
        $delta[$key] = round((float)($end[$key] ?? 0) - (float)($start[$key] ?? 0), 2);
    }
    return $delta;
}

function supplier_content_progress_snapshot_pair(int $supplierId, array $period): array
{
    $start = supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['from_ts'], 'after')
        ?: supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['from_ts'], 'before');
    $end = supplier_content_progress_snapshot_for_compare($supplierId, (string)$period['to_ts'], 'before');
    return [$start, $end];
}

function supplier_content_progress_issue_map(array $issues): array
{
    $map = [];
    foreach ($issues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        if (!isset($map[$code])) {
            $map[$code] = [
                'code' => $code,
                'label' => (string)($issue['label'] ?? $code),
                'severity' => (string)($issue['severity'] ?? 'minor'),
                'start' => 0,
                'end' => 0,
            ];
        }
        $map[$code]['end'] += (int)($issue['count'] ?? 0);
    }
    return $map;
}

function supplier_content_progress_deep_delta(int $supplierId, array $period): array
{
    [$start, $end] = supplier_content_progress_snapshot_pair($supplierId, $period);
    if (!$start || !$end) {
        return ['available' => false];
    }

    $startMetrics = supplier_content_progress_json($start['metrics_json'] ?? null);
    $endMetrics = supplier_content_progress_json($end['metrics_json'] ?? null);
    $startIssues = supplier_content_progress_json($start['issue_breakdown_json'] ?? null);
    $endIssues = supplier_content_progress_json($end['issue_breakdown_json'] ?? null);

    $scoreKeys = [
        'content_progress_score',
        'upload_score',
        'sellable_score',
        'avg_card_quality_score',
        'error_health_score',
        'marketplace_completion_score',
    ];
    $scoreDeltas = [];
    foreach ($scoreKeys as $key) {
        $scoreDeltas[$key] = [
            'start' => round((float)($start[$key] ?? 0), 2),
            'end' => round((float)($end[$key] ?? 0), 2),
            'delta' => round((float)($end[$key] ?? 0) - (float)($start[$key] ?? 0), 2),
        ];
    }

    $summaryKeys = [
        'target_products_total',
        'out_of_stock_total',
        'uploaded_any_total',
        'uploaded_all_total',
        'not_uploaded_total',
        'ready_any_total',
        'sellable_any_total',
        'sellable_all_total',
        'products_with_errors_total',
        'critical_issues_total',
        'fixable_issues_total',
    ];
    $summaryDeltas = [];
    foreach ($summaryKeys as $key) {
        $summaryDeltas[$key] = [
            'start' => round((float)($startMetrics[$key] ?? 0), 2),
            'end' => round((float)($endMetrics[$key] ?? 0), 2),
            'delta' => round((float)($endMetrics[$key] ?? 0) - (float)($startMetrics[$key] ?? 0), 2),
        ];
    }

    $marketplaceDeltas = [];
    foreach (['ozon', 'wb'] as $marketplace) {
        $startRow = (array)($startMetrics['by_marketplace'][$marketplace] ?? []);
        $endRow = (array)($endMetrics['by_marketplace'][$marketplace] ?? []);
        $marketplaceDeltas[$marketplace] = [];
        foreach (['uploaded', 'ready', 'sellable', 'error', 'revision', 'archived', 'completion_score', 'avg_quality_score', 'coverage_percent', 'sellable_percent'] as $key) {
            $marketplaceDeltas[$marketplace][$key] = [
                'start' => round((float)($startRow[$key] ?? 0), 2),
                'end' => round((float)($endRow[$key] ?? 0), 2),
                'delta' => round((float)($endRow[$key] ?? 0) - (float)($startRow[$key] ?? 0), 2),
            ];
        }
    }

    $issueRows = [];
    $combined = [];
    foreach ($startIssues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $combined[$code] = [
            'code' => $code,
            'label' => (string)($issue['label'] ?? $code),
            'severity' => (string)($issue['severity'] ?? 'minor'),
            'start' => (int)($issue['count'] ?? 0),
            'end' => 0,
        ];
    }
    foreach ($endIssues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        if (!isset($combined[$code])) {
            $combined[$code] = [
                'code' => $code,
                'label' => (string)($issue['label'] ?? $code),
                'severity' => (string)($issue['severity'] ?? 'minor'),
                'start' => 0,
                'end' => 0,
            ];
        }
        $combined[$code]['label'] = (string)($issue['label'] ?? $combined[$code]['label']);
        $combined[$code]['severity'] = (string)($issue['severity'] ?? $combined[$code]['severity']);
        $combined[$code]['end'] = (int)($issue['count'] ?? 0);
    }
    foreach ($combined as $row) {
        $row['delta'] = (int)$row['end'] - (int)$row['start'];
        if ($row['delta'] !== 0) {
            $issueRows[] = $row;
        }
    }
    $fixedIssues = array_values(array_filter($issueRows, static fn(array $row): bool => (int)$row['delta'] < 0));
    $newIssues = array_values(array_filter($issueRows, static fn(array $row): bool => (int)$row['delta'] > 0));
    usort($fixedIssues, static fn(array $a, array $b): int => abs((int)$b['delta']) <=> abs((int)$a['delta']));
    usort($newIssues, static fn(array $a, array $b): int => (int)$b['delta'] <=> (int)$a['delta']);

    return [
        'available' => true,
        'from_snapshot_id' => (int)$start['id'],
        'to_snapshot_id' => (int)$end['id'],
        'from_captured_at' => (string)($start['captured_at'] ?? ''),
        'to_captured_at' => (string)($end['captured_at'] ?? ''),
        'score_deltas' => $scoreDeltas,
        'summary_deltas' => $summaryDeltas,
        'marketplace_deltas' => $marketplaceDeltas,
        'fixed_issues' => array_slice($fixedIssues, 0, 12),
        'new_issues' => array_slice($newIssues, 0, 12),
    ];
}

function supplier_content_progress_array_path(array $data, string $path)
{
    $current = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }
    return $current;
}

function supplier_content_progress_first_number(array $data, array $paths, float $fallback = 0.0): float
{
    foreach ($paths as $path) {
        $value = supplier_content_progress_array_path($data, (string)$path);
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (is_numeric($value) && (float)$value > 0.0) {
            return (float)$value;
        }
    }
    return $fallback;
}

function supplier_content_progress_sum_numbers(array $data, array $paths): float
{
    $sum = 0.0;
    foreach ($paths as $path) {
        $value = supplier_content_progress_array_path($data, (string)$path);
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (is_numeric($value)) {
            $sum += max(0.0, (float)$value);
        }
    }
    return $sum;
}

function supplier_content_progress_scaled_points(float $value, float $headWeight, float $tailWeight, float $headLimit = 500.0): float
{
    $value = max(0.0, $value);
    $head = min($value, $headLimit);
    $tail = max(0.0, $value - $headLimit);
    return ($head * $headWeight) + ($tail * $tailWeight);
}

function supplier_content_progress_operation_profile(string $opType): ?array
{
    $map = [
        'supplier_push_ozon_content' => ['kind' => 'marketplace_upload', 'label' => 'Выгрузка Ozon'],
        'supplier_push_wb_content' => ['kind' => 'marketplace_upload', 'label' => 'Выгрузка WB'],
        'supplier_push_marketplace_content' => ['kind' => 'marketplace_upload', 'label' => 'Выгрузка маркетплейса'],
        'supplier_import_catalog' => ['kind' => 'catalog_update', 'label' => 'Обновление каталога'],
        'sanitize_description_links' => ['kind' => 'quality_update', 'label' => 'Очистка описаний'],
        'normalize_param_values' => ['kind' => 'quality_update', 'label' => 'Нормализация параметров'],
        'analyze_category_products' => ['kind' => 'quality_update', 'label' => 'Анализ категорий'],
        'gpt_assign_marketplace_categories' => ['kind' => 'quality_update', 'label' => 'Категории Ozon/WB'],
        'gpt_fill_offer_params' => ['kind' => 'quality_update', 'label' => 'Заполнение параметров'],
        'gpt_generate_description_ru' => ['kind' => 'quality_update', 'label' => 'Описание'],
        'gpt_rewrite_description_ru' => ['kind' => 'quality_update', 'label' => 'Описание'],
        'gpt_rewrite_title_marketplace' => ['kind' => 'quality_update', 'label' => 'Названия для маркетплейса'],
        'gpt_fix_title_ru' => ['kind' => 'quality_update', 'label' => 'Русские названия'],
        'gpt_fill_color_vision' => ['kind' => 'quality_update', 'label' => 'Цвета по фото'],
        'gpt_fill_same_model' => ['kind' => 'quality_update', 'label' => 'Группы одной модели'],
        'gpt_generate_cover_image' => ['kind' => 'quality_update', 'label' => 'Обложки товаров'],
        'gpt_generate_video_cover' => ['kind' => 'quality_update', 'label' => 'Видео-обложки GPT'],
        'gpt_generate_product_photo_variant' => ['kind' => 'quality_update', 'label' => 'Фото-вариации'],
        'create_photo_collage' => ['kind' => 'quality_update', 'label' => 'Коллажи из фото'],
        'create_product_specs_photo' => ['kind' => 'quality_update', 'label' => 'Фото-характеристики'],
        'gpt_fill_hashtags' => ['kind' => 'quality_update', 'label' => 'Хэштеги'],
        'gpt_fill_tnved_codes' => ['kind' => 'quality_update', 'label' => 'ТН ВЭД'],
        'detect_brand_from_title' => ['kind' => 'quality_update', 'label' => 'Бренды из названий'],
        'pixlab_remove_photo_watermarks' => ['kind' => 'quality_update', 'label' => 'Фото без водяных знаков'],
        'remove_white_bg_watermarks' => ['kind' => 'quality_update', 'label' => 'Белый фон без водяных знаков'],
        'set_ozon_category' => ['kind' => 'quality_update', 'label' => 'Категория Ozon'],
        'set_wb_subject' => ['kind' => 'quality_update', 'label' => 'Предмет WB'],
    ];
    if (isset($map[$opType])) {
        return $map[$opType];
    }
    if (str_starts_with($opType, 'gpt_')) {
        return ['kind' => 'quality_update', 'label' => $opType];
    }
    return null;
}

function supplier_content_progress_actor_kind(?string $actor, string $opType): string
{
    $actor = trim((string)$actor);
    if ($actor === '') {
        return 'unknown';
    }
    if (in_array($actor, ['cron', 'system', 'automation'], true)) {
        return 'automation';
    }
    if ($actor === 'cli') {
        return 'system';
    }
    if (str_contains($opType, 'sync') && in_array($actor, ['cron', 'cli'], true)) {
        return 'automation';
    }
    return 'human';
}

function supplier_content_progress_actor_label(string $actor): string
{
    $actor = trim($actor);
    if ($actor === '' || $actor === 'unknown') {
        return 'не указан';
    }
    return $actor;
}

function supplier_content_progress_contribution_from_operation(array $op): ?array
{
    $opType = trim((string)($op['op_type'] ?? ''));
    $profile = supplier_content_progress_operation_profile($opType);
    if ($profile === null) {
        return null;
    }

    $summary = supplier_content_progress_json($op['summary_json'] ?? null);
    $status = trim((string)($op['status'] ?? ''));
    $processedFallback = max(0.0, (float)($op['progress_done'] ?? 0));
    $processed = supplier_content_progress_first_number($summary, [
        'metrics.products_seen',
        'summary.products_scanned',
        'summary.products_seen',
        'metrics.offers_seen',
        'metrics.offers_touched',
        'metrics.products_total',
        'metrics.candidates_need_redetect',
        'metrics.processed',
        'metrics.candidates',
        'metrics.jobs',
        'summary.products_with_photos',
        'summary.selected_products',
        'pipeline.selected_offers',
        'metrics.source_offers',
    ], $processedFallback);

    $qualityUpdates = supplier_content_progress_sum_numbers($summary, [
        'metrics.products_changed',
        'summary.products_changed',
        'metrics.offers_touched',
        'metrics.offers_written',
        'metrics.offers_with_hashtags',
        'metrics.fixed',
        'metrics.inplace_applied',
        'metrics.inserted_params',
        'metrics.inserted_values',
        'metrics.inserted_color_params',
        'metrics.offers_with_inserted_colors',
        'metrics.groups_written',
        'metrics.brand_filled',
        'metrics.updated',
        'metrics.changed_products',
        'summary.descriptions_changed',
        'summary.params_changed',
        'summary.pictures_added_total',
        'summary.photos_updated',
        'summary.fixed_marketplace_values_total',
        'summary.applied_products',
        'summary.applied_ozon',
        'summary.applied_wb',
    ]);

    $marketplaceUploads = supplier_content_progress_sum_numbers($summary, [
        'metrics.cards_sent',
        'metrics.cards_created',
        'metrics.import_items_sent',
        'metrics.created_items_sent',
        'metrics.attribute_items_sent',
        'metrics.dimension_items_sent',
        'metrics.photos_items_sent',
        'metrics.photos_sent',
    ]);

    $catalogUpdates = supplier_content_progress_sum_numbers($summary, [
        'metrics.updated',
        'metrics.added',
        'metrics.changed_products',
    ]);

    $errors = supplier_content_progress_sum_numbers($summary, [
        'metrics.failed',
        'metrics.ozon_task_errors',
        'metrics.errors',
        'summary.errors',
        'upload_errors',
    ]);

    $points = 0.0;
    if ($status === 'done') {
        if ($profile['kind'] === 'marketplace_upload') {
            $points = supplier_content_progress_scaled_points($marketplaceUploads, 3.0, 0.55)
                + supplier_content_progress_scaled_points($qualityUpdates, 0.8, 0.08)
                + supplier_content_progress_scaled_points($processed, 0.04, 0.005);
        } elseif ($profile['kind'] === 'catalog_update') {
            $points = supplier_content_progress_scaled_points($catalogUpdates, 0.55, 0.035)
                + supplier_content_progress_scaled_points($processed, 0.02, 0.002);
        } else {
            $points = supplier_content_progress_scaled_points($qualityUpdates, 1.4, 0.11)
                + supplier_content_progress_scaled_points($processed, 0.05, 0.006);
        }
        $points = max(0.0, $points - ($errors * 0.5));
    }

    $actor = null;
    if (function_exists('ops_effective_actor_for_row')) {
        $actor = ops_effective_actor_for_row($op);
    }
    $actor = trim((string)($actor ?? ($op['created_by'] ?? '')));
    if ($actor === '') {
        $actor = 'unknown';
    }

    $summarySupplierId = (int)(
        supplier_content_progress_array_path($summary, 'pipeline.supplier_id')
        ?? supplier_content_progress_array_path($summary, 'summary.supplier_id')
        ?? supplier_content_progress_array_path($summary, 'metrics.supplier_id')
        ?? 0
    );
    $supplierId = (int)($op['supplier_id'] ?? 0);
    if ($supplierId <= 0 && $summarySupplierId > 0) {
        $supplierId = $summarySupplierId;
    }

    return [
        'op_id' => (int)($op['id'] ?? 0),
        'op_type' => $opType,
        'op_label' => (string)$profile['label'],
        'kind' => (string)$profile['kind'],
        'status' => $status,
        'actor' => $actor,
        'actor_label' => supplier_content_progress_actor_label($actor),
        'actor_kind' => supplier_content_progress_actor_kind($actor, $opType),
        'supplier_id' => $supplierId,
        'supplier_name' => (string)($op['supplier_name'] ?? ''),
        'supplier_code' => (string)($op['supplier_code'] ?? ''),
        'created_at' => (string)($op['created_at'] ?? ''),
        'finished_at' => (string)($op['finished_at'] ?? ''),
        'products_processed' => (int)round($processed),
        'quality_updates' => (int)round($qualityUpdates),
        'marketplace_uploads' => (int)round($marketplaceUploads),
        'catalog_updates' => (int)round($catalogUpdates),
        'errors' => (int)round($errors),
        'content_points' => round(max(0.0, $points), 2),
    ];
}

function supplier_content_progress_blank_contribution(): array
{
    return [
        'ops_total' => 0,
        'ops_done' => 0,
        'ops_error' => 0,
        'content_points' => 0.0,
        'products_processed' => 0,
        'quality_updates' => 0,
        'marketplace_uploads' => 0,
        'catalog_updates' => 0,
        'errors' => 0,
        'supplier_ids' => [],
        'top_operation' => '',
    ];
}

function supplier_content_progress_add_contribution(array &$bucket, array $event): void
{
    $bucket['ops_total'] = (int)($bucket['ops_total'] ?? 0) + 1;
    if ((string)($event['status'] ?? '') === 'done') {
        $bucket['ops_done'] = (int)($bucket['ops_done'] ?? 0) + 1;
    }
    if ((string)($event['status'] ?? '') === 'error') {
        $bucket['ops_error'] = (int)($bucket['ops_error'] ?? 0) + 1;
    }
    $bucket['content_points'] = (float)($bucket['content_points'] ?? 0.0) + (float)($event['content_points'] ?? 0.0);
    $bucket['products_processed'] = (int)($bucket['products_processed'] ?? 0) + (int)($event['products_processed'] ?? 0);
    $bucket['quality_updates'] = (int)($bucket['quality_updates'] ?? 0) + (int)($event['quality_updates'] ?? 0);
    $bucket['marketplace_uploads'] = (int)($bucket['marketplace_uploads'] ?? 0) + (int)($event['marketplace_uploads'] ?? 0);
    $bucket['catalog_updates'] = (int)($bucket['catalog_updates'] ?? 0) + (int)($event['catalog_updates'] ?? 0);
    $bucket['errors'] = (int)($bucket['errors'] ?? 0) + (int)($event['errors'] ?? 0);
    $supplierId = (int)($event['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $bucket['supplier_ids'][$supplierId] = true;
    }
    if ((float)($event['content_points'] ?? 0.0) > (float)($bucket['_top_points'] ?? -1.0)) {
        $bucket['_top_points'] = (float)$event['content_points'];
        $bucket['top_operation'] = (string)($event['op_label'] ?? $event['op_type'] ?? '');
    }
}

function supplier_content_progress_finalize_contribution_bucket(array $bucket): array
{
    $supplierIds = (array)($bucket['supplier_ids'] ?? []);
    unset($bucket['supplier_ids'], $bucket['_top_points']);
    $bucket['suppliers_count'] = count($supplierIds);
    $bucket['content_points'] = round(max(0.0, (float)($bucket['content_points'] ?? 0.0)), 2);
    return $bucket;
}

function supplier_content_progress_fetch_contributions(array $period, array $cfg = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    ops_table_ensure();

    $st = db()->prepare("
        SELECT
            o.*,
            m.supplier_id,
            s.name AS supplier_name,
            s.supplier_code AS supplier_code
        FROM feedtools_operations o
        LEFT JOIN feedtools_supplier_product_meta m ON m.dataset_id = o.dataset_id
        LEFT JOIN feedtools_suppliers s ON s.id = m.supplier_id
        WHERE o.created_at BETWEEN ? AND ?
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 2500
    ");
    $st->execute([(string)$period['from_ts'], (string)$period['to_ts']]);

    $events = [];
    $byActor = [];
    $bySupplier = [];
    $overview = supplier_content_progress_blank_contribution();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $op) {
        $event = supplier_content_progress_contribution_from_operation($op);
        if ($event === null) {
            continue;
        }
        $events[] = $event;
        supplier_content_progress_add_contribution($overview, $event);

        $actorKey = (string)$event['actor'];
        if (!isset($byActor[$actorKey])) {
            $byActor[$actorKey] = supplier_content_progress_blank_contribution() + [
                'actor' => $actorKey,
                'actor_label' => (string)$event['actor_label'],
                'actor_kind' => (string)$event['actor_kind'],
            ];
        }
        supplier_content_progress_add_contribution($byActor[$actorKey], $event);

        $supplierId = (int)($event['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            if (!isset($bySupplier[$supplierId])) {
                $bySupplier[$supplierId] = supplier_content_progress_blank_contribution() + [
                    'supplier_id' => $supplierId,
                    'supplier_name' => (string)$event['supplier_name'],
                    'supplier_code' => (string)$event['supplier_code'],
                    'actors' => [],
                ];
            }
            supplier_content_progress_add_contribution($bySupplier[$supplierId], $event);
            $actorKey = (string)$event['actor'];
            $bySupplier[$supplierId]['actors'][$actorKey] = (float)($bySupplier[$supplierId]['actors'][$actorKey] ?? 0.0) + (float)$event['content_points'];
        }
    }

    foreach ($byActor as &$row) {
        $row = supplier_content_progress_finalize_contribution_bucket($row);
    }
    unset($row);
    uasort($byActor, static fn(array $a, array $b): int => ((float)$b['content_points'] <=> (float)$a['content_points']) ?: ((int)$b['ops_done'] <=> (int)$a['ops_done']));

    foreach ($bySupplier as &$row) {
        $actors = (array)($row['actors'] ?? []);
        arsort($actors, SORT_NUMERIC);
        $row['top_actor'] = $actors ? supplier_content_progress_actor_label((string)array_key_first($actors)) : '';
        $row['top_actor_points'] = $actors ? round(max(0.0, (float)reset($actors)), 2) : 0.0;
        unset($row['actors']);
        $row = supplier_content_progress_finalize_contribution_bucket($row);
    }
    unset($row);
    uasort($bySupplier, static fn(array $a, array $b): int => ((float)$b['content_points'] <=> (float)$a['content_points']) ?: strcmp((string)$a['supplier_name'], (string)$b['supplier_name']));

    $overview = supplier_content_progress_finalize_contribution_bucket($overview);

    return [
        'overview' => $overview,
        'by_actor' => array_values($byActor),
        'by_supplier' => $bySupplier,
        'events' => array_slice($events, 0, 80),
    ];
}

function supplier_content_progress_fetch_supplier_contributions(int $supplierId, array $period, array $cfg = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    ops_table_ensure();

    $st = db()->prepare("
        SELECT
            o.*,
            m.supplier_id,
            s.name AS supplier_name,
            s.supplier_code AS supplier_code
        FROM feedtools_operations o
        LEFT JOIN feedtools_supplier_product_meta m ON m.dataset_id = o.dataset_id
        LEFT JOIN feedtools_suppliers s ON s.id = m.supplier_id
        WHERE o.created_at BETWEEN ? AND ?
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 10000
    ");
    $st->execute([(string)$period['from_ts'], (string)$period['to_ts']]);

    $events = [];
    $byActor = [];
    $byKind = [];
    $overview = supplier_content_progress_blank_contribution();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $op) {
        $event = supplier_content_progress_contribution_from_operation($op);
        if ($event === null || (int)($event['supplier_id'] ?? 0) !== $supplierId) {
            continue;
        }
        $events[] = $event;
        supplier_content_progress_add_contribution($overview, $event);

        $actorKey = (string)$event['actor'];
        if (!isset($byActor[$actorKey])) {
            $byActor[$actorKey] = supplier_content_progress_blank_contribution() + [
                'actor' => $actorKey,
                'actor_label' => (string)$event['actor_label'],
                'actor_kind' => (string)$event['actor_kind'],
            ];
        }
        supplier_content_progress_add_contribution($byActor[$actorKey], $event);

        $kindKey = (string)($event['kind'] ?? 'other');
        if (!isset($byKind[$kindKey])) {
            $byKind[$kindKey] = supplier_content_progress_blank_contribution() + [
                'kind' => $kindKey,
                'kind_label' => [
                    'marketplace_upload' => 'Выгрузка',
                    'quality_update' => 'Качество',
                    'catalog_update' => 'Каталог',
                ][$kindKey] ?? $kindKey,
            ];
        }
        supplier_content_progress_add_contribution($byKind[$kindKey], $event);
    }

    foreach ($byActor as &$row) {
        $row = supplier_content_progress_finalize_contribution_bucket($row);
    }
    unset($row);
    uasort($byActor, static fn(array $a, array $b): int => ((float)$b['content_points'] <=> (float)$a['content_points']) ?: ((int)$b['ops_done'] <=> (int)$a['ops_done']));

    foreach ($byKind as &$row) {
        $row = supplier_content_progress_finalize_contribution_bucket($row);
    }
    unset($row);
    uasort($byKind, static fn(array $a, array $b): int => ((float)$b['content_points'] <=> (float)$a['content_points']) ?: ((int)$b['ops_done'] <=> (int)$a['ops_done']));

    $overview = supplier_content_progress_finalize_contribution_bucket($overview);

    return [
        'overview' => $overview,
        'by_actor' => array_values($byActor),
        'by_kind' => array_values($byKind),
        'events' => array_slice($events, 0, 40),
    ];
}

function supplier_content_progress_fetch_monitoring(array $cfg, array $filters = []): array
{
    $portfolio = supplier_content_progress_fetch_portfolio($cfg, $filters);
    $period = (array)$portfolio['period'];
    $contributions = supplier_content_progress_fetch_contributions($period, $cfg);

    $overview = [
        'suppliers_count' => 0,
        'delta_available_count' => 0,
        'delta_missing_count' => 0,
        'improved_suppliers_count' => 0,
        'regressed_suppliers_count' => 0,
        'uploaded_delta' => 0,
        'ready_delta' => 0,
        'sellable_delta' => 0,
        'error_delta' => 0,
        'revision_delta' => 0,
        'avg_progress_delta' => 0.0,
        'avg_quality_delta' => 0.0,
        'content_ops_total' => (int)($contributions['overview']['ops_total'] ?? 0),
        'content_ops_done' => (int)($contributions['overview']['ops_done'] ?? 0),
        'content_points' => (float)($contributions['overview']['content_points'] ?? 0.0),
        'active_actors_count' => count((array)($contributions['by_actor'] ?? [])),
    ];

    $rows = [];
    $progressDeltaSum = 0.0;
    $qualityDeltaSum = 0.0;
    foreach ((array)($portfolio['rows'] ?? []) as $entry) {
        $supplier = (array)($entry['supplier'] ?? []);
        $snapshot = (array)($entry['snapshot'] ?? []);
        $metrics = (array)($entry['metrics'] ?? []);
        $delta = (array)($entry['delta'] ?? []);
        $supplierId = (int)($supplier['id'] ?? 0);
        $supplierContribution = (array)($contributions['by_supplier'][$supplierId] ?? []);
        $available = !empty($delta['available']);

        $overview['suppliers_count']++;
        if ($available) {
            $overview['delta_available_count']++;
            $overview['uploaded_delta'] += (int)round((float)($delta['uploaded_total'] ?? 0));
            $overview['ready_delta'] += (int)round((float)($delta['ready_total'] ?? 0));
            $overview['sellable_delta'] += (int)round((float)($delta['sellable_total'] ?? 0));
            $overview['error_delta'] += (int)round((float)($delta['error_total'] ?? 0));
            $overview['revision_delta'] += (int)round((float)($delta['revision_total'] ?? 0));
            $progressDelta = (float)($delta['content_progress_score'] ?? 0.0);
            $progressDeltaSum += $progressDelta;
            $qualityDeltaSum += (float)($delta['avg_card_quality_score'] ?? 0.0);
            if ($progressDelta > 0.1) {
                $overview['improved_suppliers_count']++;
            } elseif ($progressDelta < -0.1) {
                $overview['regressed_suppliers_count']++;
            }
        } else {
            $overview['delta_missing_count']++;
        }

        $rows[] = [
            'supplier' => $supplier,
            'snapshot' => $snapshot,
            'metrics' => $metrics,
            'delta' => $delta,
            'contribution' => $supplierContribution,
        ];
    }
    if ($overview['delta_available_count'] > 0) {
        $overview['avg_progress_delta'] = round($progressDeltaSum / $overview['delta_available_count'], 2);
        $overview['avg_quality_delta'] = round($qualityDeltaSum / $overview['delta_available_count'], 2);
    }

    usort($rows, static function (array $a, array $b): int {
        $aDelta = (array)($a['delta'] ?? []);
        $bDelta = (array)($b['delta'] ?? []);
        $aContribution = (array)($a['contribution'] ?? []);
        $bContribution = (array)($b['contribution'] ?? []);
        $aMovement = abs((float)($aDelta['content_progress_score'] ?? 0.0)) * 10.0
            + abs((float)($aDelta['uploaded_total'] ?? 0.0))
            + abs((float)($aDelta['sellable_total'] ?? 0.0))
            + ((float)($aContribution['content_points'] ?? 0.0) / 100.0);
        $bMovement = abs((float)($bDelta['content_progress_score'] ?? 0.0)) * 10.0
            + abs((float)($bDelta['uploaded_total'] ?? 0.0))
            + abs((float)($bDelta['sellable_total'] ?? 0.0))
            + ((float)($bContribution['content_points'] ?? 0.0) / 100.0);
        if ($aMovement !== $bMovement) {
            return $bMovement <=> $aMovement;
        }
        return strcmp((string)($a['supplier']['name'] ?? ''), (string)($b['supplier']['name'] ?? ''));
    });

    return [
        'period' => $period,
        'overview' => $overview,
        'rows' => $rows,
        'contributions' => $contributions,
    ];
}

function supplier_content_progress_task_types(): array
{
    return [
        'all' => 'Все задачи',
        'not_uploaded' => 'Не загружено',
        'marketplace_errors' => 'Ошибки и доработки',
        'not_sellable' => 'Загружено, но не продается',
        'weak_quality' => 'Слабые карточки',
        'missing_core' => 'Нет базовых данных',
    ];
}

function supplier_content_progress_task_type_where(string $type): string
{
    return match ($type) {
        'not_uploaded' => "a.normalized_status = 'not_uploaded'",
        'marketplace_errors' => "a.normalized_status IN ('error', 'revision')",
        'not_sellable' => "a.is_uploaded = 1 AND a.is_sellable = 0 AND a.normalized_status NOT IN ('error', 'revision')",
        'weak_quality' => "a.card_quality_score < 70",
        'missing_core' => "(a.issues_json LIKE '%\"code\":\"missing_title\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_brand\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_description\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_images\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_ozon_category\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_wb_category\"%'
            OR a.issues_json LIKE '%\"code\":\"missing_price\"%')",
        default => '1=1',
    };
}

function supplier_content_progress_task_status_label(string $status): string
{
    return [
        'not_uploaded' => 'не загружен',
        'archived' => 'архив',
        'error' => 'ошибка',
        'revision' => 'доработка',
        'uploaded_not_ready' => 'загружен',
        'ready_not_sellable' => 'готов, но не продается',
        'sellable' => 'продается',
    ][$status] ?? $status;
}

function supplier_content_progress_task_recommendation(array $task): string
{
    $status = (string)($task['normalized_status'] ?? '');
    $issues = (array)($task['issues'] ?? []);
    $codes = [];
    foreach ($issues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code !== '') {
            $codes[$code] = true;
        }
    }

    if ($status === 'not_uploaded') {
        return 'Подготовить обязательные поля и отправить карточку на маркетплейс.';
    }
    if (in_array($status, ['error', 'revision'], true)) {
        return 'Открыть ошибки маркетплейса, исправить карточку и повторить отправку.';
    }
    if (isset($codes['missing_images'])) {
        return 'Добавить фото: без изображения карточка не должна идти в работу.';
    }
    if (isset($codes['missing_ozon_category']) || isset($codes['missing_wb_category'])) {
        return 'Назначить категорию маркетплейса и проверить обязательные характеристики.';
    }
    if (isset($codes['missing_description']) || isset($codes['weak_description'])) {
        return 'Дописать описание и проверить, что оно подходит под требования площадки.';
    }
    if (isset($codes['missing_brand'])) {
        return 'Заполнить бренд или безопасное значение для площадки.';
    }
    if (isset($codes['missing_price'])) {
        return 'Проверить цену в данных поставщика перед выгрузкой.';
    }
    if ($status === 'ready_not_sellable' || $status === 'uploaded_not_ready') {
        return 'Проверить, почему карточка не дошла до продаваемого состояния.';
    }
    return 'Проверить слабые места карточки и довести качество до 70+.';
}

function supplier_content_progress_core_issue_codes(): array
{
    return [
        'missing_title' => true,
        'missing_brand' => true,
        'missing_description' => true,
        'missing_images' => true,
        'missing_ozon_category' => true,
        'missing_wb_category' => true,
        'missing_price' => true,
    ];
}

function supplier_content_progress_issue_labels(): array
{
    return [
        'not_uploaded_ozon' => 'Ozon: товар не загружен',
        'not_uploaded_wb' => 'WB: товар не загружен',
        'missing_ozon_category' => 'Нет категории Ozon',
        'missing_wb_category' => 'Нет категории WB',
        'missing_brand' => 'Нет бренда',
        'missing_title' => 'Нет названия',
        'weak_title' => 'Короткое название',
        'missing_description' => 'Нет описания',
        'weak_description' => 'Слабое описание',
        'missing_images' => 'Нет фото',
        'low_images_count' => 'Мало фото',
        'missing_price' => 'Нет цены',
        'ozon_error' => 'Ozon: ошибка карточки',
        'wb_error' => 'WB: ошибка карточки',
        'ozon_revision' => 'Ozon: доработка',
        'wb_revision' => 'WB: доработка',
        'archived' => 'Архив',
        'uploaded_not_ready' => 'Загружено, не готово',
        'ready_not_sellable' => 'Готово, но не продается',
        'weak_quality' => 'Слабое качество карточек',
    ];
}

function supplier_content_progress_batch_issue_label(string $code): string
{
    $labels = supplier_content_progress_issue_labels();
    return (string)($labels[$code] ?? $code);
}

function supplier_content_progress_batch_row_types(array $row, array $issues): array
{
    $status = (string)($row['normalized_status'] ?? '');
    $isUploaded = !empty($row['is_uploaded']);
    $isSellable = !empty($row['is_sellable']);
    $quality = (float)($row['card_quality_score'] ?? 0.0);
    $coreCodes = supplier_content_progress_core_issue_codes();
    $hasCore = false;
    foreach ($issues as $issue) {
        $code = (string)($issue['code'] ?? '');
        if (isset($coreCodes[$code])) {
            $hasCore = true;
            break;
        }
    }

    $types = [];
    if ($status === 'not_uploaded') {
        $types[] = 'not_uploaded';
    }
    if (in_array($status, ['error', 'revision'], true)) {
        $types[] = 'marketplace_errors';
    }
    if ($isUploaded && !$isSellable && !in_array($status, ['error', 'revision'], true)) {
        $types[] = 'not_sellable';
    }
    if ($quality < 70.0) {
        $types[] = 'weak_quality';
    }
    if ($hasCore) {
        $types[] = 'missing_core';
    }
    return array_values(array_unique($types));
}

function supplier_content_progress_batch_primary_issue(array $row, array $issues, string $type, string $forcedIssue = ''): array
{
    $marketplace = (string)($row['marketplace'] ?? '');
    $status = (string)($row['normalized_status'] ?? '');
    $issueByCode = [];
    foreach ($issues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code !== '') {
            $issueByCode[$code] = $issue;
        }
    }
    if ($forcedIssue !== '' && isset($issueByCode[$forcedIssue])) {
        $issue = (array)$issueByCode[$forcedIssue];
        return [
            'code' => $forcedIssue,
            'label' => (string)($issue['label'] ?? supplier_content_progress_batch_issue_label($forcedIssue)),
            'severity' => (string)($issue['severity'] ?? 'minor'),
        ];
    }

    if ($type === 'marketplace_errors') {
        foreach ([$marketplace . '_error', $marketplace . '_revision'] as $code) {
            if (isset($issueByCode[$code])) {
                $issue = (array)$issueByCode[$code];
                return [
                    'code' => $code,
                    'label' => (string)($issue['label'] ?? supplier_content_progress_batch_issue_label($code)),
                    'severity' => (string)($issue['severity'] ?? ($status === 'error' ? 'critical' : 'major')),
                ];
            }
        }
        $code = $marketplace . '_' . ($status === 'revision' ? 'revision' : 'error');
        return [
            'code' => $code,
            'label' => supplier_content_progress_batch_issue_label($code),
            'severity' => $status === 'error' ? 'critical' : 'major',
        ];
    }
    if ($type === 'not_sellable') {
        $code = in_array($status, ['uploaded_not_ready', 'ready_not_sellable', 'archived'], true) ? $status : 'ready_not_sellable';
        return [
            'code' => $code,
            'label' => supplier_content_progress_batch_issue_label($code),
            'severity' => $status === 'archived' ? 'major' : 'minor',
        ];
    }

    $ordered = [];
    if ($marketplace === 'ozon') {
        $ordered[] = 'missing_ozon_category';
    } elseif ($marketplace === 'wb') {
        $ordered[] = 'missing_wb_category';
    }
    array_push(
        $ordered,
        'missing_images',
        'missing_description',
        'missing_brand',
        'missing_price',
        'missing_title',
        'weak_description',
        'low_images_count',
        'weak_title'
    );
    foreach ($ordered as $code) {
        if (isset($issueByCode[$code])) {
            $issue = (array)$issueByCode[$code];
            return [
                'code' => $code,
                'label' => (string)($issue['label'] ?? supplier_content_progress_batch_issue_label($code)),
                'severity' => (string)($issue['severity'] ?? 'minor'),
            ];
        }
    }

    if ($type === 'not_uploaded') {
        $code = $marketplace === 'wb' ? 'not_uploaded_wb' : 'not_uploaded_ozon';
        return ['code' => $code, 'label' => supplier_content_progress_batch_issue_label($code), 'severity' => 'major'];
    }
    if ($type === 'weak_quality') {
        return ['code' => 'weak_quality', 'label' => supplier_content_progress_batch_issue_label('weak_quality'), 'severity' => 'minor'];
    }
    return ['code' => 'other', 'label' => 'Другая проблема', 'severity' => 'minor'];
}

function supplier_content_progress_batch_scope(array $row, string $issueCode, string $type): array
{
    $brand = trim((string)($row['product_brand'] ?? ''));
    $status = trim((string)($row['normalized_status'] ?? ''));

    if ($brand !== '' && in_array($issueCode, ['missing_price'], true)) {
        return ['kind' => 'brand', 'value' => $brand, 'label' => 'бренд: ' . $brand];
    }
    if (str_contains($issueCode, '_error') || str_contains($issueCode, '_revision') || $type === 'marketplace_errors') {
        return ['kind' => 'status', 'value' => $status, 'label' => supplier_content_progress_task_status_label($status)];
    }
    return ['kind' => 'supplier', 'value' => '', 'label' => 'весь поставщик'];
}

function supplier_content_progress_batch_action(array $group): array
{
    $code = (string)($group['issue_code'] ?? '');
    $marketplace = (string)($group['marketplace'] ?? '');
    $mpLabel = $marketplace === 'wb' ? 'WB' : 'Ozon';

    if (in_array($code, ['missing_ozon_category', 'missing_wb_category'], true)) {
        return [
            'label' => 'Назначить категории пакетно',
            'text' => 'Открыть группу, назначить категорию для всего среза и затем пересчитать прогресс.',
            'class' => 'warn',
        ];
    }
    if (in_array($code, ['missing_description', 'weak_description'], true)) {
        return [
            'label' => 'Сгенерировать описания',
            'text' => 'Запустить массовую генерацию или очистку описаний для группы товаров.',
            'class' => 'warn',
        ];
    }
    if ($code === 'missing_brand') {
        return [
            'label' => 'Заполнить бренд группой',
            'text' => 'Разобрать бренды через словарь/правило и применить ко всей группе.',
            'class' => 'warn',
        ];
    }
    if (in_array($code, ['missing_images', 'low_images_count'], true)) {
        return [
            'label' => 'Дособрать фото',
            'text' => 'Массово подтянуть фото из источника или подготовить импорт изображений.',
            'class' => 'bad',
        ];
    }
    if ($code === 'missing_price') {
        return [
            'label' => 'Проверить цены',
            'text' => 'Обновить цены из фида поставщика перед выгрузкой группы.',
            'class' => 'warn',
        ];
    }
    if (str_starts_with($code, 'not_uploaded_')) {
        return [
            'label' => 'Выгрузить пакет на ' . $mpLabel,
            'text' => 'После закрытия блокеров отправить группу товаров на маркетплейс.',
            'class' => 'good',
        ];
    }
    if (str_contains($code, '_error') || str_contains($code, '_revision')) {
        return [
            'label' => 'Исправить причину и повторить отправку',
            'text' => 'Разобрать общий тип ошибки по группе, применить правку и переотправить пакет.',
            'class' => 'bad',
        ];
    }
    if (in_array($code, ['uploaded_not_ready', 'ready_not_sellable', 'archived'], true)) {
        return [
            'label' => 'Довести до продаваемого статуса',
            'text' => 'Проверить статус площадки по группе и повторить нужную публикацию.',
            'class' => 'warn',
        ];
    }
    return [
        'label' => 'Усилить карточки группой',
        'text' => 'Открыть группу, применить массовую правку и пересчитать качество.',
        'class' => 'warn',
    ];
}

function supplier_content_progress_batch_products_href(array $group): string
{
    $datasetId = (int)($group['dataset_id'] ?? 0);
    $supplierId = (int)($group['supplier_id'] ?? 0);
    $marketplace = (string)($group['marketplace'] ?? '');
    $issueCode = (string)($group['issue_code'] ?? '');
    $scope = (array)($group['scope'] ?? []);
    $query = [
        'limit' => '100',
        'f_stock_min' => '1',
    ];
    if ($datasetId > 0) {
        $query['id'] = $datasetId;
    } elseif ($supplierId > 0) {
        $query['supplier_id'] = $supplierId;
    }
    if ((string)($scope['kind'] ?? '') === 'category') {
        $value = (string)($scope['value'] ?? '');
        $query['f_catpath'] = [$value !== '' ? $value : '__EMPTY__'];
    }
    if ($issueCode === 'missing_ozon_category') {
        $query['f_ozoncat'] = ['__EMPTY__'];
    } elseif ($issueCode === 'missing_wb_category') {
        $query['f_wbcat'] = ['__EMPTY__'];
    } elseif ($issueCode === 'missing_brand') {
        $query[$marketplace === 'wb' ? 'f_brand_wb' : 'f_brand_ozon'] = ['__EMPTY__'];
    } elseif ($issueCode === 'not_uploaded_ozon') {
        $query['f_not_in_ozon'] = '1';
    } elseif ($issueCode === 'not_uploaded_wb') {
        $query['f_not_in_wb'] = '1';
    } elseif (str_contains($issueCode, '_error')) {
        $query[$marketplace === 'wb' ? 'f_status_wb' : 'f_status_ozon'] = ['state:error'];
    } elseif (str_contains($issueCode, '_revision')) {
        $query[$marketplace === 'wb' ? 'f_status_wb' : 'f_status_ozon'] = ['state:revision'];
    }
    return 'supplier_products_view.php?' . http_build_query($query);
}

function supplier_content_progress_batch_group_add(array &$groups, array $row, array $issues, string $type, string $forcedIssue = ''): void
{
    $primary = supplier_content_progress_batch_primary_issue($row, $issues, $type, $forcedIssue);
    $scope = supplier_content_progress_batch_scope($row, (string)$primary['code'], $type);
    $supplierId = (int)($row['supplier_id'] ?? 0);
    $marketplace = (string)($row['marketplace'] ?? '');
    $key = implode('|', [
        $type,
        $supplierId,
        $marketplace,
        (string)$primary['code'],
        (string)($scope['kind'] ?? ''),
        md5((string)($scope['value'] ?? '')),
    ]);

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key' => $key,
            'type' => $type,
            'type_label' => supplier_content_progress_task_types()[$type] ?? $type,
            'supplier_id' => $supplierId,
            'supplier_name' => (string)($row['supplier_name'] ?? ''),
            'supplier_code' => (string)($row['supplier_code'] ?? ''),
            'dataset_id' => (int)($row['dataset_id'] ?? 0),
            'marketplace' => $marketplace,
            'issue_code' => (string)$primary['code'],
            'issue_label' => (string)$primary['label'],
            'severity' => (string)$primary['severity'],
            'scope' => $scope,
            'rows_count' => 0,
            'products' => [],
            'products_count' => 0,
            'stock_sum' => 0,
            'quality_sum' => 0.0,
            'avg_quality' => 0.0,
            'min_quality' => 100.0,
            'sample_products' => [],
            'sample_offer_ids' => [],
            'status_counts' => [],
            'issue_counts' => [],
            'priority' => 0.0,
        ];
    }

    $productId = (int)($row['product_id'] ?? 0);
    $offerId = trim((string)($row['offer_id'] ?? ''));
    $status = (string)($row['normalized_status'] ?? '');
    $quality = (float)($row['card_quality_score'] ?? 0.0);
    $stock = max(0, (int)round((float)($row['product_stock'] ?? 0)));

    $groups[$key]['rows_count']++;
    if ($productId > 0) {
        $groups[$key]['products'][$productId] = true;
    }
    $groups[$key]['stock_sum'] += $stock;
    $groups[$key]['quality_sum'] += $quality;
    $groups[$key]['min_quality'] = min((float)$groups[$key]['min_quality'], $quality);
    $groups[$key]['status_counts'][$status] = (int)($groups[$key]['status_counts'][$status] ?? 0) + 1;
    foreach ($issues as $issue) {
        $code = trim((string)($issue['code'] ?? ''));
        if ($code !== '') {
            $groups[$key]['issue_counts'][$code] = (int)($groups[$key]['issue_counts'][$code] ?? 0) + 1;
        }
    }
    if (count((array)$groups[$key]['sample_products']) < 4) {
        $name = trim((string)($row['product_name'] ?? ''));
        if ($name !== '') {
            $groups[$key]['sample_products'][] = $name;
        }
    }
    if ($offerId !== '' && count((array)$groups[$key]['sample_offer_ids']) < 20) {
        $groups[$key]['sample_offer_ids'][] = $offerId;
    }
}

function supplier_content_progress_batch_group_finalize(array $group): array
{
    $rows = max(1, (int)($group['rows_count'] ?? 0));
    $products = (array)($group['products'] ?? []);
    $group['products_count'] = count($products);
    $group['avg_quality'] = supplier_content_progress_round((float)($group['quality_sum'] ?? 0.0) / $rows);
    unset($group['products'], $group['quality_sum']);

    $severityWeight = ['critical' => 6.0, 'major' => 4.0, 'minor' => 1.5][(string)($group['severity'] ?? 'minor')] ?? 1.5;
    $typeWeight = [
        'marketplace_errors' => 7.0,
        'missing_core' => 6.0,
        'not_uploaded' => 5.0,
        'not_sellable' => 4.0,
        'weak_quality' => 2.5,
    ][(string)($group['type'] ?? '')] ?? 2.0;
    $qualityGap = max(0.0, 70.0 - (float)($group['avg_quality'] ?? 0.0));
    $group['priority'] = round(($rows * $typeWeight) + ((int)$group['products_count'] * $severityWeight) + ($qualityGap * 2.0), 2);
    $group['action'] = supplier_content_progress_batch_action($group);
    $group['products_href'] = supplier_content_progress_batch_products_href($group);
    $group['supplier_href'] = 'supplier_content_progress_supplier.php?supplier_id=' . urlencode((string)($group['supplier_id'] ?? 0));
    return $group;
}

function supplier_content_progress_fetch_batch_tasks(array $cfg, array $filters = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    $type = trim((string)($filters['type'] ?? 'all'));
    if (!array_key_exists($type, supplier_content_progress_task_types())) {
        $type = 'all';
    }
    $supplierId = max(0, (int)($filters['supplier_id'] ?? 0));
    $marketplace = trim((string)($filters['marketplace'] ?? ''));
    if (!in_array($marketplace, ['ozon', 'wb'], true)) {
        $marketplace = '';
    }
    $issue = trim((string)($filters['issue'] ?? ''));
    $q = trim((string)($filters['q'] ?? ''));
    $includeInactive = !empty($filters['include_inactive']);
    $refresh = !empty($filters['refresh']);
    $limit = max(25, min(250, (int)($filters['limit'] ?? 100)));

    $suppliers = suppliers_list($includeInactive, $cfg);
    if ($refresh) {
        foreach ($suppliers as $supplier) {
            $id = (int)($supplier['id'] ?? 0);
            if ($id <= 0 || ($supplierId > 0 && $id !== $supplierId)) {
                continue;
            }
            try {
                supplier_content_progress_capture_snapshot($id, $cfg);
            } catch (Throwable) {
                // Keep batch queue available even if one supplier cannot be recalculated.
            }
        }
    }

    $coreWhere = supplier_content_progress_task_type_where('missing_core');
    $baseWhere = [
        "COALESCE(s.is_archived, 0) = 0",
        "GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) > 0",
        "(a.normalized_status <> 'sellable' OR a.card_quality_score < 70 OR {$coreWhere})",
    ];
    $args = [];
    if (!$includeInactive) {
        $baseWhere[] = 's.is_active = 1';
    }
    if ($supplierId > 0) {
        $baseWhere[] = 'a.supplier_id = ?';
        $args[] = $supplierId;
    }
    if ($marketplace !== '') {
        $baseWhere[] = 'a.marketplace = ?';
        $args[] = $marketplace;
    }
    if ($issue !== '') {
        $baseWhere[] = 'a.issues_json LIKE ?';
        $args[] = '%"code":"' . str_replace(['%', '_'], ['\\%', '\\_'], $issue) . '"%';
    }
    if ($q !== '') {
        $baseWhere[] = "(p.name LIKE ? OR a.offer_id LIKE ? OR a.vendor_code LIKE ? OR s.name LIKE ? OR p.category_path LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $whereSql = implode("\n          AND ", $baseWhere);

    $st = db()->prepare("
        SELECT
            a.*,
            s.name AS supplier_name,
            s.supplier_code,
            m.dataset_id,
            p.name AS product_name,
            p.vendor_code AS product_vendor_code,
            p.brand AS product_brand,
            p.category_id,
            p.category_path,
            p.price_original AS product_price,
            GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) AS product_stock,
            p.ozon_category AS product_ozon_category,
            p.wb_category AS product_wb_category
        FROM feedtools_supplier_content_assessments a
        JOIN feedtools_supplier_products p ON p.id = a.product_id
        JOIN feedtools_suppliers s ON s.id = a.supplier_id
        LEFT JOIN feedtools_supplier_product_meta m ON m.supplier_id = a.supplier_id
        WHERE {$whereSql}
        ORDER BY s.name ASC, a.marketplace ASC, p.category_path ASC, a.product_id ASC
    ");
    $st->execute($args);

    $groupsByType = ['all' => []];
    foreach (array_keys(supplier_content_progress_task_types()) as $taskType) {
        if ($taskType !== 'all') {
            $groupsByType[$taskType] = [];
        }
    }
    $rowIdsByType = ['all' => []];
    $productIdsByType = ['all' => []];
    foreach (array_keys($groupsByType) as $taskType) {
        $rowIdsByType[$taskType] = [];
        $productIdsByType[$taskType] = [];
    }
    $issueMap = [];

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $issues = supplier_content_progress_json($row['issues_json'] ?? null);
        foreach ($issues as $issueRow) {
            $code = trim((string)($issueRow['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($issueMap[$code])) {
                $issueMap[$code] = [
                    'code' => $code,
                    'label' => (string)($issueRow['label'] ?? supplier_content_progress_batch_issue_label($code)),
                    'count' => 0,
                ];
            }
            $issueMap[$code]['count']++;
        }

        $types = supplier_content_progress_batch_row_types($row, $issues);
        if (!$types) {
            continue;
        }
        $rowId = (int)($row['id'] ?? 0);
        $productId = (int)($row['product_id'] ?? 0);
        foreach ($types as $rowType) {
            $rowIdsByType[$rowType][$rowId] = true;
            $rowIdsByType['all'][$rowId] = true;
            if ($productId > 0) {
                $productIdsByType[$rowType][$productId] = true;
                $productIdsByType['all'][$productId] = true;
            }
            supplier_content_progress_batch_group_add($groupsByType[$rowType], $row, $issues, $rowType, $issue);
            supplier_content_progress_batch_group_add($groupsByType['all'], $row, $issues, $rowType, $issue);
        }
    }

    $counts = [];
    foreach (supplier_content_progress_task_types() as $taskType => $label) {
        $finalized = [];
        foreach ((array)($groupsByType[$taskType] ?? []) as $group) {
            $finalized[] = supplier_content_progress_batch_group_finalize($group);
        }
        usort($finalized, static function (array $a, array $b): int {
            if ((float)$a['priority'] !== (float)$b['priority']) {
                return (float)$b['priority'] <=> (float)$a['priority'];
            }
            if ((int)$a['rows_count'] !== (int)$b['rows_count']) {
                return (int)$b['rows_count'] <=> (int)$a['rows_count'];
            }
            return strcmp((string)$a['supplier_name'], (string)$b['supplier_name']);
        });
        $groupsByType[$taskType] = $finalized;
        $counts[$taskType] = [
            'label' => $label,
            'count' => count($finalized),
            'groups_count' => count($finalized),
            'rows_count' => count((array)($rowIdsByType[$taskType] ?? [])),
            'products_count' => count((array)($productIdsByType[$taskType] ?? [])),
        ];
    }

    uasort($issueMap, static fn(array $a, array $b): int => ((int)$b['count'] <=> (int)$a['count']) ?: strcmp((string)$a['label'], (string)$b['label']));

    return [
        'filters' => [
            'type' => $type,
            'supplier_id' => $supplierId,
            'marketplace' => $marketplace,
            'issue' => $issue,
            'q' => $q,
            'include_inactive' => $includeInactive,
            'limit' => $limit,
        ],
        'suppliers' => $suppliers,
        'counts' => $counts,
        'issues' => array_values(array_slice($issueMap, 0, 30)),
        'groups' => array_slice((array)($groupsByType[$type] ?? []), 0, $limit),
    ];
}

function supplier_content_progress_fetch_supplier_analytics(int $supplierId, array $cfg = []): array
{
    supplier_content_progress_tables_ensure($cfg);

    $statusLabels = [
        'not_uploaded' => 'Не загружено',
        'uploaded_not_ready' => 'Загружено',
        'ready_not_sellable' => 'Готово, не продается',
        'sellable' => 'Продается',
        'revision' => 'Доработка',
        'error' => 'Ошибка',
        'archived' => 'Архив',
    ];
    $statusClasses = [
        'sellable' => 'good',
        'ready_not_sellable' => 'blue',
        'uploaded_not_ready' => 'blue',
        'revision' => 'warn',
        'error' => 'bad',
        'archived' => 'gray',
        'not_uploaded' => 'gray',
    ];
    $statusOrder = ['sellable', 'ready_not_sellable', 'uploaded_not_ready', 'not_uploaded', 'revision', 'error', 'archived'];
    $qualityBandDefs = [
        '0_39' => ['label' => '0-39', 'class' => 'bad'],
        '40_54' => ['label' => '40-54', 'class' => 'bad'],
        '55_69' => ['label' => '55-69', 'class' => 'warn'],
        '70_84' => ['label' => '70-84', 'class' => 'blue'],
        '85_100' => ['label' => '85-100', 'class' => 'good'],
    ];
    $severityRank = ['critical' => 3, 'major' => 2, 'minor' => 1];
    $marketplaceLabels = ['ozon' => 'Ozon', 'wb' => 'Wildberries'];
    $stateBucketDefs = [
        'not_uploaded' => ['label' => 'Не загружено', 'class' => 'bad'],
        'marketplace_errors' => ['label' => 'Ошибки и доработки', 'class' => 'bad'],
        'not_sellable' => ['label' => 'Не продается', 'class' => 'warn'],
        'weak_quality' => ['label' => 'Качество ниже 70', 'class' => 'warn'],
        'missing_core' => ['label' => 'Нет базовых данных', 'class' => 'warn'],
    ];
    $coreIssueCodes = [
        'missing_title' => true,
        'missing_brand' => true,
        'missing_description' => true,
        'missing_images' => true,
        'missing_price' => true,
        'missing_ozon_category' => true,
        'missing_wb_category' => true,
    ];
    $fieldGapDefs = [
        'missing_title' => ['label' => 'Нет названия', 'class' => 'bad'],
        'missing_images' => ['label' => 'Нет фото', 'class' => 'bad'],
        'missing_brand' => ['label' => 'Нет бренда', 'class' => 'warn'],
        'missing_description' => ['label' => 'Нет описания', 'class' => 'warn'],
        'missing_price' => ['label' => 'Нет цены', 'class' => 'warn'],
        'missing_ozon_category' => ['label' => 'Нет категории Ozon', 'class' => 'warn'],
        'missing_wb_category' => ['label' => 'Нет категории WB', 'class' => 'warn'],
    ];

    $statusBucket = static function (string $marketplace) use ($marketplaceLabels, $statusLabels, $statusClasses, $statusOrder): array {
        $statuses = [];
        foreach ($statusOrder as $status) {
            $statuses[$status] = [
                'key' => $status,
                'label' => $statusLabels[$status] ?? $status,
                'class' => $statusClasses[$status] ?? 'gray',
                'count' => 0,
                'percent' => 0.0,
            ];
        }
        return [
            'marketplace' => $marketplace,
            'label' => $marketplaceLabels[$marketplace] ?? $marketplace,
            'total' => 0,
            'statuses' => $statuses,
        ];
    };
    $qualityBucket = static function (string $key, string $label) use ($qualityBandDefs): array {
        $bands = [];
        foreach ($qualityBandDefs as $bandKey => $def) {
            $bands[$bandKey] = [
                'key' => $bandKey,
                'label' => $def['label'],
                'class' => $def['class'],
                'count' => 0,
                'percent' => 0.0,
            ];
        }
        return [
            'key' => $key,
            'label' => $label,
            'total' => 0,
            'sum' => 0.0,
            'avg' => 0.0,
            'bands' => $bands,
        ];
    };
    $qualityBandKey = static function (float $score): string {
        if ($score < 40.0) return '0_39';
        if ($score < 55.0) return '40_54';
        if ($score < 70.0) return '55_69';
        if ($score < 85.0) return '70_84';
        return '85_100';
    };
    $percent = static function (int $count, int $total): float {
        return $total > 0 ? supplier_content_progress_round(($count / $total) * 100.0) : 0.0;
    };

    $st = db()->prepare("
        SELECT
            a.*,
            p.name AS product_name,
            p.price_original AS product_price,
            GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) AS product_stock,
            p.ozon_category AS product_ozon_category,
            p.wb_category AS product_wb_category
        FROM feedtools_supplier_content_assessments a
        JOIN feedtools_supplier_products p ON p.id = a.product_id
        WHERE a.supplier_id = ?
          AND GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) > 0
        ORDER BY a.marketplace ASC, a.product_id ASC, a.id ASC
    ");
    $st->execute([$supplierId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusByMarketplace = [
        'ozon' => $statusBucket('ozon'),
        'wb' => $statusBucket('wb'),
    ];
    $qualityBands = [
        'all' => $qualityBucket('all', 'Все'),
        'ozon' => $qualityBucket('ozon', 'Ozon'),
        'wb' => $qualityBucket('wb', 'Wildberries'),
    ];
    $stateBuckets = [];
    foreach ($stateBucketDefs as $bucketKey => $def) {
        $stateBuckets[$bucketKey] = [
            'key' => $bucketKey,
            'label' => $def['label'],
            'class' => $def['class'],
            'count' => 0,
            'percent' => 0.0,
        ];
    }
    $fieldGaps = [];
    foreach ($fieldGapDefs as $code => $def) {
        $fieldGaps[$code] = [
            'code' => $code,
            'label' => $def['label'],
            'class' => $def['class'],
            'rows_count' => 0,
            'products' => [],
            'products_count' => 0,
            'percent' => 0.0,
        ];
    }
    $marketplaceGaps = [];
    foreach ($marketplaceLabels as $marketplace => $label) {
        $marketplaceGaps[$marketplace] = [
            'marketplace' => $marketplace,
            'label' => $label,
            'target_rows' => 0,
            'uploaded' => 0,
            'ready' => 0,
            'sellable' => 0,
            'not_uploaded' => 0,
            'error_revision' => 0,
            'not_sellable' => 0,
            'weak_quality' => 0,
            'missing_category' => 0,
            'avg_quality' => 0.0,
            'quality_sum' => 0.0,
            'risk_score' => 0.0,
        ];
    }

    $issueMap = [];
    $severityCounts = ['critical' => 0, 'major' => 0, 'minor' => 0];
    $productState = [];
    $qualityDimensionDefs = [
        'identity' => ['label' => 'Идентификация', 'max' => 18.0],
        'category' => ['label' => 'Категория', 'max' => 16.0],
        'description' => ['label' => 'Описание', 'max' => 18.0],
        'images' => ['label' => 'Фото', 'max' => 20.0],
        'attributes' => ['label' => 'Характеристики', 'max' => 18.0],
    ];
    $qualityDimensions = [];
    foreach ($qualityDimensionDefs as $key => $def) {
        $qualityDimensions[$key] = [
            'key' => $key,
            'label' => $def['label'],
            'max' => $def['max'],
            'sum' => 0.0,
            'count' => 0,
            'avg' => 0.0,
            'percent' => 0.0,
        ];
    }

    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $marketplace = (string)($row['marketplace'] ?? '');
        if (!isset($marketplaceLabels[$marketplace])) {
            continue;
        }
        $status = (string)($row['normalized_status'] ?? 'not_uploaded');
        $quality = (float)($row['card_quality_score'] ?? 0.0);
        $isUploaded = !empty($row['is_uploaded']);
        $isReady = !empty($row['is_ready']);
        $isSellable = !empty($row['is_sellable']);

        if (!isset($productState[$productId])) {
            $productState[$productId] = [
                'uploaded_any' => false,
                'uploaded_all' => true,
                'sellable_any' => false,
                'sellable_all' => true,
                'weak_quality_any' => false,
                'has_issue' => false,
                'missing_core' => false,
            ];
        }
        $productState[$productId]['uploaded_any'] = $productState[$productId]['uploaded_any'] || $isUploaded;
        $productState[$productId]['uploaded_all'] = $productState[$productId]['uploaded_all'] && $isUploaded;
        $productState[$productId]['sellable_any'] = $productState[$productId]['sellable_any'] || $isSellable;
        $productState[$productId]['sellable_all'] = $productState[$productId]['sellable_all'] && $isSellable;
        $productState[$productId]['weak_quality_any'] = $productState[$productId]['weak_quality_any'] || $quality < 70.0;

        $statusByMarketplace[$marketplace]['total']++;
        if (!isset($statusByMarketplace[$marketplace]['statuses'][$status])) {
            $statusByMarketplace[$marketplace]['statuses'][$status] = [
                'key' => $status,
                'label' => $statusLabels[$status] ?? $status,
                'class' => $statusClasses[$status] ?? 'gray',
                'count' => 0,
                'percent' => 0.0,
            ];
        }
        $statusByMarketplace[$marketplace]['statuses'][$status]['count']++;

        foreach (['all', $marketplace] as $bucketKey) {
            $qualityBands[$bucketKey]['total']++;
            $qualityBands[$bucketKey]['sum'] += $quality;
            $bandKey = $qualityBandKey($quality);
            $qualityBands[$bucketKey]['bands'][$bandKey]['count']++;
        }

        $marketplaceGaps[$marketplace]['target_rows']++;
        $marketplaceGaps[$marketplace]['uploaded'] += $isUploaded ? 1 : 0;
        $marketplaceGaps[$marketplace]['ready'] += $isReady ? 1 : 0;
        $marketplaceGaps[$marketplace]['sellable'] += $isSellable ? 1 : 0;
        $marketplaceGaps[$marketplace]['not_uploaded'] += $status === 'not_uploaded' ? 1 : 0;
        $marketplaceGaps[$marketplace]['error_revision'] += in_array($status, ['error', 'revision'], true) ? 1 : 0;
        $marketplaceGaps[$marketplace]['not_sellable'] += $isUploaded && !$isSellable && !in_array($status, ['error', 'revision'], true) ? 1 : 0;
        $marketplaceGaps[$marketplace]['weak_quality'] += $quality < 70.0 ? 1 : 0;
        $marketplaceGaps[$marketplace]['quality_sum'] += $quality;

        $stateBuckets['not_uploaded']['count'] += $status === 'not_uploaded' ? 1 : 0;
        $stateBuckets['marketplace_errors']['count'] += in_array($status, ['error', 'revision'], true) ? 1 : 0;
        $stateBuckets['not_sellable']['count'] += $isUploaded && !$isSellable && !in_array($status, ['error', 'revision'], true) ? 1 : 0;
        $stateBuckets['weak_quality']['count'] += $quality < 70.0 ? 1 : 0;

        $qualityBreakdown = supplier_content_progress_json($row['quality_breakdown_json'] ?? null);
        foreach ($qualityDimensionDefs as $dimensionKey => $def) {
            if (isset($qualityBreakdown[$dimensionKey]) && is_numeric($qualityBreakdown[$dimensionKey])) {
                $qualityDimensions[$dimensionKey]['sum'] += (float)$qualityBreakdown[$dimensionKey];
                $qualityDimensions[$dimensionKey]['count']++;
            }
        }

        $issues = supplier_content_progress_json($row['issues_json'] ?? null);
        foreach ($issues as $issue) {
            $code = trim((string)($issue['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $severity = (string)($issue['severity'] ?? 'minor');
            if (!isset($severityCounts[$severity])) {
                $severityCounts[$severity] = 0;
            }
            $severityCounts[$severity]++;
            $productState[$productId]['has_issue'] = true;
            if (isset($coreIssueCodes[$code])) {
                $productState[$productId]['missing_core'] = true;
            }
            if (!isset($issueMap[$code])) {
                $issueMap[$code] = [
                    'code' => $code,
                    'label' => (string)($issue['label'] ?? $code),
                    'severity' => $severity,
                    'fixability' => (string)($issue['fixability'] ?? ''),
                    'marketplace' => (string)($issue['marketplace'] ?? ''),
                    'rows_count' => 0,
                    'products' => [],
                    'products_count' => 0,
                    'risk_score' => 0.0,
                ];
            }
            $issueMap[$code]['rows_count']++;
            $issueMap[$code]['products'][$productId] = true;
            $issueMap[$code]['severity'] = (string)($issue['severity'] ?? $issueMap[$code]['severity']);
            $issueMap[$code]['risk_score'] += (float)($issue['weight'] ?? 1.5);

            if (isset($fieldGaps[$code])) {
                $fieldGaps[$code]['rows_count']++;
                $fieldGaps[$code]['products'][$productId] = true;
            }
            if (($code === 'missing_ozon_category' && $marketplace === 'ozon') || ($code === 'missing_wb_category' && $marketplace === 'wb')) {
                $marketplaceGaps[$marketplace]['missing_category']++;
            }
        }
    }

    $targetProducts = count($productState);
    $targetRows = count($rows);
    $productOverview = [
        'target_products' => $targetProducts,
        'marketplace_rows' => $targetRows,
        'uploaded_any' => 0,
        'uploaded_all' => 0,
        'sellable_any' => 0,
        'sellable_all' => 0,
        'weak_quality_any' => 0,
        'with_issues' => 0,
        'missing_core' => 0,
    ];
    foreach ($productState as $state) {
        $productOverview['uploaded_any'] += !empty($state['uploaded_any']) ? 1 : 0;
        $productOverview['uploaded_all'] += !empty($state['uploaded_all']) ? 1 : 0;
        $productOverview['sellable_any'] += !empty($state['sellable_any']) ? 1 : 0;
        $productOverview['sellable_all'] += !empty($state['sellable_all']) ? 1 : 0;
        $productOverview['weak_quality_any'] += !empty($state['weak_quality_any']) ? 1 : 0;
        $productOverview['with_issues'] += !empty($state['has_issue']) ? 1 : 0;
        $productOverview['missing_core'] += !empty($state['missing_core']) ? 1 : 0;
    }
    foreach (['uploaded_any', 'uploaded_all', 'sellable_any', 'sellable_all', 'weak_quality_any', 'with_issues', 'missing_core'] as $key) {
        $productOverview[$key . '_percent'] = $percent((int)$productOverview[$key], $targetProducts);
    }

    foreach ($statusByMarketplace as &$marketplaceRow) {
        $total = (int)$marketplaceRow['total'];
        foreach ($marketplaceRow['statuses'] as &$statusRow) {
            $statusRow['percent'] = $percent((int)$statusRow['count'], $total);
        }
        unset($statusRow);
        $marketplaceRow['statuses'] = array_values($marketplaceRow['statuses']);
    }
    unset($marketplaceRow);

    foreach ($qualityBands as &$bucket) {
        $total = (int)$bucket['total'];
        $bucket['avg'] = $total > 0 ? supplier_content_progress_round((float)$bucket['sum'] / $total) : 0.0;
        unset($bucket['sum']);
        foreach ($bucket['bands'] as &$band) {
            $band['percent'] = $percent((int)$band['count'], $total);
        }
        unset($band);
        $bucket['bands'] = array_values($bucket['bands']);
    }
    unset($bucket);

    foreach ($stateBuckets as &$bucket) {
        $bucket['percent'] = $percent((int)$bucket['count'], $targetRows);
    }
    unset($bucket);
    $stateBuckets['missing_core']['count'] = (int)$productOverview['missing_core'];
    $stateBuckets['missing_core']['percent'] = $percent((int)$productOverview['missing_core'], $targetProducts);

    foreach ($fieldGaps as &$gap) {
        $gap['products_count'] = count((array)$gap['products']);
        $gap['percent'] = $percent((int)$gap['products_count'], $targetProducts);
        unset($gap['products']);
    }
    unset($gap);
    uasort($fieldGaps, static function (array $a, array $b): int {
        $countCmp = ((int)$b['products_count']) <=> ((int)$a['products_count']);
        if ($countCmp !== 0) return $countCmp;
        return strcmp((string)$a['label'], (string)$b['label']);
    });
    $fieldGaps = array_values(array_filter($fieldGaps, static fn(array $gap): bool => (int)($gap['products_count'] ?? 0) > 0));

    foreach ($marketplaceGaps as &$gap) {
        $total = (int)$gap['target_rows'];
        $gap['avg_quality'] = $total > 0 ? supplier_content_progress_round((float)$gap['quality_sum'] / $total) : 0.0;
        $gap['upload_percent'] = $percent((int)$gap['uploaded'], $total);
        $gap['sellable_percent'] = $percent((int)$gap['sellable'], $total);
        $gap['not_uploaded_percent'] = $percent((int)$gap['not_uploaded'], $total);
        $gap['error_revision_percent'] = $percent((int)$gap['error_revision'], $total);
        $gap['not_sellable_percent'] = $percent((int)$gap['not_sellable'], $total);
        $gap['weak_quality_percent'] = $percent((int)$gap['weak_quality'], $total);
        $gap['risk_score'] = ((int)$gap['not_uploaded'] * 4.0)
            + ((int)$gap['error_revision'] * 5.0)
            + ((int)$gap['not_sellable'] * 3.0)
            + ((int)$gap['weak_quality'] * 1.5)
            + ((int)$gap['missing_category'] * 2.0);
        unset($gap['quality_sum']);
    }
    unset($gap);

    foreach ($issueMap as &$issue) {
        $issue['products_count'] = count((array)$issue['products']);
        $issue['percent'] = $percent((int)$issue['products_count'], $targetProducts);
        unset($issue['products']);
    }
    unset($issue);
    $topIssues = array_values($issueMap);
    usort($topIssues, static function (array $a, array $b) use ($severityRank): int {
        $aScore = ((float)($a['risk_score'] ?? 0.0)) + (($severityRank[(string)($a['severity'] ?? 'minor')] ?? 0) * 1000.0);
        $bScore = ((float)($b['risk_score'] ?? 0.0)) + (($severityRank[(string)($b['severity'] ?? 'minor')] ?? 0) * 1000.0);
        if ($aScore !== $bScore) {
            return $bScore <=> $aScore;
        }
        return strcmp((string)$a['label'], (string)$b['label']);
    });

    foreach ($qualityDimensions as &$dimension) {
        $dimension['avg'] = (int)$dimension['count'] > 0
            ? supplier_content_progress_round((float)$dimension['sum'] / (int)$dimension['count'])
            : 0.0;
        $max = max(1.0, (float)($dimension['max'] ?? 100.0));
        $dimension['percent'] = supplier_content_progress_round(((float)$dimension['avg'] / $max) * 100.0);
        unset($dimension['sum']);
    }
    unset($dimension);
    uasort($qualityDimensions, static function (array $a, array $b): int {
        return ((float)$a['avg'] <=> (float)$b['avg']) ?: strcmp((string)$a['label'], (string)$b['label']);
    });

    $focusMarketplace = null;
    foreach ($marketplaceGaps as $gap) {
        if ($focusMarketplace === null || (float)$gap['risk_score'] > (float)$focusMarketplace['risk_score']) {
            $focusMarketplace = $gap;
        }
    }

    $recommendations = [];
    if ($targetProducts <= 0) {
        $recommendations[] = [
            'title' => 'Выгрузка сейчас не требуется',
            'text' => 'У поставщика нет товаров с положительным остатком, поэтому контент не должен попадать в план выгрузки.',
            'class' => 'gray',
        ];
    } else {
        if ($focusMarketplace && (float)$focusMarketplace['risk_score'] > 0.0) {
            $recommendations[] = [
                'title' => 'Главная просадка: ' . (string)$focusMarketplace['label'],
                'text' => 'Состояние площадки: ' . (int)$focusMarketplace['not_uploaded'] . ' не загружено, '
                    . (int)$focusMarketplace['error_revision'] . ' с ошибками/доработками, '
                    . (int)$focusMarketplace['not_sellable'] . ' загружено без продаж.',
                'class' => (int)$focusMarketplace['error_revision'] > 0 ? 'bad' : 'warn',
            ];
        }
        if ((int)$stateBuckets['not_uploaded']['count'] > 0) {
            $recommendations[] = [
                'title' => 'Низкая загрузка',
                'text' => (int)$stateBuckets['not_uploaded']['count'] . ' строк marketplace еще не попали на площадки; это основной минус в upload score.',
                'class' => 'bad',
            ];
        }
        if ((int)$stateBuckets['marketplace_errors']['count'] > 0) {
            $recommendations[] = [
                'title' => 'Ошибки площадок',
                'text' => (int)$stateBuckets['marketplace_errors']['count'] . ' строк находятся в ошибке или на доработке и напрямую снижают готовность контента.',
                'class' => 'bad',
            ];
        }
        if ((int)$stateBuckets['missing_core']['count'] > 0) {
            $recommendations[] = [
                'title' => 'Пробелы в базовых данных',
                'text' => (int)$stateBuckets['missing_core']['count'] . ' товаров имеют пробелы в названии, фото, бренде, описании, цене или категории.',
                'class' => 'warn',
            ];
        }
        if ((int)$stateBuckets['weak_quality']['count'] > 0) {
            $recommendations[] = [
                'title' => 'Слабое качество заполнения',
                'text' => (int)$stateBuckets['weak_quality']['count'] . ' строк ниже 70 баллов; это зона для пакетного улучшения карточек.',
                'class' => 'warn',
            ];
        }
        if ((int)$stateBuckets['not_sellable']['count'] > 0) {
            $recommendations[] = [
                'title' => 'Загружено, но не продается',
                'text' => (int)$stateBuckets['not_sellable']['count'] . ' строк уже загружены, но не дошли до продаваемого статуса.',
                'class' => 'warn',
            ];
        }
        if (!$recommendations) {
            $recommendations[] = [
                'title' => 'Критичных узких мест нет',
                'text' => 'Поставщик выглядит ровно: можно поддерживать качество и контролировать свежесть статусов.',
                'class' => 'good',
            ];
        }
    }

    return [
        'overview' => $productOverview,
        'state_buckets' => array_values($stateBuckets),
        'status_by_marketplace' => $statusByMarketplace,
        'marketplace_gaps' => $marketplaceGaps,
        'quality_bands' => $qualityBands,
        'quality_dimensions' => array_values($qualityDimensions),
        'severity_counts' => $severityCounts,
        'field_gaps' => $fieldGaps,
        'top_issues' => array_slice($topIssues, 0, 16),
        'focus_marketplace' => $focusMarketplace ?: [],
        'recommendations' => array_slice($recommendations, 0, 6),
    ];
}

function supplier_content_progress_fetch_tasks(array $cfg, array $filters = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    $type = trim((string)($filters['type'] ?? 'all'));
    if (!array_key_exists($type, supplier_content_progress_task_types())) {
        $type = 'all';
    }
    $supplierId = max(0, (int)($filters['supplier_id'] ?? 0));
    $marketplace = trim((string)($filters['marketplace'] ?? ''));
    if (!in_array($marketplace, ['ozon', 'wb'], true)) {
        $marketplace = '';
    }
    $issue = trim((string)($filters['issue'] ?? ''));
    $q = trim((string)($filters['q'] ?? ''));
    $includeInactive = !empty($filters['include_inactive']);
    $refresh = !empty($filters['refresh']);
    $limit = max(25, min(500, (int)($filters['limit'] ?? 250)));

    $suppliers = suppliers_list($includeInactive, $cfg);
    if ($refresh) {
        foreach ($suppliers as $supplier) {
            $id = (int)($supplier['id'] ?? 0);
            if ($id <= 0 || ($supplierId > 0 && $id !== $supplierId)) {
                continue;
            }
            try {
                supplier_content_progress_capture_snapshot($id, $cfg);
            } catch (Throwable) {
                // Keep task page available even if one supplier cannot be recalculated.
            }
        }
    }

    $baseWhere = [
        "COALESCE(s.is_archived, 0) = 0",
        "GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) > 0",
        "a.normalized_status <> 'sellable'",
    ];
    $args = [];
    if (!$includeInactive) {
        $baseWhere[] = 's.is_active = 1';
    }
    if ($supplierId > 0) {
        $baseWhere[] = 'a.supplier_id = ?';
        $args[] = $supplierId;
    }
    if ($marketplace !== '') {
        $baseWhere[] = 'a.marketplace = ?';
        $args[] = $marketplace;
    }
    if ($issue !== '') {
        $baseWhere[] = 'a.issues_json LIKE ?';
        $args[] = '%"code":"' . str_replace(['%', '_'], ['\\%', '\\_'], $issue) . '"%';
    }
    if ($q !== '') {
        $baseWhere[] = "(p.name LIKE ? OR a.offer_id LIKE ? OR a.vendor_code LIKE ? OR s.name LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like);
    }

    $where = $baseWhere;
    $where[] = supplier_content_progress_task_type_where($type);
    $whereSql = implode("\n          AND ", $where);
    $baseWhereSql = implode("\n          AND ", $baseWhere);

    $counts = [];
    $countTypes = supplier_content_progress_task_types();
    foreach ($countTypes as $countType => $label) {
        $countWhere = $baseWhere;
        $countWhere[] = supplier_content_progress_task_type_where((string)$countType);
        $st = db()->prepare("
            SELECT COUNT(*)
            FROM feedtools_supplier_content_assessments a
            JOIN feedtools_supplier_products p ON p.id = a.product_id
            JOIN feedtools_suppliers s ON s.id = a.supplier_id
            WHERE " . implode("\n              AND ", $countWhere) . "
        ");
        $st->execute($args);
        $counts[$countType] = [
            'label' => $label,
            'count' => (int)$st->fetchColumn(),
        ];
    }

    $issueRows = [];
    $issueSt = db()->prepare("
        SELECT a.issues_json
        FROM feedtools_supplier_content_assessments a
        JOIN feedtools_supplier_products p ON p.id = a.product_id
        JOIN feedtools_suppliers s ON s.id = a.supplier_id
        WHERE {$baseWhereSql}
        LIMIT 1200
    ");
    $issueSt->execute($args);
    $issueMap = [];
    while ($raw = $issueSt->fetchColumn()) {
        foreach (supplier_content_progress_json($raw ?? null) as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($issueMap[$code])) {
                $issueMap[$code] = [
                    'code' => $code,
                    'label' => (string)($row['label'] ?? $code),
                    'count' => 0,
                ];
            }
            $issueMap[$code]['count']++;
        }
    }
    uasort($issueMap, static fn(array $a, array $b): int => ((int)$b['count'] <=> (int)$a['count']) ?: strcmp((string)$a['label'], (string)$b['label']));
    $issueRows = array_values(array_slice($issueMap, 0, 24));

    $st = db()->prepare("
        SELECT
            a.*,
            s.name AS supplier_name,
            s.supplier_code,
            m.dataset_id,
            p.name AS product_name,
            p.price_original AS product_price,
            GREATEST(COALESCE(p.stock_qty, 0), COALESCE(p.count_qty, 0)) AS product_stock,
            p.ozon_category AS product_ozon_category,
            p.wb_category AS product_wb_category,
            (
                CASE a.normalized_status
                    WHEN 'error' THEN 100
                    WHEN 'revision' THEN 90
                    WHEN 'not_uploaded' THEN 80
                    WHEN 'ready_not_sellable' THEN 70
                    WHEN 'uploaded_not_ready' THEN 60
                    WHEN 'archived' THEN 50
                    ELSE 20
                END
                + GREATEST(0, 70 - a.card_quality_score) / 2
                + LEAST(30, a.issue_penalty / 2)
            ) AS task_priority
        FROM feedtools_supplier_content_assessments a
        JOIN feedtools_supplier_products p ON p.id = a.product_id
        JOIN feedtools_suppliers s ON s.id = a.supplier_id
        LEFT JOIN feedtools_supplier_product_meta m ON m.supplier_id = a.supplier_id
        WHERE {$whereSql}
        ORDER BY task_priority DESC, a.card_quality_score ASC, a.updated_at DESC, a.id DESC
        LIMIT {$limit}
    ");
    $st->execute($args);
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($tasks as &$task) {
        $task['issues'] = supplier_content_progress_json($task['issues_json'] ?? null);
        $task['metrics'] = supplier_content_progress_json($task['metrics_json'] ?? null);
        $task['recommendation'] = supplier_content_progress_task_recommendation($task);
        $task['status_label'] = supplier_content_progress_task_status_label((string)($task['normalized_status'] ?? ''));
    }
    unset($task);

    return [
        'filters' => [
            'type' => $type,
            'supplier_id' => $supplierId,
            'marketplace' => $marketplace,
            'issue' => $issue,
            'q' => $q,
            'include_inactive' => $includeInactive,
            'limit' => $limit,
        ],
        'suppliers' => $suppliers,
        'counts' => $counts,
        'issues' => $issueRows,
        'tasks' => $tasks,
    ];
}

function supplier_content_progress_fetch_portfolio(array $cfg, array $filters = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    $period = supplier_content_progress_parse_period($filters);
    $refresh = !empty($filters['refresh']);
    $includeInactive = !empty($filters['include_inactive']);
    $priorityFilter = trim((string)($filters['priority'] ?? 'all'));
    $focusFilter = trim((string)($filters['focus'] ?? 'all'));
    $movementFilter = trim((string)($filters['movement'] ?? 'all'));
    $q = supplier_content_progress_lower(trim((string)($filters['q'] ?? '')));
    $priorityClasses = [
        'urgent' => 'red',
        'high' => 'yellow',
        'medium' => 'blue',
        'support' => 'green',
        'idle' => 'gray',
    ];
    if (!isset($priorityClasses[$priorityFilter]) && $priorityFilter !== 'all') {
        $priorityFilter = 'all';
    }
    if (!in_array($focusFilter, ['all', 'upload', 'errors', 'core', 'sellable', 'quality', 'support', 'stock', 'data'], true)) {
        $focusFilter = 'all';
    }
    if (!in_array($movementFilter, ['all', 'improved', 'regressed', 'changed', 'no_delta'], true)) {
        $movementFilter = 'all';
    }
    $suppliers = suppliers_list($includeInactive, $cfg);
    $rows = [];
    $overview = [
        'suppliers_count' => 0,
        'suppliers_total_count' => 0,
        'products_total' => 0,
        'target_products_total' => 0,
        'out_of_stock_total' => 0,
        'uploaded_total' => 0,
        'not_uploaded_total' => 0,
        'sellable_total' => 0,
        'error_total' => 0,
        'avg_progress' => 0.0,
        'avg_quality' => 0.0,
        'priority_urgent_count' => 0,
        'priority_high_count' => 0,
        'priority_medium_count' => 0,
        'priority_support_count' => 0,
        'priority_idle_count' => 0,
        'delta_available_count' => 0,
        'delta_missing_count' => 0,
        'improved_suppliers_count' => 0,
        'regressed_suppliers_count' => 0,
        'uploaded_delta' => 0,
        'sellable_delta' => 0,
        'error_delta' => 0,
        'revision_delta' => 0,
        'avg_progress_delta' => 0.0,
    ];

    foreach ($suppliers as $supplier) {
        $supplierId = (int)($supplier['id'] ?? 0);
        if ($supplierId <= 0) {
            continue;
        }
        if ($refresh || !supplier_content_progress_snapshot_today_exists($supplierId)) {
            try {
                supplier_content_progress_capture_snapshot($supplierId, $cfg);
            } catch (Throwable) {
                // Keep page available even when one supplier has inconsistent data.
            }
        }
        $snapshot = supplier_content_progress_latest_snapshot($supplierId);
        if (!$snapshot) {
            continue;
        }
        $metrics = supplier_content_progress_json($snapshot['metrics_json'] ?? null);
        $delta = supplier_content_progress_snapshot_delta($supplierId, $period);
        $priority = supplier_content_progress_management_priority($snapshot, $metrics, $delta);
        $row = [
            'supplier' => $supplier,
            'snapshot' => $snapshot,
            'metrics' => $metrics,
            'delta' => $delta,
            'priority' => $priority,
        ];
        $rows[] = $row;
    }

    $overview['suppliers_total_count'] = count($rows);
    $rows = array_values(array_filter($rows, static function (array $entry) use ($priorityFilter, $priorityClasses, $focusFilter, $movementFilter, $q): bool {
        $supplier = (array)($entry['supplier'] ?? []);
        $priority = (array)($entry['priority'] ?? []);
        $delta = (array)($entry['delta'] ?? []);

        if ($priorityFilter !== 'all' && (string)($priority['class'] ?? '') !== $priorityClasses[$priorityFilter]) {
            return false;
        }
        if ($focusFilter !== 'all' && (string)($priority['focus_key'] ?? '') !== $focusFilter) {
            return false;
        }
        if ($movementFilter !== 'all') {
            $available = !empty($delta['available']);
            $progressDelta = (float)($delta['content_progress_score'] ?? 0.0);
            if ($movementFilter === 'no_delta' && $available) {
                return false;
            }
            if ($movementFilter === 'improved' && (!$available || $progressDelta <= 0.1)) {
                return false;
            }
            if ($movementFilter === 'regressed' && (!$available || $progressDelta >= -0.1)) {
                return false;
            }
            if ($movementFilter === 'changed' && (!$available || abs($progressDelta) <= 0.1)) {
                return false;
            }
        }
        if ($q !== '') {
            $haystack = supplier_content_progress_lower(implode(' ', [
                (string)($supplier['name'] ?? ''),
                (string)($supplier['supplier_code'] ?? ''),
                (string)($supplier['id'] ?? ''),
            ]));
            if (!str_contains($haystack, $q)) {
                return false;
            }
        }
        return true;
    }));

    $progressDeltaSum = 0.0;
    foreach ($rows as $row) {
        $snapshot = (array)($row['snapshot'] ?? []);
        $metrics = (array)($row['metrics'] ?? []);
        $delta = (array)($row['delta'] ?? []);
        $priority = (array)($row['priority'] ?? []);

        $overview['suppliers_count']++;
        $overview['products_total'] += (int)($snapshot['products_total'] ?? 0);
        $overview['target_products_total'] += (int)($metrics['target_products_total'] ?? 0);
        $overview['out_of_stock_total'] += (int)($metrics['out_of_stock_total'] ?? 0);
        $overview['uploaded_total'] += (int)($snapshot['uploaded_total'] ?? 0);
        $overview['not_uploaded_total'] += (int)($snapshot['not_uploaded_total'] ?? 0);
        $overview['sellable_total'] += (int)($snapshot['sellable_total'] ?? 0);
        $overview['error_total'] += (int)($snapshot['error_total'] ?? 0) + (int)($snapshot['revision_total'] ?? 0);
        $overview['avg_progress'] += (float)($snapshot['content_progress_score'] ?? 0.0);
        $overview['avg_quality'] += (float)($snapshot['avg_card_quality_score'] ?? 0.0);
        $priorityClass = (string)($priority['class'] ?? '');
        if ($priorityClass === 'red') {
            $overview['priority_urgent_count']++;
        } elseif ($priorityClass === 'yellow') {
            $overview['priority_high_count']++;
        } elseif ($priorityClass === 'blue') {
            $overview['priority_medium_count']++;
        } elseif ($priorityClass === 'green') {
            $overview['priority_support_count']++;
        } else {
            $overview['priority_idle_count']++;
        }
        if (!empty($delta['available'])) {
            $overview['delta_available_count']++;
            $overview['uploaded_delta'] += (int)round((float)($delta['uploaded_total'] ?? 0));
            $overview['sellable_delta'] += (int)round((float)($delta['sellable_total'] ?? 0));
            $overview['error_delta'] += (int)round((float)($delta['error_total'] ?? 0));
            $overview['revision_delta'] += (int)round((float)($delta['revision_total'] ?? 0));
            $progressDelta = (float)($delta['content_progress_score'] ?? 0.0);
            $progressDeltaSum += $progressDelta;
            if ($progressDelta > 0.1) {
                $overview['improved_suppliers_count']++;
            } elseif ($progressDelta < -0.1) {
                $overview['regressed_suppliers_count']++;
            }
        } else {
            $overview['delta_missing_count']++;
        }
    }

    if ($overview['suppliers_count'] > 0) {
        $overview['avg_progress'] = supplier_content_progress_round($overview['avg_progress'] / $overview['suppliers_count']);
        $overview['avg_quality'] = supplier_content_progress_round($overview['avg_quality'] / $overview['suppliers_count']);
    }
    if ($overview['delta_available_count'] > 0) {
        $overview['avg_progress_delta'] = round($progressDeltaSum / $overview['delta_available_count'], 2);
    }

    usort($rows, static function (array $a, array $b): int {
        $priorityCmp = ((float)($b['priority']['score'] ?? 0.0)) <=> ((float)($a['priority']['score'] ?? 0.0));
        if ($priorityCmp !== 0) return $priorityCmp;
        $scoreCmp = ((float)($a['snapshot']['content_progress_score'] ?? 0.0)) <=> ((float)($b['snapshot']['content_progress_score'] ?? 0.0));
        if ($scoreCmp !== 0) return $scoreCmp;
        return strcmp((string)($a['supplier']['name'] ?? ''), (string)($b['supplier']['name'] ?? ''));
    });

    return [
        'period' => $period,
        'overview' => $overview,
        'rows' => $rows,
    ];
}

function supplier_content_progress_fetch_supplier(int $supplierId, array $cfg, array $filters = []): array
{
    supplier_content_progress_tables_ensure($cfg);
    $period = supplier_content_progress_parse_period($filters);
    if (!supplier_content_progress_snapshot_today_exists($supplierId) || !empty($filters['refresh'])) {
        supplier_content_progress_capture_snapshot($supplierId, $cfg);
    }
    $supplier = suppliers_get($supplierId, $cfg);
    if (!$supplier) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $snapshot = supplier_content_progress_latest_snapshot($supplierId);
    $metrics = $snapshot ? supplier_content_progress_json($snapshot['metrics_json'] ?? null) : [];
    $delta = supplier_content_progress_snapshot_delta($supplierId, $period);
    $priority = supplier_content_progress_management_priority($snapshot ?: [], $metrics, $delta);
    $deepDelta = supplier_content_progress_deep_delta($supplierId, $period);
    $analytics = supplier_content_progress_fetch_supplier_analytics($supplierId, $cfg);
    $contributions = supplier_content_progress_fetch_supplier_contributions($supplierId, $period, $cfg);

    return [
        'period' => $period,
        'supplier' => $supplier,
        'snapshot' => $snapshot,
        'metrics' => $metrics,
        'priority' => $priority,
        'connections' => supplier_content_progress_resolve_connections($supplierId, $cfg),
        'delta' => $delta,
        'deep_delta' => $deepDelta,
        'analytics' => $analytics,
        'contributions' => $contributions,
    ];
}
