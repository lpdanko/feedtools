<?php
declare(strict_types=1);

require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/BundleOffer.php';
require_once __DIR__ . '/ozon_fbo_tool.php';
require_once __DIR__ . '/orders_sync.php';

function stocks_tool_cfg_fallback(array $cfg = []): array
{
    if ($cfg) {
        return $cfg;
    }
    return ozon_price_cfg_fallback($cfg);
}

function stocks_tool_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . "\0" . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $st->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    $cache[$key] = ((int)$st->fetchColumn()) > 0;
    return $cache[$key];
}

function stocks_tool_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
    static $cache = [];
    $key = $table . "\0" . $indexName;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $st->execute([
        ':table_name' => $table,
        ':index_name' => $indexName,
    ]);
    $cache[$key] = ((int)$st->fetchColumn()) > 0;
    return $cache[$key];
}

function stocks_tool_profiles_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = stocks_tool_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    ozon_price_feeds_table_ensure($cfg);
    suppliers_table_ensure($cfg);

    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_stock_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            name VARCHAR(190) NOT NULL,
            ozon_warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            ozon_warehouse_id VARCHAR(64) NOT NULL DEFAULT '',
            ozon_warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            buffer_qty INT NOT NULL DEFAULT 0,
            max_qty INT NOT NULL DEFAULT 0,
            zero_missing_items TINYINT(1) NOT NULL DEFAULT 1,
            subtract_reserved TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            notes TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_connection_active (connection_id, is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_stock_profile_feeds (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            feed_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            buffer_qty INT NOT NULL DEFAULT 0,
            min_price DECIMAL(12,2) NULL DEFAULT NULL,
            max_price DECIMAL(12,2) NULL DEFAULT NULL,
            force_zero_stock TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_profile_feed (profile_id, feed_id),
            KEY idx_feed_profile (feed_id, profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_stock_item_state (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            offer_id VARCHAR(190) NOT NULL DEFAULT '',
            supplier_code VARCHAR(64) NOT NULL DEFAULT '',
            source_feed_id BIGINT UNSIGNED NULL DEFAULT NULL,
            last_feed_qty INT NOT NULL DEFAULT 0,
            last_reserved_qty INT NOT NULL DEFAULT 0,
            last_target_qty INT NOT NULL DEFAULT 0,
            last_pushed_qty INT NOT NULL DEFAULT 0,
            last_seen_in_feed_at DATETIME NULL DEFAULT NULL,
            last_seen_on_ozon_at DATETIME NULL DEFAULT NULL,
            last_push_status VARCHAR(32) NOT NULL DEFAULT '',
            last_push_error TEXT NULL,
            last_push_run_id BIGINT UNSIGNED NULL DEFAULT NULL,
            last_push_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_profile_warehouse_offer (profile_id, warehouse_key, offer_id),
            KEY idx_connection_offer (connection_id, offer_id),
            KEY idx_profile_feed (profile_id, source_feed_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_stock_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            kind VARCHAR(32) NOT NULL DEFAULT 'manual',
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            progress_current INT NOT NULL DEFAULT 0,
            progress_total INT NOT NULL DEFAULT 0,
            totals_json LONGTEXT NULL,
            summary_json LONGTEXT NULL,
            log_text LONGTEXT NULL,
            started_at DATETIME NULL DEFAULT NULL,
            finished_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_profile_created (profile_id, id),
            KEY idx_connection_status (connection_id, status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profiles', 'idx_connection_active')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profiles ADD KEY idx_connection_active (connection_id, is_active, sort_order, id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profile_feeds', 'uniq_profile_feed')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD UNIQUE KEY uniq_profile_feed (profile_id, feed_id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profile_feeds', 'idx_feed_profile')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD KEY idx_feed_profile (feed_id, profile_id)");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'supplier_id')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD COLUMN supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER feed_id");
    }
    stocks_tool_migrate_profile_feed_supplier_ids($pdo);
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profile_feeds', 'idx_supplier_profile')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD KEY idx_supplier_profile (supplier_id, profile_id)");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'buffer_qty')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD COLUMN buffer_qty INT NOT NULL DEFAULT 0 AFTER feed_id");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'min_price')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD COLUMN min_price DECIMAL(12,2) NULL DEFAULT NULL AFTER buffer_qty");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'max_price')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD COLUMN max_price DECIMAL(12,2) NULL DEFAULT NULL AFTER min_price");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'force_zero_stock')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_feeds ADD COLUMN force_zero_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER max_price");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_item_state', 'uniq_profile_warehouse_offer')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_item_state ADD UNIQUE KEY uniq_profile_warehouse_offer (profile_id, warehouse_key, offer_id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_item_state', 'idx_connection_offer')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_item_state ADD KEY idx_connection_offer (connection_id, offer_id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_item_state', 'idx_profile_feed')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_item_state ADD KEY idx_profile_feed (profile_id, source_feed_id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_runs', 'idx_profile_created')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_runs ADD KEY idx_profile_created (profile_id, id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_runs', 'idx_connection_status')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_runs ADD KEY idx_connection_status (connection_id, status, id)");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_runs', 'error_text')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_runs ADD COLUMN error_text TEXT NULL AFTER summary_json");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profiles', 'zero_offer_ids_text')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profiles ADD COLUMN zero_offer_ids_text LONGTEXT NULL AFTER zero_missing_items");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profiles', 'zero_supplier_categories_json')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profiles ADD COLUMN zero_supplier_categories_json LONGTEXT NULL AFTER zero_offer_ids_text");
    }
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profiles', 'zero_supplier_brands_json')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profiles ADD COLUMN zero_supplier_brands_json LONGTEXT NULL AFTER zero_supplier_categories_json");
    }

    $done = true;
}

function stocks_tool_migrate_profile_feed_supplier_ids(PDO $pdo): void
{
    if (!stocks_tool_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'supplier_id')) {
        return;
    }

    if (suppliers_table_exists($pdo, 'feedtools_ozon_price_feeds')
        && suppliers_table_has_column($pdo, 'feedtools_ozon_price_feeds', 'supplier_id')) {
        $pdo->exec("
            UPDATE feedtools_marketplace_stock_profile_feeds pf
            INNER JOIN feedtools_ozon_price_feeds f ON f.id = pf.feed_id
            SET pf.supplier_id = f.supplier_id
            WHERE pf.supplier_id = 0
              AND f.supplier_id > 0
        ");
    }

    $pdo->exec("
        UPDATE feedtools_marketplace_stock_profile_feeds pf
        INNER JOIN feedtools_suppliers s ON s.id = pf.feed_id
        SET pf.supplier_id = s.id
        WHERE pf.supplier_id = 0
    ");
}

function stocks_tool_profile_default(?int $connectionId = null): array
{
    return [
        'id' => 0,
        'connection_id' => max(0, (int)$connectionId),
        'marketplace' => 'ozon',
        'name' => '',
        'ozon_warehouse_key' => '',
        'ozon_warehouse_id' => '',
        'ozon_warehouse_name' => '',
        'buffer_qty' => 0,
        'max_qty' => 0,
        'zero_missing_items' => 1,
        'zero_offer_ids_text' => '',
        'zero_supplier_categories' => [],
        'zero_supplier_brands' => [],
        'subtract_reserved' => 1,
        'is_active' => 1,
        'sort_order' => 100,
        'notes' => '',
        'feed_ids' => [],
        'feed_settings' => [],
        'feed_count' => 0,
        'feed_names' => [],
        'supplier_codes' => [],
        'force_zero_feed_ids' => [],
        'force_zero_supplier_codes' => [],
        'force_zero_supplier_count' => 0,
    ];
}

function stocks_tool_profile_zero_rule_map_from_input($input, array $feedIds): array
{
    if (!is_array($input)) {
        return [];
    }
    $allowedFeedIds = array_fill_keys(array_map('intval', $feedIds), true);
    $out = [];
    foreach ($input as $feedId => $values) {
        $feedId = (int)$feedId;
        if ($feedId <= 0 || ($allowedFeedIds && !isset($allowedFeedIds[$feedId]))) {
            continue;
        }
        $list = [];
        foreach ((array)$values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $list[$value] = true;
            }
        }
        if ($list) {
            $out[$feedId] = array_values(array_keys($list));
            sort($out[$feedId], SORT_NATURAL | SORT_FLAG_CASE);
        }
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

function stocks_tool_profile_zero_rule_map_from_json($value): array
{
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $decoded = json_decode((string)$value, true);
    }
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $feedId => $values) {
        $feedId = (int)$feedId;
        if ($feedId <= 0) {
            continue;
        }
        $list = [];
        foreach ((array)$values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $list[$value] = true;
            }
        }
        if ($list) {
            $out[$feedId] = array_values(array_keys($list));
            sort($out[$feedId], SORT_NATURAL | SORT_FLAG_CASE);
        }
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

function stocks_tool_profile_feed_setting_default(): array
{
    return [
        'buffer_qty' => 0,
        'min_price' => null,
        'max_price' => null,
        'force_zero_stock' => 0,
    ];
}

function stocks_tool_nullable_money_from_input($value): ?float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $normalized = ozon_price_to_float($raw);
    if (!is_finite($normalized) || $normalized <= 0) {
        return null;
    }
    return round($normalized, 2);
}

function stocks_tool_profile_feed_ids_from_input(array $input): array
{
    $raw = is_array($input['supplier_ids'] ?? null)
        ? $input['supplier_ids']
        : (is_array($input['feed_ids'] ?? null) ? $input['feed_ids'] : []);
    if (is_array($input['feed_force_zero_stock'] ?? null)) {
        $raw = array_merge($raw, array_keys((array)$input['feed_force_zero_stock']));
    }
    $feedIds = [];
    foreach ($raw as $value) {
        $feedId = (int)$value;
        if ($feedId > 0) {
            $feedIds[$feedId] = true;
        }
    }
    return array_map('intval', array_keys($feedIds));
}

function stocks_tool_profile_feed_settings_from_input(array $input, array $feedIds): array
{
    $rawBuffers = is_array($input['feed_buffer_qty'] ?? null) ? $input['feed_buffer_qty'] : [];
    $rawMins = is_array($input['feed_min_price'] ?? null) ? $input['feed_min_price'] : [];
    $rawMaxs = is_array($input['feed_max_price'] ?? null) ? $input['feed_max_price'] : [];
    $rawForceZero = is_array($input['feed_force_zero_stock'] ?? null) ? $input['feed_force_zero_stock'] : [];

    $settings = [];
    foreach ($feedIds as $feedId) {
        $feedId = (int)$feedId;
        if ($feedId <= 0) {
            continue;
        }
        $settings[$feedId] = [
            'buffer_qty' => max(0, (int)($rawBuffers[$feedId] ?? 0)),
            'min_price' => stocks_tool_nullable_money_from_input($rawMins[$feedId] ?? null),
            'max_price' => stocks_tool_nullable_money_from_input($rawMaxs[$feedId] ?? null),
            'force_zero_stock' => !empty($rawForceZero[$feedId]) ? 1 : 0,
        ];
    }
    return $settings;
}

function stocks_tool_profile_normalize_input(array $input, ?int $connectionId = null): array
{
    $row = stocks_tool_profile_default($connectionId);
    $row['id'] = (int)($input['id'] ?? 0);
    $row['connection_id'] = ($connectionId ?? (int)($input['connection_id'] ?? 0)) > 0
        ? (int)($connectionId ?? (int)($input['connection_id'] ?? 0))
        : 0;
    $row['name'] = trim((string)($input['name'] ?? ''));
    $row['ozon_warehouse_key'] = trim((string)($input['ozon_warehouse_key'] ?? ''));
    $row['ozon_warehouse_id'] = trim((string)($input['ozon_warehouse_id'] ?? ''));
    $row['ozon_warehouse_name'] = trim((string)($input['ozon_warehouse_name'] ?? ''));
    $row['buffer_qty'] = max(0, (int)($input['buffer_qty'] ?? 0));
    $row['max_qty'] = max(0, (int)($input['max_qty'] ?? 0));
    $row['zero_missing_items'] = !empty($input['zero_missing_items']) ? 1 : 0;
    $row['subtract_reserved'] = !empty($input['subtract_reserved']) ? 1 : 0;
    $row['is_active'] = !empty($input['is_active']) ? 1 : 0;
    $row['sort_order'] = max(1, (int)($input['sort_order'] ?? 100));
    $row['notes'] = trim((string)($input['notes'] ?? ''));
    $row['feed_ids'] = stocks_tool_profile_feed_ids_from_input($input);
    $row['feed_settings'] = stocks_tool_profile_feed_settings_from_input($input, $row['feed_ids']);
    $row['zero_offer_ids_text'] = trim((string)($input['zero_offer_ids_text'] ?? ''));
    $row['zero_supplier_categories'] = stocks_tool_profile_zero_rule_map_from_input($input['zero_supplier_categories'] ?? [], $row['feed_ids']);
    $row['zero_supplier_brands'] = stocks_tool_profile_zero_rule_map_from_input($input['zero_supplier_brands'] ?? [], $row['feed_ids']);
    return $row;
}

function stocks_tool_profile_feed_options(int $connectionId, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    static $cache = [];
    $key = 'all_suppliers';
    if (!isset($cache[$key])) {
        $cache[$key] = array_map('suppliers_as_feed_row', suppliers_list(true, $cfg));
    }
    return $cache[$key];
}

function stocks_tool_supplier_feed_rows_by_ids(array $supplierIds, array $cfg = []): array
{
    suppliers_table_ensure($cfg);
    $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds), static fn(int $value): bool => $value > 0)));
    if (!$supplierIds) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($supplierIds), '?'));
    $st = db()->prepare("
        SELECT *
        FROM feedtools_suppliers
        WHERE id IN ($placeholders)
          AND COALESCE(is_archived, 0) = 0
    ");
    $st->execute($supplierIds);

    $rows = [];
    foreach ($st->fetchAll() ?: [] as $supplier) {
        if (!is_array($supplier)) {
            continue;
        }
        $feed = suppliers_as_feed_row($supplier);
        $feedId = (int)($feed['id'] ?? 0);
        if ($feedId > 0) {
            $rows[$feedId] = $feed;
        }
    }
    return $rows;
}

function stocks_tool_profile_feed_ids_map(array $profileIds, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $value): bool => $value > 0));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($profileIds), '?'));
    $st = db()->prepare("
        SELECT
            pf.profile_id,
            COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id, pf.feed_id) AS feed_id
        FROM feedtools_marketplace_stock_profile_feeds pf
        LEFT JOIN feedtools_ozon_price_feeds legacy_f ON legacy_f.id = pf.feed_id
        LEFT JOIN feedtools_suppliers s ON s.id = COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id)
        WHERE pf.profile_id IN ($placeholders)
          AND (
              COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id, 0) = 0
              OR COALESCE(s.is_archived, 0) = 0
          )
        ORDER BY pf.id ASC
    ");
    $st->execute($profileIds);
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $profileId = (int)($row['profile_id'] ?? 0);
        $feedId = (int)($row['feed_id'] ?? 0);
        if ($profileId <= 0 || $feedId <= 0) {
            continue;
        }
        $map[$profileId][] = $feedId;
    }
    foreach ($map as $profileId => $feedIds) {
        $map[$profileId] = array_values(array_unique(array_map('intval', $feedIds)));
    }
    return $map;
}

function stocks_tool_profile_feed_settings_map(array $profileIds, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $value): bool => $value > 0));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($profileIds), '?'));
    $st = db()->prepare("
        SELECT
            pf.profile_id,
            COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id, pf.feed_id) AS feed_id,
            pf.buffer_qty,
            pf.min_price,
            pf.max_price,
            pf.force_zero_stock
        FROM feedtools_marketplace_stock_profile_feeds pf
        LEFT JOIN feedtools_ozon_price_feeds legacy_f ON legacy_f.id = pf.feed_id
        LEFT JOIN feedtools_suppliers s ON s.id = COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id)
        WHERE pf.profile_id IN ($placeholders)
          AND (
              COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id, 0) = 0
              OR COALESCE(s.is_archived, 0) = 0
          )
        ORDER BY pf.id ASC
    ");
    $st->execute($profileIds);
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $profileId = (int)($row['profile_id'] ?? 0);
        $feedId = (int)($row['feed_id'] ?? 0);
        if ($profileId <= 0 || $feedId <= 0) {
            continue;
        }
        $map[$profileId][$feedId] = [
            'buffer_qty' => max(0, (int)($row['buffer_qty'] ?? 0)),
            'min_price' => ($row['min_price'] !== null && $row['min_price'] !== '') ? round((float)$row['min_price'], 2) : null,
            'max_price' => ($row['max_price'] !== null && $row['max_price'] !== '') ? round((float)$row['max_price'], 2) : null,
            'force_zero_stock' => !empty($row['force_zero_stock']) ? 1 : 0,
        ];
    }
    return $map;
}

function stocks_tool_profile_feed_rows(int $profileId, ?int $connectionId = null, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $feedIdsMap = stocks_tool_profile_feed_ids_map([$profileId], $cfg);
    $feedSettingsMap = stocks_tool_profile_feed_settings_map([$profileId], $cfg);
    $feedIds = array_values(array_filter(array_map('intval', $feedIdsMap[$profileId] ?? []), static fn(int $value): bool => $value > 0));
    if (!$feedIds) {
        return [];
    }
    $feedsById = stocks_tool_supplier_feed_rows_by_ids($feedIds, $cfg);
    $rows = [];
    foreach ($feedIds as $feedId) {
        if (isset($feedsById[$feedId])) {
            $feed = $feedsById[$feedId];
            $settings = $feedSettingsMap[$profileId][$feedId] ?? stocks_tool_profile_feed_setting_default();
            $feed['stock_buffer_qty'] = (int)($settings['buffer_qty'] ?? 0);
            $feed['stock_min_price'] = $settings['min_price'] ?? null;
            $feed['stock_max_price'] = $settings['max_price'] ?? null;
            $feed['stock_force_zero'] = !empty($settings['force_zero_stock']) ? 1 : 0;
            $rows[] = $feed;
        }
    }
    return $rows;
}

function stocks_tool_profile_feed_row_by_id(int $profileId, int $feedId, ?int $connectionId = null, array $cfg = []): ?array
{
    if ($profileId <= 0 || $feedId <= 0) {
        return null;
    }
    foreach (stocks_tool_profile_feed_rows($profileId, $connectionId, $cfg) as $feed) {
        if ((int)($feed['id'] ?? 0) === $feedId) {
            return $feed;
        }
    }
    return null;
}

function stocks_tool_profile_force_zero_feed_ids(array $profile): array
{
    $feedSettings = is_array($profile['feed_settings'] ?? null) ? $profile['feed_settings'] : [];
    $ids = [];
    foreach ($feedSettings as $feedId => $settings) {
        if (!is_array($settings) || empty($settings['force_zero_stock'])) {
            continue;
        }
        $feedId = (int)$feedId;
        if ($feedId > 0) {
            $ids[$feedId] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}

function stocks_tool_profile_force_zero_supplier_codes_from_feed_rows(array $feedRows): array
{
    $codes = [];
    foreach ($feedRows as $feed) {
        if (!is_array($feed) || empty($feed['stock_force_zero'])) {
            continue;
        }
        $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
        if ($supplierCode !== '') {
            $codes[$supplierCode] = true;
        }
    }
    return array_values(array_keys($codes));
}

function stocks_tool_force_zero_supplier_codes_for_connection(int $connectionId, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }

    $st = db()->prepare("
        SELECT DISTINCT s.supplier_code
        FROM feedtools_marketplace_stock_profile_feeds pf
        INNER JOIN feedtools_marketplace_stock_profiles p ON p.id = pf.profile_id
        LEFT JOIN feedtools_ozon_price_feeds legacy_f ON legacy_f.id = pf.feed_id
        LEFT JOIN feedtools_suppliers s ON s.id = COALESCE(NULLIF(pf.supplier_id, 0), legacy_f.supplier_id, pf.feed_id)
        WHERE p.connection_id = ?
          AND COALESCE(p.is_active, 1) = 1
          AND COALESCE(pf.force_zero_stock, 0) = 1
          AND COALESCE(s.is_archived, 0) = 0
          AND COALESCE(s.supplier_code, '') <> ''
    ");
    $st->execute([$connectionId]);

    $codes = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $supplierCode) {
        $supplierCode = trim((string)$supplierCode);
        if ($supplierCode !== '') {
            $codes[$supplierCode] = true;
        }
    }
    return array_values(array_keys($codes));
}

function stocks_tool_force_zero_supplier_codes(array $profile): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        (array)($profile['force_zero_supplier_codes'] ?? [])
    ))));
}

function stocks_tool_profile_list(?int $connectionId = null, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        SELECT
            p.*,
            COUNT(CASE WHEN COALESCE(s.is_archived, 0) = 0 THEN pf.id END) AS feed_count,
            GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(s.is_archived, 0) = 0 THEN s.name ELSE NULL END ORDER BY s.name SEPARATOR '\n') AS feed_names_concat,
            GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(s.is_archived, 0) = 0 THEN s.supplier_code ELSE NULL END ORDER BY s.supplier_code SEPARATOR '\n') AS supplier_codes_concat
        FROM feedtools_marketplace_stock_profiles p
        LEFT JOIN feedtools_marketplace_stock_profile_feeds pf ON pf.profile_id = p.id
        LEFT JOIN feedtools_ozon_price_feeds legacy_f ON legacy_f.id = pf.feed_id
        LEFT JOIN feedtools_suppliers s ON s.id = CASE WHEN pf.supplier_id > 0 THEN pf.supplier_id ELSE legacy_f.supplier_id END
        WHERE p.connection_id = ?
        GROUP BY p.id
        HAVING feed_count > 0 OR COUNT(pf.id) = 0
        ORDER BY p.sort_order ASC, p.updated_at DESC, p.id DESC
    ");
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    $feedIdsMap = stocks_tool_profile_feed_ids_map(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        array_filter($rows, 'is_array')
    ), $cfg);
    $feedSettingsMap = stocks_tool_profile_feed_settings_map(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        array_filter($rows, 'is_array')
    ), $cfg);
    foreach ($rows as &$row) {
        $profileId = (int)($row['id'] ?? 0);
        $row['feed_count'] = (int)($row['feed_count'] ?? 0);
        $row['feed_names'] = array_values(array_filter(array_map('trim', explode("\n", (string)($row['feed_names_concat'] ?? '')))));
        $row['supplier_codes'] = array_values(array_filter(array_map('trim', explode("\n", (string)($row['supplier_codes_concat'] ?? '')))));
        $row['feed_ids'] = $feedIdsMap[$profileId] ?? [];
        $row['feed_settings'] = $feedSettingsMap[$profileId] ?? [];
        $row['force_zero_feed_ids'] = stocks_tool_profile_force_zero_feed_ids($row);
        $row['force_zero_supplier_count'] = count($row['force_zero_feed_ids']);
        $row['force_zero_supplier_codes'] = [];
        $row['zero_offer_ids_text'] = (string)($row['zero_offer_ids_text'] ?? '');
        $row['zero_supplier_categories'] = stocks_tool_profile_zero_rule_map_from_json($row['zero_supplier_categories_json'] ?? '');
        $row['zero_supplier_brands'] = stocks_tool_profile_zero_rule_map_from_json($row['zero_supplier_brands_json'] ?? '');
    }
    unset($row);
    return $rows;
}

function stocks_tool_profile_counts_by_connection(array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $st = db()->query("
        SELECT visible_profiles.connection_id, COUNT(*) AS qty
        FROM (
            SELECT
                p.id,
                p.connection_id,
                COUNT(pf.id) AS total_feed_count,
                COUNT(CASE WHEN COALESCE(s.is_archived, 0) = 0 THEN pf.id END) AS active_feed_count
            FROM feedtools_marketplace_stock_profiles p
            LEFT JOIN feedtools_marketplace_stock_profile_feeds pf ON pf.profile_id = p.id
            LEFT JOIN feedtools_ozon_price_feeds legacy_f ON legacy_f.id = pf.feed_id
            LEFT JOIN feedtools_suppliers s ON s.id = CASE WHEN pf.supplier_id > 0 THEN pf.supplier_id ELSE legacy_f.supplier_id END
            GROUP BY p.id, p.connection_id
            HAVING active_feed_count > 0 OR total_feed_count = 0
        ) visible_profiles
        GROUP BY visible_profiles.connection_id
    ");
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $map[(int)($row['connection_id'] ?? 0)] = (int)($row['qty'] ?? 0);
    }
    return $map;
}

