<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_products.php';

function wb_promotions_sql_moscow_now(): string
{
    return "DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)";
}

function wb_promotions_tables_ensure(array $cfg = []): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $schemaCacheDir = dirname(__DIR__) . '/storage/cache';
    $schemaCachePath = $schemaCacheDir . '/wb_promotions_schema_20260625_v10.ready';
    if (is_file($schemaCachePath) && (time() - (int)@filemtime($schemaCachePath)) < 86400) {
        $ready = true;
        return;
    }

    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            promotion_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(32) NULL,
            start_datetime DATETIME NULL,
            end_datetime DATETIME NULL,
            participation_percentage DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            in_action_leftovers INT NOT NULL DEFAULT 0,
            in_action_total INT NOT NULL DEFAULT 0,
            not_in_action_leftovers INT NOT NULL DEFAULT 0,
            not_in_action_total INT NOT NULL DEFAULT 0,
            exception_products_count INT NOT NULL DEFAULT 0,
            advantages_json LONGTEXT NULL,
            ranging_json LONGTEXT NULL,
            raw_json LONGTEXT NULL,
            sync_token VARCHAR(32) NULL,
            synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_promotion (connection_id, promotion_id),
            KEY idx_connection_dates (connection_id, start_datetime, end_datetime),
            KEY idx_connection_synced (connection_id, synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotion_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            promotion_id BIGINT UNSIGNED NOT NULL,
            nm_id BIGINT UNSIGNED NOT NULL,
            vendor_code VARCHAR(191) NULL,
            source_type VARCHAR(32) NOT NULL,
            in_action TINYINT(1) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            plan_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            plan_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            plan_discount_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            raw_json LONGTEXT NULL,
            sync_token VARCHAR(32) NULL,
            synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_promo_nm_source (connection_id, promotion_id, nm_id, source_type),
            KEY idx_connection_nm (connection_id, nm_id),
            KEY idx_connection_vendor (connection_id, vendor_code),
            KEY idx_connection_discount (connection_id, plan_discount),
            KEY idx_connection_synced (connection_id, synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotion_price_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            promotion_id BIGINT UNSIGNED NOT NULL,
            nm_id BIGINT UNSIGNED NOT NULL,
            vendor_code VARCHAR(191) NULL,
            source_type VARCHAR(32) NOT NULL,
            in_action TINYINT(1) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            plan_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            plan_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            plan_discount_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            raw_json LONGTEXT NULL,
            sync_token VARCHAR(32) NULL,
            observed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_promo_nm_source_seen (connection_id, promotion_id, nm_id, source_type, observed_at),
            KEY idx_connection_nm_seen (connection_id, nm_id, observed_at),
            KEY idx_connection_discount_seen (connection_id, plan_discount, observed_at),
            KEY idx_connection_promotion_seen (connection_id, promotion_id, observed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotion_decisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            nm_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            offer_id VARCHAR(191) NOT NULL DEFAULT '',
            vendor_code VARCHAR(191) NOT NULL DEFAULT '',
            promotion_id BIGINT UNSIGNED NULL DEFAULT NULL,
            promotion_name VARCHAR(255) NOT NULL DEFAULT '',
            promotion_type VARCHAR(32) NOT NULL DEFAULT '',
            source_type VARCHAR(32) NOT NULL DEFAULT '',
            decision_status VARCHAR(64) NOT NULL DEFAULT '',
            decision_action VARCHAR(64) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL DEFAULT '',
            current_base_price DECIMAL(12,2) NULL DEFAULT NULL,
            current_discount DECIMAL(10,2) NULL DEFAULT NULL,
            base_list_price DECIMAL(12,2) NULL DEFAULT NULL,
            base_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            min_effective_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            target_effective_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            plan_price DECIMAL(12,2) NULL DEFAULT NULL,
            plan_effective_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            desired_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            desired_effective_sale_price DECIMAL(12,2) NULL DEFAULT NULL,
            desired_discount DECIMAL(10,2) NULL DEFAULT NULL,
            desired_club_discount DECIMAL(10,2) NULL DEFAULT NULL,
            raw_json LONGTEXT NULL,
            decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_feed_nm (connection_id, feed_id, nm_id),
            KEY idx_connection_status (connection_id, decision_status, decision_action),
            KEY idx_connection_promotion (connection_id, promotion_id),
            KEY idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotion_import_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_hash CHAR(40) NOT NULL,
            filename VARCHAR(255) NOT NULL DEFAULT '',
            source_path VARCHAR(1024) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT '',
            promotion_id BIGINT UNSIGNED NULL DEFAULT NULL,
            promotion_title VARCHAR(255) NOT NULL DEFAULT '',
            products_stored INT NOT NULL DEFAULT 0,
            participating_count INT NOT NULL DEFAULT 0,
            candidate_count INT NOT NULL DEFAULT 0,
            error_text TEXT NULL,
            imported_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_file_hash (connection_id, file_hash),
            KEY idx_connection_status (connection_id, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_wb_promotion_download_settings (
            connection_id BIGINT UNSIGNED NOT NULL,
            detail_curl_template LONGTEXT NULL,
            generate_curl_template LONGTEXT NULL,
            curl_template LONGTEXT NULL,
            sample_promotion_id BIGINT UNSIGNED NULL,
            last_test_status VARCHAR(32) NULL,
            last_test_message TEXT NULL,
            updated_by VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (connection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotions',
        'uniq_connection_promotion',
        "ALTER TABLE feedtools_wb_promotions ADD UNIQUE KEY uniq_connection_promotion (connection_id, promotion_id)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_products',
        'uniq_connection_promo_nm_source',
        "ALTER TABLE feedtools_wb_promotion_products ADD UNIQUE KEY uniq_connection_promo_nm_source (connection_id, promotion_id, nm_id, source_type)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_products',
        'idx_connection_nm',
        "ALTER TABLE feedtools_wb_promotion_products ADD KEY idx_connection_nm (connection_id, nm_id)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_price_history',
        'uniq_connection_promo_nm_source_seen',
        "ALTER TABLE feedtools_wb_promotion_price_history ADD UNIQUE KEY uniq_connection_promo_nm_source_seen (connection_id, promotion_id, nm_id, source_type, observed_at)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_price_history',
        'idx_connection_nm_seen',
        "ALTER TABLE feedtools_wb_promotion_price_history ADD KEY idx_connection_nm_seen (connection_id, nm_id, observed_at)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_price_history',
        'idx_connection_observed',
        "ALTER TABLE feedtools_wb_promotion_price_history ADD KEY idx_connection_observed (connection_id, observed_at)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_decisions',
        'uniq_connection_feed_nm',
        "ALTER TABLE feedtools_wb_promotion_decisions ADD UNIQUE KEY uniq_connection_feed_nm (connection_id, feed_id, nm_id)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_wb_promotion_import_files',
        'uniq_connection_file_hash',
        "ALTER TABLE feedtools_wb_promotion_import_files ADD UNIQUE KEY uniq_connection_file_hash (connection_id, file_hash)"
    );
    if (function_exists('ozon_products_table_add_column_if_missing')) {
        ozon_products_table_add_column_if_missing(
            $pdo,
            'feedtools_wb_promotion_download_settings',
            'detail_curl_template',
            "ALTER TABLE feedtools_wb_promotion_download_settings ADD COLUMN detail_curl_template LONGTEXT NULL AFTER connection_id"
        );
        ozon_products_table_add_column_if_missing(
            $pdo,
            'feedtools_wb_promotion_download_settings',
            'generate_curl_template',
            "ALTER TABLE feedtools_wb_promotion_download_settings ADD COLUMN generate_curl_template LONGTEXT NULL AFTER detail_curl_template"
        );
    } else {
        try {
            $pdo->exec("ALTER TABLE feedtools_wb_promotion_download_settings ADD COLUMN detail_curl_template LONGTEXT NULL AFTER connection_id");
        } catch (Throwable $e) {
            // Column already exists on migrated databases.
        }
        try {
            $pdo->exec("ALTER TABLE feedtools_wb_promotion_download_settings ADD COLUMN generate_curl_template LONGTEXT NULL AFTER detail_curl_template");
        } catch (Throwable $e) {
            // Column already exists on migrated databases.
        }
    }

    if (!is_dir($schemaCacheDir)) {
        @mkdir($schemaCacheDir, 0775, true);
    }
    @touch($schemaCachePath);
    $ready = true;
}

function wb_promotions_json($value): ?string
{
    if ($value === null) {
        return null;
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : null;
}

function wb_promotions_normalize_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw);
        if (preg_match('~(?:Z|[+-]\d{2}:?\d{2})$~i', $raw) === 1) {
            $dt = $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
        }
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function wb_promotions_normalize_import_date_boundary($value, bool $endOfDay): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->format('Y-m-d') . ($endOfDay ? ' 23:59:00' : ' 00:00:00');
    } catch (Throwable $e) {
        return null;
    }
}

function wb_promotions_decimal($value): float
{
    return round((float)$value, 2);
}

function wb_promotions_percentile_value(array $values, float $percentile): ?float
{
    $numbers = [];
    foreach ($values as $value) {
        $number = (float)$value;
        if ($number > 0) {
            $numbers[] = $number;
        }
    }
    if (!$numbers) {
        return null;
    }

    sort($numbers, SORT_NUMERIC);
    $count = count($numbers);
    if ($count === 1) {
        return round((float)$numbers[0], 2);
    }

    $percentile = max(0.0, min(1.0, $percentile));
    $position = ($count - 1) * $percentile;
    $lower = (int)floor($position);
    $upper = (int)ceil($position);
    if ($lower === $upper) {
        return round((float)$numbers[$lower], 2);
    }

    $weight = $position - $lower;
    return round(((float)$numbers[$lower] * (1.0 - $weight)) + ((float)$numbers[$upper] * $weight), 2);
}

function wb_promotions_subject_from_raw($raw): string
{
    if (is_array($raw)) {
        return trim((string)($raw['subject'] ?? $raw['subjectName'] ?? $raw['category'] ?? ''));
    }
    $text = trim((string)$raw);
    if ($text === '') {
        return '';
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return '';
    }
    return trim((string)($decoded['subject'] ?? $decoded['subjectName'] ?? $decoded['category'] ?? ''));
}

function wb_promotions_auto_discount_sample_rows(int $connectionId, int $daysBack = 180, int $daysAhead = 60): array
{
    static $cache = [];
    $connectionId = max(0, $connectionId);
    $daysBack = max(7, min(730, $daysBack));
    $daysAhead = max(0, min(365, $daysAhead));
    if ($connectionId <= 0) {
        return [];
    }
    $cacheKey = $connectionId . ':' . $daysBack . ':' . $daysAhead;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    wb_promotions_tables_ensure();
    $autoTypeSql = "(a.type = 'auto' OR a.name LIKE '%автоматические скидки%')";
    $nowMskSql = wb_promotions_sql_moscow_now();
    $sampleLimit = 5000;
    $historyLimit = 12000;
    $selectColumns = "
            %s.connection_id,
            %s.promotion_id,
            %s.nm_id,
            %s.vendor_code,
            %s.source_type,
            %s.in_action,
            %s.price,
            %s.plan_price,
            %s.discount,
            %s.plan_discount,
            %s.raw_json,
            %s AS observed_at,
            a.name AS promotion_name,
            a.type AS promotion_type,
            a.start_datetime,
            a.end_datetime,
            '%s' AS sample_source";
    $currentSql = "
        SELECT
            " . sprintf($selectColumns, 'p', 'p', 'p', 'p', 'p', 'p', 'p', 'p', 'p', 'p', 'p', 'p.synced_at', 'current') . "
        FROM feedtools_wb_promotion_products p
        JOIN feedtools_wb_promotions a
          ON a.connection_id = p.connection_id
         AND a.promotion_id = p.promotion_id
        WHERE p.connection_id = ?
          AND {$autoTypeSql}
          AND p.plan_discount > 0
          AND p.plan_discount < 100
          AND p.plan_price > 0
          AND (a.end_datetime IS NULL OR a.end_datetime >= DATE_SUB({$nowMskSql}, INTERVAL {$daysBack} DAY))
          AND (a.start_datetime IS NULL OR a.start_datetime <= DATE_ADD({$nowMskSql}, INTERVAL {$daysAhead} DAY))
        ORDER BY p.synced_at DESC
        LIMIT {$sampleLimit}
    ";
    $historySql = "
        SELECT
            " . sprintf($selectColumns, 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'h.observed_at', 'history') . "
        FROM (
            SELECT
                connection_id,
                promotion_id,
                nm_id,
                vendor_code,
                source_type,
                in_action,
                price,
                plan_price,
                discount,
                plan_discount,
                raw_json,
                observed_at
            FROM feedtools_wb_promotion_price_history FORCE INDEX (idx_connection_observed)
            WHERE connection_id = ?
              AND plan_discount > 0
              AND plan_discount < 100
              AND plan_price > 0
              AND observed_at >= DATE_SUB(NOW(), INTERVAL {$daysBack} DAY)
            ORDER BY observed_at DESC
            LIMIT {$historyLimit}
        ) h
        JOIN feedtools_wb_promotions a
          ON a.connection_id = h.connection_id
         AND a.promotion_id = h.promotion_id
        WHERE {$autoTypeSql}
          AND (a.start_datetime IS NULL OR a.start_datetime <= DATE_ADD({$nowMskSql}, INTERVAL {$daysAhead} DAY))
        ORDER BY h.observed_at DESC
    ";

    $rows = [];
    $st = db()->prepare($currentSql);
    $st->execute([$connectionId]);
    foreach (($st->fetchAll() ?: []) as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    $st = db()->prepare($historySql);
    $st->execute([$connectionId]);
    foreach (($st->fetchAll() ?: []) as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)($b['observed_at'] ?? ''), (string)($a['observed_at'] ?? ''));
    });
    if (count($rows) > 20000) {
        $rows = array_slice($rows, 0, 20000);
    }
    foreach ($rows as &$row) {
        $row['plan_discount'] = round((float)($row['plan_discount'] ?? 0), 2);
        $row['plan_price'] = round((float)($row['plan_price'] ?? 0), 2);
        $row['subject_key'] = wb_promotions_normalize_text_key(wb_promotions_subject_from_raw($row['raw_json'] ?? ''));
    }
    unset($row);

    $cache[$cacheKey] = $rows;
    return $rows;
}

function wb_promotions_discount_values_from_rows(array $rows): array
{
    $values = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $discount = (float)($row['plan_discount'] ?? 0);
        $planPrice = (float)($row['plan_price'] ?? 0);
        if ($discount > 0 && $discount < 100 && $planPrice > 0) {
            $values[] = $discount;
        }
    }
    return $values;
}

function wb_promotions_discount_stats(array $values): array
{
    $values = array_values(array_filter(array_map('floatval', $values), static fn(float $v): bool => $v > 0.0 && $v < 100.0));
    if (!$values) {
        return [
            'sample_size' => 0,
            'min_seen_percent' => 0.0,
            'p50_percent' => 0.0,
            'p75_percent' => 0.0,
            'p85_percent' => 0.0,
            'max_seen_percent' => 0.0,
        ];
    }
    return [
        'sample_size' => count($values),
        'min_seen_percent' => round(min($values), 2),
        'p50_percent' => wb_promotions_percentile_value($values, 0.50) ?? 0.0,
        'p75_percent' => wb_promotions_percentile_value($values, 0.75) ?? 0.0,
        'p85_percent' => wb_promotions_percentile_value($values, 0.85) ?? 0.0,
        'max_seen_percent' => round(max($values), 2),
    ];
}

