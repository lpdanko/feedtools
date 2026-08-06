<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/BundleOffer.php';

function orders_sync_cfg_fallback(array $cfg = []): array
{
    if ($cfg) {
        return $cfg;
    }
    return (isset($GLOBALS['cfg']) && is_array($GLOBALS['cfg'])) ? $GLOBALS['cfg'] : [];
}

function orders_sync_table_add_column_if_missing(string $table, string $column, string $alterSql): void
{
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    try {
        db()->exec($alterSql);
    } catch (PDOException $e) {
        $info = $e->errorInfo;
        $sqlState = (string)($info[0] ?? '');
        $mysqlCode = (int)($info[1] ?? 0);
        if ($sqlState === '42S21' || $mysqlCode === 1060) {
            return;
        }
        throw $e;
    }
}

function orders_sync_table_exists(string $table): bool
{
    static $cache = [];
    $table = trim($table);
    if ($table === '') {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return (bool)$cache[$table];
    }
    try {
        $st = db()->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $st->execute([$table]);
        return $cache[$table] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function orders_sync_table_has_index(string $table, string $indexName): bool
{
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    return (int)$st->fetchColumn() > 0;
}

function orders_sync_table_index_columns(string $table, string $indexName): array
{
    $st = db()->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
        ORDER BY SEQ_IN_INDEX ASC
    ");
    $st->execute([$table, $indexName]);
    return array_map(
        static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''),
        $st->fetchAll() ?: []
    );
}

function orders_sync_table_add_index_if_missing(string $table, string $indexName, string $alterSql): void
{
    if (orders_sync_table_has_index($table, $indexName)) {
        return;
    }
    db()->exec($alterSql);
}

function orders_sync_moysklad_accounts_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_moysklad_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            base_url VARCHAR(255) NOT NULL DEFAULT 'https://api.moysklad.ru/api/remap/1.2',
            api_token VARCHAR(255) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            notes TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active_sort (is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function orders_sync_profile_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    orders_sync_moysklad_accounts_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_sync_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            title VARCHAR(190) NOT NULL,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_organization_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_counterparty_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_project_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_saleschannel_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_delivery_planned_source VARCHAR(32) NOT NULL DEFAULT 'order_created_at',
            moysklad_default_store_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_fbo_store_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_fbo_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_wb_dbw_store_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_wb_dbw_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '',
            ozon_status_create_default_state_id VARCHAR(64) NOT NULL DEFAULT '',
            ozon_status_update_default_state_id VARCHAR(64) NOT NULL DEFAULT '__keep__',
            ozon_status_create_map_json LONGTEXT NULL,
            ozon_status_update_map_json LONGTEXT NULL,
            cancelled_transition_default_state_id VARCHAR(64) NOT NULL DEFAULT '',
            cancelled_before_ship_zero_prices TINYINT(1) NOT NULL DEFAULT 0,
            cancelled_transition_map_json LONGTEXT NULL,
            lookback_days INT NOT NULL DEFAULT 14,
            sync_date_from DATE NULL,
            sync_date_to DATE NULL,
            order_sources_json LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            notes TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_marketplace_active_sort (marketplace, is_active, sort_order, id),
            KEY idx_connection (connection_id),
            KEY idx_moysklad (moysklad_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_organization_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_organization_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_account_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_counterparty_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_counterparty_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_organization_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_project_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_project_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_counterparty_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_saleschannel_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_saleschannel_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_project_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_delivery_planned_source',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_delivery_planned_source VARCHAR(32) NOT NULL DEFAULT 'order_created_at' AFTER moysklad_saleschannel_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_default_store_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_default_store_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_delivery_planned_source"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_fbo_store_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_fbo_store_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_default_store_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_fbo_new_order_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_fbo_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_fbo_store_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_wb_dbw_store_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_wb_dbw_store_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_fbo_new_order_state_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'moysklad_wb_dbw_new_order_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN moysklad_wb_dbw_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_wb_dbw_store_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'sync_date_from',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN sync_date_from DATE NULL AFTER lookback_days"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'sync_date_to',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN sync_date_to DATE NULL AFTER sync_date_from"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'ozon_status_create_map_json',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN ozon_status_create_map_json LONGTEXT NULL AFTER ozon_status_update_default_state_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'ozon_status_create_default_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN ozon_status_create_default_state_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_wb_dbw_new_order_state_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'ozon_status_update_default_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN ozon_status_update_default_state_id VARCHAR(64) NOT NULL DEFAULT '__keep__' AFTER ozon_status_create_default_state_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'ozon_status_update_map_json',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN ozon_status_update_map_json LONGTEXT NULL AFTER ozon_status_create_map_json"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'cancelled_transition_default_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN cancelled_transition_default_state_id VARCHAR(64) NOT NULL DEFAULT '' AFTER ozon_status_update_map_json"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'cancelled_before_ship_zero_prices',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN cancelled_before_ship_zero_prices TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_transition_default_state_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profiles',
        'cancelled_transition_map_json',
        "ALTER TABLE feedtools_marketplace_sync_profiles ADD COLUMN cancelled_transition_map_json LONGTEXT NULL AFTER cancelled_before_ship_zero_prices"
    );

    $done = true;
}

function orders_sync_profile_store_mappings_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_sync_profile_store_mappings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            ozon_warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            ozon_warehouse_id VARCHAR(64) NOT NULL DEFAULT '',
            ozon_warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            moysklad_store_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '',
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_profile_warehouse (profile_id, ozon_warehouse_key),
            KEY idx_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_store_mappings',
        'moysklad_new_order_state_id',
        "ALTER TABLE feedtools_marketplace_sync_profile_store_mappings ADD COLUMN moysklad_new_order_state_id VARCHAR(64) NOT NULL DEFAULT '' AFTER moysklad_store_id"
    );

    $done = true;
}

function orders_sync_automation_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_sync_profile_automations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            operation_key VARCHAR(32) NOT NULL DEFAULT 'full',
            period_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            frequency_key VARCHAR(32) NOT NULL DEFAULT 'hourly',
            run_hour_msk TINYINT UNSIGNED NOT NULL DEFAULT 0,
            run_minute_msk TINYINT UNSIGNED NOT NULL DEFAULT 0,
            order_sources_json LONGTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME NULL,
            last_run_slot_key VARCHAR(64) NULL,
            last_run_run_id BIGINT UNSIGNED NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_profile_sort (profile_id, enabled, id),
            KEY idx_enabled_frequency (enabled, frequency_key, profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'period_days',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN period_days SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER operation_key"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'frequency_key',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN frequency_key VARCHAR(32) NOT NULL DEFAULT 'hourly' AFTER period_days"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'run_hour_msk',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN run_hour_msk TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER frequency_key"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'run_minute_msk',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN run_minute_msk TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER run_hour_msk"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'order_sources_json',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN order_sources_json LONGTEXT NULL AFTER run_minute_msk"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'enabled',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER order_sources_json"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'last_run_at',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN last_run_at DATETIME NULL AFTER enabled"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'last_run_slot_key',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN last_run_slot_key VARCHAR(64) NULL AFTER last_run_at"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'last_run_run_id',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD COLUMN last_run_run_id BIGINT UNSIGNED NULL AFTER last_run_slot_key"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'idx_profile_sort',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD KEY idx_profile_sort (profile_id, enabled, id)"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_sync_profile_automations',
        'idx_enabled_frequency',
        "ALTER TABLE feedtools_marketplace_sync_profile_automations ADD KEY idx_enabled_frequency (enabled, frequency_key, profile_id)"
    );

    $done = true;
}

function orders_sync_orders_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_order_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            order_source VARCHAR(16) NOT NULL DEFAULT 'fbs',
            external_order_id VARCHAR(64) NOT NULL DEFAULT '',
            order_number VARCHAR(64) NOT NULL DEFAULT '',
            posting_number VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(64) NOT NULL DEFAULT '',
            substatus VARCHAR(64) NOT NULL DEFAULT '',
            order_created_at DATETIME NULL,
            in_process_at DATETIME NULL,
            payload_json LONGTEXT NOT NULL,
            synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_profile_posting (profile_id, marketplace, order_source, posting_number),
            KEY idx_connection_synced (connection_id, synced_at, id),
            KEY idx_profile_synced (profile_id, synced_at, id),
            KEY idx_profile_status (profile_id, status, synced_at),
            KEY idx_last_seen_run (last_seen_run_id, order_source, id),
            KEY idx_external_order (profile_id, external_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'profile_id',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'connection_id',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER profile_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'moysklad_account_id',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER connection_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'marketplace',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon' AFTER moysklad_account_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'order_source',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN order_source VARCHAR(16) NOT NULL DEFAULT 'fbs' AFTER marketplace"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'posting_number',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN posting_number VARCHAR(80) NOT NULL DEFAULT '' AFTER order_number"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'synced_at',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER payload_json"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'last_seen_run_id',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN last_seen_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER synced_at"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_snapshots',
        'last_seen_at',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD COLUMN last_seen_at DATETIME NULL AFTER last_seen_run_id"
    );

    if (orders_sync_table_has_index('feedtools_marketplace_order_snapshots', 'uniq_connection_posting')
        && !orders_sync_table_has_index('feedtools_marketplace_order_snapshots', 'uniq_profile_posting')) {
        db()->exec("ALTER TABLE feedtools_marketplace_order_snapshots DROP INDEX uniq_connection_posting");
    }
    if (!orders_sync_table_has_index('feedtools_marketplace_order_snapshots', 'uniq_profile_posting')) {
        db()->exec("ALTER TABLE feedtools_marketplace_order_snapshots ADD UNIQUE KEY uniq_profile_posting (profile_id, marketplace, order_source, posting_number)");
    }
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_snapshots',
        'idx_profile_synced',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD KEY idx_profile_synced (profile_id, synced_at, id)"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_snapshots',
        'idx_profile_status',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD KEY idx_profile_status (profile_id, status, synced_at)"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_snapshots',
        'idx_last_seen_run',
        "ALTER TABLE feedtools_marketplace_order_snapshots ADD KEY idx_last_seen_run (last_seen_run_id, order_source, id)"
    );
    if (orders_sync_table_index_columns('feedtools_marketplace_order_snapshots', 'idx_external_order') !== ['profile_id', 'external_order_id']) {
        if (orders_sync_table_has_index('feedtools_marketplace_order_snapshots', 'idx_external_order')) {
            db()->exec("ALTER TABLE feedtools_marketplace_order_snapshots DROP INDEX idx_external_order");
        }
        db()->exec("ALTER TABLE feedtools_marketplace_order_snapshots ADD KEY idx_external_order (profile_id, external_order_id)");
    }

    // Safe legacy backfill: only when a connection has exactly one profile, so the mapping is unambiguous.
    db()->exec("
        DELETE s
        FROM feedtools_marketplace_order_snapshots s
        JOIN (
            SELECT connection_id, MIN(id) AS profile_id, MIN(moysklad_account_id) AS moysklad_account_id
            FROM feedtools_marketplace_sync_profiles
            WHERE connection_id > 0
            GROUP BY connection_id
            HAVING COUNT(*) = 1
        ) p ON p.connection_id = s.connection_id
        JOIN feedtools_marketplace_order_snapshots existing
          ON existing.profile_id = p.profile_id
         AND existing.marketplace = s.marketplace
         AND existing.order_source = s.order_source
         AND existing.posting_number = s.posting_number
         AND existing.id <> s.id
        WHERE s.profile_id = 0
    ");
    db()->exec("
        UPDATE feedtools_marketplace_order_snapshots s
        JOIN (
            SELECT connection_id, MIN(id) AS profile_id, MIN(moysklad_account_id) AS moysklad_account_id
            FROM feedtools_marketplace_sync_profiles
            WHERE connection_id > 0
            GROUP BY connection_id
            HAVING COUNT(*) = 1
        ) p ON p.connection_id = s.connection_id
        SET s.profile_id = p.profile_id,
            s.moysklad_account_id = CASE
                WHEN s.moysklad_account_id = 0 THEN p.moysklad_account_id
                ELSE s.moysklad_account_id
            END
        WHERE s.profile_id = 0
    ");

    $done = true;
}

function orders_sync_order_history_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_orders_table_ensure($cfg);
    orders_sync_runs_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_order_snapshot_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            order_source VARCHAR(16) NOT NULL DEFAULT 'fbs',
            external_order_id VARCHAR(64) NOT NULL DEFAULT '',
            order_number VARCHAR(64) NOT NULL DEFAULT '',
            posting_number VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(64) NOT NULL DEFAULT '',
            substatus VARCHAR(64) NOT NULL DEFAULT '',
            order_created_at DATETIME NULL,
            in_process_at DATETIME NULL,
            payload_json LONGTEXT NOT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_run_posting (run_id, profile_id, marketplace, order_source, posting_number),
            KEY idx_profile_fetched (profile_id, fetched_at, id),
            KEY idx_run (run_id, id),
            KEY idx_fetched (fetched_at, id),
            KEY idx_external_order (profile_id, external_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_snapshot_history',
        'idx_fetched',
        "ALTER TABLE feedtools_marketplace_order_snapshot_history ADD KEY idx_fetched (fetched_at, id)"
    );

    $done = true;
}

function orders_sync_runs_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_order_sync_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            actor VARCHAR(190) NULL DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'running',
            source_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            runner_pid BIGINT UNSIGNED NOT NULL DEFAULT 0,
            heartbeat_at DATETIME NULL,
            period_since DATETIME NULL,
            period_to DATETIME NULL,
            summary_json LONGTEXT NULL,
            log_text LONGTEXT NULL,
            error_text TEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_profile_started (profile_id, started_at, id),
            KEY idx_connection_started (connection_id, started_at, id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'profile_id',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'connection_id',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER profile_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'moysklad_account_id',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER connection_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'marketplace',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon' AFTER moysklad_account_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'actor',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN actor VARCHAR(190) NULL DEFAULT NULL AFTER source"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'source_run_id',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN source_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'runner_pid',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN runner_pid BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_run_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'heartbeat_at',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN heartbeat_at DATETIME NULL AFTER runner_pid"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'period_since',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN period_since DATETIME NULL AFTER source_run_id"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'period_to',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN period_to DATETIME NULL AFTER period_since"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'error_text',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN error_text TEXT NULL AFTER log_text"
    );
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'updated_at',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER finished_at"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'idx_profile_started',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD KEY idx_profile_started (profile_id, started_at, id)"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_sync_runs',
        'idx_profile_source_run',
        "ALTER TABLE feedtools_marketplace_order_sync_runs ADD KEY idx_profile_source_run (profile_id, source_run_id, id)"
    );

    db()->exec("
        UPDATE feedtools_marketplace_order_sync_runs r
        JOIN (
            SELECT connection_id, MIN(id) AS profile_id, MIN(moysklad_account_id) AS moysklad_account_id
            FROM feedtools_marketplace_sync_profiles
            WHERE connection_id > 0
            GROUP BY connection_id
            HAVING COUNT(*) = 1
        ) p ON p.connection_id = r.connection_id
        SET r.profile_id = p.profile_id,
            r.moysklad_account_id = CASE
                WHEN r.moysklad_account_id = 0 THEN p.moysklad_account_id
                ELSE r.moysklad_account_id
            END
        WHERE r.profile_id = 0
    ");

    $done = true;
}

function orders_sync_module_bootstrap(array $cfg = []): void
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_moysklad_accounts_table_ensure($cfg);
    orders_sync_profile_table_ensure($cfg);
    orders_sync_profile_store_mappings_table_ensure($cfg);
    orders_sync_automation_table_ensure($cfg);
    orders_sync_orders_table_ensure($cfg);
    orders_sync_runs_table_ensure($cfg);
    orders_sync_order_history_table_ensure($cfg);
    orders_sync_exports_table_ensure($cfg);
}

function orders_sync_exports_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_order_export_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            moysklad_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            order_source VARCHAR(16) NOT NULL DEFAULT 'fbs',
            posting_number VARCHAR(80) NOT NULL DEFAULT '',
            order_number VARCHAR(64) NOT NULL DEFAULT '',
            external_order_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_customerorder_id VARCHAR(64) NOT NULL DEFAULT '',
            moysklad_counterparty_id VARCHAR(64) NOT NULL DEFAULT '',
            export_mode VARCHAR(32) NOT NULL DEFAULT 'test',
            last_status VARCHAR(32) NOT NULL DEFAULT '',
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            error_text TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_profile_posting (profile_id, marketplace, order_source, posting_number),
            KEY idx_customerorder (moysklad_customerorder_id),
            KEY idx_connection (connection_id, updated_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    orders_sync_table_add_column_if_missing(
        'feedtools_marketplace_order_export_links',
        'source_fingerprint',
        "ALTER TABLE feedtools_marketplace_order_export_links ADD COLUMN source_fingerprint VARCHAR(64) NOT NULL DEFAULT '' AFTER last_status"
    );
    orders_sync_table_add_index_if_missing(
        'feedtools_marketplace_order_export_links',
        'idx_scope_posting',
        "ALTER TABLE feedtools_marketplace_order_export_links ADD KEY idx_scope_posting (connection_id, moysklad_account_id, marketplace, order_source, posting_number, updated_at, id)"
    );

    $done = true;
}

function orders_sync_export_link_get(int $profileId, string $marketplace, string $orderSource, string $postingNumber, array $cfg = []): ?array
{
    orders_sync_exports_table_ensure($cfg);
    if ($profileId <= 0 || trim($postingNumber) === '') {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_order_export_links
        WHERE profile_id = ? AND marketplace = ? AND order_source = ? AND posting_number = ?
        LIMIT 1
    ");
    $st->execute([$profileId, $marketplace, $orderSource, $postingNumber]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['request'] = orders_sync_decode_json_row($row['request_json'] ?? null);
    $row['response'] = orders_sync_decode_json_row($row['response_json'] ?? null);
    return $row;
}

function orders_sync_export_link_get_for_scope(
    int $connectionId,
    int $moyskladAccountId,
    string $marketplace,
    string $orderSource,
    string $postingNumber,
    array $cfg = []
): ?array {
    orders_sync_exports_table_ensure($cfg);
    if ($connectionId <= 0 || trim($postingNumber) === '') {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_order_export_links
        WHERE connection_id = ?
          AND moysklad_account_id = ?
          AND marketplace = ?
          AND order_source = ?
          AND posting_number = ?
          AND COALESCE(moysklad_customerorder_id, '') <> ''
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$connectionId, $moyskladAccountId, $marketplace, $orderSource, $postingNumber]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['request'] = orders_sync_decode_json_row($row['request_json'] ?? null);
    $row['response'] = orders_sync_decode_json_row($row['response_json'] ?? null);
    return $row;
}

function orders_sync_export_link_key(string $orderSource, string $postingNumber): string
{
    return orders_sync_ozon_source_normalize($orderSource) . '|' . trim($postingNumber);
}

function orders_sync_export_links_map_for_scope(
    int $profileId,
    int $connectionId,
    int $moyskladAccountId,
    string $marketplace,
    array $rows,
    array $cfg = []
): array {
    orders_sync_exports_table_ensure($cfg);
    if ($profileId <= 0 && ($connectionId <= 0 || $moyskladAccountId <= 0)) {
        return [];
    }

    $pairs = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $orderSource = orders_sync_ozon_source_normalize((string)($row['order_source'] ?? ''));
        $postingNumber = trim((string)($row['posting_number'] ?? ''));
        if ($postingNumber === '') {
            continue;
        }
        $pairs[orders_sync_export_link_key($orderSource, $postingNumber)] = [
            'order_source' => $orderSource,
            'posting_number' => $postingNumber,
        ];
    }
    if (!$pairs) {
        return [];
    }

    $orderSources = array_values(array_unique(array_map(
        static fn(array $pair): string => (string)$pair['order_source'],
        array_values($pairs)
    )));
    $postingNumbers = array_values(array_unique(array_map(
        static fn(array $pair): string => (string)$pair['posting_number'],
        array_values($pairs)
    )));
    if (!$orderSources || !$postingNumbers) {
        return [];
    }

    $sourcePlaceholders = implode(',', array_fill(0, count($orderSources), '?'));
    $postingPlaceholders = implode(',', array_fill(0, count($postingNumbers), '?'));
    $sql = "
        SELECT *
        FROM feedtools_marketplace_order_export_links
        WHERE marketplace = ?
          AND order_source IN ({$sourcePlaceholders})
          AND posting_number IN ({$postingPlaceholders})
          AND (
                profile_id = ?
                OR (
                    connection_id = ?
                    AND moysklad_account_id = ?
                    AND COALESCE(moysklad_customerorder_id, '') <> ''
                )
          )
        ORDER BY updated_at DESC, id DESC
    ";
    $args = array_merge(
        [$marketplace],
        $orderSources,
        $postingNumbers,
        [$profileId, $connectionId, $moyskladAccountId]
    );
    $st = db()->prepare($sql);
    $st->execute($args);
    $selected = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = orders_sync_export_link_key((string)($row['order_source'] ?? ''), (string)($row['posting_number'] ?? ''));
        if (!isset($pairs[$key])) {
            continue;
        }
        $hasCustomerorderId = trim((string)($row['moysklad_customerorder_id'] ?? '')) !== '';
        $candidateRank = $hasCustomerorderId ? 0 : 10;
        if ((int)($row['profile_id'] ?? 0) === $profileId) {
            $candidateRank -= 1;
        }
        if (!isset($selected[$key])) {
            $selected[$key] = ['rank' => $candidateRank, 'row' => $row];
            continue;
        }
        $currentRank = (int)($selected[$key]['rank'] ?? 1);
        if ($candidateRank < $currentRank) {
            $selected[$key] = ['rank' => $candidateRank, 'row' => $row];
        }
    }

    $result = [];
    foreach ($selected as $key => $item) {
        $row = is_array($item['row'] ?? null) ? $item['row'] : null;
        if (!is_array($row)) {
            continue;
        }
        $row['request'] = orders_sync_decode_json_row($row['request_json'] ?? null);
        $row['response'] = orders_sync_decode_json_row($row['response_json'] ?? null);
        $result[$key] = $row;
    }
    return $result;
}

function orders_sync_export_link_upsert(array $row, array $cfg = []): void
{
    orders_sync_exports_table_ensure($cfg);
    $profileId = (int)($row['profile_id'] ?? 0);
    $connectionId = (int)($row['connection_id'] ?? 0);
    $moyskladAccountId = (int)($row['moysklad_account_id'] ?? 0);
    $marketplace = (string)($row['marketplace'] ?? 'ozon');
    $orderSource = (string)($row['order_source'] ?? 'fbs');
    $postingNumber = (string)($row['posting_number'] ?? '');
    $requestJson = json_encode($row['request'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $responseJson = json_encode($row['response'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($requestJson)) {
        $requestJson = '{}';
    }
    if (!is_string($responseJson)) {
        $responseJson = '{}';
    }

    if ($connectionId > 0 && $moyskladAccountId > 0 && trim($postingNumber) !== '') {
        $existingScope = db()->prepare("
            SELECT id
            FROM feedtools_marketplace_order_export_links
            WHERE connection_id = ?
              AND moysklad_account_id = ?
              AND marketplace = ?
              AND order_source = ?
              AND posting_number = ?
            ORDER BY
              CASE WHEN profile_id = ? THEN 0 ELSE 1 END ASC,
              CASE WHEN COALESCE(moysklad_customerorder_id, '') <> '' THEN 0 ELSE 1 END ASC,
              updated_at DESC,
              id DESC
            LIMIT 1
        ");
        $existingScope->execute([$connectionId, $moyskladAccountId, $marketplace, $orderSource, $postingNumber, $profileId]);
        $existingScopeId = (int)($existingScope->fetchColumn() ?: 0);
        if ($existingScopeId > 0) {
            $st = db()->prepare("
                UPDATE feedtools_marketplace_order_export_links
                SET profile_id = CASE WHEN profile_id = 0 THEN ? ELSE profile_id END,
                    connection_id = ?,
                    moysklad_account_id = ?,
                    marketplace = ?,
                    order_source = ?,
                    posting_number = ?,
                    order_number = ?,
                    external_order_id = ?,
                    moysklad_customerorder_id = ?,
                    moysklad_counterparty_id = ?,
                    export_mode = ?,
                    last_status = ?,
                    source_fingerprint = ?,
                    request_json = ?,
                    response_json = ?,
                    error_text = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                LIMIT 1
            ");
            $st->execute([
                $profileId,
                $connectionId,
                $moyskladAccountId,
                $marketplace,
                $orderSource,
                $postingNumber,
                (string)($row['order_number'] ?? ''),
                (string)($row['external_order_id'] ?? ''),
                (string)($row['moysklad_customerorder_id'] ?? ''),
                (string)($row['moysklad_counterparty_id'] ?? ''),
                (string)($row['export_mode'] ?? 'test'),
                (string)($row['last_status'] ?? ''),
                substr(trim((string)($row['source_fingerprint'] ?? '')), 0, 64),
                $requestJson,
                $responseJson,
                $row['error_text'] ?? null,
                $existingScopeId,
            ]);
            return;
        }
    }

    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_order_export_links (
            profile_id, connection_id, moysklad_account_id, marketplace, order_source,
            posting_number, order_number, external_order_id,
            moysklad_customerorder_id, moysklad_counterparty_id, export_mode, last_status, source_fingerprint,
            request_json, response_json, error_text
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            connection_id = VALUES(connection_id),
            moysklad_account_id = VALUES(moysklad_account_id),
            order_number = VALUES(order_number),
            external_order_id = VALUES(external_order_id),
            moysklad_customerorder_id = VALUES(moysklad_customerorder_id),
            moysklad_counterparty_id = VALUES(moysklad_counterparty_id),
            export_mode = VALUES(export_mode),
            last_status = VALUES(last_status),
            source_fingerprint = VALUES(source_fingerprint),
            request_json = VALUES(request_json),
            response_json = VALUES(response_json),
            error_text = VALUES(error_text),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        $profileId,
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $orderSource,
        $postingNumber,
        (string)($row['order_number'] ?? ''),
        (string)($row['external_order_id'] ?? ''),
        (string)($row['moysklad_customerorder_id'] ?? ''),
        (string)($row['moysklad_counterparty_id'] ?? ''),
        (string)($row['export_mode'] ?? 'test'),
        (string)($row['last_status'] ?? ''),
        substr(trim((string)($row['source_fingerprint'] ?? '')), 0, 64),
        $requestJson,
        $responseJson,
        $row['error_text'] ?? null,
    ]);
}

function orders_sync_fingerprint_normalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    if ($isList) {
        return array_map('orders_sync_fingerprint_normalize', $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
        $value[$key] = orders_sync_fingerprint_normalize($item);
    }
    return $value;
}

function orders_sync_profile_export_settings_fingerprint(array $profile, array $cfg = []): string
{
    static $cache = [];
    $profileId = (int)($profile['id'] ?? 0);
    if ($profileId > 0 && isset($cache[$profileId])) {
        return $cache[$profileId];
    }
    $storeMappings = [];
    foreach (orders_sync_profile_store_mapping_rows($profileId, $cfg) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $storeMappings[] = [
            'key' => trim((string)($row['ozon_warehouse_key'] ?? '')),
            'store_id' => trim((string)($row['moysklad_store_id'] ?? '')),
            'new_order_state_id' => trim((string)($row['moysklad_new_order_state_id'] ?? '')),
        ];
    }
    usort($storeMappings, static function (array $a, array $b): int {
        return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
    });

    $payload = [
        'organization_id' => trim((string)($profile['moysklad_organization_id'] ?? '')),
        'counterparty_id' => trim((string)($profile['moysklad_counterparty_id'] ?? '')),
        'project_id' => trim((string)($profile['moysklad_project_id'] ?? '')),
        'saleschannel_id' => trim((string)($profile['moysklad_saleschannel_id'] ?? '')),
        'default_store_id' => trim((string)($profile['moysklad_default_store_id'] ?? '')),
        'fbo_store_id' => trim((string)($profile['moysklad_fbo_store_id'] ?? '')),
        'fbo_new_order_state_id' => trim((string)($profile['moysklad_fbo_new_order_state_id'] ?? '')),
        'wb_dbw_store_id' => trim((string)($profile['moysklad_wb_dbw_store_id'] ?? '')),
        'wb_dbw_new_order_state_id' => trim((string)($profile['moysklad_wb_dbw_new_order_state_id'] ?? '')),
        'delivery_planned_source' => trim((string)($profile['moysklad_delivery_planned_source'] ?? '')),
        'cancelled_before_ship_zero_prices' => !empty($profile['cancelled_before_ship_zero_prices']) ? 1 : 0,
        'ozon_status_create_default_state_id' => trim((string)($profile['ozon_status_create_default_state_id'] ?? '')),
        'ozon_status_update_default_state_id' => trim((string)($profile['ozon_status_update_default_state_id'] ?? '')),
        'cancelled_transition_default_state_id' => trim((string)($profile['cancelled_transition_default_state_id'] ?? '')),
        'ozon_status_create_map' => orders_sync_fingerprint_normalize((array)($profile['ozon_status_create_map'] ?? [])),
        'ozon_status_update_map' => orders_sync_fingerprint_normalize((array)($profile['ozon_status_update_map'] ?? [])),
        'cancelled_transition_map' => orders_sync_fingerprint_normalize((array)($profile['cancelled_transition_map'] ?? [])),
        'store_mappings' => $storeMappings,
    ];

    $fingerprint = sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($profileId > 0) {
        $cache[$profileId] = $fingerprint;
    }
    return $fingerprint;
}

function orders_sync_export_source_fingerprint(array $profile, string $source, array $posting, array $cfg = []): string
{
    $source = orders_sync_ozon_source_normalize($source);
    $posting['_feedtools_source'] = $source;
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon'));
    $posting['_feedtools_marketplace'] = $marketplace;
    $effectiveStatus = orders_sync_marketplace_effective_status($cfg, $marketplace, $source, $posting);
    $posting['_feedtools_effective_status'] = $effectiveStatus;
    $warehouse = orders_sync_marketplace_logistics_warehouse_entry_from_posting($marketplace, $posting);
    $products = [];
    foreach ((array)($posting['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $moyskladProduct = orders_sync_moysklad_position_product($product);
        $products[] = [
            'offer_id' => trim((string)($product['offer_id'] ?? '')),
            'name' => trim((string)($product['name'] ?? '')),
            'quantity' => (string)($product['quantity'] ?? ''),
            'price' => (string)($product['price'] ?? ''),
            'moysklad_offer_id' => trim((string)($moyskladProduct['offer_id'] ?? '')),
            'moysklad_quantity' => (string)($moyskladProduct['quantity'] ?? ''),
            'moysklad_price' => (string)($moyskladProduct['price'] ?? ''),
            'bundle_qty' => (string)($moyskladProduct['_feedtools_bundle_qty'] ?? ''),
        ];
    }

    $payload = [
        'profile_settings_fingerprint' => orders_sync_profile_export_settings_fingerprint($profile, $cfg),
        'marketplace' => $marketplace,
        'source' => $source,
        'posting_number' => trim((string)($posting['posting_number'] ?? '')),
        'order_number' => trim((string)($posting['order_number'] ?? '')),
        'order_id' => trim((string)($posting['order_id'] ?? '')),
        'status' => trim((string)($posting['status'] ?? '')),
        'substatus' => trim((string)($posting['substatus'] ?? '')),
        'effective_status' => trim((string)($posting['_feedtools_effective_status'] ?? '')),
        'created_at' => trim((string)($posting['created_at'] ?? '')),
        'in_process_at' => trim((string)($posting['in_process_at'] ?? '')),
        'shipment_date' => trim((string)($posting['shipment_date'] ?? '')),
        'warehouse_key' => trim((string)($warehouse['key'] ?? '')),
        'warehouse_id' => trim((string)($warehouse['id'] ?? '')),
        'products' => $products,
    ];
    if (!empty($profile['cancelled_before_ship_zero_prices']) && orders_sync_marketplace_status_is_cancelled($marketplace, $effectiveStatus)) {
        $payload['cancelled_before_ship_policy'] = 'v2';
        $payload['zero_prices_applied'] = orders_sync_marketplace_is_simple_cancelled_before_ship($marketplace, $posting, $effectiveStatus) ? 1 : 0;
    }

    return sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function orders_sync_profile_status_update_settings_fingerprint(array $profile): string
{
    $payload = [
        'cancelled_before_ship_zero_prices' => !empty($profile['cancelled_before_ship_zero_prices']) ? 1 : 0,
        'ozon_status_update_default_state_id' => trim((string)($profile['ozon_status_update_default_state_id'] ?? '')),
        'cancelled_transition_default_state_id' => trim((string)($profile['cancelled_transition_default_state_id'] ?? '')),
        'ozon_status_update_map' => orders_sync_fingerprint_normalize((array)($profile['ozon_status_update_map'] ?? [])),
        'cancelled_transition_map' => orders_sync_fingerprint_normalize((array)($profile['cancelled_transition_map'] ?? [])),
    ];
    return sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function orders_sync_status_update_source_fingerprint(array $profile, string $source, array $posting, array $cfg = []): string
{
    $source = orders_sync_ozon_source_normalize($source);
    $posting['_feedtools_source'] = $source;
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon'));
    $posting['_feedtools_marketplace'] = $marketplace;
    $effectiveStatus = orders_sync_marketplace_effective_status($cfg, $marketplace, $source, $posting);
    $payload = [
        'profile_status_settings_fingerprint' => orders_sync_profile_status_update_settings_fingerprint($profile),
        'marketplace' => $marketplace,
        'source' => $source,
        'posting_number' => trim((string)($posting['posting_number'] ?? '')),
        'order_number' => trim((string)($posting['order_number'] ?? '')),
        'order_id' => trim((string)($posting['order_id'] ?? '')),
        'status' => trim((string)($posting['status'] ?? '')),
        'substatus' => trim((string)($posting['substatus'] ?? '')),
        'effective_status' => $effectiveStatus,
        'cancelled_after_ship' => !empty(((array)($posting['cancellation'] ?? []))['cancelled_after_ship']) ? 1 : 0,
    ];
    if (!empty($profile['cancelled_before_ship_zero_prices']) && orders_sync_marketplace_status_is_cancelled($marketplace, $effectiveStatus)) {
        $payload['cancelled_before_ship_policy'] = 'v2';
        $payload['zero_prices_applied'] = orders_sync_marketplace_is_simple_cancelled_before_ship($marketplace, $posting, $effectiveStatus) ? 1 : 0;
    }
    return sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function orders_sync_export_link_should_skip_unchanged(?array $link, string $sourceFingerprint, int $ttlSeconds = 0): bool
{
    if (!is_array($link)) {
        return false;
    }
    if (trim((string)($link['moysklad_customerorder_id'] ?? '')) === '') {
        return false;
    }
    if (trim((string)($link['error_text'] ?? '')) !== '') {
        return false;
    }
    if (trim((string)($link['source_fingerprint'] ?? '')) !== trim($sourceFingerprint)) {
        return false;
    }
    if ($ttlSeconds <= 0) {
        return true;
    }
    $updatedAt = trim((string)($link['updated_at'] ?? ''));
    if ($updatedAt === '') {
        return false;
    }
    try {
        $updated = new DateTimeImmutable($updatedAt, new DateTimeZone('UTC'));
        return (time() - $updated->getTimestamp()) <= max(60, $ttlSeconds);
    } catch (Throwable) {
        return false;
    }
}

function orders_sync_moysklad_account_default(): array
{
    return [
        'id' => 0,
        'title' => '',
        'base_url' => 'https://api.moysklad.ru/api/remap/1.2',
        'api_token' => '',
        'sort_order' => 100,
        'notes' => '',
    ];
}

function orders_sync_moysklad_account_input(array $input): array
{
    $row = orders_sync_moysklad_account_default();
    $row['id'] = (int)($input['id'] ?? 0);
    $row['title'] = trim((string)($input['title'] ?? ''));
    $row['base_url'] = trim((string)($input['base_url'] ?? '')) ?: 'https://api.moysklad.ru/api/remap/1.2';
    $row['api_token'] = trim((string)($input['api_token'] ?? ''));
    $row['sort_order'] = (int)($input['sort_order'] ?? 100);
    $row['notes'] = trim((string)($input['notes'] ?? ''));
    return $row;
}

function orders_sync_moysklad_base_url_normalize(string $baseUrl): string
{
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
        $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';
    }
    return rtrim($baseUrl, '/');
}

function orders_sync_moysklad_account_validate(array $row): void
{
    if ($row['title'] === '') {
        throw new RuntimeException('Укажи название аккаунта МойСклад.');
    }
    if ($row['api_token'] === '') {
        throw new RuntimeException('Укажи API token для аккаунта МойСклад.');
    }
}

function orders_sync_moysklad_account_check(array $input, array $cfg = []): array
{
    $row = orders_sync_moysklad_account_input($input);
    orders_sync_moysklad_account_validate($row);

    $payload = orders_sync_moysklad_request($row, 'GET', 'entity/counterparty', null, ['limit' => 1]);

    return [
        'ok' => true,
        'http_code' => 200,
        'counterparty_total' => (int)($payload['meta']['size'] ?? 0),
        'endpoint' => orders_sync_moysklad_base_url_normalize((string)$row['base_url']) . '/entity/counterparty?limit=1',
    ];
}

function orders_sync_moysklad_account_save(array $input, ?string $actor = null, array $cfg = []): int
{
    orders_sync_moysklad_accounts_table_ensure($cfg);

    $row = orders_sync_moysklad_account_input($input);
    orders_sync_moysklad_account_validate($row);

    $row['base_url'] = orders_sync_moysklad_base_url_normalize((string)$row['base_url']);
    $row['is_active'] = 1;

    if ($row['id'] > 0) {
        $st = db()->prepare("
            UPDATE feedtools_moysklad_accounts
            SET title = ?, base_url = ?, api_token = ?, is_active = ?, sort_order = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        $st->execute([
            $row['title'], $row['base_url'], $row['api_token'], $row['is_active'],
            $row['sort_order'], $row['notes'], $actor, $row['id'],
        ]);
        return $row['id'];
    }

    $st = db()->prepare("
        INSERT INTO feedtools_moysklad_accounts (
            title, base_url, api_token, is_active, sort_order, notes, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        $row['title'], $row['base_url'], $row['api_token'], $row['is_active'],
        $row['sort_order'], $row['notes'], $actor, $actor,
    ]);
    return (int)db()->lastInsertId();
}

function orders_sync_moysklad_account_list(array $cfg = []): array
{
    orders_sync_moysklad_accounts_table_ensure($cfg);
    $st = db()->query("
        SELECT *
        FROM feedtools_moysklad_accounts
        ORDER BY sort_order ASC, id ASC
    ");
    return $st->fetchAll() ?: [];
}

function orders_sync_moysklad_throttle_key(array $account): string
{
    $accountId = (int)($account['id'] ?? 0);
    if ($accountId > 0) {
        return 'account_' . $accountId;
    }
    return 'token_' . substr(sha1((string)($account['base_url'] ?? '') . '|' . (string)($account['api_token'] ?? '')), 0, 24);
}

function orders_sync_moysklad_throttle(array $account, int $minIntervalMs = 220): void
{
    $minIntervalMs = max(0, $minIntervalMs);
    if ($minIntervalMs <= 0) {
        return;
    }

    $dir = dirname(__DIR__) . '/storage/cache/moysklad_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $key = preg_replace('/[^a-zA-Z0-9_-]+/', '_', orders_sync_moysklad_throttle_key($account));
    $lockPath = $dir . '/' . $key . '.lock';
    $statePath = $dir . '/' . $key . '.state';
    $lock = @fopen($lockPath, 'c+');
    if (!is_resource($lock)) {
        return;
    }

    try {
        if (!@flock($lock, LOCK_EX)) {
            return;
        }

        $now = microtime(true);
        $lastRaw = is_file($statePath) ? trim((string)@file_get_contents($statePath)) : '';
        $lastAt = is_numeric($lastRaw) ? (float)$lastRaw : 0.0;
        $nextAt = $lastAt + ($minIntervalMs / 1000);
        if ($nextAt > $now) {
            $sleepUs = (int)round(($nextAt - $now) * 1000000);
            if ($sleepUs > 0) {
                usleep(min($sleepUs, 3000000));
            }
        }

        @file_put_contents($statePath, sprintf('%.6F', microtime(true)), LOCK_EX);
        @flock($lock, LOCK_UN);
    } finally {
        @fclose($lock);
    }
}

function orders_sync_moysklad_account_get(int $id, array $cfg = []): ?array
{
    orders_sync_moysklad_accounts_table_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_moysklad_accounts WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function orders_sync_ui_cache_dir(): string
{
    return dirname(__DIR__) . '/storage/cache/orders_sync';
}

function orders_sync_ui_cache_path(string $key): string
{
    return rtrim(orders_sync_ui_cache_dir(), '/\\') . '/' . sha1($key) . '.json';
}

function orders_sync_ui_cache_read(string $key, int $ttlSeconds, bool $allowStale = false): ?array
{
    $path = orders_sync_ui_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !array_key_exists('value', $decoded) || !is_array($decoded['value'])) {
        return null;
    }

    $savedAt = (int)($decoded['saved_at'] ?? 0);
    $isFresh = $savedAt > 0 && $savedAt >= (time() - $ttlSeconds);
    if (!$isFresh && !$allowStale) {
        return null;
    }

    return $decoded['value'];
}

function orders_sync_ui_cache_write(string $key, array $value): void
{
    $dir = orders_sync_ui_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $payload = [
        'saved_at' => time(),
        'value' => $value,
    ];
    @file_put_contents(
        orders_sync_ui_cache_path($key),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function orders_sync_moysklad_collection_fetch(array $account, string $path, array $query = []): array
{
    $query = array_merge(['limit' => 1000], $query);
    $cacheKey = 'moysklad:collection:' . sha1(json_encode([
        'account_id' => (int)($account['id'] ?? 0),
        'base_url' => orders_sync_moysklad_base_url_normalize((string)($account['base_url'] ?? '')),
        'path' => trim($path, '/'),
        'query' => $query,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $response = orders_sync_ui_cache_read($cacheKey, 300);
    if (!is_array($response)) {
        try {
            $response = orders_sync_moysklad_request($account, 'GET', $path, null, $query);
            orders_sync_ui_cache_write($cacheKey, $response);
        } catch (Throwable $e) {
            $response = orders_sync_ui_cache_read($cacheKey, 300, true);
            if (!is_array($response)) {
                throw $e;
            }
        }
    }
    return array_values(array_filter(
        is_array($response['rows'] ?? null) ? $response['rows'] : [],
        static fn($row): bool => is_array($row)
    ));
}

function orders_sync_moysklad_options_normalize(array $rows, string $labelField = 'name', bool $includeArchived = false): array
{
    $options = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $archived = !empty($row['archived']);
        if ($archived && !$includeArchived) {
            continue;
        }
        $label = trim((string)($row[$labelField] ?? ''));
        if ($label === '') {
            $label = $id;
        }
        if ($archived) {
            $label .= ' (архив)';
        }
        $options[] = [
            'id' => $id,
            'label' => $label,
            'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
            'raw' => $row,
        ];
    }

    usort($options, static function (array $a, array $b): int {
        return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    return $options;
}

function orders_sync_moysklad_organizations_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'org:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/organization')
    );
}

function orders_sync_moysklad_counterparties_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'counterparty:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/counterparty')
    );
}

function orders_sync_moysklad_productfolders_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'productfolder:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/productfolder')
    );
}

function orders_sync_moysklad_projects_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'project:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/project')
    );
}

function orders_sync_moysklad_saleschannels_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'saleschannel:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/saleschannel')
    );
}

function orders_sync_moysklad_stores_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'store:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_collection_fetch($account, 'entity/store')
    );
}

function orders_sync_moysklad_option_find_by_id(array $options, string $id): ?array
{
    $needle = trim($id);
    if ($needle === '') {
        return null;
    }
    static $indexCache = [];
    $cacheKey = sha1(json_encode(array_map(
        static fn(array $option): string => trim((string)($option['id'] ?? '')),
        array_values(array_filter($options, static fn($option): bool => is_array($option)))
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!isset($indexCache[$cacheKey])) {
        $index = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionId = trim((string)($option['id'] ?? ''));
            if ($optionId === '') {
                continue;
            }
            $index[$optionId] = $option;
        }
        $indexCache[$cacheKey] = $index;
    }
    return $indexCache[$cacheKey][$needle] ?? null;
}

function orders_sync_moysklad_option_raw_by_id(array $options, string $id): ?array
{
    $option = orders_sync_moysklad_option_find_by_id($options, $id);
    if (!is_array($option)) {
        return null;
    }
    $raw = is_array($option['raw'] ?? null) ? $option['raw'] : [];
    $raw['id'] = trim((string)($raw['id'] ?? $option['id'] ?? ''));
    if (trim((string)($raw['name'] ?? '')) === '') {
        $raw['name'] = trim((string)($option['label'] ?? ''));
    }
    if (!is_array($raw['meta'] ?? null) && is_array($option['meta'] ?? null)) {
        $raw['meta'] = $option['meta'];
    }
    return trim((string)($raw['id'] ?? '')) !== '' ? $raw : null;
}

function orders_sync_moysklad_name_key(string $name): string
{
    $name = preg_replace('~\s+~u', ' ', trim($name)) ?? trim($name);
    return mb_strtolower($name, 'UTF-8');
}

function orders_sync_moysklad_option_raw_by_name(array $options, string $name): ?array
{
    $needle = orders_sync_moysklad_name_key($name);
    if ($needle === '') {
        return null;
    }
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $label = trim((string)($option['label'] ?? ''));
        $raw = is_array($option['raw'] ?? null) ? $option['raw'] : [];
        $rawName = trim((string)($raw['name'] ?? $label));
        if (orders_sync_moysklad_name_key($rawName) !== $needle && orders_sync_moysklad_name_key($label) !== $needle) {
            continue;
        }
        $raw['id'] = trim((string)($raw['id'] ?? $option['id'] ?? ''));
        if (trim((string)($raw['name'] ?? '')) === '') {
            $raw['name'] = $rawName !== '' ? $rawName : $label;
        }
        if (!is_array($raw['meta'] ?? null) && is_array($option['meta'] ?? null)) {
            $raw['meta'] = $option['meta'];
        }
        return trim((string)($raw['id'] ?? '')) !== '' ? $raw : null;
    }
    return null;
}

function orders_sync_profile_date_normalize($value): string
{
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    return $value;
}

function orders_sync_profile_sync_period(array $profile): array
{
    $dateFrom = orders_sync_profile_date_normalize($profile['sync_date_from'] ?? '');
    $dateTo = orders_sync_profile_date_normalize($profile['sync_date_to'] ?? '');

    if ($dateFrom === '' || $dateTo === '') {
        $lookbackDays = max(1, min(90, (int)($profile['lookback_days'] ?? 14)));
        $tz = new DateTimeZone('Europe/Moscow');
        $to = new DateTimeImmutable('today', $tz);
        $from = $to->sub(new DateInterval('P' . max(0, $lookbackDays - 1) . 'D'));
        $dateFrom = $from->format('Y-m-d');
        $dateTo = $to->format('Y-m-d');
    }

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    $tz = new DateTimeZone('Europe/Moscow');
    $fromStart = new DateTimeImmutable($dateFrom . ' 00:00:00', $tz);
    $toEnd = new DateTimeImmutable($dateTo . ' 23:59:59', $tz);

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'since_utc' => $fromStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'to_utc' => $toEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
    ];
}

function orders_sync_profile_default(): array
{
    $defaultPeriod = orders_sync_profile_sync_period(['lookback_days' => 14]);
    return [
        'id' => 0,
        'marketplace' => 'ozon',
        'title' => '',
        'connection_id' => 0,
        'moysklad_account_id' => 0,
        'moysklad_organization_id' => '',
        'moysklad_counterparty_id' => '',
        'moysklad_project_id' => '',
        'moysklad_saleschannel_id' => '',
        'moysklad_delivery_planned_source' => 'order_created_at',
        'moysklad_default_store_id' => '',
        'moysklad_fbo_store_id' => '',
        'moysklad_fbo_new_order_state_id' => '',
        'moysklad_wb_dbw_store_id' => '',
        'moysklad_wb_dbw_new_order_state_id' => '',
        'sync_date_from' => (string)$defaultPeriod['date_from'],
        'sync_date_to' => (string)$defaultPeriod['date_to'],
        'ozon_status_create_default_state_id' => '',
        'ozon_status_update_default_state_id' => orders_sync_status_update_keep_token(),
        'ozon_status_create_map_json' => '{}',
        'ozon_status_update_map_json' => '{}',
        'cancelled_transition_default_state_id' => '',
        'cancelled_before_ship_zero_prices' => 0,
        'cancelled_transition_map_json' => '{}',
        'ozon_status_create_map' => [],
        'ozon_status_update_map' => [],
        'cancelled_transition_map' => [],
        'lookback_days' => 14,
        'order_sources_json' => json_encode(['fbs', 'fbo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'order_sources' => ['fbs', 'fbo'],
        'is_active' => 1,
        'sort_order' => 100,
        'notes' => '',
    ];
}

function orders_sync_status_update_keep_token(): string
{
    return '__keep__';
}

function orders_sync_status_create_new_order_token(): string
{
    return '__new_order__';
}

function orders_sync_profile_status_map_normalize($raw, array $allowedSpecialTokens = []): array
{
    $values = [];
    if (is_array($raw)) {
        $values = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $values = $decoded;
        }
    }

    $result = [];
    foreach ($values as $status => $value) {
        $status = strtolower(trim((string)$status));
        $value = trim((string)$value);
        if ($status === '' || $value === '') {
            continue;
        }
        if (in_array($value, $allowedSpecialTokens, true)) {
            $result[$status] = $value;
            continue;
        }
        $result[$status] = $value;
    }
    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function orders_sync_cancelled_transition_map_normalize($raw, array $allowedSpecialTokens = []): array
{
    $values = [];
    if (is_array($raw)) {
        $values = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $values = $decoded;
        }
    }

    $result = [];
    foreach ($values as $sourceStateId => $targetStateId) {
        $sourceStateId = trim((string)$sourceStateId);
        $targetStateId = trim((string)$targetStateId);
        if ($sourceStateId === '' || $targetStateId === '') {
            continue;
        }
        if (in_array($targetStateId, $allowedSpecialTokens, true)) {
            $result[$sourceStateId] = $targetStateId;
            continue;
        }
        $result[$sourceStateId] = $targetStateId;
    }
    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function orders_sync_profile_status_maps_from_input(array $input): array
{
    $statuses = is_array($input['status_mapping_statuses'] ?? null) ? $input['status_mapping_statuses'] : [];
    $createValues = is_array($input['status_mapping_create_state_ids'] ?? null) ? $input['status_mapping_create_state_ids'] : [];
    $updateValues = is_array($input['status_mapping_update_state_ids'] ?? null) ? $input['status_mapping_update_state_ids'] : [];

    $createMap = [];
    $updateMap = [];
    foreach ($statuses as $idx => $statusRaw) {
        $status = strtolower(trim((string)$statusRaw));
        if ($status === '') {
            continue;
        }
        $createValue = trim((string)($createValues[$idx] ?? ''));
        $updateValue = trim((string)($updateValues[$idx] ?? ''));
        if ($createValue !== '') {
            $createMap[$status] = $createValue;
        }
        if ($updateValue !== '') {
            $updateMap[$status] = $updateValue;
        }
    }

    return [
        'create' => orders_sync_profile_status_map_normalize($createMap, [orders_sync_status_create_new_order_token()]),
        'update' => orders_sync_profile_status_map_normalize($updateMap, [orders_sync_status_update_keep_token()]),
    ];
}

function orders_sync_cancelled_transition_map_from_input(array $input): array
{
    $sourceStateIds = is_array($input['cancel_transition_source_state_ids'] ?? null) ? $input['cancel_transition_source_state_ids'] : [];
    $targetStateIds = is_array($input['cancel_transition_target_state_ids'] ?? null) ? $input['cancel_transition_target_state_ids'] : [];
    $map = [];
    foreach ($sourceStateIds as $idx => $sourceStateIdRaw) {
        $sourceStateId = trim((string)$sourceStateIdRaw);
        $targetStateId = trim((string)($targetStateIds[$idx] ?? ''));
        if ($sourceStateId === '' || $targetStateId === '') {
            continue;
        }
        $map[$sourceStateId] = $targetStateId;
    }
    return orders_sync_cancelled_transition_map_normalize($map, [orders_sync_status_update_keep_token()]);
}

function orders_sync_profile_normalize_order_sources($raw): array
{
    return orders_sync_marketplace_normalize_order_sources($raw, 'ozon');
}

function orders_sync_marketplace_normalize(string $marketplace): string
{
    $marketplace = strtolower(trim($marketplace));
    return in_array($marketplace, ['ozon', 'wb', 'yandex_market'], true) ? $marketplace : 'ozon';
}

function orders_sync_marketplace_from_profile(array $profile): string
{
    return orders_sync_marketplace_normalize((string)($profile['marketplace'] ?? 'ozon'));
}

function orders_sync_marketplace_order_source_options(string $marketplace): array
{
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    if ($marketplace === 'wb') {
        return [
            'fbs' => ['label' => 'FBS'],
            'dbw' => ['label' => 'DBW / склад WB'],
            'dbs' => ['label' => 'DBS'],
        ];
    }
    if ($marketplace === 'yandex_market') {
        return [
            'fby' => ['label' => 'FBY'],
            'fbs' => ['label' => 'FBS'],
            'dbs' => ['label' => 'DBS'],
            'express' => ['label' => 'Express'],
            'laas' => ['label' => 'LaaS'],
        ];
    }
    return [
        'fbs' => ['label' => 'FBS'],
        'fbo' => ['label' => 'FBO'],
    ];
}

function orders_sync_wb_warehouse_source_is_dbw(string $source): bool
{
    return orders_sync_ozon_source_normalize($source) === 'dbw';
}

function orders_sync_marketplace_normalize_order_sources($raw, string $marketplace): array
{
    $allowed = array_keys(orders_sync_marketplace_order_source_options($marketplace));
    $values = [];
    if (is_array($raw)) {
        $values = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : (preg_split('~[\s,;]+~', trim($raw)) ?: []);
    }

    $result = [];
    foreach ($values as $value) {
        $source = strtolower(trim((string)$value));
        if ($source !== '' && in_array($source, $allowed, true)) {
            $result[$source] = true;
        }
    }
    return array_keys($result ?: array_fill_keys($allowed ?: ['fbs'], true));
}

function orders_sync_profile_hydrate(array $row): array
{
    $row += orders_sync_profile_default();
    $row['sync_date_from'] = orders_sync_profile_date_normalize($row['sync_date_from'] ?? '');
    $row['sync_date_to'] = orders_sync_profile_date_normalize($row['sync_date_to'] ?? '');
    if ($row['sync_date_from'] === '' || $row['sync_date_to'] === '') {
        $period = orders_sync_profile_sync_period($row);
        $row['sync_date_from'] = (string)$period['date_from'];
    $row['sync_date_to'] = (string)$period['date_to'];
    }
    $marketplace = orders_sync_marketplace_from_profile($row);
    $row['order_sources'] = orders_sync_marketplace_normalize_order_sources($row['order_sources_json'] ?? null, $marketplace);
    $row['ozon_status_create_map'] = orders_sync_profile_status_map_normalize($row['ozon_status_create_map_json'] ?? null, [orders_sync_status_create_new_order_token()]);
    $row['ozon_status_update_map'] = orders_sync_profile_status_map_normalize($row['ozon_status_update_map_json'] ?? null, [orders_sync_status_update_keep_token()]);
    $row['cancelled_transition_map'] = orders_sync_cancelled_transition_map_normalize($row['cancelled_transition_map_json'] ?? null, [orders_sync_status_update_keep_token()]);
    return $row;
}

function orders_sync_moysklad_operation_options(): array
{
    return [
        'full' => ['label' => 'Полная выгрузка'],
        'create_only' => ['label' => 'Создать новые'],
        'status_only' => ['label' => 'Обновить статусы'],
    ];
}

function orders_sync_automation_frequency_options(): array
{
    return [
        '5min' => ['label' => 'Каждые 5 минут', 'interval_minutes' => 5],
        '15min' => ['label' => 'Каждые 15 минут', 'interval_minutes' => 15],
        '20min' => ['label' => 'Каждые 20 минут', 'interval_minutes' => 20],
        '30min' => ['label' => 'Каждые 30 минут', 'interval_minutes' => 30],
        'hourly' => ['label' => 'Каждый час', 'interval_minutes' => 60],
        '4hour' => ['label' => 'Каждые 4 часа', 'interval_minutes' => 240],
        '8hour' => ['label' => 'Каждые 8 часов', 'interval_minutes' => 480],
        '12hour' => ['label' => '2 раза в сутки', 'interval_minutes' => 720],
        'daily' => ['label' => 'Раз в сутки', 'interval_minutes' => 1440],
    ];
}

function orders_sync_automation_frequency_normalize(string $value): string
{
    $value = trim($value);
    $options = orders_sync_automation_frequency_options();
    return isset($options[$value]) ? $value : 'hourly';
}

function orders_sync_automation_frequency_label(string $value): string
{
    $value = orders_sync_automation_frequency_normalize($value);
    $options = orders_sync_automation_frequency_options();
    return (string)($options[$value]['label'] ?? 'Каждый час');
}

function orders_sync_automation_default(int $profileId = 0, array $profile = []): array
{
    $marketplace = orders_sync_marketplace_from_profile($profile ?: ['marketplace' => 'ozon']);
    $profileSources = orders_sync_marketplace_normalize_order_sources($profile['order_sources'] ?? $profile['order_sources_json'] ?? null, $marketplace);
    return [
        'id' => 0,
        'profile_id' => max(0, $profileId),
        'operation_key' => 'create_only',
        'period_days' => 1,
        'frequency_key' => 'hourly',
        'run_hour_msk' => 0,
        'run_minute_msk' => 0,
        'order_sources_json' => json_encode($profileSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'order_sources' => $profileSources,
        'enabled' => 0,
        'last_run_at' => null,
        'last_run_slot_key' => null,
        'last_run_run_id' => null,
    ];
}

function orders_sync_automation_hydrate(array $row, array $profile = []): array
{
    $marketplace = orders_sync_marketplace_from_profile($profile ?: ['marketplace' => 'ozon']);
    $row += orders_sync_automation_default((int)($row['profile_id'] ?? 0), $profile);
    $row['operation_key'] = orders_sync_moysklad_operation_normalize((string)($row['operation_key'] ?? 'create_only'));
    $row['period_days'] = max(1, min(90, (int)($row['period_days'] ?? 1)));
    $row['frequency_key'] = orders_sync_automation_frequency_normalize((string)($row['frequency_key'] ?? 'hourly'));
    $row['run_hour_msk'] = max(0, min(23, (int)($row['run_hour_msk'] ?? 0)));
    $row['run_minute_msk'] = max(0, min(59, (int)($row['run_minute_msk'] ?? 0)));
    $row['order_sources'] = orders_sync_marketplace_normalize_order_sources($row['order_sources_json'] ?? null, $marketplace);
    $row['enabled'] = !empty($row['enabled']) ? 1 : 0;
    return $row;
}

function orders_sync_automation_input(array $input, array $profile = []): array
{
    $marketplace = orders_sync_marketplace_from_profile($profile ?: ['marketplace' => 'ozon']);
    $row = orders_sync_automation_default((int)($input['profile_id'] ?? 0), $profile);
    $row['id'] = (int)($input['automation_id'] ?? $input['id'] ?? 0);
    $row['profile_id'] = (int)($input['profile_id'] ?? 0);
    $row['operation_key'] = orders_sync_moysklad_operation_normalize((string)($input['operation_key'] ?? 'create_only'));
    $row['period_days'] = max(1, min(90, (int)($input['period_days'] ?? 1)));
    $row['frequency_key'] = orders_sync_automation_frequency_normalize((string)($input['frequency_key'] ?? 'hourly'));
    $runTime = trim((string)($input['run_time_msk'] ?? '00:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $runTime)) {
        $runTime = '00:00';
    }
    [$hour, $minute] = array_map('intval', explode(':', $runTime));
    $row['run_hour_msk'] = max(0, min(23, $hour));
    $row['run_minute_msk'] = max(0, min(59, $minute));
    $row['order_sources'] = orders_sync_marketplace_normalize_order_sources($input['order_sources'] ?? [], $marketplace);
    $row['order_sources_json'] = json_encode($row['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($row['order_sources_json'])) {
        $fallbackSources = array_keys(orders_sync_marketplace_order_source_options($marketplace));
        $row['order_sources_json'] = json_encode($fallbackSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["fbs"]';
    }
    $row['enabled'] = !empty($input['enabled']) ? 1 : 0;
    return $row;
}

function orders_sync_automation_validate(array $row, array $profile, array $cfg = []): void
{
    orders_sync_automation_table_ensure($cfg);
    if ((int)($row['profile_id'] ?? 0) <= 0) {
        throw new RuntimeException('Не удалось определить профиль для автоматизации.');
    }
    if ((int)($profile['id'] ?? 0) !== (int)($row['profile_id'] ?? 0)) {
        throw new RuntimeException('Автоматизация должна принадлежать выбранному профилю.');
    }
    $operations = orders_sync_moysklad_operation_options();
    if (!isset($operations[(string)($row['operation_key'] ?? '')])) {
        throw new RuntimeException('Выбрана неизвестная операция автоматизации.');
    }
    if ((int)($row['period_days'] ?? 0) <= 0) {
        throw new RuntimeException('Укажи период заказов для автоматизации.');
    }
    if (!$row['order_sources']) {
        throw new RuntimeException('Для автоматизации выбери хотя бы один источник заказов.');
    }
}

function orders_sync_automation_list(int $profileId, array $profile = [], array $cfg = []): array
{
    orders_sync_automation_table_ensure($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_sync_profile_automations
        WHERE profile_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$profileId]);
    $rows = $st->fetchAll() ?: [];
    return array_map(static fn(array $row): array => orders_sync_automation_hydrate($row, $profile), $rows);
}

function orders_sync_automation_map(array $profileIds, array $profilesById = [], array $cfg = []): array
{
    orders_sync_automation_table_ensure($cfg);
    $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), static fn(int $id): bool => $id > 0)));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_sync_profile_automations
        WHERE profile_id IN ({$placeholders})
        ORDER BY profile_id ASC, id ASC
    ");
    $st->execute($profileIds);
    $rows = $st->fetchAll() ?: [];
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $profileId = (int)($row['profile_id'] ?? 0);
        $result[$profileId][] = orders_sync_automation_hydrate($row, $profilesById[$profileId] ?? []);
    }
    return $result;
}

function orders_sync_automation_get(int $automationId, array $profile = [], array $cfg = []): ?array
{
    orders_sync_automation_table_ensure($cfg);
    if ($automationId <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_sync_profile_automations
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$automationId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    if (!$profile) {
        $loadedProfile = orders_sync_profile_get((int)($row['profile_id'] ?? 0), $cfg);
        if (is_array($loadedProfile)) {
            $profile = $loadedProfile;
        }
    }
    return orders_sync_automation_hydrate($row, $profile);
}

function orders_sync_automation_save(array $input, array $profile, ?string $actor = null, array $cfg = []): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_automation_table_ensure($cfg);
    $row = orders_sync_automation_input($input, $profile);
    orders_sync_automation_validate($row, $profile, $cfg);

    if ($row['id'] > 0) {
        $st = db()->prepare("
            UPDATE feedtools_marketplace_sync_profile_automations
            SET operation_key = ?, period_days = ?, frequency_key = ?, run_hour_msk = ?, run_minute_msk = ?,
                order_sources_json = ?, enabled = ?, updated_by = ?
            WHERE id = ? AND profile_id = ?
        ");
        $st->execute([
            $row['operation_key'],
            $row['period_days'],
            $row['frequency_key'],
            $row['run_hour_msk'],
            $row['run_minute_msk'],
            $row['order_sources_json'],
            $row['enabled'],
            $actor,
            $row['id'],
            $row['profile_id'],
        ]);
        return $row['id'];
    }

    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_sync_profile_automations (
            profile_id, operation_key, period_days, frequency_key, run_hour_msk, run_minute_msk,
            order_sources_json, enabled, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        $row['profile_id'],
        $row['operation_key'],
        $row['period_days'],
        $row['frequency_key'],
        $row['run_hour_msk'],
        $row['run_minute_msk'],
        $row['order_sources_json'],
        $row['enabled'],
        $actor,
        $actor,
    ]);
    return (int)db()->lastInsertId();
}

function orders_sync_automation_delete(int $automationId, int $profileId, array $cfg = []): void
{
    orders_sync_automation_table_ensure($cfg);
    if ($automationId <= 0 || $profileId <= 0) {
        throw new RuntimeException('Автоматизация не найдена.');
    }
    $st = db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_automations WHERE id = ? AND profile_id = ? LIMIT 1");
    $st->execute([$automationId, $profileId]);
}

function orders_sync_automation_clone_profile_rows(int $sourceProfileId, int $targetProfileId, ?string $actor = null, array $cfg = []): void
{
    orders_sync_automation_table_ensure($cfg);
    if ($sourceProfileId <= 0 || $targetProfileId <= 0) {
        return;
    }
    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_sync_profile_automations (
            profile_id, operation_key, period_days, frequency_key, run_hour_msk, run_minute_msk,
            order_sources_json, enabled, created_by, updated_by
        )
        SELECT ?, operation_key, period_days, frequency_key, run_hour_msk, run_minute_msk,
               order_sources_json, enabled, ?, ?
        FROM feedtools_marketplace_sync_profile_automations
        WHERE profile_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$targetProfileId, $actor, $actor, $sourceProfileId]);
}

function orders_sync_automation_run_time_value(array $automation): string
{
    return sprintf('%02d:%02d', max(0, min(23, (int)($automation['run_hour_msk'] ?? 0))), max(0, min(59, (int)($automation['run_minute_msk'] ?? 0))));
}

function orders_sync_automation_slot_info(array $automation, DateTimeImmutable $nowMsk): array
{
    $frequencyKey = orders_sync_automation_frequency_normalize((string)($automation['frequency_key'] ?? 'hourly'));
    $options = orders_sync_automation_frequency_options();
    $intervalMinutes = max(5, (int)($options[$frequencyKey]['interval_minutes'] ?? 60));
    $runHour = max(0, min(23, (int)($automation['run_hour_msk'] ?? 0)));
    $runMinute = max(0, min(59, (int)($automation['run_minute_msk'] ?? 0)));
    $anchor = new DateTimeImmutable('2000-01-01 ' . sprintf('%02d:%02d:00', $runHour, $runMinute), new DateTimeZone('Europe/Moscow'));
    $diffSeconds = $nowMsk->getTimestamp() - $anchor->getTimestamp();
    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }
    $intervalSeconds = max(300, $intervalMinutes * 60);
    $slotIndex = (int)floor($diffSeconds / $intervalSeconds);
    $slotStart = $anchor->modify('+' . ($slotIndex * $intervalMinutes) . ' minutes');
    $slotEnd = $slotStart->modify('+' . $intervalMinutes . ' minutes');
    return [
        'frequency_key' => $frequencyKey,
        'interval_minutes' => $intervalMinutes,
        'slot_index' => $slotIndex,
        'slot_key' => $frequencyKey . ':' . $slotStart->format('Y-m-d H:i'),
        'slot_start' => $slotStart,
        'slot_end' => $slotEnd,
    ];
}

function orders_sync_automation_runtime_overrides(array $profile, array $automation): array
{
    $periodDays = max(1, min(90, (int)($automation['period_days'] ?? 1)));
    $tz = new DateTimeZone('Europe/Moscow');
    $dateTo = new DateTimeImmutable('today', $tz);
    $dateFrom = $dateTo->sub(new DateInterval('P' . max(0, $periodDays - 1) . 'D'));
    $rawOrderSources = array_key_exists('order_sources_json', $automation) && trim((string)$automation['order_sources_json']) !== ''
        ? $automation['order_sources_json']
        : ($automation['order_sources'] ?? $profile['order_sources'] ?? $profile['order_sources_json'] ?? []);
    return [
        'automation_id' => (int)($automation['id'] ?? 0),
        'operation_key' => orders_sync_moysklad_operation_normalize((string)($automation['operation_key'] ?? 'full')),
        'period_days' => $periodDays,
        'sync_date_from' => $dateFrom->format('Y-m-d'),
        'sync_date_to' => $dateTo->format('Y-m-d'),
        'order_sources' => orders_sync_marketplace_normalize_order_sources(
            $rawOrderSources,
            orders_sync_marketplace_from_profile($profile)
        ),
        'frequency_key' => orders_sync_automation_frequency_normalize((string)($automation['frequency_key'] ?? 'hourly')),
        'run_hour_msk' => max(0, min(23, (int)($automation['run_hour_msk'] ?? 0))),
        'run_minute_msk' => max(0, min(59, (int)($automation['run_minute_msk'] ?? 0))),
    ];
}

function orders_sync_profile_input(array $input): array
{
    $row = orders_sync_profile_default();
    $row['id'] = (int)($input['id'] ?? 0);
    $row['marketplace'] = strtolower(trim((string)($input['marketplace'] ?? 'ozon')));
    $row['title'] = trim((string)($input['title'] ?? ''));
    $row['connection_id'] = (int)($input['connection_id'] ?? 0);
    $row['moysklad_account_id'] = (int)($input['moysklad_account_id'] ?? 0);
    $row['moysklad_organization_id'] = trim((string)($input['moysklad_organization_id'] ?? ''));
    $row['moysklad_counterparty_id'] = trim((string)($input['moysklad_counterparty_id'] ?? ''));
    $row['moysklad_project_id'] = trim((string)($input['moysklad_project_id'] ?? ''));
    $row['moysklad_saleschannel_id'] = trim((string)($input['moysklad_saleschannel_id'] ?? ''));
    $row['moysklad_delivery_planned_source'] = trim((string)($input['moysklad_delivery_planned_source'] ?? 'order_created_at'));
    $row['moysklad_default_store_id'] = trim((string)($input['moysklad_default_store_id'] ?? ''));
    $row['moysklad_fbo_store_id'] = trim((string)($input['moysklad_fbo_store_id'] ?? ''));
    $row['moysklad_fbo_new_order_state_id'] = trim((string)($input['moysklad_fbo_new_order_state_id'] ?? ''));
    $row['moysklad_wb_dbw_store_id'] = trim((string)($input['moysklad_wb_dbw_store_id'] ?? ''));
    $row['moysklad_wb_dbw_new_order_state_id'] = trim((string)($input['moysklad_wb_dbw_new_order_state_id'] ?? ''));
    $row['sync_date_from'] = orders_sync_profile_date_normalize($input['sync_date_from'] ?? '');
    $row['sync_date_to'] = orders_sync_profile_date_normalize($input['sync_date_to'] ?? '');
    $row['ozon_status_create_default_state_id'] = trim((string)($input['ozon_status_create_default_state_id'] ?? ''));
    $row['ozon_status_update_default_state_id'] = trim((string)($input['ozon_status_update_default_state_id'] ?? orders_sync_status_update_keep_token()));
    $row['cancelled_transition_default_state_id'] = trim((string)($input['cancelled_transition_default_state_id'] ?? ''));
    $row['cancelled_before_ship_zero_prices'] = !empty($input['cancelled_before_ship_zero_prices']) ? 1 : 0;
    $statusMaps = orders_sync_profile_status_maps_from_input($input);
    $row['ozon_status_create_map'] = $statusMaps['create'];
    $row['ozon_status_update_map'] = $statusMaps['update'];
    $row['cancelled_transition_map'] = orders_sync_cancelled_transition_map_from_input($input);
    $row['ozon_status_create_map_json'] = json_encode($row['ozon_status_create_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $row['ozon_status_update_map_json'] = json_encode($row['ozon_status_update_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $row['cancelled_transition_map_json'] = json_encode($row['cancelled_transition_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($row['ozon_status_create_map_json'])) {
        $row['ozon_status_create_map_json'] = '{}';
    }
    if (!is_string($row['ozon_status_update_map_json'])) {
        $row['ozon_status_update_map_json'] = '{}';
    }
    if (!is_string($row['cancelled_transition_map_json'])) {
        $row['cancelled_transition_map_json'] = '{}';
    }
    $row['lookback_days'] = max(1, min(90, (int)($input['lookback_days'] ?? 14)));
    $row['order_sources'] = orders_sync_marketplace_normalize_order_sources($input['order_sources'] ?? [], $row['marketplace']);
    $row['order_sources_json'] = json_encode($row['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $row['is_active'] = !empty($input['is_active']) ? 1 : 0;
    $row['sort_order'] = (int)($input['sort_order'] ?? 100);
    $row['notes'] = trim((string)($input['notes'] ?? ''));
    return $row;
}

function orders_sync_profile_save(array $input, ?string $actor = null, array $cfg = []): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    $row = orders_sync_profile_input($input);

    $row['marketplace'] = orders_sync_marketplace_normalize((string)($row['marketplace'] ?? 'ozon'));
    if (!in_array($row['moysklad_delivery_planned_source'], ['order_created_at', 'ozon_shipment_date', 'yandex_shipment_date'], true)) {
        $row['moysklad_delivery_planned_source'] = 'order_created_at';
    }
    if ($row['title'] === '') {
        throw new RuntimeException('Укажи название профиля синхронизации.');
    }
    if ($row['sync_date_from'] === '' || $row['sync_date_to'] === '') {
        throw new RuntimeException('Выбери даты периода загрузки заказов.');
    }
    if ($row['sync_date_from'] > $row['sync_date_to']) {
        throw new RuntimeException('Дата начала периода не может быть позже даты окончания.');
    }
    if ($row['connection_id'] <= 0) {
        throw new RuntimeException('Выбери аккаунт маркетплейса для профиля синхронизации.');
    }

    $connection = ozon_price_connection_get($row['connection_id'], $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Аккаунт маркетплейса не найден.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $row['marketplace']) {
        throw new RuntimeException('Профиль синхронизации должен ссылаться на аккаунт того же маркетплейса.');
    }
    $row['order_sources'] = orders_sync_marketplace_normalize_order_sources($row['order_sources'], $row['marketplace']);
    $row['order_sources_json'] = json_encode($row['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["fbs"]';
    if ($row['marketplace'] !== 'ozon' && $row['moysklad_delivery_planned_source'] === 'ozon_shipment_date') {
        $row['moysklad_delivery_planned_source'] = 'order_created_at';
    }
    if ($row['marketplace'] !== 'yandex_market' && $row['moysklad_delivery_planned_source'] === 'yandex_shipment_date') {
        $row['moysklad_delivery_planned_source'] = 'order_created_at';
    }
    $moyskladAccount = null;
    if ($row['moysklad_account_id'] > 0) {
        $moyskladAccount = orders_sync_moysklad_account_get($row['moysklad_account_id'], $cfg);
        if (!$moyskladAccount) {
            throw new RuntimeException('Выбранный аккаунт МойСклад не найден.');
        }
        if ($row['moysklad_organization_id'] === '') {
            throw new RuntimeException('Выбери организацию МойСклад для профиля синхронизации.');
        }
        if ($row['moysklad_counterparty_id'] === '') {
            throw new RuntimeException('Выбери контрагента МойСклад для профиля синхронизации.');
        }
        if ($row['moysklad_default_store_id'] === '') {
            throw new RuntimeException('Выбери склад МойСклад по умолчанию для профиля синхронизации.');
        }
    }

    if (is_array($moyskladAccount)) {
        $mapChecks = [
            'moysklad_organization_id' => [orders_sync_moysklad_organizations_options($moyskladAccount), 'Выбранная организация МойСклад не найдена.'],
            'moysklad_counterparty_id' => [orders_sync_moysklad_counterparties_options($moyskladAccount), 'Выбранный контрагент МойСклад не найден.'],
            'moysklad_project_id' => [orders_sync_moysklad_projects_options($moyskladAccount), 'Выбранный проект МойСклад не найден.'],
            'moysklad_saleschannel_id' => [orders_sync_moysklad_saleschannels_options($moyskladAccount), 'Выбранный канал продаж МойСклад не найден.'],
            'moysklad_default_store_id' => [orders_sync_moysklad_stores_options($moyskladAccount), 'Выбранный склад МойСклад по умолчанию не найден.'],
            'moysklad_fbo_store_id' => [orders_sync_moysklad_stores_options($moyskladAccount), 'Выбранный склад МойСклад для FBO не найден.'],
            'moysklad_wb_dbw_store_id' => [orders_sync_moysklad_stores_options($moyskladAccount), 'Выбранный склад МойСклад для DBW WB не найден.'],
        ];
        foreach ($mapChecks as $field => [$options, $message]) {
            if ($row[$field] !== '' && !orders_sync_moysklad_option_find_by_id($options, $row[$field])) {
                throw new RuntimeException($message);
            }
        }
        $stateOptions = orders_sync_moysklad_customerorder_state_options($moyskladAccount);
        foreach ($row['ozon_status_create_map'] as $stateId) {
            if ($stateId === orders_sync_status_create_new_order_token()) {
                continue;
            }
            if ($stateId !== '' && !orders_sync_moysklad_option_find_by_id($stateOptions, $stateId)) {
                throw new RuntimeException('Выбранный статус МойСклад для новых заказов не найден.');
            }
        }
        if (
            $row['ozon_status_create_default_state_id'] !== ''
            && $row['ozon_status_create_default_state_id'] !== orders_sync_status_create_new_order_token()
            && !orders_sync_moysklad_option_find_by_id($stateOptions, $row['ozon_status_create_default_state_id'])
        ) {
            throw new RuntimeException('Статус МойСклад по умолчанию для новых заказов не найден.');
        }
        if (
            $row['moysklad_fbo_new_order_state_id'] !== ''
            && !orders_sync_moysklad_option_find_by_id($stateOptions, $row['moysklad_fbo_new_order_state_id'])
        ) {
            throw new RuntimeException('Статус МойСклад для FBO в сценарии нового заказа не найден.');
        }
        if (
            $row['moysklad_wb_dbw_new_order_state_id'] !== ''
            && !orders_sync_moysklad_option_find_by_id($stateOptions, $row['moysklad_wb_dbw_new_order_state_id'])
        ) {
            throw new RuntimeException('Статус МойСклад для DBW WB в сценарии нового заказа не найден.');
        }
        foreach ($row['ozon_status_update_map'] as $stateId) {
            if ($stateId === orders_sync_status_update_keep_token()) {
                continue;
            }
            if ($stateId !== '' && !orders_sync_moysklad_option_find_by_id($stateOptions, $stateId)) {
                throw new RuntimeException('Выбранный статус МойСклад для существующих заказов не найден.');
            }
        }
        if (
            $row['ozon_status_update_default_state_id'] !== ''
            && $row['ozon_status_update_default_state_id'] !== orders_sync_status_update_keep_token()
            && !orders_sync_moysklad_option_find_by_id($stateOptions, $row['ozon_status_update_default_state_id'])
        ) {
            throw new RuntimeException('Статус МойСклад по умолчанию для существующих заказов не найден.');
        }
        if (
            $row['cancelled_transition_default_state_id'] !== ''
            && $row['cancelled_transition_default_state_id'] !== orders_sync_status_update_keep_token()
            && !orders_sync_moysklad_option_find_by_id($stateOptions, $row['cancelled_transition_default_state_id'])
        ) {
            throw new RuntimeException('Статус МойСклад по умолчанию для отмененных заказов не найден.');
        }
        foreach ($row['cancelled_transition_map'] as $sourceStateId => $targetStateId) {
            if (!orders_sync_moysklad_option_find_by_id($stateOptions, (string)$sourceStateId)) {
                throw new RuntimeException('Текущий статус МойСклад для правила отмены не найден.');
            }
            if ($targetStateId === orders_sync_status_update_keep_token()) {
                continue;
            }
            if (!orders_sync_moysklad_option_find_by_id($stateOptions, (string)$targetStateId)) {
                throw new RuntimeException('Целевой статус МойСклад для правила отмены не найден.');
            }
        }
    }

    if ($row['id'] > 0) {
        $st = db()->prepare("
            UPDATE feedtools_marketplace_sync_profiles
            SET marketplace = ?, title = ?, connection_id = ?, moysklad_account_id = ?, lookback_days = ?, sync_date_from = ?, sync_date_to = ?,
                moysklad_organization_id = ?, moysklad_counterparty_id = ?, moysklad_project_id = ?, moysklad_saleschannel_id = ?, moysklad_delivery_planned_source = ?, moysklad_default_store_id = ?, moysklad_fbo_store_id = ?, moysklad_fbo_new_order_state_id = ?, moysklad_wb_dbw_store_id = ?, moysklad_wb_dbw_new_order_state_id = ?,
                ozon_status_create_default_state_id = ?, ozon_status_update_default_state_id = ?,
                ozon_status_create_map_json = ?, ozon_status_update_map_json = ?, cancelled_transition_default_state_id = ?, cancelled_before_ship_zero_prices = ?, cancelled_transition_map_json = ?,
                order_sources_json = ?, is_active = ?, sort_order = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        $st->execute([
            $row['marketplace'], $row['title'], $row['connection_id'], $row['moysklad_account_id'],
            $row['lookback_days'], $row['sync_date_from'] !== '' ? $row['sync_date_from'] : null, $row['sync_date_to'] !== '' ? $row['sync_date_to'] : null, $row['moysklad_organization_id'], $row['moysklad_counterparty_id'],
            $row['moysklad_project_id'], $row['moysklad_saleschannel_id'], $row['moysklad_delivery_planned_source'], $row['moysklad_default_store_id'], $row['moysklad_fbo_store_id'], $row['moysklad_fbo_new_order_state_id'], $row['moysklad_wb_dbw_store_id'], $row['moysklad_wb_dbw_new_order_state_id'],
            $row['ozon_status_create_default_state_id'], $row['ozon_status_update_default_state_id'],
            $row['ozon_status_create_map_json'], $row['ozon_status_update_map_json'], $row['cancelled_transition_default_state_id'], $row['cancelled_before_ship_zero_prices'], $row['cancelled_transition_map_json'],
            $row['order_sources_json'], $row['is_active'], $row['sort_order'],
            $row['notes'], $actor, $row['id'],
        ]);
        return $row['id'];
    }

    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_sync_profiles (
            marketplace, title, connection_id, moysklad_account_id, lookback_days, sync_date_from, sync_date_to,
            moysklad_organization_id, moysklad_counterparty_id, moysklad_project_id, moysklad_saleschannel_id, moysklad_delivery_planned_source, moysklad_default_store_id, moysklad_fbo_store_id, moysklad_fbo_new_order_state_id, moysklad_wb_dbw_store_id, moysklad_wb_dbw_new_order_state_id,
            ozon_status_create_default_state_id, ozon_status_update_default_state_id,
            ozon_status_create_map_json, ozon_status_update_map_json, cancelled_transition_default_state_id, cancelled_before_ship_zero_prices, cancelled_transition_map_json,
            order_sources_json, is_active, sort_order, notes, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        $row['marketplace'], $row['title'], $row['connection_id'], $row['moysklad_account_id'], $row['lookback_days'], $row['sync_date_from'] !== '' ? $row['sync_date_from'] : null, $row['sync_date_to'] !== '' ? $row['sync_date_to'] : null,
        $row['moysklad_organization_id'], $row['moysklad_counterparty_id'], $row['moysklad_project_id'], $row['moysklad_saleschannel_id'], $row['moysklad_delivery_planned_source'], $row['moysklad_default_store_id'], $row['moysklad_fbo_store_id'], $row['moysklad_fbo_new_order_state_id'], $row['moysklad_wb_dbw_store_id'], $row['moysklad_wb_dbw_new_order_state_id'],
        $row['ozon_status_create_default_state_id'], $row['ozon_status_update_default_state_id'],
        $row['ozon_status_create_map_json'], $row['ozon_status_update_map_json'], $row['cancelled_transition_default_state_id'], $row['cancelled_before_ship_zero_prices'], $row['cancelled_transition_map_json'],
        $row['order_sources_json'], $row['is_active'], $row['sort_order'], $row['notes'], $actor, $actor,
    ]);
    return (int)db()->lastInsertId();
}

function orders_sync_profile_list(array $cfg = [], ?string $marketplace = null, ?int $connectionId = null): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);

    $sql = "
        SELECT p.*,
               c.title AS connection_title,
               c.marketplace AS connection_marketplace,
               ms.title AS moysklad_title
        FROM feedtools_marketplace_sync_profiles p
        LEFT JOIN feedtools_marketplace_connections c ON c.id = p.connection_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = p.moysklad_account_id
    ";
    $args = [];
    $where = [];
    if ($marketplace !== null && trim($marketplace) !== '') {
        $where[] = "p.marketplace = ?";
        $args[] = trim($marketplace);
    }
    if (($connectionId ?? 0) > 0) {
        $where[] = "p.connection_id = ?";
        $args[] = (int)$connectionId;
    }
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY p.is_active DESC, p.sort_order ASC, p.id ASC";

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];
    return array_map('orders_sync_profile_hydrate', $rows);
}

function orders_sync_profile_get(int $id, array $cfg = []): ?array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT p.*,
               c.title AS connection_title,
               c.marketplace AS connection_marketplace,
               ms.title AS moysklad_title
        FROM feedtools_marketplace_sync_profiles p
        LEFT JOIN feedtools_marketplace_connections c ON c.id = p.connection_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = p.moysklad_account_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? orders_sync_profile_hydrate($row) : null;
}

function orders_sync_profile_clone(int $sourceProfileId, int $targetConnectionId, string $newTitle = '', ?string $actor = null, array $cfg = []): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $sourceProfile = orders_sync_profile_get($sourceProfileId, $cfg);
    if (!is_array($sourceProfile) || (int)($sourceProfile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Исходный профиль синхронизации не найден.');
    }

    $targetConnection = ozon_price_connection_get($targetConnectionId, $cfg);
    if (!is_array($targetConnection) || (int)($targetConnection['id'] ?? 0) <= 0) {
        throw new RuntimeException('Целевое подключение не найдено.');
    }

    $sourceMarketplace = trim((string)($sourceProfile['marketplace'] ?? ''));
    $targetMarketplace = trim((string)($targetConnection['marketplace'] ?? ''));
    if ($sourceMarketplace === '' || $sourceMarketplace !== $targetMarketplace) {
        throw new RuntimeException('Копирование возможно только в подключение того же маркетплейса.');
    }

    if ((int)($sourceProfile['connection_id'] ?? 0) === (int)$targetConnectionId) {
        throw new RuntimeException('Выбери другое подключение для копирования профиля.');
    }

    $title = trim($newTitle);
    if ($title === '') {
        $title = 'Копия — ' . trim((string)($sourceProfile['title'] ?? 'Профиль'));
    }

    $cloneInput = [
        'id' => 0,
        'marketplace' => $sourceMarketplace,
        'title' => $title,
        'connection_id' => (int)$targetConnectionId,
        'moysklad_account_id' => (int)($sourceProfile['moysklad_account_id'] ?? 0),
        'moysklad_organization_id' => (string)($sourceProfile['moysklad_organization_id'] ?? ''),
        'moysklad_counterparty_id' => (string)($sourceProfile['moysklad_counterparty_id'] ?? ''),
        'moysklad_project_id' => (string)($sourceProfile['moysklad_project_id'] ?? ''),
        'moysklad_saleschannel_id' => (string)($sourceProfile['moysklad_saleschannel_id'] ?? ''),
        'moysklad_delivery_planned_source' => (string)($sourceProfile['moysklad_delivery_planned_source'] ?? 'order_created_at'),
        'moysklad_default_store_id' => (string)($sourceProfile['moysklad_default_store_id'] ?? ''),
        'moysklad_fbo_store_id' => (string)($sourceProfile['moysklad_fbo_store_id'] ?? ''),
        'moysklad_fbo_new_order_state_id' => (string)($sourceProfile['moysklad_fbo_new_order_state_id'] ?? ''),
        'moysklad_wb_dbw_store_id' => (string)($sourceProfile['moysklad_wb_dbw_store_id'] ?? ''),
        'moysklad_wb_dbw_new_order_state_id' => (string)($sourceProfile['moysklad_wb_dbw_new_order_state_id'] ?? ''),
        'ozon_status_create_default_state_id' => (string)($sourceProfile['ozon_status_create_default_state_id'] ?? ''),
        'ozon_status_update_default_state_id' => (string)($sourceProfile['ozon_status_update_default_state_id'] ?? orders_sync_status_update_keep_token()),
        'cancelled_transition_default_state_id' => (string)($sourceProfile['cancelled_transition_default_state_id'] ?? ''),
        'cancelled_before_ship_zero_prices' => !empty($sourceProfile['cancelled_before_ship_zero_prices']) ? '1' : '0',
        'lookback_days' => (int)($sourceProfile['lookback_days'] ?? 14),
        'sync_date_from' => (string)($sourceProfile['sync_date_from'] ?? ''),
        'sync_date_to' => (string)($sourceProfile['sync_date_to'] ?? ''),
        'order_sources' => (array)($sourceProfile['order_sources'] ?? []),
        'is_active' => !empty($sourceProfile['is_active']) ? '1' : '0',
        'sort_order' => (int)($sourceProfile['sort_order'] ?? 100),
        'notes' => (string)($sourceProfile['notes'] ?? ''),
        'status_mapping_statuses' => [],
        'status_mapping_create_state_ids' => [],
        'status_mapping_update_state_ids' => [],
        'cancel_transition_source_state_ids' => [],
        'cancel_transition_target_state_ids' => [],
        'warehouse_mapping_keys' => [],
        'warehouse_mapping_ids' => [],
        'warehouse_mapping_names' => [],
        'warehouse_mapping_moysklad_store_ids' => [],
        'warehouse_mapping_new_order_state_ids' => [],
    ];

    $statusCodes = [];
    foreach (orders_sync_marketplace_status_catalog($sourceMarketplace, (int)($sourceProfile['connection_id'] ?? 0), $cfg) as $statusItem) {
        if (!is_array($statusItem)) {
            continue;
        }
        $statusCode = trim((string)($statusItem['code'] ?? ''));
        if ($statusCode !== '') {
            $statusCodes[$statusCode] = true;
        }
    }
    foreach ((array)($sourceProfile['ozon_status_create_map'] ?? []) as $statusCode => $_) {
        $statusCode = strtolower(trim((string)$statusCode));
        if ($statusCode !== '') {
            $statusCodes[$statusCode] = true;
        }
    }
    foreach ((array)($sourceProfile['ozon_status_update_map'] ?? []) as $statusCode => $_) {
        $statusCode = strtolower(trim((string)$statusCode));
        if ($statusCode !== '') {
            $statusCodes[$statusCode] = true;
        }
    }

    foreach (array_keys($statusCodes) as $statusCode) {
        $cloneInput['status_mapping_statuses'][] = $statusCode;
        $cloneInput['status_mapping_create_state_ids'][] = (string)((array)($sourceProfile['ozon_status_create_map'] ?? [])[$statusCode] ?? '');
        $cloneInput['status_mapping_update_state_ids'][] = (string)((array)($sourceProfile['ozon_status_update_map'] ?? [])[$statusCode] ?? '');
    }

    foreach ((array)($sourceProfile['cancelled_transition_map'] ?? []) as $sourceStateId => $targetStateId) {
        $cloneInput['cancel_transition_source_state_ids'][] = (string)$sourceStateId;
        $cloneInput['cancel_transition_target_state_ids'][] = (string)$targetStateId;
    }

    foreach (orders_sync_profile_store_mapping_rows($sourceProfileId, $cfg) as $mapping) {
        if (!is_array($mapping)) {
            continue;
        }
        $cloneInput['warehouse_mapping_keys'][] = (string)($mapping['ozon_warehouse_key'] ?? '');
        $cloneInput['warehouse_mapping_ids'][] = (string)($mapping['ozon_warehouse_id'] ?? '');
        $cloneInput['warehouse_mapping_names'][] = (string)($mapping['ozon_warehouse_name'] ?? '');
        $cloneInput['warehouse_mapping_moysklad_store_ids'][] = (string)($mapping['moysklad_store_id'] ?? '');
        $cloneInput['warehouse_mapping_new_order_state_ids'][] = (string)($mapping['moysklad_new_order_state_id'] ?? '');
    }

    $moyskladAccountId = (int)($cloneInput['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    if (is_array($moyskladAccount)) {
        $stateOptions = orders_sync_moysklad_customerorder_state_options($moyskladAccount);
        $storeOptions = orders_sync_moysklad_stores_options($moyskladAccount);

        $isValidStateId = static function (string $value) use ($stateOptions): bool {
            return $value !== '' && orders_sync_moysklad_option_find_by_id($stateOptions, $value) !== null;
        };
        $isValidStoreId = static function (string $value) use ($storeOptions): bool {
            return $value !== '' && orders_sync_moysklad_option_find_by_id($storeOptions, $value) !== null;
        };

        if (!$isValidStoreId((string)$cloneInput['moysklad_default_store_id'])) {
            $cloneInput['moysklad_default_store_id'] = '';
        }
        if (!$isValidStoreId((string)$cloneInput['moysklad_fbo_store_id'])) {
            $cloneInput['moysklad_fbo_store_id'] = '';
        }
        if (!$isValidStoreId((string)$cloneInput['moysklad_wb_dbw_store_id'])) {
            $cloneInput['moysklad_wb_dbw_store_id'] = '';
        }
        if (
            (string)$cloneInput['moysklad_fbo_new_order_state_id'] !== ''
            && !$isValidStateId((string)$cloneInput['moysklad_fbo_new_order_state_id'])
        ) {
            $cloneInput['moysklad_fbo_new_order_state_id'] = '';
        }
        if (
            (string)$cloneInput['moysklad_wb_dbw_new_order_state_id'] !== ''
            && !$isValidStateId((string)$cloneInput['moysklad_wb_dbw_new_order_state_id'])
        ) {
            $cloneInput['moysklad_wb_dbw_new_order_state_id'] = '';
        }

        $createDefaultStateId = (string)$cloneInput['ozon_status_create_default_state_id'];
        if (
            $createDefaultStateId !== ''
            && $createDefaultStateId !== orders_sync_status_create_new_order_token()
            && !$isValidStateId($createDefaultStateId)
        ) {
            $cloneInput['ozon_status_create_default_state_id'] = '';
        }

        $updateDefaultStateId = (string)$cloneInput['ozon_status_update_default_state_id'];
        if (
            $updateDefaultStateId !== ''
            && $updateDefaultStateId !== orders_sync_status_update_keep_token()
            && !$isValidStateId($updateDefaultStateId)
        ) {
            $cloneInput['ozon_status_update_default_state_id'] = orders_sync_status_update_keep_token();
        }

        $cancelDefaultStateId = (string)$cloneInput['cancelled_transition_default_state_id'];
        if (
            $cancelDefaultStateId !== ''
            && $cancelDefaultStateId !== orders_sync_status_update_keep_token()
            && !$isValidStateId($cancelDefaultStateId)
        ) {
            $cloneInput['cancelled_transition_default_state_id'] = '';
        }

        foreach ($cloneInput['status_mapping_create_state_ids'] as $idx => $stateIdRaw) {
            $stateId = trim((string)$stateIdRaw);
            if ($stateId === '' || $stateId === orders_sync_status_create_new_order_token() || $isValidStateId($stateId)) {
                continue;
            }
            $cloneInput['status_mapping_create_state_ids'][$idx] = '';
        }

        foreach ($cloneInput['status_mapping_update_state_ids'] as $idx => $stateIdRaw) {
            $stateId = trim((string)$stateIdRaw);
            if ($stateId === '' || $stateId === orders_sync_status_update_keep_token() || $isValidStateId($stateId)) {
                continue;
            }
            $cloneInput['status_mapping_update_state_ids'][$idx] = '';
        }

        foreach ($cloneInput['cancel_transition_target_state_ids'] as $idx => $stateIdRaw) {
            $stateId = trim((string)$stateIdRaw);
            if ($stateId === '' || $stateId === orders_sync_status_update_keep_token() || $isValidStateId($stateId)) {
                continue;
            }
            $cloneInput['cancel_transition_target_state_ids'][$idx] = '';
            $cloneInput['cancel_transition_source_state_ids'][$idx] = '';
        }

        foreach ($cloneInput['warehouse_mapping_moysklad_store_ids'] as $idx => $storeIdRaw) {
            $storeId = trim((string)$storeIdRaw);
            if ($storeId === '' || $isValidStoreId($storeId)) {
                continue;
            }
            $cloneInput['warehouse_mapping_moysklad_store_ids'][$idx] = '';
        }

        foreach ($cloneInput['warehouse_mapping_new_order_state_ids'] as $idx => $stateIdRaw) {
            $stateId = trim((string)$stateIdRaw);
            if ($stateId === '' || $isValidStateId($stateId)) {
                continue;
            }
            $cloneInput['warehouse_mapping_new_order_state_ids'][$idx] = '';
        }
    }

    $newProfileId = orders_sync_profile_save_with_mappings($cloneInput, $actor, $cfg);
    $newProfile = orders_sync_profile_get($newProfileId, $cfg);
    if (is_array($newProfile)) {
        orders_sync_marketplace_profile_warehouses_discover($newProfile, $cfg);
    }
    orders_sync_automation_clone_profile_rows($sourceProfileId, $newProfileId, $actor, $cfg);

    return $newProfileId;
}

function orders_sync_profile_set_active(int $id, bool $isActive, ?string $actor = null, array $cfg = []): void
{
    orders_sync_profile_table_ensure($cfg);
    if ($id <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_sync_profiles
        SET is_active = ?, updated_by = ?
        WHERE id = ?
    ");
    $st->execute([$isActive ? 1 : 0, $actor, $id]);
}

function orders_sync_profile_delete(int $id, array $cfg = []): void
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);
    if ($id <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }

    $profile = orders_sync_profile_get($id, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }

    db()->beginTransaction();
    try {
        db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_automations WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_store_mappings WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_order_export_links WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_order_snapshot_history WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_order_snapshots WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_order_sync_runs WHERE profile_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM feedtools_marketplace_sync_profiles WHERE id = ? LIMIT 1")->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function orders_sync_profile_counts_by_connection(array $cfg = [], ?string $marketplace = null): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);
    $sql = "
        SELECT connection_id, COUNT(*) AS qty
        FROM feedtools_marketplace_sync_profiles
    ";
    $args = [];
    if ($marketplace !== null && trim($marketplace) !== '') {
        $sql .= " WHERE marketplace = ?";
        $args[] = trim($marketplace);
    }
    $sql .= " GROUP BY connection_id";
    $st = db()->prepare($sql);
    $st->execute($args);
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $map[(int)($row['connection_id'] ?? 0)] = (int)($row['qty'] ?? 0);
    }
    return $map;
}

function orders_sync_profile_store_mapping_rows(int $profileId, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_store_mappings_table_ensure($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $cache = &$GLOBALS['orders_sync_profile_store_mapping_rows_cache'];
    if (!is_array($cache)) {
        $cache = [];
    }
    if (isset($cache[$profileId])) {
        return $cache[$profileId];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_sync_profile_store_mappings
        WHERE profile_id = ?
        ORDER BY ozon_warehouse_name ASC, id ASC
    ");
    $st->execute([$profileId]);
    return $cache[$profileId] = ($st->fetchAll() ?: []);
}

function orders_sync_profile_store_mapping_rows_forget(int $profileId): void
{
    $cache = &$GLOBALS['orders_sync_profile_store_mapping_rows_cache'];
    if (is_array($cache)) {
        unset($cache[$profileId]);
    }
}

function orders_sync_profile_store_mapping_index(int $profileId, array $cfg = []): array
{
    $cache = &$GLOBALS['orders_sync_profile_store_mapping_index_cache'];
    if (!is_array($cache)) {
        $cache = [];
    }
    if ($profileId <= 0) {
        return [];
    }
    if (isset($cache[$profileId])) {
        return $cache[$profileId];
    }
    $index = [];
    foreach (orders_sync_profile_store_mapping_rows($profileId, $cfg) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string)($row['ozon_warehouse_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $index[$key] = $row;
    }
    return $cache[$profileId] = $index;
}

function orders_sync_profile_store_mapping_index_forget(int $profileId): void
{
    $cache = &$GLOBALS['orders_sync_profile_store_mapping_index_cache'];
    if (is_array($cache)) {
        unset($cache[$profileId]);
    }
}

function orders_sync_profile_editor_resources(array $profile, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    $moyskladAccount = ((int)($profile['moysklad_account_id'] ?? 0) > 0)
        ? orders_sync_moysklad_account_get((int)$profile['moysklad_account_id'], $cfg)
        : null;
    $connectionId = (int)($profile['connection_id'] ?? 0);

    $options = [
        'organizations' => is_array($moyskladAccount) ? orders_sync_moysklad_organizations_options($moyskladAccount) : [],
        'counterparties' => is_array($moyskladAccount) ? orders_sync_moysklad_counterparties_options($moyskladAccount) : [],
        'projects' => is_array($moyskladAccount) ? orders_sync_moysklad_projects_options($moyskladAccount) : [],
        'saleschannels' => is_array($moyskladAccount) ? orders_sync_moysklad_saleschannels_options($moyskladAccount) : [],
        'stores' => is_array($moyskladAccount) ? orders_sync_moysklad_stores_options($moyskladAccount) : [],
        'customerorder_states' => is_array($moyskladAccount) ? orders_sync_moysklad_customerorder_state_options($moyskladAccount) : [],
    ];

    $marketplace = orders_sync_marketplace_from_profile($profile);
    $warehouseMappings = ((int)($profile['id'] ?? 0) > 0 && is_array($moyskladAccount))
        ? orders_sync_marketplace_profile_warehouses_discover($profile, $cfg)
        : [];

    return [
        'moysklad_account' => $moyskladAccount,
        'options' => $options,
        'warehouse_mappings' => $warehouseMappings,
        'status_catalog' => orders_sync_marketplace_status_catalog($marketplace, $connectionId > 0 ? $connectionId : null, $cfg),
        'status_catalog_grouped' => orders_sync_marketplace_status_catalog_grouped($marketplace, $connectionId > 0 ? $connectionId : null, $cfg),
    ];
}

function orders_sync_ozon_logistics_warehouse_entry_from_posting(array $posting): ?array
{
    $deliveryMethod = is_array($posting['delivery_method'] ?? null) ? $posting['delivery_method'] : [];
    $warehouseId = trim((string)($deliveryMethod['warehouse_id'] ?? $deliveryMethod['id'] ?? ''));
    $warehouseName = trim((string)($deliveryMethod['warehouse'] ?? ''));
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

function orders_sync_ozon_warehouses_list(array $cfg): array
{
    $oz = ozon_cfg_or_fail($cfg);
    $cacheKey = 'ozon:warehouses:' . sha1(json_encode([
        'client_id' => (string)($oz['client_id'] ?? ''),
        'api_key' => (string)($oz['api_key'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = orders_sync_ui_cache_read($cacheKey, 300);
    if (is_array($cached) && is_array($cached['warehouses'] ?? null)) {
        return array_values(array_filter($cached['warehouses'], static fn($row): bool => is_array($row)));
    }

    $cursor = '';
    $warehouses = [];

    try {
        for ($page = 0; $page < 20; $page++) {
            $payload = [
                'limit' => 100,
            ];
            if ($cursor !== '') {
                $payload['cursor'] = $cursor;
            }

            $response = ozon_post_json($oz, '/v2/warehouse/list', $payload);
            $rows = is_array($response['warehouses'] ?? null) ? $response['warehouses'] : [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $warehouses[] = $row;
                }
            }

            $cursor = trim((string)($response['cursor'] ?? ''));
            $hasNext = !empty($response['has_next']);
            if (!$hasNext || $cursor === '') {
                break;
            }
        }
    } catch (Throwable $e) {
        $stale = orders_sync_ui_cache_read($cacheKey, 300, true);
        if (is_array($stale) && is_array($stale['warehouses'] ?? null)) {
            return array_values(array_filter($stale['warehouses'], static fn($row): bool => is_array($row)));
        }
        throw $e;
    }

    orders_sync_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
    return $warehouses;
}

function orders_sync_ozon_logistics_warehouse_is_visible(array $warehouse): bool
{
    $type = mb_strtolower(trim((string)($warehouse['warehouse_type'] ?? '')), 'UTF-8');
    if ($type !== '' && $type !== 'fbs') {
        return false;
    }

    $status = mb_strtolower(trim((string)($warehouse['status'] ?? '')), 'UTF-8');
    return in_array($status, ['created', 'active'], true);
}

function orders_sync_ozon_logistics_warehouse_entry_from_warehouse(array $warehouse): ?array
{
    if (!orders_sync_ozon_logistics_warehouse_is_visible($warehouse)) {
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

function orders_sync_ozon_profile_warehouses_discover(array $profile, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_store_mappings_table_ensure($cfg);

    $profileId = (int)($profile['id'] ?? 0);
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $existingRows = orders_sync_profile_store_mapping_rows($profileId, $cfg);
    if ($profileId <= 0 || $connectionId <= 0 || (string)($profile['marketplace'] ?? 'ozon') !== 'ozon') {
        return $existingRows;
    }

    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        return $existingRows;
    }

    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $warehouses = [];
    try {
        $ozonWarehouses = orders_sync_ozon_warehouses_list($runtimeCfg);
    } catch (Throwable $e) {
        return $existingRows;
    }
    foreach ($ozonWarehouses as $warehouse) {
        $entry = orders_sync_ozon_logistics_warehouse_entry_from_warehouse($warehouse);
        if (!is_array($entry)) {
            continue;
        }
        $warehouses[$entry['key']] = $entry;
    }

    foreach ($warehouses as $entry) {
        $st = db()->prepare("
            INSERT INTO feedtools_marketplace_sync_profile_store_mappings (
                profile_id, marketplace, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name, moysklad_store_id, moysklad_new_order_state_id, last_seen_at
            ) VALUES (?, 'ozon', ?, ?, ?, '', '', NOW())
            ON DUPLICATE KEY UPDATE
                ozon_warehouse_id = VALUES(ozon_warehouse_id),
                ozon_warehouse_name = VALUES(ozon_warehouse_name),
                last_seen_at = VALUES(last_seen_at)
        ");
        $st->execute([
            $profileId,
            (string)$entry['key'],
            (string)$entry['warehouse_id'],
            (string)$entry['warehouse_name'],
        ]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);

    $keepKeys = array_fill_keys(array_keys($warehouses), true);
    $rows = orders_sync_profile_store_mapping_rows($profileId, $cfg);
    foreach ($rows as $row) {
        $key = trim((string)($row['ozon_warehouse_key'] ?? ''));
        if ($key === '' || isset($keepKeys[$key])) {
            continue;
        }
        $st = db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_store_mappings WHERE profile_id = ? AND ozon_warehouse_key = ?");
        $st->execute([$profileId, $key]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);

    return orders_sync_profile_store_mapping_rows($profileId, $cfg);
}

function orders_sync_wb_client_from_connection(array $connection, array $cfg = []): WildberriesClient
{
    $runtimeCfg = ozon_price_cfg_with_connection(orders_sync_cfg_fallback($cfg), $connection);
    return new WildberriesClient((array)($runtimeCfg['wildberries'] ?? []));
}

function orders_sync_wb_warehouses_list(array $connection, array $cfg = [], bool $forceRefresh = false): array
{
    $cacheKey = 'wb:orders-sync:warehouses:' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'token_hash' => sha1((string)($connection['api_key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!$forceRefresh) {
        $cached = orders_sync_ui_cache_read($cacheKey, 300);
        if (is_array($cached) && is_array($cached['warehouses'] ?? null)) {
            return array_values(array_filter($cached['warehouses'], static fn($row): bool => is_array($row)));
        }
    }

    try {
        $response = orders_sync_wb_client_from_connection($connection, $cfg)->getSellerWarehouses();
        $warehouses = is_array($response['warehouses'] ?? null) ? $response['warehouses'] : (is_array($response) ? $response : []);
        $warehouses = array_values(array_filter($warehouses, static fn($row): bool => is_array($row)));
        orders_sync_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
        return $warehouses;
    } catch (Throwable $e) {
        $stale = orders_sync_ui_cache_read($cacheKey, 300, true);
        if (is_array($stale) && is_array($stale['warehouses'] ?? null)) {
            return array_values(array_filter($stale['warehouses'], static fn($row): bool => is_array($row)));
        }
        throw $e;
    }
}

function orders_sync_wb_logistics_warehouse_entry_from_warehouse(array $warehouse): ?array
{
    $warehouseId = trim((string)($warehouse['id'] ?? $warehouse['warehouseId'] ?? $warehouse['warehouse_id'] ?? ''));
    $warehouseName = trim((string)($warehouse['name'] ?? $warehouse['warehouseName'] ?? ''));
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

function orders_sync_wb_logistics_warehouse_entry_from_posting(array $posting): ?array
{
    $deliveryMethod = is_array($posting['delivery_method'] ?? null) ? $posting['delivery_method'] : [];
    $warehouseId = trim((string)($deliveryMethod['warehouse_id'] ?? $posting['warehouseId'] ?? ''));
    $warehouseName = trim((string)($deliveryMethod['warehouse'] ?? $posting['warehouseName'] ?? ''));
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

function orders_sync_yandex_logistics_warehouse_entry_from_posting(array $posting): ?array
{
    $deliveryMethod = is_array($posting['delivery_method'] ?? null) ? $posting['delivery_method'] : [];
    $warehouseId = trim((string)($deliveryMethod['warehouse_id'] ?? $posting['warehouseId'] ?? $posting['warehouse_id'] ?? ''));
    $warehouseName = trim((string)($deliveryMethod['warehouse'] ?? $posting['warehouseName'] ?? $posting['warehouse_name'] ?? ''));
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

function orders_sync_yandex_logistics_warehouse_entry_from_warehouse(array $warehouse): ?array
{
    $warehouseId = trim((string)($warehouse['id'] ?? $warehouse['warehouseId'] ?? ''));
    $warehouseName = trim((string)($warehouse['name'] ?? $warehouse['warehouseName'] ?? ''));
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

function orders_sync_marketplace_logistics_warehouse_entry_from_posting(string $marketplace, array $posting): ?array
{
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    return match ($marketplace) {
        'wb' => orders_sync_wb_logistics_warehouse_entry_from_posting($posting),
        'yandex_market' => orders_sync_yandex_logistics_warehouse_entry_from_posting($posting),
        default => orders_sync_ozon_logistics_warehouse_entry_from_posting($posting),
    };
}

function orders_sync_marketplace_profile_warehouses_discover(array $profile, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_store_mappings_table_ensure($cfg);
    $profileId = (int)($profile['id'] ?? 0);
    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = null;
    $marketplaceRaw = strtolower(trim((string)($profile['marketplace'] ?? '')));
    $marketplace = $marketplaceRaw !== '' ? orders_sync_marketplace_normalize($marketplaceRaw) : '';
    if ($marketplace === '' && $connectionId > 0) {
        $connection = ozon_price_connection_get($connectionId, $cfg);
        if (is_array($connection)) {
            $marketplace = orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? ''));
        }
    }
    if ($marketplace === '') {
        $marketplace = 'ozon';
    }
    if ($marketplace === 'ozon') {
        return orders_sync_ozon_profile_warehouses_discover($profile, $cfg);
    }

    $existingRows = orders_sync_profile_store_mapping_rows($profileId, $cfg);
    if ($profileId <= 0 || $connectionId <= 0 || !in_array($marketplace, ['wb', 'yandex_market'], true)) {
        return $existingRows;
    }

    $connection = is_array($connection) ? $connection : ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        return $existingRows;
    }

    if ($marketplace === 'yandex_market') {
        try {
            $sourceWarehouses = orders_sync_yandex_warehouses_list($connection, $cfg);
        } catch (Throwable) {
            return $existingRows;
        }

        $warehouses = [];
        foreach ($sourceWarehouses as $warehouse) {
            $entry = orders_sync_yandex_logistics_warehouse_entry_from_warehouse($warehouse);
            if (is_array($entry)) {
                $warehouses[$entry['key']] = $entry;
            }
        }

        foreach ($warehouses as $entry) {
            $st = db()->prepare("
                INSERT INTO feedtools_marketplace_sync_profile_store_mappings (
                    profile_id, marketplace, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name, moysklad_store_id, moysklad_new_order_state_id, last_seen_at
                ) VALUES (?, 'yandex_market', ?, ?, ?, '', '', NOW())
                ON DUPLICATE KEY UPDATE
                    marketplace = VALUES(marketplace),
                    ozon_warehouse_id = VALUES(ozon_warehouse_id),
                    ozon_warehouse_name = VALUES(ozon_warehouse_name),
                    last_seen_at = VALUES(last_seen_at)
            ");
            $st->execute([
                $profileId,
                (string)$entry['key'],
                (string)$entry['warehouse_id'],
                (string)$entry['warehouse_name'],
            ]);
        }

        orders_sync_profile_store_mapping_rows_forget($profileId);
        orders_sync_profile_store_mapping_index_forget($profileId);

        $keepKeys = array_fill_keys(array_keys($warehouses), true);
        foreach (orders_sync_profile_store_mapping_rows($profileId, $cfg) as $row) {
            $key = trim((string)($row['ozon_warehouse_key'] ?? ''));
            if ($key === '' || isset($keepKeys[$key])) {
                continue;
            }
            db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_store_mappings WHERE profile_id = ? AND ozon_warehouse_key = ?")->execute([$profileId, $key]);
        }

        orders_sync_profile_store_mapping_rows_forget($profileId);
        orders_sync_profile_store_mapping_index_forget($profileId);
        return orders_sync_profile_store_mapping_rows($profileId, $cfg);
    }

    try {
        $sourceWarehouses = orders_sync_wb_warehouses_list($connection, $cfg, true);
    } catch (Throwable) {
        return $existingRows;
    }

    $warehouses = [];
    foreach ($sourceWarehouses as $warehouse) {
        $entry = orders_sync_wb_logistics_warehouse_entry_from_warehouse($warehouse);
        if (is_array($entry)) {
            $warehouses[$entry['key']] = $entry;
        }
    }

    foreach ($warehouses as $entry) {
        $st = db()->prepare("
            INSERT INTO feedtools_marketplace_sync_profile_store_mappings (
                profile_id, marketplace, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name, moysklad_store_id, moysklad_new_order_state_id, last_seen_at
            ) VALUES (?, 'wb', ?, ?, ?, '', '', NOW())
            ON DUPLICATE KEY UPDATE
                marketplace = VALUES(marketplace),
                ozon_warehouse_id = VALUES(ozon_warehouse_id),
                ozon_warehouse_name = VALUES(ozon_warehouse_name),
                last_seen_at = VALUES(last_seen_at)
        ");
        $st->execute([
            $profileId,
            (string)$entry['key'],
            (string)$entry['warehouse_id'],
            (string)$entry['warehouse_name'],
        ]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);

    $keepKeys = array_fill_keys(array_keys($warehouses), true);
    foreach (orders_sync_profile_store_mapping_rows($profileId, $cfg) as $row) {
        $key = trim((string)($row['ozon_warehouse_key'] ?? ''));
        if ($key === '' || isset($keepKeys[$key])) {
            continue;
        }
        db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_store_mappings WHERE profile_id = ? AND ozon_warehouse_key = ?")->execute([$profileId, $key]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);

    return orders_sync_profile_store_mapping_rows($profileId, $cfg);
}

function orders_sync_profile_save_with_mappings(array $input, ?string $actor = null, array $cfg = []): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $savedId = 0;
    db()->beginTransaction();
    try {
        $savedId = orders_sync_profile_save($input, $actor, $cfg);
        $savedProfile = orders_sync_profile_get($savedId, $cfg);
        if (!is_array($savedProfile)) {
            throw new RuntimeException('Не удалось перечитать профиль синхронизации после сохранения.');
        }
        orders_sync_profile_store_mappings_save($savedId, $input, $savedProfile, $actor, $cfg);
        db()->commit();
        return $savedId;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function orders_sync_profile_store_mappings_save(int $profileId, array $input, array $profile, ?string $actor = null, array $cfg = []): void
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_store_mappings_table_ensure($cfg);
    if ($profileId <= 0) {
        return;
    }
    $marketplace = orders_sync_marketplace_from_profile($profile);

    $keys = is_array($input['warehouse_mapping_keys'] ?? null) ? $input['warehouse_mapping_keys'] : [];
    $names = is_array($input['warehouse_mapping_names'] ?? null) ? $input['warehouse_mapping_names'] : [];
    $ids = is_array($input['warehouse_mapping_ids'] ?? null) ? $input['warehouse_mapping_ids'] : [];
    $storeIds = is_array($input['warehouse_mapping_moysklad_store_ids'] ?? null) ? $input['warehouse_mapping_moysklad_store_ids'] : [];
    $newOrderStateIds = is_array($input['warehouse_mapping_new_order_state_ids'] ?? null) ? $input['warehouse_mapping_new_order_state_ids'] : [];

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    $storeOptions = is_array($moyskladAccount) ? orders_sync_moysklad_stores_options($moyskladAccount) : [];
    $stateOptions = is_array($moyskladAccount) ? orders_sync_moysklad_customerorder_state_options($moyskladAccount) : [];

    $keepKeys = [];
    foreach ($keys as $idx => $keyRaw) {
        $key = trim((string)$keyRaw);
        if ($key === '') {
            continue;
        }
        $keepKeys[$key] = true;
        $warehouseName = trim((string)($names[$idx] ?? ''));
        $warehouseId = trim((string)($ids[$idx] ?? ''));
        $storeId = trim((string)($storeIds[$idx] ?? ''));
        $newOrderStateId = trim((string)($newOrderStateIds[$idx] ?? ''));
        if ($storeId !== '' && !orders_sync_moysklad_option_find_by_id($storeOptions, $storeId)) {
            throw new RuntimeException('Выбранный склад МойСклад для сопоставления не найден.');
        }
        if ($newOrderStateId !== '' && !orders_sync_moysklad_option_find_by_id($stateOptions, $newOrderStateId)) {
            throw new RuntimeException('Выбранный статус МойСклад для нового заказа по складу не найден.');
        }
        $st = db()->prepare("
            INSERT INTO feedtools_marketplace_sync_profile_store_mappings (
                profile_id, marketplace, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name, moysklad_store_id, moysklad_new_order_state_id, last_seen_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                ozon_warehouse_id = VALUES(ozon_warehouse_id),
                ozon_warehouse_name = VALUES(ozon_warehouse_name),
                moysklad_store_id = VALUES(moysklad_store_id),
                moysklad_new_order_state_id = VALUES(moysklad_new_order_state_id),
                last_seen_at = VALUES(last_seen_at)
        ");
        $st->execute([$profileId, $marketplace, $key, $warehouseId, $warehouseName, $storeId, $newOrderStateId]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);

    $existing = orders_sync_profile_store_mapping_rows($profileId, $cfg);
    foreach ($existing as $row) {
        $key = trim((string)($row['ozon_warehouse_key'] ?? ''));
        if ($key === '' || isset($keepKeys[$key])) {
            continue;
        }
        $st = db()->prepare("DELETE FROM feedtools_marketplace_sync_profile_store_mappings WHERE profile_id = ? AND ozon_warehouse_key = ?");
        $st->execute([$profileId, $key]);
    }

    orders_sync_profile_store_mapping_rows_forget($profileId);
    orders_sync_profile_store_mapping_index_forget($profileId);
}

function orders_sync_profile_default_id(array $cfg = [], string $marketplace = 'ozon', ?int $connectionId = null): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_profile_table_ensure($cfg);
    $sql = "
        SELECT id
        FROM feedtools_marketplace_sync_profiles
        WHERE marketplace = ?
    ";
    $args = [$marketplace];
    if (($connectionId ?? 0) > 0) {
        $sql .= " AND connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY is_active DESC, sort_order ASC, id ASC LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)($st->fetchColumn() ?: 0);
}

function orders_sync_profile_resolve(?int $requestedId, array $cfg = [], string $marketplace = 'ozon', ?int $connectionId = null): ?array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    if (($requestedId ?? 0) > 0) {
        $row = orders_sync_profile_get((int)$requestedId, $cfg);
        if (
            is_array($row)
            && (($connectionId ?? 0) <= 0 || (int)($row['connection_id'] ?? 0) === (int)$connectionId)
            && ((string)($row['marketplace'] ?? '') === $marketplace)
        ) {
            return $row;
        }
    }
    $defaultId = orders_sync_profile_default_id($cfg, $marketplace, $connectionId);
    return $defaultId > 0 ? orders_sync_profile_get($defaultId, $cfg) : null;
}

function orders_sync_run_create(
    int $profileId,
    int $connectionId,
    int $moyskladAccountId,
    string $marketplace,
    string $source,
    ?string $actor,
    ?string $sinceUtc,
    ?string $toUtc,
    ?int $sourceRunId = null,
    array $cfg = []
): int {
    orders_sync_runs_table_ensure($cfg);
    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_order_sync_runs (
            profile_id, connection_id, moysklad_account_id, marketplace, source, actor, status, source_run_id, period_since, period_to, summary_json, log_text
        ) VALUES (?, ?, ?, ?, ?, ?, 'running', ?, ?, ?, NULL, '')
    ");
    $st->execute([
        $profileId,
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $source,
        $actor,
        max(0, (int)($sourceRunId ?? 0)),
        orders_sync_db_datetime($sinceUtc),
        orders_sync_db_datetime($toUtc),
    ]);
    return (int)db()->lastInsertId();
}

function orders_sync_run_append(int $runId, string $message): void
{
    orders_sync_runs_table_ensure();
    $st = db()->prepare("
        UPDATE feedtools_marketplace_order_sync_runs
        SET log_text = CONCAT(COALESCE(log_text, ''), ?),
            heartbeat_at = CASE
                WHEN status IN ('running', 'stopping') THEN NOW()
                ELSE heartbeat_at
            END
        WHERE id = ?
    ");
    $st->execute([$message, $runId]);
}

function orders_sync_run_buffered_logger(int $runId, int $flushEveryMessages = 20): array
{
    $flushEveryMessages = max(1, $flushEveryMessages);
    $buffer = [];
    $count = 0;

    $flush = static function () use (&$buffer, &$count, $runId): void {
        if ($runId <= 0 || !$buffer) {
            $buffer = [];
            $count = 0;
            return;
        }
        orders_sync_run_append($runId, implode('', $buffer));
        $buffer = [];
        $count = 0;
    };

    $log = static function (string $message) use (&$buffer, &$count, $flush, $flushEveryMessages): void {
        $buffer[] = $message;
        $count++;
        if ($count >= $flushEveryMessages) {
            $flush();
        }
    };

    return [
        'log' => $log,
        'flush' => $flush,
    ];
}

function orders_sync_test_export_mode_label(string $mode): string
{
    return match ($mode) {
        'updated' => 'Обновлено в МойСклад',
        'restored' => 'Восстановлено из корзины в МойСклад',
        'recreated' => 'Создано заново в МойСклад',
        'linked_existing' => 'Найдено в МойСклад и привязано',
        'skipped_exists' => 'Пропущено: заказ уже есть в МойСклад',
        'skipped_missing' => 'Пропущено: заказ в МойСклад не найден',
        'skipped_same_state' => 'Пропущено: статус уже совпадает',
        'skipped_keep' => 'Пропущено: статус не меняем',
        'skipped_unchanged' => 'Пропущено: изменений нет',
        default => 'Создано в МойСклад',
    };
}

function orders_sync_run_kind_is_moysklad_operation(string $kind): bool
{
    return in_array(trim($kind), ['moysklad_export', 'moysklad_create_orders', 'moysklad_update_statuses'], true);
}

function orders_sync_run_lock_scope_for_kind(string $kind): string
{
    return match (trim($kind)) {
        'moysklad_create_orders' => 'create',
        'moysklad_update_statuses' => 'status',
        default => 'exclusive',
    };
}

function orders_sync_run_scopes_conflict(string $requestedScope, string $existingScope): bool
{
    $requestedScope = trim($requestedScope) !== '' ? trim($requestedScope) : 'exclusive';
    $existingScope = trim($existingScope) !== '' ? trim($existingScope) : 'exclusive';
    if ($requestedScope === 'exclusive' || $existingScope === 'exclusive') {
        return true;
    }
    return $requestedScope === $existingScope;
}

function orders_sync_run_kind_from_row(array $row): string
{
    $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
    $kind = trim((string)($summary['kind'] ?? ''));
    if ($kind !== '') {
        return $kind;
    }
    return match (trim((string)($row['source'] ?? ''))) {
        'ozon_sync' => 'ozon_sync',
        'marketplace_sync' => 'marketplace_sync',
        'moysklad_export' => 'moysklad_export',
        'moysklad_create_orders' => 'moysklad_create_orders',
        'moysklad_update_statuses' => 'moysklad_update_statuses',
        default => 'ozon_sync',
    };
}

function orders_sync_moysklad_operation_normalize(string $operation): string
{
    $operation = strtolower(trim($operation));
    return match ($operation) {
        'create', 'create_only', 'new', 'new_orders', 'create_orders' => 'create_only',
        'status', 'status_only', 'statuses', 'update_statuses', 'update_status' => 'status_only',
        default => 'full',
    };
}

function orders_sync_moysklad_operation_kind(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'moysklad_create_orders',
        'status_only' => 'moysklad_update_statuses',
        default => 'moysklad_export',
    };
}

function orders_sync_moysklad_operation_label(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'Поиск и создание новых заказов',
        'status_only' => 'Обновление статусов заказов',
        default => 'Выгрузка в МойСклад',
    };
}

function orders_sync_moysklad_operation_queue_text(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'поставлен в очередь на поиск и создание новых заказов в МойСклад',
        'status_only' => 'поставлен в очередь на обновление статусов заказов в МойСклад',
        default => 'поставлен в очередь на выгрузку заказов в МойСклад',
    };
}

function orders_sync_moysklad_operation_start_text(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'старт поиска и создания новых заказов в МойСклад',
        'status_only' => 'старт обновления статусов заказов в МойСклад',
        default => 'старт массовой выгрузки заказов в МойСклад',
    };
}

function orders_sync_moysklad_operation_dataset_selection(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'unlinked_only',
        'status_only' => 'linked_only',
        default => 'all',
    };
}

function orders_sync_moysklad_operation_export_mode(string $operation): string
{
    return match (orders_sync_moysklad_operation_normalize($operation)) {
        'create_only' => 'bulk_create',
        'status_only' => 'bulk_status',
        default => 'bulk',
    };
}

function orders_sync_moysklad_operation_totals_seed(): array
{
    return [
        'scanned' => 0,
        'processed' => 0,
        'created' => 0,
        'linked' => 0,
        'updated' => 0,
        'restored' => 0,
        'recreated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];
}

function orders_sync_run_dataset_selection_normalize(string $selection): string
{
    $selection = strtolower(trim($selection));
    return match ($selection) {
        'unlinked_only', 'linked_only' => $selection,
        default => 'all',
    };
}

function orders_sync_run_dataset_selection_sql(string $selection, string $alias = 'h'): array
{
    $selection = orders_sync_run_dataset_selection_normalize($selection);
    $alias = preg_match('~^[a-z][a-z0-9_]*$~i', $alias) ? $alias : 'h';
    if ($selection === 'unlinked_only') {
        return [
            'join' => '',
            'where' => "
                AND NOT EXISTS (
                    SELECT 1
                    FROM feedtools_marketplace_order_export_links e
                    WHERE e.connection_id = {$alias}.connection_id
                      AND e.moysklad_account_id = {$alias}.moysklad_account_id
                      AND e.marketplace = {$alias}.marketplace
                      AND e.order_source = {$alias}.order_source
                      AND e.posting_number = {$alias}.posting_number
                      AND COALESCE(e.moysklad_customerorder_id, '') <> ''
                )
            ",
        ];
    }
    if ($selection === 'linked_only') {
        return [
            'join' => '',
            'where' => "
                AND EXISTS (
                    SELECT 1
                    FROM feedtools_marketplace_order_export_links e
                    WHERE e.connection_id = {$alias}.connection_id
                      AND e.moysklad_account_id = {$alias}.moysklad_account_id
                      AND e.marketplace = {$alias}.marketplace
                      AND e.order_source = {$alias}.order_source
                      AND e.posting_number = {$alias}.posting_number
                      AND COALESCE(e.moysklad_customerorder_id, '') <> ''
                )
            ",
        ];
    }
    return ['join' => '', 'where' => ''];
}

function orders_sync_runtime_overrides_normalize(array $profile, array $overrides = []): array
{
    $result = [];

    $periodDays = (int)($overrides['period_days'] ?? 0);
    if ($periodDays > 0) {
        $tz = new DateTimeZone('Europe/Moscow');
        $dateTo = new DateTimeImmutable('today', $tz);
        $dateFrom = $dateTo->sub(new DateInterval('P' . max(0, min(90, $periodDays) - 1) . 'D'));
        $result['period_days'] = max(1, min(90, $periodDays));
        $result['sync_date_from'] = $dateFrom->format('Y-m-d');
        $result['sync_date_to'] = $dateTo->format('Y-m-d');
    } else {
        $dateFrom = orders_sync_profile_date_normalize($overrides['sync_date_from'] ?? '');
        $dateTo = orders_sync_profile_date_normalize($overrides['sync_date_to'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '') {
            if ($dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }
            $result['sync_date_from'] = $dateFrom;
            $result['sync_date_to'] = $dateTo;
        }
    }

    if (array_key_exists('order_sources', $overrides)) {
        $result['order_sources'] = orders_sync_marketplace_normalize_order_sources(
            $overrides['order_sources'],
            orders_sync_marketplace_from_profile($profile)
        );
    }

    foreach (['automation_id', 'operation_key', 'frequency_key', 'run_hour_msk', 'run_minute_msk'] as $key) {
        if (array_key_exists($key, $overrides)) {
            $result[$key] = $overrides[$key];
        }
    }

    return $result;
}

function orders_sync_profile_with_runtime_overrides(array $profile, array $runtimeOverrides = []): array
{
    $runtimeOverrides = orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
    if (!$runtimeOverrides) {
        return $profile;
    }
    if (isset($runtimeOverrides['sync_date_from'], $runtimeOverrides['sync_date_to'])) {
        $profile['sync_date_from'] = (string)$runtimeOverrides['sync_date_from'];
        $profile['sync_date_to'] = (string)$runtimeOverrides['sync_date_to'];
    }
    if (isset($runtimeOverrides['period_days'])) {
        $profile['lookback_days'] = (int)$runtimeOverrides['period_days'];
    }
    if (isset($runtimeOverrides['order_sources'])) {
        $profile['order_sources'] = orders_sync_marketplace_normalize_order_sources(
            $runtimeOverrides['order_sources'],
            orders_sync_marketplace_from_profile($profile)
        );
        $profile['order_sources_json'] = json_encode($profile['order_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["fbs"]';
    }
    return $profile;
}

function orders_sync_run_runtime_overrides(int $runId, array $cfg = []): array
{
    $run = orders_sync_run_get($runId, $cfg);
    $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
    $runtimeOverrides = is_array($summary['runtime_overrides'] ?? null) ? $summary['runtime_overrides'] : [];
    $profile = [];
    $profileId = (int)($run['profile_id'] ?? 0);
    if ($profileId > 0) {
        $loadedProfile = orders_sync_profile_get($profileId, $cfg);
        if (is_array($loadedProfile)) {
            $profile = $loadedProfile;
        }
    }
    if (!$profile) {
        $marketplace = orders_sync_marketplace_normalize((string)($run['marketplace'] ?? ($summary['marketplace'] ?? 'ozon')));
        $profile = ['marketplace' => $marketplace];
    }
    return orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
}

function orders_sync_run_update(int $runId, string $status, array $summary, array $cfg = []): void
{
    orders_sync_runs_table_ensure($cfg);
    if ($runId <= 0) {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_order_sync_runs
        SET status = ?, summary_json = ?,
            heartbeat_at = CASE
                WHEN ? IN ('running', 'stopping') THEN NOW()
                ELSE heartbeat_at
            END
        WHERE id = ?
    ");
    $st->execute([
        $status,
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $status,
        $runId,
    ]);
}

function orders_sync_run_request_stop(int $runId, array $cfg = []): void
{
    orders_sync_runs_table_ensure($cfg);
    if ($runId <= 0) {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_order_sync_runs
        SET status = 'stopping'
        WHERE id = ? AND status = 'running'
    ");
    $st->execute([$runId]);
}

function orders_sync_run_should_stop(int $runId, array $cfg = []): bool
{
    orders_sync_runs_table_ensure($cfg);
    if ($runId <= 0) {
        return false;
    }
    $st = db()->prepare("SELECT status FROM feedtools_marketplace_order_sync_runs WHERE id = ? LIMIT 1");
    $st->execute([$runId]);
    return trim((string)($st->fetchColumn() ?: '')) === 'stopping';
}

function orders_sync_run_stop_checker(int $runId, array $cfg = [], float $ttlSeconds = 1.0): callable
{
    $lastCheckedAt = 0.0;
    $cached = false;
    return static function (bool $force = false) use ($runId, $cfg, $ttlSeconds, &$lastCheckedAt, &$cached): bool {
        if ($runId <= 0) {
            return false;
        }
        $now = microtime(true);
        if (!$force && ($now - $lastCheckedAt) < $ttlSeconds) {
            return $cached;
        }
        $cached = orders_sync_run_should_stop($runId, $cfg);
        $lastCheckedAt = $now;
        return $cached;
    };
}

function orders_sync_run_finish(int $runId, string $status, array $summary, ?string $errorText = null, array $cfg = []): void
{
    orders_sync_runs_table_ensure($cfg);
    $st = db()->prepare("
        UPDATE feedtools_marketplace_order_sync_runs
        SET status = ?, summary_json = ?, error_text = ?, finished_at = NOW(), heartbeat_at = NOW()
        WHERE id = ?
    ");
    $st->execute([
        $status,
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $errorText,
        $runId,
    ]);
}

function orders_sync_runner_log_path(int $runId, array $cfg = []): string
{
    $cfg = orders_sync_cfg_fallback($cfg);
    $logsDir = rtrim((string)($cfg['paths']['logs_dir'] ?? (__DIR__ . '/../storage/logs')), '/\\');
    if ($logsDir === '') {
        $logsDir = __DIR__ . '/../storage/logs';
    }
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }
    return $logsDir . '/orders_sync_runner_' . max(0, $runId) . '.log';
}

function orders_sync_runner_process_matches(int $pid, int $runId): bool
{
    if ($pid <= 0) {
        return false;
    }
    $cmdlinePath = '/proc/' . $pid . '/cmdline';
    if (is_readable($cmdlinePath)) {
        $cmdline = str_replace("\0", ' ', (string)@file_get_contents($cmdlinePath));
        return str_contains($cmdline, 'orders_sync_run.php')
            && str_contains($cmdline, '--run_id=' . $runId);
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return false;
}

function orders_sync_recover_stale_runs(array $cfg = [], int $startupGraceSec = 600, int $heartbeatGraceSec = 3600): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_runs_table_ensure($cfg);

    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_order_sync_runs
        WHERE status IN ('running', 'stopping')
          AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY id ASC
        LIMIT 200
    ");
    $st->execute([max(60, $startupGraceSec)]);
    $rows = $st->fetchAll() ?: [];
    $recovered = 0;
    $now = time();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $runId = (int)($row['id'] ?? 0);
        if ($runId <= 0) {
            continue;
        }
        $pid = (int)($row['runner_pid'] ?? 0);
        $runnerAlive = $pid > 0 && orders_sync_runner_process_matches($pid, $runId);
        if ($runnerAlive) {
            continue;
        }

        $startedAt = strtotime((string)($row['started_at'] ?? '')) ?: 0;
        $heartbeatAt = strtotime((string)($row['heartbeat_at'] ?? '')) ?: 0;
        $lastActivityAt = $heartbeatAt > 0 ? $heartbeatAt : $startedAt;
        $ageSec = $startedAt > 0 ? ($now - $startedAt) : PHP_INT_MAX;
        $idleSec = $lastActivityAt > 0 ? ($now - $lastActivityAt) : PHP_INT_MAX;
        $logText = (string)($row['log_text'] ?? '');
        $runnerNeverStarted = !str_contains($logText, 'старт ')
            && !str_contains($logText, 'старт ручной')
            && !str_contains($logText, 'старт массовой')
            && !str_contains($logText, 'старт поиска')
            && !str_contains($logText, 'старт обновления');

        if (!$runnerNeverStarted && $idleSec < max(600, $heartbeatGraceSec)) {
            continue;
        }
        if ($runnerNeverStarted && $ageSec < max(60, $startupGraceSec)) {
            continue;
        }

        $summary = orders_sync_decode_json_row($row['summary_json'] ?? null);
        if (!$summary) {
            $summary = [
                'kind' => orders_sync_run_kind_from_row($row),
                'run_id' => $runId,
            ];
        }
        $message = $runnerNeverStarted
            ? 'Фоновый Orders Sync runner не стартовал или завершился до первого шага.'
            : 'Фоновый Orders Sync runner пропал без завершения run-а.';
        if ($pid > 0) {
            $message .= ' PID=' . $pid . '.';
        }
        $summary['status'] = 'error';
        $summary['fatal_error'] = $message;
        orders_sync_run_append($runId, "Автовосстановление: {$message}\n");
        orders_sync_run_finish($runId, 'error', $summary, $message, $cfg);
        $recovered++;
    }

    return $recovered;
}

function orders_sync_background_spawn(string $mode, int $profileId, int $runId, ?string $actor = null, array $cfg = []): void
{
    $cfg = orders_sync_cfg_fallback($cfg);
    $phpBin = (string)($cfg['worker']['php_bin'] ?? PHP_BINARY);
    $script = __DIR__ . '/../bin/orders_sync_run.php';
    if ($phpBin === '' || !is_file($script)) {
        throw new RuntimeException('Не удалось подготовить фоновый запуск Orders Sync.');
    }

    $cmdParts = [
        escapeshellarg($phpBin),
        escapeshellarg($script),
        '--mode=' . escapeshellarg($mode),
        '--profile_id=' . (int)$profileId,
        '--run_id=' . (int)$runId,
    ];
    $actor = trim((string)$actor);
    if ($actor !== '') {
        $cmdParts[] = '--actor=' . escapeshellarg($actor);
    }
    $logPath = orders_sync_runner_log_path($runId, $cfg);
    $cmd = 'nohup ' . implode(' ', $cmdParts)
        . ' >> ' . escapeshellarg($logPath)
        . ' 2>&1 < /dev/null & echo $!';
    $pidLines = [];
    $exitCode = 0;
    @exec($cmd, $pidLines, $exitCode);
    $pid = (int)trim((string)($pidLines[0] ?? ''));
    orders_sync_run_append(
        $runId,
        'Фоновый runner запущен: pid=' . ($pid > 0 ? (string)$pid : 'unknown')
        . ', exit_code=' . $exitCode
        . ', log=' . $logPath . "\n"
    );
    if ($pid > 0) {
        db()->prepare("
            UPDATE feedtools_marketplace_order_sync_runs
            SET runner_pid = ?, heartbeat_at = NOW()
            WHERE id = ?
        ")->execute([$pid, $runId]);
        return;
    }

    $existing = orders_sync_run_get($runId, $cfg);
    $summary = is_array($existing['summary'] ?? null) ? $existing['summary'] : [
        'kind' => $mode,
        'run_id' => $runId,
    ];
    $message = 'Не удалось запустить фоновый Orders Sync runner.';
    $summary['status'] = 'error';
    $summary['fatal_error'] = $message;
    orders_sync_run_finish($runId, 'error', $summary, $message, $cfg);
    throw new RuntimeException($message);
}

function orders_sync_run_recent(int $limit = 20, ?int $profileId = null, ?int $connectionId = null, array $cfg = []): array
{
    orders_sync_runs_table_ensure($cfg);
    $limit = max(1, min(100, $limit));

    $sql = "
        SELECT r.*, p.title AS profile_title, ms.title AS moysklad_title, c.title AS connection_title
        FROM feedtools_marketplace_order_sync_runs r
        LEFT JOIN feedtools_marketplace_sync_profiles p ON p.id = r.profile_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = r.moysklad_account_id
        LEFT JOIN feedtools_marketplace_connections c ON c.id = r.connection_id
        WHERE 1=1
    ";
    $args = [];
    if (($profileId ?? 0) > 0) {
        $sql .= " AND r.profile_id = ?";
        $args[] = (int)$profileId;
    } elseif (($connectionId ?? 0) > 0) {
        $sql .= " AND r.connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY r.id DESC LIMIT {$limit}";

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['summary'] = orders_sync_decode_json_row($row['summary_json'] ?? null);
    }
    unset($row);
    return $rows;
}

function orders_sync_run_active_for_profile(int $profileId, array $cfg = []): ?array
{
    $rows = orders_sync_run_active_list_for_profile($profileId, $cfg);
    return $rows[0] ?? null;
}

function orders_sync_run_active_list_for_profile(int $profileId, array $cfg = []): array
{
    orders_sync_runs_table_ensure($cfg);
    orders_sync_recover_stale_runs($cfg);
    if ($profileId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT r.*, p.title AS profile_title, ms.title AS moysklad_title, c.title AS connection_title
        FROM feedtools_marketplace_order_sync_runs r
        LEFT JOIN feedtools_marketplace_sync_profiles p ON p.id = r.profile_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = r.moysklad_account_id
        LEFT JOIN feedtools_marketplace_connections c ON c.id = r.connection_id
        WHERE r.profile_id = ? AND r.status IN ('running', 'stopping')
        ORDER BY r.id DESC
    ");
    $st->execute([$profileId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['summary'] = orders_sync_decode_json_row($row['summary_json'] ?? null);
    }
    unset($row);
    return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
}

function orders_sync_run_conflicting_from_list(array $runs, string $requestedKind): ?array
{
    $requestedScope = orders_sync_run_lock_scope_for_kind($requestedKind);
    foreach ($runs as $run) {
        if (!is_array($run)) {
            continue;
        }
        $existingKind = orders_sync_run_kind_from_row($run);
        $existingScope = orders_sync_run_lock_scope_for_kind($existingKind);
        if (orders_sync_run_scopes_conflict($requestedScope, $existingScope)) {
            return $run;
        }
    }
    return null;
}

function orders_sync_run_active_conflict_for_profile(int $profileId, string $requestedKind, array $cfg = []): ?array
{
    return orders_sync_run_conflicting_from_list(
        orders_sync_run_active_list_for_profile($profileId, $cfg),
        $requestedKind
    );
}

function orders_sync_run_active_list_map(array $profileIds, array $cfg = []): array
{
    orders_sync_runs_table_ensure($cfg);
    orders_sync_recover_stale_runs($cfg);
    $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), static fn(int $id): bool => $id > 0)));
    if (!$profileIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
    $sql = "
        SELECT r.*, p.title AS profile_title, ms.title AS moysklad_title, c.title AS connection_title
        FROM feedtools_marketplace_order_sync_runs r
        LEFT JOIN feedtools_marketplace_sync_profiles p ON p.id = r.profile_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = r.moysklad_account_id
        LEFT JOIN feedtools_marketplace_connections c ON c.id = r.connection_id
        WHERE r.profile_id IN ({$placeholders})
          AND r.status IN ('running', 'stopping')
        ORDER BY r.profile_id ASC, r.id DESC
    ";
    $st = db()->prepare($sql);
    $st->execute($profileIds);
    $rows = $st->fetchAll() ?: [];
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['summary'] = orders_sync_decode_json_row($row['summary_json'] ?? null);
        $result[(int)($row['profile_id'] ?? 0)][] = $row;
    }
    return $result;
}

function orders_sync_run_active_map(array $profileIds, array $cfg = []): array
{
    $lists = orders_sync_run_active_list_map($profileIds, $cfg);
    $result = [];
    foreach ($lists as $profileId => $runs) {
        if (!is_array($runs) || !$runs) {
            continue;
        }
        $result[(int)$profileId] = $runs[0];
    }
    return $result;
}

function orders_sync_run_get(int $runId, array $cfg = []): ?array
{
    orders_sync_runs_table_ensure($cfg);
    if ($runId <= 0) {
        return null;
    }
    $st = db()->prepare("
        SELECT r.*, p.title AS profile_title, ms.title AS moysklad_title, c.title AS connection_title
        FROM feedtools_marketplace_order_sync_runs r
        LEFT JOIN feedtools_marketplace_sync_profiles p ON p.id = r.profile_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = r.moysklad_account_id
        LEFT JOIN feedtools_marketplace_connections c ON c.id = r.connection_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $st->execute([$runId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['summary'] = orders_sync_decode_json_row($row['summary_json'] ?? null);
    return $row;
}

function orders_sync_orders_recent(int $limit = 50, ?int $profileId = null, ?int $connectionId = null, ?string $orderSource = null, array $cfg = []): array
{
    orders_sync_orders_table_ensure($cfg);
    $limit = max(1, min(200, $limit));

    $sql = "
        SELECT s.*, p.title AS profile_title, ms.title AS moysklad_title, c.title AS connection_title
        FROM feedtools_marketplace_order_snapshots s
        LEFT JOIN feedtools_marketplace_sync_profiles p ON p.id = s.profile_id
        LEFT JOIN feedtools_moysklad_accounts ms ON ms.id = s.moysklad_account_id
        LEFT JOIN feedtools_marketplace_connections c ON c.id = s.connection_id
        WHERE 1=1
    ";
    $args = [];
    if (($profileId ?? 0) > 0) {
        $sql .= " AND s.profile_id = ?";
        $args[] = (int)$profileId;
    } elseif (($connectionId ?? 0) > 0) {
        $sql .= " AND s.connection_id = ?";
        $args[] = (int)$connectionId;
    }
    if ($orderSource !== null && trim($orderSource) !== '') {
        $sql .= " AND s.order_source = ?";
        $args[] = trim($orderSource);
    }
    $sql .= " ORDER BY s.synced_at DESC, s.id DESC LIMIT {$limit}";

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['payload'] = orders_sync_decode_json_row($row['payload_json'] ?? null);
    }
    unset($row);
    return $rows;
}

function orders_sync_status_totals(?int $profileId = null, ?int $connectionId = null, array $cfg = []): array
{
    orders_sync_orders_table_ensure($cfg);

    $sql = "
        SELECT order_source, status, COUNT(*) AS cnt
        FROM feedtools_marketplace_order_snapshots
        WHERE 1=1
    ";
    $args = [];
    if (($profileId ?? 0) > 0) {
        $sql .= " AND profile_id = ?";
        $args[] = (int)$profileId;
    } elseif (($connectionId ?? 0) > 0) {
        $sql .= " AND connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " GROUP BY order_source, status ORDER BY order_source ASC, cnt DESC, status ASC";

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];
    $totals = [];
    foreach ($rows as $row) {
        $source = (string)($row['order_source'] ?? '');
        $status = (string)($row['status'] ?? '');
        if ($source === '' || $status === '') {
            continue;
        }
        $totals[$source][$status] = (int)($row['cnt'] ?? 0);
    }
    return $totals;
}

function orders_sync_manual_sync_ozon_profile_start(int $profileId, ?string $actor = null, array $cfg = [], array $runtimeOverrides = [], string $runSource = 'manual'): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    $marketplace = orders_sync_marketplace_from_profile($profile);
    $marketplaceLabel = price_tool_marketplace_label($marketplace);
    $runKind = $marketplace === 'ozon' ? 'ozon_sync' : 'marketplace_sync';

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $marketplace) {
        throw new RuntimeException('Маркетплейс профиля не совпадает с выбранным подключением.');
    }

    $runtimeOverrides = orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
    $runtimeProfile = orders_sync_profile_with_runtime_overrides($profile, $runtimeOverrides);

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    $orderSources = orders_sync_marketplace_normalize_order_sources($runtimeProfile['order_sources'] ?? $runtimeProfile['order_sources_json'] ?? null, $marketplace);
    $period = orders_sync_profile_sync_period($runtimeProfile);
    $sinceUtc = (string)$period['since_utc'];
    $toUtc = (string)$period['to_utc'];

    $activeRun = orders_sync_run_active_conflict_for_profile((int)$profile['id'], $runKind, $cfg);
    if (is_array($activeRun)) {
        $activeSummary = is_array($activeRun['summary'] ?? null) ? $activeRun['summary'] : [];
        $activeSummary['run_id'] = (int)($activeRun['id'] ?? 0);
        $activeSummary['status'] = (string)($activeRun['status'] ?? '');
        $activeSummary['already_running'] = true;
        return $activeSummary;
    }

    $runId = orders_sync_run_create(
        (int)$profile['id'],
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $runSource,
        $actor,
        $sinceUtc,
        $toUtc,
        null,
        $cfg
    );
    $summary = [
        'kind' => $runKind,
        'run_id' => $runId,
        'profile_id' => (int)$profile['id'],
        'profile_title' => (string)($profile['title'] ?? ''),
        'connection_id' => $connectionId,
        'connection_title' => (string)($connection['title'] ?? ''),
        'moysklad_account_id' => $moyskladAccountId,
        'moysklad_title' => (string)($moyskladAccount['title'] ?? ''),
        'marketplace' => $marketplace,
        'runtime_overrides' => $runtimeOverrides,
        'sync_date_from' => (string)$period['date_from'],
        'sync_date_to' => (string)$period['date_to'],
        'since_utc' => $sinceUtc,
        'to_utc' => $toUtc,
        'sources' => array_fill_keys($orderSources, ['fetched' => 0, 'inserted' => 0, 'updated' => 0, 'error' => null]),
        'totals' => ['fetched' => 0, 'inserted' => 0, 'updated' => 0, 'errors' => 0],
    ];
    orders_sync_run_append($runId, "Run #{$runId}: поставлен в очередь на синхронизацию заказов {$marketplaceLabel}.\n");
    orders_sync_run_update($runId, 'running', $summary, $cfg);
    orders_sync_background_spawn($runKind, (int)$profile['id'], $runId, $actor, $cfg);
    return $summary;
}

function orders_sync_manual_sync_ozon_profile(int $profileId, ?string $actor = null, array $cfg = [], ?int $existingRunId = null, array $runtimeOverrides = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    $marketplace = orders_sync_marketplace_from_profile($profile);
    $marketplaceLabel = price_tool_marketplace_label($marketplace);
    $runKind = $marketplace === 'ozon' ? 'ozon_sync' : 'marketplace_sync';

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $marketplace) {
        throw new RuntimeException('Маркетплейс профиля не совпадает с выбранным подключением.');
    }

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;

    if (($existingRunId ?? 0) > 0 && !$runtimeOverrides) {
        $runtimeOverrides = orders_sync_run_runtime_overrides((int)$existingRunId, $cfg);
    }
    $runtimeOverrides = orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
    $runtimeProfile = orders_sync_profile_with_runtime_overrides($profile, $runtimeOverrides);

    $period = orders_sync_profile_sync_period($runtimeProfile);
    $sinceUtc = (string)$period['since_utc'];
    $toUtc = (string)$period['to_utc'];
    $orderSources = orders_sync_marketplace_normalize_order_sources($runtimeProfile['order_sources'] ?? $runtimeProfile['order_sources_json'] ?? null, $marketplace);

    $runId = ($existingRunId ?? 0) > 0
        ? (int)$existingRunId
        : orders_sync_run_create(
            (int)$profile['id'],
            $connectionId,
            $moyskladAccountId,
            $marketplace,
            'manual',
            $actor,
            $sinceUtc,
            $toUtc,
            null,
            $cfg
        );

    $summary = [
        'kind' => $runKind,
        'run_id' => $runId,
        'profile_id' => (int)$profile['id'],
        'profile_title' => (string)($profile['title'] ?? ''),
        'connection_id' => $connectionId,
        'connection_title' => (string)($connection['title'] ?? ''),
        'moysklad_account_id' => $moyskladAccountId,
        'moysklad_title' => (string)($moyskladAccount['title'] ?? ''),
        'marketplace' => $marketplace,
        'runtime_overrides' => $runtimeOverrides,
        'sync_date_from' => (string)$period['date_from'],
        'sync_date_to' => (string)$period['date_to'],
        'since_utc' => $sinceUtc,
        'to_utc' => $toUtc,
        'sources' => [],
        'totals' => [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ],
    ];

    $loggerBundle = orders_sync_run_buffered_logger($runId, 16);
    $logger = $loggerBundle['log'];
    $flushLogger = $loggerBundle['flush'];
    $shouldStop = orders_sync_run_stop_checker($runId, $cfg);

    try {
        $logger("Run #{$runId}: старт ручной синхронизации заказов {$marketplaceLabel}.\n");
        $logger("Profile #{$profile['id']}: " . (string)($profile['title'] ?? '') . "\n");
        $logger("Connection #{$connectionId}: " . (string)($connection['title'] ?? '') . "\n");
        $logger("MoySklad: " . ((string)($moyskladAccount['title'] ?? '') !== '' ? (string)$moyskladAccount['title'] : 'не выбран') . "\n");
        $flushLogger();
        orders_sync_run_update($runId, 'running', $summary, $cfg);
        $refresh = orders_sync_marketplace_refresh_profile_run(
            $runtimeProfile,
            $connection,
            $moyskladAccountId,
            $runId,
            $cfg,
            $logger,
            static function () use ($shouldStop): bool {
                return $shouldStop();
            }
        );
        $summary['sources'] = (array)($refresh['summary']['sources'] ?? []);
        $summary['totals'] = (array)($refresh['summary']['totals'] ?? $summary['totals']);
        $summary['sync_date_from'] = (string)($refresh['summary']['sync_date_from'] ?? $summary['sync_date_from']);
        $summary['sync_date_to'] = (string)($refresh['summary']['sync_date_to'] ?? $summary['sync_date_to']);
        $summary['since_utc'] = (string)($refresh['summary']['since_utc'] ?? $summary['since_utc']);
        $summary['to_utc'] = (string)($refresh['summary']['to_utc'] ?? $summary['to_utc']);
        if ($shouldStop(true)) {
            $summary['stopped'] = true;
            $logger("Run #{$runId}: получен запрос на остановку. Синхронизация прервана пользователем.\n");
        }
        $flushLogger();
        orders_sync_run_update($runId, 'running', $summary, $cfg);

        $successCount = 0;
        foreach ($summary['sources'] as $item) {
            if (empty($item['error'])) {
                $successCount++;
            }
        }

        $status = 'success';
        $errorText = null;
        if (!empty($summary['stopped'])) {
            $status = 'partial';
            $errorText = 'Синхронизация остановлена пользователем.';
        } elseif ($successCount === 0) {
            $status = 'error';
            $errorText = 'Не удалось получить ни один поток ' . $marketplaceLabel . ', заданный в профиле.';
        } elseif ($summary['totals']['errors'] > 0) {
            $status = 'partial';
            $errorText = 'Часть потоков ' . $marketplaceLabel . ' завершилась с ошибкой.';
        }

        $logger("Итог: status={$status}, fetched={$summary['totals']['fetched']}, inserted={$summary['totals']['inserted']}, updated={$summary['totals']['updated']}, errors={$summary['totals']['errors']}\n");
        $flushLogger();
        orders_sync_run_finish($runId, $status, $summary, $errorText, $cfg);

        $summary['status'] = $status;
        return $summary;
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        $summary['fatal_error'] = $e->getMessage();
        $logger("Фатальная ошибка: " . $e->getMessage() . "\n");
        $flushLogger();
        orders_sync_run_finish($runId, 'error', $summary, $e->getMessage(), $cfg);
        throw $e;
    }
}

function orders_sync_test_export_ozon_order(int $profileId, string $orderRef, ?string $actor = null, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    if ((string)($profile['marketplace'] ?? '') !== 'ozon') {
        throw new RuntimeException('Тестовый экспорт сейчас поддержан только для профилей Ozon.');
    }

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    if (!is_array($moyskladAccount)) {
        throw new RuntimeException('Для тестового экспорта сначала привяжи к профилю аккаунт МойСклад.');
    }

    $cfg = ozon_price_cfg_with_connection($cfg, $connection);
    [$source, $posting] = orders_sync_ozon_fetch_posting_by_reference($profileId, $orderRef, $cfg);
    $posting['_feedtools_source'] = $source;
    $posting['_feedtools_effective_status'] = orders_sync_ozon_effective_status($cfg, $source, $posting);

    orders_sync_save_ozon_postings(
        (int)$profile['id'],
        $connectionId,
        $moyskladAccountId,
        $source,
        [$posting],
        $cfg
    );

    return orders_sync_moysklad_export_posting(
        $profile,
        $connection,
        $moyskladAccount,
        $source,
        $posting,
        $cfg,
        'test'
    );
}

function orders_sync_test_export_marketplace_order(int $profileId, string $orderRef, ?string $actor = null, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }

    $marketplace = orders_sync_marketplace_from_profile($profile);
    if ($marketplace === 'ozon') {
        return orders_sync_test_export_ozon_order($profileId, $orderRef, $actor, $cfg);
    }

    $orderRef = trim($orderRef);
    if ($orderRef === '') {
        throw new RuntimeException('Укажи номер заказа ' . price_tool_marketplace_label($marketplace) . '.');
    }

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $marketplace) {
        throw new RuntimeException('Маркетплейс профиля не совпадает с выбранным подключением.');
    }

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    if (!is_array($moyskladAccount)) {
        throw new RuntimeException('Для тестового экспорта сначала привяжи к профилю аккаунт МойСклад.');
    }

    $orderSources = orders_sync_marketplace_normalize_order_sources($profile['order_sources'] ?? $profile['order_sources_json'] ?? null, $marketplace);
    $period = orders_sync_profile_sync_period($profile);
    $sinceUtc = (string)($period['since_utc'] ?? '');
    $toUtc = (string)($period['to_utc'] ?? '');

    $errors = [];
    foreach ($orderSources as $source) {
        try {
            $postings = $marketplace === 'yandex_market'
                ? orders_sync_yandex_fetch_all_orders($connection, $source, $sinceUtc, $toUtc, 50, 1000, $cfg, null)
                : orders_sync_wb_fetch_all_orders($connection, $source, $sinceUtc, $toUtc, 1000, 200, $cfg, null, (int)$profile['id']);
        } catch (Throwable $e) {
            $errors[] = strtoupper($source) . ': ' . $e->getMessage();
            continue;
        }
        foreach ($postings as $posting) {
            $matches = $marketplace === 'yandex_market'
                ? orders_sync_yandex_posting_matches_reference(is_array($posting) ? $posting : [], $orderRef)
                : orders_sync_wb_posting_matches_reference(is_array($posting) ? $posting : [], $orderRef);
            if (!is_array($posting) || !$matches) {
                continue;
            }
            $posting['_feedtools_source'] = $source;
            $posting['_feedtools_marketplace'] = $marketplace;
            $posting['_feedtools_effective_status'] = orders_sync_marketplace_effective_status($cfg, $marketplace, $source, $posting);
            orders_sync_save_marketplace_postings(
                $marketplace,
                (int)$profile['id'],
                $connectionId,
                $moyskladAccountId,
                $source,
                [$posting],
                $cfg
            );
            return orders_sync_moysklad_export_posting(
                $profile,
                $connection,
                $moyskladAccount,
                $source,
                $posting,
                $cfg,
                'test'
            );
        }
    }

    throw new RuntimeException(
        $errors
            ? 'Не удалось получить заказ из ' . price_tool_marketplace_label($marketplace) . ' за период профиля. ' . implode(' | ', $errors)
            : 'Не удалось найти заказ ' . price_tool_marketplace_label($marketplace) . ' за период профиля.'
    );
}

function orders_sync_run_dataset_count(int $runId, array $orderSources, string $selection = 'all', array $cfg = []): int
{
    orders_sync_order_history_table_ensure($cfg);
    orders_sync_orders_table_ensure($cfg);
    $runId = (int)$runId;
    if ($runId <= 0) {
        return 0;
    }
    $orderSources = array_values(array_filter(array_map(
        static fn($value): string => orders_sync_ozon_source_normalize((string)$value),
        $orderSources
    )));
    $orderSources = array_values(array_unique($orderSources));
    if (!$orderSources) {
        return 0;
    }

    $selection = orders_sync_run_dataset_selection_normalize($selection);
    if ($selection !== 'all') {
        orders_sync_exports_table_ensure($cfg);
    }
    $placeholders = implode(',', array_fill(0, count($orderSources), '?'));

    $selectionSql = orders_sync_run_dataset_selection_sql($selection, 's');
    $args = array_merge([$runId], $orderSources);
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM feedtools_marketplace_order_snapshots s
        {$selectionSql['join']}
        WHERE s.last_seen_run_id = ?
          AND s.order_source IN ({$placeholders})
          {$selectionSql['where']}
    ");
    $st->execute($args);
    $count = (int)($st->fetchColumn() ?: 0);
    if ($count > 0) {
        return $count;
    }

    $hasCurrentRowsStmt = db()->prepare("
        SELECT 1
        FROM feedtools_marketplace_order_snapshots s
        WHERE s.last_seen_run_id = ?
          AND s.order_source IN ({$placeholders})
        LIMIT 1
    ");
    $hasCurrentRowsStmt->execute(array_merge([$runId], $orderSources));
    if ($hasCurrentRowsStmt->fetchColumn()) {
        return 0;
    }

    // Fallback for old runs created before snapshots started storing last_seen_run_id.
    $selectionSql = orders_sync_run_dataset_selection_sql($selection, 'h');
    $args = array_merge([$runId], $orderSources);
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM feedtools_marketplace_order_snapshot_history h
        {$selectionSql['join']}
        WHERE h.run_id = ?
          AND h.order_source IN ({$placeholders})
          {$selectionSql['where']}
    ");
    $st->execute($args);
    return (int)($st->fetchColumn() ?: 0);
}

function orders_sync_run_dataset_rows(int $runId, array $orderSources, int $limit = 300, int $offset = 0, string $selection = 'all', array $cfg = []): array
{
    orders_sync_order_history_table_ensure($cfg);
    orders_sync_orders_table_ensure($cfg);
    $runId = (int)$runId;
    if ($runId <= 0) {
        return [];
    }
    $orderSources = array_values(array_filter(array_map(
        static fn($value): string => orders_sync_ozon_source_normalize((string)$value),
        $orderSources
    )));
    $orderSources = array_values(array_unique($orderSources));
    if (!$orderSources) {
        return [];
    }

    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    $selection = orders_sync_run_dataset_selection_normalize($selection);
    if ($selection !== 'all') {
        orders_sync_exports_table_ensure($cfg);
    }
    $placeholders = implode(',', array_fill(0, count($orderSources), '?'));

    $selectionSql = orders_sync_run_dataset_selection_sql($selection, 's');
    $args = array_merge([$runId], $orderSources);
    $sql = "
        SELECT s.*
        FROM feedtools_marketplace_order_snapshots s
        {$selectionSql['join']}
        WHERE s.last_seen_run_id = ?
          AND s.order_source IN ({$placeholders})
          {$selectionSql['where']}
        ORDER BY COALESCE(s.order_created_at, s.in_process_at, s.synced_at) DESC, s.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];
    if (!$rows) {
        $hasCurrentRowsStmt = db()->prepare("
            SELECT 1
            FROM feedtools_marketplace_order_snapshots s
            WHERE s.last_seen_run_id = ?
              AND s.order_source IN ({$placeholders})
            LIMIT 1
        ");
        $hasCurrentRowsStmt->execute(array_merge([$runId], $orderSources));
        if ($hasCurrentRowsStmt->fetchColumn()) {
            return [];
        }
        // Fallback for old runs created before snapshots started storing last_seen_run_id.
        $selectionSql = orders_sync_run_dataset_selection_sql($selection, 'h');
        $args = array_merge([$runId], $orderSources);
        $sql = "
            SELECT h.*
            FROM feedtools_marketplace_order_snapshot_history h
            {$selectionSql['join']}
            WHERE h.run_id = ?
              AND h.order_source IN ({$placeholders})
              {$selectionSql['where']}
            ORDER BY COALESCE(h.order_created_at, h.in_process_at, h.fetched_at) DESC, h.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll() ?: [];
    }
    foreach ($rows as &$row) {
        $row['payload'] = orders_sync_decode_json_row($row['payload_json'] ?? null);
    }
    unset($row);
    return $rows;
}

function orders_sync_ozon_refresh_profile_run(
    array $profile,
    array $connection,
    int $moyskladAccountId,
    int $runId,
    array $cfg = [],
    ?callable $logger = null,
    ?callable $shouldStop = null
): array {
    $cfg = orders_sync_cfg_fallback($cfg);
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($runtimeCfg);
    $orderSources = orders_sync_profile_normalize_order_sources($profile['order_sources'] ?? $profile['order_sources_json'] ?? null);
    $period = orders_sync_profile_sync_period($profile);
    $sinceUtc = (string)($period['since_utc'] ?? '');
    $toUtc = (string)($period['to_utc'] ?? '');

    $summary = [
        'sync_date_from' => (string)($period['date_from'] ?? ''),
        'sync_date_to' => (string)($period['date_to'] ?? ''),
        'since_utc' => $sinceUtc,
        'to_utc' => $toUtc,
        'client_id' => (string)($oz['client_id'] ?? ''),
        'sources' => [],
        'totals' => [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ],
    ];

    if ($logger) {
        $logger("Client ID: " . (string)($oz['client_id'] ?? '') . "\n");
        $logger("Период заказов: {$summary['sync_date_from']} .. {$summary['sync_date_to']}\n");
        $logger("UTC-фильтр: {$sinceUtc} .. {$toUtc}\n");
        $logger("Источники: " . implode(', ', array_map('strtoupper', $orderSources)) . "\n");
    }

    if ($runId > 0) {
        db()->prepare("
            DELETE FROM feedtools_marketplace_order_snapshot_history
            WHERE run_id = ? AND profile_id = ?
        ")->execute([$runId, (int)($profile['id'] ?? 0)]);
    }

    foreach ($orderSources as $source) {
        if ($shouldStop && $shouldStop()) {
            break;
        }
        $sourceSummary = [
            'source' => $source,
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'error' => null,
        ];

        try {
            if ($logger) {
                $logger("[" . strtoupper($source) . "] запрос списка отправлений...\n");
            }
            $postings = orders_sync_ozon_fetch_all_postings(
                $runtimeCfg,
                $source,
                $sinceUtc,
                $toUtc,
                100,
                50,
                static function (string $message) use ($logger, $source): void {
                    if ($logger) {
                        $logger("[" . strtoupper($source) . '] ' . $message);
                    }
                }
            );
            $sourceSummary['fetched'] = count($postings);
            $saveSummary = orders_sync_save_ozon_postings(
                (int)($profile['id'] ?? 0),
                (int)($connection['id'] ?? 0),
                $moyskladAccountId,
                $source,
                $postings,
                $runtimeCfg,
                $runId
            );
            $sourceSummary['inserted'] = (int)($saveSummary['inserted'] ?? 0);
            $sourceSummary['updated'] = (int)($saveSummary['updated'] ?? 0);
            if ($logger) {
                $logger("[" . strtoupper($source) . "] fetched={$sourceSummary['fetched']}, new={$sourceSummary['inserted']}, updated={$sourceSummary['updated']}\n");
            }
        } catch (Throwable $sourceError) {
            $sourceSummary['error'] = $sourceError->getMessage();
            $summary['totals']['errors']++;
            if ($logger) {
                $logger("[" . strtoupper($source) . "] ошибка: " . $sourceError->getMessage() . "\n");
            }
        }

        $summary['sources'][$source] = $sourceSummary;
        $summary['totals']['fetched'] += (int)$sourceSummary['fetched'];
        $summary['totals']['inserted'] += (int)$sourceSummary['inserted'];
        $summary['totals']['updated'] += (int)$sourceSummary['updated'];
    }

    try {
        $removedHistory = orders_sync_order_snapshot_history_cleanup_old($cfg);
        if ($removedHistory > 0 && $logger) {
            $logger("Очищена старая история снимков заказов: {$removedHistory}\n");
        }
    } catch (Throwable $cleanupError) {
        if ($logger) {
            $logger("Очистка старой истории снимков заказов не выполнена: " . $cleanupError->getMessage() . "\n");
        }
    }

    return [
        'runtime_cfg' => $runtimeCfg,
        'order_sources' => $orderSources,
        'summary' => $summary,
    ];
}

function orders_sync_marketplace_refresh_profile_run(
    array $profile,
    array $connection,
    int $moyskladAccountId,
    int $runId,
    array $cfg = [],
    ?callable $logger = null,
    ?callable $shouldStop = null
): array {
    $marketplace = orders_sync_marketplace_from_profile($profile);
    if ($marketplace === 'ozon') {
        return orders_sync_ozon_refresh_profile_run($profile, $connection, $moyskladAccountId, $runId, $cfg, $logger, $shouldStop);
    }

    $cfg = orders_sync_cfg_fallback($cfg);
    $orderSources = orders_sync_marketplace_normalize_order_sources($profile['order_sources'] ?? $profile['order_sources_json'] ?? null, $marketplace);
    $period = orders_sync_profile_sync_period($profile);
    $sinceUtc = (string)($period['since_utc'] ?? '');
    $toUtc = (string)($period['to_utc'] ?? '');

    $summary = [
        'sync_date_from' => (string)($period['date_from'] ?? ''),
        'sync_date_to' => (string)($period['date_to'] ?? ''),
        'since_utc' => $sinceUtc,
        'to_utc' => $toUtc,
        'client_id' => (string)($connection['client_id'] ?? ''),
        'sources' => [],
        'totals' => [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ],
    ];

    if ($logger) {
        $logger(($marketplace === 'yandex_market' ? 'Campaign ID' : 'Seller ID') . ': ' . ((string)($connection['client_id'] ?? '') !== '' ? (string)$connection['client_id'] : 'из токена') . "\n");
        $logger("Период заказов: {$summary['sync_date_from']} .. {$summary['sync_date_to']}\n");
        $logger("UTC-фильтр: {$sinceUtc} .. {$toUtc}\n");
        $logger("Источники: " . implode(', ', array_map('strtoupper', $orderSources)) . "\n");
    }

    if ($runId > 0) {
        db()->prepare("
            DELETE FROM feedtools_marketplace_order_snapshot_history
            WHERE run_id = ? AND profile_id = ?
        ")->execute([$runId, (int)($profile['id'] ?? 0)]);
    }

    foreach ($orderSources as $source) {
        if ($shouldStop && $shouldStop()) {
            break;
        }
        $sourceSummary = [
            'source' => $source,
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'error' => null,
        ];

        try {
            if ($logger) {
                $logger("[" . strtoupper($source) . "] запрос списка заказов " . price_tool_marketplace_label($marketplace) . "...\n");
            }
            $sourceLogger = static function (string $message) use ($logger, $source): void {
                if ($logger) {
                    $logger("[" . strtoupper($source) . '] ' . $message);
                }
            };
            if ($marketplace === 'yandex_market') {
                $postings = orders_sync_yandex_fetch_all_orders(
                    $connection,
                    $source,
                    $sinceUtc,
                    $toUtc,
                    50,
                    1000,
                    $cfg,
                    $sourceLogger
                );
            } else {
                $postings = orders_sync_wb_fetch_all_orders(
                    $connection,
                    $source,
                    $sinceUtc,
                    $toUtc,
                    1000,
                    200,
                    $cfg,
                    $sourceLogger,
                    (int)($profile['id'] ?? 0)
                );
            }
            $sourceSummary['fetched'] = count($postings);
            $saveSummary = orders_sync_save_marketplace_postings(
                $marketplace,
                (int)($profile['id'] ?? 0),
                (int)($connection['id'] ?? 0),
                $moyskladAccountId,
                $source,
                $postings,
                $cfg,
                $runId
            );
            $sourceSummary['inserted'] = (int)($saveSummary['inserted'] ?? 0);
            $sourceSummary['updated'] = (int)($saveSummary['updated'] ?? 0);
            if ($logger) {
                $logger("[" . strtoupper($source) . "] fetched={$sourceSummary['fetched']}, new={$sourceSummary['inserted']}, updated={$sourceSummary['updated']}\n");
            }
        } catch (Throwable $sourceError) {
            $sourceSummary['error'] = $sourceError->getMessage();
            $summary['totals']['errors']++;
            if ($logger) {
                $logger("[" . strtoupper($source) . "] ошибка: " . $sourceError->getMessage() . "\n");
            }
        }

        $summary['sources'][$source] = $sourceSummary;
        $summary['totals']['fetched'] += (int)$sourceSummary['fetched'];
        $summary['totals']['inserted'] += (int)$sourceSummary['inserted'];
        $summary['totals']['updated'] += (int)$sourceSummary['updated'];
    }

    try {
        $removedHistory = orders_sync_order_snapshot_history_cleanup_old($cfg);
        if ($removedHistory > 0 && $logger) {
            $logger("Очищена старая история снимков заказов: {$removedHistory}\n");
        }
    } catch (Throwable $cleanupError) {
        if ($logger) {
            $logger("Очистка старой истории снимков заказов не выполнена: " . $cleanupError->getMessage() . "\n");
        }
    }

    return [
        'runtime_cfg' => $cfg,
        'order_sources' => $orderSources,
        'summary' => $summary,
    ];
}

function orders_sync_manual_moysklad_operation_start(int $profileId, string $operation = 'full', ?string $actor = null, array $cfg = [], array $runtimeOverrides = [], string $runSource = 'manual'): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);
    $operation = orders_sync_moysklad_operation_normalize($operation);
    $kind = orders_sync_moysklad_operation_kind($operation);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    $marketplace = orders_sync_marketplace_from_profile($profile);

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $marketplace) {
        throw new RuntimeException('Маркетплейс профиля не совпадает с выбранным подключением.');
    }

    $runtimeOverrides = orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
    $runtimeProfile = orders_sync_profile_with_runtime_overrides($profile, $runtimeOverrides);

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    if (!is_array($moyskladAccount)) {
        throw new RuntimeException('Для массовой выгрузки сначала привяжи к профилю аккаунт МойСклад.');
    }

    $orderSources = orders_sync_marketplace_normalize_order_sources($runtimeProfile['order_sources'] ?? $runtimeProfile['order_sources_json'] ?? null, $marketplace);
    $period = orders_sync_profile_sync_period($runtimeProfile);
    $sinceUtc = (string)($period['since_utc'] ?? '');
    $toUtc = (string)($period['to_utc'] ?? '');

    $activeRun = orders_sync_run_active_conflict_for_profile((int)$profile['id'], $kind, $cfg);
    if (is_array($activeRun)) {
        $activeSummary = is_array($activeRun['summary'] ?? null) ? $activeRun['summary'] : [];
        $activeSummary['run_id'] = (int)($activeRun['id'] ?? 0);
        $activeSummary['status'] = (string)($activeRun['status'] ?? '');
        $activeSummary['already_running'] = true;
        return $activeSummary;
    }

    $runId = orders_sync_run_create(
        (int)$profile['id'],
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $runSource !== '' ? $runSource : $kind,
        $actor,
        $sinceUtc,
        $toUtc,
        null,
        $cfg
    );
    $summary = [
        'kind' => $kind,
        'operation' => $operation,
        'run_id' => $runId,
        'profile_id' => (int)$profile['id'],
        'profile_title' => (string)($profile['title'] ?? ''),
        'connection_id' => $connectionId,
        'connection_title' => (string)($connection['title'] ?? ''),
        'moysklad_account_id' => $moyskladAccountId,
        'moysklad_title' => (string)($moyskladAccount['title'] ?? ''),
        'marketplace' => $marketplace,
        'source_run_id' => $runId,
        'runtime_overrides' => $runtimeOverrides,
        'source_sync_period_from' => (string)($period['date_from'] ?? ''),
        'source_sync_period_to' => (string)($period['date_to'] ?? ''),
        'sources' => $orderSources,
        'refresh' => [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ],
        'totals' => orders_sync_moysklad_operation_totals_seed(),
    ];
    orders_sync_run_append($runId, "Run #{$runId}: " . orders_sync_moysklad_operation_queue_text($operation) . ".\n");
    orders_sync_run_update($runId, 'running', $summary, $cfg);
    orders_sync_background_spawn($kind, (int)$profile['id'], $runId, $actor, $cfg);
    return $summary;
}

function orders_sync_manual_moysklad_operation(int $profileId, string $operation = 'full', ?string $actor = null, array $cfg = [], ?int $existingRunId = null, array $runtimeOverrides = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);
    $operation = orders_sync_moysklad_operation_normalize($operation);
    $kind = orders_sync_moysklad_operation_kind($operation);
    $datasetSelection = orders_sync_moysklad_operation_dataset_selection($operation);
    $exportMode = orders_sync_moysklad_operation_export_mode($operation);

    $profile = orders_sync_profile_get($profileId, $cfg);
    if (!is_array($profile) || (int)($profile['id'] ?? 0) <= 0) {
        throw new RuntimeException('Профиль синхронизации не найден.');
    }
    $marketplace = orders_sync_marketplace_from_profile($profile);

    $connectionId = (int)($profile['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('У профиля не найден аккаунт маркетплейса.');
    }
    if (orders_sync_marketplace_normalize((string)($connection['marketplace'] ?? '')) !== $marketplace) {
        throw new RuntimeException('Маркетплейс профиля не совпадает с выбранным подключением.');
    }

    $moyskladAccountId = (int)($profile['moysklad_account_id'] ?? 0);
    $moyskladAccount = $moyskladAccountId > 0 ? orders_sync_moysklad_account_get($moyskladAccountId, $cfg) : null;
    if (!is_array($moyskladAccount)) {
        throw new RuntimeException('Для массовой выгрузки сначала привяжи к профилю аккаунт МойСклад.');
    }

    if (($existingRunId ?? 0) > 0 && !$runtimeOverrides) {
        $runtimeOverrides = orders_sync_run_runtime_overrides((int)$existingRunId, $cfg);
    }
    $runtimeOverrides = orders_sync_runtime_overrides_normalize($profile, $runtimeOverrides);
    $runtimeProfile = orders_sync_profile_with_runtime_overrides($profile, $runtimeOverrides);

    $orderSources = orders_sync_marketplace_normalize_order_sources($runtimeProfile['order_sources'] ?? $runtimeProfile['order_sources_json'] ?? null, $marketplace);
    $period = orders_sync_profile_sync_period($runtimeProfile);
    $sinceUtc = (string)($period['since_utc'] ?? '');
    $toUtc = (string)($period['to_utc'] ?? '');
    $runId = ($existingRunId ?? 0) > 0
        ? (int)$existingRunId
        : orders_sync_run_create(
            (int)$profile['id'],
            $connectionId,
            $moyskladAccountId,
            $marketplace,
            $kind,
            $actor,
            $sinceUtc,
            $toUtc,
            null,
            $cfg
        );
    $sourceRunId = $runId;

    $summary = [
        'kind' => $kind,
        'operation' => $operation,
        'run_id' => $runId,
        'profile_id' => (int)$profile['id'],
        'profile_title' => (string)($profile['title'] ?? ''),
        'connection_id' => $connectionId,
        'connection_title' => (string)($connection['title'] ?? ''),
        'moysklad_account_id' => $moyskladAccountId,
        'moysklad_title' => (string)($moyskladAccount['title'] ?? ''),
        'marketplace' => $marketplace,
        'source_run_id' => $sourceRunId,
        'runtime_overrides' => $runtimeOverrides,
        'source_sync_started_at' => '',
        'source_sync_period_from' => (string)($period['date_from'] ?? ''),
        'source_sync_period_to' => (string)($period['date_to'] ?? ''),
        'sources' => $orderSources,
        'refresh' => [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ],
        'totals' => orders_sync_moysklad_operation_totals_seed(),
    ];

    $loggerBundle = orders_sync_run_buffered_logger($runId, 24);
    $logger = $loggerBundle['log'];
    $flushLogger = $loggerBundle['flush'];
    $shouldStop = orders_sync_run_stop_checker($runId, $cfg);

    try {
        $logger("Run #{$runId}: " . orders_sync_moysklad_operation_start_text($operation) . ".\n");
        $logger("Profile #{$profile['id']}: " . (string)($profile['title'] ?? '') . "\n");
        $logger("Connection #{$connectionId}: " . (string)($connection['title'] ?? '') . "\n");
        $logger("MoySklad: " . (string)($moyskladAccount['title'] ?? '') . "\n");
        $logger("Перед операцией обновляю актуальные заказы " . price_tool_marketplace_label($marketplace) . " за период профиля.\n");
        if (($summary['source_sync_period_from'] ?? '') !== '' && ($summary['source_sync_period_to'] ?? '') !== '') {
            $logger("Период заказов: {$summary['source_sync_period_from']} .. {$summary['source_sync_period_to']}\n");
        }
        $flushLogger();
        orders_sync_run_update($runId, 'running', $summary, $cfg);

        $summary['source_sync_started_at'] = gmdate('Y-m-d H:i:s');
        $refresh = orders_sync_marketplace_refresh_profile_run(
            $runtimeProfile,
            $connection,
            $moyskladAccountId,
            $sourceRunId,
            $cfg,
            $logger,
            static function () use ($shouldStop): bool {
                return $shouldStop();
            }
        );
        $summary['refresh'] = (array)($refresh['summary']['totals'] ?? $summary['refresh']);
        $summary['refresh_sources'] = (array)($refresh['summary']['sources'] ?? []);
        $orderSources = (array)($refresh['order_sources'] ?? $orderSources);
        $summary['sources'] = $orderSources;
        if ($shouldStop(true)) {
            $summary['stopped'] = true;
            $logger("Run #{$runId}: получен запрос на остановку. Выгрузка прервана пользователем.\n");
        }
        $flushLogger();
        orders_sync_run_update($runId, 'running', $summary, $cfg);

        $batchSize = 200;
        $effectiveDatasetSelection = $datasetSelection;
        $totalCandidates = orders_sync_run_dataset_count($sourceRunId, $orderSources, $effectiveDatasetSelection, $cfg);
        if ($operation === 'status_only') {
            $summary['totals']['linked_candidates'] = $totalCandidates;
            $allCandidates = orders_sync_run_dataset_count($sourceRunId, $orderSources, 'all', $cfg);
            $summary['totals']['all_candidates'] = $allCandidates;
            if ($allCandidates > 0 && $totalCandidates < $allCandidates) {
                $effectiveDatasetSelection = 'all';
                $totalCandidates = $allCandidates;
                $logger(
                    "Связанный marker-слой покрывает только {$summary['totals']['linked_candidates']} из {$allCandidates} заказов. " .
                    "Запускаю сверку по всем заказам периода, чтобы не пропустить статусы.\n"
                );
            }
        }
        $summary['totals']['scanned'] = $totalCandidates;
        $hasRefreshErrors = (int)($summary['refresh']['errors'] ?? 0) > 0;
        if ($hasRefreshErrors) {
            $logger(
                'Внимание: часть источников заказов не удалось получить. ' .
                'Пустой список кандидатов не будет считаться успешной сверкой.' . "\n"
            );
        }
        $logger(match ($operation) {
            'create_only' => 'Найдено кандидатов на создание новых заказов: ' . $totalCandidates . "\n",
            'status_only' => 'Найдено заказов для проверки и обновления статусов: ' . $totalCandidates . ($effectiveDatasetSelection === 'all' ? ' (включая восстановление связей)' : '') . "\n",
            default => 'Найдено актуальных заказов для выгрузки: ' . $totalCandidates . "\n",
        });
        orders_sync_run_update($runId, 'running', $summary, $cfg);

        if ($totalCandidates <= 0 && empty($summary['stopped'])) {
            if ($hasRefreshErrors) {
                $summary['refresh_failed'] = true;
                $errorText = 'Не удалось получить актуальный список заказов ' . price_tool_marketplace_label($marketplace) . '; выгрузка в МойСклад не запускалась, чтобы не принять ошибку за отсутствие заказов.';
                $logger($errorText . "\n");
                $flushLogger();
                orders_sync_run_finish($runId, 'error', $summary, $errorText, $cfg);
                $summary['status'] = 'error';
                return $summary;
            }
            $emptyMessage = match ($operation) {
                'create_only' => 'Для этого профиля нет новых заказов для создания в МойСклад.',
                'status_only' => 'Для этого профиля нет заказов, у которых можно обновить статусы в МойСклад.',
                default => 'Для этого профиля пока нет актуальных заказов для выгрузки.',
            };
            $summary['no_work'] = true;
            $summary['no_work_message'] = $emptyMessage;
            $logger($emptyMessage . "\n");
            $flushLogger();
            orders_sync_run_finish($runId, 'success', $summary, null, $cfg);
            $summary['status'] = 'success';
            return $summary;
        }

        $flushEveryOrders = 10;
        $flushEverySeconds = 3.0;
        $lastProgressFlushAt = microtime(true);
        $ordersSinceProgressFlush = 0;
        for ($offset = 0; empty($summary['stopped']) && $offset < $totalCandidates; $offset += $batchSize) {
            if ($shouldStop(true)) {
                $summary['stopped'] = true;
                $logger("Run #{$runId}: получен запрос на остановку. Выгрузка прервана пользователем.\n");
                break;
            }
            $candidates = orders_sync_run_dataset_rows($sourceRunId, $orderSources, $batchSize, $offset, $effectiveDatasetSelection, $cfg);
            if (!$candidates) {
                break;
            }
            $existingLinksByPosting = orders_sync_export_links_map_for_scope(
                (int)$profile['id'],
                $connectionId,
                $moyskladAccountId,
                $marketplace,
                $candidates,
                $cfg
            );
            $logger('Пакет выгрузки: offset=' . $offset . ', size=' . count($candidates) . "\n");

            foreach ($candidates as $row) {
                if ($shouldStop()) {
                    $summary['stopped'] = true;
                    $logger("Run #{$runId}: получен запрос на остановку. Выгрузка прервана пользователем.\n");
                    break 2;
                }
                $source = orders_sync_ozon_source_normalize((string)($row['order_source'] ?? 'fbs'));
                $postingNumber = trim((string)($row['posting_number'] ?? ''));
                $posting = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                if ($posting) {
                    $posting['_feedtools_source'] = $source;
                    $posting['_feedtools_existing_link'] = $existingLinksByPosting[orders_sync_export_link_key($source, $postingNumber)] ?? null;
                }
                $orderOrdinal = (int)($summary['totals']['processed'] ?? 0) + 1;
                $orderPrefix = '[' . $orderOrdinal . '/' . $totalCandidates . '][' . strtoupper($source) . '] ';

                try {
                    if (!$posting || $postingNumber === '') {
                        throw new RuntimeException('В сохранённом заказе этого запуска отсутствует payload.');
                    }
                    $logger($orderPrefix . $postingNumber . ' -> processing' . "\n");
                    $flushLogger();
                    $result = orders_sync_moysklad_export_posting(
                        $profile,
                        $connection,
                        $moyskladAccount,
                        $source,
                        $posting,
                        $cfg,
                        $exportMode,
                        $operation
                    );
                    $mode = (string)($result['mode'] ?? 'created');
                    if ($mode === 'created') {
                        $summary['totals']['created']++;
                    } elseif ($mode === 'linked_existing') {
                        $summary['totals']['linked']++;
                    } elseif ($mode === 'updated') {
                        $summary['totals']['updated']++;
                    } elseif ($mode === 'restored') {
                        $summary['totals']['restored']++;
                    } elseif ($mode === 'recreated') {
                        $summary['totals']['recreated']++;
                    } elseif (in_array($mode, ['skipped_keep', 'skipped_same_state', 'skipped_unchanged', 'skipped_exists', 'skipped_missing'], true)) {
                        $summary['totals']['skipped']++;
                    } else {
                        $summary['totals']['skipped']++;
                    }
                    $logger($orderPrefix . $postingNumber . ' -> ' . orders_sync_test_export_mode_label($mode) . "\n");
                } catch (Throwable $itemError) {
                    $summary['totals']['errors']++;
                    $logger($orderPrefix . ($postingNumber !== '' ? $postingNumber : '—') . ' -> ошибка: ' . $itemError->getMessage() . "\n");
                }

                $summary['totals']['processed']++;
                $ordersSinceProgressFlush++;
                $shouldFlushProgress = $ordersSinceProgressFlush >= $flushEveryOrders
                    || (microtime(true) - $lastProgressFlushAt) >= $flushEverySeconds;
                if ($shouldFlushProgress) {
                    $flushLogger();
                    orders_sync_run_update($runId, 'running', $summary, $cfg);
                    $ordersSinceProgressFlush = 0;
                    $lastProgressFlushAt = microtime(true);
                }
            }
            $flushLogger();
            orders_sync_run_update($runId, 'running', $summary, $cfg);
            $ordersSinceProgressFlush = 0;
            $lastProgressFlushAt = microtime(true);
        }

        $status = ($summary['totals']['errors'] > 0 || $hasRefreshErrors) ? 'partial' : 'success';
        $errorText = null;
        if ($summary['totals']['errors'] > 0) {
            $errorText = match ($operation) {
                'create_only' => 'Часть новых заказов не удалось создать в МойСклад.',
                'status_only' => 'Часть статусов не удалось обновить в МойСклад.',
                default => 'Часть заказов не удалось выгрузить в МойСклад.',
            };
        } elseif ($hasRefreshErrors) {
            $errorText = 'Часть актуальных заказов ' . price_tool_marketplace_label($marketplace) . ' не удалось получить перед запуском операции.';
        }
        if (!empty($summary['stopped'])) {
            $status = 'partial';
            $errorText = 'Операция для МойСклад остановлена пользователем.';
        } elseif (
            $summary['totals']['created'] === 0
            && $summary['totals']['linked'] === 0
            && $summary['totals']['updated'] === 0
            && $summary['totals']['restored'] === 0
            && $summary['totals']['recreated'] === 0
            && $summary['totals']['skipped'] === 0
        ) {
            $status = 'error';
            $errorText = match ($operation) {
                'create_only' => 'Не удалось создать или привязать ни один новый заказ в МойСклад.',
                'status_only' => 'Не удалось обновить статус ни у одного заказа в МойСклад.',
                default => 'Не удалось обработать ни один заказ для выгрузки в МойСклад.',
            };
        }

        $logger(
            "Итог: status={$status}, scanned={$summary['totals']['scanned']}, created={$summary['totals']['created']}, linked={$summary['totals']['linked']}, updated={$summary['totals']['updated']}, restored={$summary['totals']['restored']}, recreated={$summary['totals']['recreated']}, skipped={$summary['totals']['skipped']}, errors={$summary['totals']['errors']}\n"
        );
        $flushLogger();
        orders_sync_run_finish($runId, $status, $summary, $errorText, $cfg);
        $summary['status'] = $status;
        return $summary;
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        $summary['fatal_error'] = $e->getMessage();
        $logger("Фатальная ошибка: " . $e->getMessage() . "\n");
        $flushLogger();
        orders_sync_run_finish($runId, 'error', $summary, $e->getMessage(), $cfg);
        throw $e;
    }
}

function orders_sync_manual_export_moysklad_profile_start(int $profileId, ?string $actor = null, array $cfg = [], array $runtimeOverrides = [], string $runSource = 'manual'): array
{
    return orders_sync_manual_moysklad_operation_start($profileId, 'full', $actor, $cfg, $runtimeOverrides, $runSource);
}

function orders_sync_manual_create_moysklad_profile_start(int $profileId, ?string $actor = null, array $cfg = [], array $runtimeOverrides = [], string $runSource = 'manual'): array
{
    return orders_sync_manual_moysklad_operation_start($profileId, 'create_only', $actor, $cfg, $runtimeOverrides, $runSource);
}

function orders_sync_manual_update_statuses_moysklad_profile_start(int $profileId, ?string $actor = null, array $cfg = [], array $runtimeOverrides = [], string $runSource = 'manual'): array
{
    return orders_sync_manual_moysklad_operation_start($profileId, 'status_only', $actor, $cfg, $runtimeOverrides, $runSource);
}

function orders_sync_manual_export_moysklad_profile(int $profileId, ?string $actor = null, array $cfg = [], ?int $existingRunId = null, array $runtimeOverrides = []): array
{
    return orders_sync_manual_moysklad_operation($profileId, 'full', $actor, $cfg, $existingRunId, $runtimeOverrides);
}

function orders_sync_manual_create_moysklad_profile(int $profileId, ?string $actor = null, array $cfg = [], ?int $existingRunId = null, array $runtimeOverrides = []): array
{
    return orders_sync_manual_moysklad_operation($profileId, 'create_only', $actor, $cfg, $existingRunId, $runtimeOverrides);
}

function orders_sync_manual_update_statuses_moysklad_profile(int $profileId, ?string $actor = null, array $cfg = [], ?int $existingRunId = null, array $runtimeOverrides = []): array
{
    return orders_sync_manual_moysklad_operation($profileId, 'status_only', $actor, $cfg, $existingRunId, $runtimeOverrides);
}

function orders_sync_automation_mark_started(int $automationId, string $slotKey, int $runId, array $cfg = []): void
{
    orders_sync_automation_table_ensure($cfg);
    if ($automationId <= 0) {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_marketplace_sync_profile_automations
        SET last_run_at = NOW(), last_run_slot_key = ?, last_run_run_id = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $st->execute([$slotKey, $runId > 0 ? $runId : null, $automationId]);
}

function orders_sync_automation_run_due(callable $log, ?int $profileId = null, ?int $connectionId = null, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    orders_sync_module_bootstrap($cfg);
    $recoveredRuns = orders_sync_recover_stale_runs($cfg);
    if ($recoveredRuns > 0) {
        $log('[recovery] closed stale Orders Sync runs: ' . $recoveredRuns . "\n");
    }

    $sql = "
        SELECT a.*
        FROM feedtools_marketplace_sync_profile_automations a
        INNER JOIN feedtools_marketplace_sync_profiles p ON p.id = a.profile_id
        INNER JOIN feedtools_marketplace_connections c ON c.id = p.connection_id
        WHERE a.enabled = 1
          AND p.is_active = 1
          AND c.is_active = 1
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
    $summary = [
        'now_msk' => $nowMsk->format('Y-m-d H:i:s'),
        'checked' => 0,
        'queued' => 0,
        'skipped' => 0,
        'items' => [],
    ];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $profile = orders_sync_profile_get((int)($row['profile_id'] ?? 0), $cfg);
        if (!is_array($profile)) {
            continue;
        }
        $automation = orders_sync_automation_hydrate($row, $profile);
        $summary['checked']++;
        $item = [
            'automation_id' => (int)($automation['id'] ?? 0),
            'profile_id' => (int)($profile['id'] ?? 0),
            'profile_title' => (string)($profile['title'] ?? ''),
            'operation_key' => (string)($automation['operation_key'] ?? ''),
            'status' => 'skipped',
            'message' => '',
            'run_id' => null,
        ];

        $slot = orders_sync_automation_slot_info($automation, $nowMsk);
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

        $lastAutomationRunId = (int)($automation['last_run_run_id'] ?? 0);
        if ($lastAutomationRunId > 0) {
            $lastAutomationRun = orders_sync_run_get($lastAutomationRunId, $cfg);
            $lastAutomationRunStatus = trim((string)($lastAutomationRun['status'] ?? ''));
            if (in_array($lastAutomationRunStatus, ['running', 'stopping'], true)) {
                $item['message'] = 'Предыдущий запуск этой автоматизации ещё не завершился.';
                $item['status'] = 'busy';
                $item['run_id'] = $lastAutomationRunId;
                $summary['skipped']++;
                $summary['items'][] = $item;
                $log('[automation #' . (int)($automation['id'] ?? 0) . "] waiting previous run #" . $lastAutomationRunId . "\n");
                continue;
            }
        }

        $operation = orders_sync_moysklad_operation_normalize((string)($automation['operation_key'] ?? 'full'));
        $requestedKind = match ($operation) {
            'create_only' => 'moysklad_create_orders',
            'status_only' => 'moysklad_update_statuses',
            default => 'moysklad_export',
        };
        $activeRun = orders_sync_run_active_conflict_for_profile((int)($profile['id'] ?? 0), $requestedKind, $cfg);
        if (is_array($activeRun)) {
            $item['message'] = 'По профилю уже идёт другой запуск.';
            $item['status'] = 'busy';
            $item['run_id'] = (int)($activeRun['id'] ?? 0);
            $summary['skipped']++;
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . "] busy run #" . (int)($activeRun['id'] ?? 0) . "\n");
            continue;
        }

        $runtimeOverrides = orders_sync_automation_runtime_overrides($profile, $automation);

        try {
            $startSummary = match ($operation) {
                'create_only' => orders_sync_manual_create_moysklad_profile_start((int)$profile['id'], 'automation', $cfg, $runtimeOverrides, 'automation'),
                'status_only' => orders_sync_manual_update_statuses_moysklad_profile_start((int)$profile['id'], 'automation', $cfg, $runtimeOverrides, 'automation'),
                default => orders_sync_manual_export_moysklad_profile_start((int)$profile['id'], 'automation', $cfg, $runtimeOverrides, 'automation'),
            };
            $runId = (int)($startSummary['run_id'] ?? 0);
            if ($runId > 0) {
                orders_sync_automation_mark_started((int)($automation['id'] ?? 0), (string)$slot['slot_key'], $runId, $cfg);
            }
            $item['status'] = !empty($startSummary['already_running']) ? 'busy' : 'queued';
            $item['message'] = !empty($startSummary['already_running']) ? 'Профиль уже выполняет запуск.' : 'Запуск поставлен в очередь.';
            $item['run_id'] = $runId > 0 ? $runId : null;
            if ($item['status'] === 'queued') {
                $summary['queued']++;
            } else {
                $summary['skipped']++;
            }
            $summary['items'][] = $item;
            $log('[automation #' . (int)($automation['id'] ?? 0) . '] queued run #' . $runId . ' (' . $operation . ")\n");
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

function orders_sync_ozon_fetch_posting_by_reference(int $profileId, string $orderRef, array $cfg): array
{
    $orderRef = trim($orderRef);
    if ($orderRef === '') {
        throw new RuntimeException('Укажи номер заказа Ozon или posting number.');
    }

    $looksLikePosting = (bool)preg_match('~-\d+$~', $orderRef);
    $errors = [];

    if ($looksLikePosting) {
        foreach (['fbs', 'fbo'] as $source) {
            try {
                return [$source, orders_sync_ozon_get_posting($cfg, $source, $orderRef)];
            } catch (Throwable $e) {
                $errors[] = strtoupper($source) . ': ' . $e->getMessage();
            }
        }
    }

    $candidates = orders_sync_posting_candidates_by_order_number($profileId, $orderRef, $cfg);
    foreach ($candidates as $candidate) {
        $source = (string)($candidate['order_source'] ?? 'fbs');
        $postingNumber = trim((string)($candidate['posting_number'] ?? ''));
        if ($postingNumber === '') {
            continue;
        }
        try {
            return [$source, orders_sync_ozon_get_posting($cfg, $source, $postingNumber)];
        } catch (Throwable $e) {
            $errors[] = strtoupper($source) . ' ' . $postingNumber . ': ' . $e->getMessage();
        }
    }

    throw new RuntimeException(
        $errors
            ? 'Не удалось получить заказ из Ozon. ' . implode(' | ', $errors)
            : 'Не удалось найти подходящий posting number для указанного номера заказа.'
    );
}

function orders_sync_posting_candidates_by_order_number(int $profileId, string $orderNumber, array $cfg = []): array
{
    orders_sync_orders_table_ensure($cfg);
    $orderNumber = trim($orderNumber);
    if ($profileId <= 0 || $orderNumber === '') {
        return [];
    }
    $st = db()->prepare("
        SELECT posting_number, order_source, synced_at
        FROM feedtools_marketplace_order_snapshots
        WHERE profile_id = ? AND order_number = ?
        ORDER BY synced_at DESC, id DESC
        LIMIT 20
    ");
    $st->execute([$profileId, $orderNumber]);
    return $st->fetchAll() ?: [];
}

function orders_sync_ozon_get_posting(array $cfg, string $source, string $postingNumber): array
{
    $source = orders_sync_ozon_source_normalize($source);
    $postingNumber = trim($postingNumber);
    if ($postingNumber === '') {
        throw new RuntimeException('Пустой posting number.');
    }

    $path = $source === 'fbo' ? '/v2/posting/fbo/get' : '/v3/posting/fbs/get';
    $payload = [
        'posting_number' => $postingNumber,
        'with' => [
            'analytics_data' => true,
            'financial_data' => false,
        ],
    ];
    if ($source === 'fbs') {
        $payload['with']['barcodes'] = true;
    }

    $response = ozon_post_json(ozon_cfg_or_fail($cfg), $path, $payload);
    $result = $response['result'] ?? null;
    if (!is_array($result) || trim((string)($result['posting_number'] ?? '')) === '') {
        $message = trim((string)($response['message'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : 'Ozon не вернул данные по отправлению.');
    }
    return $result;
}

function orders_sync_moysklad_request(array $account, string $method, string $path, ?array $payload = null, array $query = []): array
{
    static $memoized = [];

    $baseUrl = orders_sync_moysklad_base_url_normalize((string)($account['base_url'] ?? ''));
    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $method = strtoupper($method);
    $memoKey = $method === 'GET' && $payload === null
        ? sha1(json_encode([
            'account_id' => (int)($account['id'] ?? 0),
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        : '';
    if ($memoKey !== '' && isset($memoized[$memoKey])) {
        return $memoized[$memoKey];
    }

    $headers = [
        'Authorization: Bearer ' . (string)($account['api_token'] ?? ''),
        'Accept: application/json;charset=utf-8',
        'Accept-Encoding: gzip',
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $body = $payload !== null
        ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
    if ($payload !== null && !is_string($body)) {
        throw new RuntimeException('Не удалось сериализовать payload для МойСклад.');
    }
    $maxAttempts = 6;
    $attempt = 0;
    while (true) {
        $attempt++;
        orders_sync_moysklad_throttle($account);
        $ch = curl_init($url);
        if (!is_resource($ch) && !($ch instanceof CurlHandle)) {
            throw new RuntimeException('Не удалось инициализировать запрос к МойСклад.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        $transientHttp = in_array($httpCode, [408, 429, 500, 502, 503, 504], true);
        if (($curlError !== '' || $transientHttp) && $attempt < $maxAttempts) {
            $delayMs = $httpCode === 429
                ? min(20000, 1500 * $attempt * $attempt)
                : min(8000, 400 * $attempt * $attempt);
            usleep($delayMs * 1000);
            continue;
        }

        if ($curlError !== '') {
            $suffix = $attempt > 1 ? ' после ' . $attempt . ' попыток' : '';
            throw new RuntimeException('Ошибка запроса к МойСклад' . $suffix . ': ' . $curlError);
        }

        if (!is_string($raw) || trim($raw) === '') {
            if ($httpCode >= 200 && $httpCode < 300) {
                return [];
            }
            throw new RuntimeException('МойСклад вернул пустой ответ с HTTP ' . $httpCode . '.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Не удалось разобрать ответ МойСклад (HTTP ' . $httpCode . ').');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $message = 'МойСклад вернул HTTP ' . $httpCode . '.';
            if ($errors) {
                $first = is_array($errors[0] ?? null) ? $errors[0] : [];
                $message .= ' ' . trim((string)($first['error'] ?? ''));
                $details = trim((string)($first['error_message'] ?? $first['errorMessage'] ?? ''));
                if ($details !== '') {
                    $message .= ' ' . $details;
                }
            }
            if ($attempt > 1 && $transientHttp) {
                $message .= ' После ' . $attempt . ' попыток.';
            }
            throw new RuntimeException(trim($message));
        }

        if ($memoKey !== '') {
            $memoized[$memoKey] = $decoded;
        }

        return $decoded;
    }
}

function orders_sync_moysklad_default_organization(array $account): array
{
    static $cache = [];
    $cacheKey = (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $response = orders_sync_moysklad_request($account, 'GET', 'entity/organization', null, ['limit' => 1]);
    $row = is_array($response['rows'][0] ?? null) ? $response['rows'][0] : null;
    if (!is_array($row) || trim((string)($row['id'] ?? '')) === '') {
        throw new RuntimeException('В аккаунте МойСклад не найдена ни одна организация.');
    }
    return $cache[$cacheKey] = $row;
}

function orders_sync_moysklad_find_entity_by_id(array $account, string $path, string $id): ?array
{
    static $cache = [];

    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $cacheKey = sha1(json_encode([
        'account_id' => (int)($account['id'] ?? 0),
        'path' => trim($path, '/'),
        'id' => $id,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        $response = orders_sync_moysklad_request($account, 'GET', trim($path, '/') . '/' . $id);
    } catch (Throwable $e) {
        return $cache[$cacheKey] = null;
    }
    return $cache[$cacheKey] = (is_array($response) && trim((string)($response['id'] ?? '')) !== '' ? $response : null);
}

function orders_sync_moysklad_customerorder_states(array $account): array
{
    static $cache = [];
    $cacheKey = (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $uiCacheKey = 'moysklad:customerorder-states:' . sha1(json_encode([
        'account_id' => (int)($account['id'] ?? 0),
        'base_url' => orders_sync_moysklad_base_url_normalize((string)($account['base_url'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $response = orders_sync_ui_cache_read($uiCacheKey, 300);
    if (!is_array($response)) {
        try {
            $response = orders_sync_moysklad_request($account, 'GET', 'entity/customerorder/metadata');
            orders_sync_ui_cache_write($uiCacheKey, $response);
        } catch (Throwable $e) {
            $response = orders_sync_ui_cache_read($uiCacheKey, 300, true);
            if (!is_array($response)) {
                throw $e;
            }
        }
    }
    $states = is_array($response['states'] ?? null) ? $response['states'] : [];
    return $cache[$cacheKey] = $states;
}

function orders_sync_moysklad_customerorder_state_options(array $account): array
{
    static $cache = [];
    $cacheKey = 'customerorder-states:' . (string)($account['id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    return $cache[$cacheKey] = orders_sync_moysklad_options_normalize(
        orders_sync_moysklad_customerorder_states($account)
    );
}

function orders_sync_moysklad_find_state(array $account, string $stateName): ?array
{
    $needle = mb_strtolower(trim($stateName), 'UTF-8');
    if ($needle === '') {
        return null;
    }
    foreach (orders_sync_moysklad_customerorder_states($account) as $state) {
        if (!is_array($state)) {
            continue;
        }
        if (mb_strtolower(trim((string)($state['name'] ?? '')), 'UTF-8') === $needle) {
            return $state;
        }
    }
    return null;
}

function orders_sync_moysklad_find_state_by_id(array $account, string $stateId): ?array
{
    return orders_sync_moysklad_option_raw_by_id(
        orders_sync_moysklad_customerorder_state_options($account),
        $stateId
    );
}

function orders_sync_ozon_status_labels_known(): array
{
    return [
        'awaiting_packaging' => 'Ожидает упаковки',
        'acceptance_in_progress' => 'Идёт приёмка',
        'awaiting_registration' => 'Ожидает регистрации',
        'acceptance_not_allowed' => 'Приёмка недоступна',
        'awaiting_deliver' => 'Ожидает отгрузки',
        'ready_to_ship' => 'Готов к отгрузке',
        'sent_by_seller' => 'Отправлен продавцом',
        'delivering' => 'Доставляется',
        'driver_pickup' => 'Забирает курьер',
        'last_mile' => 'Последняя миля',
        'in_transit' => 'В пути',
        'delivered' => 'Доставлен',
        'cancelled' => 'Отменён',
        'returning_to_seller' => 'Возвращается продавцу',
        'return_in_progress' => 'Возврат в процессе',
        'returned_to_seller' => 'Возвращён продавцу',
        'returned' => 'Возвращён',
    ];
}

function orders_sync_ozon_return_status_codes(): array
{
    return [
        'returning_to_seller',
        'return_in_progress',
        'returned_to_seller',
        'returned',
    ];
}

function orders_sync_ozon_return_scenario_catalog(): array
{
    return [
        [
            'code' => 'return_to_ozon_in_transit',
            'label' => 'Отменён / возврат, едет на склад Ozon',
        ],
        [
            'code' => 'return_to_seller_in_transit',
            'label' => 'Отменён / возврат, едет продавцу',
        ],
        [
            'code' => 'return_to_seller_awaiting_pickup',
            'label' => 'Отменён / возврат, ожидает, чтобы продавец забрал его',
        ],
        [
            'code' => 'return_to_ozon_arrived',
            'label' => 'Отменён / возврат, приехал на склад Ozon',
        ],
        [
            'code' => 'return_to_seller_received',
            'label' => 'Отменён / возврат, доставлен и получен продавцом',
        ],
    ];
}

function orders_sync_ozon_return_scenario_labels(): array
{
    $labels = [];
    foreach (orders_sync_ozon_return_scenario_catalog() as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = strtolower(trim((string)($item['code'] ?? '')));
        $label = trim((string)($item['label'] ?? ''));
        if ($code === '' || $label === '') {
            continue;
        }
        $labels[$code] = $label;
    }
    return $labels;
}

function orders_sync_ozon_fbo_only_status_codes(): array
{
    return [
        'acceptance_in_progress',
        'awaiting_registration',
        'acceptance_not_allowed',
    ];
}

function orders_sync_ozon_status_is_fbo_only(string $status): bool
{
    $status = strtolower(trim($status));
    return $status !== '' && in_array($status, orders_sync_ozon_fbo_only_status_codes(), true);
}

function orders_sync_ozon_status_is_return(string $status): bool
{
    $status = strtolower(trim($status));
    return $status !== '' && in_array($status, orders_sync_ozon_return_status_codes(), true);
}

function orders_sync_ozon_status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return '—';
    }
    $returnScenarioLabels = orders_sync_ozon_return_scenario_labels();
    if (isset($returnScenarioLabels[$status])) {
        return $returnScenarioLabels[$status];
    }
    $known = orders_sync_ozon_status_labels_known();
    if (isset($known[$status])) {
        return $known[$status];
    }
    return mb_convert_case(str_replace('_', ' ', $status), MB_CASE_TITLE, 'UTF-8');
}

function orders_sync_ozon_status_catalog(?int $connectionId = null, array $cfg = []): array
{
    $cfg = orders_sync_cfg_fallback($cfg);
    $known = orders_sync_ozon_status_labels_known();
    $items = [];
    foreach ($known as $code => $label) {
        $items[$code] = [
            'code' => $code,
            'label' => $label,
        ];
    }

    orders_sync_orders_table_ensure($cfg);
    $sql = "
        SELECT DISTINCT status
        FROM feedtools_marketplace_order_snapshots
        WHERE marketplace = 'ozon' AND status <> ''
    ";
    $args = [];
    if (($connectionId ?? 0) > 0) {
        $sql .= " AND connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY status ASC";
    $st = db()->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll() ?: [] as $row) {
        $code = strtolower(trim((string)($row['status'] ?? '')));
        if ($code === '' || isset($items[$code])) {
            continue;
        }
        $items[$code] = [
            'code' => $code,
            'label' => orders_sync_ozon_status_label($code),
        ];
    }

    return array_values($items);
}

function orders_sync_ozon_status_catalog_grouped(?int $connectionId = null, array $cfg = []): array
{
    $catalog = orders_sync_ozon_status_catalog($connectionId, $cfg);
    $result = [
        'order' => [],
        'fbo_only' => [],
        'return' => [],
    ];
    foreach ($catalog as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = strtolower(trim((string)($item['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        if (orders_sync_ozon_status_is_return($code)) {
            continue;
        }
        $bucket = orders_sync_ozon_status_is_fbo_only($code) ? 'fbo_only' : 'order';
        $result[$bucket][] = $item;
    }
    $result['return'] = orders_sync_ozon_return_scenario_catalog();
    return $result;
}

function orders_sync_wb_status_labels_known(): array
{
    return [
        'new' => 'Новый заказ',
        'confirm' => 'В сборке',
        'complete' => 'Передан в доставку',
        'deliver' => 'Доставляется',
        'receive' => 'Получен покупателем',
        'reject' => 'Отказ покупателя',
        'cancel' => 'Отменён продавцом',
        'cancel_missed_call' => 'Отмена: покупатель недоступен',
        'cancel_shelf_life' => 'Отмена: истёк срок хранения',
        'cancel_carrier' => 'Отменён перевозчиком',
        'prepare' => 'Готов к выдаче',
        'waiting' => 'Ожидает приёмки WB',
        'sorted' => 'Отсортирован на складе WB',
        'sold' => 'Продан / получен покупателем',
        'canceled' => 'Отменён',
        'canceled_by_client' => 'Отменён покупателем',
        'declined_by_client' => 'Отменён покупателем в первый час',
        'defect' => 'Отменён из-за брака',
        'canceled_by_missed_call' => 'Отмена: покупатель недоступен',
        'ready_for_pickup' => 'Ожидает получения в ПВЗ',
        'postponed_delivery' => 'Доставка перенесена',
        'accepted_by_carrier' => 'Принят перевозчиком',
        'sent_to_carrier' => 'Передан перевозчику',
        'canceled_by_carrier' => 'Отменён перевозчиком',
    ];
}

function orders_sync_wb_status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return '—';
    }
    $known = orders_sync_wb_status_labels_known();
    if (isset($known[$status])) {
        return $known[$status];
    }
    return mb_convert_case(str_replace('_', ' ', $status), MB_CASE_TITLE, 'UTF-8');
}

function orders_sync_wb_status_catalog(?int $connectionId = null, array $cfg = []): array
{
    $items = [];
    foreach (orders_sync_wb_status_labels_known() as $code => $label) {
        $items[$code] = ['code' => $code, 'label' => $label];
    }

    orders_sync_orders_table_ensure($cfg);
    $sql = "
        SELECT DISTINCT status
        FROM feedtools_marketplace_order_snapshots
        WHERE marketplace = 'wb' AND status <> ''
    ";
    $args = [];
    if (($connectionId ?? 0) > 0) {
        $sql .= " AND connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY status ASC";
    $st = db()->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll() ?: [] as $row) {
        $code = strtolower(trim((string)($row['status'] ?? '')));
        if ($code === '' || isset($items[$code])) {
            continue;
        }
        $items[$code] = ['code' => $code, 'label' => orders_sync_wb_status_label($code)];
    }

    return array_values($items);
}

function orders_sync_wb_status_catalog_grouped(?int $connectionId = null, array $cfg = []): array
{
    $cancelCodes = ['cancel', 'cancel_carrier', 'canceled', 'canceled_by_client', 'declined_by_client', 'defect', 'canceled_by_carrier', 'reject', 'cancel_missed_call', 'cancel_shelf_life', 'canceled_by_missed_call'];
    $result = [
        'order' => [],
        'fbo_only' => [],
        'return' => [],
    ];
    foreach (orders_sync_wb_status_catalog($connectionId, $cfg) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = strtolower(trim((string)($item['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $bucket = in_array($code, $cancelCodes, true) ? 'return' : 'order';
        $result[$bucket][] = $item;
    }
    return $result;
}

function orders_sync_yandex_status_labels_known(): array
{
    return [
        'placing' => 'Оформляется',
        'reserved' => 'Зарезервирован',
        'unpaid' => 'Не оплачен',
        'processing' => 'В обработке',
        'delivery' => 'Доставляется',
        'pickup' => 'Готов к получению',
        'delivered' => 'Доставлен',
        'cancelled' => 'Отменён',
        'partially_returned' => 'Частично возвращён',
        'returned' => 'Возвращён',
        'pending' => 'Ожидает обработки',
        'unknown' => 'Неизвестный статус',
    ];
}

function orders_sync_yandex_status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return '—';
    }
    $known = orders_sync_yandex_status_labels_known();
    if (isset($known[$status])) {
        return $known[$status];
    }
    return mb_convert_case(str_replace('_', ' ', $status), MB_CASE_TITLE, 'UTF-8');
}

function orders_sync_yandex_status_catalog(?int $connectionId = null, array $cfg = []): array
{
    $items = [];
    foreach (orders_sync_yandex_status_labels_known() as $code => $label) {
        $items[$code] = ['code' => $code, 'label' => $label];
    }

    orders_sync_orders_table_ensure($cfg);
    $sql = "
        SELECT DISTINCT status
        FROM feedtools_marketplace_order_snapshots
        WHERE marketplace = 'yandex_market' AND status <> ''
    ";
    $args = [];
    if (($connectionId ?? 0) > 0) {
        $sql .= " AND connection_id = ?";
        $args[] = (int)$connectionId;
    }
    $sql .= " ORDER BY status ASC";
    $st = db()->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll() ?: [] as $row) {
        $code = strtolower(trim((string)($row['status'] ?? '')));
        if ($code === '' || isset($items[$code])) {
            continue;
        }
        $items[$code] = ['code' => $code, 'label' => orders_sync_yandex_status_label($code)];
    }

    return array_values($items);
}

function orders_sync_yandex_status_catalog_grouped(?int $connectionId = null, array $cfg = []): array
{
    $result = [
        'order' => [],
        'fbo_only' => [],
        'return' => [],
    ];
    foreach (orders_sync_yandex_status_catalog($connectionId, $cfg) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = strtolower(trim((string)($item['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $bucket = in_array($code, ['cancelled', 'partially_returned', 'returned'], true) ? 'return' : 'order';
        $result[$bucket][] = $item;
    }
    return $result;
}

function orders_sync_marketplace_status_label(string $marketplace, string $status): string
{
    return match (orders_sync_marketplace_normalize($marketplace)) {
        'wb' => orders_sync_wb_status_label($status),
        'yandex_market' => orders_sync_yandex_status_label($status),
        default => orders_sync_ozon_status_label($status),
    };
}

function orders_sync_marketplace_status_catalog(string $marketplace, ?int $connectionId = null, array $cfg = []): array
{
    return match (orders_sync_marketplace_normalize($marketplace)) {
        'wb' => orders_sync_wb_status_catalog($connectionId, $cfg),
        'yandex_market' => orders_sync_yandex_status_catalog($connectionId, $cfg),
        default => orders_sync_ozon_status_catalog($connectionId, $cfg),
    };
}

function orders_sync_marketplace_status_catalog_grouped(string $marketplace, ?int $connectionId = null, array $cfg = []): array
{
    return match (orders_sync_marketplace_normalize($marketplace)) {
        'wb' => orders_sync_wb_status_catalog_grouped($connectionId, $cfg),
        'yandex_market' => orders_sync_yandex_status_catalog_grouped($connectionId, $cfg),
        default => orders_sync_ozon_status_catalog_grouped($connectionId, $cfg),
    };
}

function orders_sync_profile_status_mapping_resolved_value(array $profile, array $account, string $status, string $kind): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return '';
    }
    $map = $kind === 'update'
        ? (array)($profile['ozon_status_update_map'] ?? [])
        : (array)($profile['ozon_status_create_map'] ?? []);
    if (array_key_exists($status, $map) && trim((string)$map[$status]) !== '') {
        return trim((string)$map[$status]);
    }
    if ($kind === 'update') {
        return trim((string)($profile['ozon_status_update_default_state_id'] ?? orders_sync_status_update_keep_token()));
    }
    return trim((string)($profile['ozon_status_create_default_state_id'] ?? ''));
}

function orders_sync_moysklad_profile_new_order_state(array $account, array $profile, array $posting, array $cfg = []): ?array
{
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon'));
    $source = orders_sync_ozon_source_normalize((string)($posting['_feedtools_source'] ?? ''));
    if ($marketplace === 'ozon' && $source === 'fbo') {
        $fboStateId = trim((string)($profile['moysklad_fbo_new_order_state_id'] ?? ''));
        if ($fboStateId === '') {
            return null;
        }
        $state = orders_sync_moysklad_find_state_by_id($account, $fboStateId);
        if (is_array($state)) {
            return $state;
        }
        throw new RuntimeException('Статус МойСклад для нового заказа FBO не найден.');
    }
    $entry = orders_sync_marketplace_logistics_warehouse_entry_from_posting($marketplace, $posting);
    $isWbDbw = $marketplace === 'wb' && orders_sync_wb_warehouse_source_is_dbw($source);
    if ((int)($profile['id'] ?? 0) <= 0 || !is_array($entry)) {
        $row = null;
    } else {
        $row = orders_sync_profile_store_mapping_index((int)$profile['id'], $cfg)[(string)$entry['key']] ?? null;
    }
    if (is_array($row)) {
        $stateId = trim((string)($row['moysklad_new_order_state_id'] ?? ''));
        if ($stateId === '' && !$isWbDbw) {
            return null;
        }
        if ($stateId !== '') {
            $state = orders_sync_moysklad_find_state_by_id($account, $stateId);
            if (is_array($state)) {
                return $state;
            }
            throw new RuntimeException('Статус МойСклад для нового заказа по складу не найден.');
        }
    }
    if ($isWbDbw) {
        $wbDbwStateId = trim((string)($profile['moysklad_wb_dbw_new_order_state_id'] ?? ''));
        if ($wbDbwStateId === '') {
            return null;
        }
        $state = orders_sync_moysklad_find_state_by_id($account, $wbDbwStateId);
        if (is_array($state)) {
            return $state;
        }
        throw new RuntimeException('Статус МойСклад для нового заказа DBW WB не найден.');
    }
    return null;
}

function orders_sync_cancelled_transition_target_state_ids(array $profile): array
{
    $targetIds = [];
    foreach ((array)($profile['cancelled_transition_map'] ?? []) as $targetStateId) {
        $targetStateId = trim((string)$targetStateId);
        if ($targetStateId === '' || $targetStateId === orders_sync_status_update_keep_token()) {
            continue;
        }
        $targetIds[$targetStateId] = true;
    }
    return array_keys($targetIds);
}

function orders_sync_cancelled_transition_policy(array $profile, array $account, string $currentStateId, string $currentStateName = ''): ?array
{
    $currentStateId = trim($currentStateId);
    $map = (array)($profile['cancelled_transition_map'] ?? []);
    $value = '';
    $hasExplicitRule = false;

    if ($currentStateId !== '' && array_key_exists($currentStateId, $map)) {
        $value = trim((string)$map[$currentStateId]);
        $hasExplicitRule = $value !== '';
    }

    if (!$hasExplicitRule && $currentStateId !== '' && in_array($currentStateId, orders_sync_cancelled_transition_target_state_ids($profile), true)) {
        return [
            'mode' => 'keep',
            'state' => null,
            'state_id' => '',
            'state_name' => $currentStateName !== '' ? $currentStateName : 'Не менять статус',
        ];
    }

    if (!$hasExplicitRule) {
        $value = trim((string)($profile['cancelled_transition_default_state_id'] ?? ''));
        if ($value === '') {
            return null;
        }
    }

    if ($value === orders_sync_status_update_keep_token()) {
        return [
            'mode' => 'keep',
            'state' => null,
            'state_id' => '',
            'state_name' => $currentStateName !== '' ? $currentStateName : 'Не менять статус',
        ];
    }

    $state = orders_sync_moysklad_find_state_by_id($account, $value);
    if (!is_array($state)) {
        throw new RuntimeException('Статус МойСклад для правила отмены не найден.');
    }

    return [
        'mode' => 'set',
        'state' => $state,
        'state_id' => trim((string)($state['id'] ?? '')),
        'state_name' => trim((string)($state['name'] ?? '')),
    ];
}

function orders_sync_profile_status_policy(array $profile, array $account, string $status, string $kind, array $posting = [], array $cfg = []): array
{
    $status = orders_sync_marketplace_status_for_moysklad_mapping($profile, $posting, $status);
    $value = orders_sync_profile_status_mapping_resolved_value($profile, $account, $status, $kind);
    if ($kind === 'create' && $value === orders_sync_status_create_new_order_token()) {
        $state = orders_sync_moysklad_profile_new_order_state($account, $profile, $posting, $cfg);
        if (!is_array($state)) {
            return [
                'mode' => 'none',
                'state' => null,
                'state_id' => '',
                'state_name' => '',
            ];
        }
        return [
            'mode' => 'set',
            'state' => $state,
            'state_id' => trim((string)($state['id'] ?? '')),
            'state_name' => trim((string)($state['name'] ?? '')),
        ];
    }
    if ($kind === 'update' && strtolower(trim($status)) === 'new' && $value === orders_sync_status_update_keep_token()) {
        $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon'));
        $source = orders_sync_ozon_source_normalize((string)($posting['_feedtools_source'] ?? ''));
        if ($marketplace === 'wb' && orders_sync_wb_warehouse_source_is_dbw($source)) {
            $state = orders_sync_moysklad_profile_new_order_state($account, $profile, $posting, $cfg);
            if (is_array($state)) {
                return [
                    'mode' => 'set',
                    'state' => $state,
                    'state_id' => trim((string)($state['id'] ?? '')),
                    'state_name' => trim((string)($state['name'] ?? '')),
                ];
            }
        }
    }
    if ($kind === 'update' && $value === orders_sync_status_update_keep_token()) {
        return [
            'mode' => 'keep',
            'state' => null,
            'state_id' => '',
            'state_name' => 'Не менять статус',
        ];
    }
    if ($value === '') {
        return [
            'mode' => 'none',
            'state' => null,
            'state_id' => '',
            'state_name' => '',
        ];
    }
    $state = orders_sync_moysklad_find_state_by_id($account, $value);
    if (!is_array($state)) {
        throw new RuntimeException('Выбранный статус МойСклад для сопоставления не найден.');
    }
    return [
        'mode' => 'set',
        'state' => $state,
        'state_id' => trim((string)($state['id'] ?? '')),
        'state_name' => trim((string)($state['name'] ?? '')),
    ];
}

function orders_sync_moysklad_customerorder_state_id(array $order): string
{
    if (is_array($order['state'] ?? null)) {
        $state = $order['state'];
        $id = trim((string)($state['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        $href = trim((string)($state['meta']['href'] ?? ''));
        if ($href !== '' && preg_match('~/([^/]+)$~', $href, $m)) {
            return trim((string)($m[1] ?? ''));
        }
    }
    return '';
}

function orders_sync_moysklad_state_name_for_ozon(string $status, string $substatus = ''): string
{
    $status = strtolower(trim($status));
    $substatus = strtolower(trim($substatus));

    return match ($status) {
        'awaiting_packaging', 'acceptance_in_progress', 'awaiting_registration', 'acceptance_not_allowed' => 'Ожидает сборки',
        'awaiting_deliver', 'ready_to_ship', 'sent_by_seller' => 'Собран',
        'delivering', 'driver_pickup', 'last_mile', 'in_transit' => 'Доставляется',
        'delivered' => 'Доставлен',
        'cancelled' => str_contains($substatus, 'cancel') ? 'Отменен' : 'Отменен',
        'returning_to_seller', 'return_in_progress' => 'Возвращается',
        'returned_to_seller', 'returned' => 'Возвращен',
        'return_to_ozon_in_transit' => 'Возвращается',
        'return_to_seller_in_transit' => 'Возвращается',
        'return_to_seller_awaiting_pickup' => 'Возвращается',
        'return_to_ozon_arrived' => 'Возвращен',
        'return_to_seller_received' => 'Возвращен',
        default => 'Новый',
    };
}

function orders_sync_ozon_return_targets_ozon(array $returnInfo): bool
{
    $schema = strtolower(trim((string)($returnInfo['schema'] ?? '')));
    if ($schema === 'fbo') {
        return true;
    }

    $haystacks = [
        mb_strtolower(trim((string)($returnInfo['target_place']['name'] ?? '')), 'UTF-8'),
        mb_strtolower(trim((string)($returnInfo['target_place']['address'] ?? '')), 'UTF-8'),
    ];
    foreach ($haystacks as $text) {
        if ($text === '') {
            continue;
        }
        if (str_contains($text, 'ozon') || str_contains($text, 'возврат')) {
            return true;
        }
    }
    return false;
}

function orders_sync_ozon_return_visual_status_to_scenario(string $sysName, string $displayName = '', ?array $returnInfo = null): string
{
    $sysName = strtolower(trim($sysName));
    $displayName = mb_strtolower(trim($displayName), 'UTF-8');
    $targetsOzon = is_array($returnInfo) && orders_sync_ozon_return_targets_ozon($returnInfo);

    $map = [
        'movingtoozon' => 'return_to_ozon_in_transit',
        'senttoozon' => 'return_to_ozon_in_transit',
        'receivedatozon' => 'return_to_ozon_arrived',
        'movingtoseller' => 'return_to_seller_in_transit',
        'senttoseller' => 'return_to_seller_in_transit',
        'pickuppoint' => 'return_to_seller_awaiting_pickup',
        'atpickuppoint' => 'return_to_seller_awaiting_pickup',
        'receivedbyseller' => 'return_to_seller_received',
    ];
    if ($sysName !== '' && isset($map[$sysName])) {
        return $map[$sysName];
    }
    if ($sysName === 'waitingshipment') {
        return $targetsOzon ? 'return_to_ozon_in_transit' : 'return_to_seller_in_transit';
    }

    return match (true) {
        str_contains($displayName, 'едет на склад ozon') => 'return_to_ozon_in_transit',
        str_contains($displayName, 'на складе ozon') => 'return_to_ozon_arrived',
        str_contains($displayName, 'ожидает отправки') => $targetsOzon ? 'return_to_ozon_in_transit' : 'return_to_seller_in_transit',
        str_contains($displayName, 'едет к вам') => 'return_to_seller_in_transit',
        str_contains($displayName, 'в пункте выдачи') => 'return_to_seller_awaiting_pickup',
        str_contains($displayName, 'уже у вас') => 'return_to_seller_received',
        default => '',
    };
}

function orders_sync_ozon_return_sort_moment(array $row): string
{
    foreach ([
        (string)($row['visual']['change_moment'] ?? ''),
        (string)($row['logistic']['final_moment'] ?? ''),
        (string)($row['logistic']['return_date'] ?? ''),
    ] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function orders_sync_ozon_return_best_match(array $rows, array $posting): ?array
{
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    $orderId = trim((string)($posting['order_id'] ?? ''));
    $matches = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowPostingNumber = trim((string)($row['posting_number'] ?? ''));
        $rowOrderNumber = trim((string)($row['order_number'] ?? ''));
        $rowOrderId = trim((string)($row['order_id'] ?? ''));
        if (
            ($postingNumber !== '' && $rowPostingNumber === $postingNumber) ||
            ($orderNumber !== '' && $rowOrderNumber === $orderNumber) ||
            ($orderId !== '' && $rowOrderId === $orderId)
        ) {
            $matches[] = $row;
        }
    }

    if (!$matches) {
        return null;
    }

    usort($matches, static function (array $a, array $b): int {
        return strcmp(
            orders_sync_ozon_return_sort_moment($b),
            orders_sync_ozon_return_sort_moment($a)
        );
    });

    return $matches[0] ?? null;
}

function orders_sync_ozon_return_info(array $cfg, string $source, array $posting): ?array
{
    static $memoized = [];

    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    $orderId = trim((string)($posting['order_id'] ?? ''));
    $memoKey = sha1(json_encode([
        'source' => orders_sync_ozon_source_normalize($source),
        'posting_number' => $postingNumber,
        'order_number' => $orderNumber,
        'order_id' => $orderId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (array_key_exists($memoKey, $memoized)) {
        return $memoized[$memoKey];
    }

    $filters = [];
    if ($orderId !== '' && ctype_digit($orderId)) {
        $filters[] = ['order_id' => (int)$orderId];
    } elseif ($orderNumber !== '') {
        $filters[] = ['order_number' => $orderNumber];
    } elseif ($postingNumber !== '') {
        $filters[] = ['posting_number' => $postingNumber];
    }
    if (!$filters) {
        $memoized[$memoKey] = null;
        return null;
    }

    foreach ($filters as $filter) {
        try {
            $response = ozon_post_json(ozon_cfg_or_fail($cfg), '/v1/returns/list', [
                'filter' => $filter,
                'limit' => 100,
                'offset' => 0,
            ]);
        } catch (Throwable) {
            continue;
        }
        $match = orders_sync_ozon_return_best_match((array)($response['returns'] ?? []), $posting);
        if (is_array($match)) {
            $memoized[$memoKey] = $match;
            return $match;
        }
    }

    $memoized[$memoKey] = null;
    return null;
}

function orders_sync_ozon_effective_status(array $cfg, string $source, array $posting): string
{
    foreach ([
        (string)($posting['_feedtools_effective_status'] ?? ''),
        (string)($posting['effective_status'] ?? ''),
    ] as $cachedStatus) {
        $cachedStatus = strtolower(trim($cachedStatus));
        if ($cachedStatus !== '') {
            return $cachedStatus;
        }
    }

    $status = strtolower(trim((string)($posting['status'] ?? '')));
    if ($status === '') {
        return '';
    }

    if ($status === 'cancelled' || $status === 'delivered' || orders_sync_ozon_status_is_return($status)) {
        $returnInfo = orders_sync_ozon_return_info($cfg, $source, $posting);
        if (is_array($returnInfo)) {
            $scenario = orders_sync_ozon_return_visual_status_to_scenario(
                (string)($returnInfo['visual']['status']['sys_name'] ?? ''),
                (string)($returnInfo['visual']['status']['display_name'] ?? ''),
                $returnInfo
            );
            if ($scenario !== '') {
                return $scenario;
            }
        }
    }

    return $status;
}

function orders_sync_wb_effective_status(array $posting): string
{
    foreach ([
        (string)($posting['_feedtools_effective_status'] ?? ''),
        (string)($posting['effective_status'] ?? ''),
    ] as $cachedStatus) {
        $cachedStatus = strtolower(trim($cachedStatus));
        if ($cachedStatus !== '') {
            return $cachedStatus;
        }
    }

    $status = strtolower(trim((string)($posting['status'] ?? '')));
    $supplierStatus = strtolower(trim((string)($posting['supplierStatus'] ?? $posting['supplier_status'] ?? $posting['substatus'] ?? '')));
    $wbStatus = strtolower(trim((string)($posting['wbStatus'] ?? $posting['wb_status'] ?? '')));
    if ($status !== '') {
        return $status;
    }
    if ($wbStatus !== '' && !($wbStatus === 'waiting' && $supplierStatus !== '')) {
        return $wbStatus;
    }
    return $supplierStatus !== '' ? $supplierStatus : $wbStatus;
}

function orders_sync_yandex_effective_status(array $posting): string
{
    foreach ([
        (string)($posting['_feedtools_effective_status'] ?? ''),
        (string)($posting['effective_status'] ?? ''),
    ] as $cachedStatus) {
        $cachedStatus = strtolower(trim($cachedStatus));
        if ($cachedStatus !== '') {
            return $cachedStatus;
        }
    }

    $status = strtolower(trim((string)($posting['status'] ?? '')));
    if ($status !== '') {
        return $status;
    }
    return strtolower(trim((string)($posting['substatus'] ?? '')));
}

function orders_sync_marketplace_effective_status(array $cfg, string $marketplace, string $source, array $posting): string
{
    return match (orders_sync_marketplace_normalize($marketplace)) {
        'wb' => orders_sync_wb_effective_status($posting),
        'yandex_market' => orders_sync_yandex_effective_status($posting),
        default => orders_sync_ozon_effective_status($cfg, $source, $posting),
    };
}

function orders_sync_marketplace_status_is_cancelled(string $marketplace, string $rawStatus): bool
{
    $rawStatus = strtolower(trim($rawStatus));
    if ($rawStatus === '') {
        return false;
    }
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    if ($marketplace === 'wb') {
        return in_array($rawStatus, ['cancel', 'cancel_carrier', 'canceled', 'canceled_by_client', 'declined_by_client', 'defect', 'canceled_by_carrier', 'reject', 'cancel_missed_call', 'cancel_shelf_life', 'canceled_by_missed_call'], true);
    }
    if ($marketplace === 'yandex_market') {
        return in_array($rawStatus, ['cancelled', 'returned', 'partially_returned'], true);
    }
    return $rawStatus === 'cancelled';
}

function orders_sync_ozon_is_simple_cancelled_before_ship(array $posting, string $effectiveStatus = ''): bool
{
    $rawStatus = strtolower(trim((string)($posting['status'] ?? '')));
    if ($rawStatus !== 'cancelled') {
        return false;
    }
    $effectiveStatus = strtolower(trim($effectiveStatus));
    if ($effectiveStatus === '') {
        $effectiveStatus = $rawStatus;
    }
    if ($effectiveStatus !== 'cancelled') {
        return false;
    }

    $cancellation = is_array($posting['cancellation'] ?? null) ? $posting['cancellation'] : [];
    return empty($cancellation['cancelled_after_ship']);
}

function orders_sync_wb_is_simple_cancelled_before_ship(array $posting, string $effectiveStatus = ''): bool
{
    $effectiveStatus = strtolower(trim($effectiveStatus));
    if ($effectiveStatus === '') {
        $effectiveStatus = orders_sync_wb_effective_status($posting);
    }
    if ($effectiveStatus === '') {
        return false;
    }

    $supplierStatus = strtolower(trim((string)($posting['supplierStatus'] ?? $posting['supplier_status'] ?? $posting['substatus'] ?? '')));
    $wbStatus = strtolower(trim((string)($posting['wbStatus'] ?? $posting['wb_status'] ?? '')));

    if ($wbStatus === 'declined_by_client' || $effectiveStatus === 'declined_by_client') {
        return true;
    }
    if ($wbStatus === 'canceled' || $effectiveStatus === 'canceled') {
        return true;
    }
    if (
        ($wbStatus === 'canceled_by_client' || $effectiveStatus === 'canceled_by_client')
        && $supplierStatus === 'new'
    ) {
        return true;
    }
    return $supplierStatus === 'cancel' || $effectiveStatus === 'cancel';
}

function orders_sync_marketplace_status_for_moysklad_mapping(array $profile, array $posting, string $effectiveStatus): string
{
    $effectiveStatus = strtolower(trim($effectiveStatus));
    $marketplace = orders_sync_marketplace_normalize(
        (string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon')
    );
    if (
        $marketplace === 'wb'
        && orders_sync_wb_is_simple_cancelled_before_ship($posting, $effectiveStatus)
    ) {
        return 'canceled';
    }
    return $effectiveStatus;
}

function orders_sync_marketplace_is_simple_cancelled_before_ship(string $marketplace, array $posting, string $effectiveStatus = ''): bool
{
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    if ($marketplace === 'ozon') {
        return orders_sync_ozon_is_simple_cancelled_before_ship($posting, $effectiveStatus);
    }
    if ($marketplace === 'wb') {
        return orders_sync_wb_is_simple_cancelled_before_ship($posting, $effectiveStatus);
    }
    return orders_sync_marketplace_status_is_cancelled($marketplace, $effectiveStatus);
}

function orders_sync_moysklad_resolve_store(array $account, array $posting): ?array
{
    $candidates = [];
    $analytics = is_array($posting['analytics_data'] ?? null) ? $posting['analytics_data'] : [];
    $deliveryMethod = is_array($posting['delivery_method'] ?? null) ? $posting['delivery_method'] : [];
    foreach ([
        (string)($analytics['warehouse_name'] ?? ''),
        (string)($analytics['warehouse'] ?? ''),
        (string)($deliveryMethod['warehouse'] ?? ''),
    ] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $candidate) {
        $response = orders_sync_moysklad_request($account, 'GET', 'entity/store', null, ['search' => $candidate, 'limit' => 20]);
        foreach (($response['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (mb_strtolower(trim((string)($row['name'] ?? '')), 'UTF-8') === mb_strtolower($candidate, 'UTF-8')) {
                return $row;
            }
        }
    }
    return null;
}

function orders_sync_moysklad_profile_store(array $account, array $profile, array $posting, array $cfg = []): ?array
{
    $defaultStoreId = trim((string)($profile['moysklad_default_store_id'] ?? ''));
    $fboStoreId = trim((string)($profile['moysklad_fbo_store_id'] ?? ''));
    $wbDbwStoreId = trim((string)($profile['moysklad_wb_dbw_store_id'] ?? ''));
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? $profile['marketplace'] ?? 'ozon'));
    $source = orders_sync_ozon_source_normalize((string)($posting['_feedtools_source'] ?? ''));
    $entry = orders_sync_marketplace_logistics_warehouse_entry_from_posting($marketplace, $posting);
    if ($marketplace === 'ozon' && $source === 'fbo' && $fboStoreId !== '') {
        $store = orders_sync_moysklad_store_by_id($account, $fboStoreId);
        if (is_array($store)) {
            return $store;
        }
        throw new RuntimeException('Склад МойСклад для FBO из профиля не найден.');
    }
    if ((int)($profile['id'] ?? 0) > 0 && is_array($entry)) {
        $row = orders_sync_profile_store_mapping_index((int)$profile['id'], $cfg)[(string)$entry['key']] ?? null;
        if (is_array($row)) {
            $mappedStoreId = trim((string)($row['moysklad_store_id'] ?? ''));
            if ($mappedStoreId !== '') {
                $store = orders_sync_moysklad_store_by_id($account, $mappedStoreId);
                if (is_array($store)) {
                    return $store;
                }
                throw new RuntimeException('Склад МойСклад из сопоставления профиля не найден.');
            }
        }
    }
    if ($marketplace === 'wb' && orders_sync_wb_warehouse_source_is_dbw($source) && $wbDbwStoreId !== '') {
        $store = orders_sync_moysklad_store_by_id($account, $wbDbwStoreId);
        if (is_array($store)) {
            return $store;
        }
        throw new RuntimeException('Склад МойСклад для DBW WB из профиля не найден.');
    }
    if ($defaultStoreId !== '') {
        $store = orders_sync_moysklad_store_by_id($account, $defaultStoreId);
        if (is_array($store)) {
            return $store;
        }
        throw new RuntimeException('Склад МойСклад по умолчанию из профиля не найден.');
    }
    return orders_sync_moysklad_resolve_store($account, $posting);
}

function orders_sync_moysklad_store_by_id(array $account, string $storeId): ?array
{
    $storeId = trim($storeId);
    if ($storeId === '') {
        return null;
    }
    return orders_sync_moysklad_option_raw_by_id(
        orders_sync_moysklad_stores_options($account),
        $storeId
    ) ?? orders_sync_moysklad_find_entity_by_id($account, 'entity/store', $storeId);
}

function orders_sync_moysklad_find_customerorder_by_name(array $account, string $name, bool $deleted = false): ?array
{
    static $cache = [];

    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $cacheKey = sha1(json_encode([
        'account_id' => (int)($account['id'] ?? 0),
        'name' => mb_strtolower($name, 'UTF-8'),
        'deleted' => $deleted,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $query = [
        'search' => $name,
        'limit' => 20,
    ];
    if ($deleted) {
        $query['deleted'] = 'true';
    }
    $response = orders_sync_moysklad_request($account, 'GET', 'entity/customerorder', null, $query);
    foreach (($response['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (mb_strtolower(trim((string)($row['name'] ?? '')), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
            return $cache[$cacheKey] = $row;
        }
    }
    return $cache[$cacheKey] = null;
}

function orders_sync_exception_is_moysklad_not_found(Throwable $e): bool
{
    $message = mb_strtolower(trim($e->getMessage()), 'UTF-8');
    if ($message === '') {
        return false;
    }
    return str_contains($message, 'http 404')
        || str_contains($message, ' не найден')
        || str_contains($message, 'не найдена')
        || str_contains($message, 'идентификатором');
}

function orders_sync_moysklad_customerorder_needs_restore(array $account, array $order): bool
{
    if (!empty($order['deleted'])) {
        return true;
    }

    $orderId = trim((string)($order['id'] ?? ''));
    $orderName = trim((string)($order['name'] ?? ''));
    if ($orderId === '' || $orderName === '') {
        return false;
    }

    $activeOrder = orders_sync_moysklad_find_customerorder_by_name($account, $orderName, false);
    if (!is_array($activeOrder)) {
        return true;
    }

    return trim((string)($activeOrder['id'] ?? '')) !== $orderId;
}

function orders_sync_moysklad_customerorder_restore_or_null(array $account, string $orderId, array $payload): ?array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return null;
    }

    $restorePayload = $payload;
    $restorePayload['archived'] = false;
    try {
        return orders_sync_moysklad_request($account, 'PUT', 'entity/customerorder/' . $orderId, $restorePayload);
    } catch (Throwable $e) {
        if (orders_sync_exception_is_moysklad_not_found($e)) {
            return null;
        }
        throw $e;
    }
}

function orders_sync_moysklad_existing_customerorder_resolve(
    array $account,
    int $profileId,
    int $connectionId,
    int $moyskladAccountId,
    string $marketplace,
    string $source,
    string $postingNumber,
    string $payloadName,
    ?array $existingLink = null,
    array $cfg = [],
    array $payloadNameAliases = []
): ?array {
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    if (!is_array($existingLink) || trim((string)($existingLink['moysklad_customerorder_id'] ?? '')) === '') {
        $existingLink = orders_sync_export_link_get($profileId, $marketplace, $source, $postingNumber, $cfg);
    }
    if ((!is_array($existingLink) || trim((string)($existingLink['moysklad_customerorder_id'] ?? '')) === '') && $connectionId > 0 && $moyskladAccountId > 0) {
        $existingLink = orders_sync_export_link_get_for_scope(
            $connectionId,
            $moyskladAccountId,
            $marketplace,
            $source,
            $postingNumber,
            $cfg
        );
    }
    $existingOrderId = trim((string)($existingLink['moysklad_customerorder_id'] ?? ''));

    if ($existingOrderId !== '') {
        $existingOrder = orders_sync_moysklad_find_entity_by_id($account, 'entity/customerorder', $existingOrderId);
        if (is_array($existingOrder)) {
            return $existingOrder;
        }
    }

    $payloadNames = [];
    foreach (array_merge([$payloadName], $payloadNameAliases) as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && !in_array($candidate, $payloadNames, true)) {
            $payloadNames[] = $candidate;
        }
    }

    foreach ([false, true] as $includeArchived) {
        foreach ($payloadNames as $candidateName) {
            $existingOrder = orders_sync_moysklad_find_customerorder_by_name($account, $candidateName, $includeArchived);
            if (!is_array($existingOrder)) {
                continue;
            }

            $existingOrderId = trim((string)($existingOrder['id'] ?? ''));
            if ($existingOrderId !== '') {
                $fullOrder = orders_sync_moysklad_find_entity_by_id($account, 'entity/customerorder', $existingOrderId);
                if (is_array($fullOrder)) {
                    return $fullOrder;
                }
            }

            return $existingOrder;
        }
    }

    return null;
}

function orders_sync_moysklad_profile_organization(array $account, array $profile): array
{
    $selectedId = trim((string)($profile['moysklad_organization_id'] ?? ''));
    if ($selectedId !== '') {
        $organization = orders_sync_moysklad_option_raw_by_id(
            orders_sync_moysklad_organizations_options($account),
            $selectedId
        ) ?? orders_sync_moysklad_find_entity_by_id($account, 'entity/organization', $selectedId);
        if (is_array($organization)) {
            return $organization;
        }
        throw new RuntimeException('Организация из настроек профиля не найдена в МойСклад.');
    }
    return orders_sync_moysklad_default_organization($account);
}

function orders_sync_moysklad_profile_counterparty(array $account, array $profile): array
{
    $selectedId = trim((string)($profile['moysklad_counterparty_id'] ?? ''));
    if ($selectedId !== '') {
        $counterparty = orders_sync_moysklad_option_raw_by_id(
            orders_sync_moysklad_counterparties_options($account),
            $selectedId
        ) ?? orders_sync_moysklad_find_entity_by_id($account, 'entity/counterparty', $selectedId);
        if (is_array($counterparty)) {
            return $counterparty;
        }
        throw new RuntimeException('Контрагент из настроек профиля не найден в МойСклад.');
    }
    throw new RuntimeException('В профиле синхронизации не выбран контрагент МойСклад.');
}

function orders_sync_moysklad_profile_project(array $account, array $profile): ?array
{
    $selectedId = trim((string)($profile['moysklad_project_id'] ?? ''));
    if ($selectedId === '') {
        return null;
    }
    $project = orders_sync_moysklad_option_raw_by_id(
        orders_sync_moysklad_projects_options($account),
        $selectedId
    ) ?? orders_sync_moysklad_find_entity_by_id($account, 'entity/project', $selectedId);
    if (is_array($project)) {
        return $project;
    }
    throw new RuntimeException('Проект из настроек профиля не найден в МойСклад.');
}

function orders_sync_moysklad_profile_saleschannel(array $account, array $profile): ?array
{
    $selectedId = trim((string)($profile['moysklad_saleschannel_id'] ?? ''));
    if ($selectedId === '') {
        return null;
    }
    $salesChannel = orders_sync_moysklad_option_raw_by_id(
        orders_sync_moysklad_saleschannels_options($account),
        $selectedId
    ) ?? orders_sync_moysklad_find_entity_by_id($account, 'entity/saleschannel', $selectedId);
    if (is_array($salesChannel)) {
        return $salesChannel;
    }
    throw new RuntimeException('Канал продаж из настроек профиля не найден в МойСклад.');
}

function orders_sync_moysklad_assortment_placeholder_name(string $rowName, string $offerId, string $code): bool
{
    if (orders_sync_order_product_name_is_placeholder($rowName, $offerId, $code)) {
        return true;
    }

    $rowName = mb_strtolower(trim($rowName), 'UTF-8');
    $offerId = mb_strtolower(trim($offerId), 'UTF-8');
    $code = mb_strtolower(trim($code), 'UTF-8');
    if ($rowName === '') {
        return true;
    }
    foreach (array_filter([$offerId, $code, trim($code . ' ' . $offerId)]) as $candidate) {
        if ($rowName === $candidate) {
            return true;
        }
    }
    return false;
}

function orders_sync_moysklad_maybe_update_placeholder_assortment_name(array $account, array $row, string $targetName, string $offerId, string $code): array
{
    $targetName = trim($targetName);
    $rowId = trim((string)($row['id'] ?? ''));
    $rowName = trim((string)($row['name'] ?? ''));
    $type = trim((string)($row['meta']['type'] ?? ''));
    if (
        $targetName === ''
        || $rowId === ''
        || $rowName === $targetName
        || !in_array($type, ['product', 'service'], true)
        || !orders_sync_moysklad_assortment_placeholder_name($rowName, $offerId, $code)
    ) {
        return $row;
    }
    try {
        $updated = orders_sync_moysklad_request($account, 'PUT', 'entity/' . $type . '/' . $rowId, ['name' => $targetName]);
        return is_array($updated) && trim((string)($updated['id'] ?? '')) !== '' ? $updated : $row;
    } catch (Throwable) {
        return $row;
    }
}

function orders_sync_moysklad_maybe_update_assortment_identifiers(array $account, array $row, string $offerId, string $code): array
{
    $offerId = trim($offerId);
    $code = trim($code);
    $rowId = trim((string)($row['id'] ?? ''));
    $type = trim((string)($row['meta']['type'] ?? ''));
    if ($offerId === '' || $code === '' || $code === $offerId || $rowId === '' || $type !== 'product') {
        return $row;
    }

    $updates = [];
    $rowArticle = trim((string)($row['article'] ?? ''));
    $rowCode = trim((string)($row['code'] ?? ''));
    if ($rowArticle !== $offerId) {
        $updates['article'] = $offerId;
    }
    if ($rowCode === '' || $rowCode === $offerId) {
        $updates['code'] = $code;
    }
    if (!$updates) {
        return $row;
    }

    try {
        $updated = orders_sync_moysklad_request($account, 'PUT', 'entity/product/' . $rowId, $updates);
        return is_array($updated) && trim((string)($updated['id'] ?? '')) !== '' ? $updated : $row;
    } catch (Throwable) {
        return $row;
    }
}

function orders_sync_moysklad_exact_rows(array $account, string $entity, string $field, string $value, int $limit = 20): array
{
    $entity = trim($entity, '/');
    $field = trim($field);
    $value = trim($value);
    if ($entity === '' || $field === '' || $value === '') {
        return [];
    }

    try {
        $response = orders_sync_moysklad_request($account, 'GET', 'entity/' . $entity, null, [
            'filter' => $field . '=' . $value,
            'limit' => $limit,
        ]);
    } catch (Throwable) {
        return [];
    }

    return array_values(array_filter((array)($response['rows'] ?? []), 'is_array'));
}

function orders_sync_moysklad_exact_assortment_match(array $account, string $offerId, string $code, string $name): ?array
{
    $offerId = trim($offerId);
    $code = trim($code);
    $name = trim($name);
    $hasSupplierCode = $offerId !== '' && bundle_offer_supplier_code($offerId) !== '';

    $attempts = [
        ['entity' => 'product', 'field' => 'article', 'value' => $offerId],
        ['entity' => 'product', 'field' => 'code', 'value' => $offerId],
        ['entity' => 'product', 'field' => 'externalCode', 'value' => $offerId],
        ['entity' => 'variant', 'field' => 'code', 'value' => $offerId],
        ['entity' => 'variant', 'field' => 'externalCode', 'value' => $offerId],
    ];
    if (!$hasSupplierCode) {
        $attempts[] = ['entity' => 'product', 'field' => 'code', 'value' => $code];
        $attempts[] = ['entity' => 'product', 'field' => 'externalCode', 'value' => $code];
        $attempts[] = ['entity' => 'variant', 'field' => 'code', 'value' => $code];
        $attempts[] = ['entity' => 'variant', 'field' => 'externalCode', 'value' => $code];
    }

    foreach ($attempts as $attempt) {
        $value = trim((string)($attempt['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        foreach (orders_sync_moysklad_exact_rows($account, (string)$attempt['entity'], (string)$attempt['field'], $value) as $row) {
            $type = trim((string)($row['meta']['type'] ?? $attempt['entity']));
            if (!in_array($type, ['product', 'variant'], true)) {
                continue;
            }
            return $row;
        }
    }

    $searches = $hasSupplierCode ? array_filter([$offerId]) : array_filter([$offerId, $code, $name]);
    foreach ($searches as $search) {
        try {
            $response = orders_sync_moysklad_request($account, 'GET', 'entity/product', null, [
                'search' => $search,
                'limit' => 50,
            ]);
        } catch (Throwable) {
            continue;
        }
        foreach ((array)($response['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $article = trim((string)($row['article'] ?? ''));
            $rowCode = trim((string)($row['code'] ?? ''));
            $rowExternalCode = trim((string)($row['externalCode'] ?? ''));
            $rowName = trim((string)($row['name'] ?? ''));
            if (
                ($offerId !== '' && $article !== '' && $article === $offerId)
                || ($offerId !== '' && $rowCode !== '' && $rowCode === $offerId)
                || ($offerId !== '' && $rowExternalCode !== '' && $rowExternalCode === $offerId)
                || (!$hasSupplierCode && $code !== '' && $rowCode !== '' && $rowCode === $code)
                || (!$hasSupplierCode && $code !== '' && $rowExternalCode !== '' && $rowExternalCode === $code)
                || (!$hasSupplierCode && $name !== '' && mb_strtolower($rowName, 'UTF-8') === mb_strtolower($name, 'UTF-8'))
            ) {
                return $row;
            }
        }
    }

    return null;
}

function orders_sync_order_product_name_is_placeholder(string $name, string $offerId = '', string $code = ''): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }

    $lower = mb_strtolower($name, 'UTF-8');
    $offerId = trim($offerId);
    $code = trim($code);
    $candidates = array_filter(array_unique([
        $offerId,
        $code,
        $code !== '' && $offerId !== '' ? $code . ' ' . $offerId : '',
        $offerId !== '' ? bundle_offer_article_without_supplier_code($offerId) : '',
        'Товар WB',
        'Товар маркетплейса',
    ]));
    foreach ($candidates as $candidate) {
        if ($lower === mb_strtolower(trim((string)$candidate), 'UTF-8')) {
            return true;
        }
    }

    if (preg_match('~^wb\s+nmid\s+\d+$~iu', $name) === 1) {
        return true;
    }
    if (preg_match('~^[A-Za-zА-Яа-я0-9._-]+__\d+$~u', $name) === 1) {
        return true;
    }
    if ($code !== '' && preg_match('~^[A-Za-zА-Яа-я0-9._-]+$~u', $name) === 1 && mb_strlen($name, 'UTF-8') <= 32 && $lower === mb_strtolower($code, 'UTF-8')) {
        return true;
    }

    return false;
}

function orders_sync_supplier_product_offer_id_candidates(string $offerId): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [];
    }

    $candidates = [$offerId => true];
    if (!str_contains($offerId, '__')) {
        foreach (orders_sync_yandex_internal_offer_candidates($offerId) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $candidates[$candidate] = true;
            }
        }
        $internal = bundle_offer_yandex_offer_id_to_internal($offerId, orders_sync_yandex_supplier_codes());
        if ($internal !== '' && $internal !== $offerId) {
            $candidates[$internal] = true;
        }
    }

    return array_keys($candidates);
}

function orders_sync_supplier_product_name_by_offer_id(string $offerId): string
{
    static $cache = [];
    $offerId = trim($offerId);
    if ($offerId === '') {
        return '';
    }
    if (array_key_exists($offerId, $cache)) {
        return (string)$cache[$offerId];
    }
    if (!orders_sync_table_exists('feedtools_supplier_products')) {
        return $cache[$offerId] = '';
    }

    try {
        foreach (orders_sync_supplier_product_offer_id_candidates($offerId) as $candidate) {
            $st = db()->prepare("
                SELECT name
                FROM feedtools_supplier_products
                WHERE offer_id = ? AND COALESCE(name, '') <> ''
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $st->execute([$candidate]);
            $name = trim((string)($st->fetchColumn() ?: ''));
            if ($name !== '') {
                return $cache[$offerId] = $name;
            }
        }

        if (!str_contains($offerId, '__')) {
            $st = db()->prepare("
                SELECT offer_id, name
                FROM feedtools_supplier_products
                WHERE offer_id LIKE ? AND COALESCE(name, '') <> ''
                ORDER BY updated_at DESC, id DESC
                LIMIT 2
            ");
            $st->execute([$offerId . '\_\_%']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 1) {
                return $cache[$offerId] = trim((string)($rows[0]['name'] ?? ''));
            }
        }

        return $cache[$offerId] = '';
    } catch (Throwable) {
        return $cache[$offerId] = '';
    }
}

function orders_sync_supplier_product_name_for_order_product(array $product, string $offerId = '', string $code = ''): string
{
    $candidates = [];
    foreach ([$offerId, $code] as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $candidates[$value] = true;
        }
    }
    foreach (['offer_id', 'offerId', 'vendorCode', 'vendor_code', 'supplierArticle', 'supplier_article', 'article', 'marketplace_offer_id', 'raw_offer_id'] as $field) {
        $value = trim((string)($product[$field] ?? ''));
        if ($value !== '') {
            $candidates[$value] = true;
        }
    }

    foreach (array_keys($candidates) as $candidate) {
        $name = orders_sync_supplier_product_name_by_offer_id($candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return '';
}

function orders_sync_supplier_profile_by_code(string $supplierCode): ?array
{
    static $cache = [];
    $supplierCode = suppliers_normalize_code($supplierCode);
    if ($supplierCode === '') {
        return null;
    }
    if (array_key_exists($supplierCode, $cache)) {
        return is_array($cache[$supplierCode]) ? $cache[$supplierCode] : null;
    }
    try {
        suppliers_table_ensure();
        $st = db()->prepare("
            SELECT id, name, supplier_code
            FROM feedtools_suppliers
            WHERE supplier_code = ?
              AND COALESCE(is_archived, 0) = 0
            ORDER BY is_active DESC, sort_order ASC, name ASC, id ASC
            LIMIT 1
        ");
        $st->execute([$supplierCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $cache[$supplierCode] = is_array($row) ? $row : null;
    } catch (Throwable) {
        return $cache[$supplierCode] = null;
    }
}

function orders_sync_moysklad_find_entity_by_exact_name(array $account, string $entity, string $name): ?array
{
    $entity = trim($entity, '/');
    $name = trim($name);
    if ($entity === '' || $name === '') {
        return null;
    }
    foreach (orders_sync_moysklad_exact_rows($account, $entity, 'name', $name, 10) as $row) {
        if (orders_sync_moysklad_name_key((string)($row['name'] ?? '')) === orders_sync_moysklad_name_key($name)) {
            return $row;
        }
    }
    return null;
}

function orders_sync_moysklad_ensure_productfolder_by_name(array $account, string $name): ?array
{
    static $cache = [];
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $cacheKey = (int)($account['id'] ?? 0) . ':' . orders_sync_moysklad_name_key($name);
    if (array_key_exists($cacheKey, $cache)) {
        return is_array($cache[$cacheKey]) ? $cache[$cacheKey] : null;
    }

    $row = orders_sync_moysklad_option_raw_by_name(orders_sync_moysklad_productfolders_options($account), $name)
        ?? orders_sync_moysklad_find_entity_by_exact_name($account, 'productfolder', $name);
    if (is_array($row)) {
        return $cache[$cacheKey] = $row;
    }

    try {
        $created = orders_sync_moysklad_request($account, 'POST', 'entity/productfolder', ['name' => $name]);
        return $cache[$cacheKey] = is_array($created) ? $created : null;
    } catch (Throwable) {
        return $cache[$cacheKey] = null;
    }
}

function orders_sync_moysklad_ensure_counterparty_by_name(array $account, string $name): ?array
{
    static $cache = [];
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $cacheKey = (int)($account['id'] ?? 0) . ':' . orders_sync_moysklad_name_key($name);
    if (array_key_exists($cacheKey, $cache)) {
        return is_array($cache[$cacheKey]) ? $cache[$cacheKey] : null;
    }

    $row = orders_sync_moysklad_option_raw_by_name(orders_sync_moysklad_counterparties_options($account), $name)
        ?? orders_sync_moysklad_find_entity_by_exact_name($account, 'counterparty', $name);
    if (is_array($row)) {
        return $cache[$cacheKey] = $row;
    }

    try {
        $created = orders_sync_moysklad_request($account, 'POST', 'entity/counterparty', ['name' => $name]);
        return $cache[$cacheKey] = is_array($created) ? $created : null;
    } catch (Throwable) {
        return $cache[$cacheKey] = null;
    }
}

function orders_sync_moysklad_supplier_product_refs(array $account, string $offerId): array
{
    $supplierCode = bundle_offer_supplier_code($offerId);
    if ($supplierCode === '' && !str_contains($offerId, '__')) {
        $internalOfferId = bundle_offer_yandex_offer_id_to_internal($offerId, orders_sync_yandex_supplier_codes());
        $supplierCode = bundle_offer_supplier_code($internalOfferId);
    }
    $supplier = orders_sync_supplier_profile_by_code($supplierCode);
    $supplierName = trim((string)($supplier['name'] ?? ''));
    if ($supplierName === '') {
        return [];
    }

    $refs = [];
    $folder = orders_sync_moysklad_ensure_productfolder_by_name($account, $supplierName);
    if (is_array($folder) && trim((string)($folder['id'] ?? '')) !== '') {
        $refs['productFolder'] = orders_sync_moysklad_ref($account, 'entity/productfolder', (string)$folder['id'], 'productfolder');
    }
    $counterparty = orders_sync_moysklad_ensure_counterparty_by_name($account, $supplierName);
    if (is_array($counterparty) && trim((string)($counterparty['id'] ?? '')) !== '') {
        $refs['supplier'] = orders_sync_moysklad_ref($account, 'entity/counterparty', (string)$counterparty['id'], 'counterparty');
    }
    return $refs;
}

function orders_sync_marketplace_product_offer_id(array $product): string
{
    $fallback = '';
    foreach (['offer_id', 'offerId', 'vendorCode', 'vendor_code', 'supplierArticle', 'supplier_article', 'article'] as $field) {
        $value = trim((string)($product[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        if (str_contains($value, '__')) {
            return $value;
        }
        if ($fallback === '') {
            $fallback = $value;
        }
    }
    return $fallback;
}

function orders_sync_moysklad_position_product(array $product): array
{
    $offerId = orders_sync_marketplace_product_offer_id($product);
    if ($offerId !== '') {
        $product['offer_id'] = $offerId;
    }
    $code = $offerId !== '' ? bundle_offer_article_without_supplier_code($offerId) : '';
    $currentName = trim((string)($product['name'] ?? ''));
    if (orders_sync_order_product_name_is_placeholder($currentName, $offerId, $code)) {
        $supplierName = orders_sync_supplier_product_name_for_order_product($product, $offerId, $code);
        if ($supplierName !== '') {
            $product['name'] = $supplierName;
        }
    }

    $bundle = bundle_offer_parse($offerId);
    if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
        return $product;
    }

    $bundleQty = max(1, (int)($bundle['bundle_qty'] ?? 1));
    $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
    if ($bundleQty <= 1 || $baseOfferId === '') {
        return $product;
    }

    $quantity = max(0.0, (float)($product['quantity'] ?? 1));
    $price = (float)((string)($product['price'] ?? '0'));
    $baseName = orders_sync_supplier_product_name_by_offer_id($baseOfferId);

    $out = $product;
    $out['_feedtools_bundle_offer_id'] = $offerId;
    $out['_feedtools_bundle_qty'] = $bundleQty;
    $out['_feedtools_bundle_original_quantity'] = $quantity;
    $out['_feedtools_bundle_original_price'] = $price;
    $out['offer_id'] = $baseOfferId;
    $out['sku'] = $baseOfferId;
    $out['quantity'] = bundle_offer_moysklad_quantity($offerId, $quantity);
    $out['price'] = round(bundle_offer_unit_price_from_bundle_price($offerId, $price), 2);
    if ($baseName !== '') {
        $out['name'] = $baseName;
    }

    return $out;
}

function orders_sync_moysklad_resolve_assortment(array $account, array $product): array
{
    static $cache = [];

    $offerId = orders_sync_marketplace_product_offer_id($product);
    $hasSupplierCode = $offerId !== '' && bundle_offer_supplier_code($offerId) !== '';
    $code = bundle_offer_article_without_supplier_code($offerId);
    $name = trim((string)($product['name'] ?? ''));
    if (orders_sync_order_product_name_is_placeholder($name, $offerId, $code)) {
        $supplierName = orders_sync_supplier_product_name_for_order_product($product, $offerId, $code);
        if ($supplierName !== '') {
            $name = $supplierName;
        }
    }
    if ($name === '') {
        $name = $offerId !== '' ? $offerId : 'Товар маркетплейса';
    }
    $cacheKey = sha1(json_encode([
        'account_id' => (int)($account['id'] ?? 0),
        'offer_id' => $offerId,
        'code' => $code,
        'name' => mb_strtolower($name, 'UTF-8'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $match = orders_sync_moysklad_exact_assortment_match($account, $offerId, $code, $name);
    if (is_array($match)) {
        $match = orders_sync_moysklad_maybe_update_placeholder_assortment_name($account, $match, $name, $offerId, $code);
        return $cache[$cacheKey] = orders_sync_moysklad_maybe_update_assortment_identifiers($account, $match, $offerId, $code);
    }

    $payload = [
        'name' => $name,
        'code' => $code !== '' ? $code : ($offerId !== '' ? $offerId : ('ozon-' . substr(sha1($name), 0, 12))),
    ];
    if ($offerId !== '') {
        $payload['article'] = $offerId;
    }
    $supplierRefs = $offerId !== '' ? orders_sync_moysklad_supplier_product_refs($account, $offerId) : [];
    if ($supplierRefs) {
        $payload = array_merge($payload, $supplierRefs);
    }

    try {
        return $cache[$cacheKey] = orders_sync_moysklad_request($account, 'POST', 'entity/product', $payload);
    } catch (Throwable $e) {
        if (!$supplierRefs) {
            throw $e;
        }
        foreach (['productFolder', 'supplier'] as $field) {
            unset($payload[$field]);
        }
        return $cache[$cacheKey] = orders_sync_moysklad_request($account, 'POST', 'entity/product', $payload);
    }
}

function orders_sync_moysklad_ref(array $account, string $entity, string $id, ?string $type = null): array
{
    $entity = trim($entity, '/');
    $id = trim($id);
    return [
        'meta' => [
            'href' => orders_sync_moysklad_base_url_normalize((string)($account['base_url'] ?? '')) . '/' . $entity . '/' . $id,
            'type' => $type !== null ? $type : basename($entity),
            'mediaType' => 'application/json',
        ],
    ];
}

function orders_sync_moysklad_moment_format(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        $ozonTimezone = new DateTimeZone('Europe/Moscow');
        $hasExplicitTimezone = preg_match('~(?:Z|[+\-]\d{2}:\d{2})$~', $value) === 1;
        $moment = $hasExplicitTimezone
            ? new DateTimeImmutable($value)
            : new DateTimeImmutable($value, $ozonTimezone);
        return $moment->setTimezone($ozonTimezone)->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function orders_sync_moysklad_profile_delivery_planned_moment(array $profile, array $posting): ?string
{
    $source = trim((string)($profile['moysklad_delivery_planned_source'] ?? 'order_created_at'));
    if ($source === 'ozon_shipment_date') {
        foreach ([
            (string)($posting['in_process_at'] ?? ''),
            (string)($posting['shipment_date'] ?? ''),
        ] as $candidate) {
            $moment = orders_sync_moysklad_moment_format($candidate);
            if ($moment !== null) {
                return $moment;
            }
        }
        return null;
    }
    if ($source === 'yandex_shipment_date') {
        foreach ([
            (string)($posting['shipment_date'] ?? ''),
            (string)($posting['in_process_at'] ?? ''),
        ] as $candidate) {
            $moment = orders_sync_moysklad_moment_format($candidate);
            if ($moment !== null) {
                return $moment;
            }
        }
        return null;
    }
    foreach ([
        (string)($posting['created_at'] ?? ''),
        (string)($posting['in_process_at'] ?? ''),
    ] as $candidate) {
        $moment = orders_sync_moysklad_moment_format($candidate);
        if ($moment !== null) {
            return $moment;
        }
    }
    return null;
}

function orders_sync_moysklad_order_moment(array $posting): ?string
{
    foreach ([
        (string)($posting['created_at'] ?? ''),
        (string)($posting['in_process_at'] ?? ''),
    ] as $candidate) {
        $moment = orders_sync_moysklad_moment_format($candidate);
        if ($moment !== null) {
            return $moment;
        }
    }
    return null;
}

function orders_sync_moysklad_order_payload_name(array $posting): string
{
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? 'ozon'));
    $source = orders_sync_ozon_source_normalize((string)($posting['_feedtools_source'] ?? 'fbs'));
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));

    if ($marketplace === 'wb' && $source === 'dbw' && $orderNumber !== '') {
        return $orderNumber;
    }

    return $postingNumber !== '' ? $postingNumber : $orderNumber;
}

function orders_sync_moysklad_order_legacy_payload_name(array $posting): string
{
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    return $postingNumber !== '' ? $postingNumber : $orderNumber;
}

function orders_sync_moysklad_order_payload_name_candidates(array $posting): array
{
    $names = [];
    foreach ([
        orders_sync_moysklad_order_payload_name($posting),
        orders_sync_moysklad_order_legacy_payload_name($posting),
    ] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && !in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }
    return $names;
}

function orders_sync_moysklad_build_order_payload(array $account, array $posting, string $source, array $organization, array $counterparty, ?array $store, ?array $state, ?array $project = null, ?array $salesChannel = null, ?string $orderMoment = null, ?string $deliveryPlannedMoment = null, bool $zeroPrices = false): array
{
    $marketplace = orders_sync_marketplace_normalize((string)($posting['_feedtools_marketplace'] ?? 'ozon'));
    $externalPrefix = match ($marketplace) {
        'wb' => 'wb',
        'yandex_market' => 'yandex',
        default => 'ozon',
    };
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    $status = trim((string)($posting['status'] ?? ''));
    $substatus = trim((string)($posting['substatus'] ?? ''));
    $payloadName = orders_sync_moysklad_order_payload_name($posting);

    $positions = [];
    foreach ((array)($posting['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $positionProduct = orders_sync_moysklad_position_product($product);
        $assortment = orders_sync_moysklad_resolve_assortment($account, $positionProduct);
        $assortmentType = (string)($assortment['meta']['type'] ?? '');
        if (!in_array($assortmentType, ['product', 'variant', 'service'], true)) {
            $assortmentType = (string)($assortment['article'] ?? '') === '' ? 'product' : 'product';
        }
        $priceRub = $zeroPrices ? 0.0 : (float)((string)($positionProduct['price'] ?? '0'));
        $positions[] = [
            'quantity' => max(1, (float)($positionProduct['quantity'] ?? 1)),
            'price' => (int)round($priceRub * 100),
            'assortment' => orders_sync_moysklad_ref(
                $account,
                'entity/' . $assortmentType,
                (string)($assortment['id'] ?? ''),
                $assortmentType
            ),
        ];
    }
    if (!$positions) {
        throw new RuntimeException('Не удалось подготовить ни одной позиции заказа для МойСклад.');
    }

    $payload = [
        'name' => $payloadName,
        'externalCode' => $postingNumber !== '' ? $externalPrefix . ':' . $postingNumber : ($externalPrefix . '-order:' . $orderNumber),
        'organization' => orders_sync_moysklad_ref($account, 'entity/organization', (string)($organization['id'] ?? ''), 'organization'),
        'agent' => orders_sync_moysklad_ref($account, 'entity/counterparty', (string)($counterparty['id'] ?? ''), 'counterparty'),
        'positions' => $positions,
    ];
    if ($store && trim((string)($store['id'] ?? '')) !== '') {
        $payload['store'] = orders_sync_moysklad_ref($account, 'entity/store', (string)$store['id'], 'store');
    }
    if ($state && trim((string)($state['id'] ?? '')) !== '') {
        $payload['state'] = [
            'meta' => [
                'href' => trim((string)($state['meta']['href'] ?? '')),
                'type' => 'state',
                'mediaType' => 'application/json',
            ],
        ];
    }
    if ($project && trim((string)($project['id'] ?? '')) !== '') {
        $payload['project'] = orders_sync_moysklad_ref($account, 'entity/project', (string)$project['id'], 'project');
    }
    if ($salesChannel && trim((string)($salesChannel['id'] ?? '')) !== '') {
        $payload['salesChannel'] = orders_sync_moysklad_ref($account, 'entity/saleschannel', (string)$salesChannel['id'], 'saleschannel');
    }
    if (trim((string)$orderMoment) !== '') {
        $payload['moment'] = trim((string)$orderMoment);
    }
    if (trim((string)$deliveryPlannedMoment) !== '') {
        $payload['deliveryPlannedMoment'] = trim((string)$deliveryPlannedMoment);
    }
    return $payload;
}

function orders_sync_moysklad_export_context(array $profile, array $connection, array $moyskladAccount, string $source, array $posting, array $cfg = []): array
{
    $marketplace = orders_sync_marketplace_from_profile($profile);
    $source = orders_sync_ozon_source_normalize($source);
    $profileId = (int)($profile['id'] ?? 0);
    $connectionId = (int)($connection['id'] ?? 0);
    $moyskladAccountId = (int)($moyskladAccount['id'] ?? 0);
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    $externalOrderId = trim((string)($posting['order_id'] ?? ''));
    $ozonStatusRaw = strtolower(trim((string)($posting['status'] ?? '')));
    $ozonStatus = orders_sync_marketplace_effective_status($cfg, $marketplace, $source, $posting);
    $zeroPricesForCancelled = !empty($profile['cancelled_before_ship_zero_prices'])
        && orders_sync_marketplace_is_simple_cancelled_before_ship($marketplace, $posting, $ozonStatus);
    $posting['_feedtools_source'] = $source;
    $posting['_feedtools_marketplace'] = $marketplace;
    $posting['_feedtools_effective_status'] = $ozonStatus;
    $sourceFingerprint = orders_sync_export_source_fingerprint($profile, $source, $posting, $cfg);
    $existingLink = is_array($posting['_feedtools_existing_link'] ?? null) ? $posting['_feedtools_existing_link'] : null;
    if (!is_array($existingLink) || trim((string)($existingLink['moysklad_customerorder_id'] ?? '')) === '') {
        $existingLink = orders_sync_export_link_get($profileId, $marketplace, $source, $postingNumber, $cfg);
        if ((!is_array($existingLink) || trim((string)($existingLink['moysklad_customerorder_id'] ?? '')) === '') && $connectionId > 0 && $moyskladAccountId > 0) {
            $existingLink = orders_sync_export_link_get_for_scope(
                $connectionId,
                $moyskladAccountId,
                $marketplace,
                $source,
                $postingNumber,
                $cfg
            );
        }
    }

    return [
        'profile_id' => $profileId,
        'profile_title' => (string)($profile['title'] ?? ''),
        'connection_id' => $connectionId,
        'connection_title' => (string)($connection['title'] ?? ''),
        'moysklad_account_id' => $moyskladAccountId,
        'moysklad_title' => (string)($moyskladAccount['title'] ?? ''),
        'marketplace' => $marketplace,
        'marketplace_label' => price_tool_marketplace_label($marketplace),
        'source' => $source,
        'posting' => $posting,
        'posting_number' => $postingNumber,
        'order_number' => $orderNumber,
        'external_order_id' => $externalOrderId,
        'ozon_status_raw' => $ozonStatusRaw,
        'ozon_status' => $ozonStatus,
        'zero_prices_applied' => $zeroPricesForCancelled,
        'source_fingerprint' => $sourceFingerprint,
        'existing_link' => $existingLink,
    ];
}

function orders_sync_moysklad_export_skip_unchanged_result(array $context, string $exportMode, ?array $moyskladAccount = null): ?array
{
    if ($exportMode === 'test') {
        return null;
    }
    $existingLink = is_array($context['existing_link'] ?? null) ? $context['existing_link'] : null;
    if (!orders_sync_export_link_should_skip_unchanged($existingLink, (string)($context['source_fingerprint'] ?? ''))) {
        return null;
    }

    $existingResponse = is_array($existingLink['response'] ?? null) ? $existingLink['response'] : [];
    if (is_array($moyskladAccount)) {
        $posting = is_array($context['posting'] ?? null) ? $context['posting'] : [];
        $payloadName = orders_sync_moysklad_order_payload_name($posting);
        if ($payloadName === '') {
            $contextPostingNumber = trim((string)($context['posting_number'] ?? ''));
            $contextOrderNumber = trim((string)($context['order_number'] ?? ''));
            $payloadName = $contextPostingNumber !== '' ? $contextPostingNumber : $contextOrderNumber;
        }
        $payloadNameAliases = orders_sync_moysklad_order_payload_name_candidates($posting);
        $existingResponse = orders_sync_moysklad_existing_customerorder_resolve(
            $moyskladAccount,
            (int)($context['profile_id'] ?? 0),
            (int)($context['connection_id'] ?? 0),
            (int)($context['moysklad_account_id'] ?? 0),
            (string)($context['marketplace'] ?? 'ozon'),
            (string)($context['source'] ?? 'fbs'),
            (string)($context['posting_number'] ?? ''),
            $payloadName,
            $existingLink,
            [],
            $payloadNameAliases
        ) ?? [];
        if (!$existingResponse) {
            return null;
        }
        if (orders_sync_moysklad_customerorder_needs_restore($moyskladAccount, $existingResponse)) {
            return null;
        }
        if ($payloadName !== '' && trim((string)($existingResponse['name'] ?? '')) !== $payloadName) {
            return null;
        }
    }
    $existingStateName = trim((string)($existingResponse['state']['name'] ?? ''));
    $marketplace = orders_sync_marketplace_normalize((string)($context['marketplace'] ?? 'ozon'));
    return [
        'ok' => true,
        'profile_id' => (int)($context['profile_id'] ?? 0),
        'profile_title' => (string)($context['profile_title'] ?? ''),
        'connection_id' => (int)($context['connection_id'] ?? 0),
        'connection_title' => (string)($context['connection_title'] ?? ''),
        'moysklad_account_id' => (int)($context['moysklad_account_id'] ?? 0),
        'moysklad_title' => (string)($context['moysklad_title'] ?? ''),
        'source' => (string)($context['source'] ?? 'fbs'),
        'posting_number' => (string)($context['posting_number'] ?? ''),
        'order_number' => (string)($context['order_number'] ?? ''),
        'ozon_status' => (string)($context['posting']['status'] ?? ''),
        'ozon_effective_status' => (string)($context['ozon_status'] ?? ''),
        'ozon_effective_status_label' => orders_sync_marketplace_status_label($marketplace, (string)($context['ozon_status'] ?? '')),
        'ozon_substatus' => (string)($context['posting']['substatus'] ?? ''),
        'zero_prices_applied' => !empty($context['zero_prices_applied']),
        'moysklad_state_name' => $existingStateName,
        'moysklad_customerorder_id' => trim((string)($existingLink['moysklad_customerorder_id'] ?? '')),
        'moysklad_customerorder_name' => trim((string)($existingResponse['name'] ?? '')),
        'moysklad_counterparty_name' => '',
        'organization_name' => '',
        'project_name' => '',
        'saleschannel_name' => '',
        'store_name' => trim((string)($existingResponse['store']['name'] ?? '')),
        'mode' => 'skipped_unchanged',
        'positions_count' => count((array)($context['posting']['products'] ?? [])),
        'request' => is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [],
        'response' => $existingResponse,
    ];
}

function orders_sync_moysklad_export_result(array $context, string $stateName, string $mode, array $payload, array $response, array $counterparty, array $organization, ?array $project, ?array $salesChannel, ?array $store, string $existingOrderId = ''): array
{
    $moyskladOrderId = trim((string)($response['id'] ?? ''));
    $marketplace = orders_sync_marketplace_normalize((string)($context['marketplace'] ?? 'ozon'));
    return [
        'ok' => true,
        'profile_id' => (int)($context['profile_id'] ?? 0),
        'profile_title' => (string)($context['profile_title'] ?? ''),
        'connection_id' => (int)($context['connection_id'] ?? 0),
        'connection_title' => (string)($context['connection_title'] ?? ''),
        'moysklad_account_id' => (int)($context['moysklad_account_id'] ?? 0),
        'moysklad_title' => (string)($context['moysklad_title'] ?? ''),
        'source' => (string)($context['source'] ?? 'fbs'),
        'posting_number' => (string)($context['posting_number'] ?? ''),
        'order_number' => (string)($context['order_number'] ?? ''),
        'ozon_status' => (string)($context['posting']['status'] ?? ''),
        'ozon_effective_status' => (string)($context['ozon_status'] ?? ''),
        'ozon_effective_status_label' => orders_sync_marketplace_status_label($marketplace, (string)($context['ozon_status'] ?? '')),
        'ozon_substatus' => (string)($context['posting']['substatus'] ?? ''),
        'zero_prices_applied' => !empty($context['zero_prices_applied']),
        'moysklad_state_name' => $stateName,
        'moysklad_customerorder_id' => $moyskladOrderId !== '' ? $moyskladOrderId : $existingOrderId,
        'moysklad_customerorder_name' => (string)($response['name'] ?? ''),
        'moysklad_counterparty_name' => (string)($counterparty['name'] ?? ''),
        'organization_name' => (string)($organization['name'] ?? ''),
        'project_name' => (string)($project['name'] ?? ''),
        'saleschannel_name' => (string)($salesChannel['name'] ?? ''),
        'store_name' => (string)($store['name'] ?? ''),
        'mode' => $mode,
        'positions_count' => count((array)($payload['positions'] ?? [])),
        'request' => $payload,
        'response' => $response,
    ];
}

function orders_sync_moysklad_state_ref(?array $state): ?array
{
    if (!is_array($state)) {
        return null;
    }
    $href = trim((string)($state['meta']['href'] ?? ''));
    if ($href === '') {
        return null;
    }
    return [
        'meta' => [
            'href' => $href,
            'type' => 'state',
            'mediaType' => 'application/json',
        ],
    ];
}

function orders_sync_moysklad_ref_href_value($value): string
{
    if (!is_array($value)) {
        return '';
    }
    return trim((string)($value['meta']['href'] ?? ''));
}

function orders_sync_moysklad_datetime_compare_value($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('~^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})~', $value, $m)) {
        return $m[1] . ' ' . $m[2];
    }
    return $value;
}

function orders_sync_moysklad_customerorder_positions(array $account, array $existingOrder): array
{
    $rows = $existingOrder['positions']['rows'] ?? null;
    if (is_array($rows)) {
        return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    }

    $orderId = trim((string)($existingOrder['id'] ?? ''));
    if ($orderId === '') {
        return [];
    }

    $response = orders_sync_moysklad_request(
        $account,
        'GET',
        'entity/customerorder/' . $orderId . '/positions',
        null,
        ['limit' => 1000]
    );
    $rows = $response['rows'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
}

function orders_sync_moysklad_position_signature(array $position): ?array
{
    $assortmentHref = orders_sync_moysklad_ref_href_value($position['assortment'] ?? null);
    if ($assortmentHref === '') {
        return null;
    }

    return [
        'assortment' => $assortmentHref,
        'quantity' => round((float)($position['quantity'] ?? 0), 6),
        'price' => (int)round((float)($position['price'] ?? 0)),
        'discount' => round((float)($position['discount'] ?? 0), 6),
        'vat' => (int)($position['vat'] ?? 0),
    ];
}

function orders_sync_moysklad_positions_signature(array $positions): array
{
    $signature = [];
    foreach ($positions as $position) {
        if (!is_array($position)) {
            continue;
        }
        $row = orders_sync_moysklad_position_signature($position);
        if ($row !== null) {
            $signature[] = $row;
        }
    }
    usort($signature, static function (array $a, array $b): int {
        return strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    });
    return $signature;
}

function orders_sync_moysklad_positions_same(array $existingPositions, array $desiredPositions): bool
{
    return orders_sync_moysklad_positions_signature($existingPositions) === orders_sync_moysklad_positions_signature($desiredPositions);
}

function orders_sync_moysklad_positions_have_zero_prices(array $positions): bool
{
    foreach ($positions as $position) {
        if (!is_array($position)) {
            continue;
        }
        if ((float)($position['quantity'] ?? 0) > 0 && (float)($position['price'] ?? 0) <= 0.0) {
            return true;
        }
    }
    return false;
}

function orders_sync_posting_has_nonzero_moysklad_prices(array $posting): bool
{
    foreach ((array)($posting['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $moyskladProduct = orders_sync_moysklad_position_product($product);
        if ((float)($moyskladProduct['price'] ?? $product['price'] ?? 0) > 0.0) {
            return true;
        }
    }
    return false;
}

function orders_sync_moysklad_should_restore_zeroed_cancelled_positions(
    array $profile,
    array $account,
    string $marketplace,
    array $posting,
    string $effectiveStatus,
    array $existingOrder
): bool {
    if (empty($profile['cancelled_before_ship_zero_prices'])) {
        return false;
    }
    if (!orders_sync_marketplace_status_is_cancelled($marketplace, $effectiveStatus)) {
        return false;
    }
    if (orders_sync_marketplace_is_simple_cancelled_before_ship($marketplace, $posting, $effectiveStatus)) {
        return false;
    }
    if (!orders_sync_posting_has_nonzero_moysklad_prices($posting)) {
        return false;
    }

    return orders_sync_moysklad_positions_have_zero_prices(
        orders_sync_moysklad_customerorder_positions($account, $existingOrder)
    );
}

function orders_sync_moysklad_order_update_payload(array $account, array $existingOrder, array $desiredPayload): array
{
    $payload = [];

    foreach (['name', 'externalCode'] as $field) {
        if (!array_key_exists($field, $desiredPayload)) {
            continue;
        }
        if (trim((string)($existingOrder[$field] ?? '')) !== trim((string)($desiredPayload[$field] ?? ''))) {
            $payload[$field] = $desiredPayload[$field];
        }
    }

    foreach (['moment', 'deliveryPlannedMoment'] as $field) {
        if (!array_key_exists($field, $desiredPayload)) {
            continue;
        }
        if (orders_sync_moysklad_datetime_compare_value($existingOrder[$field] ?? '') !== orders_sync_moysklad_datetime_compare_value($desiredPayload[$field] ?? '')) {
            $payload[$field] = $desiredPayload[$field];
        }
    }

    foreach (['organization', 'agent', 'store', 'state', 'project', 'salesChannel'] as $field) {
        if (!array_key_exists($field, $desiredPayload)) {
            continue;
        }
        if (orders_sync_moysklad_ref_href_value($existingOrder[$field] ?? null) !== orders_sync_moysklad_ref_href_value($desiredPayload[$field] ?? null)) {
            $payload[$field] = $desiredPayload[$field];
        }
    }

    if (isset($desiredPayload['positions']) && is_array($desiredPayload['positions'])) {
        $existingPositions = orders_sync_moysklad_customerorder_positions($account, $existingOrder);
        if (!orders_sync_moysklad_positions_same($existingPositions, $desiredPayload['positions'])) {
            $payload['positions'] = $desiredPayload['positions'];
        }
    }

    return $payload;
}

function orders_sync_moysklad_status_update_payload(array $account, array $existingOrder, ?array $existingLink, ?array $targetState, bool $zeroPrices, ?array $fallbackPayload = null, bool $restorePositions = false): array
{
    $payload = [];

    $stateRef = is_array($targetState)
        ? orders_sync_moysklad_state_ref($targetState)
        : orders_sync_moysklad_state_ref(is_array($existingOrder['state'] ?? null) ? $existingOrder['state'] : null);
    if (is_array($stateRef) && orders_sync_moysklad_ref_href_value($existingOrder['state'] ?? null) !== orders_sync_moysklad_ref_href_value($stateRef)) {
        $payload['state'] = $stateRef;
    }

    if ($zeroPrices) {
        $sourcePayload = is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [];
        if (!$sourcePayload || empty($sourcePayload['positions'])) {
            $sourcePayload = is_array($fallbackPayload) ? $fallbackPayload : [];
        }
        if (!$sourcePayload || empty($sourcePayload['positions'])) {
            throw new RuntimeException('Не удалось подготовить позиции для обнуления цен заказа в МойСклад.');
        }
        $zeroPricePositions = (array)$sourcePayload['positions'];
        foreach ($zeroPricePositions as $idx => $position) {
            if (!is_array($position)) {
                continue;
            }
            $zeroPricePositions[$idx]['price'] = 0;
        }

        $existingPositions = orders_sync_moysklad_customerorder_positions($account, $existingOrder);
        if (!orders_sync_moysklad_positions_same($existingPositions, $zeroPricePositions)) {
            $payload['positions'] = $zeroPricePositions;
        }
    } elseif ($restorePositions) {
        $sourcePayload = is_array($fallbackPayload) ? $fallbackPayload : [];
        if (!$sourcePayload || empty($sourcePayload['positions'])) {
            throw new RuntimeException('Не удалось подготовить позиции для восстановления цен заказа в МойСклад.');
        }

        $restorePositionsPayload = (array)$sourcePayload['positions'];
        $existingPositions = orders_sync_moysklad_customerorder_positions($account, $existingOrder);
        if (!orders_sync_moysklad_positions_same($existingPositions, $restorePositionsPayload)) {
            $payload['positions'] = $restorePositionsPayload;
        }
    }

    return $payload;
}

function orders_sync_moysklad_create_posting(array $profile, array $connection, array $moyskladAccount, string $source, array $posting, array $cfg = [], string $exportMode = 'bulk_create'): array
{
    $context = orders_sync_moysklad_export_context($profile, $connection, $moyskladAccount, $source, $posting, $cfg);
    $profileId = (int)($context['profile_id'] ?? 0);
    $connectionId = (int)($context['connection_id'] ?? 0);
    $moyskladAccountId = (int)($context['moysklad_account_id'] ?? 0);
    $marketplace = orders_sync_marketplace_normalize((string)($context['marketplace'] ?? 'ozon'));
    $postingNumber = (string)($context['posting_number'] ?? '');
    $orderNumber = (string)($context['order_number'] ?? '');
    $externalOrderId = (string)($context['external_order_id'] ?? '');
    $sourceFingerprint = (string)($context['source_fingerprint'] ?? '');
    $existingLink = is_array($context['existing_link'] ?? null) ? $context['existing_link'] : null;
    $posting = is_array($context['posting'] ?? null) ? $context['posting'] : [];
    $stateName = '';
    $payloadName = orders_sync_moysklad_order_payload_name($posting);
    $payloadNameAliases = orders_sync_moysklad_order_payload_name_candidates($posting);

    $existingOrder = orders_sync_moysklad_existing_customerorder_resolve(
        $moyskladAccount,
        $profileId,
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $source,
        $postingNumber,
        $payloadName,
        $existingLink,
        $cfg,
        $payloadNameAliases
    );
    $existingOrderId = trim((string)($existingOrder['id'] ?? ''));
    $response = [];
    $mode = 'created';
    $persistFingerprint = $sourceFingerprint;
    $persistRequest = [];
    $persistResponse = [];
    $organization = [];
    $counterparty = [];
    $project = null;
    $salesChannel = null;
    $store = null;
    $payload = [];

    if ($existingOrderId !== '') {
        $currentStateName = trim((string)($existingOrder['state']['name'] ?? ''));
        if (orders_sync_moysklad_customerorder_needs_restore($moyskladAccount, $existingOrder)) {
            $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
            $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
            $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
            $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
            $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
            $orderMoment = orders_sync_moysklad_order_moment($posting);
            $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
            $createPolicy = orders_sync_profile_status_policy($profile, $moyskladAccount, (string)($context['ozon_status'] ?? ''), 'create', $posting, $cfg);
            $payload = orders_sync_moysklad_build_order_payload(
                $moyskladAccount,
                $posting,
                $source,
                $organization,
                $counterparty,
                $store,
                $createPolicy['state'] ?? null,
                $project,
                $salesChannel,
                $orderMoment,
                $deliveryPlannedMoment,
                !empty($context['zero_prices_applied'])
            );
            $response = orders_sync_moysklad_customerorder_restore_or_null($moyskladAccount, $existingOrderId, $payload) ?? [];
            if (is_array($response) && trim((string)($response['id'] ?? '')) !== '') {
                $mode = 'restored';
            } else {
                $response = orders_sync_moysklad_request($moyskladAccount, 'POST', 'entity/customerorder', $payload);
                $mode = 'recreated';
            }
            $stateName = (string)($createPolicy['state_name'] ?? '');
            $persistResponse = $response;
            $persistRequest = $payload;
        } else {
            $mode = 'linked_existing';
            $stateName = $currentStateName;
            $nameUpdatePayload = [];
            if ($payloadName !== '' && trim((string)($existingOrder['name'] ?? '')) !== $payloadName) {
                $nameUpdatePayload['name'] = $payloadName;
            }
            if ($nameUpdatePayload) {
                $response = orders_sync_moysklad_request($moyskladAccount, 'PUT', 'entity/customerorder/' . $existingOrderId, $nameUpdatePayload);
                $mode = 'linked_existing_updated_name';
                $persistRequest = $nameUpdatePayload;
                $persistResponse = $response;
            } else {
                $response = $existingOrder;
                $persistFingerprint = '';
                $persistRequest = [];
                $persistResponse = $existingOrder;
            }
        }
    } else {
        $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
        $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
        $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
        $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
        $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
        $orderMoment = orders_sync_moysklad_order_moment($posting);
        $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
        $createPolicy = orders_sync_profile_status_policy($profile, $moyskladAccount, (string)($context['ozon_status'] ?? ''), 'create', $posting, $cfg);
        $payload = orders_sync_moysklad_build_order_payload(
            $moyskladAccount,
            $posting,
            $source,
            $organization,
            $counterparty,
            $store,
            $createPolicy['state'] ?? null,
            $project,
            $salesChannel,
            $orderMoment,
            $deliveryPlannedMoment,
            !empty($context['zero_prices_applied'])
        );
        $stateName = (string)($createPolicy['state_name'] ?? '');
        $response = orders_sync_moysklad_request($moyskladAccount, 'POST', 'entity/customerorder', $payload);
        $persistResponse = $response;
        $persistRequest = $payload;
    }

    $moyskladOrderId = trim((string)($persistResponse['id'] ?? $existingOrderId));
    orders_sync_export_link_upsert([
        'profile_id' => $profileId,
        'connection_id' => $connectionId,
        'moysklad_account_id' => $moyskladAccountId,
        'marketplace' => $marketplace,
        'order_source' => $source,
        'posting_number' => $postingNumber,
        'order_number' => $orderNumber,
        'external_order_id' => $externalOrderId,
        'moysklad_customerorder_id' => $moyskladOrderId,
        'moysklad_counterparty_id' => (string)($counterparty['id'] ?? ''),
        'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'bulk_create',
        'last_status' => $mode,
        'source_fingerprint' => $persistFingerprint,
        'request' => $persistRequest,
        'response' => $persistResponse,
        'error_text' => null,
    ], $cfg);

    return orders_sync_moysklad_export_result(
        $context,
        $stateName,
        $mode,
        $mode === 'linked_existing' ? [] : $payload,
        $response,
        $mode === 'linked_existing' ? [] : $counterparty,
        $mode === 'linked_existing' ? [] : $organization,
        $mode === 'linked_existing' ? null : $project,
        $mode === 'linked_existing' ? null : $salesChannel,
        $mode === 'linked_existing' ? null : $store,
        $existingOrderId
    );
}

function orders_sync_moysklad_update_status_posting(array $profile, array $connection, array $moyskladAccount, string $source, array $posting, array $cfg = [], string $exportMode = 'bulk_status'): array
{
    $context = orders_sync_moysklad_export_context($profile, $connection, $moyskladAccount, $source, $posting, $cfg);
    $context['source_fingerprint'] = orders_sync_status_update_source_fingerprint($profile, $source, $posting, $cfg);

    $profileId = (int)($context['profile_id'] ?? 0);
    $connectionId = (int)($context['connection_id'] ?? 0);
    $moyskladAccountId = (int)($context['moysklad_account_id'] ?? 0);
    $marketplace = orders_sync_marketplace_normalize((string)($context['marketplace'] ?? 'ozon'));
    $postingNumber = (string)($context['posting_number'] ?? '');
    $orderNumber = (string)($context['order_number'] ?? '');
    $externalOrderId = (string)($context['external_order_id'] ?? '');
    $posting = is_array($context['posting'] ?? null) ? $context['posting'] : [];
    $existingLink = is_array($context['existing_link'] ?? null) ? $context['existing_link'] : null;
    $sourceFingerprint = (string)($context['source_fingerprint'] ?? '');

    $payloadName = orders_sync_moysklad_order_payload_name($posting);
    $payloadNameAliases = orders_sync_moysklad_order_payload_name_candidates($posting);
    $existingOrder = orders_sync_moysklad_existing_customerorder_resolve(
        $moyskladAccount,
        $profileId,
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $source,
        $postingNumber,
        $payloadName,
        $existingLink,
        $cfg,
        $payloadNameAliases
    );
    $existingOrderId = trim((string)($existingOrder['id'] ?? ''));
    $currentStateId = orders_sync_moysklad_customerorder_state_id(is_array($existingOrder) ? $existingOrder : []);
    $currentStateName = trim((string)($existingOrder['state']['name'] ?? ''));
    $hasLinkedOrder = is_array($existingLink) && trim((string)($existingLink['moysklad_customerorder_id'] ?? '')) !== '';
    $nameNeedsUpdate = $payloadName !== '' && trim((string)($existingOrder['name'] ?? '')) !== $payloadName;

    if ($existingOrderId === '' || orders_sync_moysklad_customerorder_needs_restore($moyskladAccount, is_array($existingOrder) ? $existingOrder : [])) {
        if ($hasLinkedOrder) {
            orders_sync_export_link_upsert([
                'profile_id' => $profileId,
                'connection_id' => $connectionId,
                'moysklad_account_id' => $moyskladAccountId,
                'marketplace' => $marketplace,
                'order_source' => $source,
                'posting_number' => $postingNumber,
                'order_number' => $orderNumber,
                'external_order_id' => $externalOrderId,
                'moysklad_customerorder_id' => trim((string)($existingLink['moysklad_customerorder_id'] ?? '')),
                'moysklad_counterparty_id' => trim((string)($existingLink['moysklad_counterparty_id'] ?? '')),
                'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'bulk_status',
                'last_status' => 'skipped_missing',
                'source_fingerprint' => $sourceFingerprint,
                'request' => is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [],
                'response' => is_array($existingLink['response'] ?? null) ? $existingLink['response'] : [],
                'error_text' => null,
            ], $cfg);
        }
        return [
            'ok' => true,
            'profile_id' => $profileId,
            'profile_title' => (string)($context['profile_title'] ?? ''),
            'connection_id' => $connectionId,
            'connection_title' => (string)($context['connection_title'] ?? ''),
            'moysklad_account_id' => $moyskladAccountId,
            'moysklad_title' => (string)($context['moysklad_title'] ?? ''),
            'source' => (string)($context['source'] ?? 'fbs'),
            'posting_number' => $postingNumber,
            'order_number' => $orderNumber,
            'ozon_status' => (string)($posting['status'] ?? ''),
            'ozon_effective_status' => (string)($context['ozon_status'] ?? ''),
            'ozon_effective_status_label' => orders_sync_marketplace_status_label($marketplace, (string)($context['ozon_status'] ?? '')),
            'ozon_substatus' => (string)($posting['substatus'] ?? ''),
            'zero_prices_applied' => !empty($context['zero_prices_applied']),
            'moysklad_state_name' => '',
            'moysklad_customerorder_id' => '',
            'moysklad_customerorder_name' => '',
            'moysklad_counterparty_name' => '',
            'organization_name' => '',
            'project_name' => '',
            'saleschannel_name' => '',
            'store_name' => '',
            'mode' => 'skipped_missing',
            'positions_count' => 0,
            'request' => [],
            'response' => [],
        ];
    }

    $updatePolicy = null;
    if (orders_sync_marketplace_status_is_cancelled($marketplace, (string)($context['ozon_status_raw'] ?? $context['ozon_status'] ?? ''))) {
        $updatePolicy = orders_sync_cancelled_transition_policy($profile, $moyskladAccount, $currentStateId, $currentStateName);
    }
    if (!is_array($updatePolicy)) {
        $updatePolicy = orders_sync_profile_status_policy($profile, $moyskladAccount, (string)($context['ozon_status'] ?? ''), 'update', $posting, $cfg);
    }
    $stateName = (string)($updatePolicy['state_name'] ?? '');
    $linkWasMissing = !$hasLinkedOrder;
    $zeroPricesForCancelled = !empty($context['zero_prices_applied']);
    $restoreZeroedCancelledPositions = orders_sync_moysklad_should_restore_zeroed_cancelled_positions(
        $profile,
        $moyskladAccount,
        $marketplace,
        $posting,
        (string)($context['ozon_status'] ?? ''),
        $existingOrder
    );

    if (!$nameNeedsUpdate && !$restoreZeroedCancelledPositions && (($updatePolicy['mode'] ?? '') === 'keep' || (($updatePolicy['mode'] ?? '') === 'none' && !$zeroPricesForCancelled))) {
        $mode = $linkWasMissing ? 'linked_existing' : 'skipped_keep';
        if ($stateName === '') {
            $stateName = $currentStateName;
        }
        orders_sync_export_link_upsert([
            'profile_id' => $profileId,
            'connection_id' => $connectionId,
            'moysklad_account_id' => $moyskladAccountId,
            'marketplace' => $marketplace,
            'order_source' => $source,
            'posting_number' => $postingNumber,
            'order_number' => $orderNumber,
            'external_order_id' => $externalOrderId,
            'moysklad_customerorder_id' => $existingOrderId,
            'moysklad_counterparty_id' => trim((string)($existingLink['moysklad_counterparty_id'] ?? '')),
            'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'bulk_status',
            'last_status' => $mode,
            'source_fingerprint' => $sourceFingerprint,
            'request' => is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [],
            'response' => $existingOrder,
            'error_text' => null,
        ], $cfg);
        return orders_sync_moysklad_export_result($context, $stateName, $mode, [], $existingOrder, [], [], null, null, null, $existingOrderId);
    }

    if (!$nameNeedsUpdate && !$restoreZeroedCancelledPositions && ($updatePolicy['mode'] ?? '') === 'set' && trim((string)($updatePolicy['state_id'] ?? '')) !== '' && $currentStateId === trim((string)($updatePolicy['state_id'] ?? '')) && !$zeroPricesForCancelled) {
        $mode = $linkWasMissing ? 'linked_existing' : 'skipped_same_state';
        if ($stateName === '') {
            $stateName = $currentStateName;
        }
        orders_sync_export_link_upsert([
            'profile_id' => $profileId,
            'connection_id' => $connectionId,
            'moysklad_account_id' => $moyskladAccountId,
            'marketplace' => $marketplace,
            'order_source' => $source,
            'posting_number' => $postingNumber,
            'order_number' => $orderNumber,
            'external_order_id' => $externalOrderId,
            'moysklad_customerorder_id' => $existingOrderId,
            'moysklad_counterparty_id' => trim((string)($existingLink['moysklad_counterparty_id'] ?? '')),
            'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'bulk_status',
            'last_status' => $mode,
            'source_fingerprint' => $sourceFingerprint,
            'request' => is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [],
            'response' => $existingOrder,
            'error_text' => null,
        ], $cfg);
        return orders_sync_moysklad_export_result($context, $stateName, $mode, [], $existingOrder, [], [], null, null, null, $existingOrderId);
    }

    $fallbackPayload = null;
    $cachedPayload = is_array($existingLink['request'] ?? null) ? $existingLink['request'] : [];
    if (!$cachedPayload || ($zeroPricesForCancelled && empty($cachedPayload['positions'])) || $restoreZeroedCancelledPositions) {
        $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
        $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
        $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
        $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
        $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
        $orderMoment = orders_sync_moysklad_order_moment($posting);
        $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
        $fallbackPayload = orders_sync_moysklad_build_order_payload(
            $moyskladAccount,
            $posting,
            $source,
            $organization,
            $counterparty,
            $store,
            ($updatePolicy['mode'] ?? '') === 'set' ? ($updatePolicy['state'] ?? null) : null,
            $project,
            $salesChannel,
            $orderMoment,
            $deliveryPlannedMoment,
            $zeroPricesForCancelled
        );
    } else {
        $organization = [];
        $counterparty = [];
        $project = null;
        $salesChannel = null;
        $store = null;
    }

    $payload = orders_sync_moysklad_status_update_payload(
        $moyskladAccount,
        $existingOrder,
        $existingLink,
        ($updatePolicy['mode'] ?? '') === 'set' ? ($updatePolicy['state'] ?? null) : null,
        $zeroPricesForCancelled,
        $fallbackPayload,
        $restoreZeroedCancelledPositions
    );
    if ($nameNeedsUpdate) {
        $payload['name'] = $payloadName;
    }
    if (!$payload) {
        $response = $existingOrder;
        $mode = 'skipped_remote_same';
    } else {
        $response = orders_sync_moysklad_request($moyskladAccount, 'PUT', 'entity/customerorder/' . $existingOrderId, $payload);
        $mode = 'updated';
    }
    if ($stateName === '') {
        $stateName = trim((string)($response['state']['name'] ?? $currentStateName));
    }

    orders_sync_export_link_upsert([
        'profile_id' => $profileId,
        'connection_id' => $connectionId,
        'moysklad_account_id' => $moyskladAccountId,
        'marketplace' => $marketplace,
        'order_source' => $source,
        'posting_number' => $postingNumber,
        'order_number' => $orderNumber,
        'external_order_id' => $externalOrderId,
        'moysklad_customerorder_id' => $existingOrderId,
        'moysklad_counterparty_id' => (string)($counterparty['id'] ?? trim((string)($existingLink['moysklad_counterparty_id'] ?? ''))),
        'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'bulk_status',
        'last_status' => $mode,
        'source_fingerprint' => $sourceFingerprint,
        'request' => $payload,
        'response' => $response,
        'error_text' => null,
    ], $cfg);

    return orders_sync_moysklad_export_result(
        $context,
        $stateName,
        $mode,
        $payload,
        $response,
        $counterparty,
        $organization,
        $project,
        $salesChannel,
        $store,
        $existingOrderId
    );
}

function orders_sync_moysklad_export_posting(array $profile, array $connection, array $moyskladAccount, string $source, array $posting, array $cfg = [], string $exportMode = 'test', string $operation = 'full'): array
{
    $operation = orders_sync_moysklad_operation_normalize($operation);
    if ($operation === 'create_only') {
        return orders_sync_moysklad_create_posting($profile, $connection, $moyskladAccount, $source, $posting, $cfg, $exportMode);
    }
    if ($operation === 'status_only') {
        return orders_sync_moysklad_update_status_posting($profile, $connection, $moyskladAccount, $source, $posting, $cfg, $exportMode);
    }
    $context = orders_sync_moysklad_export_context($profile, $connection, $moyskladAccount, $source, $posting, $cfg);
    $skipResult = orders_sync_moysklad_export_skip_unchanged_result($context, $exportMode, $moyskladAccount);
    if (is_array($skipResult)) {
        return $skipResult;
    }

    $profileId = (int)($context['profile_id'] ?? 0);
    $connectionId = (int)($context['connection_id'] ?? 0);
    $moyskladAccountId = (int)($context['moysklad_account_id'] ?? 0);
    $marketplace = orders_sync_marketplace_normalize((string)($context['marketplace'] ?? 'ozon'));
    $postingNumber = (string)($context['posting_number'] ?? '');
    $orderNumber = (string)($context['order_number'] ?? '');
    $externalOrderId = (string)($context['external_order_id'] ?? '');
    $ozonStatusRaw = (string)($context['ozon_status_raw'] ?? '');
    $ozonStatus = (string)($context['ozon_status'] ?? '');
    $sourceFingerprint = (string)($context['source_fingerprint'] ?? '');
    $existingLink = is_array($context['existing_link'] ?? null) ? $context['existing_link'] : null;
    $posting = is_array($context['posting'] ?? null) ? $context['posting'] : [];
    $zeroPricesForCancelled = !empty($context['zero_prices_applied']);
    $stateName = '';
    $organization = [];
    $counterparty = [];
    $project = null;
    $salesChannel = null;
    $store = null;
    $payload = [];
    $persistRequest = [];
    $payloadName = orders_sync_moysklad_order_payload_name($posting);
    $payloadNameAliases = orders_sync_moysklad_order_payload_name_candidates($posting);

    $existingOrder = orders_sync_moysklad_existing_customerorder_resolve(
        $moyskladAccount,
        $profileId,
        $connectionId,
        $moyskladAccountId,
        $marketplace,
        $source,
        $postingNumber,
        $payloadName,
        $existingLink,
        $cfg,
        $payloadNameAliases
    );
    $existingOrderId = trim((string)($existingOrder['id'] ?? ''));
    $response = [];
    $mode = 'created';

    if ($existingOrderId !== '') {
        $currentStateId = orders_sync_moysklad_customerorder_state_id($existingOrder);
        $currentStateName = trim((string)($existingOrder['state']['name'] ?? ''));
        $existingOrderNeedsRestore = orders_sync_moysklad_customerorder_needs_restore($moyskladAccount, $existingOrder);
        $updatePolicy = null;
        if (orders_sync_marketplace_status_is_cancelled($marketplace, $ozonStatusRaw !== '' ? $ozonStatusRaw : $ozonStatus)) {
            $updatePolicy = orders_sync_cancelled_transition_policy($profile, $moyskladAccount, $currentStateId, $currentStateName);
        }
        if (!is_array($updatePolicy)) {
            $updatePolicy = orders_sync_profile_status_policy($profile, $moyskladAccount, $ozonStatus, 'update', $posting, $cfg);
        }
        $stateName = (string)($updatePolicy['state_name'] ?? '');

        if ($existingOrderNeedsRestore) {
            $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
            $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
            $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
            $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
            $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
            $orderMoment = orders_sync_moysklad_order_moment($posting);
            $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
            $payload = orders_sync_moysklad_build_order_payload(
                $moyskladAccount,
                $posting,
                $source,
                $organization,
                $counterparty,
                $store,
                ($updatePolicy['mode'] ?? '') === 'set' ? ($updatePolicy['state'] ?? null) : null,
                $project,
                $salesChannel,
                $orderMoment,
                $deliveryPlannedMoment,
                $zeroPricesForCancelled
            );
            $response = orders_sync_moysklad_customerorder_restore_or_null($moyskladAccount, $existingOrderId, $payload) ?? [];
            if (is_array($response) && trim((string)($response['id'] ?? '')) !== '') {
                $mode = 'restored';
            } else {
                $response = orders_sync_moysklad_request($moyskladAccount, 'POST', 'entity/customerorder', $payload);
                $mode = 'recreated';
            }
        } else {
            $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
            $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
            $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
            $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
            $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
            $orderMoment = orders_sync_moysklad_order_moment($posting);
            $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
            $targetState = null;
            if (($updatePolicy['mode'] ?? '') === 'set') {
                $targetState = $updatePolicy['state'] ?? null;
            } elseif (($updatePolicy['mode'] ?? '') === 'keep' && is_array($existingOrder['state'] ?? null)) {
                $targetState = $existingOrder['state'];
            }
            $payload = orders_sync_moysklad_build_order_payload(
                $moyskladAccount,
                $posting,
                $source,
                $organization,
                $counterparty,
                $store,
                is_array($targetState) ? $targetState : null,
                $project,
                $salesChannel,
                $orderMoment,
                $deliveryPlannedMoment,
                $zeroPricesForCancelled
            );
            $persistRequest = $payload;
            $updatePayload = orders_sync_moysklad_order_update_payload($moyskladAccount, $existingOrder, $payload);
            if (!$updatePayload) {
                $response = $existingOrder;
                $payload = [];
                $mode = 'skipped_remote_same';
            } else {
                $payload = $updatePayload;
                $response = orders_sync_moysklad_request($moyskladAccount, 'PUT', 'entity/customerorder/' . $existingOrderId, $payload);
                $mode = ($updatePolicy['mode'] ?? '') === 'keep' ? 'updated_keep_state' : 'updated';
            }
        }

        if ($stateName === '') {
            $stateName = $currentStateName;
        }
    } else {
        $organization = orders_sync_moysklad_profile_organization($moyskladAccount, $profile);
        $counterparty = orders_sync_moysklad_profile_counterparty($moyskladAccount, $profile);
        $project = orders_sync_moysklad_profile_project($moyskladAccount, $profile);
        $salesChannel = orders_sync_moysklad_profile_saleschannel($moyskladAccount, $profile);
        $store = orders_sync_moysklad_profile_store($moyskladAccount, $profile, $posting, $cfg);
        $orderMoment = orders_sync_moysklad_order_moment($posting);
        $deliveryPlannedMoment = orders_sync_moysklad_profile_delivery_planned_moment($profile, $posting);
        $createPolicy = orders_sync_profile_status_policy($profile, $moyskladAccount, $ozonStatus, 'create', $posting, $cfg);
        $payload = orders_sync_moysklad_build_order_payload(
            $moyskladAccount,
            $posting,
            $source,
            $organization,
            $counterparty,
            $store,
            $createPolicy['state'] ?? null,
            $project,
            $salesChannel,
            $orderMoment,
            $deliveryPlannedMoment,
            $zeroPricesForCancelled
        );
        $persistRequest = $payload;
        $stateName = (string)($createPolicy['state_name'] ?? '');
        $response = orders_sync_moysklad_request($moyskladAccount, 'POST', 'entity/customerorder', $payload);
        $mode = 'created';
    }

    $moyskladOrderId = trim((string)($response['id'] ?? $existingOrderId));
    orders_sync_export_link_upsert([
        'profile_id' => $profileId,
        'connection_id' => $connectionId,
        'moysklad_account_id' => $moyskladAccountId,
        'marketplace' => $marketplace,
        'order_source' => $source,
        'posting_number' => $postingNumber,
        'order_number' => $orderNumber,
        'external_order_id' => $externalOrderId,
        'moysklad_customerorder_id' => $moyskladOrderId !== '' ? $moyskladOrderId : $existingOrderId,
        'moysklad_counterparty_id' => (string)($counterparty['id'] ?? ''),
        'export_mode' => trim($exportMode) !== '' ? trim($exportMode) : 'test',
        'last_status' => $mode,
        'source_fingerprint' => $sourceFingerprint,
        'request' => $persistRequest ?: $payload,
        'response' => $response,
        'error_text' => null,
    ], $cfg);

    return orders_sync_moysklad_export_result(
        $context,
        $stateName,
        $mode,
        $payload,
        $response,
        $counterparty,
        $organization,
        $project,
        $salesChannel,
        $store,
        $existingOrderId
    );
}

function orders_sync_ozon_fetch_all_postings(
    array $cfg,
    string $source,
    string $sinceUtc,
    string $toUtc,
    int $limit = 100,
    int $maxPages = 50,
    ?callable $log = null
): array {
    $source = orders_sync_ozon_source_normalize($source);
    $all = [];
    $offset = 0;
    $limit = max(1, min(1000, $limit));
    $maxPages = max(1, min(200, $maxPages));

    for ($page = 1; $page <= $maxPages; $page++) {
        $payload = orders_sync_ozon_list_payload($source, $sinceUtc, $toUtc, $limit, $offset);
        if ($log) {
            $log("page {$page}: offset={$offset}, limit={$limit}\n");
        }
        $response = ozon_post_json(ozon_cfg_or_fail($cfg), orders_sync_ozon_list_path($source), $payload);
        $items = orders_sync_ozon_extract_postings($source, $response);
        if ($log) {
            $log("page {$page}: items=" . count($items) . "\n");
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }
        if (count($items) < $limit) {
            break;
        }
        $offset += $limit;
    }

    return $all;
}

function orders_sync_ozon_source_normalize(string $source): string
{
    $source = strtolower(trim($source));
    return in_array($source, ['fbs', 'fbo', 'dbw', 'dbs', 'fby', 'express', 'laas'], true) ? $source : 'fbs';
}

function orders_sync_ozon_list_path(string $source): string
{
    return $source === 'fbo' ? '/v2/posting/fbo/list' : '/v3/posting/fbs/list';
}

function orders_sync_ozon_list_payload(string $source, string $sinceUtc, string $toUtc, int $limit, int $offset): array
{
    if ($source === 'fbo') {
        return [
            'dir' => 'DESC',
            'filter' => [
                'since' => $sinceUtc,
                'to' => $toUtc,
            ],
            'limit' => $limit,
            'offset' => $offset,
            'translit' => true,
            'with' => [
                'analytics_data' => true,
                'financial_data' => false,
            ],
        ];
    }

    return [
        'dir' => 'DESC',
        'filter' => [
            'since' => $sinceUtc,
            'to' => $toUtc,
        ],
        'limit' => $limit,
        'offset' => $offset,
        'with' => [
            'analytics_data' => true,
            'barcodes' => true,
            'financial_data' => false,
        ],
    ];
}

function orders_sync_ozon_extract_postings(string $source, array $response): array
{
    if ($source === 'fbo') {
        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        return [];
    }
    return is_array($result['postings'] ?? null) ? $result['postings'] : [];
}

function orders_sync_yandex_supported_sources(): array
{
    return ['fby', 'fbs', 'dbs', 'express', 'laas'];
}

function orders_sync_yandex_program_type(string $source): string
{
    $source = orders_sync_ozon_source_normalize($source);
    return match ($source) {
        'fby' => 'FBY',
        'dbs' => 'DBS',
        'express' => 'EXPRESS',
        'laas' => 'LAAS',
        default => 'FBS',
    };
}

function orders_sync_yandex_source_from_program_type(string $programType): string
{
    $programType = strtoupper(trim($programType));
    return match ($programType) {
        'FBY' => 'fby',
        'DBS' => 'dbs',
        'EXPRESS' => 'express',
        'LAAS' => 'laas',
        default => 'fbs',
    };
}

function orders_sync_yandex_campaign_context(array $connection): array
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
    $businessId = (int)($business['id'] ?? $campaign['businessId'] ?? $campaign['business_id'] ?? 0);
    $campaignId = (int)($resolved['campaign_id'] ?? $campaign['id'] ?? 0);

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

function orders_sync_yandex_warehouses_list(array $connection, array $cfg = []): array
{
    $context = orders_sync_yandex_campaign_context($connection);
    $businessId = (int)$context['business_id'];
    $campaignId = (int)$context['campaign_id'];
    $cacheKey = 'yandex:orders-sync:warehouses:' . sha1(json_encode([
        'connection_id' => (int)($connection['id'] ?? 0),
        'campaign_id' => $campaignId,
        'business_id' => $businessId,
        'api_key_hash' => sha1((string)($connection['api_key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = orders_sync_ui_cache_read($cacheKey, 300);
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

    orders_sync_ui_cache_write($cacheKey, ['warehouses' => $warehouses]);
    return $warehouses;
}

function orders_sync_yandex_period_chunks(string $sinceUtc, string $toUtc): array
{
    $tz = new DateTimeZone('Europe/Moscow');
    $from = (new DateTimeImmutable($sinceUtc))->setTimezone($tz);
    $to = (new DateTimeImmutable($toUtc))->setTimezone($tz);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $cursor = new DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $tz);
    $endExclusive = (new DateTimeImmutable($to->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day');
    $chunks = [];
    while ($cursor < $endExclusive) {
        $chunkEndExclusive = $cursor->modify('+30 days');
        if ($chunkEndExclusive > $endExclusive) {
            $chunkEndExclusive = $endExclusive;
        }
        $chunks[] = [$cursor->format('Y-m-d'), $chunkEndExclusive->format('Y-m-d')];
        $cursor = $chunkEndExclusive;
    }

    return $chunks ?: [[$from->format('Y-m-d'), $from->modify('+1 day')->format('Y-m-d')]];
}

function orders_sync_yandex_nested_value(array $row, array $path)
{
    $current = $row;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null;
        }
        $current = $current[$key];
    }
    return $current;
}

function orders_sync_yandex_money_value($value): ?float
{
    if (is_numeric($value)) {
        return (float)$value;
    }
    if (is_array($value)) {
        foreach (['value', 'amount', 'price'] as $field) {
            if (is_numeric($value[$field] ?? null)) {
                return (float)$value[$field];
            }
        }
    }
    return null;
}

function orders_sync_yandex_prices_total(array $prices): ?float
{
    $hasAny = false;
    $total = 0.0;
    foreach (['payment', 'cashback', 'subsidy'] as $field) {
        $value = orders_sync_yandex_money_value($prices[$field] ?? null);
        if ($value === null) {
            continue;
        }
        $hasAny = true;
        $total += $value;
    }
    return $hasAny ? $total : null;
}

function orders_sync_yandex_item_price_rub(array $item, float $quantity, ?float $orderTotal = null, int $itemsCount = 1): float
{
    $prices = is_array($item['prices'] ?? null) ? $item['prices'] : [];
    if ($prices) {
        $lineTotal = orders_sync_yandex_prices_total($prices);
        if ($lineTotal !== null) {
            return round($lineTotal / max(1.0, $quantity), 2);
        }
    }

    foreach ([
        ['price'],
        ['buyerPrice'],
        ['buyer_price'],
    ] as $path) {
        $value = orders_sync_yandex_money_value(orders_sync_yandex_nested_value($item, $path));
        if ($value !== null) {
            return round($value, 2);
        }
    }

    if ($orderTotal !== null && $orderTotal > 0 && $itemsCount === 1) {
        return round($orderTotal / max(1.0, $quantity), 2);
    }

    return 0.0;
}

function orders_sync_yandex_order_total_rub(array $order): ?float
{
    $prices = is_array($order['prices'] ?? null) ? $order['prices'] : [];
    if ($prices) {
        $total = orders_sync_yandex_prices_total($prices);
        if ($total !== null) {
            return $total;
        }
    }

    foreach ([
        ['paymentTotal'],
        ['total'],
    ] as $path) {
        $value = orders_sync_yandex_money_value(orders_sync_yandex_nested_value($order, $path));
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

function orders_sync_yandex_supplier_codes(): array
{
    static $codes = null;
    if (is_array($codes)) {
        return $codes;
    }
    $map = [];
    if (!orders_sync_table_exists('feedtools_suppliers')) {
        return $codes = [];
    }
    try {
        suppliers_table_ensure();
        $rows = db()->query("
            SELECT supplier_code
            FROM feedtools_suppliers
            WHERE supplier_code IS NOT NULL AND supplier_code <> ''
              AND COALESCE(is_archived, 0) = 0
            ORDER BY LENGTH(supplier_code) DESC, supplier_code DESC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) {
        return $codes = [];
    }
    foreach ($rows as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $map[$code] = true;
        }
    }
    return $codes = array_keys($map);
}

function orders_sync_supplier_product_offer_exists(string $offerId): bool
{
    static $cache = [];
    $offerId = trim($offerId);
    if ($offerId === '') {
        return false;
    }
    if (array_key_exists($offerId, $cache)) {
        return (bool)$cache[$offerId];
    }
    if (!orders_sync_table_exists('feedtools_supplier_products')) {
        return $cache[$offerId] = false;
    }
    try {
        $st = db()->prepare("SELECT 1 FROM feedtools_supplier_products WHERE offer_id = ? LIMIT 1");
        $st->execute([$offerId]);
        return $cache[$offerId] = (bool)$st->fetchColumn();
    } catch (Throwable) {
        return $cache[$offerId] = false;
    }
}

function orders_sync_yandex_internal_offer_candidates(string $offerId): array
{
    $offerId = trim($offerId);
    if ($offerId === '' || str_contains($offerId, '__')) {
        return [];
    }
    $candidates = [];
    foreach (orders_sync_yandex_supplier_codes() as $supplierCode) {
        $supplierCode = trim((string)$supplierCode);
        $needle = '000' . $supplierCode;
        if ($needle === '000' || !str_ends_with($offerId, $needle)) {
            continue;
        }
        $prefix = substr($offerId, 0, -strlen($needle));
        if ($prefix === '') {
            continue;
        }
        $candidates[] = $prefix . '__' . $supplierCode;
    }
    return array_values(array_unique($candidates));
}

function orders_sync_yandex_offer_id_to_internal(string $offerId): string
{
    $offerId = trim($offerId);
    if ($offerId === '' || str_contains($offerId, '__')) {
        return $offerId;
    }
    if (orders_sync_supplier_product_offer_exists($offerId)) {
        return $offerId;
    }
    foreach (orders_sync_yandex_internal_offer_candidates($offerId) as $candidate) {
        if (orders_sync_supplier_product_offer_exists($candidate)) {
            return $candidate;
        }
    }
    return bundle_offer_yandex_offer_id_to_internal($offerId, orders_sync_yandex_supplier_codes());
}

function orders_sync_yandex_order_created_at(array $order): string
{
    foreach (['creationDate', 'createdAt', 'creationDateTime', 'createdDate', 'updateDate'] as $field) {
        $value = trim((string)($order[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function orders_sync_yandex_shipment_date(array $order): string
{
    $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
    $shipment = is_array($delivery['shipment'] ?? null) ? $delivery['shipment'] : [];
    $dates = is_array($delivery['dates'] ?? null) ? $delivery['dates'] : [];
    foreach ([
        (string)($shipment['shipmentDate'] ?? ''),
        (string)($order['shipmentDate'] ?? ''),
        (string)($dates['toDate'] ?? ''),
        (string)($dates['fromDate'] ?? ''),
        (string)($dates['realDeliveryDate'] ?? ''),
    ] as $value) {
        $value = trim($value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function orders_sync_yandex_warehouse_info(array $order): array
{
    $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
    $shipment = is_array($delivery['shipment'] ?? null) ? $delivery['shipment'] : [];
    $warehouseId = trim((string)(
        $delivery['warehouseId']
        ?? $delivery['warehouse_id']
        ?? $shipment['warehouseId']
        ?? $shipment['warehouse_id']
        ?? ''
    ));
    $warehouseName = trim((string)(
        $delivery['warehouseName']
        ?? $delivery['warehouse_name']
        ?? $shipment['warehouseName']
        ?? ''
    ));
    return [$warehouseId, $warehouseName];
}

function orders_sync_yandex_normalize_order(string $source, array $order, array $connection = []): array
{
    $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
    $programSource = orders_sync_yandex_source_from_program_type((string)($order['programType'] ?? $order['program_type'] ?? ''));
    $source = in_array($source, orders_sync_yandex_supported_sources(), true) ? $source : $programSource;
    $orderId = trim((string)($order['orderId'] ?? $order['id'] ?? ''));
    $externalOrderId = trim((string)($order['externalOrderId'] ?? $order['external_order_id'] ?? ''));
    $postingNumber = $orderId !== '' ? $orderId : $externalOrderId;
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $substatus = strtolower(trim((string)($order['substatus'] ?? '')));
    $createdAt = orders_sync_yandex_order_created_at($order);
    [$warehouseId, $warehouseName] = orders_sync_yandex_warehouse_info($order);
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $orderTotal = orders_sync_yandex_order_total_rub($order);
    $products = [];
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $quantity = max(1.0, (float)($item['count'] ?? $item['quantity'] ?? 1));
        $rawOfferId = trim((string)($item['offerId'] ?? $item['shopSku'] ?? $item['marketSku'] ?? $item['id'] ?? ''));
        $offerId = orders_sync_yandex_offer_id_to_internal($rawOfferId);
        $name = trim((string)($item['offerName'] ?? $item['name'] ?? $item['title'] ?? ''));
        if ($offerId === '') {
            $offerId = $postingNumber !== '' ? $postingNumber . '-' . ($idx + 1) : 'yandex-item-' . ($idx + 1);
        }
        if ($name === '') {
            $name = $offerId !== '' ? $offerId : 'Товар Яндекс Маркета';
        }
        $products[] = [
            'offer_id' => $offerId,
            'name' => $name,
            'quantity' => $quantity,
            'price' => orders_sync_yandex_item_price_rub($item, $quantity, $orderTotal, count($items)),
            'sku' => $offerId,
            'marketplace_offer_id' => $rawOfferId,
            'raw_offer_id' => $rawOfferId,
            'item_id' => trim((string)($item['id'] ?? '')),
        ];
    }
    if (!$products && $postingNumber !== '') {
        $products[] = [
            'offer_id' => $postingNumber,
            'name' => 'Заказ Яндекс Маркета ' . $postingNumber,
            'quantity' => 1,
            'price' => $orderTotal !== null ? round($orderTotal, 2) : 0.0,
            'sku' => $postingNumber,
        ];
    }

    return [
        'posting_number' => $postingNumber,
        'order_number' => $externalOrderId !== '' ? $externalOrderId : $postingNumber,
        'order_id' => $orderId !== '' ? $orderId : $postingNumber,
        'externalOrderId' => $externalOrderId,
        'status' => $status,
        'substatus' => $substatus,
        'created_at' => $createdAt,
        'in_process_at' => $createdAt,
        'shipment_date' => orders_sync_yandex_shipment_date($order),
        'delivery_method' => [
            'warehouse_id' => $warehouseId,
            'warehouse' => $warehouseName,
            'service_name' => trim((string)($delivery['serviceName'] ?? '')),
        ],
        'products' => $products,
        '_feedtools_source' => $source,
        '_feedtools_effective_status' => $status,
        '_feedtools_marketplace' => 'yandex_market',
        '_yandex_raw_order' => $order,
    ];
}

function orders_sync_yandex_fetch_all_orders(
    array $connection,
    string $source,
    string $sinceUtc,
    string $toUtc,
    int $limit = 50,
    int $maxPages = 1000,
    array $cfg = [],
    ?callable $log = null
): array {
    $source = orders_sync_ozon_source_normalize($source);
    if (!in_array($source, orders_sync_yandex_supported_sources(), true)) {
        if ($log) {
            $log("source {$source} пропущен: для Яндекс Маркета поддерживаются FBY, FBS, DBS, Express и LaaS.\n");
        }
        return [];
    }

    $limit = max(1, min(50, $limit));
    $maxPages = max(1, min(1000, $maxPages));
    $context = orders_sync_yandex_campaign_context($connection);
    $campaignId = (int)$context['campaign_id'];
    $businessId = (int)$context['business_id'];
    $programType = orders_sync_yandex_program_type($source);
    $ordersByKey = [];

    $chunks = orders_sync_yandex_period_chunks($sinceUtc, $toUtc);
    foreach ($chunks as $chunkIdx => [$dateFrom, $dateToExclusive]) {
        $pageToken = '';
        $seenTokens = [];
        $chunkLabel = ($chunkIdx + 1) . '/' . count($chunks);
        for ($page = 1; $page <= $maxPages; $page++) {
            $query = ['limit' => $limit];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $payload = [
                'campaignIds' => [$campaignId],
                'programTypes' => [$programType],
                'dates' => [
                    'creationDateFrom' => $dateFrom,
                    'creationDateTo' => $dateToExclusive,
                ],
            ];
            if ($log) {
                $log("chunk {$chunkLabel}, page {$page}: {$dateFrom}..{$dateToExclusive}, program={$programType}, limit={$limit}\n");
            }
            $body = marketplace_connection_yandex_request(
                $connection,
                'POST',
                '/v1/businesses/' . $businessId . '/orders',
                $query,
                $payload
            );
            $items = is_array($body['orders'] ?? null) ? $body['orders'] : [];
            if ($log) {
                $log("chunk {$chunkLabel}, page {$page}: items=" . count($items) . "\n");
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $posting = orders_sync_yandex_normalize_order($source, $item, $connection);
                $key = trim((string)($posting['posting_number'] ?? ''));
                if ($key !== '') {
                    $ordersByKey[$key] = $posting;
                }
            }
            $nextToken = trim((string)($body['paging']['nextPageToken'] ?? ''));
            if ($nextToken === '' || isset($seenTokens[$nextToken])) {
                break;
            }
            $seenTokens[$nextToken] = true;
            $pageToken = $nextToken;
        }
    }

    return array_values($ordersByKey);
}

function orders_sync_yandex_posting_matches_reference(array $posting, string $orderRef): bool
{
    $orderRef = trim($orderRef);
    if ($orderRef === '') {
        return false;
    }
    $orderRefLower = mb_strtolower($orderRef, 'UTF-8');
    $candidates = [
        (string)($posting['posting_number'] ?? ''),
        (string)($posting['order_number'] ?? ''),
        (string)($posting['order_id'] ?? ''),
        (string)($posting['externalOrderId'] ?? ''),
    ];
    foreach ((array)($posting['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $candidates[] = (string)($product['offer_id'] ?? '');
        $candidates[] = (string)($product['sku'] ?? '');
    }
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && mb_strtolower($candidate, 'UTF-8') === $orderRefLower) {
            return true;
        }
    }
    return false;
}

function orders_sync_wb_period_chunks(string $sinceUtc, string $toUtc): array
{
    $from = new DateTimeImmutable($sinceUtc);
    $to = new DateTimeImmutable($toUtc);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $chunks = [];
    $cursor = $from;
    while ($cursor <= $to) {
        $chunkTo = $cursor->modify('+29 days')->setTime(23, 59, 59);
        if ($chunkTo > $to) {
            $chunkTo = $to;
        }
        $chunks[] = [$cursor, $chunkTo];
        $cursor = $chunkTo->modify('+1 second');
    }
    return $chunks ?: [[$from, $to]];
}

function orders_sync_wb_supported_sources(): array
{
    return ['fbs', 'dbw', 'dbs'];
}

function orders_sync_wb_order_id(array $order): int
{
    foreach (['id', 'orderId', 'order_id'] as $field) {
        if (is_numeric($order[$field] ?? null)) {
            return (int)$order[$field];
        }
    }
    return 0;
}

function orders_sync_wb_status_order_id(array $statusRow): int
{
    foreach (['id', 'orderId', 'order_id'] as $field) {
        if (is_numeric($statusRow[$field] ?? null)) {
            return (int)$statusRow[$field];
        }
    }
    return 0;
}

function orders_sync_wb_created_at(array $order): string
{
    foreach (['createdAt', 'created_at', 'dateCreated', 'date_created'] as $field) {
        $value = trim((string)($order[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function orders_sync_wb_order_in_period(array $order, string $sinceUtc, string $toUtc): bool
{
    $createdAt = orders_sync_wb_created_at($order);
    if ($createdAt === '') {
        return true;
    }
    try {
        $created = (new DateTimeImmutable($createdAt))->setTimezone(new DateTimeZone('UTC'));
        $from = (new DateTimeImmutable($sinceUtc))->setTimezone(new DateTimeZone('UTC'));
        $to = (new DateTimeImmutable($toUtc))->setTimezone(new DateTimeZone('UTC'));
        return $created >= $from && $created <= $to;
    } catch (Throwable) {
        return true;
    }
}

function orders_sync_wb_statistics_date_from(string $sinceUtc): string
{
    try {
        return (new DateTimeImmutable($sinceUtc))
            ->setTimezone(new DateTimeZone('Europe/Moscow'))
            ->format('Y-m-d');
    } catch (Throwable) {
        return gmdate('Y-m-d');
    }
}

function orders_sync_wb_supplier_report_rows_cached(
    WildberriesClient $client,
    string $kind,
    string $dateFrom,
    int $connectionId = 0,
    ?callable $log = null
): array {
    $kind = $kind === 'sales' ? 'sales' : 'orders';
    $dateFrom = trim($dateFrom) !== '' ? trim($dateFrom) : gmdate('Y-m-d');
    $cacheKey = 'wb:supplier-report:' . sha1(json_encode([
        'kind' => $kind,
        'connection_id' => max(0, $connectionId),
        'date_from' => $dateFrom,
        'flag' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cached = orders_sync_ui_cache_read($cacheKey, 120);
    if (is_array($cached) && is_array($cached['rows'] ?? null)) {
        if ($log) {
            $log("supplier/{$kind}: cache hit, dateFrom={$dateFrom}, rows=" . count($cached['rows']) . "\n");
        }
        return array_values(array_filter($cached['rows'], static fn($row): bool => is_array($row)));
    }

    $rows = $kind === 'sales'
        ? $client->getSupplierSales($dateFrom, 0)
        : $client->getSupplierOrders($dateFrom, 0);
    $rows = is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
    orders_sync_ui_cache_write($cacheKey, [
        'rows' => $rows,
        'fetched_at' => gmdate(DateTimeInterface::ATOM),
    ]);
    return $rows;
}

function orders_sync_wb_supplier_order_datetime(array $row, string $field): ?DateTimeImmutable
{
    $value = trim((string)($row[$field] ?? ''));
    if ($value === '' || str_starts_with($value, '0001-')) {
        return null;
    }
    try {
        $hasTimezone = (bool)preg_match('~(?:Z|[+-]\d{2}:?\d{2})$~', $value);
        $dt = $hasTimezone
            ? new DateTimeImmutable($value)
            : new DateTimeImmutable($value, new DateTimeZone('Europe/Moscow'));
        return $dt->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

function orders_sync_wb_supplier_order_in_period(array $row, string $sinceUtc, string $toUtc): bool
{
    $created = orders_sync_wb_supplier_order_datetime($row, 'date');
    if (!$created) {
        return true;
    }
    try {
        $from = (new DateTimeImmutable($sinceUtc))->setTimezone(new DateTimeZone('UTC'));
        $to = (new DateTimeImmutable($toUtc))->setTimezone(new DateTimeZone('UTC'));
        return $created >= $from && $created <= $to;
    } catch (Throwable) {
        return true;
    }
}

function orders_sync_wb_supplier_order_is_wb_warehouse(array $row): bool
{
    $warehouseType = mb_strtolower(trim((string)($row['warehouseType'] ?? $row['warehouse_type'] ?? '')), 'UTF-8');
    if ($warehouseType === '') {
        return false;
    }
    return str_contains($warehouseType, 'склад wb') || str_contains($warehouseType, 'склад вб');
}

function orders_sync_wb_supplier_row_latest_moment(array $row): string
{
    foreach (['lastChangeDate', 'date'] as $field) {
        $dt = orders_sync_wb_supplier_order_datetime($row, $field);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format(DateTimeInterface::ATOM);
        }
    }
    return '';
}

function orders_sync_wb_supplier_sale_is_return(array $row): bool
{
    $saleId = mb_strtolower(trim((string)($row['saleID'] ?? $row['sale_id'] ?? '')), 'UTF-8');
    if ($saleId !== '' && str_starts_with($saleId, 'r')) {
        return true;
    }

    $orderType = mb_strtolower(trim((string)($row['orderType'] ?? $row['order_type'] ?? '')), 'UTF-8');
    return $orderType !== '' && str_contains($orderType, 'возврат');
}

function orders_sync_wb_supplier_sales_index(array $rows): array
{
    $index = [
        'srid' => [],
        'gNumber' => [],
        '_stats' => [
            'rows' => 0,
            'warehouse_wb' => 0,
            'indexed' => 0,
        ],
    ];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $index['_stats']['rows']++;
        if (!orders_sync_wb_supplier_order_is_wb_warehouse($row)) {
            continue;
        }
        $index['_stats']['warehouse_wb']++;

        $keys = [];
        $srid = trim((string)($row['srid'] ?? ''));
        if ($srid !== '') {
            $keys[] = ['srid', $srid];
        }
        $gNumber = trim((string)($row['gNumber'] ?? $row['g_number'] ?? ''));
        if ($gNumber !== '') {
            $keys[] = ['gNumber', $gNumber];
        }
        if (!$keys) {
            continue;
        }

        $moment = orders_sync_wb_supplier_row_latest_moment($row);
        $row['_feedtools_sale_is_return'] = orders_sync_wb_supplier_sale_is_return($row);
        $row['_feedtools_sort_moment'] = $moment;
        foreach ($keys as [$bucket, $key]) {
            $existing = $index[$bucket][$key] ?? null;
            $existingMoment = is_array($existing) ? (string)($existing['_feedtools_sort_moment'] ?? '') : '';
            if (!is_array($existing) || strcmp($moment, $existingMoment) >= 0) {
                $index[$bucket][$key] = $row;
            }
        }
        $index['_stats']['indexed']++;
    }

    return $index;
}

function orders_sync_wb_supplier_sale_for_order(array $row, ?array $salesIndex): ?array
{
    if (!is_array($salesIndex)) {
        return null;
    }

    $srid = trim((string)($row['srid'] ?? ''));
    if ($srid !== '' && is_array($salesIndex['srid'][$srid] ?? null)) {
        return $salesIndex['srid'][$srid];
    }

    $gNumber = trim((string)($row['gNumber'] ?? $row['g_number'] ?? ''));
    if ($gNumber !== '' && is_array($salesIndex['gNumber'][$gNumber] ?? null)) {
        return $salesIndex['gNumber'][$gNumber];
    }

    return null;
}

function orders_sync_wb_supplier_order_status(array $row, ?array $salesIndex): string
{
    $isCancel = filter_var($row['isCancel'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($isCancel) {
        return 'canceled';
    }

    if (!is_array($salesIndex)) {
        return 'sold';
    }

    $sale = orders_sync_wb_supplier_sale_for_order($row, $salesIndex);
    if (!is_array($sale)) {
        return 'new';
    }

    return !empty($sale['_feedtools_sale_is_return']) ? 'canceled_by_client' : 'sold';
}

function orders_sync_wb_supplier_group_status(array $statuses): string
{
    $statuses = array_values(array_filter(array_map(
        static fn($value): string => strtolower(trim((string)$value)),
        $statuses
    ), static fn(string $value): bool => $value !== ''));
    if (!$statuses) {
        return 'new';
    }

    $allSold = true;
    $allCancelled = true;
    $hasReturn = false;
    foreach ($statuses as $status) {
        if ($status !== 'sold') {
            $allSold = false;
        }
        if (!orders_sync_marketplace_status_is_cancelled('wb', $status)) {
            $allCancelled = false;
        }
        if (in_array($status, ['canceled_by_client', 'reject', 'defect'], true)) {
            $hasReturn = true;
        }
    }

    if ($allSold) {
        return 'sold';
    }
    if ($allCancelled) {
        return $hasReturn ? 'canceled_by_client' : 'canceled';
    }
    return 'new';
}

function orders_sync_wb_supplier_order_posting_number(array $row): string
{
    $srid = trim((string)($row['srid'] ?? ''));
    if ($srid !== '') {
        return mb_substr($srid, 0, 80, 'UTF-8');
    }

    $parts = array_values(array_filter([
        trim((string)($row['gNumber'] ?? $row['g_number'] ?? '')),
        trim((string)($row['nmId'] ?? $row['nm_id'] ?? '')),
        trim((string)($row['barcode'] ?? '')),
        trim((string)($row['date'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    $fallback = implode(':', $parts);
    if ($fallback === '') {
        $fallback = 'wbw:' . sha1((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (mb_strlen($fallback, 'UTF-8') > 80) {
        $fallback = 'wbw:' . sha1($fallback);
    }
    return $fallback;
}

function orders_sync_wb_supplier_order_price_rub(array $row): float
{
    foreach (['finishedPrice', 'priceWithDisc', 'totalPrice'] as $field) {
        if (array_key_exists($field, $row) && is_numeric($row[$field])) {
            return round((float)$row[$field], 2);
        }
    }
    return 0.0;
}

function orders_sync_wb_supplier_order_group_key(array $row): string
{
    $gNumber = trim((string)($row['gNumber'] ?? $row['g_number'] ?? ''));
    if ($gNumber !== '') {
        return 'g:' . mb_strtolower($gNumber, 'UTF-8');
    }

    $postingNumber = orders_sync_wb_supplier_order_posting_number($row);
    return $postingNumber !== '' ? ('p:' . $postingNumber) : ('r:' . sha1((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function orders_sync_wb_normalize_supplier_order(array $row, int $connectionId = 0, ?array $salesIndex = null): array
{
    $postingNumber = orders_sync_wb_supplier_order_posting_number($row);
    $gNumber = trim((string)($row['gNumber'] ?? $row['g_number'] ?? ''));
    $srid = trim((string)($row['srid'] ?? ''));
    $article = trim((string)($row['supplierArticle'] ?? $row['supplier_article'] ?? ''));
    $nmId = trim((string)($row['nmId'] ?? $row['nm_id'] ?? ''));
    $barcode = trim((string)($row['barcode'] ?? ''));
    $warehouseName = trim((string)($row['warehouseName'] ?? $row['warehouse_name'] ?? ''));
    $warehouseType = trim((string)($row['warehouseType'] ?? $row['warehouse_type'] ?? ''));
    $created = orders_sync_wb_supplier_order_datetime($row, 'date');
    $lastChanged = orders_sync_wb_supplier_order_datetime($row, 'lastChangeDate');
    $cancelDate = orders_sync_wb_supplier_order_datetime($row, 'cancelDate');
    $status = orders_sync_wb_supplier_order_status($row, $salesIndex);

    $cachedCard = orders_sync_wb_cached_card_for_order($connectionId, $article, $nmId);
    $cachedVendorCode = orders_sync_wb_cached_card_vendor_code($cachedCard);
    $productName = orders_sync_wb_cached_card_title($cachedCard);
    $offerId = $article !== '' ? $article : ($cachedVendorCode !== '' ? $cachedVendorCode : ($nmId !== '' ? $nmId : $postingNumber));
    if ($cachedVendorCode !== '' && ($offerId === '' || !str_contains($offerId, '__') || str_contains($cachedVendorCode, '__'))) {
        $offerId = $cachedVendorCode;
    }
    $offerCode = $offerId !== '' ? bundle_offer_article_without_supplier_code($offerId) : '';
    if (orders_sync_order_product_name_is_placeholder($productName, $offerId, $offerCode)) {
        $supplierName = orders_sync_supplier_product_name_for_order_product([
            'offer_id' => $offerId,
            'vendor_code' => $cachedVendorCode,
            'marketplace_offer_id' => $article,
            'raw_offer_id' => $article,
        ], $offerId, $offerCode);
        if ($supplierName !== '') {
            $productName = $supplierName;
        }
    }
    if ($productName === '') {
        $productName = $article !== '' ? $article : ($nmId !== '' ? 'WB nmID ' . $nmId : 'Товар WB');
    }

    return [
        'posting_number' => $postingNumber,
        'order_number' => $gNumber !== '' ? $gNumber : ($srid !== '' ? $srid : $postingNumber),
        'order_id' => $postingNumber,
        'id' => $postingNumber,
        'orderUid' => $gNumber,
        'rid' => $srid,
        'article' => $article,
        'nmId' => $nmId,
        'status' => $status,
        'substatus' => $warehouseType,
        'supplierStatus' => $status,
        'wbStatus' => $status,
        'created_at' => $created ? $created->format(DateTimeInterface::ATOM) : trim((string)($row['date'] ?? '')),
        'in_process_at' => $lastChanged ? $lastChanged->format(DateTimeInterface::ATOM) : ($created ? $created->format(DateTimeInterface::ATOM) : ''),
        'shipment_date' => $cancelDate ? $cancelDate->format(DateTimeInterface::ATOM) : '',
        'delivery_method' => [
            'warehouse_id' => '',
            'warehouse' => $warehouseName,
        ],
        'analytics_data' => [
            'warehouse_name' => $warehouseName,
            'warehouse_type' => $warehouseType,
        ],
        'products' => [[
            'offer_id' => $offerId,
            'name' => $productName,
            'quantity' => 1,
            'price' => orders_sync_wb_supplier_order_price_rub($row),
            'sku' => $barcode,
            'vendor_code' => $cachedVendorCode !== '' ? $cachedVendorCode : $article,
            'marketplace_offer_id' => $article,
            'raw_offer_id' => $article,
            'nm_id' => $nmId,
            'chrt_id' => '',
        ]],
        '_feedtools_source' => 'dbw',
        '_feedtools_effective_status' => $status,
        '_feedtools_marketplace' => 'wb',
        '_wb_supplier_order' => $row,
        '_wb_supplier_sale' => orders_sync_wb_supplier_sale_for_order($row, $salesIndex) ?: null,
    ];
}

function orders_sync_wb_normalize_supplier_order_group(array $rows, int $connectionId = 0, ?array $salesIndex = null): array
{
    $rows = array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    if (!$rows) {
        return [];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp(
            orders_sync_wb_supplier_order_posting_number($a),
            orders_sync_wb_supplier_order_posting_number($b)
        );
    });

    $base = orders_sync_wb_normalize_supplier_order($rows[0], $connectionId, $salesIndex);
    if (count($rows) <= 1) {
        return $base;
    }

    $productsByKey = [];
    $statuses = [];
    $latestChange = null;
    foreach ($rows as $row) {
        $posting = orders_sync_wb_normalize_supplier_order($row, $connectionId, $salesIndex);
        $statuses[] = (string)($posting['status'] ?? '');
        $changed = orders_sync_moysklad_moment_format((string)($posting['in_process_at'] ?? ''));
        if ($changed !== null && ($latestChange === null || strcmp($changed, $latestChange) > 0)) {
            $latestChange = $changed;
        }

        foreach ((array)($posting['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $key = json_encode([
                'offer_id' => (string)($product['offer_id'] ?? ''),
                'sku' => (string)($product['sku'] ?? ''),
                'price' => (string)($product['price'] ?? ''),
                'nm_id' => (string)($product['nm_id'] ?? ''),
                'chrt_id' => (string)($product['chrt_id'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($key) || $key === '') {
                $key = sha1((string)json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (isset($productsByKey[$key])) {
                $productsByKey[$key]['quantity'] = (float)($productsByKey[$key]['quantity'] ?? 0) + (float)($product['quantity'] ?? 1);
            } else {
                $productsByKey[$key] = $product;
                $productsByKey[$key]['quantity'] = max(1, (float)($product['quantity'] ?? 1));
            }
        }
    }

    $status = orders_sync_wb_supplier_group_status($statuses);
    $base['status'] = $status;
    $base['supplierStatus'] = $status;
    $base['wbStatus'] = $status;
    $base['_feedtools_effective_status'] = $status;
    $base['products'] = array_values($productsByKey);
    $base['_wb_supplier_orders'] = $rows;
    if ($latestChange !== null) {
        $base['in_process_at'] = $latestChange;
    }
    return $base;
}

function orders_sync_wb_fetch_supplier_wb_warehouse_orders(
    WildberriesClient $client,
    string $sinceUtc,
    string $toUtc,
    ?callable $log = null,
    int $connectionId = 0
): array {
    $dateFrom = orders_sync_wb_statistics_date_from($sinceUtc);
    if ($log) {
        $log("supplier/orders: dateFrom={$dateFrom}, warehouseType=Склад WB\n");
    }
    $rows = orders_sync_wb_supplier_report_rows_cached($client, 'orders', $dateFrom, $connectionId, $log);

    $salesIndex = null;
    try {
        $salesRows = orders_sync_wb_supplier_report_rows_cached($client, 'sales', $dateFrom, $connectionId, $log);
        $salesIndex = orders_sync_wb_supplier_sales_index($salesRows);
        if ($log) {
            $stats = is_array($salesIndex['_stats'] ?? null) ? $salesIndex['_stats'] : [];
            $log('supplier/sales: rows=' . (int)($stats['rows'] ?? 0)
                . ', warehouse_wb=' . (int)($stats['warehouse_wb'] ?? 0)
                . ', indexed=' . (int)($stats['indexed'] ?? 0) . "\n");
        }
    } catch (Throwable $e) {
        if ($log) {
            $log('supplier/sales warning: ' . $e->getMessage() . '; DBW statuses fallback to legacy sold/canceled mode.' . "\n");
        }
        $salesIndex = null;
    }

    $warehouseRows = 0;
    $periodRows = 0;
    $rowsByGroup = [];
    $ordersByPosting = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !orders_sync_wb_supplier_order_is_wb_warehouse($row)) {
            continue;
        }
        $warehouseRows++;
        if (!orders_sync_wb_supplier_order_in_period($row, $sinceUtc, $toUtc)) {
            continue;
        }
        $periodRows++;
        $rowsByGroup[orders_sync_wb_supplier_order_group_key($row)][] = $row;
    }

    foreach ($rowsByGroup as $groupRows) {
        $posting = orders_sync_wb_normalize_supplier_order_group($groupRows, $connectionId, $salesIndex);
        $postingNumber = trim((string)($posting['posting_number'] ?? ''));
        if ($postingNumber !== '') {
            $ordersByPosting[$postingNumber] = $posting;
        }
    }

    if ($log) {
        $log('supplier/orders: rows=' . count($rows)
            . ', warehouse_wb=' . $warehouseRows
            . ', in_period=' . $periodRows
            . ', groups=' . count($rowsByGroup)
            . ', normalized=' . count($ordersByPosting) . "\n");
    }
    return array_values($ordersByPosting);
}

function orders_sync_wb_fetch_orders_page(WildberriesClient $client, string $source, int $limit, int $next, int $dateFrom, int $dateTo): array
{
    return match ($source) {
        'dbw' => $client->getDbwOrders($limit, $next, $dateFrom, $dateTo),
        'dbs' => $client->getDbsOrders($limit, $next, $dateFrom, $dateTo),
        default => $client->getFbsOrders($limit, $next, $dateFrom, $dateTo),
    };
}

function orders_sync_wb_fetch_new_orders(WildberriesClient $client, string $source): array
{
    return match ($source) {
        'dbw' => $client->getDbwNewOrders(),
        'dbs' => $client->getDbsNewOrders(),
        default => $client->getFbsNewOrders(),
    };
}

function orders_sync_wb_fetch_order_statuses(WildberriesClient $client, string $source, array $ids): array
{
    return match ($source) {
        'dbw' => $client->getDbwOrderStatuses($ids),
        'dbs' => $client->getDbsOrderStatuses($ids),
        default => $client->getFbsOrderStatuses($ids),
    };
}

function orders_sync_wb_snapshot_orders_for_status(int $profileId, string $source, string $sinceUtc, string $toUtc, array $cfg = []): array
{
    $profileId = max(0, $profileId);
    if ($profileId <= 0) {
        return [];
    }
    orders_sync_orders_table_ensure($cfg);
    $from = orders_sync_db_datetime($sinceUtc);
    $to = orders_sync_db_datetime($toUtc);
    if ($from === null || $to === null) {
        return [];
    }

    $st = db()->prepare("
        SELECT posting_number, payload_json
        FROM feedtools_marketplace_order_snapshots
        WHERE profile_id = ?
          AND marketplace = 'wb'
          AND order_source = ?
          AND COALESCE(order_created_at, in_process_at, synced_at) >= ?
          AND COALESCE(order_created_at, in_process_at, synced_at) <= ?
        ORDER BY COALESCE(order_created_at, in_process_at, synced_at) DESC, id DESC
        LIMIT 5000
    ");
    $st->execute([$profileId, $source, $from, $to]);

    $orders = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $payload = orders_sync_decode_json_row($row['payload_json'] ?? null);
        $order = is_array($payload['_wb_raw_order'] ?? null) ? $payload['_wb_raw_order'] : $payload;
        if (!is_array($order)) {
            continue;
        }
        if (orders_sync_wb_order_id($order) <= 0 && is_numeric($row['posting_number'] ?? null)) {
            $order['id'] = (int)$row['posting_number'];
        }
        if (orders_sync_wb_order_id($order) > 0) {
            $orders[] = $order;
        }
    }
    return $orders;
}

function orders_sync_wb_fetch_all_orders(
    array $connection,
    string $source,
    string $sinceUtc,
    string $toUtc,
    int $limit = 1000,
    int $maxPages = 200,
    array $cfg = [],
    ?callable $log = null,
    int $profileId = 0
): array {
    $source = orders_sync_ozon_source_normalize($source);
    if (!in_array($source, orders_sync_wb_supported_sources(), true)) {
        if ($log) {
            $log("source {$source} пропущен: для WB Orders Sync поддерживаются FBS, DBW и DBS.\n");
        }
        return [];
    }

    $client = orders_sync_wb_client_from_connection($connection, $cfg);
    $limit = max(1, min(1000, $limit));
    $maxPages = max(1, min(1000, $maxPages));
    if ($source === 'dbw') {
        return orders_sync_wb_fetch_supplier_wb_warehouse_orders(
            $client,
            $sinceUtc,
            $toUtc,
            $log,
            (int)($connection['id'] ?? 0)
        );
    }

    $ordersById = [];
    $chunks = orders_sync_wb_period_chunks($sinceUtc, $toUtc);

    foreach ($chunks as $chunkIdx => [$chunkFrom, $chunkTo]) {
        $next = 0;
        $chunkLabel = ($chunkIdx + 1) . '/' . count($chunks);
        for ($page = 1; $page <= $maxPages; $page++) {
            if ($log) {
                $log("chunk {$chunkLabel}, page {$page}: next={$next}, limit={$limit}\n");
            }
            $response = orders_sync_wb_fetch_orders_page(
                $client,
                $source,
                $limit,
                $next,
                $chunkFrom->getTimestamp(),
                $chunkTo->getTimestamp()
            );
            $items = is_array($response['orders'] ?? null) ? $response['orders'] : [];
            if ($log) {
                $log("chunk {$chunkLabel}, page {$page}: items=" . count($items) . "\n");
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    $id = orders_sync_wb_order_id($item);
                    if ($id > 0) {
                        $ordersById[$id] = $item;
                    }
                }
            }
            $responseNext = (int)($response['next'] ?? 0);
            if (count($items) < $limit || $responseNext <= 0 || $responseNext === $next) {
                break;
            }
            $next = $responseNext;
        }
    }

    try {
        $response = orders_sync_wb_fetch_new_orders($client, $source);
        $items = is_array($response['orders'] ?? null) ? $response['orders'] : [];
        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !orders_sync_wb_order_in_period($item, $sinceUtc, $toUtc)) {
                continue;
            }
            $id = orders_sync_wb_order_id($item);
            if ($id > 0 && !isset($ordersById[$id])) {
                $ordersById[$id] = $item;
                $added++;
            }
        }
        if ($log) {
            $log('new orders: items=' . count($items) . ', added=' . $added . "\n");
        }
    } catch (Throwable $e) {
        if ($log) {
            $log('new orders warning: ' . $e->getMessage() . "\n");
        }
    }

    $snapshotOrders = orders_sync_wb_snapshot_orders_for_status($profileId, $source, $sinceUtc, $toUtc, $cfg);
    $snapshotAdded = 0;
    foreach ($snapshotOrders as $item) {
        if (!orders_sync_wb_order_in_period($item, $sinceUtc, $toUtc)) {
            continue;
        }
        $id = orders_sync_wb_order_id($item);
        if ($id > 0 && !isset($ordersById[$id])) {
            $ordersById[$id] = $item;
            $snapshotAdded++;
        }
    }
    if ($snapshotOrders && $log) {
        $log('snapshot active ids: items=' . count($snapshotOrders) . ', added=' . $snapshotAdded . "\n");
    }

    $orders = array_values($ordersById);
    $statusById = [];
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $order): int => orders_sync_wb_order_id($order),
        $orders
    ), static fn(int $id): bool => $id > 0)));
    foreach (array_chunk($ids, 1000) as $idx => $chunkIds) {
        if ($log) {
            $log('statuses batch ' . ($idx + 1) . ': items=' . count($chunkIds) . "\n");
        }
        $statusResponse = orders_sync_wb_fetch_order_statuses($client, $source, $chunkIds);
        foreach ((array)($statusResponse['orders'] ?? []) as $statusRow) {
            if (!is_array($statusRow)) {
                continue;
            }
            $id = orders_sync_wb_status_order_id($statusRow);
            if ($id > 0) {
                $statusById[$id] = $statusRow;
            }
        }
    }

    $warehouseNamesById = [];
    try {
        foreach (orders_sync_wb_warehouses_list($connection, $cfg) as $warehouse) {
            $entry = orders_sync_wb_logistics_warehouse_entry_from_warehouse($warehouse);
            if (is_array($entry) && trim((string)$entry['warehouse_id']) !== '') {
                $warehouseNamesById[(string)$entry['warehouse_id']] = (string)$entry['warehouse_name'];
            }
        }
    } catch (Throwable) {
        $warehouseNamesById = [];
    }

    $normalized = [];
    foreach ($orders as $order) {
        $id = orders_sync_wb_order_id($order);
        if ($id <= 0) {
            continue;
        }
        $statusRow = $statusById[$id] ?? [];
        $normalized[] = orders_sync_wb_normalize_order($source, $order, is_array($statusRow) ? $statusRow : [], $warehouseNamesById, (int)($connection['id'] ?? 0));
    }

    return $normalized;
}

function orders_sync_wb_price_rub(array $order): float
{
    foreach (['convertedFinalPrice', 'finalPrice', 'convertedPrice', 'price', 'salePrice'] as $field) {
        if (!array_key_exists($field, $order) || !is_numeric($order[$field])) {
            continue;
        }
        $value = (float)$order[$field];
        // WB marketplace orders API returns money fields in kopecks.
        return round($value / 100, 2);
    }
    return 0.0;
}

function orders_sync_wb_cached_card_for_order(int $connectionId, string $article, string $nmId): ?array
{
    static $cache = [];

    $connectionId = max(0, $connectionId);
    $article = trim($article);
    $nmId = trim($nmId);
    if ($article === '' && $nmId === '') {
        return null;
    }
    $cacheKey = $connectionId . '|' . mb_strtolower($article, 'UTF-8') . '|' . $nmId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $attempts = [];
    if ($connectionId > 0) {
        $attempts[] = $connectionId;
    }
    $attempts[] = 0;

    foreach (array_values(array_unique($attempts)) as $scopeConnectionId) {
        $where = [];
        $args = [];
        if ($scopeConnectionId > 0) {
            $where[] = 'connection_id = ?';
            $args[] = $scopeConnectionId;
        }
        $match = [];
        if ($article !== '') {
            $match[] = 'vendor_code = ?';
            $args[] = $article;
        }
        if ($nmId !== '' && ctype_digit($nmId)) {
            $match[] = 'nm_id = ?';
            $args[] = (int)$nmId;
        }
        if (!$match) {
            continue;
        }
        $where[] = '(' . implode(' OR ', $match) . ')';
        try {
            $st = db()->prepare("
                SELECT raw_json
                FROM feedtools_wb_products
                WHERE " . implode(' AND ', $where) . "
                  AND raw_json IS NOT NULL
                  AND raw_json <> ''
                ORDER BY is_active DESC, updated_at DESC
                LIMIT 1
            ");
            $st->execute($args);
            $raw = (string)($st->fetchColumn() ?: '');
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $cache[$cacheKey] = $decoded;
            }
        } catch (Throwable) {
            break;
        }
    }

    return $cache[$cacheKey] = null;
}

function orders_sync_wb_cached_card_title(?array $card): string
{
    if (!is_array($card)) {
        return '';
    }
    foreach (['title', 'name'] as $field) {
        $value = trim((string)($card[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function orders_sync_wb_cached_card_vendor_code(?array $card, int $depth = 0): string
{
    if (!is_array($card) || $depth > 4) {
        return '';
    }
    foreach (['vendorCode', 'vendor_code', 'supplierArticle', 'supplier_article', 'article'] as $field) {
        $value = trim((string)($card[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach ($card as $value) {
        if (!is_array($value)) {
            continue;
        }
        $found = orders_sync_wb_cached_card_vendor_code($value, $depth + 1);
        if ($found !== '') {
            return $found;
        }
    }
    return '';
}

function orders_sync_wb_posting_matches_reference(array $posting, string $orderRef): bool
{
    $orderRef = trim($orderRef);
    if ($orderRef === '') {
        return false;
    }
    $orderRefLower = mb_strtolower($orderRef, 'UTF-8');
    $candidates = [
        (string)($posting['posting_number'] ?? ''),
        (string)($posting['order_number'] ?? ''),
        (string)($posting['order_id'] ?? ''),
        (string)($posting['id'] ?? ''),
        (string)($posting['rid'] ?? ''),
        (string)($posting['orderUid'] ?? $posting['order_uid'] ?? ''),
        (string)($posting['article'] ?? ''),
        (string)($posting['nmId'] ?? $posting['nm_id'] ?? ''),
    ];
    foreach ((array)($posting['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $candidates[] = (string)($product['offer_id'] ?? '');
        $candidates[] = (string)($product['sku'] ?? '');
        $candidates[] = (string)($product['vendor_code'] ?? '');
        $candidates[] = (string)($product['marketplace_offer_id'] ?? '');
        $candidates[] = (string)($product['raw_offer_id'] ?? '');
    }
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && mb_strtolower($candidate, 'UTF-8') === $orderRefLower) {
            return true;
        }
    }
    return false;
}

function orders_sync_wb_normalize_fbs_order(array $order, array $statusRow = [], array $warehouseNamesById = [], int $connectionId = 0): array
{
    return orders_sync_wb_normalize_order('fbs', $order, $statusRow, $warehouseNamesById, $connectionId);
}

function orders_sync_wb_normalize_order(string $source, array $order, array $statusRow = [], array $warehouseNamesById = [], int $connectionId = 0): array
{
    $source = orders_sync_ozon_source_normalize($source);
    if (!in_array($source, orders_sync_wb_supported_sources(), true)) {
        $source = 'fbs';
    }
    $id = orders_sync_wb_order_id($order);
    $postingNumber = (string)$id;
    $orderUid = trim((string)($order['orderUid'] ?? ''));
    $rid = trim((string)($order['rid'] ?? ''));
    $article = trim((string)($order['article'] ?? ''));
    $nmId = trim((string)($order['nmId'] ?? ''));
    $chrtId = trim((string)($order['chrtId'] ?? ''));
    $warehouseId = trim((string)($order['warehouseId'] ?? ''));
    $warehouseName = $warehouseId !== '' ? (string)($warehouseNamesById[$warehouseId] ?? '') : '';
    $supplierStatus = strtolower(trim((string)($statusRow['supplierStatus'] ?? '')));
    $wbStatus = strtolower(trim((string)($statusRow['wbStatus'] ?? '')));
    $effectiveStatus = orders_sync_wb_effective_status([
        'supplierStatus' => $supplierStatus,
        'wbStatus' => $wbStatus,
    ]);
    $createdAt = trim((string)($order['createdAt'] ?? ''));
    $cachedCard = orders_sync_wb_cached_card_for_order($connectionId, $article, $nmId);
    $cachedVendorCode = orders_sync_wb_cached_card_vendor_code($cachedCard);
    $productName = orders_sync_wb_cached_card_title($cachedCard);
    $offerId = $article !== '' ? $article : ($nmId !== '' ? $nmId : $postingNumber);
    if ($cachedVendorCode !== '' && ($offerId === '' || !str_contains($offerId, '__') || str_contains($cachedVendorCode, '__'))) {
        $offerId = $cachedVendorCode;
    }
    $offerCode = $offerId !== '' ? bundle_offer_article_without_supplier_code($offerId) : '';
    if (orders_sync_order_product_name_is_placeholder($productName, $offerId, $offerCode)) {
        $supplierName = orders_sync_supplier_product_name_for_order_product([
            'offer_id' => $offerId,
            'vendor_code' => $cachedVendorCode,
            'marketplace_offer_id' => $article,
            'raw_offer_id' => $article,
        ], $offerId, $offerCode);
        if ($supplierName !== '') {
            $productName = $supplierName;
        }
    }
    if ($productName === '') {
        $productName = $article !== '' ? $article : ($nmId !== '' ? 'WB nmID ' . $nmId : 'Товар WB');
    }

    return [
        'posting_number' => $postingNumber,
        'order_number' => $orderUid !== '' ? $orderUid : ($rid !== '' ? $rid : $postingNumber),
        'order_id' => $postingNumber,
        'id' => $postingNumber,
        'orderUid' => $orderUid,
        'rid' => $rid,
        'article' => $article,
        'nmId' => $nmId,
        'status' => $effectiveStatus,
        'substatus' => $supplierStatus,
        'supplierStatus' => $supplierStatus,
        'wbStatus' => $wbStatus,
        'created_at' => $createdAt,
        'in_process_at' => $createdAt,
        'shipment_date' => trim((string)($order['ddate'] ?? $order['dDate'] ?? $order['sellerDate'] ?? '')),
        'delivery_method' => [
            'warehouse_id' => $warehouseId,
            'warehouse' => $warehouseName,
        ],
        'products' => [[
            'offer_id' => $offerId,
            'name' => $productName,
            'quantity' => 1,
            'price' => orders_sync_wb_price_rub($order),
            'sku' => is_array($order['skus'] ?? null) ? implode(', ', array_map('strval', $order['skus'])) : '',
            'vendor_code' => $cachedVendorCode !== '' ? $cachedVendorCode : $article,
            'marketplace_offer_id' => $article,
            'raw_offer_id' => $article,
            'nm_id' => $nmId,
            'chrt_id' => $chrtId,
        ]],
        '_feedtools_source' => $source,
        '_feedtools_effective_status' => $effectiveStatus,
        '_feedtools_marketplace' => 'wb',
        '_wb_raw_order' => $order,
        '_wb_status' => $statusRow,
    ];
}

function orders_sync_save_ozon_posting_history(
    int $runId,
    array $rows,
    array $cfg = []
): void {
    orders_sync_order_history_table_ensure($cfg);
    if ($runId <= 0 || !$rows) {
        return;
    }

    $chunkSize = 200;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        if (!$chunk) {
            continue;
        }
        $valuesSql = [];
        $params = [];
        foreach ($chunk as $row) {
            $valuesSql[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            $params[] = $runId;
            $params[] = $row['profile_id'];
            $params[] = $row['connection_id'];
            $params[] = $row['moysklad_account_id'];
            $params[] = $row['marketplace'];
            $params[] = $row['order_source'];
            $params[] = $row['external_order_id'];
            $params[] = $row['order_number'];
            $params[] = $row['posting_number'];
            $params[] = $row['status'];
            $params[] = $row['substatus'];
            $params[] = $row['order_created_at'];
            $params[] = $row['in_process_at'];
            $params[] = $row['payload_json'];
        }
        $sql = "
            INSERT INTO feedtools_marketplace_order_snapshot_history (
                run_id, profile_id, connection_id, moysklad_account_id, marketplace, order_source,
                external_order_id, order_number, posting_number, status, substatus,
                order_created_at, in_process_at, payload_json, fetched_at
            ) VALUES " . implode(', ', $valuesSql) . "
            ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                moysklad_account_id = VALUES(moysklad_account_id),
                external_order_id = VALUES(external_order_id),
                order_number = VALUES(order_number),
                status = VALUES(status),
                substatus = VALUES(substatus),
                order_created_at = VALUES(order_created_at),
                in_process_at = VALUES(in_process_at),
                payload_json = VALUES(payload_json),
                fetched_at = NOW()
        ";
        db()->prepare($sql)->execute($params);
    }
}

function orders_sync_order_snapshot_history_cleanup_old(array $cfg = [], int $limit = 5000): int
{
    $cfg = orders_sync_cfg_fallback($cfg);
    $days = (int)($cfg['retention']['order_snapshot_history_days'] ?? 7);
    $days = max(1, min(3650, $days));
    $limit = max(1, min(50000, $limit));
    orders_sync_order_history_table_ensure($cfg);

    $st = db()->prepare("
        DELETE FROM feedtools_marketplace_order_snapshot_history
        WHERE fetched_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
        LIMIT {$limit}
    ");
    $st->execute();
    return (int)$st->rowCount();
}

function orders_sync_save_ozon_postings(
    int $profileId,
    int $connectionId,
    int $moyskladAccountId,
    string $source,
    array $postings,
    array $cfg = [],
    int $runId = 0
): array {
    return orders_sync_save_marketplace_postings('ozon', $profileId, $connectionId, $moyskladAccountId, $source, $postings, $cfg, $runId);
}

function orders_sync_save_marketplace_postings(
    string $marketplace,
    int $profileId,
    int $connectionId,
    int $moyskladAccountId,
    string $source,
    array $postings,
    array $cfg = [],
    int $runId = 0
): array {
    orders_sync_orders_table_ensure($cfg);
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    $source = orders_sync_ozon_source_normalize($source);

    $rows = [];
    $postingNumbers = [];
    foreach ($postings as $posting) {
        if (!is_array($posting)) {
            continue;
        }
        $row = orders_sync_marketplace_snapshot_row($marketplace, $profileId, $connectionId, $moyskladAccountId, $source, $posting, $cfg);
        $postingNumber = (string)($row['posting_number'] ?? '');
        if ($postingNumber === '') {
            continue;
        }
        $rows[] = $row;
        $postingNumbers[] = $postingNumber;
    }

    if (!$rows) {
        return ['inserted' => 0, 'updated' => 0];
    }

    $existingMap = orders_sync_existing_postings_map($profileId, $marketplace, $source, $postingNumbers);

    $inserted = 0;
    $updated = 0;
    $historyRows = [];
    foreach ($rows as $row) {
        $postingNumber = (string)$row['posting_number'];
        $existingRow = $existingMap[$postingNumber] ?? null;
        if (is_array($existingRow)) {
            $updated++;
        } else {
            $inserted++;
        }
        if ($runId > 0 && orders_sync_snapshot_row_changed(is_array($existingRow) ? $existingRow : null, $row)) {
            $historyRows[] = $row;
        }
    }

    $chunkSize = 200;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        if (!$chunk) {
            continue;
        }
        $valuesSql = [];
        $params = [];
        foreach ($chunk as $row) {
            $valuesSql[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())';
            $params[] = $row['profile_id'];
            $params[] = $row['connection_id'];
            $params[] = $row['moysklad_account_id'];
            $params[] = $row['marketplace'];
            $params[] = $row['order_source'];
            $params[] = $row['external_order_id'];
            $params[] = $row['order_number'];
            $params[] = $row['posting_number'];
            $params[] = $row['status'];
            $params[] = $row['substatus'];
            $params[] = $row['order_created_at'];
            $params[] = $row['in_process_at'];
            $params[] = $row['payload_json'];
            $params[] = max(0, (int)$runId);
        }
        $sql = "
            INSERT INTO feedtools_marketplace_order_snapshots (
                profile_id, connection_id, moysklad_account_id, marketplace, order_source,
                external_order_id, order_number, posting_number, status, substatus,
                order_created_at, in_process_at, payload_json, synced_at, last_seen_run_id, last_seen_at
            ) VALUES " . implode(', ', $valuesSql) . "
            ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                moysklad_account_id = VALUES(moysklad_account_id),
                external_order_id = VALUES(external_order_id),
                order_number = VALUES(order_number),
                status = VALUES(status),
                substatus = VALUES(substatus),
                order_created_at = VALUES(order_created_at),
                in_process_at = VALUES(in_process_at),
                payload_json = VALUES(payload_json),
                synced_at = NOW(),
                last_seen_run_id = CASE
                    WHEN VALUES(last_seen_run_id) > 0 THEN VALUES(last_seen_run_id)
                    ELSE last_seen_run_id
                END,
                last_seen_at = CASE
                    WHEN VALUES(last_seen_run_id) > 0 THEN NOW()
                    ELSE last_seen_at
                END
        ";
        db()->prepare($sql)->execute($params);
    }

    if ($runId > 0 && $historyRows) {
        orders_sync_save_ozon_posting_history($runId, $historyRows, $cfg);
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
    ];
}

function orders_sync_existing_postings_map(int $profileId, string $marketplace, string $source, array $postingNumbers): array
{
    $postingNumbers = array_values(array_unique(array_filter(array_map('strval', $postingNumbers))));
    if (!$postingNumbers) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postingNumbers), '?'));
    $args = array_merge([$profileId, $marketplace, $source], $postingNumbers);
    $st = db()->prepare("
        SELECT posting_number, external_order_id, order_number, status, substatus,
               order_created_at, in_process_at, payload_json
        FROM feedtools_marketplace_order_snapshots
        WHERE profile_id = ? AND marketplace = ? AND order_source = ?
          AND posting_number IN ({$placeholders})
    ");
    $st->execute($args);

    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $postingNumber = (string)($row['posting_number'] ?? '');
        if ($postingNumber !== '') {
            $map[$postingNumber] = $row;
        }
    }
    return $map;
}

function orders_sync_snapshot_row_changed(?array $existing, array $row): bool
{
    if (!$existing) {
        return true;
    }
    foreach ([
        'external_order_id',
        'order_number',
        'status',
        'substatus',
        'order_created_at',
        'in_process_at',
        'payload_json',
    ] as $key) {
        $old = $existing[$key] ?? null;
        $new = $row[$key] ?? null;
        if (($old === null ? '' : (string)$old) !== ($new === null ? '' : (string)$new)) {
            return true;
        }
    }
    return false;
}

function orders_sync_ozon_snapshot_row(int $profileId, int $connectionId, int $moyskladAccountId, string $source, array $posting, array $cfg = []): array
{
    return orders_sync_marketplace_snapshot_row('ozon', $profileId, $connectionId, $moyskladAccountId, $source, $posting, $cfg);
}

function orders_sync_marketplace_snapshot_row(string $marketplace, int $profileId, int $connectionId, int $moyskladAccountId, string $source, array $posting, array $cfg = []): array
{
    $marketplace = orders_sync_marketplace_normalize($marketplace);
    $source = orders_sync_ozon_source_normalize($source);
    $posting['_feedtools_source'] = $source;
    $posting['_feedtools_marketplace'] = $marketplace;
    $posting['_feedtools_effective_status'] = orders_sync_marketplace_effective_status($cfg, $marketplace, $source, $posting);
    $postingNumber = trim((string)($posting['posting_number'] ?? ''));
    $orderNumber = trim((string)($posting['order_number'] ?? ''));
    $externalOrderId = trim((string)($posting['order_id'] ?? ''));
    $status = trim((string)($posting['status'] ?? ''));
    $substatus = trim((string)($posting['substatus'] ?? ''));
    $orderCreatedAt = trim((string)($posting['created_at'] ?? ''));
    $inProcessAt = trim((string)($posting['in_process_at'] ?? ''));

    if ($orderNumber === '' && $postingNumber !== '') {
        $orderNumber = preg_replace('~-\d+$~', '', $postingNumber) ?: $postingNumber;
    }
    if ($orderCreatedAt === '') {
        $orderCreatedAt = $inProcessAt;
    }
    $payloadJson = json_encode($posting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        $payloadJson = '{}';
    }

    return [
        'profile_id' => $profileId,
        'connection_id' => $connectionId,
        'moysklad_account_id' => $moyskladAccountId,
        'marketplace' => $marketplace,
        'order_source' => $source,
        'external_order_id' => $externalOrderId,
        'order_number' => $orderNumber,
        'posting_number' => $postingNumber,
        'status' => $status,
        'substatus' => $substatus,
        'order_created_at' => orders_sync_db_datetime($orderCreatedAt),
        'in_process_at' => orders_sync_db_datetime($inProcessAt),
        'payload_json' => $payloadJson,
    ];
}

function orders_sync_db_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function orders_sync_decode_json_row($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}