function stocks_tool_profile_get(int $id, ?int $connectionId = null, array $cfg = []): ?array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    if ($connectionId !== null && $connectionId > 0) {
        $st = db()->prepare("SELECT * FROM feedtools_marketplace_stock_profiles WHERE id = ? AND connection_id = ? LIMIT 1");
        $st->execute([$id, $connectionId]);
    } else {
        $st = db()->prepare("SELECT * FROM feedtools_marketplace_stock_profiles WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['feed_ids'] = stocks_tool_profile_feed_ids_map([(int)$id], $cfg)[(int)$id] ?? [];
    $row['feed_settings'] = stocks_tool_profile_feed_settings_map([(int)$id], $cfg)[(int)$id] ?? [];
    $countSt = db()->prepare("SELECT COUNT(*) FROM feedtools_marketplace_stock_profile_feeds WHERE profile_id = ?");
    $countSt->execute([(int)$id]);
    if ((int)$countSt->fetchColumn() > 0 && !(array)$row['feed_ids']) {
        return null;
    }
    $row['zero_offer_ids_text'] = (string)($row['zero_offer_ids_text'] ?? '');
    $row['zero_supplier_categories'] = stocks_tool_profile_zero_rule_map_from_json($row['zero_supplier_categories_json'] ?? '');
    $row['zero_supplier_brands'] = stocks_tool_profile_zero_rule_map_from_json($row['zero_supplier_brands_json'] ?? '');
    $row['feed_count'] = count((array)($row['feed_ids'] ?? []));
    $row['feed_names'] = [];
    $row['supplier_codes'] = [];
    $feedRows = stocks_tool_profile_feed_rows($id, (int)($row['connection_id'] ?? 0), $cfg);
    foreach ($feedRows as $feed) {
        $row['feed_names'][] = (string)($feed['name'] ?? '');
        $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
        if ($supplierCode !== '') {
            $row['supplier_codes'][] = $supplierCode;
        }
    }
    $row['feed_names'] = array_values(array_filter(array_map('trim', (array)$row['feed_names'])));
    $row['supplier_codes'] = array_values(array_unique(array_filter(array_map('trim', (array)$row['supplier_codes']))));
    $row['force_zero_feed_ids'] = stocks_tool_profile_force_zero_feed_ids($row);
    $row['force_zero_supplier_codes'] = array_values(array_unique(array_merge(
        stocks_tool_profile_force_zero_supplier_codes_from_feed_rows($feedRows),
        stocks_tool_force_zero_supplier_codes_for_connection((int)($row['connection_id'] ?? 0), $cfg)
    )));
    $row['force_zero_supplier_count'] = count($row['force_zero_supplier_codes']);
    return $row;
}

function stocks_tool_profile_delete(int $id, ?int $connectionId = null, array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    stocks_tool_automation_table_ensure($cfg);
    if ($id <= 0) {
        throw new RuntimeException('Не удалось определить профиль остатков для удаления.');
    }

    $profile = stocks_tool_profile_get($id, $connectionId, $cfg);
    if (!is_array($profile)) {
        throw new RuntimeException('Профиль остатков не найден.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_profile_feeds WHERE profile_id = ?");
        $st->execute([$id]);
        $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_profile_automations WHERE profile_id = ?");
        $st->execute([$id]);
        $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_item_state WHERE profile_id = ?");
        $st->execute([$id]);
        $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_runs WHERE profile_id = ?");
        $st->execute([$id]);
        if ($connectionId !== null && $connectionId > 0) {
            $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_profiles WHERE id = ? AND connection_id = ? LIMIT 1");
            $st->execute([$id, $connectionId]);
        } else {
            $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_profiles WHERE id = ? LIMIT 1");
            $st->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function stocks_tool_profile_save(array $input, ?string $actor = null, ?int $connectionId = null, array $cfg = []): int
{
    stocks_tool_profiles_table_ensure($cfg);

    $row = stocks_tool_profile_normalize_input($input, $connectionId);
    if ($row['connection_id'] <= 0) {
        throw new RuntimeException('Для профиля остатков нужно выбрать подключение маркетплейса.');
    }
    if ($row['name'] === '') {
        throw new RuntimeException('Укажи название профиля остатков.');
    }

    $connection = ozon_price_connection_get((int)$row['connection_id'], $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Не удалось загрузить подключение маркетплейса.');
    }
    $marketplace = stocks_tool_marketplace_key($connection);
    if (!in_array($marketplace, ['ozon', 'wb', 'yandex_market'], true)) {
        throw new RuntimeException('Stocks Tool пока поддерживает только Ozon, WB и Яндекс Маркет.');
    }

    $warehouses = stocks_tool_warehouse_options($cfg, $connection);
    $warehouseKey = (string)($row['ozon_warehouse_key'] ?? '');
    if ($warehouseKey === '' || !isset($warehouses[$warehouseKey])) {
        throw new RuntimeException('Выбери склад ' . stocks_tool_marketplace_item_label($marketplace) . ', на который нужно передавать остатки.');
    }

    $warehouse = $warehouses[$warehouseKey];
    $row['ozon_warehouse_id'] = (string)($warehouse['warehouse_id'] ?? '');
    $row['ozon_warehouse_name'] = (string)($warehouse['warehouse_name'] ?? '');

    $feedOptions = stocks_tool_profile_feed_options((int)$row['connection_id'], $cfg);
    $feedsById = [];
    foreach ($feedOptions as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        if ($feedId > 0) {
            $feedsById[$feedId] = $feed;
        }
    }

    if (!$row['feed_ids']) {
        throw new RuntimeException('Выбери хотя бы одного поставщика для расчёта остатков.');
    }

    $supplierCodeSet = [];
    foreach ($row['feed_ids'] as $feedId) {
        if (!isset($feedsById[$feedId])) {
            throw new RuntimeException('Один из выбранных поставщиков не найден.');
        }
        $supplierCode = trim((string)($feedsById[$feedId]['supplier_code'] ?? ''));
        if ($supplierCode === '') {
            throw new RuntimeException('У каждого выбранного поставщика должен быть заполнен код поставщика.');
        }
        if (isset($supplierCodeSet[$supplierCode])) {
            throw new RuntimeException('Нельзя выбрать двух поставщиков с одинаковым кодом поставщика: ' . $supplierCode);
        }
        $supplierCodeSet[$supplierCode] = true;

        $feedSettings = is_array($row['feed_settings'][$feedId] ?? null)
            ? $row['feed_settings'][$feedId]
            : stocks_tool_profile_feed_setting_default();
        $minPrice = $feedSettings['min_price'] ?? null;
        $maxPrice = $feedSettings['max_price'] ?? null;
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new RuntimeException('У источника ' . (string)($feedsById[$feedId]['name'] ?? ('#' . $feedId)) . ' минимальная цена больше максимальной.');
        }
    }

    $profileId = (int)($row['id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($profileId > 0) {
            $existing = stocks_tool_profile_get($profileId, (int)$row['connection_id'], $cfg);
            if (!is_array($existing)) {
                throw new RuntimeException('Профиль остатков не найден для обновления.');
            }
            $st = $pdo->prepare("
                UPDATE feedtools_marketplace_stock_profiles
                SET
                    connection_id = ?,
                    marketplace = ?,
                    name = ?,
                    ozon_warehouse_key = ?,
                    ozon_warehouse_id = ?,
                    ozon_warehouse_name = ?,
                    buffer_qty = ?,
                    max_qty = ?,
                    zero_missing_items = ?,
                    zero_offer_ids_text = ?,
                    zero_supplier_categories_json = ?,
                    zero_supplier_brands_json = ?,
                    subtract_reserved = ?,
                    is_active = ?,
                    sort_order = ?,
                    notes = ?,
                    updated_by = ?
                WHERE id = ? AND connection_id = ?
            ");
            $st->execute([
                $row['connection_id'],
                $marketplace,
                $row['name'],
                $row['ozon_warehouse_key'],
                $row['ozon_warehouse_id'],
                $row['ozon_warehouse_name'],
                $row['buffer_qty'],
                $row['max_qty'],
                $row['zero_missing_items'],
                $row['zero_offer_ids_text'],
                json_encode($row['zero_supplier_categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($row['zero_supplier_brands'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['subtract_reserved'],
                $row['is_active'],
                $row['sort_order'],
                $row['notes'],
                $actor,
                $profileId,
                $row['connection_id'],
            ]);
        } else {
            $st = $pdo->prepare("
                INSERT INTO feedtools_marketplace_stock_profiles (
                    connection_id, marketplace, name, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name,
                    buffer_qty, max_qty, zero_missing_items, zero_offer_ids_text,
                    zero_supplier_categories_json, zero_supplier_brands_json,
                    subtract_reserved, is_active, sort_order,
                    notes, created_by, updated_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $st->execute([
                $row['connection_id'],
                $marketplace,
                $row['name'],
                $row['ozon_warehouse_key'],
                $row['ozon_warehouse_id'],
                $row['ozon_warehouse_name'],
                $row['buffer_qty'],
                $row['max_qty'],
                $row['zero_missing_items'],
                $row['zero_offer_ids_text'],
                json_encode($row['zero_supplier_categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($row['zero_supplier_brands'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['subtract_reserved'],
                $row['is_active'],
                $row['sort_order'],
                $row['notes'],
                $actor,
                $actor,
            ]);
            $profileId = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare("DELETE FROM feedtools_marketplace_stock_profile_feeds WHERE profile_id = ?");
        $st->execute([$profileId]);

        $st = $pdo->prepare("
            INSERT INTO feedtools_marketplace_stock_profile_feeds (
                profile_id, feed_id, supplier_id, buffer_qty, min_price, max_price, force_zero_stock
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($row['feed_ids'] as $feedId) {
            $feedSettings = is_array($row['feed_settings'][$feedId] ?? null)
                ? $row['feed_settings'][$feedId]
                : stocks_tool_profile_feed_setting_default();
            $st->execute([
                $profileId,
                $feedId,
                $feedId,
                max(0, (int)($feedSettings['buffer_qty'] ?? 0)),
                $feedSettings['min_price'] ?? null,
                $feedSettings['max_price'] ?? null,
                !empty($feedSettings['force_zero_stock']) ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $profileId;
}

function stocks_tool_automation_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    stocks_tool_profiles_table_ensure($cfg);
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_stock_profile_automations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            operation_key VARCHAR(32) NOT NULL DEFAULT 'sync',
            frequency_key VARCHAR(32) NOT NULL DEFAULT 'hourly',
            run_hour_msk TINYINT UNSIGNED NOT NULL DEFAULT 0,
            run_minute_msk TINYINT UNSIGNED NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME NULL DEFAULT NULL,
            last_run_slot_key VARCHAR(64) NULL DEFAULT NULL,
            last_run_run_id BIGINT UNSIGNED NULL DEFAULT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_profile_sort (profile_id, enabled, id),
            KEY idx_enabled_frequency (enabled, frequency_key, profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profile_automations', 'idx_profile_sort')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_automations ADD KEY idx_profile_sort (profile_id, enabled, id)");
    }
    if (!stocks_tool_table_has_index($pdo, 'feedtools_marketplace_stock_profile_automations', 'idx_enabled_frequency')) {
        $pdo->exec("ALTER TABLE feedtools_marketplace_stock_profile_automations ADD KEY idx_enabled_frequency (enabled, frequency_key, profile_id)");
    }

    $done = true;
}

function stocks_tool_operation_options(): array
{
    return [
        'sync' => [
            'label' => 'Обновить остатки',
        ],
    ];
}

function stocks_tool_operation_normalize(string $value): string
{
    $value = trim($value);
    $options = stocks_tool_operation_options();
    return array_key_exists($value, $options) ? $value : 'sync';
}

function stocks_tool_operation_label(string $value): string
{
    $value = stocks_tool_operation_normalize($value);
    $options = stocks_tool_operation_options();
    return (string)($options[$value]['label'] ?? 'Обновить остатки');
}

function stocks_tool_automation_frequency_options(): array
{
    return [
        '5m' => ['label' => 'Каждые 5 минут', 'interval_minutes' => 5],
        '15m' => ['label' => 'Каждые 15 минут', 'interval_minutes' => 15],
        '20m' => ['label' => 'Каждые 20 минут', 'interval_minutes' => 20],
        '30m' => ['label' => 'Каждые 30 минут', 'interval_minutes' => 30],
        'hourly' => ['label' => 'Каждый час', 'interval_minutes' => 60],
        '4h' => ['label' => 'Каждые 4 часа', 'interval_minutes' => 240],
        '8h' => ['label' => 'Каждые 8 часов', 'interval_minutes' => 480],
        'daily' => ['label' => 'Раз в сутки', 'interval_minutes' => 1440],
    ];
}

function stocks_tool_automation_frequency_normalize(string $value): string
{
    $value = trim($value);
    $options = stocks_tool_automation_frequency_options();
    return array_key_exists($value, $options) ? $value : 'hourly';
}

function stocks_tool_automation_frequency_label(string $value): string
{
    $value = stocks_tool_automation_frequency_normalize($value);
    $options = stocks_tool_automation_frequency_options();
    return (string)($options[$value]['label'] ?? 'Каждый час');
}

function stocks_tool_automation_default(int $profileId = 0, array $profile = []): array
{
    return [
        'id' => 0,
        'profile_id' => max(0, $profileId),
        'connection_id' => (int)($profile['connection_id'] ?? 0),
        'marketplace' => (string)($profile['marketplace'] ?? 'ozon'),
        'operation_key' => 'sync',
        'frequency_key' => 'hourly',
        'run_hour_msk' => 0,
        'run_minute_msk' => 0,
        'enabled' => 0,
        'last_run_at' => null,
        'last_run_slot_key' => null,
        'last_run_run_id' => null,
    ];
}

function stocks_tool_automation_hydrate(array $row, array $profile = []): array
{
    $row += stocks_tool_automation_default((int)($row['profile_id'] ?? 0), $profile);
    $row['id'] = (int)($row['id'] ?? 0);
    $row['profile_id'] = (int)($row['profile_id'] ?? 0);
    $row['connection_id'] = (int)($row['connection_id'] ?? ($profile['connection_id'] ?? 0));
    $row['operation_key'] = stocks_tool_operation_normalize((string)($row['operation_key'] ?? 'sync'));
    $row['frequency_key'] = stocks_tool_automation_frequency_normalize((string)($row['frequency_key'] ?? 'hourly'));
    $row['run_hour_msk'] = max(0, min(23, (int)($row['run_hour_msk'] ?? 0)));
    $row['run_minute_msk'] = max(0, min(59, (int)($row['run_minute_msk'] ?? 0)));
    $row['enabled'] = !empty($row['enabled']) ? 1 : 0;
    return $row;
}

function stocks_tool_automation_input(array $input, array $profile = []): array
{
    $row = stocks_tool_automation_default((int)($input['profile_id'] ?? 0), $profile);
    $row['id'] = (int)($input['automation_id'] ?? $input['id'] ?? 0);
    $row['profile_id'] = (int)($input['profile_id'] ?? $row['profile_id']);
    $row['connection_id'] = (int)($profile['connection_id'] ?? $row['connection_id']);
    $row['marketplace'] = (string)($profile['marketplace'] ?? $row['marketplace']);
    $row['operation_key'] = stocks_tool_operation_normalize((string)($input['operation_key'] ?? 'sync'));
    $row['frequency_key'] = stocks_tool_automation_frequency_normalize((string)($input['frequency_key'] ?? 'hourly'));

    $timeValue = trim((string)($input['run_time_msk'] ?? '00:00'));
    if (preg_match('~^(\d{1,2}):(\d{2})$~', $timeValue, $m)) {
        $row['run_hour_msk'] = max(0, min(23, (int)$m[1]));
        $row['run_minute_msk'] = max(0, min(59, (int)$m[2]));
    } else {
        $row['run_hour_msk'] = max(0, min(23, (int)($input['run_hour_msk'] ?? 0)));
        $row['run_minute_msk'] = max(0, min(59, (int)($input['run_minute_msk'] ?? 0)));
    }
    $row['enabled'] = !empty($input['enabled']) ? 1 : 0;
    return $row;
}

function stocks_tool_automation_validate(array $row, array $profile, array $cfg = []): void
{
    stocks_tool_automation_table_ensure($cfg);
    if ((int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль остатков для автоматизации не найден.');
    }
    if ((int)($row['profile_id'] ?? 0) !== (int)($profile['id'] ?? 0)) {
        throw new RuntimeException('Автоматизация должна принадлежать выбранному профилю остатков.');
    }
    $operationKey = stocks_tool_operation_normalize((string)($row['operation_key'] ?? 'sync'));
    if (!array_key_exists($operationKey, stocks_tool_operation_options())) {
        throw new RuntimeException('Выбрана неподдерживаемая операция автоматизации.');
    }
    $frequencyKey = stocks_tool_automation_frequency_normalize((string)($row['frequency_key'] ?? 'hourly'));
    if (!array_key_exists($frequencyKey, stocks_tool_automation_frequency_options())) {
        throw new RuntimeException('Выбрана неподдерживаемая периодичность автоматизации.');
    }
    $runHour = max(0, min(23, (int)($row['run_hour_msk'] ?? 0)));
    $runMinute = max(0, min(59, (int)($row['run_minute_msk'] ?? 0)));
    if ($runHour < 0 || $runHour > 23 || $runMinute < 0 || $runMinute > 59) {
        throw new RuntimeException('Проверь время начала запуска по Москве.');
    }
}

function stocks_tool_automation_list(int $profileId, array $profile = [], array $cfg = []): array
{
    stocks_tool_automation_table_ensure($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_profile_automations
        WHERE profile_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$profileId]);
    $rows = $st->fetchAll() ?: [];
    return array_map(static fn(array $row): array => stocks_tool_automation_hydrate($row, $profile), $rows);
}

function stocks_tool_automation_map(array $profileIds, array $profilesById = [], array $cfg = []): array
{
    stocks_tool_automation_table_ensure($cfg);
    $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $value): bool => $value > 0));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($profileIds), '?'));
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_profile_automations
        WHERE profile_id IN ($placeholders)
        ORDER BY profile_id ASC, id ASC
    ");
    $st->execute($profileIds);
    $result = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $profileId = (int)($row['profile_id'] ?? 0);
        if ($profileId <= 0) {
            continue;
        }
        $result[$profileId] ??= [];
        $result[$profileId][] = stocks_tool_automation_hydrate($row, $profilesById[$profileId] ?? []);
    }
    return $result;
}

function stocks_tool_automation_save(array $input, array $profile, ?string $actor = null, array $cfg = []): int
{
    stocks_tool_automation_table_ensure($cfg);
    $row = stocks_tool_automation_input($input, $profile);
    stocks_tool_automation_validate($row, $profile, $cfg);

    $automationId = (int)($row['id'] ?? 0);
    if ($automationId > 0) {
        $st = db()->prepare("
            UPDATE feedtools_marketplace_stock_profile_automations
            SET
                connection_id = ?,
                marketplace = ?,
                operation_key = ?,
                frequency_key = ?,
                run_hour_msk = ?,
                run_minute_msk = ?,
                enabled = ?,
                updated_by = ?
            WHERE id = ? AND profile_id = ?
        ");
        $st->execute([
            (int)($profile['connection_id'] ?? 0),
            (string)($profile['marketplace'] ?? 'ozon'),
            (string)($row['operation_key'] ?? 'sync'),
            (string)($row['frequency_key'] ?? 'hourly'),
            (int)($row['run_hour_msk'] ?? 0),
            (int)($row['run_minute_msk'] ?? 0),
            !empty($row['enabled']) ? 1 : 0,
            $actor,
            $automationId,
            (int)($profile['id'] ?? 0),
        ]);
        return $automationId;
    }

    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_stock_profile_automations (
            profile_id, connection_id, marketplace, operation_key, frequency_key,
            run_hour_msk, run_minute_msk, enabled, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        (int)($profile['id'] ?? 0),
        (int)($profile['connection_id'] ?? 0),
        (string)($profile['marketplace'] ?? 'ozon'),
        (string)($row['operation_key'] ?? 'sync'),
        (string)($row['frequency_key'] ?? 'hourly'),
        (int)($row['run_hour_msk'] ?? 0),
        (int)($row['run_minute_msk'] ?? 0),
        !empty($row['enabled']) ? 1 : 0,
        $actor,
        $actor,
    ]);
    return (int)db()->lastInsertId();
}

function stocks_tool_automation_delete(int $automationId, int $profileId, array $cfg = []): void
{
    stocks_tool_automation_table_ensure($cfg);
    if ($automationId <= 0 || $profileId <= 0) {
        return;
    }
    $st = db()->prepare("DELETE FROM feedtools_marketplace_stock_profile_automations WHERE id = ? AND profile_id = ? LIMIT 1");
    $st->execute([$automationId, $profileId]);
}

function stocks_tool_automation_run_time_value(array $automation): string
{
    return sprintf(
        '%02d:%02d',
        max(0, min(23, (int)($automation['run_hour_msk'] ?? 0))),
        max(0, min(59, (int)($automation['run_minute_msk'] ?? 0)))
    );
}

function stocks_tool_automation_slot_info(array $automation, DateTimeImmutable $nowMsk): array
{
    $frequencyKey = stocks_tool_automation_frequency_normalize((string)($automation['frequency_key'] ?? 'hourly'));
    $options = stocks_tool_automation_frequency_options();
    $intervalMinutes = (int)($options[$frequencyKey]['interval_minutes'] ?? 60);
    $runHour = max(0, min(23, (int)($automation['run_hour_msk'] ?? 0)));
    $runMinute = max(0, min(59, (int)($automation['run_minute_msk'] ?? 0)));

    $anchor = $nowMsk->setTime($runHour, $runMinute, 0);
    if ($intervalMinutes >= 1440) {
        if ($nowMsk < $anchor) {
            $anchor = $anchor->modify('-1 day');
        }
        $slotStart = $anchor;
        $slotEnd = $slotStart->modify('+1 day');
    } else {
        if ($nowMsk < $anchor) {
            $anchor = $anchor->modify('-1 day');
        }
        $elapsedSeconds = max(0, $nowMsk->getTimestamp() - $anchor->getTimestamp());
        $slotIndex = intdiv($elapsedSeconds, $intervalMinutes * 60);
        $slotStart = $anchor->modify('+' . ($slotIndex * $intervalMinutes) . ' minutes');
        $slotEnd = $slotStart->modify('+' . $intervalMinutes . ' minutes');
    }

    return [
        'slot_key' => $slotStart->format('YmdHi'),
        'slot_start' => $slotStart,
        'slot_end' => $slotEnd,
    ];
}

function stocks_tool_ui_cache_dir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function stocks_tool_ui_cache_key_to_path(string $cacheKey): string
{
    return stocks_tool_ui_cache_dir() . '/stocks_tool_' . preg_replace('~[^0-9A-Za-z_.-]+~', '_', $cacheKey) . '.json';
}

function stocks_tool_ui_cache_read(string $cacheKey, int $ttlSeconds = 300): ?array
{
    $path = stocks_tool_ui_cache_key_to_path($cacheKey);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return null;
    }
    $ts = (int)($data['ts'] ?? 0);
    if ($ttlSeconds > 0 && $ts > 0 && (time() - $ts) > $ttlSeconds) {
        return null;
    }
    return is_array($data['payload'] ?? null) ? $data['payload'] : null;
}

function stocks_tool_ui_cache_read_stale(string $cacheKey): ?array
{
    return stocks_tool_ui_cache_read($cacheKey, 0);
}

function stocks_tool_ui_cache_write(string $cacheKey, array $payload): void
{
    $dir = stocks_tool_ui_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = stocks_tool_ui_cache_key_to_path($cacheKey);
    @file_put_contents($path, json_encode([
        'ts' => time(),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function stocks_tool_ozon_warehouses_list(array $cfg): array
{
    $cfg = stocks_tool_cfg_fallback($cfg);
    $cacheKey = 'ozon_warehouses_' . sha1(json_encode([
        'connection_id' => (int)($cfg['price_tool_connection']['id'] ?? 0),
        'client_id' => (string)($cfg['ozon']['client_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, 300);
    if (is_array($cached) && is_array($cached['warehouses'] ?? null)) {
        return array_values(array_filter($cached['warehouses'], static fn($row): bool => is_array($row)));
    }

    $oz = ozon_cfg_or_fail($cfg);
    $warehouses = [];
    try {
        $limit = 200;
        $offset = 0;
        for ($page = 0; $page < 20; $page++) {
            $payload = [
                'limit' => $limit,
                'offset' => $offset,
            ];
            $response = ozon_post_json($oz, '/v2/warehouse/list', $payload);
            $rows = is_array($response['warehouses'] ?? null) ? $response['warehouses'] : [];
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $warehouses[] = $row;
                }
            }
            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }
    } catch (Throwable $e) {
        $stale = stocks_tool_ui_cache_read_stale($cacheKey);
        if (is_array($stale) && is_array($stale['warehouses'] ?? null)) {
            return array_values(array_filter($stale['warehouses'], static fn($row): bool => is_array($row)));
        }
        throw $e;
    }

    stocks_tool_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
    return $warehouses;
}

function stocks_tool_ozon_warehouse_is_visible(array $warehouse): bool
{
    $type = mb_strtolower(trim((string)($warehouse['warehouse_type'] ?? '')), 'UTF-8');
    if ($type !== '' && $type !== 'fbs') {
        return false;
    }
    $status = mb_strtolower(trim((string)($warehouse['status'] ?? '')), 'UTF-8');
    return in_array($status, ['created', 'active'], true);
}

function stocks_tool_ozon_warehouse_entry_from_row(array $warehouse): ?array
{
    if (!stocks_tool_ozon_warehouse_is_visible($warehouse)) {
        return null;
    }
    $warehouseId = trim((string)($warehouse['warehouse_id'] ?? ''));
    $warehouseName = trim((string)($warehouse['name'] ?? ''));
    if ($warehouseId === '' && $warehouseName === '') {
        return null;
    }
    $key = $warehouseId !== '' ? ('id:' . $warehouseId) : ('name:' . mb_strtolower($warehouseName, 'UTF-8'));
    return [
        'key' => $key,
        'warehouse_id' => $warehouseId,
        'warehouse_name' => $warehouseName !== '' ? $warehouseName : $warehouseId,
    ];
}

function stocks_tool_ozon_warehouse_options(array $cfg): array
{
    $options = [];
    foreach (stocks_tool_ozon_warehouses_list($cfg) as $warehouse) {
        $entry = stocks_tool_ozon_warehouse_entry_from_row($warehouse);
        if (!is_array($entry)) {
            continue;
        }
        $options[(string)$entry['key']] = $entry;
    }
    ksort($options);
    return $options;
}

function stocks_tool_yandex_campaign_context(array $connection): array
{
    static $cache = [];

    $connection['base_url'] = trim((string)($connection['base_url'] ?? '')) ?: 'https://api.partner.market.yandex.ru';
    $cacheKey = sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'campaign_id' => trim((string)($connection['client_id'] ?? '')),
        'api_key_hash' => sha1((string)($connection['api_key'] ?? '')),
        'base_url' => (string)$connection['base_url'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $resolved = marketplace_connection_yandex_resolve_campaign($connection);
    $campaign = is_array($resolved['campaign'] ?? null) ? $resolved['campaign'] : [];
    $business = is_array($campaign['business'] ?? null) ? $campaign['business'] : [];
    $campaignId = (int)($resolved['campaign_id'] ?? $campaign['id'] ?? 0);
    $businessId = (int)($business['id'] ?? $campaign['businessId'] ?? $campaign['business_id'] ?? 0);

    if ($campaignId <= 0) {
        throw new RuntimeException('Не удалось определить Campaign ID Яндекс Маркета.');
    }
    if ($businessId <= 0) {
        throw new RuntimeException('Не удалось определить Business ID Яндекс Маркета. Проверь доступ API-Key к списку кампаний.');
    }

    return $cache[$cacheKey] = [
        'campaign_id' => $campaignId,
        'business_id' => $businessId,
        'campaign' => $campaign,
        'campaigns' => is_array($resolved['campaigns'] ?? null) ? $resolved['campaigns'] : [],
    ];
}

function stocks_tool_yandex_warehouses_list(array $cfg, array $connection): array
{
    $cfg = stocks_tool_cfg_fallback($cfg);
    $context = stocks_tool_yandex_campaign_context($connection);
    $campaignId = (int)$context['campaign_id'];
    $businessId = (int)$context['business_id'];
    $cacheKey = 'yandex:stocks-tool:warehouses:' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'campaign_id' => $campaignId,
        'business_id' => $businessId,
        'api_key_hash' => sha1((string)($connection['api_key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, 300);
    if (is_array($cached) && is_array($cached['warehouses'] ?? null)) {
        return array_values(array_filter($cached['warehouses'], static fn($row): bool => is_array($row)));
    }

    $warehouses = [];
    $pageToken = '';
    for ($page = 0; $page < 50; $page++) {
        $query = ['limit' => 30];
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $payload = [
            'campaignIds' => [$campaignId],
            'components' => ['ADDRESS', 'STATUS'],
        ];
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            '/v2/businesses/' . $businessId . '/warehouses',
            $query,
            $payload
        );
        $result = is_array($body['result'] ?? null) ? $body['result'] : $body;
        $items = is_array($result['warehouses'] ?? null) ? $result['warehouses'] : [];
        foreach ($items as $item) {
            if (is_array($item) && (int)($item['campaignId'] ?? $campaignId) === $campaignId) {
                $warehouses[] = $item;
            }
        }
        $pageToken = trim((string)($result['paging']['nextPageToken'] ?? $body['paging']['nextPageToken'] ?? ''));
        if ($pageToken === '') {
            break;
        }
    }

    stocks_tool_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
    return $warehouses;
}

function stocks_tool_yandex_warehouse_entry_from_row(array $warehouse): ?array
{
    $warehouseId = trim((string)($warehouse['id'] ?? $warehouse['warehouseId'] ?? $warehouse['warehouse_id'] ?? ''));
    $warehouseName = trim((string)($warehouse['name'] ?? $warehouse['warehouseName'] ?? $warehouse['warehouse_name'] ?? ''));
    if ($warehouseId === '' && $warehouseName === '') {
        return null;
    }
    return [
        'key' => $warehouseId !== '' ? ('id:' . $warehouseId) : ('name:' . mb_strtolower($warehouseName, 'UTF-8')),
        'warehouse_id' => $warehouseId,
        'warehouse_name' => $warehouseName !== '' ? $warehouseName : ('Яндекс склад #' . $warehouseId),
    ];
}

function stocks_tool_yandex_warehouse_options(array $cfg, array $connection): array
{
    $options = [];
    foreach (stocks_tool_yandex_warehouses_list($cfg, $connection) as $warehouse) {
        $entry = stocks_tool_yandex_warehouse_entry_from_row($warehouse);
        if (!is_array($entry)) {
            continue;
        }
        $options[(string)$entry['key']] = $entry;
    }
    ksort($options);
    return $options;
}

function stocks_tool_marketplace_key(array $row): string
{
    $marketplace = strtolower(trim((string)($row['marketplace'] ?? 'ozon')));
    return $marketplace !== '' ? $marketplace : 'ozon';
}

function stocks_tool_marketplace_item_label(string $marketplace): string
{
    return match (stocks_tool_marketplace_key(['marketplace' => $marketplace])) {
        'wb' => 'WB',
        'yandex_market' => 'Яндекс Маркет',
        default => 'Ozon',
    };
}

function stocks_tool_missing_marketplace_status(string $marketplace): string
{
    return match (stocks_tool_marketplace_key(['marketplace' => $marketplace])) {
        'wb' => 'missing_on_wb',
        'yandex_market' => 'missing_on_yandex',
        default => 'missing_on_ozon',
    };
}

function stocks_tool_missing_marketplace_total_key(string $marketplace): string
{
    return match (stocks_tool_marketplace_key(['marketplace' => $marketplace])) {
        'wb' => 'not_on_wb',
        'yandex_market' => 'not_on_yandex',
        default => 'not_on_ozon',
    };
}

function stocks_tool_wb_warehouses_list(array $cfg, array $connection): array
{
    $cfg = stocks_tool_cfg_fallback($cfg);
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $cacheKey = 'wb_warehouses_' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'seller_id' => (string)($connection['client_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, 300);
    if (is_array($cached) && is_array($cached['warehouses'] ?? null)) {
        return array_values(array_filter($cached['warehouses'], static fn($row): bool => is_array($row)));
    }

    $client = wb_price_tool_client($runtimeCfg, $connection);
    $response = $client->getSellerWarehouses();
    $warehouses = is_array($response) ? array_values(array_filter($response, static fn($row): bool => is_array($row))) : [];
    stocks_tool_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
    return $warehouses;
}

function stocks_tool_wb_warehouse_entry_from_row(array $warehouse): ?array
{
    if (!empty($warehouse['isDeleting'])) {
        return null;
    }
    $warehouseId = (int)($warehouse['id'] ?? 0);
    $warehouseName = trim((string)($warehouse['name'] ?? ''));
    if ($warehouseId <= 0) {
        return null;
    }
    return [
        'key' => 'id:' . $warehouseId,
        'warehouse_id' => (string)$warehouseId,
        'warehouse_name' => $warehouseName !== '' ? $warehouseName : ('WB склад #' . $warehouseId),
    ];
}

function stocks_tool_wb_warehouse_options(array $cfg, array $connection): array
{
    $options = [];
    foreach (stocks_tool_wb_warehouses_list($cfg, $connection) as $warehouse) {
        $entry = stocks_tool_wb_warehouse_entry_from_row($warehouse);
        if (!is_array($entry)) {
            continue;
        }
        $options[(string)$entry['key']] = $entry;
    }
    ksort($options);
    return $options;
}

function stocks_tool_warehouse_options(array $cfg, array $connection): array
{
    $marketplace = stocks_tool_marketplace_key($connection);
    if ($marketplace === 'wb') {
        return stocks_tool_wb_warehouse_options($cfg, $connection);
    }
    if ($marketplace === 'yandex_market') {
        return stocks_tool_yandex_warehouse_options($cfg, $connection);
    }
    return stocks_tool_ozon_warehouse_options(ozon_price_cfg_with_connection($cfg, $connection));
}

function stocks_tool_module_bootstrap(array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    stocks_tool_automation_table_ensure($cfg);
}

function stocks_tool_zero_rule_options_from_db(int $supplierId, array $cfg = []): array
{
    static $cache = [];
    if (isset($cache[$supplierId])) {
        return $cache[$supplierId];
    }
    if ($supplierId <= 0) {
        return ['categories' => [], 'brands' => []];
    }
    supplier_products_tables_ensure($cfg);
    $pdo = db();
    $categories = [];
    $brands = [];

    $st = $pdo->prepare("
        SELECT category_id, category_path, COUNT(*) AS qty
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND category_id <> ''
        GROUP BY category_id, category_path
        ORDER BY category_path ASC, category_id ASC
        LIMIT 1000
    ");
    $st->execute([$supplierId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $categoryId = trim((string)($row['category_id'] ?? ''));
        if ($categoryId === '') {
            continue;
        }
        $label = trim((string)($row['category_path'] ?? ''));
        $categories[$categoryId] = [
            'value' => $categoryId,
            'label' => $label !== '' ? $label : $categoryId,
            'count' => (int)($row['qty'] ?? 0),
        ];
    }

    $st = $pdo->prepare("
        SELECT brand, COUNT(*) AS qty
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND brand <> ''
        GROUP BY brand
        ORDER BY brand ASC
        LIMIT 1000
    ");
    $st->execute([$supplierId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $brand = trim((string)($row['brand'] ?? ''));
        if ($brand === '') {
            continue;
        }
        $brands[$brand] = [
            'value' => $brand,
            'label' => $brand,
            'count' => (int)($row['qty'] ?? 0),
        ];
    }

    $cache[$supplierId] = [
        'categories' => array_values($categories),
        'brands' => array_values($brands),
    ];
    return $cache[$supplierId];
}

function stocks_tool_parse_feed_zero_rule_options(string $xmlPath): array
{
    $options = ozon_price_parse_feed_force_rule_options($xmlPath);
    $categories = [];
    foreach ((array)($options['categories'] ?? []) as $category) {
        if (!is_array($category)) {
            continue;
        }
        $categoryId = trim((string)($category['id'] ?? ''));
        $value = $categoryId !== '' ? $categoryId : trim((string)($category['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $category['value'] = $value;
        $categories[] = $category;
    }
    $options['categories'] = $categories;
    return $options;
}

function stocks_tool_zero_rule_options_for_feed(array $feed, array $cfg = [], bool $allowRemoteRefresh = true): array
{
    $supplierId = (int)($feed['supplier_id'] ?? $feed['id'] ?? 0);
    $fromDb = stocks_tool_zero_rule_options_from_db($supplierId, $cfg);
    if (!empty($fromDb['categories']) || !empty($fromDb['brands'])) {
        return $fromDb;
    }

    $feedUrl = trim((string)($feed['feed_url'] ?? ''));
    if ($feedUrl === '' || !function_exists('ozon_price_feed_fetch_remote_xml')) {
        return ['categories' => [], 'brands' => []];
    }
    $cacheKey = 'zero_rule_options_' . sha1(json_encode([
        'supplier_id' => $supplierId,
        'feed_url' => $feedUrl,
        'supplier_code' => (string)($feed['supplier_code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, 3600);
    if (is_array($cached)) {
        return [
            'categories' => array_values(array_filter((array)($cached['categories'] ?? []), 'is_array')),
            'brands' => array_values(array_filter((array)($cached['brands'] ?? []), 'is_array')),
        ];
    }
    if (!$allowRemoteRefresh) {
        $stale = stocks_tool_ui_cache_read($cacheKey, 0);
        if (is_array($stale)) {
            return [
                'categories' => array_values(array_filter((array)($stale['categories'] ?? []), 'is_array')),
                'brands' => array_values(array_filter((array)($stale['brands'] ?? []), 'is_array')),
            ];
        }
        return ['categories' => [], 'brands' => []];
    }

    try {
        $download = ozon_price_feed_fetch_remote_xml($feedUrl);
        try {
            $options = stocks_tool_parse_feed_zero_rule_options((string)$download['path']);
        } finally {
            @unlink((string)$download['path']);
        }
        stocks_tool_ui_cache_write($cacheKey, $options);
        return $options;
    } catch (Throwable $e) {
        return ['categories' => [], 'brands' => []];
    }
}

function stocks_tool_qty_from_raw(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $value = ozon_price_to_float($raw);
    if ($value < 0) {
        return 0;
    }
    return (int)floor($value + 0.0000001);
}

function stocks_tool_norm_rule_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('ё', 'е', function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $value = preg_replace('~\s+~u', ' ', (string)$value);
    return trim((string)$value);
}

function stocks_tool_split_zero_offer_ids(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $parts = preg_split('~[\r\n,;]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || isset($seen[$part])) {
            continue;
        }
        $seen[$part] = true;
        $out[] = $part;
    }
    return $out;
}

function stocks_tool_zero_rule_category_set(array $profile, int $feedId): array
{
    $out = [];
    foreach ((array)(($profile['zero_supplier_categories'] ?? [])[$feedId] ?? []) as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $out[$value] = true;
        }
    }
    return $out;
}

function stocks_tool_zero_rule_brand_set(array $profile, int $feedId): array
{
    $out = [];
    foreach ((array)(($profile['zero_supplier_brands'] ?? [])[$feedId] ?? []) as $value) {
        $norm = stocks_tool_norm_rule_value((string)$value);
        if ($norm !== '') {
            $out[$norm] = true;
        }
    }
    return $out;
}

function stocks_tool_zero_rule_article_matches(array $profile, array $offerRow, string $supplierCode): bool
{
    $offerId = trim((string)($offerRow['offer_id'] ?? ''));
    $rawOfferId = trim((string)($offerRow['raw_offer_id'] ?? ''));
    if ($offerId === '' && $rawOfferId === '') {
        return false;
    }
    foreach (stocks_tool_split_zero_offer_ids((string)($profile['zero_offer_ids_text'] ?? '')) as $article) {
        if ($article === $offerId || ($rawOfferId !== '' && $article === $rawOfferId)) {
            return true;
        }
        if (!str_contains($article, '__') && $supplierCode !== '') {
            if (ozon_price_apply_supplier_code($article, $supplierCode) === $offerId) {
                return true;
            }
        }
    }
    return false;
}

function stocks_tool_zero_rule_reasons_for_offer(array $profile, int $feedId, array $offerRow, string $supplierCode): array
{
    $reasons = [];
    if (stocks_tool_zero_rule_article_matches($profile, $offerRow, $supplierCode)) {
        $reasons['article'] = true;
    }

    $categoryId = trim((string)($offerRow['category_id'] ?? ''));
    $categorySet = stocks_tool_zero_rule_category_set($profile, $feedId);
    if ($categoryId !== '' && isset($categorySet[$categoryId])) {
        $reasons['category'] = true;
    }

    $brandNorm = stocks_tool_norm_rule_value((string)($offerRow['brand'] ?? ''));
    $brandSet = stocks_tool_zero_rule_brand_set($profile, $feedId);
    if ($brandNorm !== '' && isset($brandSet[$brandNorm])) {
        $reasons['brand'] = true;
    }

    return array_keys($reasons);
}

function stocks_tool_zero_offer_supplier_code(string $offerId, array $feedRows): string
{
    foreach ($feedRows as $feed) {
        $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
        if ($supplierCode !== '' && stocks_tool_offer_matches_supplier_code($offerId, $supplierCode)) {
            return $supplierCode;
        }
    }
    if (preg_match('~__(.+)$~', $offerId, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function stocks_tool_add_zero_article_placeholders(array &$offerMap, array $profile, array $feedRows, callable $log): void
{
    $articles = stocks_tool_split_zero_offer_ids((string)($profile['zero_offer_ids_text'] ?? ''));
    if (!$articles) {
        return;
    }

    $added = 0;
    foreach ($articles as $article) {
        if (str_contains($article, '__')) {
            $offerId = trim($article);
            if ($offerId !== '' && !isset($offerMap[$offerId])) {
                $supplierCode = stocks_tool_zero_offer_supplier_code($offerId, $feedRows);
                $offerMap[$offerId] = [
                    'offer_id' => $offerId,
                    'raw_offer_id' => preg_replace('~__(.+)$~', '', $offerId),
                    'qty' => 0,
                    'raw_qty' => 0,
                    'price' => null,
                    'source_feed_id' => null,
                    'supplier_code' => $supplierCode,
                    'qty_source' => 'zero_rule',
                    'category_id' => '',
                    'category_path' => '',
                    'brand' => '',
                    'zero_rule_reasons' => ['article'],
                    'zero_rule_reason' => 'article',
                ];
                $added++;
            }
            continue;
        }

        foreach ($feedRows as $feed) {
            $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
            if ($supplierCode === '') {
                continue;
            }
            $offerId = ozon_price_apply_supplier_code($article, $supplierCode);
            if ($offerId === '' || isset($offerMap[$offerId])) {
                continue;
            }
            $offerMap[$offerId] = [
                'offer_id' => $offerId,
                'raw_offer_id' => $article,
                'qty' => 0,
                'raw_qty' => 0,
                'price' => null,
                'source_feed_id' => (int)($feed['id'] ?? 0) > 0 ? (int)$feed['id'] : null,
                'supplier_code' => $supplierCode,
                'qty_source' => 'zero_rule',
                'category_id' => '',
                'category_path' => '',
                'brand' => '',
                'zero_rule_reasons' => ['article'],
                'zero_rule_reason' => 'article',
            ];
            $added++;
        }
    }

    if ($added > 0) {
        $log('[zero rules] добавлено артикулов вне текущего фида: ' . $added . "\n");
    }
}

function stocks_tool_parse_feed_quantities(string $xmlPath, array $feed): array
{
    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть XML-фид поставщика.');
    }

    $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
    $feedId = (int)($feed['id'] ?? 0);
    $offers = [];
    $stats = [
        'offers_seen' => 0,
        'offers_with_qty' => 0,
        'duplicates' => 0,
        'invalid_qty' => 0,
        'missing_price' => 0,
        'zero_price' => 0,
    ];

    $inOffer = false;
    $offerDepth = -1;
    $currentId = '';
    $currentQtyRaw = null;
    $currentQtySource = '';
    $currentPrice = null;
    $currentPriceRaw = '';
    $currentPriceSource = '';
    $currentCategoryId = '';
    $currentBrand = '';
    $currentVendor = '';
    $catMap = [];

    while ($reader->read()) {
        if (!$inOffer && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'categories') {
            $categoriesXml = trim((string)$reader->readOuterXml());
            $catMap = supplier_products_category_map_from_xml($categoriesXml);
            supplier_products_skip_current_element($reader, 'categories', $reader->depth);
            continue;
        }

        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
            $inOffer = true;
            $offerDepth = $reader->depth;
            $currentId = trim((string)$reader->getAttribute('id'));
            $currentQtyRaw = null;
            $currentQtySource = '';
            $currentPrice = null;
            $currentPriceRaw = '';
            $currentPriceSource = '';
            $currentCategoryId = '';
            $currentBrand = '';
            $currentVendor = '';
            $stats['offers_seen']++;
            continue;
        }

        if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'stock' || $reader->name === 'count')) {
            $tag = $reader->name;
            $valueRaw = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($currentId !== '' && $valueRaw !== '') {
                if ($currentQtyRaw === null) {
                    $currentQtyRaw = $valueRaw;
                    $currentQtySource = $tag;
                } elseif ($tag === 'stock' && $currentQtySource !== 'stock') {
                    $currentQtyRaw = $valueRaw;
                    $currentQtySource = 'stock';
                } elseif ($tag === $currentQtySource) {
                    $currentQtyRaw = $valueRaw;
                }
            }
            continue;
        }

        if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'categoryId' || $reader->name === 'category')) {
            $valueRaw = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($currentCategoryId === '' && $valueRaw !== '') {
                $currentCategoryId = $valueRaw;
            }
            continue;
        }

        if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'brand' || $reader->name === 'vendor')) {
            $valueRaw = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($reader->name === 'brand') {
                $currentBrand = $valueRaw;
            } elseif ($currentVendor === '') {
                $currentVendor = $valueRaw;
            }
            continue;
        }

        if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'price_original' || $reader->name === 'price')) {
            $tag = $reader->name;
            $valueRaw = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($valueRaw !== '' && ($tag === 'price_original' || $currentPriceSource !== 'price_original')) {
                $price = ozon_price_to_float($valueRaw);
                $currentPrice = is_finite($price) ? round($price, 2) : 0.0;
                $currentPriceRaw = $valueRaw;
                $currentPriceSource = $tag;
            }
            continue;
        }

        if ($inOffer && $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
            if ($currentId !== '' && $currentQtyRaw !== null && $currentQtyRaw !== '') {
                $qty = stocks_tool_qty_from_raw((string)$currentQtyRaw);
                if ($qty !== null) {
                    $offerId = ozon_price_apply_supplier_code($currentId, $supplierCode);
                    if ($offerId !== '') {
                        if (isset($offers[$offerId])) {
                            $stats['duplicates']++;
                        } else {
                            $stats['offers_with_qty']++;
                        }
                        $offers[$offerId] = [
                            'offer_id' => $offerId,
                            'raw_offer_id' => $currentId,
                            'qty' => $qty,
                            'raw_qty' => $qty,
                            'price' => $currentPrice,
                            'price_raw' => $currentPriceRaw,
                            'price_source' => $currentPriceSource,
                            'price_missing_or_zero' => $currentPrice === null || $currentPrice <= 0,
                            'source_feed_id' => $feedId > 0 ? $feedId : null,
                            'supplier_code' => $supplierCode,
                            'qty_source' => $currentQtySource,
                            'category_id' => $currentCategoryId,
                            'category_path' => supplier_products_build_category_path($currentCategoryId, $catMap),
                            'brand' => $currentBrand !== '' ? $currentBrand : $currentVendor,
                        ];
                        if ($currentPrice === null) {
                            $stats['missing_price']++;
                        } elseif ($currentPrice <= 0) {
                            $stats['zero_price']++;
                        }
                    }
                } else {
                    $stats['invalid_qty']++;
                }
            }

            $inOffer = false;
            $offerDepth = -1;
            $currentId = '';
            $currentQtyRaw = null;
            $currentQtySource = '';
            $currentPrice = null;
            $currentPriceRaw = '';
            $currentPriceSource = '';
            $currentCategoryId = '';
            $currentBrand = '';
            $currentVendor = '';
        }
    }

    $reader->close();

    return [
        'offers' => $offers,
        'stats' => $stats,
    ];
}

function stocks_tool_download_feed_with_retry(array $feed, callable $log, int $maxAttempts = 3): array
{
    $feedId = (int)($feed['id'] ?? 0);
    $feedName = trim((string)($feed['name'] ?? ('Feed #' . $feedId)));
    $maxAttempts = max(1, min(5, $maxAttempts));
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return ozon_price_feed_fetch_remote_xml((string)($feed['feed_url'] ?? ''));
        } catch (Throwable $e) {
            $lastError = $e;
            $message = trim($e->getMessage());
            $retryable = preg_match('~пустой XML|HTTP (?:408|425|429|5\d\d)|timed? out|timeout|temporar|connection reset~iu', $message) === 1;
            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }
            $delay = $attempt === 1 ? 2 : 5;
            $log('[feed #' . $feedId . '] ' . $feedName
                . ' · источник временно недоступен: ' . $message
                . ' · повтор ' . ($attempt + 1) . '/' . $maxAttempts
                . ' через ' . $delay . " сек.\n");
            sleep($delay);
        }
    }

    throw $lastError ?? new RuntimeException('Не удалось скачать XML-фид поставщика.');
}

function stocks_tool_profile_feed_quantity_map(array $profile, array $feedRows, callable $log): array
{
    $offerMap = [];
    $feedSummaries = [];
    $successfulFeedIds = [];
    $successfulSupplierCodes = [];
    $failedFeeds = [];
    foreach ($feedRows as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        $feedName = trim((string)($feed['name'] ?? ('Feed #' . $feedId)));
        $supplierCode = trim((string)($feed['supplier_code'] ?? ''));
        $feedBuffer = max(0, (int)($feed['stock_buffer_qty'] ?? 0));
        $feedMinPrice = ($feed['stock_min_price'] ?? null) !== null ? round((float)$feed['stock_min_price'], 2) : null;
        $feedMaxPrice = ($feed['stock_max_price'] ?? null) !== null ? round((float)$feed['stock_max_price'], 2) : null;
        $forceZeroStock = !empty($feed['stock_force_zero']);

        try {
            $download = stocks_tool_download_feed_with_retry($feed, $log);
            try {
                $parsed = stocks_tool_parse_feed_quantities((string)$download['path'], $feed);
            } finally {
                @unlink((string)$download['path']);
            }
            $stats = is_array($parsed['stats'] ?? null) ? $parsed['stats'] : [];
            $feedOffers = is_array($parsed['offers'] ?? null) ? $parsed['offers'] : [];
            if ((int)($stats['offers_seen'] ?? 0) <= 0 || !$feedOffers) {
                throw new RuntimeException('XML не содержит товаров с распознанными остатками.');
            }
        } catch (Throwable $e) {
            $message = trim($e->getMessage()) ?: 'Не удалось прочитать XML-фид.';
            $failed = [
                'feed_id' => $feedId,
                'feed_name' => $feedName,
                'supplier_code' => $supplierCode,
                'status' => $forceZeroStock ? 'force_zero_without_feed' : 'unavailable',
                'error' => $message,
                'force_zero_stock' => $forceZeroStock ? 1 : 0,
            ];
            $failedFeeds[] = $failed;
            $feedSummaries[] = $failed + [
                'offers_seen' => 0,
                'offers_with_qty' => 0,
                'offers_total' => 0,
                'buffer_qty' => $feedBuffer,
                'min_price' => $feedMinPrice,
                'max_price' => $feedMaxPrice,
            ];
            $log('[feed #' . $feedId . '] ' . $feedName
                . ' · supplier=' . ($supplierCode !== '' ? $supplierCode : '—')
                . ' · НЕДОСТУПЕН: ' . $message
                . ($forceZeroStock
                    ? ' · включено принудительное обнуление, оно продолжит выполняться'
                    : ' · товары этого поставщика исключены из запуска, текущие остатки не изменятся')
                . "\n");
            continue;
        }

        $successfulFeedIds[$feedId] = true;
        if ($supplierCode !== '') {
            $successfulSupplierCodes[$supplierCode] = true;
        }
        $supplierControls = supplier_products_marketplace_controls_by_offer_ids(
            (int)($feed['supplier_id'] ?? $feed['id'] ?? 0),
            array_keys($feedOffers)
        );
        $priceFiltered = 0;
        $zeroByArticle = 0;
        $zeroByCategory = 0;
        $zeroByBrand = 0;
        $zeroBySupplier = 0;
        $zeroByNoPrice = 0;
        $zeroForced = 0;
        foreach ($feedOffers as $offerId => &$offerRow) {
            if (!is_array($offerRow)) {
                continue;
            }
            $rawQty = max(0, (int)($offerRow['raw_qty'] ?? $offerRow['qty'] ?? 0));
            $price = isset($offerRow['price']) && $offerRow['price'] !== null ? round((float)$offerRow['price'], 2) : null;
            $qty = $rawQty;
            $priceMissingOrZero = $price === null || $price <= 0;
            if ($priceMissingOrZero) {
                $qty = 0;
                $zeroByNoPrice++;
            }
            $priceOutOfRange = false;
            if ($price !== null && $price > 0) {
                if ($feedMinPrice !== null && $price < $feedMinPrice) {
                    $priceOutOfRange = true;
                }
                if ($feedMaxPrice !== null && $price > $feedMaxPrice) {
                    $priceOutOfRange = true;
                }
            }
            if ($priceOutOfRange) {
                $qty = 0;
                $priceFiltered++;
            }
            if ($feedBuffer > 0) {
                $qty = max(0, $qty - $feedBuffer);
            }
            $control = is_array($supplierControls[$offerId] ?? null) ? $supplierControls[$offerId] : null;
            if ($control) {
                $qty = supplier_products_apply_stock_modifier(
                    $qty,
                    (int)($control['marketplace_enabled'] ?? 1),
                    (int)($control['stock_modifier'] ?? 0)
                );
            }
            $zeroReasons = stocks_tool_zero_rule_reasons_for_offer($profile, $feedId, $offerRow, $supplierCode);
            if ($forceZeroStock && !in_array('supplier', $zeroReasons, true)) {
                $zeroReasons[] = 'supplier';
            }
            if ($zeroReasons) {
                $qty = 0;
                $zeroForced++;
                if (in_array('article', $zeroReasons, true)) {
                    $zeroByArticle++;
                }
                if (in_array('category', $zeroReasons, true)) {
                    $zeroByCategory++;
                }
                if (in_array('brand', $zeroReasons, true)) {
                    $zeroByBrand++;
                }
                if (in_array('supplier', $zeroReasons, true)) {
                    $zeroBySupplier++;
                }
            }
            $offerRow['qty'] = $qty;
            $offerRow['price_out_of_range'] = $priceOutOfRange;
            $offerRow['feed_buffer_qty'] = $feedBuffer;
            $offerRow['feed_min_price'] = $feedMinPrice;
            $offerRow['feed_max_price'] = $feedMaxPrice;
            $offerRow['price_missing_or_zero'] = $priceMissingOrZero;
            $offerRow['zero_rule_reasons'] = $zeroReasons;
            $offerRow['zero_rule_reason'] = implode(',', $zeroReasons);
            $offerRow['force_zero_stock'] = $forceZeroStock ? 1 : 0;
        }
        unset($offerRow);
        $feedSummaries[] = [
            'feed_id' => $feedId,
            'feed_name' => $feedName,
            'supplier_code' => $supplierCode,
            'status' => 'success',
            'error' => '',
            'offers_seen' => (int)($stats['offers_seen'] ?? 0),
            'offers_with_qty' => (int)($stats['offers_with_qty'] ?? 0),
            'duplicates' => (int)($stats['duplicates'] ?? 0),
            'invalid_qty' => (int)($stats['invalid_qty'] ?? 0),
            'missing_price' => (int)($stats['missing_price'] ?? 0),
            'zero_price' => (int)($stats['zero_price'] ?? 0),
            'buffer_qty' => $feedBuffer,
            'min_price' => $feedMinPrice,
            'max_price' => $feedMaxPrice,
            'force_zero_stock' => $forceZeroStock ? 1 : 0,
            'price_filtered' => $priceFiltered,
            'zero_no_price' => $zeroByNoPrice,
            'zero_by_article' => $zeroByArticle,
            'zero_by_category' => $zeroByCategory,
            'zero_by_brand' => $zeroByBrand,
            'zero_by_supplier' => $zeroBySupplier,
            'zero_forced' => $zeroForced,
            'offers_total' => count($feedOffers),
        ];
        $log('[feed #' . $feedId . '] ' . $feedName
            . ' · supplier=' . ($supplierCode !== '' ? $supplierCode : '—')
            . ' · offers_seen=' . (int)($stats['offers_seen'] ?? 0)
            . ' · with_qty=' . (int)($stats['offers_with_qty'] ?? 0)
            . ' · duplicates=' . (int)($stats['duplicates'] ?? 0)
            . ' · invalid_qty=' . (int)($stats['invalid_qty'] ?? 0)
            . ' · no_price=' . $zeroByNoPrice
            . ' · price_filtered=' . $priceFiltered
            . ' · zero_rules=' . $zeroForced
            . ' · supplier_zero=' . $zeroBySupplier
            . ' · feed_buffer=' . $feedBuffer
            . ' · parsed=' . count($feedOffers) . "\n");

        foreach ($feedOffers as $offerId => $row) {
            $offerMap[$offerId] = $row;
        }
    }

    stocks_tool_add_zero_article_placeholders($offerMap, $profile, $feedRows, $log);

    return [
        'offers' => $offerMap,
        'feeds' => $feedSummaries,
        'successful_feed_ids' => array_map('intval', array_keys($successfulFeedIds)),
        'successful_supplier_codes' => array_values(array_keys($successfulSupplierCodes)),
        'failed_feeds' => $failedFeeds,
    ];
}

function stocks_tool_supplier_codes_from_profile(array $profile): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        array_merge(
            (array)($profile['supplier_codes'] ?? []),
            (array)($profile['force_zero_supplier_codes'] ?? [])
        )
    ))));
}

function stocks_tool_yandex_offer_id_to_marketplace(string $offerId): string
{
    return bundle_offer_yandex_offer_id_to_marketplace($offerId);
}

function stocks_tool_yandex_offer_id_to_internal(string $offerId, array $supplierCodes = []): string
{
    return bundle_offer_yandex_offer_id_to_internal($offerId, $supplierCodes);
}

function stocks_tool_marketplace_offer_id_to_remote(string $marketplace, string $offerId): string
{
    return stocks_tool_marketplace_key(['marketplace' => $marketplace]) === 'yandex_market'
        ? stocks_tool_yandex_offer_id_to_marketplace($offerId)
        : trim($offerId);
}

function stocks_tool_marketplace_offer_id_to_internal(string $marketplace, string $offerId, array $supplierCodes = []): string
{
    return stocks_tool_marketplace_key(['marketplace' => $marketplace]) === 'yandex_market'
        ? stocks_tool_yandex_offer_id_to_internal($offerId, $supplierCodes)
        : trim($offerId);
}

function stocks_tool_offer_matches_supplier_code(string|int $offerId, string $supplierCode): bool
{
    $offerId = trim((string)$offerId);
    $supplierCode = trim($supplierCode);
    if ($offerId === '' || $supplierCode === '') {
        return false;
    }
    return str_ends_with($offerId, '__' . $supplierCode);
}

function stocks_tool_offer_supplier_code(string $offerId, array $supplierCodes): string
{
    foreach ($supplierCodes as $supplierCode) {
        $supplierCode = trim((string)$supplierCode);
        if ($supplierCode !== '' && stocks_tool_offer_matches_supplier_code($offerId, $supplierCode)) {
            return $supplierCode;
        }
    }
    return '';
}

function stocks_tool_scope_offer_ids_for_supplier(array $profile, string $supplierCode, array $cfg, callable $log): array
{
    $supplierCode = trim($supplierCode);
    if ($supplierCode === '') {
        return [];
    }
    $scope = [];
    if (stocks_tool_marketplace_key($profile) === 'wb') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        $offerIds = is_array($connection) ? stocks_tool_wb_offer_ids_cached($cfg, $connection, 1800) : [];
    } elseif (stocks_tool_marketplace_key($profile) === 'yandex_market') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        $offerIds = is_array($connection)
            ? stocks_tool_yandex_offer_ids_cached($cfg, $connection, (string)($profile['ozon_warehouse_id'] ?? ''), [$supplierCode], 1800)
            : [];
    } else {
        $offerIds = stocks_tool_ozon_offer_ids_cached($cfg, 1800);
    }
    foreach ($offerIds as $offerId) {
        if (stocks_tool_offer_matches_supplier_code($offerId, $supplierCode)) {
            $scope[$offerId] = true;
        }
    }

    $st = db()->prepare("
        SELECT offer_id
        FROM feedtools_marketplace_stock_item_state
        WHERE profile_id = ? AND warehouse_key = ? AND supplier_code = ?
    ");
    $st->execute([
        (int)($profile['id'] ?? 0),
        (string)($profile['ozon_warehouse_key'] ?? ''),
        $supplierCode,
    ]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId !== '') {
            $scope[$offerId] = true;
        }
    }

    $log('Supplier scope на ' . stocks_tool_marketplace_item_label(stocks_tool_marketplace_key($profile)) . ': supplier_code=' . $supplierCode . ' · scoped_offers=' . count($scope) . "\n");
    return array_values(array_keys($scope));
}

function stocks_tool_wb_offer_ids_cached(array $cfg, array $connection, int $ttlSec = 300): array
{
    $cacheKey = 'wb:offers:' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'seller_id' => (string)($connection['client_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, $ttlSec);
    if (is_array($cached) && is_array($cached['offer_ids'] ?? null)) {
        return array_values(array_filter(array_map('strval', $cached['offer_ids'])));
    }

    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $cards = wb_price_tool_fetch_all_cards($runtimeCfg, $connection, false, $ttlSec);
    $offerIds = [];
    foreach ((array)($cards['items'] ?? []) as $card) {
        if (!is_array($card)) {
            continue;
        }
        $vendorCode = trim((string)($card['vendorCode'] ?? ''));
        if ($vendorCode !== '') {
            $offerIds[$vendorCode] = true;
        }
    }
    stocks_tool_ui_cache_write($cacheKey, ['offer_ids' => array_keys($offerIds)]);
    return array_keys($offerIds);
}

function stocks_tool_ozon_offer_ids_cached(array $cfg, int $ttlSec = 300): array
{
    $oz = ozon_cfg_or_fail($cfg);
    $cacheKey = 'ozon:offers:' . sha1(json_encode([
        'client_id' => (string)($oz['client_id'] ?? ''),
        'base_url' => (string)($oz['base_url'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, $ttlSec);
    if (is_array($cached) && is_array($cached['offer_ids'] ?? null)) {
        return array_values(array_filter(array_map('strval', $cached['offer_ids'])));
    }

    $offerIds = ozon_fetch_all_offer_ids_v3($oz);
    stocks_tool_ui_cache_write($cacheKey, ['offer_ids' => array_values($offerIds)]);
    return array_values(array_filter(array_map('strval', $offerIds)));
}

function stocks_tool_yandex_stock_endpoint(int $campaignId): string
{
    return '/v2/campaigns/' . $campaignId . '/offers/stocks';
}

function stocks_tool_yandex_stocks_response_warehouses(array $body): array
{
    $result = is_array($body['result'] ?? null) ? $body['result'] : $body;
    return is_array($result['warehouses'] ?? null) ? $result['warehouses'] : [];
}

function stocks_tool_yandex_stocks_response_next_page_token(array $body): string
{
    $result = is_array($body['result'] ?? null) ? $body['result'] : $body;
    return trim((string)($result['paging']['nextPageToken'] ?? $body['paging']['nextPageToken'] ?? ''));
}

function stocks_tool_yandex_stock_counts(array $offer): array
{
    $available = null;
    $fit = null;
    $reserved = 0;

    foreach ((array)($offer['stocks'] ?? []) as $stock) {
        if (!is_array($stock)) {
            continue;
        }
        $type = strtoupper(trim((string)($stock['type'] ?? '')));
        $count = max(0, (int)($stock['count'] ?? 0));
        if (in_array($type, ['AVAILABLE', 'AVAILABLE_FOR_SALE'], true)) {
            $available = ($available ?? 0) + $count;
        } elseif ($type === 'FIT') {
            $fit = ($fit ?? 0) + $count;
        } elseif (in_array($type, ['FREEZE', 'FROZEN', 'RESERVED'], true)) {
            $reserved += $count;
        }
    }

    if ($available !== null) {
        $present = max(0, (int)$available);
    } elseif ($fit !== null) {
        $present = max(0, (int)$fit - $reserved);
    } else {
        $present = 0;
    }

    return [
        'present' => $present,
        'reserved' => $reserved,
    ];
}

function stocks_tool_yandex_stock_map_from_response(array $body, array $remoteToInternal, int $warehouseId, array $supplierCodes = []): array
{
    $map = [];
    foreach (stocks_tool_yandex_stocks_response_warehouses($body) as $warehouse) {
        if (!is_array($warehouse)) {
            continue;
        }
        $responseWarehouseId = (int)($warehouse['warehouseId'] ?? $warehouse['warehouse_id'] ?? $warehouse['id'] ?? 0);
        if ($warehouseId > 0 && $responseWarehouseId > 0 && $responseWarehouseId !== $warehouseId) {
            continue;
        }
        $offers = is_array($warehouse['offers'] ?? null) ? $warehouse['offers'] : (is_array($warehouse['skus'] ?? null) ? $warehouse['skus'] : []);
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $remoteOfferId = trim((string)($offer['offerId'] ?? $offer['sku'] ?? $offer['offer_id'] ?? ''));
            if ($remoteOfferId === '') {
                continue;
            }
            $offerId = $remoteToInternal[$remoteOfferId]
                ?? stocks_tool_yandex_offer_id_to_internal($remoteOfferId, $supplierCodes);
            if ($offerId === '') {
                continue;
            }
            $counts = stocks_tool_yandex_stock_counts($offer);
            $map[$offerId] = [
                'offer_id' => $offerId,
                'remote_offer_id' => $remoteOfferId,
                'product_id' => (int)($offer['marketSku'] ?? $offer['market_sku'] ?? 0),
                'present' => (int)$counts['present'],
                'reserved' => (int)$counts['reserved'],
            ];
        }
    }
    return $map;
}

function stocks_tool_yandex_offer_ids_cached(array $cfg, array $connection, string $warehouseId, array $supplierCodes = [], int $ttlSec = 300): array
{
    $cfg = stocks_tool_cfg_fallback($cfg);
    $context = stocks_tool_yandex_campaign_context($connection);
    $campaignId = (int)$context['campaign_id'];
    $warehouseIdInt = (int)$warehouseId;
    if ($warehouseIdInt <= 0) {
        return [];
    }

    $cacheKey = 'yandex:offers:' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'campaign_id' => $campaignId,
        'warehouse_id' => $warehouseIdInt,
        'api_key_hash' => sha1((string)($connection['api_key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = stocks_tool_ui_cache_read($cacheKey, $ttlSec);
    if (is_array($cached) && is_array($cached['offer_ids'] ?? null)) {
        return array_values(array_filter(array_map('strval', $cached['offer_ids'])));
    }

    $offerIds = [];
    $pageToken = '';
    for ($page = 0; $page < 200; $page++) {
        $query = ['limit' => 200];
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $payload = [
            'stocksWarehouseId' => $warehouseIdInt,
            'withTurnover' => false,
        ];
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            stocks_tool_yandex_stock_endpoint($campaignId),
            $query,
            $payload
        );
        foreach (stocks_tool_yandex_stocks_response_warehouses($body) as $warehouse) {
            if (!is_array($warehouse)) {
                continue;
            }
            $responseWarehouseId = (int)($warehouse['warehouseId'] ?? $warehouse['warehouse_id'] ?? $warehouse['id'] ?? 0);
            if ($responseWarehouseId > 0 && $responseWarehouseId !== $warehouseIdInt) {
                continue;
            }
            $offers = is_array($warehouse['offers'] ?? null) ? $warehouse['offers'] : (is_array($warehouse['skus'] ?? null) ? $warehouse['skus'] : []);
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $remoteOfferId = trim((string)($offer['offerId'] ?? $offer['sku'] ?? $offer['offer_id'] ?? ''));
                if ($remoteOfferId !== '') {
                    $internalOfferId = stocks_tool_yandex_offer_id_to_internal($remoteOfferId, $supplierCodes);
                    if ($internalOfferId !== '') {
                        $offerIds[$internalOfferId] = true;
                    }
                }
            }
        }
        $pageToken = stocks_tool_yandex_stocks_response_next_page_token($body);
        if ($pageToken === '') {
            break;
        }
    }

    $offerIds = array_values(array_filter(array_map('strval', array_keys($offerIds)), static fn(string $offerId): bool => trim($offerId) !== ''));
    stocks_tool_ui_cache_write($cacheKey, ['offer_ids' => $offerIds]);
    return $offerIds;
}

function stocks_tool_state_offer_ids(int $profileId, string $warehouseKey, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($profileId <= 0 || trim($warehouseKey) === '') {
        return [];
    }
    $st = db()->prepare("
        SELECT offer_id
        FROM feedtools_marketplace_stock_item_state
        WHERE profile_id = ? AND warehouse_key = ?
        ORDER BY offer_id ASC
    ");
    $st->execute([$profileId, $warehouseKey]);
    $offerIds = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId !== '') {
            $offerIds[] = $offerId;
        }
    }
    return array_values(array_unique($offerIds));
}

function stocks_tool_state_map(int $profileId, string $warehouseKey, array $offerIds, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    $offerIds = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $offerIds)));
    if ($profileId <= 0 || trim($warehouseKey) === '' || !$offerIds) {
        return [];
    }

    $map = [];
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $params = array_merge([$profileId, $warehouseKey], $chunk);
        $st = db()->prepare("
            SELECT *
            FROM feedtools_marketplace_stock_item_state
            WHERE profile_id = ? AND warehouse_key = ? AND offer_id IN ($placeholders)
        ");
        $st->execute($params);
        foreach ($st->fetchAll() ?: [] as $row) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId !== '') {
                $map[$offerId] = $row;
            }
        }
    }
    return $map;
}

function stocks_tool_scope_offer_ids(array $profile, array $feedOfferMap, array $cfg, callable $log): array
{
    $supplierCodes = stocks_tool_supplier_codes_from_profile($profile);
    $forceZeroSupplierCodes = stocks_tool_force_zero_supplier_codes($profile);
    $forceZeroSupplierSet = array_fill_keys($forceZeroSupplierCodes, true);
    $marketplace = stocks_tool_marketplace_key($profile);
    $scope = [];

    foreach (array_keys($feedOfferMap) as $offerId) {
        $scope[(string)$offerId] = true;
    }

    $includeMissingItems = !empty($profile['zero_missing_items']);

    if ($marketplace === 'wb') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        $connectionOfferIds = is_array($connection) ? stocks_tool_wb_offer_ids_cached($cfg, $connection, 1800) : [];
    } elseif ($marketplace === 'yandex_market') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        $connectionOfferIds = is_array($connection)
            ? stocks_tool_yandex_offer_ids_cached($cfg, $connection, (string)($profile['ozon_warehouse_id'] ?? ''), $supplierCodes, 1800)
            : [];
    } else {
        $connectionOfferIds = stocks_tool_ozon_offer_ids_cached($cfg, 1800);
    }
    $knownBundleAdded = 0;
    $forceZeroAdded = 0;
    foreach ($connectionOfferIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        foreach ($supplierCodes as $supplierCode) {
            if (stocks_tool_offer_matches_supplier_code($offerId, $supplierCode)) {
                $forceZeroSupplier = isset($forceZeroSupplierSet[$supplierCode]);
                if (!$includeMissingItems && !$forceZeroSupplier) {
                    $bundle = bundle_offer_parse($offerId);
                    if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
                        break;
                    }
                    if (empty($scope[$offerId])) {
                        $knownBundleAdded++;
                    }
                }
                if ($forceZeroSupplier && empty($scope[$offerId])) {
                    $forceZeroAdded++;
                }
                $scope[$offerId] = true;
                break;
            }
        }
    }

    foreach (stocks_tool_state_offer_ids((int)($profile['id'] ?? 0), (string)($profile['ozon_warehouse_key'] ?? ''), $cfg) as $offerId) {
        $stateSupplierCode = stocks_tool_offer_supplier_code((string)$offerId, $supplierCodes);
        if ($stateSupplierCode === '') {
            continue;
        }
        $forceZeroSupplier = $stateSupplierCode !== '' && isset($forceZeroSupplierSet[$stateSupplierCode]);
        if (!$includeMissingItems && !$forceZeroSupplier) {
            $bundle = bundle_offer_parse((string)$offerId);
            if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
                continue;
            }
            if (empty($scope[$offerId])) {
                $knownBundleAdded++;
            }
        }
        if ($forceZeroSupplier && empty($scope[$offerId])) {
            $forceZeroAdded++;
        }
        $scope[$offerId] = true;
    }

    if (!$includeMissingItems) {
        $log('Supplier scope на ' . stocks_tool_marketplace_item_label($marketplace)
            . ': режим без обнуления исчезнувших'
            . ' · bundle_cards=' . $knownBundleAdded
            . ' · supplier_zero_cards=' . $forceZeroAdded
            . ' · scoped_offers=' . count($scope) . "\n");
        return array_values(array_keys($scope));
    }

    $log('Supplier scope на ' . stocks_tool_marketplace_item_label($marketplace)
        . ': supplier_codes=' . implode(', ', $supplierCodes)
        . ($forceZeroSupplierCodes ? (' · supplier_zero=' . implode(', ', $forceZeroSupplierCodes)) : '')
        . ' · scoped_offers=' . count($scope) . "\n");
    return array_values(array_keys($scope));
}

function stocks_tool_wb_card_chrt_ids(array $card): array
{
    $ids = [];
    foreach ((array)($card['sizes'] ?? []) as $size) {
        if (!is_array($size)) {
            continue;
        }
        $chrtId = isset($size['chrtID']) && is_numeric($size['chrtID'])
            ? (int)$size['chrtID']
            : (isset($size['chrtId']) && is_numeric($size['chrtId']) ? (int)$size['chrtId'] : 0);
        if ($chrtId > 0) {
            $ids[$chrtId] = true;
        }
    }
    return array_keys($ids);
}

function stocks_tool_wb_card_stock_units(array $card): array
{
    $units = [];
    foreach ((array)($card['sizes'] ?? []) as $size) {
        if (!is_array($size)) {
            continue;
        }
        $chrtId = isset($size['chrtID']) && is_numeric($size['chrtID'])
            ? (int)$size['chrtID']
            : (isset($size['chrtId']) && is_numeric($size['chrtId']) ? (int)$size['chrtId'] : 0);
        if ($chrtId <= 0) {
            continue;
        }
        $skus = is_array($size['skus'] ?? null) ? $size['skus'] : [];
        $sku = '';
        foreach ($skus as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $sku = $candidate;
                break;
            }
        }
        $units[$chrtId] = [
            'chrt_id' => $chrtId,
            'sku' => $sku,
        ];
    }
    return array_values($units);
}

function stocks_tool_wb_card_for_offer_id(string $offerId, array $cardsIndex): ?array
{
    $byVendorCode = is_array($cardsIndex['by_vendor_code'] ?? null) ? $cardsIndex['by_vendor_code'] : [];
    foreach (array_values(array_unique(array_filter([$offerId, wb_price_tool_strip_supplier_suffix($offerId)]))) as $candidate) {
        if (isset($byVendorCode[$candidate]) && is_array($byVendorCode[$candidate])) {
            return $byVendorCode[$candidate];
        }
    }
    return null;
}

function stocks_tool_fetch_wb_stock_map(array $cfg, array $connection, array $offerIds, string $warehouseId, callable $log): array
{
    $offerIds = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $offerIds)));
    if (!$offerIds) {
        return [];
    }
    $warehouseIdInt = (int)$warehouseId;
    if ($warehouseIdInt <= 0) {
        throw new RuntimeException('У профиля не задан ID склада WB для проверки остатков.');
    }

    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $cardsIndex = wb_price_tool_fetch_all_cards($runtimeCfg, $connection, false, 1800);
    $byChrtId = [];
    $map = [];
    $skippedNoCard = 0;
    $skippedMultiSize = 0;
    foreach ($offerIds as $offerId) {
        $card = stocks_tool_wb_card_for_offer_id($offerId, $cardsIndex);
        if (!is_array($card)) {
            $skippedNoCard++;
            continue;
        }
        $stockUnits = stocks_tool_wb_card_stock_units($card);
        if (count($stockUnits) !== 1) {
            $skippedMultiSize++;
            continue;
        }
        $stockUnit = (array)$stockUnits[0];
        $chrtId = (int)($stockUnit['chrt_id'] ?? 0);
        $byChrtId[$chrtId] = $offerId;
        $map[$offerId] = [
            'offer_id' => $offerId,
            'product_id' => (int)($card['nmID'] ?? 0),
            'chrt_id' => $chrtId,
            'sku' => (string)($stockUnit['sku'] ?? ''),
            'present' => 0,
            'reserved' => 0,
        ];
    }

    $client = wb_price_tool_client($runtimeCfg, $connection);
    $batchNo = 0;
    foreach (array_chunk(array_keys($byChrtId), 1000) as $chunk) {
        $batchNo++;
        $resp = $client->getWarehouseStocks($warehouseIdInt, $chunk);
        $items = is_array($resp['stocks'] ?? null) ? $resp['stocks'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $chrtId = isset($item['chrtId']) && is_numeric($item['chrtId']) ? (int)$item['chrtId'] : 0;
            $offerId = $byChrtId[$chrtId] ?? '';
            if ($offerId !== '' && isset($map[$offerId])) {
                $map[$offerId]['present'] = max(0, (int)($item['amount'] ?? 0));
                $sku = trim((string)($item['sku'] ?? ''));
                if ($sku !== '') {
                    $map[$offerId]['sku'] = $sku;
                }
            }
        }
        $log('[wb stocks] batch ' . $batchNo . ': requested=' . count($chunk) . ' · returned=' . count($items) . "\n");
    }
    if ($skippedNoCard > 0 || $skippedMultiSize > 0) {
        $log('[wb map] skipped_no_card=' . $skippedNoCard . ' · skipped_multi_size=' . $skippedMultiSize . "\n");
    }

    return $map;
}

function stocks_tool_fetch_ozon_stock_map(array $cfg, array $offerIds, string $warehouseId, callable $log): array
{
    $offerIds = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $offerIds)));
    if (!$offerIds) {
        return [];
    }

    $oz = ozon_cfg_or_fail($cfg);
    $warehouseIdInt = (int)$warehouseId;
    $map = [];
    $batchNo = 0;

    foreach (array_chunk($offerIds, 500) as $chunk) {
        $batchNo++;
        $payload = [
            'filter' => [
                'offer_id' => array_values($chunk),
                'warehouse_id' => [$warehouseIdInt],
                'visibility' => 'ALL',
            ],
            'limit' => count($chunk),
        ];
        $resp = ozon_post_json($oz, '/v4/product/info/stocks', $payload);
        $items = is_array($resp['items'] ?? null) ? $resp['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId === '') {
                continue;
            }
            $present = 0;
            $reserved = 0;
            foreach ((array)($item['stocks'] ?? []) as $stockRow) {
                if (!is_array($stockRow)) {
                    continue;
                }
                $type = strtolower(trim((string)($stockRow['type'] ?? '')));
                if ($type !== '' && $type !== 'fbs') {
                    continue;
                }
                $present += max(0, (int)($stockRow['present'] ?? 0));
                $reserved += max(0, (int)($stockRow['reserved'] ?? 0));
            }
            $map[$offerId] = [
                'offer_id' => $offerId,
                'product_id' => (int)($item['product_id'] ?? 0),
                'present' => $present,
                'reserved' => $reserved,
            ];
        }
        $log('[ozon stocks] batch ' . $batchNo . ': requested=' . count($chunk) . ' · returned=' . count($items) . "\n");
    }

    return $map;
}

function stocks_tool_fetch_yandex_stock_map(array $cfg, array $connection, array $offerIds, string $warehouseId, callable $log): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(static fn($value): string => trim((string)$value), $offerIds))));
    if (!$offerIds) {
        return [];
    }

    $context = stocks_tool_yandex_campaign_context($connection);
    $campaignId = (int)$context['campaign_id'];
    $warehouseIdInt = (int)$warehouseId;
    if ($warehouseIdInt <= 0) {
        throw new RuntimeException('У профиля не задан ID склада Яндекс Маркета для проверки остатков.');
    }

    $remoteToInternal = [];
    $remoteOfferIds = [];
    foreach ($offerIds as $offerId) {
        $remoteOfferId = stocks_tool_yandex_offer_id_to_marketplace($offerId);
        if ($remoteOfferId === '') {
            continue;
        }
        $remoteToInternal[$remoteOfferId] = $offerId;
        $remoteOfferIds[] = $remoteOfferId;
    }

    $map = [];
    $batchNo = 0;
    foreach (array_chunk($remoteOfferIds, 200) as $chunk) {
        $batchNo++;
        $payload = [
            'stocksWarehouseId' => $warehouseIdInt,
            'offerIds' => array_values($chunk),
            'withTurnover' => false,
        ];
        $body = marketplace_connection_yandex_request(
            $connection,
            'POST',
            stocks_tool_yandex_stock_endpoint($campaignId),
            ['limit' => count($chunk)],
            $payload
        );
        $chunkMap = stocks_tool_yandex_stock_map_from_response($body, $remoteToInternal, $warehouseIdInt);
        foreach ($chunkMap as $offerId => $row) {
            $map[$offerId] = $row;
        }
        $log('[yandex stocks] batch ' . $batchNo . ': requested=' . count($chunk) . ' · returned=' . count($chunkMap) . "\n");
    }

    return $map;
}