function wb_promotions_expected_auto_discount(int $connectionId, int $nmId = 0, float $bufferPercent = 2.0, array $options = []): array
{
    $bufferPercent = max(0.0, min(20.0, $bufferPercent));
    if ($connectionId <= 0) {
        return [
            'discount_percent' => 0.0,
            'base_discount_percent' => 0.0,
            'buffer_percent' => round($bufferPercent, 2),
            'sample_size' => 0,
            'source' => 'none',
            'percentile' => 0,
            'max_seen_percent' => 0.0,
            'min_seen_percent' => 0.0,
            'p50_percent' => 0.0,
            'p75_percent' => 0.0,
            'p85_percent' => 0.0,
            'product_sample_size' => 0,
            'subject_sample_size' => 0,
            'connection_sample_size' => 0,
            'subject' => '',
            'confidence' => 'none',
        ];
    }

    $daysBack = max(7, min(730, (int)($options['days_back'] ?? 180)));
    $daysAhead = max(0, min(365, (int)($options['days_ahead'] ?? 60)));
    $rows = wb_promotions_auto_discount_sample_rows($connectionId, $daysBack, $daysAhead);

    $productRows = $nmId > 0
        ? array_values(array_filter($rows, static fn(array $row): bool => (int)($row['nm_id'] ?? 0) === $nmId))
        : [];
    $productValues = wb_promotions_discount_values_from_rows($productRows);
    $subject = trim((string)($options['subject'] ?? ''));
    if ($subject === '') {
        foreach ($productRows as $row) {
            $subject = wb_promotions_subject_from_raw($row['raw_json'] ?? '');
            if ($subject !== '') {
                break;
            }
        }
    }
    $subjectKey = wb_promotions_normalize_text_key($subject);
    $subjectRows = $subjectKey !== ''
        ? array_values(array_filter($rows, static fn(array $row): bool => (string)($row['subject_key'] ?? '') === $subjectKey))
        : [];
    $subjectValues = wb_promotions_discount_values_from_rows($subjectRows);
    $connectionValues = wb_promotions_discount_values_from_rows($rows);

    $values = $productValues;
    $source = 'product_history_p75';
    $percentile = 75;
    $confidence = count($productValues) >= 4 ? 'high' : 'medium';
    if (count($values) < 2 && count($subjectValues) >= 4) {
        $values = $subjectValues;
        $source = 'subject_history_p80';
        $percentile = 80;
        $confidence = count($subjectValues) >= 12 ? 'medium' : 'low';
    }
    if (count($values) < 2) {
        $values = $connectionValues;
        $source = 'connection_history_p85';
        $percentile = 85;
        $confidence = count($connectionValues) >= 30 ? 'medium' : 'low';
    }

    $base = wb_promotions_percentile_value($values, $percentile / 100.0);
    $stats = wb_promotions_discount_stats($values);
    if ($base === null) {
        return [
            'discount_percent' => 0.0,
            'base_discount_percent' => 0.0,
            'buffer_percent' => round($bufferPercent, 2),
            'sample_size' => 0,
            'source' => 'none',
            'percentile' => 0,
            'max_seen_percent' => 0.0,
            'min_seen_percent' => 0.0,
            'p50_percent' => 0.0,
            'p75_percent' => 0.0,
            'p85_percent' => 0.0,
            'product_sample_size' => count($productValues),
            'subject_sample_size' => count($subjectValues),
            'connection_sample_size' => count($connectionValues),
            'subject' => $subject,
            'confidence' => 'none',
        ];
    }

    return [
        'discount_percent' => round(max(0.0, min(85.0, $base + $bufferPercent)), 2),
        'base_discount_percent' => round($base, 2),
        'buffer_percent' => round($bufferPercent, 2),
        'sample_size' => count($values),
        'source' => $source,
        'percentile' => $percentile,
        'max_seen_percent' => round((float)$stats['max_seen_percent'], 2),
        'min_seen_percent' => round((float)$stats['min_seen_percent'], 2),
        'p50_percent' => round((float)$stats['p50_percent'], 2),
        'p75_percent' => round((float)$stats['p75_percent'], 2),
        'p85_percent' => round((float)$stats['p85_percent'], 2),
        'product_sample_size' => count($productValues),
        'subject_sample_size' => count($subjectValues),
        'connection_sample_size' => count($connectionValues),
        'subject' => $subject,
        'confidence' => $confidence,
    ];
}

function wb_promotions_item_id(array $item): int
{
    foreach (['id', 'nmID', 'nmId', 'nmid'] as $key) {
        if (isset($item[$key]) && is_numeric($item[$key])) {
            return (int)$item[$key];
        }
    }
    return 0;
}

function wb_promotions_upsert_promotion(PDO $pdo, int $connectionId, array $item, string $syncToken, string $syncedAt): void
{
    $promotionId = (int)($item['id'] ?? ($item['promotionID'] ?? 0));
    if ($connectionId <= 0 || $promotionId <= 0) {
        return;
    }
    $existing = null;
    $existingRawArray = [];
    try {
        $existingSt = $pdo->prepare("
            SELECT *
            FROM feedtools_wb_promotions
            WHERE connection_id = ?
              AND promotion_id = ?
            LIMIT 1
        ");
        $existingSt->execute([$connectionId, $promotionId]);
        $existingRow = $existingSt->fetch();
        if (is_array($existingRow)) {
            $existing = $existingRow;
            $decodedRaw = json_decode((string)($existingRow['raw_json'] ?? ''), true);
            if (is_array($decodedRaw)) {
                $existingRawArray = $decodedRaw;
            }
        }
    } catch (Throwable $e) {
        $existing = null;
        $existingRawArray = [];
    }

    $hasValue = static function (array $source, string $key): bool {
        if (!array_key_exists($key, $source)) {
            return false;
        }
        $value = $source[$key];
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return true;
    };
    $existingField = static function (?array $row, string $field, $default = null) {
        if (!is_array($row) || !array_key_exists($field, $row)) {
            return $default;
        }
        $value = $row[$field];
        return $value !== null && $value !== '' ? $value : $default;
    };
    $firstValue = static function (array $source, array $keys, $default = null) use ($hasValue) {
        foreach ($keys as $key) {
            if ($hasValue($source, $key)) {
                return $source[$key];
            }
        }
        return $default;
    };
    $decodeJsonField = static function (?array $row, string $field) {
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string)($row[$field] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    };

    $rawSource = trim((string)($item['rawSource'] ?? ''));
    $isManualXlsx = $rawSource === 'manual_xlsx';

    $hasIncomingPeriodId = false;
    foreach (['periodID', 'periodId', 'period_id'] as $periodKey) {
        if (isset($item[$periodKey]) && is_scalar($item[$periodKey]) && trim((string)$item[$periodKey]) !== '') {
            $hasIncomingPeriodId = true;
            break;
        }
    }
    if (!$hasIncomingPeriodId) {
        $existingPeriod = '';
        if ($existingRawArray) {
            $existingPeriod = wb_promotions_template_value_from_raw_json(
                ['raw_json' => wb_promotions_json($existingRawArray)],
                ['periodID', 'periodId', 'period_id']
            );
        }
        if ($existingPeriod !== '') {
            $item['periodID'] = is_numeric($existingPeriod) ? (int)$existingPeriod : $existingPeriod;
            $item['periodId'] = $item['periodID'];
        }
    }

    $name = trim((string)($item['name'] ?? ('WB action #' . $promotionId)));
    $existingName = trim((string)$existingField($existing, 'name', ''));
    if ($isManualXlsx && $existingName !== '') {
        $name = $existingName;
    }
    $type = trim((string)$firstValue($item, ['type'], $existingField($existing, 'type', '')));
    $startDatetime = wb_promotions_normalize_datetime($firstValue($item, ['startDateTime', 'start_datetime'], null))
        ?: $existingField($existing, 'start_datetime', null);
    $endDatetime = wb_promotions_normalize_datetime($firstValue($item, ['endDateTime', 'end_datetime'], null))
        ?: $existingField($existing, 'end_datetime', null);
    $participationPercentage = wb_promotions_decimal(
        $firstValue($item, ['participationPercentage'], $existingField($existing, 'participation_percentage', 0))
    );
    $inActionLeftovers = (int)$firstValue($item, ['inActionLeftovers', 'inPromoActionLeftovers'], $existingField($existing, 'in_action_leftovers', 0));
    $inActionTotal = (int)$firstValue($item, ['inActionTotal', 'inPromoActionTotal'], $existingField($existing, 'in_action_total', 0));
    $notInActionLeftovers = (int)$firstValue($item, ['notInActionLeftovers', 'notInPromoActionLeftovers'], $existingField($existing, 'not_in_action_leftovers', 0));
    $notInActionTotal = (int)$firstValue($item, ['notInActionTotal', 'notInPromoActionTotal'], $existingField($existing, 'not_in_action_total', 0));
    $exceptionProductsCount = (int)$firstValue($item, ['exceptionProductsCount'], $existingField($existing, 'exception_products_count', 0));
    $advantages = $firstValue($item, ['advantages'], $decodeJsonField($existing, 'advantages_json'));
    $ranging = $firstValue($item, ['ranging'], $decodeJsonField($existing, 'ranging_json'));
    $rawPayload = array_replace($existingRawArray, $item);
    if ($name !== '') {
        $rawPayload['name'] = $name;
    }
    if ($type !== '') {
        $rawPayload['type'] = $type;
    }
    if ($participationPercentage > 0) {
        $rawPayload['participationPercentage'] = $participationPercentage;
    }
    if (is_array($advantages)) {
        $rawPayload['advantages'] = $advantages;
    }
    if (is_array($ranging)) {
        $rawPayload['ranging'] = $ranging;
    }

    $st = $pdo->prepare("
        INSERT INTO feedtools_wb_promotions (
            connection_id, promotion_id, name, type, start_datetime, end_datetime,
            participation_percentage, in_action_leftovers, in_action_total,
            not_in_action_leftovers, not_in_action_total, exception_products_count,
            advantages_json, ranging_json, raw_json, sync_token, synced_at
        ) VALUES (
            :connection_id, :promotion_id, :name, :type, :start_datetime, :end_datetime,
            :participation_percentage, :in_action_leftovers, :in_action_total,
            :not_in_action_leftovers, :not_in_action_total, :exception_products_count,
            :advantages_json, :ranging_json, :raw_json, :sync_token, :synced_at
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            type = VALUES(type),
            start_datetime = COALESCE(VALUES(start_datetime), start_datetime),
            end_datetime = COALESCE(VALUES(end_datetime), end_datetime),
            participation_percentage = VALUES(participation_percentage),
            in_action_leftovers = VALUES(in_action_leftovers),
            in_action_total = VALUES(in_action_total),
            not_in_action_leftovers = VALUES(not_in_action_leftovers),
            not_in_action_total = VALUES(not_in_action_total),
            exception_products_count = VALUES(exception_products_count),
            advantages_json = VALUES(advantages_json),
            ranging_json = VALUES(ranging_json),
            raw_json = VALUES(raw_json),
            sync_token = VALUES(sync_token),
            synced_at = VALUES(synced_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':promotion_id' => $promotionId,
        ':name' => $name,
        ':type' => $type !== '' ? $type : null,
        ':start_datetime' => $startDatetime,
        ':end_datetime' => $endDatetime,
        ':participation_percentage' => $participationPercentage,
        ':in_action_leftovers' => $inActionLeftovers,
        ':in_action_total' => $inActionTotal,
        ':not_in_action_leftovers' => $notInActionLeftovers,
        ':not_in_action_total' => $notInActionTotal,
        ':exception_products_count' => $exceptionProductsCount,
        ':advantages_json' => wb_promotions_json($advantages),
        ':ranging_json' => wb_promotions_json($ranging),
        ':raw_json' => wb_promotions_json($rawPayload),
        ':sync_token' => $syncToken,
        ':synced_at' => $syncedAt,
    ]);
}

function wb_promotions_upsert_product(
    PDO $pdo,
    int $connectionId,
    int $promotionId,
    string $sourceType,
    array $item,
    ?string $vendorCode,
    string $syncToken,
    string $syncedAt
): void {
    $nmId = wb_promotions_item_id($item);
    if ($connectionId <= 0 || $promotionId <= 0 || $nmId <= 0) {
        return;
    }

    $sourceType = $sourceType === 'participating' ? 'participating' : 'candidate';
    $discount = wb_promotions_decimal($item['discount'] ?? 0);
    $planDiscount = wb_promotions_decimal($item['planDiscount'] ?? 0);
    $st = $pdo->prepare("
        INSERT INTO feedtools_wb_promotion_products (
            connection_id, promotion_id, nm_id, vendor_code, source_type, in_action,
            price, plan_price, discount, plan_discount, plan_discount_delta,
            raw_json, sync_token, synced_at
        ) VALUES (
            :connection_id, :promotion_id, :nm_id, :vendor_code, :source_type, :in_action,
            :price, :plan_price, :discount, :plan_discount, :plan_discount_delta,
            :raw_json, :sync_token, :synced_at
        )
        ON DUPLICATE KEY UPDATE
            vendor_code = VALUES(vendor_code),
            in_action = VALUES(in_action),
            price = VALUES(price),
            plan_price = VALUES(plan_price),
            discount = VALUES(discount),
            plan_discount = VALUES(plan_discount),
            plan_discount_delta = VALUES(plan_discount_delta),
            raw_json = VALUES(raw_json),
            sync_token = VALUES(sync_token),
            synced_at = VALUES(synced_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':promotion_id' => $promotionId,
        ':nm_id' => $nmId,
        ':vendor_code' => $vendorCode !== null && $vendorCode !== '' ? $vendorCode : null,
        ':source_type' => $sourceType,
        ':in_action' => !empty($item['inAction']) ? 1 : ($sourceType === 'participating' ? 1 : 0),
        ':price' => wb_promotions_decimal($item['price'] ?? 0),
        ':plan_price' => wb_promotions_decimal($item['planPrice'] ?? 0),
        ':discount' => $discount,
        ':plan_discount' => $planDiscount,
        ':plan_discount_delta' => round($planDiscount - $discount, 2),
        ':raw_json' => wb_promotions_json($item),
        ':sync_token' => $syncToken,
        ':synced_at' => $syncedAt,
    ]);

    $observedAtRaw = wb_promotions_normalize_datetime($syncedAt) ?: date('Y-m-d H:i:s');
    $observedAt = substr($observedAtRaw, 0, 10) . ' 00:00:00';
    $history = $pdo->prepare("
        INSERT INTO feedtools_wb_promotion_price_history (
            connection_id, promotion_id, nm_id, vendor_code, source_type, in_action,
            price, plan_price, discount, plan_discount, plan_discount_delta,
            raw_json, sync_token, observed_at
        ) VALUES (
            :connection_id, :promotion_id, :nm_id, :vendor_code, :source_type, :in_action,
            :price, :plan_price, :discount, :plan_discount, :plan_discount_delta,
            :raw_json, :sync_token, :observed_at
        )
        ON DUPLICATE KEY UPDATE
            vendor_code = VALUES(vendor_code),
            in_action = VALUES(in_action),
            price = VALUES(price),
            plan_price = VALUES(plan_price),
            discount = VALUES(discount),
            plan_discount = VALUES(plan_discount),
            plan_discount_delta = VALUES(plan_discount_delta),
            raw_json = VALUES(raw_json),
            sync_token = VALUES(sync_token)
    ");
    $history->execute([
        ':connection_id' => $connectionId,
        ':promotion_id' => $promotionId,
        ':nm_id' => $nmId,
        ':vendor_code' => $vendorCode !== null && $vendorCode !== '' ? $vendorCode : null,
        ':source_type' => $sourceType,
        ':in_action' => !empty($item['inAction']) ? 1 : ($sourceType === 'participating' ? 1 : 0),
        ':price' => wb_promotions_decimal($item['price'] ?? 0),
        ':plan_price' => wb_promotions_decimal($item['planPrice'] ?? 0),
        ':discount' => $discount,
        ':plan_discount' => $planDiscount,
        ':plan_discount_delta' => round($planDiscount - $discount, 2),
        ':raw_json' => wb_promotions_json($item),
        ':sync_token' => $syncToken,
        ':observed_at' => $observedAt,
    ]);
}

function wb_promotions_finalize_sync(PDO $pdo, int $connectionId, string $syncToken): void
{
    if ($connectionId <= 0 || $syncToken === '') {
        return;
    }

    $st = $pdo->prepare("
        DELETE FROM feedtools_wb_promotions
        WHERE connection_id = ?
          AND (sync_token IS NULL OR sync_token <> ?)
          AND (raw_json IS NULL OR raw_json NOT LIKE '%manual_xlsx%')
    ");
    $st->execute([$connectionId, $syncToken]);

    $st = $pdo->prepare("
        DELETE FROM feedtools_wb_promotion_products
        WHERE connection_id = ?
          AND (sync_token IS NULL OR sync_token <> ?)
          AND (raw_json IS NULL OR raw_json NOT LIKE '%manual_xlsx%')
    ");
    $st->execute([$connectionId, $syncToken]);
}

function wb_promotions_cleanup_manual_products_for_promotion(PDO $pdo, int $connectionId, int $promotionId, string $syncToken): void
{
    if ($connectionId <= 0 || $promotionId <= 0 || $syncToken === '') {
        return;
    }

    $st = $pdo->prepare("
        DELETE FROM feedtools_wb_promotion_products
        WHERE connection_id = ?
          AND promotion_id = ?
          AND (sync_token IS NULL OR sync_token <> ?)
          AND raw_json LIKE '%manual_xlsx%'
    ");
    $st->execute([$connectionId, $promotionId, $syncToken]);
}

function wb_promotions_cleanup_price_history_old(PDO $pdo, array $cfg = [], int $batchSize = 50000, int $maxBatches = 5): int
{
    $days = (int)($cfg['retention']['wb_promotion_price_history_days'] ?? 7);
    $days = max(7, min(365, $days));
    $batchSize = max(1000, min(100000, $batchSize));
    $maxBatches = max(1, min(100, $maxBatches));
    $cutoff = (new DateTimeImmutable('now'))
        ->modify('-' . $days . ' days')
        ->format('Y-m-d H:i:s');

    $connectionStmt = $pdo->prepare("
        SELECT connection_id
        FROM feedtools_wb_promotion_price_history FORCE INDEX (idx_connection_observed)
        WHERE observed_at < ?
        GROUP BY connection_id
        ORDER BY connection_id ASC
    ");
    $connectionStmt->execute([$cutoff]);
    $connectionIds = array_values(array_filter(array_map('intval', $connectionStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    if (!$connectionIds) {
        return 0;
    }

    $delete = $pdo->prepare("
        DELETE FROM feedtools_wb_promotion_price_history
        WHERE connection_id = ?
          AND observed_at < ?
        ORDER BY observed_at ASC
        LIMIT {$batchSize}
    ");
    $deleted = 0;
    $batches = 0;
    do {
        $deletedThisRound = 0;
        foreach ($connectionIds as $connectionId) {
            $delete->execute([$connectionId, $cutoff]);
            $count = $delete->rowCount();
            $deleted += $count;
            $deletedThisRound += $count;
            $batches++;
            if ($batches >= $maxBatches) {
                break 2;
            }
        }
    } while ($deletedThisRound > 0);

    return $deleted;
}

function wb_promotions_sync_summary(int $connectionId, array $cfg = [], float $dangerPlanDiscountPercent = 60.0): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $dangerPlanDiscountPercent = max(0.0, $dangerPlanDiscountPercent);
    $summary = [
        'promotions_count' => 0,
        'auto_promotions_count' => 0,
        'regular_promotions_count' => 0,
        'products_count' => 0,
        'participating_count' => 0,
        'candidate_count' => 0,
        'risky_discount_count' => 0,
        'max_plan_discount' => null,
        'last_synced_at' => null,
    ];

    if ($connectionId <= 0) {
        return $summary;
    }

    $st = db()->prepare("
        SELECT
            COUNT(*) AS promotions_count,
            SUM(CASE WHEN type = 'auto' THEN 1 ELSE 0 END) AS auto_promotions_count,
            SUM(CASE WHEN type <> 'auto' OR type IS NULL THEN 1 ELSE 0 END) AS regular_promotions_count,
            MAX(synced_at) AS last_synced_at
        FROM feedtools_wb_promotions
        WHERE connection_id = ?
    ");
    $st->execute([$connectionId]);
    $row = $st->fetch() ?: [];
    $summary['promotions_count'] = (int)($row['promotions_count'] ?? 0);
    $summary['auto_promotions_count'] = (int)($row['auto_promotions_count'] ?? 0);
    $summary['regular_promotions_count'] = (int)($row['regular_promotions_count'] ?? 0);
    $summary['last_synced_at'] = (string)($row['last_synced_at'] ?? '') ?: null;

    $st = db()->prepare("
        SELECT
            COUNT(*) AS products_count,
            SUM(CASE WHEN source_type = 'participating' THEN 1 ELSE 0 END) AS participating_count,
            SUM(CASE WHEN source_type = 'candidate' THEN 1 ELSE 0 END) AS candidate_count,
            SUM(CASE WHEN plan_discount >= ? THEN 1 ELSE 0 END) AS risky_discount_count,
            MAX(plan_discount) AS max_plan_discount
        FROM feedtools_wb_promotion_products
        WHERE connection_id = ?
    ");
    $st->execute([$dangerPlanDiscountPercent, $connectionId]);
    $row = $st->fetch() ?: [];
    $summary['products_count'] = (int)($row['products_count'] ?? 0);
    $summary['participating_count'] = (int)($row['participating_count'] ?? 0);
    $summary['candidate_count'] = (int)($row['candidate_count'] ?? 0);
    $summary['risky_discount_count'] = (int)($row['risky_discount_count'] ?? 0);
    $summary['max_plan_discount'] = $row['max_plan_discount'] !== null ? (float)$row['max_plan_discount'] : null;

    return $summary;
}

function wb_promotions_decision_summary(int $connectionId, array $cfg = []): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $summary = [
        'total' => 0,
        'selected' => 0,
        'price_only' => 0,
        'upload_to_promotion' => 0,
        'blocked_below_target_margin' => 0,
        'blocked_below_min_price' => 0,
        'none' => 0,
        'last_decided_at' => null,
    ];
    if ($connectionId <= 0) {
        return $summary;
    }

    $st = db()->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN decision_status = 'selected' THEN 1 ELSE 0 END) AS selected,
            SUM(CASE WHEN decision_action = 'price_only' THEN 1 ELSE 0 END) AS price_only,
            SUM(CASE WHEN decision_action = 'upload_to_promotion' THEN 1 ELSE 0 END) AS upload_to_promotion,
            SUM(CASE WHEN decision_status = 'blocked_below_target_margin' THEN 1 ELSE 0 END) AS blocked_below_target_margin,
            SUM(CASE WHEN decision_status = 'blocked_below_min_price' THEN 1 ELSE 0 END) AS blocked_below_min_price,
            SUM(CASE WHEN decision_status = 'none' THEN 1 ELSE 0 END) AS none,
            MAX(decided_at) AS last_decided_at
        FROM feedtools_wb_promotion_decisions
        WHERE connection_id = ?
    ");
    $st->execute([$connectionId]);
    $row = $st->fetch() ?: [];
    foreach (['total', 'selected', 'price_only', 'upload_to_promotion', 'blocked_below_target_margin', 'blocked_below_min_price', 'none'] as $key) {
        $summary[$key] = (int)($row[$key] ?? 0);
    }
    $summary['last_decided_at'] = (string)($row['last_decided_at'] ?? '') ?: null;
    return $summary;
}

function wb_promotions_recent_decisions(int $connectionId, array $cfg = [], int $limit = 30): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $limit = max(1, min(200, $limit));
    if ($connectionId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_wb_promotion_decisions
        WHERE connection_id = ?
        ORDER BY decided_at DESC, updated_at DESC, id DESC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId]);
    return $st->fetchAll() ?: [];
}

function wb_promotions_record_pricing_decision(int $connectionId, int $feedId, array $calc): void
{
    wb_promotions_tables_ensure();
    $connectionId = max(0, $connectionId);
    $feedId = max(0, $feedId);
    $nmId = (int)($calc['nm_id'] ?? 0);
    if ($connectionId <= 0 || $feedId <= 0 || $nmId <= 0) {
        return;
    }

    $decision = is_array($calc['promotion_decision'] ?? null) ? (array)$calc['promotion_decision'] : [];
    $desired = is_array($calc['desired_state'] ?? null) ? (array)$calc['desired_state'] : [];
    $row = is_array($decision['row'] ?? null) ? (array)$decision['row'] : [];
    $status = trim((string)($decision['status'] ?? ($calc['breakdown']['promotion_pricing_status'] ?? 'none')));
    if ($status === '') {
        $status = 'none';
    }
    $action = trim((string)($desired['promotion_action'] ?? ($decision['action'] ?? '')));
    if ($action === '') {
        $action = in_array($status, ['selected', 'future_selected'], true) ? 'price_only' : 'base_price';
    }

    $reason = trim((string)($decision['reason'] ?? ''));
    if ($reason === '') {
        if ($status === 'selected') {
            $reason = 'Подходящая активная акция выбрана по плановой цене и min price.';
        } elseif ($status === 'future_selected') {
            $reason = 'Подходящая будущая акция выбрана по плановой цене и min price.';
        } elseif ($status === 'blocked_below_target_margin') {
            $reason = 'Старое решение: акция была выше min price, но ниже прежнего запаса.';
        } elseif ($status === 'blocked_below_min_price') {
            $reason = 'Плановая цена акции ниже нижней границы с запасом.';
        } elseif ($status === 'none') {
            $reason = 'Нет активной сохраненной акции для товара.';
        }
    }

    $numOrNull = static function ($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float)$value, 2);
    };

    $raw = [
        'decision' => $decision,
        'desired_state' => $desired,
        'warnings' => array_values((array)($calc['warnings'] ?? [])),
        'breakdown' => is_array($calc['breakdown'] ?? null) ? (array)$calc['breakdown'] : [],
    ];

    $st = db()->prepare("
        INSERT INTO feedtools_wb_promotion_decisions (
            connection_id, feed_id, nm_id, offer_id, vendor_code,
            promotion_id, promotion_name, promotion_type, source_type,
            decision_status, decision_action, reason,
            current_base_price, current_discount,
            base_list_price, base_sale_price, min_effective_sale_price, target_effective_sale_price,
            plan_price, plan_effective_sale_price,
            desired_sale_price, desired_effective_sale_price, desired_discount, desired_club_discount,
            raw_json, decided_at
        ) VALUES (
            :connection_id, :feed_id, :nm_id, :offer_id, :vendor_code,
            :promotion_id, :promotion_name, :promotion_type, :source_type,
            :decision_status, :decision_action, :reason,
            :current_base_price, :current_discount,
            :base_list_price, :base_sale_price, :min_effective_sale_price, :target_effective_sale_price,
            :plan_price, :plan_effective_sale_price,
            :desired_sale_price, :desired_effective_sale_price, :desired_discount, :desired_club_discount,
            :raw_json, NOW()
        )
        ON DUPLICATE KEY UPDATE
            offer_id = VALUES(offer_id),
            vendor_code = VALUES(vendor_code),
            promotion_id = VALUES(promotion_id),
            promotion_name = VALUES(promotion_name),
            promotion_type = VALUES(promotion_type),
            source_type = VALUES(source_type),
            decision_status = VALUES(decision_status),
            decision_action = VALUES(decision_action),
            reason = VALUES(reason),
            current_base_price = VALUES(current_base_price),
            current_discount = VALUES(current_discount),
            base_list_price = VALUES(base_list_price),
            base_sale_price = VALUES(base_sale_price),
            min_effective_sale_price = VALUES(min_effective_sale_price),
            target_effective_sale_price = VALUES(target_effective_sale_price),
            plan_price = VALUES(plan_price),
            plan_effective_sale_price = VALUES(plan_effective_sale_price),
            desired_sale_price = VALUES(desired_sale_price),
            desired_effective_sale_price = VALUES(desired_effective_sale_price),
            desired_discount = VALUES(desired_discount),
            desired_club_discount = VALUES(desired_club_discount),
            raw_json = VALUES(raw_json),
            decided_at = VALUES(decided_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':feed_id' => $feedId,
        ':nm_id' => $nmId,
        ':offer_id' => mb_substr((string)($calc['offer_id'] ?? ''), 0, 191, 'UTF-8'),
        ':vendor_code' => mb_substr((string)($calc['vendor_code'] ?? ''), 0, 191, 'UTF-8'),
        ':promotion_id' => (int)($row['promotion_id'] ?? ($desired['promotion_id'] ?? 0)) ?: null,
        ':promotion_name' => mb_substr((string)($row['promotion_name'] ?? ($desired['promotion_name'] ?? '')), 0, 255, 'UTF-8'),
        ':promotion_type' => mb_substr((string)($row['promotion_type'] ?? ($desired['promotion_type'] ?? '')), 0, 32, 'UTF-8'),
        ':source_type' => mb_substr((string)($row['source_type'] ?? ($desired['promotion_source_type'] ?? '')), 0, 32, 'UTF-8'),
        ':decision_status' => mb_substr($status, 0, 64, 'UTF-8'),
        ':decision_action' => mb_substr($action, 0, 64, 'UTF-8'),
        ':reason' => mb_substr($reason, 0, 255, 'UTF-8'),
        ':current_base_price' => $numOrNull($calc['current_price'] ?? null),
        ':current_discount' => $numOrNull($calc['current_discount'] ?? null),
        ':base_list_price' => $numOrNull($calc['recommended_base_list_price'] ?? ($desired['target_list_price'] ?? null)),
        ':base_sale_price' => $numOrNull($calc['recommended_base_sale_price'] ?? ($calc['recommended_base_effective_sale_price'] ?? null)),
        ':min_effective_sale_price' => $numOrNull($calc['recommended_min_effective_sale_price'] ?? ($decision['min_effective_sale_price'] ?? null)),
        ':target_effective_sale_price' => $numOrNull($decision['target_effective_sale_price'] ?? null),
        ':plan_price' => $numOrNull($row['plan_price'] ?? ($desired['promotion_plan_price_before_recalc'] ?? null)),
        ':plan_effective_sale_price' => $numOrNull($decision['row_plan_effective_price'] ?? ($desired['promotion_plan_effective_sale_price'] ?? null)),
        ':desired_sale_price' => $numOrNull($desired['target_sale_price'] ?? null),
        ':desired_effective_sale_price' => $numOrNull($desired['target_effective_sale_price'] ?? null),
        ':desired_discount' => $numOrNull($desired['discount'] ?? null),
        ':desired_club_discount' => $numOrNull($desired['club_discount'] ?? null),
        ':raw_json' => wb_promotions_json($raw),
    ]);
}

function wb_promotions_delete_stale_pricing_decisions(int $connectionId, int $feedId, string $keepUpdatedSince): int
{
    wb_promotions_tables_ensure();
    $connectionId = max(0, $connectionId);
    $feedId = max(0, $feedId);
    $keepUpdatedSince = trim($keepUpdatedSince);
    if ($connectionId <= 0 || $feedId <= 0 || $keepUpdatedSince === '') {
        return 0;
    }

    $st = db()->prepare("
        DELETE FROM feedtools_wb_promotion_decisions
        WHERE connection_id = ?
          AND feed_id = ?
          AND updated_at < ?
    ");
    $st->execute([$connectionId, $feedId, $keepUpdatedSince]);
    return (int)$st->rowCount();
}

function wb_promotions_list(int $connectionId, array $cfg = [], int $limit = 200): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $limit = max(1, min(500, $limit));
    if ($connectionId <= 0) {
        return [];
    }

    $nowMskSql = wb_promotions_sql_moscow_now();
    $st = db()->prepare("
        SELECT
            a.*,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.products_count, 0)
                ELSE COALESCE(a.in_action_total, 0) + COALESCE(a.not_in_action_total, 0)
            END AS products_count,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.participating_count, 0)
                ELSE COALESCE(a.in_action_total, 0)
            END AS participating_count,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.candidate_count, 0)
                ELSE COALESCE(a.not_in_action_total, 0)
            END AS candidate_count,
            p.min_plan_price,
            p.max_plan_price,
            p.max_plan_discount,
            p.last_product_synced_at
        FROM feedtools_wb_promotions a
        LEFT JOIN (
            SELECT
                connection_id,
                promotion_id,
                COUNT(*) AS products_count,
                SUM(CASE WHEN source_type = 'participating' OR in_action = 1 THEN 1 ELSE 0 END) AS participating_count,
                SUM(CASE WHEN source_type = 'candidate' AND in_action = 0 THEN 1 ELSE 0 END) AS candidate_count,
                MIN(NULLIF(plan_price, 0)) AS min_plan_price,
                MAX(plan_price) AS max_plan_price,
                MAX(plan_discount) AS max_plan_discount,
                MAX(synced_at) AS last_product_synced_at
            FROM feedtools_wb_promotion_products
            WHERE connection_id = ?
            GROUP BY connection_id, promotion_id
        ) p
          ON p.connection_id = a.connection_id
         AND p.promotion_id = a.promotion_id
        WHERE a.connection_id = ?
          AND (a.type = 'auto' OR COALESCE(p.products_count, 0) > 0)
          AND (a.end_datetime IS NULL OR a.end_datetime >= {$nowMskSql})
        ORDER BY
            CASE WHEN a.start_datetime IS NULL THEN 1 ELSE 0 END ASC,
            a.start_datetime ASC,
            a.promotion_id ASC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId, $connectionId]);
    return $st->fetchAll() ?: [];
}

function wb_promotions_get(int $connectionId, int $promotionId, array $cfg = []): ?array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $promotionId = max(0, $promotionId);
    if ($connectionId <= 0 || $promotionId <= 0) {
        return null;
    }

    $st = db()->prepare("
        SELECT
            a.*,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.products_count, 0)
                ELSE COALESCE(a.in_action_total, 0) + COALESCE(a.not_in_action_total, 0)
            END AS products_count,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.participating_count, 0)
                ELSE COALESCE(a.in_action_total, 0)
            END AS participating_count,
            CASE
                WHEN COALESCE(p.products_count, 0) > 0 THEN COALESCE(p.candidate_count, 0)
                ELSE COALESCE(a.not_in_action_total, 0)
            END AS candidate_count,
            p.min_plan_price,
            p.max_plan_price,
            p.max_plan_discount,
            p.last_product_synced_at
        FROM feedtools_wb_promotions a
        LEFT JOIN (
            SELECT
                connection_id,
                promotion_id,
                COUNT(*) AS products_count,
                SUM(CASE WHEN source_type = 'participating' OR in_action = 1 THEN 1 ELSE 0 END) AS participating_count,
                SUM(CASE WHEN source_type = 'candidate' AND in_action = 0 THEN 1 ELSE 0 END) AS candidate_count,
                MIN(NULLIF(plan_price, 0)) AS min_plan_price,
                MAX(plan_price) AS max_plan_price,
                MAX(plan_discount) AS max_plan_discount,
                MAX(synced_at) AS last_product_synced_at
            FROM feedtools_wb_promotion_products
            WHERE connection_id = ?
              AND promotion_id = ?
            GROUP BY connection_id, promotion_id
        ) p
          ON p.connection_id = a.connection_id
         AND p.promotion_id = a.promotion_id
        WHERE a.connection_id = ?
          AND a.promotion_id = ?
        LIMIT 1
    ");
    $st->execute([$connectionId, $promotionId, $connectionId, $promotionId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function wb_promotions_delete(int $connectionId, int $promotionId, array $cfg = []): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $promotionId = max(0, $promotionId);
    if ($connectionId <= 0 || $promotionId <= 0) {
        throw new RuntimeException('Не указан ID акции WB для удаления.');
    }

    $pdo = db();
    $st = $pdo->prepare("
        SELECT promotion_id, name, type
        FROM feedtools_wb_promotions
        WHERE connection_id = ?
          AND promotion_id = ?
        LIMIT 1
    ");
    $st->execute([$connectionId, $promotionId]);
    $promotion = $st->fetch();
    if (!is_array($promotion)) {
        throw new RuntimeException('Акция WB #' . $promotionId . ' не найдена в этом подключении.');
    }

    $result = [
        'connection_id' => $connectionId,
        'promotion_id' => $promotionId,
        'name' => (string)($promotion['name'] ?? ''),
        'type' => (string)($promotion['type'] ?? ''),
        'promotions_deleted' => 0,
        'products_deleted' => 0,
        'history_deleted' => 0,
        'decisions_deleted' => 0,
        'import_files_deleted' => 0,
    ];

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare("
            DELETE FROM feedtools_wb_promotion_products
            WHERE connection_id = ?
              AND promotion_id = ?
        ");
        $delete->execute([$connectionId, $promotionId]);
        $result['products_deleted'] = $delete->rowCount();

        $delete = $pdo->prepare("
            DELETE FROM feedtools_wb_promotion_price_history
            WHERE connection_id = ?
              AND promotion_id = ?
        ");
        $delete->execute([$connectionId, $promotionId]);
        $result['history_deleted'] = $delete->rowCount();

        $delete = $pdo->prepare("
            DELETE FROM feedtools_wb_promotion_decisions
            WHERE connection_id = ?
              AND promotion_id = ?
        ");
        $delete->execute([$connectionId, $promotionId]);
        $result['decisions_deleted'] = $delete->rowCount();

        $delete = $pdo->prepare("
            DELETE FROM feedtools_wb_promotion_import_files
            WHERE connection_id = ?
              AND promotion_id = ?
        ");
        $delete->execute([$connectionId, $promotionId]);
        $result['import_files_deleted'] = $delete->rowCount();

        $delete = $pdo->prepare("
            DELETE FROM feedtools_wb_promotions
            WHERE connection_id = ?
              AND promotion_id = ?
        ");
        $delete->execute([$connectionId, $promotionId]);
        $result['promotions_deleted'] = $delete->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function wb_promotions_products_for_promotion(int $connectionId, int $promotionId, array $cfg = [], int $limit = 300): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $promotionId = max(0, $promotionId);
    $limit = max(1, min(1000, $limit));
    if ($connectionId <= 0 || $promotionId <= 0) {
        return [];
    }

    $st = db()->prepare("
        SELECT *
        FROM feedtools_wb_promotion_products
        WHERE connection_id = ?
          AND promotion_id = ?
        ORDER BY
            CASE WHEN source_type = 'participating' OR in_action = 1 THEN 0 ELSE 1 END ASC,
            plan_price ASC,
            plan_discount DESC,
            nm_id ASC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId, $promotionId]);
    return $st->fetchAll() ?: [];
}

function wb_promotions_pricing_decision(
    int $connectionId,
    int $nmId,
    float $maxPlanDiscountPercent,
    array $cfg = [],
    float $minEffectiveSalePrice = 0.0,
    float $clubDiscountPercent = 0.0,
    float $targetEffectiveSalePrice = 0.0,
    int $futurePrepareDays = 0
): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    $nmId = max(0, $nmId);
    $maxPlanDiscountPercent = max(0.0, min(99.0, $maxPlanDiscountPercent));
    $minEffectiveSalePrice = max(0.0, $minEffectiveSalePrice);
    $targetEffectiveSalePrice = $targetEffectiveSalePrice > 0.0
        ? min($minEffectiveSalePrice, $targetEffectiveSalePrice)
        : $minEffectiveSalePrice;
    $clubDiscountPercent = max(0.0, min(31.0, $clubDiscountPercent));
    $clubFactor = max(0.01, 1.0 - ($clubDiscountPercent / 100.0));
    $futurePrepareDays = max(0, min(60, $futurePrepareDays));
    $nowMskSql = wb_promotions_sql_moscow_now();
    if ($connectionId <= 0 || $nmId <= 0) {
        return [
            'status' => 'none',
            'row' => null,
            'all_count' => 0,
            'active_count' => 0,
            'future_count' => 0,
            'eligible_count' => 0,
            'safe_but_below_target_count' => 0,
            'unsafe_count' => 0,
            'future_prepare_days' => $futurePrepareDays,
            'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
            'target_effective_sale_price' => round($targetEffectiveSalePrice, 2),
        ];
    }

    $st = db()->prepare("
        SELECT
            p.*,
            a.name AS promotion_name,
            a.type AS promotion_type,
            a.start_datetime,
            a.end_datetime,
            TIMESTAMPDIFF(MINUTE, COALESCE(p.synced_at, p.updated_at, p.created_at), NOW()) AS data_age_minutes,
            CASE WHEN a.start_datetime IS NULL OR a.start_datetime <= {$nowMskSql} THEN 'active' ELSE 'future' END AS promotion_timing
        FROM feedtools_wb_promotion_products p
        JOIN feedtools_wb_promotions a
          ON a.connection_id = p.connection_id
         AND a.promotion_id = p.promotion_id
        WHERE p.connection_id = ?
          AND p.nm_id = ?
          AND p.plan_discount > 0
          AND p.plan_price > 0
          AND a.end_datetime IS NOT NULL
          AND a.end_datetime >= {$nowMskSql}
          AND (
              a.start_datetime IS NULL
              OR a.start_datetime <= {$nowMskSql}
              OR a.start_datetime <= DATE_ADD({$nowMskSql}, INTERVAL {$futurePrepareDays} DAY)
          )
        ORDER BY
            CASE WHEN a.start_datetime IS NULL OR a.start_datetime <= {$nowMskSql} THEN 0 ELSE 1 END ASC,
            p.plan_price ASC,
            p.plan_discount ASC,
            CASE WHEN p.source_type = 'participating' THEN 0 ELSE 1 END ASC,
            COALESCE(a.start_datetime, '9999-12-31') ASC,
            p.promotion_id ASC
    ");
    $st->execute([$connectionId, $nmId]);
    $rows = $st->fetchAll() ?: [];
    $preparedRows = [];
    $activeRows = [];
    $futureRows = [];
    $staleAutoRows = 0;
    // The official promotions sync runs nightly. Keep its snapshot valid until the
    // next scheduled run, while still refusing data that missed a full cycle.
    $maxActiveAutoAgeMinutes = 30 * 60;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $promotionType = (string)($row['promotion_type'] ?? '');
        $promotionTiming = (string)($row['promotion_timing'] ?? 'active');
        $dataAgeMinutes = isset($row['data_age_minutes']) ? (int)$row['data_age_minutes'] : 0;
        if ($promotionType === 'auto' && $promotionTiming === 'active' && $dataAgeMinutes > $maxActiveAutoAgeMinutes) {
            $staleAutoRows++;
            continue;
        }
        $planPrice = max(0.0, (float)($row['plan_price'] ?? 0));
        $planEffectivePrice = round($planPrice * $clubFactor, 2);
        $row['plan_effective_sale_price'] = $planEffectivePrice;
        $row['plan_above_min_price'] = ($minEffectiveSalePrice <= 0.0 || $planEffectivePrice + 0.01 >= $minEffectiveSalePrice) ? 1 : 0;
        $row['plan_above_target_price'] = ($targetEffectiveSalePrice <= 0.0 || $planEffectivePrice + 0.01 >= $targetEffectiveSalePrice) ? 1 : 0;
        $preparedRows[] = $row;
        if ((string)($row['promotion_timing'] ?? 'active') === 'future') {
            $futureRows[] = $row;
        } else {
            $activeRows[] = $row;
        }
    }
    $rows = $preparedRows;
    $activeCount = count($activeRows);
    $futureCount = count($futureRows);
    if (!$rows) {
        return [
            'status' => 'none',
            'row' => null,
            'all_count' => 0,
            'active_count' => 0,
            'future_count' => 0,
            'eligible_count' => 0,
            'safe_but_below_target_count' => 0,
            'unsafe_count' => 0,
            'stale_auto_count' => $staleAutoRows,
            'future_prepare_days' => $futurePrepareDays,
            'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
            'target_effective_sale_price' => round($targetEffectiveSalePrice, 2),
        ];
    }

    if (!$activeRows) {
        usort($futureRows, static function (array $a, array $b): int {
            $startCmp = strcmp((string)($a['start_datetime'] ?? ''), (string)($b['start_datetime'] ?? ''));
            if ($startCmp !== 0) {
                return $startCmp;
            }
            $priceCmp = (float)($a['plan_effective_sale_price'] ?? 0) <=> (float)($b['plan_effective_sale_price'] ?? 0);
            if ($priceCmp !== 0) {
                return $priceCmp;
            }
            return (int)($a['promotion_id'] ?? 0) <=> (int)($b['promotion_id'] ?? 0);
        });
        $bestFuture = $futureRows[0] ?? null;
        return [
            'status' => 'future_available',
            'row' => $bestFuture,
            'row_plan_effective_price' => is_array($bestFuture) ? round((float)($bestFuture['plan_effective_sale_price'] ?? ($bestFuture['plan_price'] ?? 0)), 2) : null,
            'all_count' => count($rows),
            'active_count' => 0,
            'future_count' => $futureCount,
            'eligible_count' => 0,
            'safe_but_below_target_count' => 0,
            'unsafe_count' => 0,
            'stale_auto_count' => $staleAutoRows,
            'future_prepare_days' => $futurePrepareDays,
            'promotion_timing' => 'future',
            'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
            'target_effective_sale_price' => round($targetEffectiveSalePrice, 2),
            'reason' => 'Есть будущая акция в окне подготовки, но до даты старта она не участвует в текущем расчёте цены. Текущая цена считается по базовой стратегии и прогнозу будущей скидки.',
        ];
    }

    $eligible = [];
    $safeButBelowTarget = [];
    $unsafe = [];
    foreach ($activeRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['plan_above_target_price'])) {
            $eligible[] = $row;
            if (empty($row['plan_above_min_price'])) {
                $safeButBelowTarget[] = $row;
            }
        } else {
            $unsafe[] = $row;
        }
    }

    if (!$eligible) {
        usort($unsafe, static function (array $a, array $b): int {
            $priceCmp = (float)($b['plan_effective_sale_price'] ?? 0) <=> (float)($a['plan_effective_sale_price'] ?? 0);
            if ($priceCmp !== 0) {
                return $priceCmp;
            }
            return (float)($a['plan_discount'] ?? 0) <=> (float)($b['plan_discount'] ?? 0);
        });
        $bestUnsafe = $unsafe[0] ?? $rows[0];
        $bestPlanEffective = round((float)($bestUnsafe['plan_effective_sale_price'] ?? (max(0.0, (float)($bestUnsafe['plan_price'] ?? 0)) * $clubFactor)), 2);
        return [
            'status' => 'blocked_below_min_price',
            'row' => $bestUnsafe,
            'row_plan_effective_price' => $bestPlanEffective,
            'all_count' => count($rows),
            'active_count' => $activeCount,
            'future_count' => $futureCount,
            'eligible_count' => 0,
            'safe_but_below_target_count' => count($safeButBelowTarget),
            'unsafe_count' => count($unsafe),
            'stale_auto_count' => $staleAutoRows,
            'future_prepare_days' => $futurePrepareDays,
            'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
            'target_effective_sale_price' => round($targetEffectiveSalePrice, 2),
            'reason' => 'Плановая цена акции ниже нижней границы с запасом.',
        ];
    }

    usort($eligible, static function (array $a, array $b): int {
        $priceCmp = (float)($a['plan_effective_sale_price'] ?? 0) <=> (float)($b['plan_effective_sale_price'] ?? 0);
        if ($priceCmp !== 0) {
            return $priceCmp;
        }
        $discountCmp = (float)($a['plan_discount'] ?? 0) <=> (float)($b['plan_discount'] ?? 0);
        if ($discountCmp !== 0) {
            return $discountCmp;
        }
        $participatingCmp = (int)((string)($a['source_type'] ?? '') !== 'participating') <=> (int)((string)($b['source_type'] ?? '') !== 'participating');
        if ($participatingCmp !== 0) {
            return $participatingCmp;
        }
        return (int)($a['promotion_id'] ?? 0) <=> (int)($b['promotion_id'] ?? 0);
    });

    $selected = $eligible[0];
    $selectedBelowMinWithinReserve = empty($selected['plan_above_min_price']);
    $planDiscount = (float)($selected['plan_discount'] ?? 0);
    $selectedTiming = (string)($selected['promotion_timing'] ?? 'active');
    return [
        'status' => 'selected',
        'row' => $selected,
        'row_plan_effective_price' => round((float)($selected['plan_effective_sale_price'] ?? ($selected['plan_price'] ?? 0)), 2),
        'all_count' => count($rows),
        'active_count' => $activeCount,
        'future_count' => $futureCount,
        'eligible_count' => count($eligible),
        'safe_but_below_target_count' => count($safeButBelowTarget),
        'unsafe_count' => count($unsafe),
        'stale_auto_count' => $staleAutoRows,
        'future_prepare_days' => $futurePrepareDays,
        'promotion_timing' => $selectedTiming,
        'min_effective_sale_price' => round($minEffectiveSalePrice, 2),
        'target_effective_sale_price' => round($targetEffectiveSalePrice, 2),
        'over_discount_limit' => $maxPlanDiscountPercent > 0 && $planDiscount > $maxPlanDiscountPercent,
        'below_target_margin' => $selectedBelowMinWithinReserve,
        'below_min_within_reserve' => $selectedBelowMinWithinReserve,
        'reason' => $selectedBelowMinWithinReserve
            ? 'Выбрана активная акция: плановая цена ниже min price, но не ниже нижней границы с запасом.'
            : 'Выбрана активная акция с минимальной плановой ценой, которая не ниже нижней границы с запасом.',
    ];
}

function wb_promotions_import_lc(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function wb_promotions_normalize_text_key(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B", '_'], ' ', $value);
    $value = str_replace('ё', 'е', wb_promotions_import_lc($value));
    $value = preg_replace('~[^\p{L}\p{N}]+~u', ' ', (string)$value);
    $value = preg_replace('~\s+~u', ' ', (string)$value);
    return trim((string)$value);
}

function wb_promotions_match_key(string $value): string
{
    $key = wb_promotions_normalize_text_key($value);
    if ($key === '') {
        return '';
    }
    $key = preg_replace('~\b(автоакция|авто акции|автоматические скидки|автоматическая скидка)\b~u', ' ', $key);
    $key = preg_replace('~\b(wb|wildberries)\b~u', ' ', (string)$key);
    $key = preg_replace('~\s+~u', ' ', (string)$key);
    return trim((string)$key);
}

function wb_promotions_token_overlap_score(string $a, string $b): float
{
    $a = wb_promotions_match_key($a);
    $b = wb_promotions_match_key($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        $short = min(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $long = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        return $long > 0 ? max(0.82, min(0.98, $short / $long)) : 0.0;
    }
    $tokensA = array_values(array_filter(preg_split('~\s+~u', $a) ?: [], static fn($v): bool => mb_strlen((string)$v, 'UTF-8') >= 3));
    $tokensB = array_values(array_filter(preg_split('~\s+~u', $b) ?: [], static fn($v): bool => mb_strlen((string)$v, 'UTF-8') >= 3));
    if (!$tokensA || !$tokensB) {
        return 0.0;
    }
    $setA = array_fill_keys($tokensA, true);
    $setB = array_fill_keys($tokensB, true);
    $intersection = count(array_intersect_key($setA, $setB));
    $denominator = max(count($setA), count($setB));
    return $denominator > 0 ? $intersection / $denominator : 0.0;
}

function wb_promotions_clean_string($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_float($value)) {
        $out = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        return $out === '-0' ? '0' : trim($out);
    }
    if (is_int($value)) {
        return (string)$value;
    }
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', (string)$value);
    $value = preg_replace('~\s+~u', ' ', (string)$value);
    return trim((string)$value);
}

function wb_promotions_parse_decimal($value): float
{
    $raw = wb_promotions_clean_string($value);
    if ($raw === '') {
        return 0.0;
    }
    $raw = str_replace(["\xC2\xA0", ' '], '', $raw);
    $raw = str_replace(',', '.', $raw);
    $raw = preg_replace('~[^0-9.\-]+~', '', (string)$raw);
    if ($raw === '' || $raw === '-' || $raw === '.') {
        return 0.0;
    }
    return round((float)$raw, 2);
}

function wb_promotions_guess_title_from_filename(string $filename): string
{
    $base = basename($filename);
    $base = preg_replace('~\.(xlsx|xls)$~iu', '', $base);
    $base = preg_replace('~^(?:wbpromo|promo|promotion|promotionid)[_\-\s]*\d{2,}[_\-\s]+~iu', '', (string)$base);
    while (preg_match('~^\d{8}_\d{6}_+~u', (string)$base)) {
        $base = preg_replace('~^\d{8}_\d{6}_+~u', '', (string)$base, 1);
    }
    $base = preg_replace('~^(?:wbpromo|promo|promotion|promotionid)[_\-\s]*\d{2,}[_\-\s]+~iu', '', (string)$base);
    $base = preg_replace('~^Все\s+товары\s+подходящие\s+для\s+акции[_\s-]*~iu', '', (string)$base);
    $base = preg_replace('~^Товары\s+для\s+исключения\s+из\s+акции[_\s-]*~iu', '', (string)$base);
    $base = preg_replace('~^Товары\s+для\s+акции[_\s-]*~iu', '', (string)$base);
    $base = preg_replace('~[_\s-]*\d{2}\.\d{2}\.\d{4}\s+\d{2}\.\d{2}\.\d{2}$~u', '', (string)$base);
    $base = trim((string)$base);
    if (str_contains($base, '_') && !str_contains($base, ':')) {
        $base = preg_replace('~_~u', ': ', $base, 1);
    }
    $base = str_replace('_', ' ', (string)$base);
    $base = preg_replace('~\s+~u', ' ', (string)$base);
    $base = preg_replace('~\s+:\s+~u', ': ', (string)$base);
    return trim((string)$base);
}

function wb_promotions_manual_promotion_id(string $title): int
{
    $key = wb_promotions_normalize_text_key($title);
    $crc = (int)sprintf('%u', crc32($key !== '' ? $key : 'manual-wb-promotion'));
    return 900000000000 + $crc;
}

function wb_promotions_find_matching_promotion_id(int $connectionId, string $title): int
{
    $titleKey = wb_promotions_normalize_text_key($title);
    if ($connectionId <= 0 || $titleKey === '') {
        return 0;
    }
    $nowMskSql = wb_promotions_sql_moscow_now();
    $st = db()->prepare("
        SELECT promotion_id, name, type, start_datetime, end_datetime
        FROM feedtools_wb_promotions
        WHERE connection_id = ?
        ORDER BY
          CASE WHEN end_datetime IS NULL OR end_datetime >= {$nowMskSql} THEN 0 ELSE 1 END ASC,
          CASE WHEN type = 'auto' THEN 0 ELSE 1 END ASC,
          start_datetime ASC,
          promotion_id ASC
    ");
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as $row) {
        if (wb_promotions_normalize_text_key((string)($row['name'] ?? '')) === $titleKey) {
            return (int)($row['promotion_id'] ?? 0);
        }
    }
    $titleMatchKey = wb_promotions_match_key($title);
    foreach ($rows as $row) {
        if (wb_promotions_match_key((string)($row['name'] ?? '')) === $titleMatchKey) {
            return (int)($row['promotion_id'] ?? 0);
        }
    }

    $bestId = 0;
    $bestScore = 0.0;
    foreach ($rows as $row) {
        $score = wb_promotions_token_overlap_score($title, (string)($row['name'] ?? ''));
        if ((string)($row['type'] ?? '') === 'auto') {
            $score += 0.03;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (int)($row['promotion_id'] ?? 0);
        }
    }
    if ($bestId > 0 && $bestScore >= 0.86) {
        return $bestId;
    }
    return 0;
}

function wb_promotions_find_promotion_dates(int $connectionId, int $promotionId): array
{
    if ($connectionId <= 0 || $promotionId <= 0) {
        return ['start_datetime' => null, 'end_datetime' => null];
    }
    $st = db()->prepare("SELECT start_datetime, end_datetime FROM feedtools_wb_promotions WHERE connection_id = ? AND promotion_id = ? LIMIT 1");
    $st->execute([$connectionId, $promotionId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['start_datetime' => null, 'end_datetime' => null];
    }
    return [
        'start_datetime' => $row['start_datetime'] ?? null,
        'end_datetime' => $row['end_datetime'] ?? null,
    ];
}

function wb_promotions_header_map(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row): array
{
    $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $map = [];
    for ($col = 1; $col <= $highestCol; $col++) {
        $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
        $value = wb_promotions_clean_string($sheet->getCell($addr)->getCalculatedValue());
        $key = wb_promotions_normalize_text_key($value);
        if ($key !== '') {
            $map[$key] = $col;
        }
    }
    return $map;
}

function wb_promotions_find_import_header_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
{
    $required = [
        'артикул wb',
        'плановая цена для акции',
        'текущая розничная цена',
        'загружаемая скидка для участия в акции',
    ];
    for ($row = 1; $row <= min(25, $sheet->getHighestRow()); $row++) {
        $map = wb_promotions_header_map($sheet, $row);
        $ok = true;
        foreach ($required as $key) {
            if (!isset($map[$key])) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return [$row, $map];
        }
    }
    throw new RuntimeException('Не удалось найти строку заголовков в XLSX автоакции WB.');
}

function wb_promotions_cell_value(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $map, string $headerKey, int $row): string
{
    $col = (int)($map[$headerKey] ?? 0);
    if ($col <= 0) {
        return '';
    }
    $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
    $cell = $sheet->getCell($addr);
    $value = wb_promotions_clean_string($cell->getCalculatedValue());
    if ($value === '') {
        $value = wb_promotions_clean_string($cell->getFormattedValue());
    }
    return $value;
}

function wb_promotions_import_xlsx(
    string $path,
    int $connectionId,
    string $originalFilename = '',
    string $promotionTitle = '',
    int $promotionId = 0,
    array $cfg = [],
    string $promotionStart = '',
    string $promotionEnd = ''
): array {
    wb_promotions_tables_ensure($cfg);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для импорта XLSX автоакции WB нужно подключение WB.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Файл XLSX автоакции WB не найден.');
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('Не подключён PhpSpreadsheet: невозможно прочитать XLSX автоакции WB.');
    }

    $promotionTitle = trim($promotionTitle);
    if ($promotionTitle === '') {
        $promotionTitle = wb_promotions_guess_title_from_filename($originalFilename !== '' ? $originalFilename : $path);
    }
    if ($promotionTitle === '') {
        throw new RuntimeException('Не удалось определить название акции WB из файла. Укажи название вручную.');
    }
    $filenamePromotionId = wb_promotions_promotion_id_from_filename($originalFilename !== '' ? $originalFilename : $path);
    if ($promotionId <= 0 && $filenamePromotionId > 0) {
        $promotionId = $filenamePromotionId;
    }
    if ($promotionId <= 0) {
        $promotionId = wb_promotions_find_matching_promotion_id($connectionId, $promotionTitle);
    }
    $promotionIdMatched = $promotionId > 0;
    if ($promotionId <= 0) {
        $promotionId = wb_promotions_manual_promotion_id($promotionTitle);
    }
    $manualStartDatetime = wb_promotions_normalize_import_date_boundary($promotionStart, false);
    $manualEndDatetime = wb_promotions_normalize_import_date_boundary($promotionEnd, true);
    $existingDates = wb_promotions_find_promotion_dates($connectionId, $promotionId);
    $effectiveEndDatetime = $manualEndDatetime ?: ($existingDates['end_datetime'] ?? null);
    if ($effectiveEndDatetime === null) {
        throw new RuntimeException('Укажи дату окончания автоакции WB или сначала синхронизируй календарь, чтобы сервис мог сам сопоставить XLSX с акцией.');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getSheet(0);
    [$headerRow, $map] = wb_promotions_find_import_header_row($sheet);
    $highestRow = $sheet->getHighestRow();

    $syncToken = bin2hex(random_bytes(8));
    $syncedAt = date('Y-m-d H:i:s');
    $pdo = db();
    $seenProducts = 0;
    $storedProducts = 0;
    $participating = 0;
    $candidates = 0;
    $maxPlanDiscount = null;
    $minPlanPrice = null;

    $rawRowsLimit = 20;
    $rawRows = [];
    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $nmId = (int)preg_replace('~\D+~', '', wb_promotions_cell_value($sheet, $map, 'артикул wb', $row));
        if ($nmId <= 0) {
            continue;
        }
        $seenProducts++;
        $vendorCode = wb_promotions_cell_value($sheet, $map, 'артикул поставщика', $row);
        $alreadyRaw = wb_promotions_import_lc(wb_promotions_cell_value($sheet, $map, 'товар уже участвует в акции', $row));
        $inAction = in_array($alreadyRaw, ['да', 'yes', '1', 'true'], true);
        $sourceType = $inAction ? 'participating' : 'candidate';
        $planPrice = wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'плановая цена для акции', $row));
        $planDiscount = wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'загружаемая скидка для участия в акции', $row));
        $item = [
            'id' => $nmId,
            'vendorCode' => $vendorCode,
            'brand' => wb_promotions_cell_value($sheet, $map, 'бренд', $row),
            'subject' => wb_promotions_cell_value($sheet, $map, 'предмет', $row),
            'name' => wb_promotions_cell_value($sheet, $map, 'наименование', $row),
            'barcode' => wb_promotions_cell_value($sheet, $map, 'последний баркод', $row),
            'daysOnSite' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'количество дней на сайте', $row)),
            'turnover' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'оборачиваемость', $row)),
            'wbStock' => (int)wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'остаток товара на складах wb шт', $row)),
            'sellerStock' => (int)wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'остаток товара на складе продавца wb шт', $row)),
            'price' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'текущая розничная цена', $row)),
            'planPrice' => $planPrice,
            'currency' => wb_promotions_cell_value($sheet, $map, 'валюта', $row),
            'autoActionMinPrice' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'минимальная цена для применения скидки по автоакции', $row)),
            'autoActionMinPriceDaysLeft' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'минимальная цена осталось дней', $row)),
            'autoActionDiscountBlock' => wb_promotions_cell_value($sheet, $map, 'блокировка применения скидки по автоакции', $row),
            'autoActionDiscountBlockDaysLeft' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'блокировка изменения скидки для участия в автоакции осталось дней', $row)),
            'discount' => wb_promotions_parse_decimal(wb_promotions_cell_value($sheet, $map, 'текущая скидка на сайте', $row)),
            'planDiscount' => $planDiscount,
            'inAction' => $inAction,
            'status' => wb_promotions_cell_value($sheet, $map, 'статус', $row),
            'source' => 'manual_xlsx',
        ];
        wb_promotions_upsert_product($pdo, $connectionId, $promotionId, $sourceType, $item, $vendorCode !== '' ? $vendorCode : null, $syncToken, $syncedAt);
        $storedProducts++;
        if ($inAction) {
            $participating++;
        } else {
            $candidates++;
        }
        if ($maxPlanDiscount === null || $planDiscount > $maxPlanDiscount) {
            $maxPlanDiscount = $planDiscount;
        }
        if ($planPrice > 0 && ($minPlanPrice === null || $planPrice < $minPlanPrice)) {
            $minPlanPrice = $planPrice;
        }
        if (count($rawRows) < $rawRowsLimit) {
            $rawRows[] = $item;
        }
    }

    wb_promotions_cleanup_manual_products_for_promotion($pdo, $connectionId, $promotionId, $syncToken);

    wb_promotions_upsert_promotion($pdo, $connectionId, [
        'id' => $promotionId,
        'name' => $promotionTitle,
        'type' => 'auto',
        'start_datetime' => $manualStartDatetime ?: ($existingDates['start_datetime'] ?? null),
        'end_datetime' => $manualEndDatetime ?: ($existingDates['end_datetime'] ?? null),
        'inPromoActionTotal' => $participating,
        'notInPromoActionTotal' => $candidates,
        'rawSource' => 'manual_xlsx',
        'originalFilename' => $originalFilename,
        'manualStartDateTime' => $manualStartDatetime,
        'manualEndDateTime' => $manualEndDatetime,
        'headerRow' => $headerRow,
        'productsSeen' => $seenProducts,
        'productsStored' => $storedProducts,
        'promotionIdMatched' => $promotionIdMatched,
        'sampleRows' => $rawRows,
    ], $syncToken, $syncedAt);

    return [
        'connection_id' => $connectionId,
        'promotion_id' => $promotionId,
        'promotion_id_matched' => $promotionIdMatched,
        'promotion_title' => $promotionTitle,
        'header_row' => $headerRow,
        'products_seen' => $seenProducts,
        'products_stored' => $storedProducts,
        'participating_count' => $participating,
        'candidate_count' => $candidates,
        'max_plan_discount' => $maxPlanDiscount,
        'min_plan_price' => $minPlanPrice,
        'start_datetime' => $manualStartDatetime ?: ($existingDates['start_datetime'] ?? null),
        'end_datetime' => $manualEndDatetime ?: ($existingDates['end_datetime'] ?? null),
        'synced_at' => $syncedAt,
    ];
}