function stocks_tool_fetch_marketplace_stock_map(array $cfg, array $connection, array $profile, array $offerIds, callable $log): array
{
    $marketplace = stocks_tool_marketplace_key($profile);
    if ($marketplace === 'wb') {
        return stocks_tool_fetch_wb_stock_map($cfg, $connection, $offerIds, (string)($profile['ozon_warehouse_id'] ?? ''), $log);
    }
    if ($marketplace === 'yandex_market') {
        return stocks_tool_fetch_yandex_stock_map($cfg, $connection, $offerIds, (string)($profile['ozon_warehouse_id'] ?? ''), $log);
    }
    return stocks_tool_fetch_ozon_stock_map($cfg, $offerIds, (string)($profile['ozon_warehouse_id'] ?? ''), $log);
}

function stocks_tool_calculate_target_qty(array $profile, int $feedQty, int $reservedQty): int
{
    return stocks_tool_apply_max_qty($profile, stocks_tool_available_qty_before_max($profile, $feedQty, $reservedQty));
}

function stocks_tool_available_qty_before_max(array $profile, int $feedQty, int $reservedQty): int
{
    $target = max(0, $feedQty);
    if (!empty($profile['subtract_reserved'])) {
        $target -= max(0, $reservedQty);
    }
    $target -= max(0, (int)($profile['buffer_qty'] ?? 0));
    return max(0, $target);
}