function wb_promotions_default_import_inbox_dir(int $connectionId): string
{
    $connectionId = max(0, $connectionId);
    return dirname(__DIR__) . '/storage/wb_promotions/inbox/' . $connectionId;
}

function wb_promotions_resolve_import_dir(string $dir, int $connectionId): string
{
    $dir = trim($dir);
    if ($dir === '') {
        return wb_promotions_default_import_inbox_dir($connectionId);
    }
    if ($dir[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $dir)) {
        return rtrim($dir, "/\\");
    }
    return rtrim(dirname(__DIR__) . '/' . ltrim($dir, "/\\"), "/\\");
}

function wb_promotions_safe_import_filename(string $filename): string
{
    $base = basename($filename);
    $base = preg_replace('~[^\p{L}\p{N}_.() -]+~u', '_', $base);
    $base = preg_replace('~\s+~u', ' ', (string)$base);
    $base = trim((string)$base, " .\t\n\r\0\x0B");
    if ($base === '') {
        $base = 'wb_promotion.xlsx';
    }
    if (!preg_match('~\.(xlsx|xls)$~iu', $base)) {
        $base .= '.xlsx';
    }
    return wb_promotions_limit_filename_bytes($base, 180);
}

function wb_promotions_limit_filename_bytes(string $filename, int $maxBytes = 180): string
{
    $filename = trim($filename);
    $maxBytes = max(40, min(240, $maxBytes));
    if ($filename === '' || strlen($filename) <= $maxBytes) {
        return $filename !== '' ? $filename : 'wb_promotion.xlsx';
    }

    $extension = '';
    $stem = $filename;
    if (preg_match('~(\.[A-Za-z0-9]{1,8})$~', $filename, $m) === 1) {
        $extension = (string)$m[1];
        $stem = substr($filename, 0, -strlen($extension));
    }

    $hash = substr(sha1($filename), 0, 8);
    $suffix = '_' . $hash . $extension;
    $stemBytes = max(1, $maxBytes - strlen($suffix));
    $cut = function_exists('mb_strcut')
        ? mb_strcut($stem, 0, $stemBytes, 'UTF-8')
        : substr($stem, 0, $stemBytes);
    $cut = trim((string)$cut, " ._\t\n\r\0\x0B");
    if ($cut === '') {
        $cut = 'wb_promotion';
    }
    return $cut . $suffix;
}