function stocks_tool_apply_max_qty(array $profile, int $targetQty): int
{
    $target = max(0, $targetQty);
    $maxQty = max(0, (int)($profile['max_qty'] ?? 0));
    if ($maxQty > 0) {
        $target = min($target, $maxQty);
    }
    return $target;
}

function stocks_tool_marketplace_stock_qty_limit(string $marketplace): int
{
    return $marketplace === 'wb' ? 999 : 0;
}

function stocks_tool_reserved_units_by_base_offer(array $stockMap): array
{
    $map = [];
    foreach ($stockMap as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $offerId = trim((string)($row['offer_id'] ?? $offerId));
        if ($offerId === '') {
            continue;
        }
        $reserved = max(0, (int)($row['reserved'] ?? 0));
        if ($reserved <= 0) {
            continue;
        }

        $bundle = bundle_offer_parse($offerId);
        if (!empty($bundle['is_bundle']) && !empty($bundle['format_valid'])) {
            $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
            if ($baseOfferId !== '') {
                $map[$baseOfferId] = (int)($map[$baseOfferId] ?? 0) + (int)bundle_offer_moysklad_quantity($offerId, $reserved);
            }
            continue;
        }

        $map[$offerId] = (int)($map[$offerId] ?? 0) + $reserved;
    }
    return $map;
}

function stocks_tool_scope_base_offer_ids(array $offerIds): array
{
    $map = [];
    foreach ($offerIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $map[$offerId] = true;
        $bundle = bundle_offer_parse($offerId);
        if (!empty($bundle['is_bundle']) && !empty($bundle['format_valid'])) {
            $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
            if ($baseOfferId !== '') {
                $map[$baseOfferId] = true;
            }
        }
    }
    return $map;
}

function stocks_tool_order_snapshot_counts_as_reserved(array $row, array $payload): bool
{
    $source = strtolower(trim((string)($row['order_source'] ?? $payload['_feedtools_source'] ?? '')));
    if ($source === 'fbo' || $source === 'fby' || str_contains($source, 'fbo') || str_contains($source, 'fby')) {
        return false;
    }

    $statusParts = [
        (string)($row['status'] ?? ''),
        (string)($row['substatus'] ?? ''),
        (string)($payload['status'] ?? ''),
        (string)($payload['substatus'] ?? ''),
        (string)($payload['_feedtools_effective_status'] ?? ''),
    ];
    $statusText = strtolower(trim(implode(' ', array_filter($statusParts, static fn(string $value): bool => trim($value) !== ''))));
    if ($statusText === '') {
        return false;
    }

    foreach ([
        'cancel',
        'cancelled',
        'canceled',
        'отмен',
        'delivered',
        'complete',
        'completed',
        'done',
        'closed',
        'return',
        'returned',
        'partially_returned',
        'refund',
        'rejected',
        'declined',
        'defect',
        'lost',
    ] as $finalMarker) {
        if (str_contains($statusText, $finalMarker)) {
            return false;
        }
    }

    return true;
}

function stocks_tool_order_snapshot_product_reserved_unit(array $product, string $marketplace): ?array
{
    $normalized = function_exists('orders_sync_moysklad_position_product')
        ? orders_sync_moysklad_position_product($product)
        : $product;

    $offerId = trim((string)(
        $normalized['offer_id']
        ?? $product['offer_id']
        ?? $product['sku']
        ?? $product['marketplace_offer_id']
        ?? ''
    ));
    if ($offerId === '') {
        return null;
    }

    $offerId = stocks_tool_marketplace_offer_id_to_internal($marketplace, $offerId);
    $bundle = bundle_offer_parse($offerId);
    $qty = max(0.0, (float)($normalized['quantity'] ?? $product['quantity'] ?? 1));
    if ($qty <= 0.0) {
        return null;
    }

    if (!empty($bundle['is_bundle']) && !empty($bundle['format_valid'])) {
        $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
        if ($baseOfferId === '') {
            return null;
        }
        return [
            'offer_id' => $baseOfferId,
            'quantity' => (int)ceil(bundle_offer_moysklad_quantity($offerId, $qty)),
        ];
    }

    return [
        'offer_id' => $offerId,
        'quantity' => (int)ceil($qty),
    ];
}