function wb_promotions_ensure_writable_dir(string $dir): bool
{
    if ($dir === '') {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    @chmod($dir, 0775);
    return is_dir($dir) && is_writable($dir);
}

function wb_promotions_promotion_id_from_filename(string $filename): int
{
    $base = basename(trim($filename));
    if ($base === '') {
        return 0;
    }
    if (preg_match('~(?:^|[_\-\s])(?:wbpromo|promo|promotion|promotionid)[_\-\s]*(\d{2,})~iu', $base, $m) === 1) {
        return max(0, (int)$m[1]);
    }
    return 0;
}

function wb_promotions_import_file_record(int $connectionId, string $fileHash): ?array
{
    if ($connectionId <= 0 || $fileHash === '') {
        return null;
    }
    wb_promotions_tables_ensure();
    $st = db()->prepare("
        SELECT *
        FROM feedtools_wb_promotion_import_files
        WHERE connection_id = ?
          AND file_hash = ?
        LIMIT 1
    ");
    $st->execute([$connectionId, $fileHash]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function wb_promotions_upsert_import_file_record(
    int $connectionId,
    string $fileHash,
    string $filename,
    string $sourcePath,
    string $status,
    array $result = [],
    string $errorText = ''
): void {
    wb_promotions_tables_ensure();
    $st = db()->prepare("
        INSERT INTO feedtools_wb_promotion_import_files (
            connection_id, file_hash, filename, source_path, status,
            promotion_id, promotion_title, products_stored, participating_count, candidate_count,
            error_text, imported_at
        ) VALUES (
            :connection_id, :file_hash, :filename, :source_path, :status,
            :promotion_id, :promotion_title, :products_stored, :participating_count, :candidate_count,
            :error_text, :imported_at
        )
        ON DUPLICATE KEY UPDATE
            filename = VALUES(filename),
            source_path = VALUES(source_path),
            status = VALUES(status),
            promotion_id = VALUES(promotion_id),
            promotion_title = VALUES(promotion_title),
            products_stored = VALUES(products_stored),
            participating_count = VALUES(participating_count),
            candidate_count = VALUES(candidate_count),
            error_text = VALUES(error_text),
            imported_at = VALUES(imported_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':file_hash' => $fileHash,
        ':filename' => mb_substr($filename, 0, 255, 'UTF-8'),
        ':source_path' => mb_substr($sourcePath, 0, 1024, 'UTF-8'),
        ':status' => mb_substr($status, 0, 32, 'UTF-8'),
        ':promotion_id' => !empty($result['promotion_id']) ? (int)$result['promotion_id'] : null,
        ':promotion_title' => mb_substr((string)($result['promotion_title'] ?? ''), 0, 255, 'UTF-8'),
        ':products_stored' => (int)($result['products_stored'] ?? 0),
        ':participating_count' => (int)($result['participating_count'] ?? 0),
        ':candidate_count' => (int)($result['candidate_count'] ?? 0),
        ':error_text' => $errorText !== '' ? mb_substr($errorText, 0, 4000, 'UTF-8') : null,
        ':imported_at' => $status === 'imported' ? date('Y-m-d H:i:s') : null,
    ]);
}

function wb_promotions_unique_destination_path(string $dir, string $filename): string
{
    wb_promotions_ensure_writable_dir($dir);
    $safe = wb_promotions_safe_import_filename($filename);
    $extension = 'xlsx';
    if (preg_match('~\.(xlsx|xls)$~iu', $safe, $m)) {
        $extension = strtolower((string)$m[1]);
    }
    $base = preg_replace('~\.(xlsx|xls)$~iu', '', $safe);
    $candidate = rtrim($dir, "/\\") . '/' . date('Ymd_His') . '_' . $safe;
    $i = 2;
    while (is_file($candidate)) {
        $candidate = rtrim($dir, "/\\") . '/' . date('Ymd_His') . '_' . $base . '_' . $i . '.' . $extension;
        $i++;
    }
    return $candidate;
}

function wb_promotions_move_import_file(string $path, string $dir, string $filename): string
{
    wb_promotions_ensure_writable_dir($dir);
    $dest = wb_promotions_unique_destination_path($dir, $filename);
    if (@rename($path, $dest)) {
        return $dest;
    }
    if (@copy($path, $dest)) {
        @unlink($path);
        return $dest;
    }
    return $path;
}

function wb_promotions_import_xlsx_folder(
    int $connectionId,
    string $inboxDir = '',
    array $cfg = [],
    array $options = []
): array {
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для автоимпорта XLSX WB нужно подключение WB.');
    }

    $inboxDir = wb_promotions_resolve_import_dir($inboxDir, $connectionId);
    $processedDir = (string)($options['processed_dir'] ?? ($inboxDir . '/_processed'));
    $failedDir = (string)($options['failed_dir'] ?? ($inboxDir . '/_failed'));
    $moveProcessed = !array_key_exists('move_processed', $options) || !empty($options['move_processed']);
    $moveFailed = !array_key_exists('move_failed', $options) || !empty($options['move_failed']);
    $minAgeSec = max(0, (int)($options['min_age_sec'] ?? 10));
    $maxFiles = max(0, (int)($options['max_files'] ?? 0));

    if (!is_dir($inboxDir) && !@mkdir($inboxDir, 0775, true) && !is_dir($inboxDir)) {
        throw new RuntimeException('Не удалось создать папку автоимпорта WB: ' . $inboxDir);
    }

    $files = array_values(array_unique(array_merge(
        glob(rtrim($inboxDir, "/\\") . '/*.xlsx') ?: [],
        glob(rtrim($inboxDir, "/\\") . '/*.xls') ?: []
    )));
    usort($files, static fn(string $a, string $b): int => ((int)@filemtime($a) <=> (int)@filemtime($b)) ?: strcmp($a, $b));
    if ($maxFiles > 0) {
        $files = array_slice($files, 0, $maxFiles);
    }

    $summary = [
        'connection_id' => $connectionId,
        'inbox_dir' => $inboxDir,
        'processed_dir' => $processedDir,
        'failed_dir' => $failedDir,
        'files_seen' => count($files),
        'imported' => 0,
        'skipped_recent' => 0,
        'skipped_duplicate' => 0,
        'failed' => 0,
        'products_stored' => 0,
        'participating_count' => 0,
        'candidate_count' => 0,
        'items' => [],
    ];

    foreach ($files as $path) {
        $filename = basename($path);
        $mtime = (int)@filemtime($path);
        if ($minAgeSec > 0 && $mtime > 0 && (time() - $mtime) < $minAgeSec) {
            $summary['skipped_recent']++;
            $summary['items'][] = [
                'filename' => $filename,
                'status' => 'skipped_recent',
                'message' => 'Файл слишком свежий, возможно, ещё скачивается.',
            ];
            continue;
        }
        $hash = is_file($path) ? (string)@sha1_file($path) : '';
        if ($hash === '') {
            $summary['failed']++;
            $summary['items'][] = [
                'filename' => $filename,
                'status' => 'failed',
                'message' => 'Не удалось прочитать файл для хэша.',
            ];
            continue;
        }
        $filenamePromotionId = wb_promotions_promotion_id_from_filename($filename);
        $existing = wb_promotions_import_file_record($connectionId, $hash);
        $existingFilenamePromotionId = is_array($existing)
            ? wb_promotions_promotion_id_from_filename((string)($existing['filename'] ?? ''))
            : 0;
        $duplicateWithoutExplicitId = $filenamePromotionId <= 0;
        $duplicateWithSameExplicitId = $filenamePromotionId > 0
            && (int)($existing['promotion_id'] ?? 0) === $filenamePromotionId
            && $existingFilenamePromotionId === $filenamePromotionId;
        if (
            is_array($existing)
            && (string)($existing['status'] ?? '') === 'imported'
            && ($duplicateWithoutExplicitId || $duplicateWithSameExplicitId)
        ) {
            $summary['skipped_duplicate']++;
            if ($moveProcessed) {
                wb_promotions_move_import_file($path, $processedDir, $filename);
            }
            $summary['items'][] = [
                'filename' => $filename,
                'status' => 'skipped_duplicate',
                'promotion_id' => (int)($existing['promotion_id'] ?? 0),
                'products_stored' => (int)($existing['products_stored'] ?? 0),
            ];
            continue;
        }

        try {
            $result = wb_promotions_import_xlsx($path, $connectionId, $filename, '', 0, $cfg);
            wb_promotions_upsert_import_file_record($connectionId, $hash, $filename, $path, 'imported', $result);
            $summary['imported']++;
            $summary['products_stored'] += (int)($result['products_stored'] ?? 0);
            $summary['participating_count'] += (int)($result['participating_count'] ?? 0);
            $summary['candidate_count'] += (int)($result['candidate_count'] ?? 0);
            $movedTo = $moveProcessed ? wb_promotions_move_import_file($path, $processedDir, $filename) : $path;
            $summary['items'][] = [
                'filename' => $filename,
                'status' => 'imported',
                'moved_to' => $movedTo,
                'promotion_id' => (int)($result['promotion_id'] ?? 0),
                'promotion_title' => (string)($result['promotion_title'] ?? ''),
                'products_stored' => (int)($result['products_stored'] ?? 0),
                'participating_count' => (int)($result['participating_count'] ?? 0),
                'candidate_count' => (int)($result['candidate_count'] ?? 0),
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            wb_promotions_upsert_import_file_record($connectionId, $hash, $filename, $path, 'failed', [], $message);
            $summary['failed']++;
            $movedTo = $moveFailed ? wb_promotions_move_import_file($path, $failedDir, $filename) : $path;
            if ($moveFailed) {
                @file_put_contents($movedTo . '.error.txt', $message . "\n");
            }
            $summary['items'][] = [
                'filename' => $filename,
                'status' => 'failed',
                'moved_to' => $movedTo,
                'message' => $message,
            ];
        }
    }

    $summary['price_history_deleted'] = wb_promotions_cleanup_price_history_old(db(), $cfg);

    return $summary;
}

function wb_promotions_download_settings_default(int $connectionId): array
{
    return [
        'connection_id' => max(0, $connectionId),
        'detail_curl_template' => '',
        'generate_curl_template' => '',
        'curl_template' => '',
        'sample_promotion_id' => 0,
        'last_test_status' => '',
        'last_test_message' => '',
        'updated_by' => '',
        'updated_at' => null,
        'is_saved' => false,
    ];
}

function wb_promotions_download_settings_get(int $connectionId, array $cfg = []): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        return wb_promotions_download_settings_default(0);
    }
    $st = db()->prepare("SELECT * FROM feedtools_wb_promotion_download_settings WHERE connection_id = ? LIMIT 1");
    $st->execute([$connectionId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return wb_promotions_download_settings_default($connectionId);
    }
    $row = $row + wb_promotions_download_settings_default($connectionId);
    $row['sample_promotion_id'] = (int)($row['sample_promotion_id'] ?? 0);
    $row['is_saved'] = true;
    return $row;
}

function wb_promotions_download_settings_set_test_result(
    int $connectionId,
    string $status,
    string $message,
    array $cfg = []
): void {
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        return;
    }
    $status = mb_substr(trim($status), 0, 32, 'UTF-8');
    $message = mb_substr(trim($message), 0, 4000, 'UTF-8');
    $st = db()->prepare("
        UPDATE feedtools_wb_promotion_download_settings
        SET last_test_status = ?, last_test_message = ?
        WHERE connection_id = ?
    ");
    $st->execute([$status !== '' ? $status : null, $message !== '' ? $message : null, $connectionId]);
}

function wb_promotions_template_has_action_placeholder(string $template): bool
{
    return preg_match('~\{(?:promotion_id|promo_id|period_id|periodID|periodId)\}~', $template) === 1;
}

function wb_promotions_template_has_promotion_placeholder(string $template): bool
{
    return preg_match('~\{(?:promotion_id|promo_id)\}~', $template) === 1;
}

function wb_promotions_template_has_download_placeholder(string $template): bool
{
    return preg_match('~\{(?:file_id|report_id|task_id|download_id|export_id|generation_id|download_url)\}~', $template) === 1;
}

function wb_promotions_template_validation_replacements(int $sampleId = 0): array
{
    $id = $sampleId > 0 ? (string)$sampleId : '1234567890';
    return [
        '{promotion_id}' => $id,
        '{promo_id}' => $id,
        '{period_id}' => $id,
        '{periodID}' => $id,
        '{periodId}' => $id,
        '{file_id}' => 'file123',
        '{report_id}' => 'report123',
        '{task_id}' => 'task123',
        '{download_id}' => 'download123',
        '{export_id}' => 'export123',
        '{generation_id}' => 'generation123',
        '{download_url}' => 'https://example.com/report.xlsx',
    ];
}

function wb_promotions_template_replace_sample_id(string $template, int $sampleId): string
{
    if ($template === '' || $sampleId <= 0 || wb_promotions_template_has_action_placeholder($template)) {
        return $template;
    }

    $sample = preg_quote((string)$sampleId, '~');
    $periodPattern = '~((?:periodID|periodId|period_id)(?:"?\s*[:=]\s*"?))' . $sample . '~u';
    $replaced = preg_replace($periodPattern, '$1{period_id}', $template);
    if (is_string($replaced) && $replaced !== $template) {
        return $replaced;
    }

    return str_replace((string)$sampleId, '{promotion_id}', $template);
}

function wb_promotions_template_replace_known_id_params(string $template): string
{
    if ($template === '') {
        return $template;
    }

    // WB periodically changes these names (periodIDs, periodIdList, promoIds, etc.).
    $periodKey = 'period[A-Za-z0-9_]*';
    $promotionKey = '(?:promotion[A-Za-z0-9_]*|promo[A-Za-z0-9_]*)';
    $replaced = preg_replace(
        '~((?:"?' . $periodKey . '"?)(?:\[\])?\s*[:=]\s*"?)\d+~iu',
        '$1{period_id}',
        $template
    );
    if (is_string($replaced)) {
        $template = $replaced;
    }

    $replaced = preg_replace(
        '~((?:"?' . $periodKey . '"?)\s*:\s*)\[[^\]]*\]~iu',
        '$1[{period_id}]',
        $template
    );
    if (is_string($replaced)) {
        $template = $replaced;
    }

    $replaced = preg_replace(
        '~((?:"?' . $promotionKey . '"?)(?:\[\])?\s*[:=]\s*"?)\d+~iu',
        '$1{promotion_id}',
        $template
    );
    if (is_string($replaced)) {
        $template = $replaced;
    }

    $replaced = preg_replace(
        '~((?:"?' . $promotionKey . '"?)\s*:\s*)\[[^\]]*\]~iu',
        '$1[{promotion_id}]',
        $template
    );
    if (is_string($replaced)) {
        $template = $replaced;
    }

    // Some cabinet builds use a generic id/ids field inside these requests.
    if (!wb_promotions_template_has_action_placeholder($template)) {
        $replaced = preg_replace(
            '~((?:[?&]|["\'])(?:id|ids|idList)(?:["\']|\[\])?\s*[:=]\s*(?:\[\s*)?["\']?)\d+~iu',
            '$1{period_id}',
            $template,
            1
        );
        if (is_string($replaced)) {
            $template = $replaced;
        }
    }

    // Fallback for a new cabinet request shape with an unnamed numeric ID in
    // the JSON body or in a URL path segment.
    if (!wb_promotions_template_has_action_placeholder($template)) {
        try {
            $request = wb_promotions_parse_curl_command($template);
            $body = trim((string)($request['body'] ?? ''));
            if ($body !== '' && preg_match_all('~(?<!\d)\d{3,12}(?!\d)~', $body, $matches)) {
                $ids = array_values(array_unique((array)($matches[0] ?? [])));
                if (count($ids) === 1) {
                    $newBody = preg_replace(
                        '~(?<!\d)' . preg_quote((string)$ids[0], '~') . '(?!\d)~',
                        '{period_id}',
                        $body,
                        1
                    );
                    if (is_string($newBody) && $newBody !== $body) {
                        $template = str_replace($body, $newBody, $template);
                    }
                }
            }
            if (!wb_promotions_template_has_action_placeholder($template)) {
                $url = (string)($request['url'] ?? '');
                $newUrl = preg_replace('~/(\d{3,12})(?=/|[?&#]|$)~', '/{period_id}', $url, 1);
                if (is_string($newUrl) && $newUrl !== $url) {
                    $template = str_replace($url, $newUrl, $template);
                }
            }
        } catch (Throwable $ignored) {
            // The regular validation below will return the useful input error.
        }
    }

    return $template;
}

function wb_promotions_template_normalize_period_placeholder(string $template): string
{
    if ($template === '') {
        return $template;
    }

    $template = str_replace(
        ['{{period_id},', '{{periodID},', '{{periodId},'],
        ['{"periodID":{period_id},', '{"periodID":{period_id},', '{"periodID":{period_id},'],
        $template
    );

    $normalized = preg_replace(
        '~([?&])\{(?:period_id|periodID|periodId)\}(?=(&|$|[\'"\s]))~u',
        '$1periodID={period_id}',
        $template
    );
    return is_string($normalized) ? $normalized : $template;
}

function wb_promotions_download_settings_save(
    int $connectionId,
    string $curlTemplate,
    int $samplePromotionId = 0,
    ?string $actor = null,
    array $cfg = [],
    string $generateCurlTemplate = '',
    string $detailCurlTemplate = ''
): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для сохранения шаблона скачивания WB нужно подключение WB.');
    }
    $curlTemplate = trim($curlTemplate);
    $generateCurlTemplate = trim($generateCurlTemplate);
    $detailCurlTemplate = trim($detailCurlTemplate);
    if ($detailCurlTemplate !== '' && !preg_match('~^\s*curl(?:\s|$)~iu', $detailCurlTemplate)) {
        throw new RuntimeException('Нужен cURL-запрос деталей акции из DevTools: команда должна начинаться с curl.');
    }
    if ($generateCurlTemplate !== '' && !preg_match('~^\s*curl(?:\s|$)~iu', $generateCurlTemplate)) {
        throw new RuntimeException('Нужен cURL-запрос формирования файла из DevTools: команда должна начинаться с curl.');
    }
    if ($curlTemplate !== '' && !preg_match('~^\s*curl(?:\s|$)~iu', $curlTemplate)) {
        throw new RuntimeException('Нужен cURL-запрос скачивания файла из DevTools: команда должна начинаться с curl.');
    }
    $samplePromotionId = max(0, $samplePromotionId);
    $detailCurlTemplate = wb_promotions_template_replace_known_id_params(
        wb_promotions_template_replace_sample_id($detailCurlTemplate, $samplePromotionId)
    );
    $generateCurlTemplate = wb_promotions_template_normalize_period_placeholder(
        wb_promotions_template_replace_known_id_params(
            wb_promotions_template_replace_sample_id($generateCurlTemplate, $samplePromotionId)
        )
    );
    $curlTemplate = wb_promotions_template_normalize_period_placeholder(
        wb_promotions_template_replace_known_id_params(
            wb_promotions_template_replace_sample_id($curlTemplate, $samplePromotionId)
        )
    );

    if ($detailCurlTemplate !== '' && !wb_promotions_template_has_promotion_placeholder($detailCurlTemplate)) {
        throw new RuntimeException('Не удалось автоматически найти promoID в запросе деталей акции. Скопируй через Copy as cURL именно запрос promotions/detail и вставь его целиком.');
    }
    if ($generateCurlTemplate !== '' && !wb_promotions_template_has_action_placeholder($generateCurlTemplate)) {
        throw new RuntimeException('Не удалось автоматически найти ID акции в запросе формирования файла. Скопируй через Copy as cURL запрос, который появляется после нажатия «Сформировать файл», и вставь его целиком.');
    }
    if ($curlTemplate !== '' && !wb_promotions_template_has_action_placeholder($curlTemplate) && !wb_promotions_template_has_download_placeholder($curlTemplate)) {
        throw new RuntimeException('Не удалось автоматически найти ID файла или акции в запросе скачивания. Скопируй через Copy as cURL запрос, который появляется после нажатия «Скачать файл», и вставь его целиком.');
    }
    if ($detailCurlTemplate !== '') {
        wb_promotions_parse_curl_command(strtr($detailCurlTemplate, wb_promotions_template_validation_replacements($samplePromotionId)));
    }
    if ($generateCurlTemplate !== '') {
        wb_promotions_parse_curl_command(strtr($generateCurlTemplate, wb_promotions_template_validation_replacements($samplePromotionId)));
    }
    if ($curlTemplate !== '') {
        wb_promotions_parse_curl_command(strtr($curlTemplate, wb_promotions_template_validation_replacements($samplePromotionId)));
    }

    $st = db()->prepare("
        INSERT INTO feedtools_wb_promotion_download_settings (
            connection_id, detail_curl_template, generate_curl_template, curl_template, sample_promotion_id, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            detail_curl_template = VALUES(detail_curl_template),
            generate_curl_template = VALUES(generate_curl_template),
            curl_template = VALUES(curl_template),
            sample_promotion_id = VALUES(sample_promotion_id),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        $connectionId,
        $detailCurlTemplate,
        $generateCurlTemplate,
        $curlTemplate,
        $samplePromotionId > 0 ? $samplePromotionId : null,
        $actor !== null ? mb_substr($actor, 0, 191, 'UTF-8') : null,
    ]);

    return wb_promotions_download_settings_get($connectionId, $cfg);
}

function wb_promotions_split_curl_bundle(string $bundle): array
{
    $bundle = trim(str_replace(["\r\n", "\r"], "\n", $bundle));
    if ($bundle === '') {
        return [];
    }
    $parts = preg_split('~(?=^\s*curl(?:\s|$))~mi', $bundle) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static function (string $part): bool {
        return $part !== '' && preg_match('~^curl(?:\s|$)~i', $part) === 1;
    }));
}

function wb_promotions_classify_curl_bundle(string $bundle): array
{
    $commands = wb_promotions_split_curl_bundle($bundle);
    if (!$commands) {
        throw new RuntimeException('Вставь три команды cURL из DevTools кабинета WB одну за другой.');
    }

    $classified = [
        'detail_curl_template' => '',
        'generate_curl_template' => '',
        'curl_template' => '',
    ];
    $unknown = [];
    foreach ($commands as $index => $command) {
        $request = wb_promotions_parse_curl_command($command);
        $method = strtoupper((string)($request['method'] ?? 'GET'));
        $url = (string)($request['url'] ?? '');
        $parts = parse_url($url);
        $path = strtolower((string)($parts['path'] ?? ''));
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $queryKeys = array_map('strtolower', array_keys($query));
        $body = strtolower((string)($request['body'] ?? ''));

        $target = '';
        if (str_contains($path, '/promotions/detail') || in_array('promoid', $queryKeys, true)) {
            $target = 'detail_curl_template';
        } elseif ($method !== 'GET' && (
            str_contains($body, 'periodid')
            || str_contains($path, '/excel/create')
            || str_contains($path, '/recovery')
        )) {
            $target = 'generate_curl_template';
        } elseif ($method === 'GET' && (
            in_array('periodid', $queryKeys, true)
            || str_contains($path, '/excel')
            || str_contains($path, '/recovery')
        )) {
            $target = 'curl_template';
        }

        if ($target === '') {
            $unknown[] = '#' . ($index + 1) . ' ' . $method . ' ' . ($path !== '' ? $path : $url);
            continue;
        }
        if ($classified[$target] !== '') {
            throw new RuntimeException('Вставлены две команды одного типа. Нужны ровно: детали акции, сформировать файл и скачать файл.');
        }
        $classified[$target] = $command;
    }

    if ($unknown) {
        throw new RuntimeException('Не удалось распознать cURL-команду: ' . implode(', ', $unknown) . '.');
    }
    $missing = [];
    foreach ([
        'detail_curl_template' => 'детали акции',
        'generate_curl_template' => 'сформировать файл',
        'curl_template' => 'скачать файл',
    ] as $key => $label) {
        if ($classified[$key] === '') {
            $missing[] = $label;
        }
    }
    if ($missing) {
        throw new RuntimeException('Не хватает cURL-команд: ' . implode(', ', $missing) . '. Вставь три команды без ручного изменения ID.');
    }

    $classified['commands_count'] = count($commands);
    return $classified;
}