function stocks_tool_order_snapshot_reserved_units_by_base_offer(
    array $scopeOfferIds,
    int $currentConnectionId,
    array $currentConnectionReservedUnits,
    array $cfg,
    callable $log
): array {
    if (!function_exists('orders_sync_orders_table_ensure')) {
        return ['current' => [], 'other' => []];
    }

    orders_sync_orders_table_ensure($cfg);

    $scopeMap = stocks_tool_scope_base_offer_ids($scopeOfferIds);
    if (!$scopeMap) {
        return ['current' => [], 'other' => []];
    }

    $days = max(1, min(365, (int)($cfg['stocks_tool']['global_reserve_order_days'] ?? 45)));
    $limit = max(1000, min(200000, (int)($cfg['stocks_tool']['global_reserve_order_limit'] ?? 50000)));
    $sql = "
        SELECT id, connection_id, marketplace, order_source, posting_number,
               status, substatus, payload_json
        FROM feedtools_marketplace_order_snapshots
        WHERE marketplace IN ('ozon', 'wb', 'yandex_market')
          AND COALESCE(last_seen_at, synced_at, order_created_at, created_at) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        ORDER BY synced_at DESC, id DESC
        LIMIT {$limit}
    ";

    $map = ['current' => [], 'other' => []];
    $seenOrders = [];
    $rowsSeen = 0;
    $rowsReserved = 0;
    $productsReserved = 0;
    $currentProductsReserved = 0;

    $st = db()->query($sql);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rowsSeen++;
        $marketplace = stocks_tool_marketplace_key(['marketplace' => (string)($row['marketplace'] ?? '')]);
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        if (!stocks_tool_order_snapshot_counts_as_reserved($row, $payload)) {
            continue;
        }

        $orderKey = implode('|', [
            (string)($row['connection_id'] ?? 0),
            $marketplace,
            strtolower(trim((string)($row['order_source'] ?? ''))),
            trim((string)($row['posting_number'] ?? '')) ?: ('id:' . (string)($row['id'] ?? '')),
        ]);
        if (isset($seenOrders[$orderKey])) {
            continue;
        }
        $seenOrders[$orderKey] = true;
        $rowsReserved++;

        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $unit = stocks_tool_order_snapshot_product_reserved_unit($product, $marketplace);
            if (!is_array($unit)) {
                continue;
            }
            $offerId = trim((string)($unit['offer_id'] ?? ''));
            $quantity = max(0, (int)($unit['quantity'] ?? 0));
            if ($offerId === '' || $quantity <= 0 || !isset($scopeMap[$offerId])) {
                continue;
            }

            $bucket = ($currentConnectionId > 0 && (int)($row['connection_id'] ?? 0) === $currentConnectionId)
                ? 'current'
                : 'other';
            $map[$bucket][$offerId] = (int)($map[$bucket][$offerId] ?? 0) + $quantity;
            $productsReserved++;
            if ($bucket === 'current') {
                $currentProductsReserved++;
            }
        }
    }

    $log('[global reserve orders] days=' . $days
        . ' · rows_seen=' . $rowsSeen
        . ' · active_orders=' . $rowsReserved
        . ' · reserved_products=' . $productsReserved
        . ' · current_connection_products=' . $currentProductsReserved . "\n");

    return $map;
}

function stocks_tool_global_reserved_units_by_base_offer(
    array $scopeOfferIds,
    int $currentConnectionId,
    array $currentStockMap,
    array $cfg,
    callable $log
): array {
    $currentReservedUnits = stocks_tool_reserved_units_by_base_offer($currentStockMap);
    $orderReserveBuckets = stocks_tool_order_snapshot_reserved_units_by_base_offer(
        $scopeOfferIds,
        $currentConnectionId,
        $currentReservedUnits,
        $cfg,
        $log
    );

    $currentOrderReservedUnits = is_array($orderReserveBuckets['current'] ?? null) ? $orderReserveBuckets['current'] : [];
    $otherOrderReservedUnits = is_array($orderReserveBuckets['other'] ?? null) ? $orderReserveBuckets['other'] : [];
    $offerIds = array_fill_keys(array_merge(
        array_keys($currentReservedUnits),
        array_keys($currentOrderReservedUnits),
        array_keys($otherOrderReservedUnits)
    ), true);

    $total = [];
    foreach (array_keys($offerIds) as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $currentApiQty = max(0, (int)($currentReservedUnits[$offerId] ?? 0));
        $currentOrderQty = max(0, (int)($currentOrderReservedUnits[$offerId] ?? 0));
        $otherOrderQty = max(0, (int)($otherOrderReservedUnits[$offerId] ?? 0));
        $totalQty = max($currentApiQty, $currentOrderQty) + $otherOrderQty;
        if ($totalQty > 0) {
            $total[$offerId] = $totalQty;
        }
    }

    $sumCurrent = array_sum(array_map('intval', $currentReservedUnits));
    $sumCurrentOrders = array_sum(array_map('intval', $currentOrderReservedUnits));
    $sumOtherOrders = array_sum(array_map('intval', $otherOrderReservedUnits));
    $sumTotal = array_sum(array_map('intval', $total));
    $log('[global reserve] current_api=' . $sumCurrent
        . ' · current_orders=' . $sumCurrentOrders
        . ' · other_orders=' . $sumOtherOrders
        . ' · total=' . $sumTotal
        . ' · offers=' . count(array_filter($total, static fn($value): bool => (int)$value > 0)) . "\n");

    return $total;
}

function stocks_tool_bundle_feed_row(string $offerId, array $feedOfferMap): ?array
{
    $bundle = bundle_offer_parse($offerId);
    if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
        return null;
    }

    $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
    $bundleQty = max(1, (int)($bundle['bundle_qty'] ?? 1));
    $baseRow = $baseOfferId !== '' && is_array($feedOfferMap[$baseOfferId] ?? null)
        ? $feedOfferMap[$baseOfferId]
        : null;
    if (!is_array($baseRow)) {
        return null;
    }

    $baseQty = max(0, (int)($baseRow['qty'] ?? 0));
    $baseRawQty = max(0, (int)($baseRow['raw_qty'] ?? $baseQty));
    $row = $baseRow;
    $row['offer_id'] = $offerId;
    $row['raw_offer_id'] = trim((string)($bundle['base_supplier_article'] ?? ''));
    $row['qty'] = bundle_offer_bundle_units_from_base($offerId, $baseQty);
    $row['raw_qty'] = bundle_offer_bundle_units_from_base($offerId, $baseRawQty);
    $row['qty_source'] = 'bundle_from_base';
    $row['bundle_base_offer_id'] = $baseOfferId;
    $row['bundle_qty'] = $bundleQty;
    $row['bundle_base_qty'] = $baseQty;

    return $row;
}

function stocks_tool_build_state_rows(
    array $profile,
    array $scopeOfferIds,
    array $feedOfferMap,
    array $ozonStockMap,
    array $stateMap,
    array $cfg = [],
    ?callable $log = null,
    array $globalReservedUnitsByBaseOffer = []
): array
{
    $warehouseKey = trim((string)($profile['ozon_warehouse_key'] ?? ''));
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $marketplace = stocks_tool_marketplace_key($profile);
    $missingStatus = stocks_tool_missing_marketplace_status($marketplace);
    $missingTotalKey = stocks_tool_missing_marketplace_total_key($marketplace);
    $supplierCodes = stocks_tool_supplier_codes_from_profile($profile);
    $forceZeroSupplierSet = array_fill_keys(stocks_tool_force_zero_supplier_codes($profile), true);
    $reservedUnitsByBaseOffer = $globalReservedUnitsByBaseOffer ?: stocks_tool_reserved_units_by_base_offer($ozonStockMap);
    $now = date('Y-m-d H:i:s');
    $fboZeroActiveMap = [];
    if ($marketplace === 'ozon' && $connectionId > 0) {
        $zeroEnabledOfferIds = ozon_fbo_tool_zero_fbs_enabled_offer_ids($connectionId, $scopeOfferIds, $cfg);
        if ($zeroEnabledOfferIds) {
            $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $zeroEnabledOfferIds, $cfg, $log);
            if ($log !== null) {
                $log('[fbo zero fbs] enabled=' . count($zeroEnabledOfferIds)
                    . ' · checked=' . (int)($refresh['requested'] ?? 0)
                    . ' · active_fbo=' . (int)($refresh['fbo_active'] ?? 0)
                    . ' · rules_annulled=' . (int)($refresh['rules_annulled'] ?? 0) . "\n");
            }
            $fboZeroActiveMap = ozon_fbo_tool_zero_fbs_active_map($connectionId, $scopeOfferIds, $cfg);
        }
    }

    $rows = [];
    $updates = [];
    $totals = [
        'scoped' => 0,
        'feed_seen' => count($feedOfferMap),
        'missing_zeroed' => 0,
        'unchanged' => 0,
        'to_update' => 0,
        'zero_forced' => 0,
        'zero_no_price' => 0,
        'zero_by_article' => 0,
        'zero_by_category' => 0,
        'zero_by_brand' => 0,
        'supplier_force_zero' => 0,
        'fbo_fbs_zeroed' => 0,
        'marketplace_qty_capped' => 0,
        'marketplace_forced_resend' => 0,
        'bundle_scoped' => 0,
        'bundle_derived' => 0,
        'bundle_missing_base' => 0,
        'bundle_invalid' => 0,
        $missingTotalKey => 0,
    ];

    foreach ($scopeOfferIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $totals['scoped']++;
        $bundle = bundle_offer_parse($offerId);
        $isBundle = !empty($bundle['is_bundle']);
        if (!$isBundle && str_contains($offerId, '##') && empty($bundle['format_valid'])) {
            $totals['bundle_invalid']++;
        }
        if ($isBundle) {
            $totals['bundle_scoped']++;
        }
        $explicitFeedRow = $feedOfferMap[$offerId] ?? null;
        $explicitBundleZeroRule = $isBundle
            && is_array($explicitFeedRow)
            && !empty($explicitFeedRow['zero_rule_reasons'])
            && is_array($explicitFeedRow['zero_rule_reasons']);
        if ($isBundle) {
            if ($explicitBundleZeroRule) {
                $feedRow = $explicitFeedRow;
            } else {
                $feedRow = stocks_tool_bundle_feed_row($offerId, $feedOfferMap);
                if (is_array($feedRow)) {
                    $totals['bundle_derived']++;
                } else {
                    $totals['bundle_missing_base']++;
                    $feedRow = $explicitFeedRow;
                }
            }
        } else {
            $feedRow = $explicitFeedRow;
        }
        $stateRow = $stateMap[$offerId] ?? null;
        $ozonRow = $ozonStockMap[$offerId] ?? null;
        $supplierCode = is_array($feedRow)
            ? trim((string)($feedRow['supplier_code'] ?? ''))
            : (trim((string)($stateRow['supplier_code'] ?? '')) ?: stocks_tool_offer_supplier_code($offerId, $supplierCodes));
        $isForceZeroSupplier = $supplierCode !== '' && isset($forceZeroSupplierSet[$supplierCode]);

        $isMissingFromFeed = !is_array($feedRow);
        if ($isMissingFromFeed && empty($profile['zero_missing_items']) && !$isForceZeroSupplier) {
            continue;
        }
        if ($isMissingFromFeed) {
            $totals['missing_zeroed']++;
        }
        if ($isForceZeroSupplier) {
            $totals['supplier_force_zero']++;
        }
        if (is_array($feedRow) && !empty($feedRow['zero_rule_reasons']) && is_array($feedRow['zero_rule_reasons'])) {
            $totals['zero_forced']++;
            if (in_array('article', (array)$feedRow['zero_rule_reasons'], true)) {
                $totals['zero_by_article']++;
            }
            if (in_array('category', (array)$feedRow['zero_rule_reasons'], true)) {
                $totals['zero_by_category']++;
            }
            if (in_array('brand', (array)$feedRow['zero_rule_reasons'], true)) {
                $totals['zero_by_brand']++;
            }
        }
        if (is_array($feedRow) && !empty($feedRow['price_missing_or_zero'])) {
            $totals['zero_no_price']++;
        }

        $feedQty = is_array($feedRow) ? max(0, (int)($feedRow['qty'] ?? 0)) : 0;
        $ownReservedQty = is_array($ozonRow) ? max(0, (int)($ozonRow['reserved'] ?? 0)) : 0;
        $reservedQty = $ownReservedQty;
        $presentQty = is_array($ozonRow) ? max(0, (int)($ozonRow['present'] ?? 0)) : 0;
        if ($isBundle && is_array($feedRow) && isset($feedRow['bundle_base_offer_id'], $feedRow['bundle_qty'], $feedRow['bundle_base_qty'])) {
            $baseOfferId = trim((string)$feedRow['bundle_base_offer_id']);
            $baseFeedQty = max(0, (int)$feedRow['bundle_base_qty']);
            $baseReservedUnits = max(0, (int)($reservedUnitsByBaseOffer[$baseOfferId] ?? 0));
            $reservedQty = bundle_offer_bundle_units_from_base($offerId, $baseReservedUnits);
            $baseAvailableUnits = stocks_tool_available_qty_before_max($profile, $baseFeedQty, $baseReservedUnits);
            $targetQty = stocks_tool_apply_max_qty($profile, bundle_offer_bundle_units_from_base($offerId, $baseAvailableUnits));
        } else {
            if (!$isBundle && isset($reservedUnitsByBaseOffer[$offerId])) {
                $reservedQty = max(0, (int)$reservedUnitsByBaseOffer[$offerId]);
            }
            $targetQty = stocks_tool_calculate_target_qty($profile, $feedQty, $reservedQty);
        }
        if ($isForceZeroSupplier) {
            $targetQty = 0;
        }
        $targetQtyBeforeFboZero = $targetQty;
        $fboZeroRow = is_array($fboZeroActiveMap[$offerId] ?? null) ? (array)$fboZeroActiveMap[$offerId] : null;
        if ($fboZeroRow) {
            $targetQty = 0;
            $totals['fbo_fbs_zeroed']++;
        }
        $marketplaceQtyLimit = stocks_tool_marketplace_stock_qty_limit($marketplace);
        if ($marketplaceQtyLimit > 0 && $targetQtyBeforeFboZero > $marketplaceQtyLimit) {
            $targetQtyBeforeFboZero = $marketplaceQtyLimit;
        }
        if ($marketplaceQtyLimit > 0 && $targetQty > $marketplaceQtyLimit) {
            if ($log !== null) {
                $log('[stock cap] ' . stocks_tool_marketplace_item_label($marketplace)
                    . ': ' . $offerId . ' target_qty=' . $targetQty
                    . ' ограничен до ' . $marketplaceQtyLimit . " из-за лимита API\n");
            }
            $targetQty = $marketplaceQtyLimit;
            $totals['marketplace_qty_capped']++;
        }
        $sourceFeedId = is_array($feedRow)
            ? (int)($feedRow['source_feed_id'] ?? 0)
            : (int)($stateRow['source_feed_id'] ?? 0);
        $isOnOzonNow = is_array($ozonRow);
        $forceMarketplaceResend = $marketplace === 'wb' && $isOnOzonNow && $targetQty > 0;

        $status = 'skipped_same';
        $errorText = null;
        if (!$isOnOzonNow) {
            $status = $missingStatus;
            $totals[$missingTotalKey]++;
        } elseif ($presentQty !== $targetQty || $forceMarketplaceResend) {
            $status = 'pending_update';
            $totals['to_update']++;
            if ($forceMarketplaceResend && $presentQty === $targetQty) {
                $totals['marketplace_forced_resend']++;
            }
            $updates[$offerId] = [
                'offer_id' => $offerId,
                'target_qty' => $targetQty,
                'target_qty_before_fbo_zero' => $targetQtyBeforeFboZero,
                'present_qty' => $presentQty,
                'feed_qty' => $feedQty,
                'reserved_qty' => $reservedQty,
                'fbo_zero_fbs_active' => $fboZeroRow !== null,
                'source_feed_id' => $sourceFeedId > 0 ? $sourceFeedId : null,
                'supplier_code' => $supplierCode,
                'product_id' => (int)($ozonRow['product_id'] ?? 0),
                'chrt_id' => (int)($ozonRow['chrt_id'] ?? 0),
                'sku' => (string)($ozonRow['sku'] ?? ''),
            ];
        } else {
            $totals['unchanged']++;
        }

        $lastPushedQty = $status === 'skipped_same'
            ? $targetQty
            : max(0, (int)($stateRow['last_pushed_qty'] ?? 0));
        if ($isForceZeroSupplier && !$isOnOzonNow) {
            $lastPushedQty = 0;
        }

        $rows[$offerId] = [
            'profile_id' => (int)($profile['id'] ?? 0),
            'connection_id' => $connectionId,
            'marketplace' => $marketplace,
            'warehouse_key' => $warehouseKey,
            'offer_id' => $offerId,
            'supplier_code' => $supplierCode,
            'source_feed_id' => $sourceFeedId > 0 ? $sourceFeedId : null,
            'last_feed_qty' => $feedQty,
            'last_reserved_qty' => $reservedQty,
            'last_target_qty' => $targetQty,
            'last_pushed_qty' => $lastPushedQty,
            'fbo_zero_fbs_active' => $fboZeroRow !== null,
            'fbo_present_qty' => $fboZeroRow !== null ? max(0, (int)($fboZeroRow['fbo_present'] ?? 0)) : 0,
            'last_seen_in_feed_at' => is_array($feedRow) ? $now : ($stateRow['last_seen_in_feed_at'] ?? null),
            'last_seen_on_ozon_at' => $isOnOzonNow ? $now : null,
            'last_push_status' => $status,
            'last_push_error' => $errorText,
        ];
    }

    return [
        'state_rows' => $rows,
        'updates' => $updates,
        'totals' => $totals,
    ];
}

function stocks_tool_wb_stock_error_is_transient(string $message): bool
{
    return preg_match('~\b(409|429|500|502|503|504)\b~', $message) === 1;
}

function stocks_tool_wb_stock_retry_delay(int $attempt, string $message): int
{
    if (preg_match('~retry[-_ ]?after["\':\s]+(\d+)~i', $message, $m)) {
        return min(120, max(1, (int)$m[1]));
    }
    if (str_contains($message, 'HTTP 409')) {
        return $attempt === 1 ? 12 : 30;
    }
    if (str_contains($message, 'HTTP 429')) {
        return $attempt === 1 ? 20 : 45;
    }
    return $attempt === 1 ? 6 : 15;
}

function stocks_tool_wb_push_chunk(
    WildberriesClient $client,
    int $warehouseId,
    array $chunk,
    int $batchNo,
    int $depth,
    string $labelSuffix,
    callable $log,
    array &$results,
    array &$totals
): void {
    $chunk = array_values(array_filter($chunk, 'is_array'));
    if (!$chunk) {
        return;
    }

    $payload = array_map(
        static function (array $row): array {
            $payloadRow = [
                'chrtId' => (int)$row['chrtId'],
                'amount' => max(0, (int)$row['amount']),
            ];
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku !== '') {
                $payloadRow['sku'] = $sku;
            }
            return $payloadRow;
        },
        $chunk
    );
    $label = 'batch ' . $batchNo . ($labelSuffix !== '' ? '.' . $labelSuffix : '');
    $maxAttempts = $depth === 0 ? 2 : 1;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $client->updateWarehouseStocks($warehouseId, $payload);
            $totals['sent'] += count($chunk);
            $totals['updated'] += count($chunk);
            foreach ($chunk as $row) {
                $results[(string)$row['offer_id']] = [
                    'status' => 'updated',
                    'message' => 'Остаток WB обновлён.',
                ];
            }
            if ($attempt > 1 || $depth > 0) {
                $log('[wb push] ' . $label . ': ok items=' . count($chunk) . "\n");
            }
            return;
        } catch (Throwable $error) {
            $lastError = $error;
            $message = $error->getMessage();
            if ($attempt < $maxAttempts && stocks_tool_wb_stock_error_is_transient($message)) {
                $delay = stocks_tool_wb_stock_retry_delay($attempt, $message);
                $log('[wb push] ' . $label . ': ошибка, повтор через ' . $delay . ' сек · ' . $message . "\n");
                sleep($delay);
                continue;
            }
            break;
        }
    }

    $message = $lastError instanceof Throwable ? $lastError->getMessage() : 'Неизвестная ошибка WB.';
    $count = count($chunk);
    if ($count > 1 && $depth < 3) {
        $splitSize = $count > 100 ? 100 : ($count > 20 ? 20 : 1);
        $log('[wb push] ' . $label . ': WB не принял пачку items=' . $count
            . ', дроблю по ' . $splitSize . ' · ' . $message . "\n");
        $partNo = 0;
        foreach (array_chunk($chunk, $splitSize) as $part) {
            $partNo++;
            $partLabel = ($labelSuffix !== '' ? $labelSuffix . '.' : '') . (string)$partNo;
            stocks_tool_wb_push_chunk($client, $warehouseId, $part, $batchNo, $depth + 1, $partLabel, $log, $results, $totals);
        }
        return;
    }

    $totals['errors'] += count($chunk);
    foreach ($chunk as $row) {
        $results[(string)$row['offer_id']] = [
            'status' => 'error',
            'message' => 'WB не принял остаток: ' . $message,
        ];
    }
    $log('[wb push] ' . $label . ': error items=' . count($chunk) . ' · ' . $message . "\n");
}

function stocks_tool_stock_push_lock_name(array $profile): string
{
    $marketplace = stocks_tool_marketplace_key($profile);
    $basis = implode('|', [
        $marketplace,
        (string)($profile['connection_id'] ?? ''),
        (string)($profile['ozon_warehouse_key'] ?? ''),
        (string)($profile['ozon_warehouse_id'] ?? ''),
    ]);
    return 'feedtools_stock_' . sha1($basis);
}

function stocks_tool_stock_push_lock_acquire(string $name, int $timeoutSec = 0): bool
{
    $name = substr($name, 0, 64);
    try {
        $st = db()->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, max(0, $timeoutSec)]);
        return (int)($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return true;
    }
}

function stocks_tool_stock_push_lock_release(string $name): void
{
    $name = substr($name, 0, 64);
    try {
        $st = db()->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$name]);
    } catch (Throwable $e) {
        // Some local/test DB engines do not support advisory locks.
    }
}

function stocks_tool_ozon_auto_unarchive_bool($value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int)$value) !== 0;
    }
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $default;
}

function stocks_tool_ozon_auto_unarchive_enabled(array $cfg): bool
{
    $toolCfg = is_array($cfg['stocks_tool'] ?? null) ? (array)$cfg['stocks_tool'] : [];
    if (array_key_exists('ozon_auto_unarchive_enabled', $toolCfg)) {
        return stocks_tool_ozon_auto_unarchive_bool($toolCfg['ozon_auto_unarchive_enabled'], true);
    }
    return true;
}

function stocks_tool_ozon_auto_unarchive_limit(array $cfg): int
{
    $toolCfg = is_array($cfg['stocks_tool'] ?? null) ? (array)$cfg['stocks_tool'] : [];
    return min(100, max(0, (int)($toolCfg['ozon_auto_unarchive_limit_per_day'] ?? 100)));
}

function stocks_tool_ozon_auto_unarchive_offer_scan_limit(array $cfg): int
{
    $toolCfg = is_array($cfg['stocks_tool'] ?? null) ? (array)$cfg['stocks_tool'] : [];
    return min(20000, max(100, (int)($toolCfg['ozon_auto_unarchive_offer_scan_limit'] ?? 5000)));
}

function stocks_tool_ozon_auto_unarchive_detail_limit(array $cfg, int $dailyLimit): int
{
    $toolCfg = is_array($cfg['stocks_tool'] ?? null) ? (array)$cfg['stocks_tool'] : [];
    $default = max(100, min(500, $dailyLimit * 3));
    return min(1000, max($dailyLimit, (int)($toolCfg['ozon_auto_unarchive_detail_limit'] ?? $default)));
}

function stocks_tool_ozon_auto_unarchive_slot_key(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
}

function stocks_tool_ozon_sync_state_get(int $connectionId, string $key, array $cfg): string
{
    if ($connectionId <= 0 || $key === '') {
        return '';
    }
    try {
        ozon_products_tables_ensure($cfg);
        $st = db()->prepare("SELECT state_value FROM feedtools_ozon_sync_state WHERE connection_id = ? AND state_key = ? LIMIT 1");
        $st->execute([$connectionId, $key]);
        $value = $st->fetchColumn();
        return ($value === false || $value === null) ? '' : (string)$value;
    } catch (Throwable $e) {
        return '';
    }
}

function stocks_tool_ozon_sync_state_set(int $connectionId, string $clientId, string $key, string $value, array $cfg): void
{
    if ($connectionId <= 0 || $key === '') {
        return;
    }
    try {
        ozon_products_tables_ensure($cfg);
        $st = db()->prepare("
            INSERT INTO feedtools_ozon_sync_state (connection_id, ozon_client_id, state_key, state_value)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ozon_client_id = VALUES(ozon_client_id), state_value = VALUES(state_value), updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([$connectionId, $clientId, $key, $value]);
    } catch (Throwable $e) {
        // State is a throttling guard only; stock updates must keep running without it.
    }
}

function stocks_tool_ozon_positive_target_qty_by_offer(array $stateRows, int $limit): array
{
    $map = [];
    foreach ($stateRows as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $offerId = trim((string)($row['offer_id'] ?? $offerId));
        if ($offerId === '') {
            continue;
        }
        $targetQty = max(0, (int)($row['last_target_qty'] ?? 0));
        if ($targetQty <= 0) {
            continue;
        }
        $map[$offerId] = $targetQty;
        if (count($map) >= $limit) {
            break;
        }
    }
    return $map;
}

function stocks_tool_ozon_auto_unarchive_flatten_text($value, array &$parts): void
{
    if (is_array($value)) {
        foreach ($value as $key => $nested) {
            if (is_string($key) || is_numeric($key)) {
                $parts[] = (string)$key;
            }
            stocks_tool_ozon_auto_unarchive_flatten_text($nested, $parts);
        }
        return;
    }
    if (is_bool($value)) {
        $parts[] = $value ? 'true' : 'false';
        return;
    }
    if (is_scalar($value) && $value !== null) {
        $parts[] = (string)$value;
    }
}

function stocks_tool_ozon_auto_unarchive_value_by_keys(array $row, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return null;
}

function stocks_tool_ozon_auto_unarchive_nested_value_by_keys($value, array $keys, bool &$found)
{
    if (!is_array($value)) {
        return null;
    }
    foreach ($value as $key => $nested) {
        if (is_string($key) && in_array($key, $keys, true)) {
            $found = true;
            return $nested;
        }
        if (is_array($nested)) {
            $nestedValue = stocks_tool_ozon_auto_unarchive_nested_value_by_keys($nested, $keys, $found);
            if ($found) {
                return $nestedValue;
            }
        }
    }
    return null;
}

function stocks_tool_ozon_autoarchive_marker(array $row): bool
{
    $explicitKeys = [
        'is_autoarchived',
        'is_auto_archived',
        'autoarchived',
        'auto_archived',
    ];
    $foundExplicit = false;
    $explicit = stocks_tool_ozon_auto_unarchive_nested_value_by_keys($row, $explicitKeys, $foundExplicit);
    if (!$foundExplicit) {
        $explicit = stocks_tool_ozon_auto_unarchive_value_by_keys($row, $explicitKeys);
        $foundExplicit = $explicit !== null;
    }
    if ($foundExplicit && stocks_tool_ozon_auto_unarchive_bool($explicit, false)) {
        return true;
    }

    $raw = null;
    if (isset($row['raw_json']) && is_string($row['raw_json']) && trim($row['raw_json']) !== '') {
        $decoded = json_decode((string)$row['raw_json'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
            $foundNestedExplicit = false;
            $nestedExplicit = stocks_tool_ozon_auto_unarchive_nested_value_by_keys($decoded, $explicitKeys, $foundNestedExplicit);
            if ($foundNestedExplicit) {
                return stocks_tool_ozon_auto_unarchive_bool($nestedExplicit, false);
            }
        }
    }

    $parts = [
        (string)($row['status_name'] ?? ''),
        (string)($row['status_description'] ?? ''),
        (string)($row['status_failed'] ?? ''),
        (string)($row['marketplace_status'] ?? ''),
    ];
    if (is_array($raw)) {
        stocks_tool_ozon_auto_unarchive_flatten_text($raw, $parts);
    }
    $text = mb_strtolower(str_replace('ё', 'е', implode(' ', array_filter($parts, static fn($v): bool => trim((string)$v) !== ''))), 'UTF-8');
    if ($text === '') {
        return false;
    }
    return preg_match('~(?:auto[a-z0-9_\\-\\s]{0,30}archiv|archiv[a-z0-9_\\-\\s]{0,30}auto|авто[\\p{L}\\p{N}_\\-\\s]{0,30}архив|архив[\\p{L}\\p{N}_\\-\\s]{0,30}авто|автоматическ[\\p{L}\\p{N}_\\-\\s]{0,60}архив|архив[\\p{L}\\p{N}_\\-\\s]{0,60}автоматическ)~iu', $text) === 1;
}

function stocks_tool_ozon_auto_unarchive_archived_candidates(
    int $connectionId,
    array $positiveQtyByOffer,
    int $scanLimit,
    bool $includeLocallyActive = false
): array
{
    if ($connectionId <= 0 || !$positiveQtyByOffer) {
        return [];
    }

    $offerIds = array_slice(array_keys($positiveQtyByOffer), 0, $scanLimit);
    $rows = [];
    $pdo = db();
    $autoArchivedSelect = stocks_tool_table_has_column($pdo, 'feedtools_ozon_products', 'is_autoarchived')
        ? 'is_autoarchived'
        : '0 AS is_autoarchived';
    $archivedFilter = $includeLocallyActive ? '' : 'AND is_archived = 1';
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
            SELECT
                offer_id, product_id, sku, is_archived, marketplace_status,
                {$autoArchivedSelect},
                status_name, status_description, status_failed, raw_json, updated_at
            FROM feedtools_ozon_products
            WHERE connection_id = ?
              {$archivedFilter}
              AND product_id IS NOT NULL
              AND product_id > 0
              AND offer_id IN ({$placeholders})
            ORDER BY updated_at ASC, id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$connectionId], array_values($chunk)));
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId === '' || !isset($positiveQtyByOffer[$offerId])) {
                continue;
            }
            $row['target_qty'] = (int)$positiveQtyByOffer[$offerId];
            $rows[$offerId] = $row;
        }
    }
    return array_values($rows);
}

function stocks_tool_ozon_product_info_map(array $oz, array $productIds, int $limit, callable $log): array
{
    $productIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => is_numeric($value) ? (int)$value : 0,
        $productIds
    ), static fn(int $value): bool => $value > 0)));
    if ($limit > 0) {
        $productIds = array_slice($productIds, 0, $limit);
    }

    $itemsByProductId = [];
    $errors = 0;
    $batchNo = 0;
    foreach (array_chunk($productIds, 100) as $chunk) {
        $batchNo++;
        try {
            $resp = ozon_post_json($oz, '/v3/product/info/list', [
                'product_id' => array_values($chunk),
            ]);
        } catch (Throwable $e) {
            $errors += count($chunk);
            $log('[ozon auto-unarchive] details batch ' . $batchNo . ': error items=' . count($chunk) . ' · ' . $e->getMessage() . "\n");
            continue;
        }
        $items = is_array($resp['items'] ?? null) ? $resp['items'] : (is_array($resp['result']['items'] ?? null) ? $resp['result']['items'] : []);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = 0;
            foreach (['id', 'product_id'] as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    $productId = (int)$item[$key];
                    break;
                }
            }
            if ($productId > 0) {
                $itemsByProductId[$productId] = $item;
            }
        }
    }

    return [
        'requested' => count($productIds),
        'returned' => count($itemsByProductId),
        'errors' => $errors,
        'items' => $itemsByProductId,
    ];
}

function stocks_tool_ozon_auto_unarchive_select_product_ids(array $candidates, array $detailsByProductId, int $limit): array
{
    $selected = [];
    $seen = [];
    foreach ($candidates as $row) {
        if (!is_array($row) || (int)($row['is_archived'] ?? 0) !== 1) {
            continue;
        }
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId <= 0 || isset($seen[$productId])) {
            continue;
        }
        $detail = is_array($detailsByProductId[$productId] ?? null) ? (array)$detailsByProductId[$productId] : [];
        $isAutoArchived = (int)($row['is_autoarchived'] ?? 0) === 1;
        if (!$isAutoArchived) {
            $isAutoArchived = $detail
                ? stocks_tool_ozon_autoarchive_marker($detail + $row)
                : stocks_tool_ozon_autoarchive_marker($row);
        }
        if (!$isAutoArchived) {
            continue;
        }
        $seen[$productId] = true;
        $selected[] = [
            'product_id' => $productId,
            'offer_id' => (string)($row['offer_id'] ?? ''),
            'target_qty' => (int)($row['target_qty'] ?? 0),
        ];
        if (count($selected) >= $limit) {
            break;
        }
    }
    return $selected;
}

function stocks_tool_ozon_auto_unarchive_execute(
    array $profile,
    array $connection,
    array $stateRows,
    array $cfg,
    callable $log
): array {
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $clientId = trim((string)($connection['client_id'] ?? ($cfg['ozon']['client_id'] ?? '')));
    $limit = stocks_tool_ozon_auto_unarchive_limit($cfg);
    $slotKey = stocks_tool_ozon_auto_unarchive_slot_key();
    $result = [
        'enabled' => stocks_tool_ozon_auto_unarchive_enabled($cfg),
        'status' => 'skipped',
        'slot_key' => $slotKey,
        'limit' => $limit,
        'offer_scan_limit' => stocks_tool_ozon_auto_unarchive_offer_scan_limit($cfg),
        'positive_stock_offers' => 0,
        'archived_candidates' => 0,
        'details_requested' => 0,
        'details_returned' => 0,
        'details_errors' => 0,
        'selected' => 0,
        'selected_items' => [],
        'sent' => 0,
        'local_updated' => 0,
        'error' => '',
    ];

    if (!$result['enabled'] || $limit <= 0 || $connectionId <= 0) {
        $result['status'] = !$result['enabled'] ? 'disabled' : 'skipped';
        return $result;
    }

    $lockName = 'ft_ozon_auto_unarchive_' . sha1((string)$connectionId . '|' . $slotKey);
    $lockAcquired = stocks_tool_stock_push_lock_acquire($lockName, 0);
    if (!$lockAcquired) {
        $result['status'] = 'busy';
        $log("[ozon auto-unarchive] уже выполняется в другом процессе, пропускаю.\n");
        return $result;
    }

    try {
        $lastSlot = stocks_tool_ozon_sync_state_get($connectionId, 'stocks_auto_unarchive_last_slot', $cfg);
        if ($lastSlot === $slotKey) {
            $result['status'] = 'already_done';
            $log('[ozon auto-unarchive] дневной лимит уже использован (' . $slotKey . "), пропускаю.\n");
            return $result;
        }

        $scanLimit = (int)$result['offer_scan_limit'];
        $positiveQtyByOffer = stocks_tool_ozon_positive_target_qty_by_offer($stateRows, $scanLimit);
        $result['positive_stock_offers'] = count($positiveQtyByOffer);
        if (!$positiveQtyByOffer) {
            $result['status'] = 'no_positive_stock';
            $log("[ozon auto-unarchive] нет товаров с целевым остатком больше 0.\n");
            return $result;
        }

        try {
            ozon_products_tables_ensure($cfg);
            $candidates = stocks_tool_ozon_auto_unarchive_archived_candidates($connectionId, $positiveQtyByOffer, $scanLimit);
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $log('[ozon auto-unarchive] ошибка выбора кандидатов: ' . $e->getMessage() . "\n");
            return $result;
        }

        $result['archived_candidates'] = count($candidates);
        $detailsByProductId = [];
        try {
            $oz = ozon_cfg_or_fail($cfg);
            $detailLimit = stocks_tool_ozon_auto_unarchive_detail_limit($cfg, $limit);
            $detailQtyByOffer = [];
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $offerId = trim((string)($candidate['offer_id'] ?? ''));
                if ($offerId !== '' && isset($positiveQtyByOffer[$offerId])) {
                    $detailQtyByOffer[$offerId] = (int)$positiveQtyByOffer[$offerId];
                }
                if (count($detailQtyByOffer) >= $detailLimit) {
                    break;
                }
            }
            foreach ($positiveQtyByOffer as $offerId => $targetQty) {
                if (count($detailQtyByOffer) >= $detailLimit) {
                    break;
                }
                $detailQtyByOffer[(string)$offerId] = (int)$targetQty;
            }
            $detailRows = stocks_tool_ozon_auto_unarchive_archived_candidates(
                $connectionId,
                $detailQtyByOffer,
                $detailLimit,
                true
            );
            $detailResult = stocks_tool_ozon_product_info_map(
                $oz,
                array_column($detailRows, 'product_id'),
                $detailLimit,
                $log
            );
            $result['details_requested'] = (int)($detailResult['requested'] ?? 0);
            $result['details_returned'] = (int)($detailResult['returned'] ?? 0);
            $result['details_errors'] = (int)($detailResult['errors'] ?? 0);
            $detailsByProductId = is_array($detailResult['items'] ?? null) ? (array)$detailResult['items'] : [];

            $verifiedCandidates = [];
            foreach ($detailRows as $detailRow) {
                if (!is_array($detailRow)) {
                    continue;
                }
                $productId = (int)($detailRow['product_id'] ?? 0);
                $detail = is_array($detailsByProductId[$productId] ?? null)
                    ? (array)$detailsByProductId[$productId]
                    : [];
                if (!$detail) {
                    continue;
                }
                $marketplaceStatus = ozon_products_marketplace_status_from_info($detail);
                $isArchived = !empty($detail['is_archived'])
                    || !empty($detail['archived'])
                    || in_array($marketplaceStatus, ['archived', 'auto_archived'], true);
                if (!$isArchived) {
                    continue;
                }
                $offerId = trim((string)($detailRow['offer_id'] ?? ($detail['offer_id'] ?? '')));
                if ($offerId === '') {
                    continue;
                }
                $detailRow['is_archived'] = 1;
                $detailRow['is_autoarchived'] = stocks_tool_ozon_autoarchive_marker($detail) ? 1 : 0;
                $detailRow['marketplace_status'] = $marketplaceStatus;
                $rawJson = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $detailRow['raw_json'] = is_string($rawJson) ? $rawJson : '';
                $verifiedCandidates[$offerId] = $detailRow;
            }
            $candidates = array_values($verifiedCandidates);
            $result['archived_candidates'] = count($candidates);
        } catch (Throwable $e) {
            $result['details_errors'] += max(1, count($candidates));
            $candidates = [];
            $log('[ozon auto-unarchive] не удалось уточнить признаки автоархива: ' . $e->getMessage() . "\n");
        }

        if (!$candidates) {
            $result['status'] = 'no_archived_candidates';
            $log("[ozon auto-unarchive] среди товаров с остатком нет архивных карточек Ozon.\n");
            return $result;
        }

        $selected = stocks_tool_ozon_auto_unarchive_select_product_ids($candidates, $detailsByProductId, $limit);
        $result['selected'] = count($selected);
        $result['selected_items'] = $selected;
        if (!$selected) {
            $result['status'] = 'no_autoarchive_markers';
            $log('[ozon auto-unarchive] найден архив с остатком, но без точного признака автоархива; обычный архив не трогаю. candidates=' . count($candidates) . "\n");
            return $result;
        }

        $oz = ozon_cfg_or_fail($cfg);
        $productIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['product_id'], $selected)));
        try {
            ozon_post_json($oz, '/v1/product/unarchive', [
                'product_id' => array_values($productIds),
            ]);
            $result['sent'] = count($productIds);
            $pdo = db();
            $autoArchivedSet = stocks_tool_table_has_column($pdo, 'feedtools_ozon_products', 'is_autoarchived')
                ? "is_autoarchived = 0,"
                : "";
            $st = $pdo->prepare("
                UPDATE feedtools_ozon_products
                SET
                    is_active = 1,
                    is_archived = 0,
                    {$autoArchivedSet}
                    marketplace_status = 'ready',
                    status_name = 'Выведен из автоархива',
                    status_description = '',
                    status_failed = '',
                    status_updated_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE connection_id = ?
                  AND product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
            ");
            $st->execute(array_merge([$connectionId], $productIds));
            $result['local_updated'] = (int)$st->rowCount();
            $result['status'] = 'done';
            $log('[ozon auto-unarchive] отправлено на вывод из автоархива=' . count($productIds)
                . ' · local_updated=' . $result['local_updated'] . "\n");
        } catch (Throwable $e) {
            $result['status'] = 'api_error';
            $result['error'] = $e->getMessage();
            $log('[ozon auto-unarchive] Ozon не принял вывод из автоархива: ' . $e->getMessage() . "\n");
        }

        return $result;
    } finally {
        $restoreLimitReached = str_contains(
            strtolower((string)($result['error'] ?? '')),
            'restore limit exceeded'
        );
        if ((int)($result['sent'] ?? 0) > 0 || $restoreLimitReached) {
            stocks_tool_ozon_sync_state_set($connectionId, $clientId, 'stocks_auto_unarchive_last_slot', $slotKey, $cfg);
        }
        if (!in_array((string)($result['status'] ?? ''), ['already_done', 'busy'], true)) {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            stocks_tool_ozon_sync_state_set(
                $connectionId,
                $clientId,
                'stocks_auto_unarchive_last_result',
                is_string($encoded) ? $encoded : '',
                $cfg
            );
        }
        stocks_tool_stock_push_lock_release($lockName);
    }
}

function stocks_tool_ozon_auto_unarchive_append_stock_updates(array &$updates, array &$stateRows, array $selectedItems): int
{
    $added = 0;
    foreach ($selectedItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $offerId = trim((string)($item['offer_id'] ?? ''));
        if ($offerId === '' || isset($updates[$offerId])) {
            continue;
        }
        $stateRow = is_array($stateRows[$offerId] ?? null) ? (array)$stateRows[$offerId] : [];
        $targetQty = max(0, (int)($item['target_qty'] ?? ($stateRow['last_target_qty'] ?? 0)));
        if ($targetQty <= 0) {
            continue;
        }
        $updates[$offerId] = [
            'offer_id' => $offerId,
            'target_qty' => $targetQty,
            'target_qty_before_fbo_zero' => $targetQty,
            'present_qty' => 0,
            'feed_qty' => max(0, (int)($stateRow['last_feed_qty'] ?? $targetQty)),
            'reserved_qty' => max(0, (int)($stateRow['last_reserved_qty'] ?? 0)),
            'fbo_zero_fbs_active' => false,
            'source_feed_id' => isset($stateRow['source_feed_id']) ? (int)$stateRow['source_feed_id'] : null,
            'supplier_code' => (string)($stateRow['supplier_code'] ?? ''),
            'product_id' => (int)($item['product_id'] ?? 0),
            'sku' => '',
        ];
        if (isset($stateRows[$offerId]) && is_array($stateRows[$offerId])) {
            $stateRows[$offerId]['last_push_status'] = 'pending_update';
            $stateRows[$offerId]['last_push_error'] = null;
        }
        $added++;
    }
    return $added;
}