function wb_promotions_curl_tokenize(string $command): array
{
    // Chrome copies multiline cURL commands with a backslash before each line break.
    $command = preg_replace("~\\\\\r?\n[ \t]*~", ' ', $command) ?? $command;
    $tokens = [];
    $buf = '';
    $quote = null;
    $escape = false;
    $len = strlen($command);
    for ($i = 0; $i < $len; $i++) {
        $ch = $command[$i];
        if ($escape) {
            $buf .= $ch;
            $escape = false;
            continue;
        }
        if ($ch === '\\') {
            $escape = true;
            continue;
        }
        if ($quote === "'") {
            if ($ch === "'") {
                $quote = null;
            } else {
                $buf .= $ch;
            }
            continue;
        }
        if ($quote === '"') {
            if ($ch === '"') {
                $quote = null;
            } else {
                $buf .= $ch;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            continue;
        }
        if (ctype_space($ch)) {
            if ($buf !== '') {
                $tokens[] = $buf;
                $buf = '';
            }
            continue;
        }
        $buf .= $ch;
    }
    if ($buf !== '') {
        $tokens[] = $buf;
    }
    return $tokens;
}

function wb_promotions_parse_curl_command(string $command): array
{
    $tokens = wb_promotions_curl_tokenize($command);
    if (!$tokens || strtolower((string)$tokens[0]) !== 'curl') {
        throw new RuntimeException('Нужна команда curl из DevTools.');
    }

    $url = '';
    $method = '';
    $headers = [];
    $body = null;
    $count = count($tokens);
    for ($i = 1; $i < $count; $i++) {
        $token = (string)$tokens[$i];
        $next = static function () use (&$tokens, &$i, $count): string {
            if ($i + 1 >= $count) {
                return '';
            }
            $i++;
            return (string)$tokens[$i];
        };

        if ($token === '-X' || $token === '--request') {
            $method = strtoupper($next());
            continue;
        }
        if (str_starts_with($token, '--request=')) {
            $method = strtoupper(substr($token, 10));
            continue;
        }
        if ($token === '--url') {
            $url = $next();
            continue;
        }
        if (str_starts_with($token, '--url=')) {
            $url = substr($token, 6);
            continue;
        }
        if ($token === '-H' || $token === '--header') {
            $header = $next();
            $pos = strpos($header, ':');
            if ($pos !== false) {
                $name = trim(substr($header, 0, $pos));
                $value = trim(substr($header, $pos + 1));
                if ($name !== '') {
                    $headers[$name] = $value;
                }
            }
            continue;
        }
        if (str_starts_with($token, '--header=')) {
            $header = substr($token, 9);
            $pos = strpos($header, ':');
            if ($pos !== false) {
                $name = trim(substr($header, 0, $pos));
                $value = trim(substr($header, $pos + 1));
                if ($name !== '') {
                    $headers[$name] = $value;
                }
            }
            continue;
        }
        if ($token === '-b' || $token === '--cookie' || $token === '--cookie-jar') {
            $cookie = $next();
            if ($token !== '--cookie-jar' && $cookie !== '') {
                $headers['Cookie'] = isset($headers['Cookie']) && $headers['Cookie'] !== ''
                    ? ($headers['Cookie'] . '; ' . $cookie)
                    : $cookie;
            }
            continue;
        }
        if ($token === '-A' || $token === '--user-agent') {
            $ua = $next();
            if ($ua !== '') {
                $headers['User-Agent'] = $ua;
            }
            continue;
        }
        if ($token === '-e' || $token === '--referer') {
            $referer = $next();
            if ($referer !== '') {
                $headers['Referer'] = $referer;
            }
            continue;
        }
        if (in_array($token, ['-d', '--data', '--data-raw', '--data-binary', '--data-ascii'], true)) {
            $body = $next();
            if ($method === '') {
                $method = 'POST';
            }
            continue;
        }
        foreach (['--data=', '--data-raw=', '--data-binary=', '--data-ascii='] as $prefix) {
            if (str_starts_with($token, $prefix)) {
                $body = substr($token, strlen($prefix));
                if ($method === '') {
                    $method = 'POST';
                }
                continue 2;
            }
        }
        if ($token !== '' && $token[0] !== '-' && $url === '' && preg_match('~^https?://~i', $token)) {
            $url = $token;
            continue;
        }
    }

    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        throw new RuntimeException('В cURL-запросе не найден http/https URL скачивания.');
    }
    if ($method === '') {
        $method = $body !== null ? 'POST' : 'GET';
    }

    return [
        'url' => $url,
        'method' => $method,
        'headers' => $headers,
        'body' => $body,
    ];
}

function wb_promotions_template_value_from_raw_json(array $promotion, array $keys): string
{
    $raw = $promotion['raw_json'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }
    foreach ($keys as $key) {
        if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
            return trim((string)$decoded[$key]);
        }
    }
    return '';
}

function wb_promotions_find_scalar_in_json($node, array $keys): string
{
    if (!is_array($node)) {
        return '';
    }
    $wanted = [];
    foreach ($keys as $key) {
        $wanted[strtolower((string)$key)] = true;
    }
    foreach ($node as $key => $value) {
        if (isset($wanted[strtolower((string)$key)]) && is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            $found = wb_promotions_find_scalar_in_json($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

function wb_promotions_detail_response_period_id(array $response): string
{
    $body = trim((string)($response['bytes'] ?? ''));
    if ($body === '') {
        return '';
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return '';
    }
    return wb_promotions_find_scalar_in_json($decoded, ['periodID', 'periodId', 'period_id']);
}

function wb_promotions_merge_raw_json(array $promotion, array $patch): array
{
    $raw = [];
    if (isset($promotion['raw_json']) && is_string($promotion['raw_json']) && trim($promotion['raw_json']) !== '') {
        $decoded = json_decode($promotion['raw_json'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }
    $raw = array_replace_recursive($raw, $patch);
    $promotion['raw_json'] = wb_promotions_json($raw);
    return $promotion;
}

function wb_promotions_period_id_for_template(array $promotion): string
{
    foreach (['period_id', 'periodID', 'periodId'] as $key) {
        if (isset($promotion[$key]) && is_scalar($promotion[$key]) && trim((string)$promotion[$key]) !== '') {
            return trim((string)$promotion[$key]);
        }
    }
    $rawPeriodId = wb_promotions_template_value_from_raw_json($promotion, ['periodID', 'periodId', 'period_id']);
    if ($rawPeriodId !== '') {
        return $rawPeriodId;
    }

    // WB recovery endpoint names the calendar promotion id "periodID".
    $rawCalendarId = wb_promotions_template_value_from_raw_json($promotion, ['id']);
    if ($rawCalendarId !== '') {
        return $rawCalendarId;
    }

    return (string)(int)($promotion['promotion_id'] ?? $promotion['id'] ?? 0);
}

function wb_promotions_has_explicit_period_id(array $promotion): bool
{
    foreach (['period_id', 'periodID', 'periodId'] as $key) {
        if (isset($promotion[$key]) && is_scalar($promotion[$key]) && trim((string)$promotion[$key]) !== '') {
            return true;
        }
    }
    return wb_promotions_template_value_from_raw_json($promotion, ['periodID', 'periodId', 'period_id']) !== '';
}

function wb_promotions_download_http_error_message(int $status, string $context = 'WB вернул ошибку', string $contentType = ''): string
{
    $message = $context . ': HTTP ' . $status;
    if ($status === 401 || $status === 403) {
        $message .= '. Сохранённый cURL-шаблон кабинета WB не авторизован или устарел. Обнови cURL-шаблоны скачивания автоакций для этого WB-аккаунта.';
    }
    if ($contentType !== '') {
        $message .= ', content-type=' . $contentType;
    }
    return $message;
}

function wb_promotions_download_settings_test(int $connectionId, array $cfg = []): array
{
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для проверки cURL нужен WB-аккаунт.');
    }
    $settings = wb_promotions_download_settings_get($connectionId, $cfg);
    $detailTemplate = trim((string)($settings['detail_curl_template'] ?? ''));
    $generateTemplate = trim((string)($settings['generate_curl_template'] ?? ''));
    $downloadTemplate = trim((string)($settings['curl_template'] ?? ''));
    if ($detailTemplate === '') {
        throw new RuntimeException('Не сохранён cURL запроса деталей акции.');
    }
    if ($generateTemplate === '') {
        throw new RuntimeException('Не сохранён cURL запроса формирования файла.');
    }
    if ($downloadTemplate === '') {
        throw new RuntimeException('Не сохранён cURL запроса скачивания файла.');
    }
    $promotions = wb_promotions_downloadable_promotions($connectionId, $cfg, 45, 1, true);
    if (!$promotions) {
        throw new RuntimeException('В календаре WB сейчас нет автоакции для проверки cURL.');
    }

    $promotion = $promotions[0];
    $command = wb_promotions_download_apply_template($detailTemplate, $promotion);
    $request = wb_promotions_parse_curl_command($command);
    $response = wb_promotions_execute_download_request($request, 45, 2000000);
    $httpStatus = (int)($response['status'] ?? 0);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(wb_promotions_download_http_error_message(
            $httpStatus,
            'Проверка cURL не пройдена',
            (string)($response['content_type'] ?? '')
        ));
    }
    $periodId = wb_promotions_detail_response_period_id($response);
    if ($periodId === '') {
        throw new RuntimeException('Авторизация сработала, но WB не вернул periodID в деталях акции. Проверь, что вставлен cURL запроса promotions/detail.');
    }

    $promotion['periodID'] = is_numeric($periodId) ? (int)$periodId : $periodId;
    $promotion['periodId'] = $promotion['periodID'];
    $generateCommand = wb_promotions_download_apply_template($generateTemplate, $promotion);
    $generateRequest = wb_promotions_parse_curl_command($generateCommand);
    $generateResponse = wb_promotions_execute_download_request($generateRequest, 90, 5000000);
    $generateStatus = (int)($generateResponse['status'] ?? 0);
    if ($generateStatus < 200 || $generateStatus >= 300) {
        throw new RuntimeException(wb_promotions_download_http_error_message(
            $generateStatus,
            'Детали акции получены, но WB не сформировал файл',
            (string)($generateResponse['content_type'] ?? '')
        ));
    }

    $templateValues = wb_promotions_response_template_values($generateResponse);
    $downloadResponse = null;
    $downloadError = '';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        usleep($attempt === 1 ? 2500000 : 1500000);
        $downloadCommand = wb_promotions_download_apply_template($downloadTemplate, $promotion, $templateValues);
        $downloadRequest = wb_promotions_parse_curl_command($downloadCommand);
        $downloadResponse = wb_promotions_execute_download_request($downloadRequest, 90, 50000000);
        $downloadStatus = (int)($downloadResponse['status'] ?? 0);
        if ($downloadStatus < 200 || $downloadStatus >= 300) {
            $downloadError = wb_promotions_download_http_error_message(
                $downloadStatus,
                'Файл сформирован, но WB не разрешил его скачать',
                (string)($downloadResponse['content_type'] ?? '')
            );
            continue;
        }
        if (!empty($downloadResponse['is_spreadsheet'])) {
            $downloadError = '';
            break;
        }
        $downloadError = 'WB вернул не Excel-файл после формирования отчёта.';
    }
    if (!is_array($downloadResponse) || $downloadError !== '') {
        throw new RuntimeException($downloadError !== '' ? $downloadError : 'WB не вернул Excel-файл.');
    }

    return [
        'status' => 'ok',
        'message' => 'Все три запроса работают: акция найдена, отчёт сформирован, Excel-файл получен.',
        'http_status' => $httpStatus,
        'generate_http_status' => $generateStatus,
        'download_http_status' => (int)($downloadResponse['status'] ?? 0),
        'promotion_id' => (int)($promotion['promotion_id'] ?? 0),
        'period_id_found' => true,
        'spreadsheet_received' => true,
    ];
}

function wb_promotions_enrich_promotions_with_period_ids(
    int $connectionId,
    array $promotions,
    string $detailCurlTemplate,
    array $cfg = [],
    array $options = [],
    ?callable $log = null
): array {
    $connectionId = max(0, $connectionId);
    $detailCurlTemplate = trim($detailCurlTemplate);
    if ($connectionId <= 0 || $detailCurlTemplate === '') {
        return $promotions;
    }
    if (!wb_promotions_template_has_promotion_placeholder($detailCurlTemplate)) {
        throw new RuntimeException('В cURL-шаблоне деталей акции нужен {promotion_id} или {promo_id}.');
    }

    $timeoutSec = max(10, min(120, (int)($options['timeout_sec'] ?? 45)));
    $maxBytes = max(10000, min(5000000, (int)($options['max_bytes'] ?? 2000000)));
    $pdo = db();
    $updated = [];
    $found = 0;
    $failed = 0;

    foreach ($promotions as $promotion) {
        if (!is_array($promotion)) {
            $updated[] = $promotion;
            continue;
        }
        if (wb_promotions_has_explicit_period_id($promotion)) {
            $updated[] = $promotion;
            continue;
        }
        $promotionId = (int)($promotion['promotion_id'] ?? $promotion['id'] ?? 0);
        if ($promotionId <= 0) {
            $updated[] = $promotion;
            continue;
        }
        try {
            $command = wb_promotions_download_apply_template($detailCurlTemplate, $promotion);
            $request = wb_promotions_parse_curl_command($command);
            $response = wb_promotions_execute_download_request($request, $timeoutSec, $maxBytes);
            $status = (int)($response['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(wb_promotions_download_http_error_message(
                    $status,
                    'HTTP-ошибка при получении деталей акции',
                    (string)($response['content_type'] ?? '')
                ));
            }
            $periodId = wb_promotions_detail_response_period_id($response);
            if ($periodId === '') {
                throw new RuntimeException('periodID не найден в JSON ответа');
            }
            $promotion['periodID'] = is_numeric($periodId) ? (int)$periodId : $periodId;
            $promotion['periodId'] = $promotion['periodID'];
            $promotion = wb_promotions_merge_raw_json($promotion, [
                'periodID' => $promotion['periodID'],
                'periodId' => $promotion['periodID'],
                'detail_period_source' => 'cabinet_detail',
                'detail_synced_at' => date('Y-m-d H:i:s'),
            ]);
            $st = $pdo->prepare("
                UPDATE feedtools_wb_promotions
                SET raw_json = ?, updated_at = CURRENT_TIMESTAMP
                WHERE connection_id = ? AND promotion_id = ?
            ");
            $st->execute([(string)$promotion['raw_json'], $connectionId, $promotionId]);
            $found++;
            if ($log) {
                $log("period detail {$promotionId}: periodID={$periodId}\n");
            }
        } catch (Throwable $e) {
            $failed++;
            if ($log) {
                $log("period detail failed {$promotionId}: " . $e->getMessage() . "\n");
            }
        }
        $updated[] = $promotion;
    }

    if ($log) {
        $log("period detail summary: found={$found}, failed={$failed}\n");
    }
    return $updated;
}

function wb_promotions_download_apply_template(string $template, array $promotion, array $extraReplacements = []): string
{
    $template = wb_promotions_template_normalize_period_placeholder($template);
    $promotionId = (string)(int)($promotion['promotion_id'] ?? $promotion['id'] ?? 0);
    $periodId = wb_promotions_period_id_for_template($promotion);
    $name = (string)($promotion['name'] ?? $promotion['promotion_name'] ?? '');
    $start = (string)($promotion['start_datetime'] ?? '');
    $end = (string)($promotion['end_datetime'] ?? '');
    $replacements = [
        '{promotion_id}' => $promotionId,
        '{promo_id}' => $promotionId,
        '{period_id}' => $periodId,
        '{periodID}' => $periodId,
        '{periodId}' => $periodId,
        '{promotion_name}' => $name,
        '{promotion_name_url}' => rawurlencode($name),
        '{date_from}' => $start !== '' ? substr($start, 0, 10) : '',
        '{date_to}' => $end !== '' ? substr($end, 0, 10) : '',
    ];
    foreach ($extraReplacements as $key => $value) {
        $key = (string)$key;
        if ($key === '') {
            continue;
        }
        if ($key[0] !== '{') {
            $key = '{' . $key . '}';
        }
        $replacements[$key] = (string)$value;
    }
    return strtr($template, $replacements);
}

function wb_promotions_response_template_values(array $response): array
{
    $values = [];
    $body = trim((string)($response['bytes'] ?? ''));
    if ($body === '') {
        return $values;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $values;
    }

    $walk = static function ($node) use (&$walk, &$values): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walk($value);
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $rawKey = (string)$key;
            $normKey = strtolower(preg_replace('~[^a-z0-9]+~i', '', $rawKey) ?? '');
            $rawValue = trim((string)$value);
            if ($rawValue === '') {
                continue;
            }
            $target = '';
            if (in_array($normKey, ['fileid', 'file'], true)) {
                $target = 'file_id';
            } elseif (in_array($normKey, ['reportid', 'report'], true)) {
                $target = 'report_id';
            } elseif (in_array($normKey, ['taskid', 'task'], true)) {
                $target = 'task_id';
            } elseif (in_array($normKey, ['downloadid', 'download'], true)) {
                $target = 'download_id';
            } elseif (in_array($normKey, ['exportid', 'export'], true)) {
                $target = 'export_id';
            } elseif ($normKey === 'url' || $normKey === 'downloadurl') {
                $target = 'download_url';
            } elseif ($normKey === 'id') {
                $target = 'generation_id';
            }
            if ($target !== '' && !isset($values[$target])) {
                $values[$target] = $rawValue;
            }
            if ($normKey === 'id' && !isset($values['file_id']) && preg_match('~^[A-Za-z0-9._:-]{3,}$~', $rawValue)) {
                foreach (['file_id', 'report_id', 'task_id', 'download_id', 'export_id'] as $fallbackKey) {
                    $values[$fallbackKey] = $values[$fallbackKey] ?? $rawValue;
                }
            }
        }
    };
    $walk($decoded);

    return $values;
}

function wb_promotions_is_spreadsheet_response(string $bytes, string $contentType = '', string $filename = ''): bool
{
    if ($bytes === '') {
        return false;
    }
    $prefix = substr($bytes, 0, 8);
    if (str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "\xD0\xCF\x11\xE0")) {
        return true;
    }
    $contentType = strtolower($contentType);
    if (str_contains($contentType, 'spreadsheet') || str_contains($contentType, 'excel') || str_contains($contentType, 'octet-stream')) {
        return true;
    }
    return preg_match('~\.(xlsx|xls)$~iu', $filename) === 1;
}

function wb_promotions_filename_from_content_disposition(string $header): string
{
    if ($header === '') {
        return '';
    }
    if (preg_match("~filename\\*=UTF-8''([^;]+)~i", $header, $m)) {
        return rawurldecode(trim((string)$m[1], " \t\n\r\"'"));
    }
    if (preg_match('~filename="?([^";]+)"?~i', $header, $m)) {
        return trim((string)$m[1], " \t\n\r\"'");
    }
    return '';
}

function wb_promotions_download_filename(array $promotion, string $contentDisposition = '', string $fallbackExtension = 'xlsx'): string
{
    $name = (string)($promotion['name'] ?? $promotion['promotion_name'] ?? ('Акция ' . (int)($promotion['promotion_id'] ?? 0)));
    $promotionId = (int)($promotion['promotion_id'] ?? $promotion['id'] ?? 0);
    $fromHeader = wb_promotions_filename_from_content_disposition($contentDisposition);
    if ($fromHeader !== '' && wb_promotions_token_overlap_score(wb_promotions_guess_title_from_filename($fromHeader), $name) >= 0.80) {
        $filename = wb_promotions_safe_import_filename($fromHeader);
    } else {
        $filename = wb_promotions_safe_import_filename('Все товары подходящие для акции_' . $name . '_' . date('d.m.Y H.i.s') . '.' . $fallbackExtension);
    }
    if ($promotionId > 0 && wb_promotions_promotion_id_from_filename($filename) !== $promotionId) {
        $filename = wb_promotions_safe_import_filename('wbpromo_' . $promotionId . '__' . $filename);
    }
    return $filename;
}

function wb_promotions_extract_json_spreadsheet_response(string $bytes, string &$contentType, string &$contentDisposition): string
{
    if ($bytes === '' || !str_contains(strtolower($contentType), 'json')) {
        return $bytes;
    }
    $decoded = json_decode($bytes, true);
    if (!is_array($decoded)) {
        return $bytes;
    }

    $candidates = [];
    foreach ([
        ['data', 'file'],
        ['data', 'content'],
        ['data', 'base64'],
        ['file'],
        ['content'],
        ['base64'],
    ] as $path) {
        $value = $decoded;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                $value = null;
                break;
            }
            $value = $value[$key];
        }
        if (is_string($value) && trim($value) !== '') {
            $candidates[] = trim($value);
        }
    }

    foreach ($candidates as $candidate) {
        if (preg_match('~^data:[^;]+;base64,(.+)$~s', $candidate, $m)) {
            $candidate = trim((string)$m[1]);
        }
        $candidate = preg_replace('~\s+~', '', $candidate);
        if (!is_string($candidate) || strlen($candidate) < 32) {
            continue;
        }
        $fileBytes = base64_decode($candidate, true);
        if (!is_string($fileBytes) || $fileBytes === '') {
            continue;
        }
        if (!wb_promotions_is_spreadsheet_response($fileBytes, '', 'download.xlsx')) {
            continue;
        }
        $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if ($contentDisposition === '') {
            $filename = '';
            foreach (['filename', 'fileName', 'name'] as $key) {
                $candidateName = (string)($decoded['data'][$key] ?? $decoded[$key] ?? '');
                if ($candidateName !== '') {
                    $filename = $candidateName;
                    break;
                }
            }
            if ($filename !== '') {
                $contentDisposition = 'attachment; filename="' . wb_promotions_safe_import_filename($filename) . '"';
            }
        }
        return $fileBytes;
    }

    return $bytes;
}

function wb_promotions_execute_download_request(array $request, int $timeoutSec = 120, int $maxBytes = 50000000): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for WB cabinet download automation.');
    }
    $url = (string)($request['url'] ?? '');
    $method = strtoupper((string)($request['method'] ?? 'GET'));
    $body = array_key_exists('body', $request) ? $request['body'] : null;
    $headers = is_array($request['headers'] ?? null) ? (array)$request['headers'] : [];
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $name = trim((string)$name);
        $lower = strtolower($name);
        if ($name === '' || str_starts_with($name, ':') || in_array($lower, ['content-length', 'host', 'accept-encoding'], true)) {
            continue;
        }
        $headerLines[] = $name . ': ' . (string)$value;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => max(10, $timeoutSec),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_USERAGENT => (string)($headers['User-Agent'] ?? $headers['user-agent'] ?? 'FeedTools WB Promotion Downloader'),
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Ошибка скачивания XLS/XLSX WB: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $headerText = substr((string)$raw, 0, $headerSize);
    $bytes = substr((string)$raw, $headerSize);
    if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
        throw new RuntimeException('Ответ WB слишком большой для XLS/XLSX автоакции: ' . strlen($bytes) . ' байт.');
    }
    $contentDisposition = '';
    if (preg_match_all('~^content-disposition:\s*(.+)$~im', $headerText, $matches) && !empty($matches[1])) {
        $contentDisposition = trim((string)end($matches[1]));
    }
    $bytes = wb_promotions_extract_json_spreadsheet_response($bytes, $contentType, $contentDisposition);
    if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
        throw new RuntimeException('Ответ WB слишком большой для XLS/XLSX автоакции: ' . strlen($bytes) . ' байт.');
    }

    return [
        'status' => $status,
        'content_type' => $contentType,
        'content_disposition' => $contentDisposition,
        'effective_url' => $effectiveUrl,
        'bytes' => $bytes,
        'bytes_len' => strlen($bytes),
        'is_spreadsheet' => wb_promotions_is_spreadsheet_response($bytes, $contentType, wb_promotions_filename_from_content_disposition($contentDisposition)),
    ];
}

function wb_promotions_downloadable_promotions(int $connectionId, array $cfg = [], int $daysAhead = 45, int $limit = 50, bool $onlyAuto = true): array
{
    wb_promotions_tables_ensure($cfg);
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        return [];
    }
    $daysAhead = max(0, min(365, $daysAhead));
    $limit = max(1, min(500, $limit));
    $typeSql = $onlyAuto ? "AND (type = 'auto' OR name LIKE '%автоматические скидки%')" : '';
    $nowMskSql = wb_promotions_sql_moscow_now();
    $st = db()->prepare("
        SELECT promotion_id, name, type, start_datetime, end_datetime, raw_json, synced_at
        FROM feedtools_wb_promotions
        WHERE connection_id = ?
          {$typeSql}
          AND (end_datetime IS NULL OR end_datetime >= {$nowMskSql})
          AND (start_datetime IS NULL OR start_datetime <= DATE_ADD({$nowMskSql}, INTERVAL ? DAY))
          AND (promotion_id < 900000000000 OR raw_json IS NULL OR raw_json NOT LIKE '%manual_xlsx%')
        ORDER BY
          CASE
            WHEN (start_datetime IS NULL OR start_datetime <= {$nowMskSql}) AND (end_datetime IS NULL OR end_datetime >= {$nowMskSql}) THEN 0
            ELSE 1
          END ASC,
          CASE WHEN start_datetime IS NULL THEN 1 ELSE 0 END ASC,
          start_datetime ASC,
          promotion_id ASC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId, $daysAhead]);
    return array_values(array_filter($st->fetchAll() ?: [], 'is_array'));
}

function wb_promotions_download_xlsx_for_promotions(
    int $connectionId,
    array $promotions,
    string $curlTemplate,
    string $inboxDir = '',
    array $cfg = [],
    array $options = [],
    ?callable $log = null
): array {
    $connectionId = max(0, $connectionId);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для скачивания XLS/XLSX автоакций WB нужно подключение WB.');
    }
    $curlTemplate = trim($curlTemplate);
    if ($curlTemplate === '') {
        throw new RuntimeException('Не сохранён cURL-шаблон скачивания XLS/XLSX из кабинета WB.');
    }
    if (!wb_promotions_template_has_action_placeholder($curlTemplate) && !wb_promotions_template_has_download_placeholder($curlTemplate)) {
        throw new RuntimeException('В cURL-шаблоне нужен плейсхолдер {period_id} или {promotion_id}. Для WB recovery обычно замени periodID на {period_id}.');
    }

    $inboxDir = wb_promotions_resolve_import_dir($inboxDir, $connectionId);
    if (!wb_promotions_ensure_writable_dir($inboxDir)) {
        throw new RuntimeException('Не удалось создать или открыть на запись папку для скачанных XLS/XLSX WB: ' . $inboxDir);
    }
    $timeoutSec = max(10, min(300, (int)($options['timeout_sec'] ?? 120)));
    $maxBytes = max(100000, min(200000000, (int)($options['max_bytes'] ?? 50000000)));
    $generateCurlTemplate = trim((string)($options['generate_curl_template'] ?? ''));
    if ($generateCurlTemplate !== '' && !wb_promotions_template_has_action_placeholder($generateCurlTemplate)) {
        throw new RuntimeException('В cURL-шаблоне формирования файла нужен плейсхолдер {period_id} или {promotion_id}.');
    }
    $generateWaitMs = max(0, min(30000, (int)($options['generate_wait_ms'] ?? 2500)));
    $downloadAttempts = max(1, min(8, (int)($options['download_attempts'] ?? ($generateCurlTemplate !== '' ? 3 : 1))));
    $downloadRetryDelayMs = max(250, min(30000, (int)($options['download_retry_delay_ms'] ?? 2500)));

    $summary = [
        'connection_id' => $connectionId,
        'inbox_dir' => $inboxDir,
        'promotions_seen' => count($promotions),
        'two_step' => $generateCurlTemplate !== '' ? 1 : 0,
        'downloaded' => 0,
        'failed' => 0,
        'items' => [],
    ];

    foreach ($promotions as $promotion) {
        if (!is_array($promotion)) {
            continue;
        }
        $promotionId = (int)($promotion['promotion_id'] ?? $promotion['id'] ?? 0);
        $promotionName = (string)($promotion['name'] ?? $promotion['promotion_name'] ?? '');
        if ($promotionId <= 0) {
            continue;
        }
        try {
            $templateValues = [];
            $generateStatus = null;
            if ($generateCurlTemplate !== '') {
                $generateCommand = wb_promotions_download_apply_template($generateCurlTemplate, $promotion);
                $generateRequest = wb_promotions_parse_curl_command($generateCommand);
                $generateResponse = wb_promotions_execute_download_request($generateRequest, $timeoutSec, $maxBytes);
                $generateStatus = (int)($generateResponse['status'] ?? 0);
                if ($generateStatus < 200 || $generateStatus >= 300) {
                    throw new RuntimeException(wb_promotions_download_http_error_message(
                        $generateStatus,
                        'WB не сформировал файл',
                        (string)($generateResponse['content_type'] ?? '')
                    ));
                }
                $templateValues = wb_promotions_response_template_values($generateResponse);
                if ($log) {
                    $valueKeys = $templateValues ? implode(',', array_keys($templateValues)) : '-';
                    $log("generated promotion {$promotionId}: {$promotionName}, http={$generateStatus}, values={$valueKeys}\n");
                }
                if ($generateWaitMs > 0) {
                    usleep($generateWaitMs * 1000);
                }
            }

            $response = null;
            $lastDownloadError = null;
            for ($attempt = 1; $attempt <= $downloadAttempts; $attempt++) {
                $command = wb_promotions_download_apply_template($curlTemplate, $promotion, $templateValues);
                $request = wb_promotions_parse_curl_command($command);
                $response = wb_promotions_execute_download_request($request, $timeoutSec, $maxBytes);
                $status = (int)($response['status'] ?? 0);
                if ($status < 200 || $status >= 300) {
                    $lastDownloadError = wb_promotions_download_http_error_message(
                        $status,
                        'WB вернул ошибку при скачивании файла',
                        (string)($response['content_type'] ?? '')
                    );
                } elseif (empty($response['is_spreadsheet'])) {
                    $snippet = trim(strip_tags(mb_substr((string)($response['bytes'] ?? ''), 0, 300, 'UTF-8')));
                    $lastDownloadError = 'WB вернул не Excel-файл, content-type=' . (string)($response['content_type'] ?? '-') . ($snippet !== '' ? ', фрагмент: ' . $snippet : '');
                } else {
                    $lastDownloadError = null;
                    break;
                }
                if ($attempt < $downloadAttempts) {
                    if ($log) {
                        $log("download retry {$attempt}/{$downloadAttempts} promotion {$promotionId}: {$lastDownloadError}\n");
                    }
                    usleep($downloadRetryDelayMs * 1000);
                }
            }
            if (!is_array($response) || $lastDownloadError !== null) {
                throw new RuntimeException($lastDownloadError ?? 'WB не вернул файл для скачивания.');
            }
            $status = (int)($response['status'] ?? 0);
            $extension = str_contains(strtolower((string)($response['content_type'] ?? '')), 'ms-excel') ? 'xls' : 'xlsx';
            $filename = wb_promotions_download_filename($promotion, (string)($response['content_disposition'] ?? ''), $extension);
            $filename = wb_promotions_safe_import_filename($filename);
            $target = wb_promotions_unique_destination_path($inboxDir, $filename);
            if (@file_put_contents($target, (string)$response['bytes']) === false) {
                throw new RuntimeException('Не удалось сохранить скачанный XLS/XLSX: ' . $target);
            }
            $summary['downloaded']++;
            $item = [
                'promotion_id' => $promotionId,
                'promotion_name' => $promotionName,
                'start_datetime' => $promotion['start_datetime'] ?? null,
                'end_datetime' => $promotion['end_datetime'] ?? null,
                'status' => 'downloaded',
                'generate_http_status' => $generateStatus,
                'http_status' => $status,
                'content_type' => (string)($response['content_type'] ?? ''),
                'bytes' => (int)($response['bytes_len'] ?? 0),
                'path' => $target,
            ];
            $summary['items'][] = $item;
            if ($log) {
                $log("downloaded promotion {$promotionId}: {$promotionName}, bytes={$item['bytes']}\n");
            }
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['items'][] = [
                'promotion_id' => $promotionId,
                'promotion_name' => $promotionName,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
            if ($log) {
                $log("download failed promotion {$promotionId}: {$promotionName}: " . $e->getMessage() . "\n");
            }
        }
    }

    return $summary;
}