function stocks_tool_push_updates(array $profile, array $updates, array $cfg, callable $log): array
{
    $updates = array_values(array_filter($updates, 'is_array'));
    if (!$updates) {
        return ['results' => [], 'totals' => ['sent' => 0, 'updated' => 0, 'errors' => 0]];
    }

    if (stocks_tool_marketplace_key($profile) === 'wb') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        if (!is_array($connection)) {
            throw new RuntimeException('Подключение WB для обновления остатков не найдено.');
        }
        $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
        $warehouseId = (int)($profile['ozon_warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new RuntimeException('У профиля не задан warehouse_id WB для отправки остатков.');
        }

        $client = wb_price_tool_client($runtimeCfg, $connection);
        if (method_exists($client, 'setRetryLogger')) {
            $client->setRetryLogger(static function (int $attempt, int $delaySec, string $method, string $api, string $path) use ($log): void {
                $log('[wb limiter] attempt=' . $attempt . ' · pause=' . $delaySec . 's · ' . $method . ' ' . $api . $path . "\n");
            });
        }
        $results = [];
        $totals = ['sent' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];
        $prepared = [];
        $qtyLimit = stocks_tool_marketplace_stock_qty_limit('wb');
        foreach ($updates as $row) {
            $offerId = (string)($row['offer_id'] ?? '');
            $chrtId = (int)($row['chrt_id'] ?? 0);
            if ($chrtId <= 0) {
                $totals['skipped']++;
                $results[$offerId] = [
                    'status' => 'skipped_wb',
                    'message' => 'Нет chrtId WB для товара.',
                ];
                continue;
            }
            $amount = max(0, (int)($row['target_qty'] ?? 0));
            if ($qtyLimit > 0 && $amount > $qtyLimit) {
                $log('[wb push] ' . $offerId . ': target_qty=' . $amount . ' ограничен до ' . $qtyLimit . " из-за лимита API\n");
                $amount = $qtyLimit;
            }
            $prepared[] = [
                'offer_id' => $offerId,
                'chrtId' => $chrtId,
                'sku' => trim((string)($row['sku'] ?? '')),
                'amount' => $amount,
            ];
        }

        $batchNo = 0;
        foreach (array_chunk($prepared, 1000) as $chunk) {
            $batchNo++;
            $log('[wb push] batch ' . $batchNo . ': items=' . count($chunk) . "\n");
            stocks_tool_wb_push_chunk($client, $warehouseId, $chunk, $batchNo, 0, '', $log, $results, $totals);
        }

        return ['results' => $results, 'totals' => $totals];
    }

    if (stocks_tool_marketplace_key($profile) === 'yandex_market') {
        $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
        if (!is_array($connection)) {
            throw new RuntimeException('Подключение Яндекс Маркета для обновления остатков не найдено.');
        }
        $context = stocks_tool_yandex_campaign_context($connection);
        $campaignId = (int)$context['campaign_id'];
        $warehouseId = (int)($profile['ozon_warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new RuntimeException('У профиля не задан warehouse_id Яндекс Маркета для отправки остатков.');
        }

        $results = [];
        $totals = ['sent' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];
        $batchNo = 0;
        foreach (array_chunk($updates, 500) as $chunk) {
            $batchNo++;
            $updatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format(DateTimeInterface::ATOM);
            $payload = ['skus' => []];
            foreach ($chunk as $row) {
                $offerId = trim((string)($row['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                $payload['skus'][] = [
                    'sku' => stocks_tool_yandex_offer_id_to_marketplace($offerId),
                    'items' => [[
                        'count' => max(0, (int)($row['target_qty'] ?? 0)),
                        'updatedAt' => $updatedAt,
                    ]],
                ];
            }
            if (!$payload['skus']) {
                continue;
            }
            $log('[yandex push] batch ' . $batchNo . ': items=' . count($payload['skus']) . ' · warehouse_id=' . $warehouseId . "\n");
            $body = marketplace_connection_yandex_request(
                $connection,
                'PUT',
                stocks_tool_yandex_stock_endpoint($campaignId),
                [],
                $payload
            );
            $statusText = strtoupper(trim((string)($body['status'] ?? 'OK')));
            if ($statusText !== '' && $statusText !== 'OK') {
                throw new RuntimeException('Яндекс Маркет не принял обновление остатков: ' . marketplace_connection_extract_error_message($body));
            }
            $totals['sent'] += count($payload['skus']);
            $totals['updated'] += count($payload['skus']);
            foreach ($chunk as $row) {
                $offerId = trim((string)($row['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                $results[$offerId] = [
                    'status' => 'accepted',
                    'message' => 'Остаток Яндекс Маркета принят к обновлению.',
                ];
            }
        }

        return ['results' => $results, 'totals' => $totals];
    }

    $oz = ozon_cfg_or_fail($cfg);
    $warehouseId = (int)($profile['ozon_warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        throw new RuntimeException('У профиля не задан warehouse_id Ozon для отправки остатков.');
    }

    $results = [];
    $totals = ['sent' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];
    $batchNo = 0;
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $pushOfferIds = array_values(array_filter(array_unique(array_map(
        static fn(array $row): string => trim((string)($row['offer_id'] ?? '')),
        $updates
    ))));
    if ($connectionId > 0 && $pushOfferIds) {
        $zeroEnabledOfferIds = ozon_fbo_tool_zero_fbs_enabled_offer_ids($connectionId, $pushOfferIds, $cfg);
        if ($zeroEnabledOfferIds) {
            $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $zeroEnabledOfferIds, $cfg, $log);
            $activeFboZero = ozon_fbo_tool_zero_fbs_active_map($connectionId, $pushOfferIds, $cfg);
            $forcedZero = 0;
            $restoredNormal = 0;
            foreach ($updates as &$row) {
                $offerId = trim((string)($row['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                if (is_array($activeFboZero[$offerId] ?? null)) {
                    if ((int)($row['target_qty'] ?? 0) !== 0) {
                        $forcedZero++;
                    }
                    $row['target_qty'] = 0;
                    $row['fbo_zero_fbs_active'] = true;
                    continue;
                }
                if (!empty($row['fbo_zero_fbs_active']) && (int)($row['target_qty'] ?? 0) === 0) {
                    $row['target_qty'] = max(0, (int)($row['target_qty_before_fbo_zero'] ?? 0));
                    $row['fbo_zero_fbs_active'] = false;
                    $restoredNormal++;
                }
            }
            unset($row);
            $log('[fbo zero fbs pre-push] enabled=' . count($zeroEnabledOfferIds)
                . ' · checked=' . (int)($refresh['requested'] ?? 0)
                . ' · active_fbo=' . count($activeFboZero)
                . ' · forced_zero=' . $forcedZero
                . ' · restored_normal=' . $restoredNormal
                . ' · rules_annulled=' . (int)($refresh['rules_annulled'] ?? 0) . "\n");
        }
    }
    $lockName = stocks_tool_stock_push_lock_name($profile);
    $lockAcquired = stocks_tool_stock_push_lock_acquire($lockName, 20);
    if (!$lockAcquired) {
        throw new RuntimeException('По этому подключению и складу уже идёт выгрузка остатков Ozon. Повтори запуск после завершения текущей операции.');
    }
    try {
        foreach (array_chunk($updates, 100) as $chunk) {
            $batchNo++;
            $payload = ['stocks' => []];
            $defaultedOfferIds = [];
            foreach ($chunk as $row) {
                $offerId = (string)($row['offer_id'] ?? '');
                $payload['stocks'][] = [
                    'offer_id' => $offerId,
                    'warehouse_id' => $warehouseId,
                    'stock' => max(0, (int)($row['target_qty'] ?? 0)),
                ];
                if ($offerId !== '') {
                    $defaultedOfferIds[] = $offerId;
                }
            }
            $log('[push] batch ' . $batchNo . ': items=' . count($chunk) . "\n");
            $resp = ozon_post_json($oz, '/v2/products/stocks', $payload);
            $items = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            $totals['sent'] += count($chunk);

            foreach ($chunk as $row) {
                $offerId = (string)($row['offer_id'] ?? '');
                $results[$offerId] = [
                    'status' => 'error',
                    'message' => 'Ozon не вернул результат по товару.',
                ];
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $offerId = trim((string)($item['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                $errors = stocks_tool_push_errors_parse($item);
                if ($errors) {
                    $message = implode(' | ', array_filter(array_map(
                        static fn(array $error): string => (string)($error['full'] ?? ''),
                        $errors
                    )));
                    if (stocks_tool_push_errors_are_ignorable($errors)) {
                        $totals['skipped']++;
                        $results[$offerId] = [
                            'status' => 'skipped_ozon',
                            'message' => $message,
                        ];
                        $log('[push skip] ' . $offerId . ': ' . $message . "\n");
                    } else {
                        $totals['errors']++;
                        $results[$offerId] = [
                            'status' => 'error',
                            'message' => $message,
                        ];
                        $log('[push error] ' . $offerId . ': ' . $message . "\n");
                    }
                    continue;
                }
                $updated = !empty($item['updated']);
                if ($updated) {
                    $totals['updated']++;
                } else {
                    $log('[push warn] ' . $offerId . ': запрос принят без признака updated' . "\n");
                }
                $results[$offerId] = [
                    'status' => $updated ? 'updated' : 'accepted',
                    'message' => $updated ? 'Остаток обновлён.' : 'Запрос принят без признака updated.',
                ];
            }

            foreach ($defaultedOfferIds as $offerId) {
                $result = $results[$offerId] ?? null;
                if (!is_array($result)) {
                    continue;
                }
                if (($result['status'] ?? '') === 'error' && ($result['message'] ?? '') === 'Ozon не вернул результат по товару.') {
                    $log('[push error] ' . $offerId . ': Ozon не вернул результат по товару.' . "\n");
                }
            }
        }
    } finally {
        stocks_tool_stock_push_lock_release($lockName);
    }

    return ['results' => $results, 'totals' => $totals];
}

function stocks_tool_push_errors_parse(array $item): array
{
    $errors = [];
    foreach ((array)($item['errors'] ?? []) as $error) {
        if (!is_array($error)) {
            continue;
        }
        $message = trim((string)($error['message'] ?? ''));
        $code = trim((string)($error['code'] ?? ''));
        $errors[] = [
            'code' => $code,
            'message' => $message,
            'full' => $code !== '' ? ($code . ': ' . $message) : $message,
        ];
    }
    return $errors;
}

function stocks_tool_push_errors_are_ignorable(array $errors): bool
{
    if (!$errors) {
        return false;
    }
    $ignorableCodes = [
        'PRODUCT_IS_NOT_CREATED' => true,
        'NOT_PASS_MODERATION' => true,
    ];
    foreach ($errors as $error) {
        $code = trim((string)($error['code'] ?? ''));
        if ($code === '' || !isset($ignorableCodes[$code])) {
            return false;
        }
    }
    return true;
}

function stocks_tool_offer_ids_for_article(string $article, array $feedOfferMap): array
{
    $article = trim($article);
    if ($article === '') {
        return [];
    }

    $exact = [];
    if (isset($feedOfferMap[$article]) && is_array($feedOfferMap[$article])) {
        $exact[] = $article;
    }

    $rawMatches = [];
    foreach ($feedOfferMap as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawOfferId = trim((string)($row['raw_offer_id'] ?? ''));
        if ($rawOfferId !== '' && $rawOfferId === $article) {
            $rawMatches[] = (string)$offerId;
        }
    }

    $bundle = bundle_offer_parse($article);
    if (!empty($bundle['is_bundle']) && !empty($bundle['format_valid'])) {
        $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
        if ($baseOfferId !== '' && isset($feedOfferMap[$baseOfferId]) && is_array($feedOfferMap[$baseOfferId])) {
            $exact[] = $article;
        } elseif ($baseOfferId !== '') {
            foreach ($feedOfferMap as $offerId => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawOfferId = trim((string)($row['raw_offer_id'] ?? ''));
                if ($rawOfferId !== '' && $rawOfferId === $baseOfferId) {
                    $rawMatches[] = (int)($bundle['bundle_qty'] ?? 1) . '##' . (string)$offerId;
                }
            }
        }
    }

    $matches = array_values(array_unique(array_merge($exact, $rawMatches)));
    sort($matches);
    return $matches;
}

function stocks_tool_test_offer_by_article(int $profileId, string $article, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    $article = trim($article);
    if ($profileId <= 0) {
        throw new RuntimeException('Профиль остатков не найден.');
    }
    if ($article === '') {
        throw new RuntimeException('Укажи артикул или полный offer_id для проверки.');
    }

    $profile = stocks_tool_profile_get($profileId, null, $cfg);
    if (!is_array($profile)) {
        throw new RuntimeException('Профиль остатков не найден.');
    }
    $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Подключение маркетплейса не найдено.');
    }
    $marketplace = stocks_tool_marketplace_key($profile);
    $marketplaceLabel = stocks_tool_marketplace_item_label($marketplace);
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $feedRows = stocks_tool_profile_feed_rows($profileId, (int)($profile['connection_id'] ?? 0), $cfg);
    if (!$feedRows) {
        throw new RuntimeException('У профиля нет источников поставщиков для проверки остатков.');
    }

    $logLines = [];
    $log = static function (string $message) use (&$logLines): void {
        $message = trim($message);
        if ($message !== '') {
            $logLines[] = $message;
        }
    };

    $feedsResult = stocks_tool_profile_feed_quantity_map($profile, $feedRows, $log);
    $feedOfferMap = is_array($feedsResult['offers'] ?? null) ? $feedsResult['offers'] : [];
    $feedSummaries = is_array($feedsResult['feeds'] ?? null) ? $feedsResult['feeds'] : [];
    $effectiveProfile = $profile;
    $effectiveProfile['supplier_codes'] = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        (array)($feedsResult['successful_supplier_codes'] ?? [])
    ))));
    $failedSupplierCodes = [];
    foreach ((array)($feedsResult['failed_feeds'] ?? []) as $failedFeed) {
        if (!is_array($failedFeed)) continue;
        $failedCode = trim((string)($failedFeed['supplier_code'] ?? ''));
        if ($failedCode !== '') $failedSupplierCodes[$failedCode] = (string)($failedFeed['feed_name'] ?? $failedCode);
    }

    $candidateOfferIds = stocks_tool_offer_ids_for_article($article, $feedOfferMap);
    if (!$candidateOfferIds && str_contains($article, '__')) {
        $articleSupplierCode = stocks_tool_offer_supplier_code($article, stocks_tool_supplier_codes_from_profile($profile));
        if ($articleSupplierCode !== ''
            && isset($failedSupplierCodes[$articleSupplierCode])
            && !in_array($articleSupplierCode, stocks_tool_force_zero_supplier_codes($effectiveProfile), true)) {
            throw new RuntimeException('Источник поставщика «' . $failedSupplierCodes[$articleSupplierCode]
                . '» сейчас недоступен. Остаток товара не будет рассчитываться как нулевой.');
        }
        $candidateOfferIds[] = $article;
    }

    if (!$candidateOfferIds) {
        throw new RuntimeException('Товар с таким артикулом не найден в выбранных источниках поставщика.');
    }
    if (count($candidateOfferIds) > 1) {
        throw new RuntimeException('По этому артикулу найдено несколько offer_id: ' . implode(', ', $candidateOfferIds) . '. Укажи полный offer_id с suffix поставщика.');
    }

    $offerId = (string)$candidateOfferIds[0];
    $feedRow = is_array($feedOfferMap[$offerId] ?? null) ? $feedOfferMap[$offerId] : null;
    $stateMap = stocks_tool_state_map($profileId, (string)($profile['ozon_warehouse_key'] ?? ''), [$offerId], $cfg);
    $ozonStockMap = stocks_tool_fetch_marketplace_stock_map($runtimeCfg, $connection, $profile, [$offerId], $log);
    $globalReservedUnits = stocks_tool_global_reserved_units_by_base_offer(
        [$offerId],
        (int)($profile['connection_id'] ?? 0),
        $ozonStockMap,
        $cfg,
        $log
    );
    $comparison = stocks_tool_build_state_rows($effectiveProfile, [$offerId], $feedOfferMap, $ozonStockMap, $stateMap, $cfg, $log, $globalReservedUnits);
    $stateRow = is_array(($comparison['state_rows'] ?? [])[$offerId] ?? null) ? ($comparison['state_rows'] ?? [])[$offerId] : null;
    if (!is_array($stateRow)) {
        throw new RuntimeException('Не удалось рассчитать итоговое состояние товара для проверки.');
    }
    $forceZeroSupplierSet = array_fill_keys(stocks_tool_force_zero_supplier_codes($effectiveProfile), true);
    $resultSupplierCode = trim((string)($stateRow['supplier_code'] ?? ''));
    $forceZeroStock = $resultSupplierCode !== '' && isset($forceZeroSupplierSet[$resultSupplierCode]);

    $status = (string)($stateRow['last_push_status'] ?? '');
    $statusLabel = match ($status) {
        'pending_update' => 'Будет обновлён остаток',
        'skipped_same' => 'Изменений нет',
        'missing_on_ozon' => 'Товар не найден на ' . $marketplaceLabel . ' для этого склада',
        'missing_on_wb' => 'Товар не найден на ' . $marketplaceLabel . ' для этого склада',
        'missing_on_yandex' => 'Товар не найден на ' . $marketplaceLabel . ' для этого склада',
        default => $status !== '' ? $status : '—',
    };

    $payloadPreview = null;
    if ($status === 'pending_update' && trim((string)($profile['ozon_warehouse_id'] ?? '')) !== '') {
        if ($marketplace === 'wb') {
            $payloadPreview = [
                'warehouse_id' => (int)($profile['ozon_warehouse_id'] ?? 0),
                'stocks' => [[
                    'chrtId' => (int)($ozonStockMap[$offerId]['chrt_id'] ?? 0),
                    'amount' => (int)($stateRow['last_target_qty'] ?? 0),
                ]],
            ];
        } elseif ($marketplace === 'yandex_market') {
            $payloadPreview = [
                'skus' => [[
                    'sku' => stocks_tool_yandex_offer_id_to_marketplace($offerId),
                    'items' => [[
                        'count' => (int)($stateRow['last_target_qty'] ?? 0),
                        'updatedAt' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format(DateTimeInterface::ATOM),
                    ]],
                ]],
            ];
        } else {
            $payloadPreview = [
                'stocks' => [[
                    'offer_id' => $offerId,
                    'warehouse_id' => (int)($profile['ozon_warehouse_id'] ?? 0),
                    'stock' => (int)($stateRow['last_target_qty'] ?? 0),
                ]],
            ];
        }
    }

    return [
        'profile_id' => $profileId,
        'profile_name' => (string)($profile['name'] ?? ''),
        'marketplace' => $marketplace,
        'marketplace_label' => $marketplaceLabel,
        'article' => $article,
        'offer_id' => $offerId,
        'chrt_id' => (int)($ozonStockMap[$offerId]['chrt_id'] ?? 0),
        'raw_offer_id' => trim((string)($feedRow['raw_offer_id'] ?? $article)),
        'warehouse_name' => (string)($profile['ozon_warehouse_name'] ?? ''),
        'warehouse_id' => (string)($profile['ozon_warehouse_id'] ?? ''),
        'feed_qty' => (int)($stateRow['last_feed_qty'] ?? 0),
        'feed_price' => isset($feedRow['price']) && $feedRow['price'] !== null ? round((float)$feedRow['price'], 2) : null,
        'reserved_qty' => (int)($stateRow['last_reserved_qty'] ?? 0),
        'present_qty' => (int)(($ozonStockMap[$offerId]['present'] ?? 0)),
        'target_qty' => (int)($stateRow['last_target_qty'] ?? 0),
        'last_pushed_qty' => (int)($stateRow['last_pushed_qty'] ?? 0),
        'supplier_code' => $resultSupplierCode,
        'source_feed_id' => (int)($stateRow['source_feed_id'] ?? 0),
        'feed_buffer_qty' => (int)($feedRow['feed_buffer_qty'] ?? 0),
        'feed_min_price' => ($feedRow['feed_min_price'] ?? null) !== null ? round((float)$feedRow['feed_min_price'], 2) : null,
        'feed_max_price' => ($feedRow['feed_max_price'] ?? null) !== null ? round((float)$feedRow['feed_max_price'], 2) : null,
        'force_zero_stock' => $forceZeroStock,
        'fbo_zero_fbs_active' => !empty($stateRow['fbo_zero_fbs_active']),
        'fbo_present_qty' => (int)($stateRow['fbo_present_qty'] ?? 0),
        'price_out_of_range' => !empty($feedRow['price_out_of_range']),
        'zero_rule_reason' => trim((string)($feedRow['zero_rule_reason'] ?? '')),
        'zero_rule_reasons' => (array)($feedRow['zero_rule_reasons'] ?? []),
        'is_missing_from_feed' => !is_array($feedRow),
        'is_on_ozon' => is_array($ozonStockMap[$offerId] ?? null),
        'status' => $status,
        'status_label' => $statusLabel,
        'payload_preview' => $payloadPreview,
        'feeds' => $feedSummaries,
        'log_lines' => $logLines,
    ];
}

function stocks_tool_test_offer_push_by_article(int $profileId, string $article, ?string $actor = null, array $cfg = []): array
{
    $result = stocks_tool_test_offer_by_article($profileId, $article, $cfg);
    $profile = stocks_tool_profile_get($profileId, null, $cfg);
    if (!is_array($profile)) {
        throw new RuntimeException('Профиль остатков не найден.');
    }
    $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Подключение маркетплейса не найдено.');
    }

    if (empty($result['is_on_ozon'])) {
        throw new RuntimeException('Нельзя отправить остаток: товар не найден на ' . (string)($result['marketplace_label'] ?? 'маркетплейсе') . ' для выбранного склада.');
    }

    $offerId = trim((string)($result['offer_id'] ?? ''));
    if ($offerId === '') {
        throw new RuntimeException('Не удалось определить offer_id для тестовой отправки.');
    }

    $marketplace = stocks_tool_marketplace_key($profile);
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $logLines = [];
    $log = static function (string $message) use (&$logLines): void {
        $message = trim($message);
        if ($message !== '') {
            $logLines[] = $message;
        }
    };

    $update = [
        'offer_id' => $offerId,
        'target_qty' => max(0, (int)($result['target_qty'] ?? 0)),
        'present_qty' => max(0, (int)($result['present_qty'] ?? 0)),
        'feed_qty' => max(0, (int)($result['feed_qty'] ?? 0)),
        'reserved_qty' => max(0, (int)($result['reserved_qty'] ?? 0)),
        'source_feed_id' => (int)($result['source_feed_id'] ?? 0) > 0 ? (int)$result['source_feed_id'] : null,
        'supplier_code' => trim((string)($result['supplier_code'] ?? '')),
        'product_id' => 0,
        'chrt_id' => (int)($result['chrt_id'] ?? 0),
    ];

    $pushResult = stocks_tool_push_updates($profile, [$update], $runtimeCfg, $log);
    $pushResults = is_array($pushResult['results'] ?? null) ? $pushResult['results'] : [];
    $offerPushResult = is_array($pushResults[$offerId] ?? null) ? $pushResults[$offerId] : ['status' => 'error', 'message' => 'Маркетплейс не вернул результат по товару.'];
    $isSuccess = in_array((string)($offerPushResult['status'] ?? ''), ['updated', 'accepted'], true);

    $now = date('Y-m-d H:i:s');
    stocks_tool_state_upsert([[
        'profile_id' => $profileId,
        'connection_id' => (int)($profile['connection_id'] ?? 0),
        'marketplace' => $marketplace,
        'warehouse_key' => (string)($profile['ozon_warehouse_key'] ?? ''),
        'offer_id' => $offerId,
        'supplier_code' => $update['supplier_code'],
        'source_feed_id' => $update['source_feed_id'],
        'last_feed_qty' => $update['feed_qty'],
        'last_reserved_qty' => $update['reserved_qty'],
        'last_target_qty' => $update['target_qty'],
        'last_pushed_qty' => $isSuccess ? $update['target_qty'] : max(0, (int)($result['last_pushed_qty'] ?? 0)),
        'last_seen_in_feed_at' => empty($result['is_missing_from_feed']) ? $now : null,
        'last_seen_on_ozon_at' => $now,
        'last_push_status' => (string)($offerPushResult['status'] ?? 'error'),
        'last_push_error' => $isSuccess ? null : (string)($offerPushResult['message'] ?? 'Ошибка тестовой отправки остатка.'),
    ]], 0, $cfg);

    $result['test_push'] = [
        'status' => (string)($offerPushResult['status'] ?? 'error'),
        'message' => (string)($offerPushResult['message'] ?? ''),
        'success' => $isSuccess,
        'sent_at' => $now,
        'actor' => (string)($actor ?? ''),
        'totals' => is_array($pushResult['totals'] ?? null) ? $pushResult['totals'] : [],
    ];
    $result['status'] = $isSuccess ? 'updated' : (string)($offerPushResult['status'] ?? 'error');
    $result['status_label'] = $isSuccess ? 'Тестовый остаток отправлен' : 'Тестовая отправка не выполнена';
    $result['last_pushed_qty'] = $isSuccess ? $update['target_qty'] : (int)($result['last_pushed_qty'] ?? 0);
    $result['log_lines'] = array_values(array_merge((array)($result['log_lines'] ?? []), $logLines));

    return $result;
}

function stocks_tool_state_upsert(array $rows, int $runId, array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    $rows = array_values(array_filter($rows, 'is_array'));
    if (!$rows) {
        return;
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        $valuesSql = [];
        $args = [];
        $now = date('Y-m-d H:i:s');
        foreach ($chunk as $row) {
            $valuesSql[] = '(' . implode(', ', array_fill(0, 17, '?')) . ')';
            $args[] = (int)($row['profile_id'] ?? 0);
            $args[] = (int)($row['connection_id'] ?? 0);
            $args[] = (string)($row['marketplace'] ?? 'ozon');
            $args[] = (string)($row['warehouse_key'] ?? '');
            $args[] = (string)($row['offer_id'] ?? '');
            $args[] = (string)($row['supplier_code'] ?? '');
            $args[] = isset($row['source_feed_id']) ? (int)$row['source_feed_id'] : null;
            $args[] = (int)($row['last_feed_qty'] ?? 0);
            $args[] = (int)($row['last_reserved_qty'] ?? 0);
            $args[] = (int)($row['last_target_qty'] ?? 0);
            $args[] = (int)($row['last_pushed_qty'] ?? 0);
            $args[] = $row['last_seen_in_feed_at'] ?? null;
            $args[] = $row['last_seen_on_ozon_at'] ?? null;
            $args[] = (string)($row['last_push_status'] ?? '');
            $args[] = $row['last_push_error'] ?? null;
            $args[] = $runId > 0 ? $runId : null;
            $args[] = $now;
        }

        $sql = "
            INSERT INTO feedtools_marketplace_stock_item_state (
                profile_id, connection_id, marketplace, warehouse_key, offer_id, supplier_code,
                source_feed_id, last_feed_qty, last_reserved_qty, last_target_qty, last_pushed_qty,
                last_seen_in_feed_at, last_seen_on_ozon_at, last_push_status, last_push_error, last_push_run_id, last_push_at
            ) VALUES
                " . implode(",\n                ", $valuesSql) . "
            ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                marketplace = VALUES(marketplace),
                supplier_code = VALUES(supplier_code),
                source_feed_id = VALUES(source_feed_id),
                last_feed_qty = VALUES(last_feed_qty),
                last_reserved_qty = VALUES(last_reserved_qty),
                last_target_qty = VALUES(last_target_qty),
                last_pushed_qty = VALUES(last_pushed_qty),
                last_seen_in_feed_at = VALUES(last_seen_in_feed_at),
                last_seen_on_ozon_at = VALUES(last_seen_on_ozon_at),
                last_push_status = VALUES(last_push_status),
                last_push_error = VALUES(last_push_error),
                last_push_run_id = VALUES(last_push_run_id),
                last_push_at = VALUES(last_push_at)
        ";

        $st = db()->prepare($sql);
        $st->execute($args);
    }
}

function stocks_tool_state_rows_changed(array $rows, array $stateMap): array
{
    $rows = array_values(array_filter($rows, 'is_array'));
    if (!$rows) {
        return [];
    }

    $changed = [];
    foreach ($rows as $row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        if ($offerId === '') {
            continue;
        }
        $existing = is_array($stateMap[$offerId] ?? null) ? $stateMap[$offerId] : null;
        if (!is_array($existing)) {
            $changed[] = $row;
            continue;
        }

        $fields = [
            'supplier_code',
            'source_feed_id',
            'last_feed_qty',
            'last_reserved_qty',
            'last_target_qty',
            'last_pushed_qty',
            'last_push_status',
            'last_push_error',
        ];
        $isChanged = false;
        foreach ($fields as $field) {
            $left = $row[$field] ?? null;
            $right = $existing[$field] ?? null;
            if ((string)$left !== (string)$right) {
                $isChanged = true;
                break;
            }
        }

        if (!$isChanged) {
            $feedSeenChanged = ((string)($row['last_seen_in_feed_at'] ?? '') !== '') !== ((string)($existing['last_seen_in_feed_at'] ?? '') !== '');
            $ozonSeenChanged = ((string)($row['last_seen_on_ozon_at'] ?? '') !== '') !== ((string)($existing['last_seen_on_ozon_at'] ?? '') !== '');
            $isChanged = $feedSeenChanged || $ozonSeenChanged;
        }

        if ($isChanged) {
            $changed[] = $row;
        }
    }

    return $changed;
}

function stocks_tool_build_zero_supplier_state_rows(
    array $profile,
    array $scopeOfferIds,
    array $ozonStockMap,
    array $stateMap,
    int $feedId,
    string $supplierCode
): array {
    $warehouseKey = trim((string)($profile['ozon_warehouse_key'] ?? ''));
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $marketplace = stocks_tool_marketplace_key($profile);
    $missingStatus = stocks_tool_missing_marketplace_status($marketplace);
    $missingTotalKey = stocks_tool_missing_marketplace_total_key($marketplace);
    $now = date('Y-m-d H:i:s');

    $rows = [];
    $updates = [];
    $totals = [
        'scoped' => 0,
        'missing_zeroed' => 0,
        'unchanged' => 0,
        'to_update' => 0,
        $missingTotalKey => 0,
    ];

    foreach ($scopeOfferIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $totals['scoped']++;
        $stateRow = $stateMap[$offerId] ?? null;
        $ozonRow = $ozonStockMap[$offerId] ?? null;
        $reservedQty = is_array($ozonRow) ? max(0, (int)($ozonRow['reserved'] ?? 0)) : 0;
        $presentQty = is_array($ozonRow) ? max(0, (int)($ozonRow['present'] ?? 0)) : 0;
        $isOnOzonNow = is_array($ozonRow);

        $status = 'skipped_same';
        if (!$isOnOzonNow) {
            $status = $missingStatus;
            $totals[$missingTotalKey]++;
        } elseif ($presentQty !== 0) {
            $status = 'pending_update';
            $totals['to_update']++;
            $updates[$offerId] = [
                'offer_id' => $offerId,
                'target_qty' => 0,
                'present_qty' => $presentQty,
                'feed_qty' => 0,
                'reserved_qty' => $reservedQty,
                'source_feed_id' => $feedId > 0 ? $feedId : null,
                'supplier_code' => $supplierCode,
                'product_id' => (int)($ozonRow['product_id'] ?? 0),
                'chrt_id' => (int)($ozonRow['chrt_id'] ?? 0),
            ];
        } else {
            $totals['unchanged']++;
        }

        $lastPushedQty = $status === 'skipped_same'
            ? 0
            : max(0, (int)($stateRow['last_pushed_qty'] ?? 0));
        if (!$isOnOzonNow) {
            $lastPushedQty = 0;
        }

        $rows[$offerId] = [
            'profile_id' => (int)($profile['id'] ?? 0),
            'connection_id' => $connectionId,
            'marketplace' => $marketplace,
            'warehouse_key' => $warehouseKey,
            'offer_id' => $offerId,
            'supplier_code' => $supplierCode !== '' ? $supplierCode : trim((string)($stateRow['supplier_code'] ?? '')),
            'source_feed_id' => $feedId > 0 ? $feedId : (isset($stateRow['source_feed_id']) ? (int)$stateRow['source_feed_id'] : null),
            'last_feed_qty' => 0,
            'last_reserved_qty' => $reservedQty,
            'last_target_qty' => 0,
            'last_pushed_qty' => $lastPushedQty,
            'last_seen_in_feed_at' => null,
            'last_seen_on_ozon_at' => $isOnOzonNow ? $now : null,
            'last_push_status' => $status,
            'last_push_error' => null,
        ];
    }

    return [
        'state_rows' => $rows,
        'updates' => $updates,
        'totals' => $totals,
    ];
}

function stocks_tool_run_create(int $profileId, string $kind = 'manual_stock_sync', ?string $actor = null, array $cfg = [], array $summarySeed = []): int
{
    stocks_tool_profiles_table_ensure($cfg);
    $profile = stocks_tool_profile_get($profileId, null, $cfg);
    if (!is_array($profile)) {
        throw new RuntimeException('Профиль остатков не найден.');
    }
    $activeRun = stocks_tool_run_active_for_profile($profileId, $cfg);
    if (is_array($activeRun)) {
        throw new RuntimeException('По этому профилю уже идёт запуск #' . (int)($activeRun['id'] ?? 0) . '.');
    }

    $summary = array_replace_recursive([
        'kind' => $kind,
        'profile_id' => $profileId,
        'connection_id' => (int)($profile['connection_id'] ?? 0),
        'actor' => $actor,
        'totals' => [],
    ], $summarySeed);
    $marketplace = stocks_tool_marketplace_key($profile);
    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_stock_runs (
            profile_id, connection_id, marketplace, kind, status, progress_current, progress_total,
            totals_json, summary_json, log_text, error_text
        ) VALUES (?, ?, ?, ?, 'queued', 0, 0, '{}', ?, '', NULL)
    ");
    $st->execute([
        $profileId,
        (int)($profile['connection_id'] ?? 0),
        $marketplace,
        $kind,
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return (int)db()->lastInsertId();
}

function stocks_tool_run_append(int $runId, string $message): void
{
    if ($runId <= 0 || $message === '') {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_stock_runs
        SET log_text = CONCAT(COALESCE(log_text, ''), ?)
        WHERE id = ?
    ");
    $st->execute([$message, $runId]);
}

function stocks_tool_run_update(int $runId, string $status, array $summary, int $progressCurrent, int $progressTotal, array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    $st = db()->prepare("
        UPDATE feedtools_marketplace_stock_runs
        SET
            status = ?,
            progress_current = ?,
            progress_total = ?,
            totals_json = ?,
            summary_json = ?
        WHERE id = ?
    ");
    $st->execute([
        $status,
        max(0, $progressCurrent),
        max(0, $progressTotal),
        json_encode((array)($summary['totals'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $runId,
    ]);
}

function stocks_tool_run_finish(int $runId, string $status, array $summary, ?string $errorText = null, array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    $st = db()->prepare("
        UPDATE feedtools_marketplace_stock_runs
        SET
            status = ?,
            progress_current = ?,
            progress_total = ?,
            totals_json = ?,
            summary_json = ?,
            error_text = ?,
            finished_at = NOW()
        WHERE id = ?
    ");
    $st->execute([
        $status,
        max(0, (int)($summary['progress_current'] ?? 0)),
        max(0, (int)($summary['progress_total'] ?? 0)),
        json_encode((array)($summary['totals'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $errorText,
        $runId,
    ]);
}

function stocks_tool_run_get(int $runId, array $cfg = []): ?array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($runId <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_marketplace_stock_runs WHERE id = ? LIMIT 1");
    $st->execute([$runId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['totals'] = json_decode((string)($row['totals_json'] ?? '{}'), true) ?: [];
    $row['summary'] = json_decode((string)($row['summary_json'] ?? '{}'), true) ?: [];
    return $row;
}

function stocks_tool_active_run_stale_after_seconds(): int
{
    return 2 * 60 * 60;
}

function stocks_tool_run_timestamp($value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts === false ? 0 : (int)$ts;
}

function stocks_tool_run_is_stale_active(array $run, ?int $nowTs = null): bool
{
    $status = trim((string)($run['status'] ?? ''));
    if (!in_array($status, ['queued', 'running'], true)) {
        return false;
    }
    $lastTs = stocks_tool_run_timestamp($run['updated_at'] ?? null)
        ?: stocks_tool_run_timestamp($run['started_at'] ?? null)
        ?: stocks_tool_run_timestamp($run['created_at'] ?? null);
    if ($lastTs <= 0) {
        return false;
    }
    $nowTs ??= time();
    return ($nowTs - $lastTs) > stocks_tool_active_run_stale_after_seconds();
}

function stocks_tool_run_mark_stale(int $runId, string $reason = '', array $cfg = []): void
{
    if ($runId <= 0) {
        return;
    }
    $run = stocks_tool_run_get($runId, $cfg);
    if (!is_array($run) || !stocks_tool_run_is_stale_active($run)) {
        return;
    }

    $reason = trim($reason) !== ''
        ? trim($reason)
        : 'Запуск завис и не обновлялся дольше ' . (int)(stocks_tool_active_run_stale_after_seconds() / 60) . ' минут.';
    $summary = is_array($run['summary'] ?? null) ? (array)$run['summary'] : [];
    if (!$summary) {
        $summary = [
            'kind' => (string)($run['kind'] ?? 'stock_sync'),
            'run_id' => $runId,
            'profile_id' => (int)($run['profile_id'] ?? 0),
            'connection_id' => (int)($run['connection_id'] ?? 0),
            'marketplace' => (string)($run['marketplace'] ?? 'ozon'),
            'totals' => is_array($run['totals'] ?? null) ? (array)$run['totals'] : [],
        ];
    }
    $summary['run_id'] = $runId;
    $summary['progress_current'] = (int)($run['progress_current'] ?? ($summary['progress_current'] ?? 0));
    $summary['progress_total'] = (int)($run['progress_total'] ?? ($summary['progress_total'] ?? 0));
    $summary['stale_marked_at'] = date('Y-m-d H:i:s');
    $summary['stale_reason'] = $reason;

    stocks_tool_run_append($runId, "Run #{$runId}: {$reason}\n");
    stocks_tool_run_finish($runId, 'error', $summary, $reason, $cfg);
}

function stocks_tool_run_list_for_profile(int $profileId, int $limit = 12, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_runs
        WHERE profile_id = ?
        ORDER BY id DESC
        LIMIT " . max(1, min(100, $limit))
    );
    $st->execute([$profileId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['totals'] = json_decode((string)($row['totals_json'] ?? '{}'), true) ?: [];
        $row['summary'] = json_decode((string)($row['summary_json'] ?? '{}'), true) ?: [];
    }
    unset($row);
    return $rows;
}

function stocks_tool_run_active_for_profile(int $profileId, array $cfg = []): ?array
{
    stocks_tool_profiles_table_ensure($cfg);
    if ($profileId <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_runs
        WHERE profile_id = ? AND status IN ('queued', 'running')
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$profileId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['totals'] = json_decode((string)($row['totals_json'] ?? '{}'), true) ?: [];
    $row['summary'] = json_decode((string)($row['summary_json'] ?? '{}'), true) ?: [];
    return $row;
}

function stocks_tool_run_active_map(array $profileIds, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $value): bool => $value > 0));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($profileIds), '?'));
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_runs
        WHERE profile_id IN ($placeholders) AND status IN ('queued', 'running')
        ORDER BY profile_id ASC, id DESC
    ");
    $st->execute($profileIds);
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $profileId = (int)($row['profile_id'] ?? 0);
        if ($profileId <= 0 || isset($map[$profileId])) {
            continue;
        }
        $row['totals'] = json_decode((string)($row['totals_json'] ?? '{}'), true) ?: [];
        $row['summary'] = json_decode((string)($row['summary_json'] ?? '{}'), true) ?: [];
        $map[$profileId] = $row;
    }
    return $map;
}

function stocks_tool_run_recent_map(array $profileIds, int $limitPerProfile = 6, array $cfg = []): array
{
    stocks_tool_profiles_table_ensure($cfg);
    $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $value): bool => $value > 0));
    if (!$profileIds) {
        return [];
    }
    $limitPerProfile = max(1, min(50, $limitPerProfile));
    $map = [];
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_stock_runs
        WHERE profile_id = ?
        ORDER BY id DESC
        LIMIT {$limitPerProfile}
    ");
    foreach ($profileIds as $profileId) {
        $st->execute([$profileId]);
        foreach ($st->fetchAll() ?: [] as $row) {
            $rowProfileId = (int)($row['profile_id'] ?? 0);
            if ($rowProfileId <= 0) {
                continue;
            }
            $row['totals'] = json_decode((string)($row['totals_json'] ?? '{}'), true) ?: [];
            $row['summary'] = json_decode((string)($row['summary_json'] ?? '{}'), true) ?: [];
            $map[$rowProfileId][] = $row;
        }
    }
    return $map;
}

function stocks_tool_run_start(int $profileId, string $kind = 'manual_stock_sync', ?string $actor = null, array $cfg = [], array $summarySeed = []): array
{
    $kind = trim($kind) !== '' ? trim($kind) : 'manual_stock_sync';
    $activeRun = stocks_tool_run_active_for_profile($profileId, $cfg);
    if (is_array($activeRun)) {
        if (stocks_tool_run_is_stale_active($activeRun)) {
            stocks_tool_run_mark_stale(
                (int)($activeRun['id'] ?? 0),
                'Предыдущий запуск Stocks Tool завис и был автоматически закрыт перед новым запуском.',
                $cfg
            );
        } else {
            return [
                'run_id' => (int)($activeRun['id'] ?? 0),
                'already_running' => true,
            ];
        }
    }

    $activeRun = stocks_tool_run_active_for_profile($profileId, $cfg);
    if (is_array($activeRun)) {
        return [
            'run_id' => (int)($activeRun['id'] ?? 0),
            'already_running' => true,
        ];
    }

    $runId = stocks_tool_run_create($profileId, $kind, $actor, $cfg, $summarySeed);
    $queueMessage = match ($kind) {
        'automation_stock_sync' => 'Run #' . $runId . ": поставлен в очередь по автоматизации обновления остатков.\n",
        'manual_zero_supplier' => 'Run #' . $runId . ": поставлен в очередь на обнуление остатков выбранного поставщика.\n",
        default => 'Run #' . $runId . ": поставлен в очередь на обновление остатков.\n",
    };
    stocks_tool_run_append($runId, $queueMessage);
    stocks_tool_spawn_run_process($runId, $actor);

    return [
        'run_id' => $runId,
        'already_running' => false,
    ];
}

function stocks_tool_spawn_run_process(int $runId, ?string $actor = null): void
{
    if ($runId <= 0) {
        return;
    }
    $rootDir = dirname(__DIR__);
    $phpBinary = PHP_SAPI === 'cli'
        ? (PHP_BINARY !== '' ? PHP_BINARY : 'php')
        : 'php';
    $script = $rootDir . '/bin/stocks_tool_run.php';
    $cmd = 'cd ' . escapeshellarg($rootDir)
        . ' && ' . escapeshellarg($phpBinary)
        . ' ' . escapeshellarg($script)
        . ' --run_id=' . (int)$runId;
    if ($actor !== null && trim($actor) !== '') {
        $cmd .= ' --actor=' . escapeshellarg(trim($actor));
    }
    $cmd .= ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function stocks_tool_manual_run_start(int $profileId, ?string $actor = null, array $cfg = []): int
{
    $startSummary = stocks_tool_run_start($profileId, 'manual_stock_sync', $actor, $cfg);
    return (int)($startSummary['run_id'] ?? 0);
}

function stocks_tool_zero_supplier_run_start(int $profileId, int $feedId, ?string $actor = null, array $cfg = []): int
{
    $feed = stocks_tool_profile_feed_row_by_id($profileId, $feedId, null, $cfg);
    if (!is_array($feed)) {
        throw new RuntimeException('Источник поставщика не найден в профиле остатков.');
    }
    $summarySeed = [
        'selected_feed_id' => $feedId,
        'selected_feed_name' => (string)($feed['name'] ?? ''),
        'selected_supplier_code' => (string)($feed['supplier_code'] ?? ''),
    ];
    $startSummary = stocks_tool_run_start($profileId, 'manual_zero_supplier', $actor, $cfg, $summarySeed);
    return (int)($startSummary['run_id'] ?? 0);
}

function stocks_tool_manual_run_execute(int $runId, ?string $actor = null, array $cfg = []): void
{
    stocks_tool_profiles_table_ensure($cfg);
    $run = stocks_tool_run_get($runId, $cfg);
    if (!is_array($run)) {
        throw new RuntimeException('Run Stocks Tool не найден.');
    }

    $profileId = (int)($run['profile_id'] ?? 0);
    $profile = stocks_tool_profile_get($profileId, null, $cfg);
    if (!is_array($profile)) {
        throw new RuntimeException('Профиль остатков не найден.');
    }
    $connection = ozon_price_connection_get((int)($profile['connection_id'] ?? 0), $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Подключение маркетплейса не найдено.');
    }
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $feedRows = stocks_tool_profile_feed_rows($profileId, (int)($profile['connection_id'] ?? 0), $cfg);
    $runSummary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
    $runKind = (string)($run['kind'] ?? 'manual_stock_sync');
    $marketplace = stocks_tool_marketplace_key($profile);
    $marketplaceLabel = stocks_tool_marketplace_item_label($marketplace);
    if (!$feedRows && $runKind !== 'manual_zero_supplier') {
        throw new RuntimeException('У профиля нет поставщиков для расчёта остатков.');
    }

    $summary = [
        'kind' => $runKind,
        'run_id' => $runId,
        'profile_id' => $profileId,
        'connection_id' => (int)($profile['connection_id'] ?? 0),
        'marketplace' => $marketplace,
        'profile_name' => (string)($profile['name'] ?? ''),
        'warehouse_name' => (string)($profile['ozon_warehouse_name'] ?? ''),
        'warehouse_id' => (string)($profile['ozon_warehouse_id'] ?? ''),
        'totals' => [
            'feed_offers' => 0,
            'feed_errors' => 0,
            'scoped_offers' => 0,
            'missing_zeroed' => 0,
            'zero_forced' => 0,
            'zero_no_price' => 0,
            'zero_by_article' => 0,
            'zero_by_category' => 0,
            'zero_by_brand' => 0,
            'supplier_force_zero' => 0,
            'fbo_fbs_zeroed' => 0,
            'bundle_scoped' => 0,
            'bundle_derived' => 0,
            'bundle_missing_base' => 0,
            'bundle_invalid' => 0,
            'unchanged' => 0,
            'to_update' => 0,
            'sent' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'not_on_ozon' => 0,
            'not_on_wb' => 0,
            'not_on_yandex' => 0,
            'auto_unarchive_candidates' => 0,
            'auto_unarchive_selected' => 0,
            'auto_unarchive_sent' => 0,
            'auto_unarchive_stock_updates_added' => 0,
        ],
        'feeds' => [],
        'progress_current' => 0,
        'progress_total' => 0,
    ];
    $progressCurrent = 0;
    $progressTotal = 0;

    $logBuffer = '';
    $logLastFlushAt = microtime(true);
    $flushLog = static function (bool $force = false) use (&$logBuffer, &$logLastFlushAt, $runId): void {
        if ($logBuffer === '') {
            return;
        }
        if (!$force && strlen($logBuffer) < 4096 && (microtime(true) - $logLastFlushAt) < 1.5) {
            return;
        }
        stocks_tool_run_append($runId, $logBuffer);
        $logBuffer = '';
        $logLastFlushAt = microtime(true);
    };
    $log = static function (string $message) use (&$logBuffer, $flushLog): void {
        if ($message === '') {
            return;
        }
        $logBuffer .= $message;
        $flushLog(false);
    };

    try {
        $st = db()->prepare("UPDATE feedtools_marketplace_stock_runs SET status = 'running', started_at = NOW() WHERE id = ?");
        $st->execute([$runId]);

        if ($runKind === 'manual_zero_supplier') {
            $log('Run #' . $runId . ': старт обнуления остатков выбранного поставщика на ' . $marketplaceLabel . ".\n");
        } else {
            $log('Run #' . $runId . ': старт обновления остатков на ' . $marketplaceLabel . ".\n");
        }
        $log('Profile #' . $profileId . ': ' . (string)($profile['name'] ?? '') . "\n");
        $connectionTitle = trim((string)($connection['name'] ?? ''));
        if ($connectionTitle === '') {
            $connectionTitle = trim((string)($connection['title'] ?? ''));
        }
        $log('Connection #' . (int)($profile['connection_id'] ?? 0) . ': ' . $connectionTitle . "\n");
        $log('Склад ' . $marketplaceLabel . ': ' . (string)($profile['ozon_warehouse_name'] ?? '—') . ' · ID ' . (string)($profile['ozon_warehouse_id'] ?? '') . "\n");

        $feedOfferMap = [];
        $scopeOfferIds = [];
        if ($runKind === 'manual_zero_supplier') {
            $selectedFeedId = (int)($runSummary['selected_feed_id'] ?? 0);
            $selectedFeed = stocks_tool_profile_feed_row_by_id($profileId, $selectedFeedId, (int)($profile['connection_id'] ?? 0), $cfg);
            if (!is_array($selectedFeed)) {
                throw new RuntimeException('Для запуска обнуления не найден выбранный источник поставщика.');
            }
            $selectedSupplierCode = trim((string)($runSummary['selected_supplier_code'] ?? ($selectedFeed['supplier_code'] ?? '')));
            if ($selectedSupplierCode === '') {
                throw new RuntimeException('У выбранного источника не указан код поставщика.');
            }
            $summary['selected_feed_id'] = $selectedFeedId;
            $summary['selected_feed_name'] = (string)($selectedFeed['name'] ?? '');
            $summary['selected_supplier_code'] = $selectedSupplierCode;
            $summary['feeds'] = [[
                'feed_id' => $selectedFeedId,
                'feed_name' => (string)($selectedFeed['name'] ?? ''),
                'supplier_code' => $selectedSupplierCode,
            ]];
            $log('Источник для обнуления: ' . (string)($selectedFeed['name'] ?? ('feed #' . $selectedFeedId)) . ' · supplier=' . $selectedSupplierCode . "\n");
            $flushLog(true);

            $scopeOfferIds = stocks_tool_scope_offer_ids_for_supplier($profile, $selectedSupplierCode, $runtimeCfg, $log);
            $summary['totals']['feed_offers'] = count($scopeOfferIds);
            $summary['totals']['scoped_offers'] = count($scopeOfferIds);
            $progressTotal = count($scopeOfferIds);
            $summary['progress_total'] = $progressTotal;
            $flushLog(true);
            stocks_tool_run_update($runId, 'running', $summary, $progressCurrent, $progressTotal, $cfg);

            $stateMap = stocks_tool_state_map($profileId, (string)($profile['ozon_warehouse_key'] ?? ''), $scopeOfferIds, $cfg);
            $ozonStockMap = stocks_tool_fetch_marketplace_stock_map($runtimeCfg, $connection, $profile, $scopeOfferIds, $log);
            $comparison = stocks_tool_build_zero_supplier_state_rows($profile, $scopeOfferIds, $ozonStockMap, $stateMap, $selectedFeedId, $selectedSupplierCode);
        } else {
            $feedsResult = stocks_tool_profile_feed_quantity_map($profile, $feedRows, $log);
            $feedOfferMap = is_array($feedsResult['offers'] ?? null) ? $feedsResult['offers'] : [];
            $summary['feeds'] = is_array($feedsResult['feeds'] ?? null) ? $feedsResult['feeds'] : [];
            $failedFeeds = array_values(array_filter((array)($feedsResult['failed_feeds'] ?? []), 'is_array'));
            $availableSupplierCodes = array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                (array)($feedsResult['successful_supplier_codes'] ?? [])
            ))));
            $effectiveProfile = $profile;
            $effectiveProfile['supplier_codes'] = $availableSupplierCodes;
            $summary['failed_feeds'] = $failedFeeds;
            $summary['available_supplier_codes'] = $availableSupplierCodes;
            $summary['totals']['feed_errors'] = count($failedFeeds);
            $summary['totals']['feed_offers'] = count($feedOfferMap);
            if (!$feedOfferMap && !$availableSupplierCodes && !stocks_tool_force_zero_supplier_codes($effectiveProfile)) {
                $failedNames = array_values(array_filter(array_map(
                    static fn(array $row): string => trim((string)($row['feed_name'] ?? '')),
                    $failedFeeds
                )));
                throw new RuntimeException('Ни один источник поставщиков не доступен для безопасного обновления остатков'
                    . ($failedNames ? (': ' . implode(', ', $failedNames)) : '') . '.');
            }
            $flushLog(true);

            $scopeOfferIds = stocks_tool_scope_offer_ids($effectiveProfile, $feedOfferMap, $runtimeCfg, $log);
            $summary['totals']['scoped_offers'] = count($scopeOfferIds);
            $progressTotal = count($scopeOfferIds);
            $summary['progress_total'] = $progressTotal;
            $flushLog(true);
            stocks_tool_run_update($runId, 'running', $summary, $progressCurrent, $progressTotal, $cfg);

            $stateMap = stocks_tool_state_map($profileId, (string)($profile['ozon_warehouse_key'] ?? ''), $scopeOfferIds, $cfg);
            $ozonStockMap = stocks_tool_fetch_marketplace_stock_map($runtimeCfg, $connection, $profile, $scopeOfferIds, $log);
            $globalReservedUnits = stocks_tool_global_reserved_units_by_base_offer(
                $scopeOfferIds,
                (int)($profile['connection_id'] ?? 0),
                $ozonStockMap,
                $runtimeCfg,
                $log
            );
            $comparison = stocks_tool_build_state_rows($effectiveProfile, $scopeOfferIds, $feedOfferMap, $ozonStockMap, $stateMap, $runtimeCfg, $log, $globalReservedUnits);
        }

        $stateRows = is_array($comparison['state_rows'] ?? null) ? $comparison['state_rows'] : [];
        $updates = is_array($comparison['updates'] ?? null) ? $comparison['updates'] : [];
        foreach ((array)($comparison['totals'] ?? []) as $key => $value) {
            $summary['totals'][$key] = (int)$value;
        }
        $summary['progress_total'] = count($stateRows);
        $progressTotal = count($stateRows);
        $missingTotalKey = stocks_tool_missing_marketplace_total_key($marketplace);
        $log('После сравнения: scoped=' . $summary['totals']['scoped_offers']
            . ' · unchanged=' . $summary['totals']['unchanged']
            . ' · to_update=' . $summary['totals']['to_update']
            . ' · missing_zeroed=' . $summary['totals']['missing_zeroed']
            . ' · no_price=' . (int)($summary['totals']['zero_no_price'] ?? 0)
            . ' · zero_rules=' . (int)($summary['totals']['zero_forced'] ?? 0)
            . ' · supplier_zero=' . (int)($summary['totals']['supplier_force_zero'] ?? 0)
            . ' · fbo_fbs_zeroed=' . (int)($summary['totals']['fbo_fbs_zeroed'] ?? 0)
            . ' · qty_capped=' . (int)($summary['totals']['marketplace_qty_capped'] ?? 0)
            . ' · forced_resend=' . (int)($summary['totals']['marketplace_forced_resend'] ?? 0)
            . ' · bundles=' . (int)($summary['totals']['bundle_derived'] ?? 0) . '/' . (int)($summary['totals']['bundle_scoped'] ?? 0)
            . ' · ' . $missingTotalKey . '=' . (int)($summary['totals'][$missingTotalKey] ?? 0) . "\n");
        $flushLog(true);
        stocks_tool_run_update($runId, 'running', $summary, $progressCurrent, $progressTotal, $cfg);

        if ($runKind !== 'manual_zero_supplier' && $marketplace === 'ozon') {
            $autoUnarchive = stocks_tool_ozon_auto_unarchive_execute($profile, $connection, $stateRows, $runtimeCfg, $log);
            if (($autoUnarchive['status'] ?? '') === 'done') {
                $stockUpdatesAdded = stocks_tool_ozon_auto_unarchive_append_stock_updates(
                    $updates,
                    $stateRows,
                    is_array($autoUnarchive['selected_items'] ?? null) ? (array)$autoUnarchive['selected_items'] : []
                );
                if ($stockUpdatesAdded > 0) {
                    $autoUnarchive['stock_updates_added'] = $stockUpdatesAdded;
                    $summary['totals']['to_update'] = (int)($summary['totals']['to_update'] ?? 0) + $stockUpdatesAdded;
                    $log('[ozon auto-unarchive] добавил в текущий push остатков=' . $stockUpdatesAdded . "\n");
                }
            }
            $summary['ozon_auto_unarchive'] = $autoUnarchive;
            $summary['totals']['auto_unarchive_candidates'] = (int)($autoUnarchive['archived_candidates'] ?? 0);
            $summary['totals']['auto_unarchive_selected'] = (int)($autoUnarchive['selected'] ?? 0);
            $summary['totals']['auto_unarchive_sent'] = (int)($autoUnarchive['sent'] ?? 0);
            $summary['totals']['auto_unarchive_stock_updates_added'] = (int)($autoUnarchive['stock_updates_added'] ?? 0);
            $log('Автоархив Ozon: status=' . (string)($autoUnarchive['status'] ?? 'skipped')
                . ' · positive=' . (int)($autoUnarchive['positive_stock_offers'] ?? 0)
                . ' · archived_candidates=' . (int)($autoUnarchive['archived_candidates'] ?? 0)
                . ' · selected=' . (int)($autoUnarchive['selected'] ?? 0)
                . ' · sent=' . (int)($autoUnarchive['sent'] ?? 0)
                . ' · stock_updates_added=' . (int)($autoUnarchive['stock_updates_added'] ?? 0) . "\n");
            $flushLog(true);
            stocks_tool_run_update($runId, 'running', $summary, $progressCurrent, $progressTotal, $cfg);
        }

        $pushResult = stocks_tool_push_updates($profile, array_values($updates), $runtimeCfg, $log);
        $pushResults = is_array($pushResult['results'] ?? null) ? $pushResult['results'] : [];
        $summary['totals']['sent'] = (int)(($pushResult['totals']['sent'] ?? 0));
        $summary['totals']['updated'] = (int)(($pushResult['totals']['updated'] ?? 0));
        $summary['totals']['skipped'] = (int)(($pushResult['totals']['skipped'] ?? 0));
        $summary['totals']['errors'] = (int)(($pushResult['totals']['errors'] ?? 0));

        foreach ($stateRows as $offerId => &$row) {
            $offerId = (string)$offerId;
            if (isset($pushResults[$offerId])) {
                $result = $pushResults[$offerId];
                $row['last_push_status'] = (string)($result['status'] ?? 'error');
                $row['last_push_error'] = ($result['status'] ?? '') === 'error'
                    ? (string)($result['message'] ?? 'Ошибка обновления остатка.')
                    : null;
                if (($result['status'] ?? '') === 'updated' || ($result['status'] ?? '') === 'accepted') {
                    $row['last_pushed_qty'] = (int)($row['last_target_qty'] ?? 0);
                }
            }
            $progressCurrent++;
            $summary['progress_current'] = $progressCurrent;
        }
        unset($row);

        $rowsToPersist = stocks_tool_state_rows_changed(array_values($stateRows), $stateMap);
        if ($rowsToPersist) {
            stocks_tool_state_upsert($rowsToPersist, $runId, $cfg);
        }
        $flushLog(true);
        stocks_tool_run_update($runId, 'running', $summary, $progressCurrent, $progressTotal, $cfg);

        $log('Итог: feed_offers=' . (int)$summary['totals']['feed_offers']
            . ' · feed_errors=' . (int)($summary['totals']['feed_errors'] ?? 0)
            . ' · scoped=' . (int)$summary['totals']['scoped_offers']
            . ' · to_update=' . (int)$summary['totals']['to_update']
            . ' · updated=' . (int)$summary['totals']['updated']
            . ' · skipped=' . (int)$summary['totals']['skipped']
            . ' · errors=' . (int)$summary['totals']['errors']
            . ' · persisted=' . count($rowsToPersist) . "\n");
        $flushLog(true);

        $pushErrors = (int)($summary['totals']['errors'] ?? 0);
        $feedErrors = (int)($summary['totals']['feed_errors'] ?? 0);
        $status = ($pushErrors > 0 || $feedErrors > 0) ? 'partial' : 'success';
        $errorParts = [];
        if ($feedErrors > 0) {
            $errorParts[] = 'Недоступно источников поставщиков: ' . $feedErrors . '; их товары не изменялись';
        }
        if ($pushErrors > 0) {
            $errorParts[] = 'часть товаров не удалось обновить на маркетплейсе';
        }
        stocks_tool_run_finish($runId, $status, $summary, $errorParts ? (implode('. ', $errorParts) . '.') : null, $cfg);
    } finally {
        $flushLog(true);
    }
}

function stocks_tool_automation_mark_started(int $automationId, string $slotKey, int $runId, array $cfg = []): void
{
    stocks_tool_automation_table_ensure($cfg);
    if ($automationId <= 0) {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_stock_profile_automations
        SET last_run_at = NOW(), last_run_slot_key = ?, last_run_run_id = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $st->execute([$slotKey, $runId > 0 ? $runId : null, $automationId]);
}

function stocks_tool_automation_run_due(callable $log, ?int $profileId = null, ?int $connectionId = null, array $cfg = []): array
{
    $cfg = stocks_tool_cfg_fallback($cfg);
    stocks_tool_module_bootstrap($cfg);

    $sql = "
        SELECT a.*
        FROM feedtools_marketplace_stock_profile_automations a
        INNER JOIN feedtools_marketplace_stock_profiles p ON p.id = a.profile_id
        WHERE a.enabled = 1
    ";
    $args = [];
    if (($profileId ?? 0) > 0) {
        $sql .= " AND a.profile_id = ?";
        $args[] = (int)$profileId;
    } elseif (($connectionId ?? 0) > 0) {
        $sql .= " AND p.connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY a.id ASC";
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];

    $nowMsk = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $maxParallel = max(1, min(10, (int)($cfg['worker']['stocks_tool_automation_max_parallel'] ?? 1)));
    $automationProfileIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['profile_id'] ?? 0),
        array_filter($rows, 'is_array')
    ))));
    $activeRunsByProfile = stocks_tool_run_active_map($automationProfileIds, $cfg);
    $activeRunIds = [];
    foreach ($activeRunsByProfile as $activeRun) {
        $activeRunId = (int)($activeRun['id'] ?? 0);
        if ($activeRunId > 0 && stocks_tool_run_is_stale_active($activeRun)) {
            stocks_tool_run_mark_stale(
                $activeRunId,
                'Активный запуск автоматизации завис и был закрыт перед проверкой общей очереди.',
                $cfg
            );
            continue;
        }
        if ($activeRunId > 0) {
            $activeRunIds[$activeRunId] = true;
        }
    }
    $activeCount = count($activeRunIds);
    $summary = [
        'now_msk' => $nowMsk->format('Y-m-d H:i:s'),
        'max_parallel' => $maxParallel,
        'active_at_start' => $activeCount,
        'checked' => 0,
        'queued' => 0,
        'skipped' => 0,
        'items' => [],
    ];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $profile = stocks_tool_profile_get((int)($row['profile_id'] ?? 0), null, $cfg);
        if (!is_array($profile)) {
            continue;
        }
        $automation = stocks_tool_automation_hydrate($row, $profile);
        $summary['checked']++;
        $item = [
            'automation_id' => (int)($automation['id'] ?? 0),
            'profile_id' => (int)($profile['id'] ?? 0),
            'profile_name' => (string)($profile['name'] ?? ''),
            'operation_key' => (string)($automation['operation_key'] ?? 'sync'),
            'status' => 'skipped',
            'message' => '',
            'run_id' => null,
        ];

        $slot = stocks_tool_automation_slot_info($automation, $nowMsk);
        $item['slot_key'] = (string)$slot['slot_key'];
        $item['slot_start'] = $slot['slot_start']->format('Y-m-d H:i:s');
        $item['slot_end'] = $slot['slot_end']->format('Y-m-d H:i:s');

        if ((string)($automation['last_run_slot_key'] ?? '') === (string)$slot['slot_key']) {
            $item['message'] = 'В текущем интервале уже запускалось.';
            $item['run_id'] = !empty($automation['last_run_run_id']) ? (int)$automation['last_run_run_id'] : null;
            $summary['skipped']++;
            $summary['items'][] = $item;
            continue;
        }

        $lastRunId = (int)($automation['last_run_run_id'] ?? 0);
        if ($lastRunId > 0) {
            $lastRun = stocks_tool_run_get($lastRunId, $cfg);
            $lastStatus = trim((string)($lastRun['status'] ?? ''));
            if (in_array($lastStatus, ['queued', 'running'], true)) {
                if (is_array($lastRun) && stocks_tool_run_is_stale_active($lastRun)) {
                    stocks_tool_run_mark_stale(
                        $lastRunId,
                        'Предыдущий запуск автоматизации завис и был автоматически закрыт перед новым запуском.',
                        $cfg
                    );
                    $log('[automation #' . (int)($automation['id'] ?? 0) . "] stale previous run #" . $lastRunId . " closed\n");
                } else {
                    $item['status'] = 'busy';
                    $item['message'] = 'Предыдущий запуск этой автоматизации ещё не завершился.';
                    $item['run_id'] = $lastRunId;
                    $summary['skipped']++;
                    $summary['items'][] = $item;
                    $log('[automation #' . (int)($automation['id'] ?? 0) . "] waiting previous run #" . $lastRunId . "\n");
                    continue;
                }
            }
        }

        $activeRun = stocks_tool_run_active_for_profile((int)($profile['id'] ?? 0), $cfg);
        if (is_array($activeRun)) {
            if (stocks_tool_run_is_stale_active($activeRun)) {
                stocks_tool_run_mark_stale(
                    (int)($activeRun['id'] ?? 0),
                    'Активный запуск профиля завис и был автоматически закрыт перед новым запуском.',
                    $cfg
                );
                $log('[automation #' . (int)($automation['id'] ?? 0) . "] stale active run #" . (int)($activeRun['id'] ?? 0) . " closed\n");
            } else {
                $item['status'] = 'busy';
                $item['message'] = 'По профилю уже идёт другой запуск.';
                $item['run_id'] = (int)($activeRun['id'] ?? 0);
                $summary['skipped']++;
                $summary['items'][] = $item;
                $log('[automation #' . (int)($automation['id'] ?? 0) . "] busy run #" . (int)($activeRun['id'] ?? 0) . "\n");
                continue;
            }
        }

        $activeRun = stocks_tool_run_active_for_profile((int)($profile['id'] ?? 0), $cfg);
        if (is_array($activeRun)) {
            $item['status'] = 'busy';
            $item['message'] = 'По профилю уже идёт другой запуск.';
            $item['run_id'] = (int)($activeRun['id'] ?? 0);
            $summary['skipped']++;
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . "] busy run #" . (int)($activeRun['id'] ?? 0) . "\n");
            continue;
        }

        if ($activeCount >= $maxParallel) {
            $item['status'] = 'capacity';
            $item['message'] = 'Достигнут лимит одновременных автоматических запусков (' . $maxParallel . ').';
            $summary['skipped']++;
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . '] waiting for global capacity (' . $activeCount . '/' . $maxParallel . ")\n");
            continue;
        }

        try {
            $startSummary = stocks_tool_run_start((int)$profile['id'], 'automation_stock_sync', 'automation', $cfg);
            $runId = (int)($startSummary['run_id'] ?? 0);
            if ($runId > 0 && empty($startSummary['already_running'])) {
                stocks_tool_automation_mark_started((int)($automation['id'] ?? 0), (string)$slot['slot_key'], $runId, $cfg);
            }
            $item['status'] = !empty($startSummary['already_running']) ? 'busy' : 'queued';
            $item['message'] = !empty($startSummary['already_running']) ? 'Профиль уже выполняет запуск.' : 'Запуск поставлен в очередь.';
            $item['run_id'] = $runId > 0 ? $runId : null;
            if ($item['status'] === 'queued') {
                $summary['queued']++;
                $activeCount++;
            } else {
                $summary['skipped']++;
            }
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . '] queued run #' . $runId . " (sync)\n");
        } catch (Throwable $e) {
            $item['status'] = 'error';
            $item['message'] = $e->getMessage();
            $summary['skipped']++;
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . '] error: ' . $e->getMessage() . "\n");
        }
    }

    return $summary;
}
