<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/ozon_actions.php';

const OZON_FBO_TOOL_APPROX_COST_RATIO = 0.60;
const OZON_FBO_TOOL_WB_WAREHOUSE_KEY = 'wb:fbo';
const OZON_FBO_TOOL_WB_WAREHOUSE_NAME = 'Все склады WB';

function ozon_fbo_tool_warehouse_key(?string $warehouseId = null, ?string $warehouseKey = null): string
{
    $warehouseKey = trim((string)$warehouseKey);
    if ($warehouseKey !== '') {
        return mb_substr($warehouseKey, 0, 128, 'UTF-8');
    }
    $warehouseId = trim((string)$warehouseId);
    return $warehouseId !== '' ? ('id:' . mb_substr($warehouseId, 0, 120, 'UTF-8')) : '';
}

function ozon_fbo_tool_options_warehouse(array $options = []): array
{
    $warehouseId = trim((string)($options['warehouse_id'] ?? ''));
    $warehouseKey = ozon_fbo_tool_warehouse_key($warehouseId, (string)($options['warehouse_key'] ?? ''));
    $warehouseName = trim((string)($options['warehouse_name'] ?? ''));
    if (($options['marketplace'] ?? '') === 'wb' || $warehouseKey === OZON_FBO_TOOL_WB_WAREHOUSE_KEY) {
        $warehouseId = '';
        $warehouseKey = OZON_FBO_TOOL_WB_WAREHOUSE_KEY;
        $warehouseName = $warehouseName !== '' ? $warehouseName : OZON_FBO_TOOL_WB_WAREHOUSE_NAME;
    }
    return [
        'warehouse_id' => mb_substr($warehouseId, 0, 64, 'UTF-8'),
        'warehouse_key' => $warehouseKey,
        'warehouse_name' => mb_substr($warehouseName, 0, 190, 'UTF-8'),
    ];
}

function ozon_fbo_tool_default_warehouse_options(array $connection): array
{
    $marketplace = (string)($connection['marketplace'] ?? 'ozon');
    return [
        'marketplace' => $marketplace !== '' ? $marketplace : 'ozon',
        'warehouse_id' => '',
        'warehouse_key' => $marketplace === 'wb' ? OZON_FBO_TOOL_WB_WAREHOUSE_KEY : '',
        'warehouse_name' => $marketplace === 'wb' ? '' : 'FBO Ozon',
    ];
}

function ozon_fbo_tool_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    return (int)$st->fetchColumn() > 0;
}

function ozon_fbo_tool_tables_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_fbo_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            connection_id BIGINT UNSIGNED NOT NULL,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            warehouse_id VARCHAR(64) NOT NULL DEFAULT '',
            warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            offer_id VARCHAR(190) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            chrt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(64) NOT NULL DEFAULT '',
            name TEXT NULL,
            fbo_present INT NOT NULL DEFAULT 0,
            fbo_reserved INT NOT NULL DEFAULT 0,
            price DECIMAL(14,2) NULL,
            marketing_seller_price DECIMAL(14,2) NULL,
            min_price DECIMAL(14,2) NULL,
            old_price DECIMAL(14,2) NULL,
            color_index VARCHAR(64) NOT NULL DEFAULT '',
            price_index_value DECIMAL(10,4) NULL,
            price_index_source VARCHAR(64) NOT NULL DEFAULT '',
            days_without_sales INT NULL,
            days_without_sales_cluster INT NULL,
            ads DECIMAL(12,4) NULL,
            turnover_grade VARCHAR(64) NOT NULL DEFAULT '',
            raw_stocks_json LONGTEXT NULL,
            raw_price_json LONGTEXT NULL,
            raw_info_json LONGTEXT NULL,
            raw_analytics_json LONGTEXT NULL,
            zero_fbs_while_fbo TINYINT(1) NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NULL,
            last_refreshed_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_connection_warehouse_offer (connection_id, warehouse_key, offer_id),
            KEY idx_connection_stock (connection_id, fbo_present, fbo_reserved),
            KEY idx_connection_refreshed (connection_id, last_refreshed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'marketplace' => "ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon' AFTER connection_id",
        'warehouse_id' => "ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN warehouse_id VARCHAR(64) NOT NULL DEFAULT '' AFTER marketplace",
        'warehouse_key' => "ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN warehouse_key VARCHAR(128) NOT NULL DEFAULT '' AFTER warehouse_id",
        'warehouse_name' => "ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN warehouse_name VARCHAR(190) NOT NULL DEFAULT '' AFTER warehouse_key",
        'chrt_id' => "ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN chrt_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER product_id",
    ] as $column => $sql) {
        if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', $column)) {
            $pdo->exec($sql);
        }
    }
    if (ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'uniq_connection_offer')
        && !ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'uniq_connection_warehouse_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items DROP INDEX uniq_connection_offer");
    }
    if (!ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'uniq_connection_warehouse_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD UNIQUE KEY uniq_connection_warehouse_offer (connection_id, warehouse_key, offer_id)");
    }
    if (ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'uniq_connection_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items DROP INDEX uniq_connection_offer");
    }
    if (!ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'idx_connection_stock')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD KEY idx_connection_stock (connection_id, fbo_present, fbo_reserved)");
    }
    if (!ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_items', 'idx_connection_warehouse_stock')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD KEY idx_connection_warehouse_stock (connection_id, warehouse_key, fbo_present, fbo_reserved)");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'zero_fbs_while_fbo')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN zero_fbs_while_fbo TINYINT(1) NOT NULL DEFAULT 0 AFTER raw_info_json");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'days_without_sales')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN days_without_sales INT NULL AFTER price_index_source");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'days_without_sales_cluster')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN days_without_sales_cluster INT NULL AFTER days_without_sales");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'ads')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN ads DECIMAL(12,4) NULL AFTER days_without_sales_cluster");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'turnover_grade')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN turnover_grade VARCHAR(64) NOT NULL DEFAULT '' AFTER ads");
    }
    if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_items', 'raw_analytics_json')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_items ADD COLUMN raw_analytics_json LONGTEXT NULL AFTER raw_info_json");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_fbo_price_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            connection_id BIGINT UNSIGNED NOT NULL,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            warehouse_id VARCHAR(64) NOT NULL DEFAULT '',
            warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            offer_id VARCHAR(190) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            pricing_mode VARCHAR(32) NOT NULL DEFAULT 'exact',
            duration_days INT NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            note TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_connection_warehouse_offer (connection_id, warehouse_key, offer_id),
            KEY idx_connection_status (connection_id, status, expires_at),
            KEY idx_connection_offer_status (connection_id, offer_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    foreach ([
        'marketplace' => "ALTER TABLE feedtools_ozon_fbo_price_rules ADD COLUMN marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon' AFTER connection_id",
        'warehouse_id' => "ALTER TABLE feedtools_ozon_fbo_price_rules ADD COLUMN warehouse_id VARCHAR(64) NOT NULL DEFAULT '' AFTER marketplace",
        'warehouse_key' => "ALTER TABLE feedtools_ozon_fbo_price_rules ADD COLUMN warehouse_key VARCHAR(128) NOT NULL DEFAULT '' AFTER warehouse_id",
        'warehouse_name' => "ALTER TABLE feedtools_ozon_fbo_price_rules ADD COLUMN warehouse_name VARCHAR(190) NOT NULL DEFAULT '' AFTER warehouse_key",
        'pricing_mode' => "ALTER TABLE feedtools_ozon_fbo_price_rules ADD COLUMN pricing_mode VARCHAR(32) NOT NULL DEFAULT 'exact' AFTER target_price",
    ] as $column => $sql) {
        if (!ozon_fbo_tool_table_has_column($pdo, 'feedtools_ozon_fbo_price_rules', $column)) {
            $pdo->exec($sql);
        }
    }
    if (ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_price_rules', 'uniq_connection_offer')
        && !ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_price_rules', 'uniq_connection_warehouse_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_price_rules DROP INDEX uniq_connection_offer");
    }
    if (!ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_price_rules', 'uniq_connection_warehouse_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_price_rules ADD UNIQUE KEY uniq_connection_warehouse_offer (connection_id, warehouse_key, offer_id)");
    }
    if (ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_price_rules', 'uniq_connection_offer')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_price_rules DROP INDEX uniq_connection_offer");
    }
    if (!ozon_fbo_tool_table_has_index($pdo, 'feedtools_ozon_fbo_price_rules', 'idx_connection_warehouse_status')) {
        $pdo->exec("ALTER TABLE feedtools_ozon_fbo_price_rules ADD KEY idx_connection_warehouse_status (connection_id, warehouse_key, status, expires_at)");
    }

    $done = true;
}

function ozon_fbo_tool_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function ozon_fbo_tool_count_by_connection(array $cfg = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $rows = db()->query("
        SELECT i.connection_id, COUNT(*) AS cnt
        FROM feedtools_ozon_fbo_items i
        LEFT JOIN feedtools_marketplace_connections c
          ON c.id = i.connection_id
        WHERE (
            CASE
              WHEN COALESCE(c.marketplace, 'ozon') = 'wb' THEN i.fbo_present > 0
              ELSE i.fbo_present > 0 OR i.fbo_reserved > 0
            END
          )
          AND (COALESCE(c.marketplace, 'ozon') <> 'wb' OR i.warehouse_key = '" . OZON_FBO_TOOL_WB_WAREHOUSE_KEY . "')
        GROUP BY i.connection_id
    ")->fetchAll() ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[(int)($row['connection_id'] ?? 0)] = (int)($row['cnt'] ?? 0);
    }
    return $out;
}

function ozon_fbo_tool_wb_warehouses(array $cfg, array $connection, bool $forceRefresh = false): array
{
    return [[
        'id' => '',
        'name' => OZON_FBO_TOOL_WB_WAREHOUSE_NAME,
        'warehouse_key' => OZON_FBO_TOOL_WB_WAREHOUSE_KEY,
        'is_wb_fbo_scope' => true,
    ]];
}

function ozon_fbo_tool_wb_warehouse_options(array $cfg, array $connection, bool $forceRefresh = false): array
{
    $options = [];
    foreach (ozon_fbo_tool_wb_warehouses($cfg, $connection, $forceRefresh) as $warehouse) {
        $warehouseId = trim((string)($warehouse['id'] ?? ''));
        $warehouseName = trim((string)($warehouse['name'] ?? ''));
        $warehouseKey = trim((string)($warehouse['warehouse_key'] ?? ''));
        if ($warehouseKey === '') {
            $warehouseKey = ozon_fbo_tool_warehouse_key($warehouseId);
        }
        if ($warehouseKey === '') {
            continue;
        }
        $entry = [
            'warehouse_id' => $warehouseId,
            'warehouse_key' => $warehouseKey,
            'warehouse_name' => $warehouseName !== '' ? $warehouseName : OZON_FBO_TOOL_WB_WAREHOUSE_NAME,
        ];
        $options[(string)$entry['warehouse_key']] = $entry;
    }
    uasort($options, static function (array $a, array $b): int {
        return strnatcasecmp((string)($a['warehouse_name'] ?? ''), (string)($b['warehouse_name'] ?? ''));
    });
    return $options;
}

function ozon_fbo_tool_price_feeds_for_connection(int $connectionId, array $cfg = []): array
{
    $feeds = ozon_price_feed_list($connectionId, $cfg);
    return array_values(array_filter($feeds, static fn(array $feed): bool => !ozon_price_feed_supplier_is_archived($feed)));
}

function ozon_fbo_tool_price_rules_by_offer(int $connectionId, array $offerIds = [], array $cfg = [], array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));

    $warehouse = ozon_fbo_tool_options_warehouse($options);
    $warehouseFilter = '';
    $warehouseArgs = [];
    $warehouseOrder = '';
    if ($warehouse['warehouse_key'] !== '') {
        if ($warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY) {
            $warehouseFilter = ' AND (warehouse_key = ? OR marketplace = \'wb\')';
            $warehouseOrder = "warehouse_key = '" . OZON_FBO_TOOL_WB_WAREHOUSE_KEY . "' DESC,";
            $warehouseArgs[] = $warehouse['warehouse_key'];
        } else {
            $warehouseFilter = ' AND warehouse_key = ?';
            $warehouseArgs[] = $warehouse['warehouse_key'];
        }
    }

    $out = [];
    if ($offerIds) {
        foreach (array_chunk($offerIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("
                SELECT *
                FROM feedtools_ozon_fbo_price_rules
                WHERE connection_id = ?
                  {$warehouseFilter}
                  AND offer_id IN ({$placeholders})
                ORDER BY {$warehouseOrder} status = 'active' DESC, updated_at DESC
            ");
            $st->execute(array_merge([$connectionId], $warehouseArgs, $chunk));
            foreach ($st->fetchAll() ?: [] as $row) {
                $offerId = trim((string)($row['offer_id'] ?? ''));
                if ($offerId !== '' && !isset($out[$offerId])) {
                    $out[$offerId] = $row;
                }
            }
        }
        return $out;
    }

    $st = db()->prepare("
        SELECT *
        FROM feedtools_ozon_fbo_price_rules
        WHERE connection_id = ?
        {$warehouseFilter}
        ORDER BY offer_id ASC, {$warehouseOrder} status = 'active' DESC, updated_at DESC
    ");
    $st->execute(array_merge([$connectionId], $warehouseArgs));
    foreach ($st->fetchAll() ?: [] as $row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        if ($offerId !== '' && !isset($out[$offerId])) {
            $out[$offerId] = $row;
        }
    }
    return $out;
}

function ozon_fbo_tool_rule_state(?array $rule, int $units, ?int $now = null): array
{
    if (!is_array($rule) || (int)($rule['id'] ?? 0) <= 0) {
        return [
            'has_rule' => false,
            'is_active' => false,
            'status_label' => '—',
            'days_active' => null,
            'days_left' => null,
            'expires_label' => '',
        ];
    }

    $now = $now ?? time();
    $status = strtolower(trim((string)($rule['status'] ?? '')));
    $startsTs = strtotime((string)($rule['starts_at'] ?? '')) ?: $now;
    $enabled = $status === 'active';
    $stockActive = $units > 0;
    $isActive = $enabled && $stockActive;
    $daysActive = max(0, (int)floor(($now - $startsTs) / 86400));

    if ($status === 'finished') {
        $label = 'завершено';
    } elseif ($status !== 'active') {
        $label = 'отключено';
    } elseif (!$stockActive) {
        $label = 'ждёт FBO-остаток';
    } else {
        $label = 'активно';
    }

    return [
        'has_rule' => true,
        'is_active' => $isActive,
        'status_label' => $label,
        'days_active' => $daysActive,
        'days_left' => null,
        'expires_label' => '',
    ];
}

function ozon_fbo_tool_normalize_pricing_mode(string $pricingMode, string $marketplace = 'ozon'): string
{
    if (strtolower(trim($marketplace)) !== 'wb') {
        return 'exact';
    }
    return strtolower(trim($pricingMode)) === 'promotion_floor' ? 'promotion_floor' : 'exact';
}

function ozon_fbo_tool_save_price_rule(int $connectionId, string $offerId, float $targetPrice, int $durationDays = 0, ?string $actor = null, array $cfg = [], array $options = [], string $pricingMode = 'exact'): void
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerId = trim($offerId);
    if ($connectionId <= 0 || $offerId === '') {
        throw new RuntimeException('Не удалось определить товар для FBO-правила.');
    }
    $targetPrice = round($targetPrice, 2);
    if ($targetPrice <= 0) {
        throw new RuntimeException('Укажи сниженную цену больше нуля.');
    }
    $startsAt = date('Y-m-d H:i:s');
    $durationDays = 0;
    $expiresAt = null;
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $defaults = is_array($connection) ? ozon_fbo_tool_default_warehouse_options($connection) : ['marketplace' => 'ozon'];
    $warehouse = ozon_fbo_tool_options_warehouse(array_replace($defaults, $options));
    $marketplace = (string)($defaults['marketplace'] ?? 'ozon');
    $pricingMode = ozon_fbo_tool_normalize_pricing_mode($pricingMode, $marketplace);

    $productId = 0;
    $st = db()->prepare("SELECT product_id FROM feedtools_ozon_fbo_items WHERE connection_id = ? AND warehouse_key = ? AND offer_id = ? LIMIT 1");
    $st->execute([$connectionId, $warehouse['warehouse_key'], $offerId]);
    $productId = (int)($st->fetchColumn() ?: 0);

    $up = db()->prepare("
        INSERT INTO feedtools_ozon_fbo_price_rules (
            connection_id, marketplace, warehouse_id, warehouse_key, warehouse_name,
            offer_id, product_id, target_price, pricing_mode, duration_days,
            status, starts_at, expires_at, updated_by, created_by
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            'active', ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            marketplace = VALUES(marketplace),
            warehouse_id = VALUES(warehouse_id),
            warehouse_name = VALUES(warehouse_name),
            product_id = VALUES(product_id),
            target_price = VALUES(target_price),
            pricing_mode = VALUES(pricing_mode),
            duration_days = VALUES(duration_days),
            status = 'active',
            starts_at = VALUES(starts_at),
            expires_at = VALUES(expires_at),
            updated_by = VALUES(updated_by)
    ");
    $up->execute([
        $connectionId,
        $marketplace,
        $warehouse['warehouse_id'],
        $warehouse['warehouse_key'],
        $warehouse['warehouse_name'],
        $offerId,
        $productId,
        $targetPrice,
        $pricingMode,
        $durationDays,
        $startsAt,
        $expiresAt,
        $actor,
        $actor,
    ]);
}

function ozon_fbo_tool_disable_price_rule(int $connectionId, string $offerId, ?string $actor = null, array $cfg = [], array $options = []): void
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerId = trim($offerId);
    if ($connectionId <= 0 || $offerId === '') {
        throw new RuntimeException('Не удалось определить FBO-правило для отключения.');
    }
    $warehouse = ozon_fbo_tool_options_warehouse($options);
    $warehouseFilter = $warehouse['warehouse_key'] !== '' ? ' AND warehouse_key = ?' : '';
    $args = [$actor, $connectionId, $offerId];
    if ($warehouse['warehouse_key'] !== '') {
        $args[] = $warehouse['warehouse_key'];
    }
    $st = db()->prepare("
        UPDATE feedtools_ozon_fbo_price_rules
        SET status = 'disabled',
            updated_by = ?
        WHERE connection_id = ?
          AND offer_id = ?
          {$warehouseFilter}
        LIMIT 1
    ");
    $st->execute($args);
}

function ozon_fbo_tool_set_zero_fbs_flag(int $connectionId, array $offerIds, bool $enabled, array $cfg = []): int
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        return 0;
    }

    $updated = 0;
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $args = array_merge([$enabled ? 1 : 0, $connectionId], $chunk);
        $st = db()->prepare("
            UPDATE feedtools_ozon_fbo_items
            SET zero_fbs_while_fbo = ?
            WHERE connection_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $st->execute($args);
        $updated += $st->rowCount();
    }
    return $updated;
}

function ozon_fbo_tool_zero_fbs_enabled_offer_ids(int $connectionId, array $offerIds = [], array $cfg = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }

    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $out = [];
    if ($offerIds) {
        foreach (array_chunk($offerIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("
                SELECT offer_id
                FROM feedtools_ozon_fbo_items
                WHERE connection_id = ?
                  AND zero_fbs_while_fbo = 1
                  AND offer_id IN ({$placeholders})
            ");
            $st->execute(array_merge([$connectionId], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $offerId) {
                $offerId = trim((string)$offerId);
                if ($offerId !== '') {
                    $out[$offerId] = true;
                }
            }
        }
        return array_keys($out);
    }

    $st = db()->prepare("
        SELECT offer_id
        FROM feedtools_ozon_fbo_items
        WHERE connection_id = ?
          AND zero_fbs_while_fbo = 1
    ");
    $st->execute([$connectionId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId !== '') {
            $out[$offerId] = true;
        }
    }
    return array_keys($out);
}

function ozon_fbo_tool_zero_fbs_active_map(int $connectionId, array $offerIds = [], array $cfg = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }

    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $out = [];
    if ($offerIds) {
        foreach (array_chunk($offerIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("
                SELECT offer_id, product_id, fbo_present, fbo_reserved, last_refreshed_at
                FROM feedtools_ozon_fbo_items
                WHERE connection_id = ?
                  AND zero_fbs_while_fbo = 1
                  AND fbo_present > 0
                  AND offer_id IN ({$placeholders})
            ");
            $st->execute(array_merge([$connectionId], $chunk));
            foreach ($st->fetchAll() ?: [] as $row) {
                $offerId = trim((string)($row['offer_id'] ?? ''));
                if ($offerId !== '') {
                    $out[$offerId] = $row;
                }
            }
        }
        return $out;
    }

    $st = db()->prepare("
        SELECT offer_id, product_id, fbo_present, fbo_reserved, last_refreshed_at
        FROM feedtools_ozon_fbo_items
        WHERE connection_id = ?
          AND zero_fbs_while_fbo = 1
          AND fbo_present > 0
    ");
    $st->execute([$connectionId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        if ($offerId !== '') {
            $out[$offerId] = $row;
        }
    }
    return $out;
}

function ozon_fbo_tool_annul_inactive_price_rules(int $connectionId, ?string $actor = null, array $cfg = [], array $offerIds = [], array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [
            'expired' => 0,
            'sold_out' => 0,
            'total' => 0,
        ];
    }

    $expiredCount = 0;

    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $offerFilterSql = '';
    $args = [$actor, $connectionId];
    $warehouse = ozon_fbo_tool_options_warehouse($options);
    $warehouseFilterSql = '';
    if ($warehouse['warehouse_key'] !== '') {
        $warehouseFilterSql = ' AND r.warehouse_key = ?';
        $args[] = $warehouse['warehouse_key'];
    }
    if ($offerIds) {
        $offerFilterSql = ' AND r.offer_id IN (' . implode(', ', array_fill(0, count($offerIds), '?')) . ')';
        array_push($args, ...$offerIds);
    }
    $soldOutCondition = $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
        ? 'COALESCE(i.fbo_present, 0) <= 0'
        : 'COALESCE(i.fbo_present, 0) + COALESCE(i.fbo_reserved, 0) <= 0';

    $soldOut = db()->prepare("
        UPDATE feedtools_ozon_fbo_price_rules r
        LEFT JOIN feedtools_ozon_fbo_items i
            ON i.connection_id = r.connection_id
           AND i.warehouse_key = r.warehouse_key
           AND i.offer_id = r.offer_id
        SET r.status = 'finished',
            r.note = 'FBO-правило завершено: FBO-остаток закончился.',
            r.updated_by = ?
        WHERE r.connection_id = ?
          AND r.status = 'active'
          {$warehouseFilterSql}
          {$offerFilterSql}
          AND {$soldOutCondition}
    ");
    $soldOut->execute($args);
    $soldOutCount = $soldOut->rowCount();

    return [
        'expired' => $expiredCount,
        'sold_out' => $soldOutCount,
        'total' => $expiredCount + $soldOutCount,
    ];
}

function ozon_fbo_tool_force_rule_for_offer(int $connectionId, string $offerId, int $fboUnits = 0, array $cfg = []): ?array
{
    $rules = ozon_fbo_tool_price_rules_by_offer($connectionId, [$offerId], $cfg);
    $rule = is_array($rules[$offerId] ?? null) ? (array)$rules[$offerId] : null;
    $state = ozon_fbo_tool_rule_state($rule, $fboUnits);
    if (empty($state['is_active']) || (float)($rule['target_price'] ?? 0) <= 0) {
        return null;
    }
    $pricingMode = ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), (string)($rule['marketplace'] ?? 'ozon'));
    $isPromotionFloor = $pricingMode === 'promotion_floor';
    return [
        'target' => $offerId,
        'mode' => $isPromotionFloor ? 'promotion_floor' : 'set_fixed',
        'value' => round((float)$rule['target_price'], 2),
        'label' => ($isPromotionFloor ? 'FBO минимум акции ' : 'FBO цена ') . round((float)$rule['target_price'], 2) . ' ₽',
        'source_line' => 'FBO Tool: ' . $offerId . ' ' . ($isPromotionFloor ? 'promotion_floor=' : '=') . round((float)$rule['target_price'], 2),
        'matched_by' => 'fbo_offer_id',
        'matched_value' => $offerId,
        'fbo_rule' => $rule,
        'fbo_state' => $state,
    ];
}

function ozon_fbo_tool_active_force_rule_for_offer(int $connectionId, string $offerId, array $cfg = [], array $options = []): ?array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerId = trim($offerId);
    if ($connectionId <= 0 || $offerId === '') {
        return null;
    }

    $connection = ozon_price_connection_get($connectionId, $cfg);
    $defaults = is_array($connection) ? ozon_fbo_tool_default_warehouse_options($connection) : [];
    $warehouse = ozon_fbo_tool_options_warehouse(array_replace($defaults, $options));
    $warehouseFilter = '';
    $args = [$connectionId, $offerId];
    if ($warehouse['warehouse_key'] !== '') {
        $warehouseFilter = $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
            ? ' AND (r.warehouse_key = ? OR r.marketplace = \'wb\')'
            : ' AND r.warehouse_key = ?';
        $args[] = $warehouse['warehouse_key'];
    }
    $itemWarehouseJoin = $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
        ? "AND i.warehouse_key = '" . OZON_FBO_TOOL_WB_WAREHOUSE_KEY . "'"
        : 'AND i.warehouse_key = r.warehouse_key';
    $warehouseOrder = $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
        ? "r.warehouse_key = '" . OZON_FBO_TOOL_WB_WAREHOUSE_KEY . "' DESC,"
        : '';
    $fboUnitsExpr = $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
        ? 'COALESCE(i.fbo_present, 0)'
        : 'COALESCE(i.fbo_present, 0) + COALESCE(i.fbo_reserved, 0)';

    $st = db()->prepare("
        SELECT
            r.*,
            {$fboUnitsExpr} AS fbo_units,
            i.last_refreshed_at,
            i.warehouse_name AS item_warehouse_name
        FROM feedtools_ozon_fbo_price_rules r
        LEFT JOIN feedtools_ozon_fbo_items i
            ON i.connection_id = r.connection_id
           {$itemWarehouseJoin}
           AND i.offer_id = r.offer_id
        WHERE r.connection_id = ?
          AND r.offer_id = ?
          {$warehouseFilter}
          AND r.status = 'active'
          AND r.target_price > 0
          AND {$fboUnitsExpr} > 0
        ORDER BY {$warehouseOrder} r.updated_at DESC
        LIMIT 1
    ");
    $st->execute($args);
    $rule = $st->fetch();
    if (!is_array($rule)) {
        return null;
    }
    $units = max(0, (int)($rule['fbo_units'] ?? 0));
    $state = ozon_fbo_tool_rule_state($rule, $units);
    if (empty($state['is_active'])) {
        return null;
    }
    $pricingMode = ozon_fbo_tool_normalize_pricing_mode((string)($rule['pricing_mode'] ?? 'exact'), (string)($rule['marketplace'] ?? 'ozon'));
    $isPromotionFloor = $pricingMode === 'promotion_floor';
    return [
        'target' => $offerId,
        'mode' => $isPromotionFloor ? 'promotion_floor' : 'set_fixed',
        'value' => round((float)$rule['target_price'], 2),
        'label' => ($isPromotionFloor ? 'FBO минимум акции ' : 'FBO цена ') . round((float)$rule['target_price'], 2) . ' ₽',
        'source_line' => 'FBO Tool: ' . $offerId . ' ' . ($isPromotionFloor ? 'promotion_floor=' : '=') . round((float)$rule['target_price'], 2),
        'matched_by' => 'fbo_offer_id',
        'matched_value' => $offerId,
        'fbo_rule' => $rule,
        'fbo_state' => $state,
        'fbo_units' => $units,
    ];
}

function ozon_fbo_tool_active_rule_warehouses_for_offers(int $connectionId, array $offerIds, array $cfg = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT marketplace, warehouse_id, warehouse_key, warehouse_name, offer_id
            FROM feedtools_ozon_fbo_price_rules
            WHERE connection_id = ?
              AND status = 'active'
              AND target_price > 0
              AND offer_id IN ({$placeholders})
        ");
        $st->execute(array_merge([$connectionId], $chunk));
        foreach ($st->fetchAll() ?: [] as $row) {
            if ((string)($row['marketplace'] ?? '') === 'wb') {
                $row['warehouse_id'] = '';
                $row['warehouse_key'] = OZON_FBO_TOOL_WB_WAREHOUSE_KEY;
                $row['warehouse_name'] = OZON_FBO_TOOL_WB_WAREHOUSE_NAME;
            }
            $warehouseKey = trim((string)($row['warehouse_key'] ?? ''));
            if ($warehouseKey === '') {
                continue;
            }
            if (!isset($out[$warehouseKey])) {
                $out[$warehouseKey] = [
                    'warehouse_id' => trim((string)($row['warehouse_id'] ?? '')),
                    'warehouse_key' => $warehouseKey,
                    'warehouse_name' => trim((string)($row['warehouse_name'] ?? '')),
                    'offer_ids' => [],
                ];
            }
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$warehouseKey]['offer_ids'][$offerId] = true;
            }
        }
    }

    foreach ($out as &$row) {
        $row['offer_ids'] = array_keys((array)$row['offer_ids']);
    }
    unset($row);
    return array_values($out);
}

function ozon_fbo_tool_refresh_active_rule_stocks_for_offers(int $connectionId, array $offerIds, array $cfg = [], ?callable $log = null): array
{
    $summary = [
        'warehouses' => 0,
        'requested' => 0,
        'fbo_active' => 0,
        'rules_annulled' => 0,
        'error' => '',
    ];
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        return $summary;
    }
    $warehouses = ozon_fbo_tool_active_rule_warehouses_for_offers($connectionId, $offerIds, $cfg);
    $summary['warehouses'] = count($warehouses);
    $log = $log ?: static function (string $line): void {};
    foreach ($warehouses as $warehouse) {
        $offerIdsForWarehouse = array_values((array)($warehouse['offer_ids'] ?? []));
        if (!$offerIdsForWarehouse) {
            continue;
        }
        try {
            $log('[wb fbo price guard] refreshing scope=' . (string)($warehouse['warehouse_key'] ?? '') . ' offers=' . count($offerIdsForWarehouse) . "\n");
            $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $offerIdsForWarehouse, $cfg, $log, $warehouse);
            $summary['requested'] += (int)($refresh['requested'] ?? 0);
            $summary['fbo_active'] += (int)($refresh['fbo_active'] ?? 0);
            $summary['rules_annulled'] += (int)($refresh['rules_annulled'] ?? 0);
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
            $log('[wb fbo price guard] refresh failed: ' . $e->getMessage() . "\n");
        }
    }
    return $summary;
}

function ozon_fbo_tool_to_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        $value = $value['price'] ?? $value['value'] ?? null;
    }
    if ($value === null || $value === '') {
        return null;
    }
    $num = ozon_price_to_float((string)$value);
    return $num > 0 ? round($num, 2) : null;
}

function ozon_fbo_tool_price_value(array $priceItem, array $paths): ?float
{
    foreach ($paths as $path) {
        $cursor = $priceItem;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                $cursor = null;
                break;
            }
            $cursor = $cursor[$part];
        }
        $value = ozon_fbo_tool_to_float($cursor);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

function ozon_fbo_tool_stock_row_is_fbo(array $stockRow): bool
{
    $type = strtolower(trim((string)($stockRow['type'] ?? $stockRow['stock_type'] ?? '')));
    $warehouseType = strtolower(trim((string)($stockRow['warehouse_type'] ?? '')));
    $deliverySchema = strtolower(trim((string)($stockRow['delivery_schema'] ?? $stockRow['schema'] ?? '')));
    foreach ([$type, $warehouseType, $deliverySchema] as $value) {
        if ($value !== '' && str_contains($value, 'fbo')) {
            return true;
        }
    }
    return false;
}

function ozon_fbo_tool_stock_present(array $stockRow): int
{
    foreach (['present', 'amount', 'available', 'quantity', 'qty'] as $key) {
        if (array_key_exists($key, $stockRow)) {
            return max(0, (int)$stockRow[$key]);
        }
    }
    return 0;
}

function ozon_fbo_tool_stock_reserved(array $stockRow): int
{
    foreach (['reserved', 'reserve', 'reserved_qty'] as $key) {
        if (array_key_exists($key, $stockRow)) {
            return max(0, (int)$stockRow[$key]);
        }
    }
    return 0;
}

function ozon_fbo_tool_fetch_stock_map(array $oz, array $offerIds, callable $log): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $map = [];
    $batchNo = 0;

    foreach (array_chunk($offerIds, 500) as $chunk) {
        $batchNo++;
        $batchFboSeen = 0;
        $resp = ozon_post_json($oz, '/v4/product/info/stocks', [
            'filter' => [
                'offer_id' => array_values($chunk),
                'visibility' => 'ALL',
            ],
            'limit' => count($chunk),
        ]);
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
            $rawFboStocks = [];
            foreach ((array)($item['stocks'] ?? []) as $stockRow) {
                if (!is_array($stockRow) || !ozon_fbo_tool_stock_row_is_fbo($stockRow)) {
                    continue;
                }
                $present += ozon_fbo_tool_stock_present($stockRow);
                $reserved += ozon_fbo_tool_stock_reserved($stockRow);
                $rawFboStocks[] = $stockRow;
            }
            if ($present <= 0 && $reserved <= 0) {
                continue;
            }
            $batchFboSeen++;
            $map[$offerId] = [
                'offer_id' => $offerId,
                'product_id' => (int)($item['product_id'] ?? 0),
                'fbo_present' => $present,
                'fbo_reserved' => $reserved,
                'raw_stocks' => $rawFboStocks,
                'raw_item' => $item,
            ];
        }
        $log('[fbo stocks] batch ' . $batchNo . ': requested=' . count($chunk) . ' · returned=' . count($items) . ' · fbo_seen=' . $batchFboSeen . "\n");
    }

    return $map;
}

function ozon_fbo_tool_wb_card_stock_units(array $card): array
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
        $sku = '';
        foreach ((array)($size['skus'] ?? []) as $candidate) {
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

function ozon_fbo_tool_wb_card_for_offer_id(string $offerId, array $cardsIndex): ?array
{
    $byVendorCode = is_array($cardsIndex['by_vendor_code'] ?? null) ? $cardsIndex['by_vendor_code'] : [];
    foreach (array_values(array_unique(array_filter([
        $offerId,
        function_exists('wb_price_tool_strip_supplier_suffix') ? wb_price_tool_strip_supplier_suffix($offerId) : $offerId,
    ]))) as $candidate) {
        if (isset($byVendorCode[$candidate]) && is_array($byVendorCode[$candidate])) {
            return $byVendorCode[$candidate];
        }
    }
    return null;
}

function ozon_fbo_tool_wb_current_good_for_offer_id(string $offerId, int $nmId, array $goodsIndex): ?array
{
    $byVendorCode = is_array($goodsIndex['by_vendor_code'] ?? null) ? $goodsIndex['by_vendor_code'] : [];
    foreach (array_values(array_unique(array_filter([
        $offerId,
        function_exists('wb_price_tool_strip_supplier_suffix') ? wb_price_tool_strip_supplier_suffix($offerId) : $offerId,
    ]))) as $candidate) {
        if (isset($byVendorCode[$candidate]) && is_array($byVendorCode[$candidate])) {
            return $byVendorCode[$candidate];
        }
    }
    $byNmId = is_array($goodsIndex['by_nm_id'] ?? null) ? $goodsIndex['by_nm_id'] : [];
    return $nmId > 0 && isset($byNmId[$nmId]) && is_array($byNmId[$nmId]) ? $byNmId[$nmId] : null;
}

function ozon_fbo_tool_wb_stock_int(array $row, array $keys): int
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && is_numeric($row[$key])) {
            return max(0, (int)$row[$key]);
        }
    }
    return 0;
}

function ozon_fbo_tool_wb_stock_rows(array $cfg, array $connection, callable $log): array
{
    $client = wb_price_tool_client($cfg, $connection);
    $rows = $client->getWbWarehouseStocks([], [], 250000, 0);
    if (is_array($rows['data'] ?? null)) {
        $rows = $rows['data'];
    }
    if (is_array($rows['items'] ?? null)) {
        $rows = $rows['items'];
    }
    if (is_array($rows['stocks'] ?? null)) {
        $rows = $rows['stocks'];
    }
    $rows = is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
    $log('[wb fbo stocks] source=analytics stocks-report/wb-warehouses rows=' . count($rows) . "\n");
    return $rows;
}

function ozon_fbo_tool_fetch_wb_stock_map(array $cfg, array $connection, array $offerIds, array $warehouse, callable $log): array
{
    $warehouseKey = trim((string)($warehouse['warehouse_key'] ?? ''));
    if ($warehouseKey === '') {
        $warehouseKey = OZON_FBO_TOOL_WB_WAREHOUSE_KEY;
    }

    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $cardsIndex = wb_price_tool_fetch_all_cards($runtimeCfg, $connection, false, 1800);
    $goodsIndex = wb_price_tool_fetch_all_goods($runtimeCfg, $connection, false, 300);
    if (!$offerIds) {
        foreach ((array)($cardsIndex['items'] ?? []) as $card) {
            if (!is_array($card)) {
                continue;
            }
            $vendorCode = trim((string)($card['vendorCode'] ?? ''));
            if ($vendorCode !== '') {
                $offerIds[] = $vendorCode;
            }
        }
        $offerIds = array_values(array_unique($offerIds));
    }

    $byNmId = [];
    $byVendorCode = [];
    $map = [];
    $skippedNoCard = 0;
    $skippedNoChrt = 0;
    foreach ($offerIds as $offerId) {
        $card = ozon_fbo_tool_wb_card_for_offer_id($offerId, $cardsIndex);
        if (!is_array($card)) {
            $skippedNoCard++;
            continue;
        }
        $stockUnits = ozon_fbo_tool_wb_card_stock_units($card);
        if (!$stockUnits) {
            $skippedNoChrt++;
            continue;
        }
        $nmId = (int)($card['nmID'] ?? 0);
        $good = ozon_fbo_tool_wb_current_good_for_offer_id($offerId, $nmId, $goodsIndex);
        $firstUnit = (array)$stockUnits[0];
        $map[$offerId] = [
            'offer_id' => $offerId,
            'product_id' => $nmId,
            'chrt_id' => (int)($firstUnit['chrt_id'] ?? 0),
            'sku' => (string)($firstUnit['sku'] ?? ''),
            'name' => trim((string)($card['title'] ?? $good['name'] ?? '')),
            'fbo_present' => 0,
            'fbo_reserved' => 0,
            'price' => is_array($good) ? wb_price_tool_current_price($good) : null,
            'marketing_seller_price' => is_array($good) ? wb_price_tool_current_discounted_price($good) : null,
            'raw_stocks' => [],
            'raw_card' => $card,
            'raw_good' => is_array($good) ? $good : [],
        ];
        if ($nmId > 0) {
            $byNmId[$nmId][$offerId] = true;
        }
        foreach (array_values(array_unique(array_filter([
            $offerId,
            function_exists('wb_price_tool_strip_supplier_suffix') ? wb_price_tool_strip_supplier_suffix($offerId) : $offerId,
            trim((string)($card['vendorCode'] ?? '')),
        ]))) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $byVendorCode[$candidate] = $offerId;
            }
        }
    }

    $targetOfferIds = $offerIds ? array_fill_keys($offerIds, true) : [];
    $matchedRows = 0;
    foreach (ozon_fbo_tool_wb_stock_rows($runtimeCfg, $connection, $log) as $item) {
        $offerId = '';
        $supplierArticle = trim((string)($item['supplierArticle'] ?? $item['vendorCode'] ?? $item['vendor_code'] ?? ''));
        foreach (array_values(array_unique(array_filter([
            $supplierArticle,
            function_exists('wb_price_tool_strip_supplier_suffix') ? wb_price_tool_strip_supplier_suffix($supplierArticle) : $supplierArticle,
        ]))) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && isset($byVendorCode[$candidate])) {
                $offerId = (string)$byVendorCode[$candidate];
                break;
            }
        }
        if ($offerId === '') {
            $nmId = ozon_fbo_tool_wb_stock_int($item, ['nmId', 'nmID', 'nm_id']);
            $nmOffers = $nmId > 0 ? array_keys((array)($byNmId[$nmId] ?? [])) : [];
            if (count($nmOffers) === 1) {
                $offerId = (string)$nmOffers[0];
            }
        }
        if ($offerId === '' || !isset($map[$offerId])) {
            continue;
        }
        if ($targetOfferIds && !isset($targetOfferIds[$offerId])) {
            continue;
        }
        $quantity = ozon_fbo_tool_wb_stock_int($item, ['quantity', 'qty', 'stock', 'amount']);
        $quantityFull = ozon_fbo_tool_wb_stock_int($item, ['quantityFull', 'quantity_full']);
        $reserved = ozon_fbo_tool_wb_stock_int($item, ['inWayToClient', 'in_way_to_client'])
            + ozon_fbo_tool_wb_stock_int($item, ['inWayFromClient', 'in_way_from_client']);
        if ($reserved <= 0 && $quantityFull > $quantity) {
            $reserved = $quantityFull - $quantity;
        }
        $map[$offerId]['fbo_present'] += $quantity;
        $map[$offerId]['fbo_reserved'] += max(0, $reserved);
        $map[$offerId]['raw_stocks'][] = $item + ['_feedtools_stock_source' => 'wb_supplier_stocks'];
        $matchedRows++;
    }
    $matchedOffers = 0;
    foreach ($map as $row) {
        if (max(0, (int)($row['fbo_present'] ?? 0)) > 0) {
            $matchedOffers++;
        }
    }
    $log('[wb fbo stocks] scope=' . $warehouseKey . ' matched_rows=' . $matchedRows . ' matched_offers=' . $matchedOffers . "\n");
    if ($skippedNoCard > 0 || $skippedNoChrt > 0) {
        $log('[wb fbo map] skipped_no_card=' . $skippedNoCard . ' skipped_no_chrt=' . $skippedNoChrt . "\n");
    }

    return $map;
}

function ozon_fbo_tool_refresh_offer_stocks(int $connectionId, array $offerIds, array $cfg = [], ?callable $log = null, array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0) {
        return ['requested' => 0, 'fbo_active' => 0];
    }

    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        return ['requested' => count($offerIds), 'fbo_active' => 0];
    }
    $marketplace = (string)($connection['marketplace'] ?? 'ozon');
    if (!$offerIds && $marketplace !== 'wb') {
        return ['requested' => 0, 'fbo_active' => 0];
    }
    $defaults = ozon_fbo_tool_default_warehouse_options($connection);
    $warehouse = ozon_fbo_tool_options_warehouse(array_replace($defaults, $options));
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $log = $log ?: static function (string $line): void {};
    if ($marketplace === 'wb') {
        $stockMap = ozon_fbo_tool_fetch_wb_stock_map($runtimeCfg, $connection, $offerIds, $warehouse, $log);
    } elseif ($marketplace === 'ozon') {
        $oz = ozon_cfg_or_fail($runtimeCfg);
        $stockMap = ozon_fbo_tool_fetch_stock_map($oz, $offerIds, $log);
    } else {
        return ['requested' => count($offerIds), 'fbo_active' => 0];
    }

    $pdo = db();
    $now = date('Y-m-d H:i:s');
    if ($offerIds) {
        foreach (array_chunk($offerIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("
                UPDATE feedtools_ozon_fbo_items
                SET fbo_present = 0,
                    fbo_reserved = 0,
                    last_refreshed_at = ?
                WHERE connection_id = ?
                  AND warehouse_key = ?
                  AND offer_id IN ({$placeholders})
            ");
            $st->execute(array_merge([$now, $connectionId, $warehouse['warehouse_key']], $chunk));
        }
    } else {
        $st = $pdo->prepare("
            UPDATE feedtools_ozon_fbo_items
            SET fbo_present = 0,
                fbo_reserved = 0,
                last_refreshed_at = ?
            WHERE connection_id = ?
              AND warehouse_key = ?
        ");
        $st->execute([$now, $connectionId, $warehouse['warehouse_key']]);
    }

    $up = $pdo->prepare("
        INSERT INTO feedtools_ozon_fbo_items (
            connection_id, marketplace, warehouse_id, warehouse_key, warehouse_name,
            offer_id, product_id, chrt_id, sku, name,
            fbo_present, fbo_reserved,
            price, marketing_seller_price,
            raw_stocks_json, raw_price_json, raw_info_json,
            first_seen_at, last_seen_at, last_refreshed_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            marketplace = VALUES(marketplace),
            warehouse_id = VALUES(warehouse_id),
            warehouse_name = VALUES(warehouse_name),
            product_id = IF(VALUES(product_id) > 0, VALUES(product_id), product_id),
            chrt_id = IF(VALUES(chrt_id) > 0, VALUES(chrt_id), chrt_id),
            sku = IF(VALUES(sku) <> '', VALUES(sku), sku),
            name = IF(VALUES(name) <> '', VALUES(name), name),
            fbo_present = VALUES(fbo_present),
            fbo_reserved = VALUES(fbo_reserved),
            price = IF(VALUES(price) IS NOT NULL, VALUES(price), price),
            marketing_seller_price = IF(VALUES(marketing_seller_price) IS NOT NULL, VALUES(marketing_seller_price), marketing_seller_price),
            raw_stocks_json = VALUES(raw_stocks_json),
            raw_price_json = IF(VALUES(raw_price_json) IS NOT NULL, VALUES(raw_price_json), raw_price_json),
            raw_info_json = IF(VALUES(raw_info_json) IS NOT NULL, VALUES(raw_info_json), raw_info_json),
            last_seen_at = VALUES(last_seen_at),
            last_refreshed_at = VALUES(last_refreshed_at)
    ");
    foreach ($stockMap as $offerId => $stockRow) {
        if (!is_array($stockRow)) {
            continue;
        }
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $up->execute([
            $connectionId,
            $marketplace,
            $warehouse['warehouse_id'],
            $warehouse['warehouse_key'],
            $warehouse['warehouse_name'],
            $offerId,
            (int)($stockRow['product_id'] ?? 0),
            (int)($stockRow['chrt_id'] ?? 0),
            mb_substr((string)($stockRow['sku'] ?? ''), 0, 64, 'UTF-8'),
            (string)($stockRow['name'] ?? ''),
            max(0, (int)($stockRow['fbo_present'] ?? 0)),
            max(0, (int)($stockRow['fbo_reserved'] ?? 0)),
            isset($stockRow['price']) && $stockRow['price'] !== null ? (float)$stockRow['price'] : null,
            isset($stockRow['marketing_seller_price']) && $stockRow['marketing_seller_price'] !== null ? (float)$stockRow['marketing_seller_price'] : null,
            json_encode($stockRow['raw_stocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            !empty($stockRow['raw_good']) ? json_encode($stockRow['raw_good'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            !empty($stockRow['raw_card']) ? json_encode($stockRow['raw_card'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $now,
            $now,
            $now,
        ]);
    }

    $annulledRules = ozon_fbo_tool_annul_inactive_price_rules($connectionId, 'fbo_stock_refresh', $cfg, $offerIds, $warehouse);

    $activeRows = 0;
    foreach ($stockMap as $stockRow) {
        $present = max(0, (int)($stockRow['fbo_present'] ?? 0));
        $reserved = max(0, (int)($stockRow['fbo_reserved'] ?? 0));
        if ($marketplace === 'wb' ? $present > 0 : ($present + $reserved > 0)) {
            $activeRows++;
        }
    }

    return [
        'requested' => $offerIds ? count($offerIds) : count($stockMap),
        'fbo_active' => $activeRows,
        'rules_annulled' => (int)($annulledRules['total'] ?? 0),
    ];
}

function ozon_fbo_tool_fetch_info_items(array $oz, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $out = [];
    foreach (array_chunk($offerIds, 1000) as $chunk) {
        $resp = ozon_post_json($oz, '/v3/product/info/list', [
            'offer_id' => array_values($chunk),
        ]);
        foreach ((array)($resp['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $item;
            }
        }
    }
    return $out;
}

function ozon_fbo_tool_fetch_price_items(array $oz, array $offerIds, int $chunkSize = 100): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($offerIds, max(1, $chunkSize)) as $chunk) {
        $resp = ozon_post_json($oz, '/v5/product/info/prices', [
            'filter' => ['offer_id' => array_values($chunk)],
            'limit' => count($chunk),
        ]);
        foreach ((array)($resp['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $item;
            }
        }
    }

    return $out;
}

function ozon_fbo_tool_fetch_analytics_stock_map(array $oz, array $skusByOffer, callable $log): array
{
    $skuToOffer = [];
    foreach ($skusByOffer as $offerId => $sku) {
        $offerId = trim((string)$offerId);
        $sku = trim((string)$sku);
        if ($offerId !== '' && $sku !== '') {
            $skuToOffer[$sku] = $offerId;
        }
    }
    if (!$skuToOffer) {
        return [];
    }

    $out = [];
    $batchNo = 0;
    $requestNo = 0;
    foreach (array_chunk(array_keys($skuToOffer), 100) as $chunk) {
        $batchNo++;
        $offset = 0;
        $limit = 1000;
        do {
            if ($requestNo > 0) {
                usleep(2200000);
            }
            $requestNo++;
            $payload = [
                'skus' => array_values($chunk),
                'limit' => $limit,
                'offset' => $offset,
            ];
            $resp = null;
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                try {
                    $resp = ozon_post_json($oz, '/v1/analytics/stocks', $payload);
                    break;
                } catch (Throwable $e) {
                    if (!str_contains($e->getMessage(), '429') || $attempt >= 4) {
                        throw $e;
                    }
                    sleep(2 + $attempt);
                }
            }
            if (!is_array($resp)) {
                $resp = [];
            }
            $items = is_array($resp['items'] ?? null) ? (array)$resp['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $offerId = trim((string)($item['offer_id'] ?? ''));
                if ($offerId === '') {
                    $sku = trim((string)($item['sku'] ?? ''));
                    $offerId = (string)($skuToOffer[$sku] ?? '');
                }
                if ($offerId === '') {
                    continue;
                }

                $days = array_key_exists('days_without_sales', $item) && $item['days_without_sales'] !== null
                    ? max(0, (int)$item['days_without_sales'])
                    : null;
                $daysCluster = array_key_exists('days_without_sales_cluster', $item) && $item['days_without_sales_cluster'] !== null
                    ? max(0, (int)$item['days_without_sales_cluster'])
                    : null;
                $current = is_array($out[$offerId] ?? null) ? (array)$out[$offerId] : [
                    'offer_id' => $offerId,
                    'days_without_sales' => null,
                    'days_without_sales_cluster' => null,
                    'ads' => null,
                    'turnover_grade' => '',
                    'rows' => [],
                ];
                $current['rows'][] = $item;
                if ($days !== null && ($current['days_without_sales'] === null || $days > (int)$current['days_without_sales'])) {
                    $current['days_without_sales'] = $days;
                    $current['ads'] = isset($item['ads']) ? round((float)$item['ads'], 4) : null;
                    $current['turnover_grade'] = mb_substr((string)($item['turnover_grade'] ?? ''), 0, 64, 'UTF-8');
                }
                if ($daysCluster !== null && ($current['days_without_sales_cluster'] === null || $daysCluster > (int)$current['days_without_sales_cluster'])) {
                    $current['days_without_sales_cluster'] = $daysCluster;
                }
                $out[$offerId] = $current;
            }
            $offset += $limit;
        } while (count($items) >= $limit);

        $log('[fbo analytics] batch ' . $batchNo . ': requested_skus=' . count($chunk) . ' · matched=' . count($out) . "\n");
    }

    return $out;
}

function ozon_fbo_tool_price_index_summary(array $priceItem): array
{
    $indexes = is_array($priceItem['price_indexes'] ?? null) ? (array)$priceItem['price_indexes'] : [];
    $metric = $indexes ? ozon_price_effective_index_metric($indexes) : null;
    return [
        'color_index' => trim((string)($indexes['color_index'] ?? $priceItem['color_index'] ?? '')),
        'price_index_value' => is_array($metric) ? (float)($metric['value'] ?? 0) : null,
        'price_index_source' => is_array($metric) ? (string)($metric['source'] ?? '') : '',
    ];
}

function ozon_fbo_tool_upsert_items(int $connectionId, array $stockMap, array $priceItems, array $infoItems, array $analyticsItems = [], ?array $resetOfferIds = null): void
{
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    if ($resetOfferIds === null) {
        $reset = $pdo->prepare("
            UPDATE feedtools_ozon_fbo_items
            SET fbo_present = 0,
                fbo_reserved = 0,
                last_refreshed_at = ?
            WHERE connection_id = ?
        ");
        $reset->execute([$now, $connectionId]);
    } else {
        $resetOfferIds = array_values(array_filter(array_unique(array_map(
            static fn($value): string => trim((string)$value),
            $resetOfferIds
        ))));
        foreach (array_chunk($resetOfferIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $reset = $pdo->prepare("
                UPDATE feedtools_ozon_fbo_items
                SET fbo_present = 0,
                    fbo_reserved = 0,
                    last_refreshed_at = ?
                WHERE connection_id = ?
                  AND offer_id IN ({$placeholders})
            ");
            $reset->execute(array_merge([$now, $connectionId], $chunk));
        }
    }

    $up = $pdo->prepare("
        INSERT INTO feedtools_ozon_fbo_items (
            connection_id, offer_id, product_id, sku, name,
            fbo_present, fbo_reserved,
            price, marketing_seller_price, min_price, old_price,
            color_index, price_index_value, price_index_source,
            days_without_sales, days_without_sales_cluster, ads, turnover_grade,
            raw_stocks_json, raw_price_json, raw_info_json, raw_analytics_json,
            first_seen_at, last_seen_at, last_refreshed_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            product_id = VALUES(product_id),
            sku = VALUES(sku),
            name = VALUES(name),
            fbo_present = VALUES(fbo_present),
            fbo_reserved = VALUES(fbo_reserved),
            price = VALUES(price),
            marketing_seller_price = VALUES(marketing_seller_price),
            min_price = VALUES(min_price),
            old_price = VALUES(old_price),
            color_index = VALUES(color_index),
            price_index_value = VALUES(price_index_value),
            price_index_source = VALUES(price_index_source),
            days_without_sales = VALUES(days_without_sales),
            days_without_sales_cluster = VALUES(days_without_sales_cluster),
            ads = VALUES(ads),
            turnover_grade = VALUES(turnover_grade),
            raw_stocks_json = VALUES(raw_stocks_json),
            raw_price_json = VALUES(raw_price_json),
            raw_info_json = VALUES(raw_info_json),
            raw_analytics_json = VALUES(raw_analytics_json),
            last_seen_at = VALUES(last_seen_at),
            last_refreshed_at = VALUES(last_refreshed_at)
    ");

    foreach ($stockMap as $offerId => $stockRow) {
        if (!is_array($stockRow)) {
            continue;
        }
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $priceItem = is_array($priceItems[$offerId] ?? null) ? (array)$priceItems[$offerId] : [];
        $infoItem = is_array($infoItems[$offerId] ?? null) ? (array)$infoItems[$offerId] : [];
        $index = ozon_fbo_tool_price_index_summary($priceItem);
        $analytics = is_array($analyticsItems[$offerId] ?? null) ? (array)$analyticsItems[$offerId] : [];
        $productId = (int)($stockRow['product_id'] ?? 0);
        if ($productId <= 0) {
            $productId = (int)($priceItem['product_id'] ?? $infoItem['id'] ?? 0);
        }
        $name = trim((string)($infoItem['name'] ?? $priceItem['name'] ?? ''));
        $sku = trim((string)($infoItem['sku'] ?? $priceItem['sku'] ?? ''));

        $up->execute([
            $connectionId,
            $offerId,
            $productId,
            mb_substr($sku, 0, 64, 'UTF-8'),
            $name,
            max(0, (int)($stockRow['fbo_present'] ?? 0)),
            max(0, (int)($stockRow['fbo_reserved'] ?? 0)),
            ozon_fbo_tool_price_value($priceItem, ['price.price', 'price']),
            ozon_fbo_tool_price_value($priceItem, ['price.marketing_seller_price', 'marketing_seller_price']),
            ozon_fbo_tool_price_value($priceItem, ['price.min_price', 'min_price']),
            ozon_fbo_tool_price_value($priceItem, ['price.old_price', 'old_price']),
            mb_substr((string)($index['color_index'] ?? ''), 0, 64, 'UTF-8'),
            (isset($index['price_index_value']) && (float)$index['price_index_value'] > 0) ? (float)$index['price_index_value'] : null,
            mb_substr((string)($index['price_index_source'] ?? ''), 0, 64, 'UTF-8'),
            isset($analytics['days_without_sales']) && $analytics['days_without_sales'] !== null ? (int)$analytics['days_without_sales'] : null,
            isset($analytics['days_without_sales_cluster']) && $analytics['days_without_sales_cluster'] !== null ? (int)$analytics['days_without_sales_cluster'] : null,
            isset($analytics['ads']) && $analytics['ads'] !== null ? (float)$analytics['ads'] : null,
            mb_substr((string)($analytics['turnover_grade'] ?? ''), 0, 64, 'UTF-8'),
            json_encode($stockRow['raw_stocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($priceItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($infoItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $analytics ? json_encode($analytics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $now,
            $now,
            $now,
        ]);
    }
}

function ozon_fbo_tool_refresh_offers(int $connectionId, array $offerIds, array $cfg = [], ?callable $log = null, array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        return ['connection_id' => $connectionId, 'requested' => count($offerIds), 'fbo_items' => 0];
    }

    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Не удалось найти подключение для FBO Tool.');
    }
    if ((string)($connection['marketplace'] ?? '') === 'wb') {
        $startedAt = microtime(true);
        $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $offerIds, $cfg, $log, $options);
        return [
            'connection_id' => $connectionId,
            'requested' => count($offerIds),
            'fbo_items' => (int)($refresh['fbo_active'] ?? 0),
            'prices_loaded' => 0,
            'info_loaded' => count($offerIds),
            'analytics_loaded' => 0,
            'rules_annulled' => (int)($refresh['rules_annulled'] ?? 0),
            'elapsed_sec' => round(microtime(true) - $startedAt, 2),
        ];
    }
    if ((string)($connection['marketplace'] ?? '') !== 'ozon') {
        throw new RuntimeException('FBO Tool доступен только для подключения Ozon или WB.');
    }
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($runtimeCfg);
    $log = $log ?: static function (string $line): void {};

    $startedAt = microtime(true);
    $stockMap = ozon_fbo_tool_fetch_stock_map($oz, $offerIds, $log);
    $fboOfferIds = array_keys($stockMap);
    $priceItems = $fboOfferIds ? ozon_fbo_tool_fetch_price_items($oz, $fboOfferIds, 100) : [];
    $infoItems = $fboOfferIds ? ozon_fbo_tool_fetch_info_items($oz, $fboOfferIds) : [];
    $skusByOffer = [];
    foreach ($fboOfferIds as $offerId) {
        $sku = trim((string)($infoItems[$offerId]['sku'] ?? $priceItems[$offerId]['sku'] ?? ''));
        if ($sku !== '') {
            $skusByOffer[$offerId] = $sku;
        }
    }
    $analyticsItems = ozon_fbo_tool_fetch_analytics_stock_map($oz, $skusByOffer, $log);
    ozon_fbo_tool_upsert_items($connectionId, $stockMap, $priceItems, $infoItems, $analyticsItems, $offerIds);
    $annulledRules = ozon_fbo_tool_annul_inactive_price_rules($connectionId, 'fbo_selected_refresh', $cfg, $offerIds, $options);

    return [
        'connection_id' => $connectionId,
        'requested' => count($offerIds),
        'fbo_items' => count($stockMap),
        'prices_loaded' => count($priceItems),
        'info_loaded' => count($infoItems),
        'analytics_loaded' => count($analyticsItems),
        'rules_annulled' => (int)($annulledRules['total'] ?? 0),
        'elapsed_sec' => round(microtime(true) - $startedAt, 2),
    ];
}

function ozon_fbo_tool_refresh(int $connectionId, array $cfg = [], ?callable $log = null, array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Не удалось найти подключение для FBO Tool.');
    }
    if ((string)($connection['marketplace'] ?? '') === 'wb') {
        $startedAt = microtime(true);
        $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, [], $cfg, $log, $options);
        return [
            'connection_id' => $connectionId,
            'offers_seen' => (int)($refresh['requested'] ?? 0),
            'fbo_items' => (int)($refresh['fbo_active'] ?? 0),
            'prices_loaded' => 0,
            'info_loaded' => (int)($refresh['requested'] ?? 0),
            'analytics_loaded' => 0,
            'rules_annulled' => (int)($refresh['rules_annulled'] ?? 0),
            'elapsed_sec' => round(microtime(true) - $startedAt, 2),
        ];
    }
    if ((string)($connection['marketplace'] ?? '') !== 'ozon') {
        throw new RuntimeException('FBO Tool доступен только для подключения Ozon или WB.');
    }
    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($runtimeCfg);
    $log = $log ?: static function (string $line): void {};

    $startedAt = microtime(true);
    $offerIds = ozon_fetch_all_offer_ids_v3($oz);
    $log('[fbo] offers=' . count($offerIds) . "\n");
    $stockMap = ozon_fbo_tool_fetch_stock_map($oz, $offerIds, $log);
    $fboOfferIds = array_keys($stockMap);
    $priceItems = ozon_fbo_tool_fetch_price_items($oz, $fboOfferIds, 100);
    $infoItems = ozon_fbo_tool_fetch_info_items($oz, $fboOfferIds);
    $skusByOffer = [];
    foreach ($fboOfferIds as $offerId) {
        $sku = trim((string)($infoItems[$offerId]['sku'] ?? $priceItems[$offerId]['sku'] ?? ''));
        if ($sku !== '') {
            $skusByOffer[$offerId] = $sku;
        }
    }
    $analyticsItems = ozon_fbo_tool_fetch_analytics_stock_map($oz, $skusByOffer, $log);
    ozon_fbo_tool_upsert_items($connectionId, $stockMap, $priceItems, $infoItems, $analyticsItems);
    $annulledRules = ozon_fbo_tool_annul_inactive_price_rules($connectionId, 'fbo_refresh', $cfg, [], $options);

    return [
        'connection_id' => $connectionId,
        'offers_seen' => count($offerIds),
        'fbo_items' => count($stockMap),
        'prices_loaded' => count($priceItems),
        'info_loaded' => count($infoItems),
        'analytics_loaded' => count($analyticsItems),
        'rules_annulled' => (int)($annulledRules['total'] ?? 0),
        'elapsed_sec' => round(microtime(true) - $startedAt, 2),
    ];
}

function ozon_fbo_tool_items(int $connectionId, array $filters = [], array $cfg = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }

    $where = ['connection_id = ?'];
    $args = [$connectionId];
    $warehouse = ozon_fbo_tool_options_warehouse($filters);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $marketplace = is_array($connection) ? (string)($connection['marketplace'] ?? 'ozon') : 'ozon';
    if ($warehouse['warehouse_key'] !== '') {
        $where[] = 'warehouse_key = ?';
        $args[] = $warehouse['warehouse_key'];
    }
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        (array)($filters['offer_ids'] ?? [])
    ))));
    if ($offerIds) {
        $placeholders = implode(', ', array_fill(0, count($offerIds), '?'));
        $where[] = "offer_id IN ({$placeholders})";
        array_push($args, ...$offerIds);
    }
    if (!empty($filters['only_with_stock'])) {
        $where[] = $marketplace === 'wb' || $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
            ? 'fbo_present > 0'
            : '(fbo_present > 0 OR fbo_reserved > 0)';
    }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(offer_id LIKE ? OR name LIKE ?)';
        $like = '%' . $q . '%';
        $args[] = $like;
        $args[] = $like;
    }
    $priceExpr = 'COALESCE(NULLIF(price, 0), NULLIF(marketing_seller_price, 0), NULLIF(min_price, 0))';
    if (array_key_exists('price_min', $filters) && $filters['price_min'] !== null && $filters['price_min'] !== '') {
        $where[] = "{$priceExpr} >= ?";
        $args[] = (float)$filters['price_min'];
    }
    if (array_key_exists('price_max', $filters) && $filters['price_max'] !== null && $filters['price_max'] !== '') {
        $where[] = "{$priceExpr} <= ?";
        $args[] = (float)$filters['price_max'];
    }

    $sortKey = trim((string)($filters['sort'] ?? 'present'));
    $sortDir = strtolower(trim((string)($filters['dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'offer' => 'offer_id',
        'name' => 'name',
        'present' => 'fbo_present',
        'reserved' => 'fbo_reserved',
        'price' => 'price',
        'min_price' => 'min_price',
        'index' => 'price_index_value',
        'days_without_sales' => 'days_without_sales',
    ];
    $orderBy = $sortMap[$sortKey] ?? 'fbo_present';
    $secondary = $orderBy === 'offer_id' ? 'fbo_present DESC' : 'offer_id ASC';

    $limit = max(1, min(5000, (int)($filters['limit'] ?? 200)));
    $sql = "
        SELECT *
        FROM feedtools_ozon_fbo_items
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$orderBy} {$sortDir}, {$secondary}
        LIMIT {$limit}
    ";
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function ozon_fbo_tool_row_sale_price(array $row): ?float
{
    foreach (['price', 'min_price', 'marketing_seller_price'] as $key) {
        if (isset($row[$key]) && (float)$row[$key] > 0) {
            return round((float)$row[$key], 2);
        }
    }
    return null;
}

function ozon_fbo_tool_row_units(array $row): int
{
    $present = max(0, (int)($row['fbo_present'] ?? 0));
    $marketplace = strtolower(trim((string)($row['marketplace'] ?? '')));
    $warehouseKey = trim((string)($row['warehouse_key'] ?? ''));
    if ($marketplace === 'wb' || $warehouseKey === OZON_FBO_TOOL_WB_WAREHOUSE_KEY) {
        return $present;
    }
    return $present + max(0, (int)($row['fbo_reserved'] ?? 0));
}

function ozon_fbo_tool_json_array($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ozon_fbo_tool_display_warnings(array $warnings, int $limit = 2): string
{
    $out = [];
    $seen = [];
    foreach ($warnings as $warning) {
        $warning = trim((string)$warning);
        if ($warning === '') {
            continue;
        }

        $warningLower = mb_strtolower($warning, 'UTF-8');
        if (str_contains($warningLower, 'переход на следующий уровень индекса требует снизить min price')) {
            continue;
        }
        if (str_contains($warningLower, 'для перехода на следующий уровень индекса снижать min price не нужно')) {
            continue;
        }
        if (str_contains($warningLower, 'быстрый режим: xml-фид price tool не скачивался')) {
            continue;
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', rtrim($warning, " .\t\n\r\0\x0B"))), 'UTF-8');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $warning;
        if (count($out) >= $limit) {
            break;
        }
    }
    return implode(' · ', $out);
}

function ozon_fbo_tool_sale_warning(array $row): array
{
    $info = ozon_fbo_tool_json_array($row['raw_info_json'] ?? null);
    if (!$info) {
        return [];
    }

    $statuses = is_array($info['statuses'] ?? null) ? (array)$info['statuses'] : [];
    $visibility = is_array($info['visibility_details'] ?? null) ? (array)$info['visibility_details'] : [];

    $label = '';

    if (!empty($info['is_archived'])) {
        $label = 'в архиве';
    }

    $statusName = trim((string)($statuses['status_name'] ?? ''));
    $statusDescription = trim((string)($statuses['status_description'] ?? ''));

    if ($statusName !== '') {
        $statusNameLower = mb_strtolower($statusName, 'UTF-8');
        if ($statusNameLower === 'продается' || $statusNameLower === 'продаётся') {
            return [];
        }
        $label = $statusNameLower === 'не продается' || $statusNameLower === 'не продаётся'
            ? 'не продаётся'
            : $statusName;
    }

    if ($label === '') {
        $moderateStatus = mb_strtolower(trim((string)($statuses['moderate_status'] ?? '')), 'UTF-8');
        $status = mb_strtolower(trim((string)($statuses['status'] ?? '')), 'UTF-8');
        if ($moderateStatus === 'declined' || $status === 'declined') {
            $label = 'не продаётся';
        }
    }

    foreach ($visibility as $key => $value) {
        if ($value === false || $value === 0 || $value === 'false') {
            if ($label === '') {
                $label = $key === 'has_price' ? 'нет цены' : ($key === 'has_stock' ? 'нет остатка' : 'скрыт');
            }
            break;
        }
    }

    if ($label === '') {
        return [];
    }

    $message = $statusDescription !== '' ? $statusDescription : $statusName;
    return [
        'label' => $label,
        'message' => $message,
    ];
}

function ozon_fbo_tool_feed_find_offers(array $feed, array $offerIds, bool $allowDownload = true): array
{
    $targets = [];
    $supplierCode = (string)($feed['supplier_code'] ?? '');
    foreach ($offerIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId !== '') {
            $targets[$offerId] = true;
        }
    }
    if (!$targets) {
        return [];
    }

    $targetKeys = array_keys($targets);
    sort($targetKeys, SORT_NATURAL);
    $cacheKey = 'fbo_feed_find_' . sha1(json_encode([
        'v' => 2,
        'feed_id' => (int)($feed['id'] ?? 0),
        'url' => (string)($feed['feed_url'] ?? ''),
        'cost_tag' => (string)($feed['cost_tag'] ?? ''),
        'supplier_code' => $supplierCode,
        'offers' => $targetKeys,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = ozon_price_tool_cache_read($cacheKey, 6 * 60 * 60);
    if (is_array($cached)) {
        $out = [];
        foreach ($cached as $offerId => $offer) {
            if (is_array($offer)) {
                $out[(string)$offerId] = $offer;
            }
        }
        return $out;
    }
    if (!$allowDownload) {
        throw new RuntimeException('Быстрый режим: XML-фид Price Tool не скачивался при открытии страницы, используются данные Ozon и кеш.');
    }

    $download = ozon_price_feed_fetch_remote_xml((string)($feed['feed_url'] ?? ''));
    $path = (string)($download['path'] ?? '');
    try {
        $costTag = trim((string)($feed['cost_tag'] ?? ''));
        if ($costTag === '') {
            throw new RuntimeException('В выбранном профиле Price Tool не указан тег закупочной цены.');
        }

        $reader = new XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Не удалось открыть XML-фид профиля Price Tool.');
        }

        $found = [];
        $bundleTargetsByBaseOffer = [];
        foreach (array_keys($targets) as $targetOfferId) {
            $bundle = bundle_offer_parse($targetOfferId);
            if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
                continue;
            }
            $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
            if ($baseOfferId !== '') {
                $bundleTargetsByBaseOffer[$baseOfferId][] = $targetOfferId;
            }
        }
        $categoryMap = [];
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->name === 'category') {
                $categoryId = trim((string)$reader->getAttribute('id'));
                if ($categoryId !== '') {
                    $categoryMap[$categoryId] = [
                        'id' => $categoryId,
                        'name' => trim((string)$reader->readString()),
                        'parentId' => trim((string)$reader->getAttribute('parentId')),
                    ];
                }
                continue;
            }
            if ($reader->name !== 'offer') {
                continue;
            }

            $offer = ozon_price_parse_offer_node($reader, $costTag, $categoryMap);
            $candidateOfferId = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
            if ($candidateOfferId === '') {
                continue;
            }
            $offer['offer_id'] = $candidateOfferId;

            if (!empty($targets[$candidateOfferId])) {
                $found[$candidateOfferId] = $offer;
            }
            foreach ((array)($bundleTargetsByBaseOffer[$candidateOfferId] ?? []) as $bundleOfferId) {
                if (isset($found[$bundleOfferId])) {
                    continue;
                }
                $derivedOffer = ozon_price_bundle_derive_offer($offer, (string)$bundleOfferId);
                if (is_array($derivedOffer)) {
                    $found[(string)$bundleOfferId] = $derivedOffer;
                }
            }
            if (count($found) >= count($targets)) {
                break;
            }
        }
        $reader->close();
        ozon_price_tool_cache_write($cacheKey, $found);
        return $found;
    } finally {
        if ($path !== '') {
            @unlink($path);
        }
    }
}

function ozon_fbo_tool_price_enrich_cache_fields(): array
{
    return [
        '_cost_unit_price',
        '_cost_total',
        '_cost_estimated',
        '_cost_source',
        '_price_tool_status',
        '_price_tool_warning',
        '_optimal_price',
        '_index_target_price',
        '_index_target_label',
        '_elastic_price',
        '_elastic_label',
    ];
}

function ozon_fbo_tool_price_enrich_cache_key(int $connectionId, string $offerId, array $row, array $settings, array $offer, array $promoRows, array $rule = []): string
{
    $fingerprint = [
        'v' => 5,
        'connection_id' => $connectionId,
        'offer_id' => $offerId,
        'settings' => sha1(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'offer' => sha1(json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'promo' => sha1(json_encode($promoRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'fbo_rule' => [
            'target_price' => (string)($rule['target_price'] ?? ''),
            'pricing_mode' => (string)($rule['pricing_mode'] ?? ''),
            'status' => (string)($rule['status'] ?? ''),
            'updated_at' => (string)($rule['updated_at'] ?? ''),
        ],
        'price' => [
            'price' => (string)($row['price'] ?? ''),
            'marketing_seller_price' => (string)($row['marketing_seller_price'] ?? ''),
            'min_price' => (string)($row['min_price'] ?? ''),
            'old_price' => (string)($row['old_price'] ?? ''),
            'color_index' => (string)($row['color_index'] ?? ''),
            'price_index_value' => (string)($row['price_index_value'] ?? ''),
            'price_index_source' => (string)($row['price_index_source'] ?? ''),
            'raw_price_hash' => sha1((string)($row['raw_price_json'] ?? '')),
        ],
    ];

    return 'fbo_price_enrich_' . sha1(json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ozon_fbo_tool_apply_price_enrich_cache(array &$row, array $cached): void
{
    foreach (ozon_fbo_tool_price_enrich_cache_fields() as $field) {
        if (array_key_exists($field, $cached)) {
            $row[$field] = $cached[$field];
        }
    }
}

function ozon_fbo_tool_extract_price_enrich_cache(array $row): array
{
    $payload = [];
    foreach (ozon_fbo_tool_price_enrich_cache_fields() as $field) {
        if (array_key_exists($field, $row)) {
            $payload[$field] = $row[$field];
        }
    }
    return $payload;
}

function ozon_fbo_tool_apply_ozon_price_fallback(array &$row): void
{
    $priceItem = ozon_fbo_tool_json_array($row['raw_price_json'] ?? null);
    if (!$priceItem) {
        return;
    }

    $price = ozon_fbo_tool_price_value($priceItem, ['price.price', 'price']);
    $minPrice = ozon_fbo_tool_price_value($priceItem, ['price.min_price', 'min_price']);
    $marketingPrice = ozon_fbo_tool_price_value($priceItem, ['price.marketing_seller_price', 'marketing_seller_price']);
    $priceIndexes = is_array($priceItem['price_indexes'] ?? null) ? (array)$priceItem['price_indexes'] : [];

    if (empty($row['_index_target_price']) && $priceIndexes) {
        $referencePrice = $minPrice !== null && $minPrice > 0
            ? $minPrice
            : ($marketingPrice !== null && $marketingPrice > 0 ? $marketingPrice : ($price ?? 0.0));
        if ($minPrice !== null && $minPrice > 0 && $referencePrice > 0) {
            $transition = ozon_price_next_index_transition_target($minPrice, $referencePrice, $priceIndexes);
            if (is_array($transition) && !empty($transition['target_price'])) {
                $row['_index_target_price'] = round((float)$transition['target_price'], 2);
                $row['_index_target_label'] = trim((string)($transition['next_level_label'] ?? ''));
            }
        }
    }

    if (empty($row['_elastic_price'])) {
        $actions = is_array($priceItem['marketing_actions']['actions'] ?? null)
            ? (array)$priceItem['marketing_actions']['actions']
            : [];
        $best = null;
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $title = mb_strtolower((string)($action['title'] ?? ''), 'UTF-8');
            if (!str_contains($title, 'эластичный бустинг')) {
                continue;
            }
            $value = ozon_fbo_tool_to_float($action['value'] ?? null);
            if ($value !== null && $value > 0 && ($best === null || $value < $best)) {
                $best = $value;
            }
        }
        if ($best !== null) {
            $row['_elastic_price'] = round($best, 2);
            $row['_elastic_label'] = 'из Ozon';
        }
    }
}

function ozon_fbo_tool_enrich_rows_for_price_tool(int $connectionId, array $rows, ?array $feed, array $cfg = [], bool $allowFeedDownload = true, array $options = []): array
{
    if (!$rows) {
        return [];
    }

    $offerIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['offer_id'] ?? '')),
        $rows
    ))));
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $marketplace = is_array($connection) ? (string)($connection['marketplace'] ?? 'ozon') : 'ozon';
    $runtimeCfg = is_array($connection) ? ozon_price_cfg_with_connection($cfg, $connection) : $cfg;
    $wbRuntime = null;
    $wbGoodsIndex = null;
    $rulesByOffer = ozon_fbo_tool_price_rules_by_offer($connectionId, $offerIds, $cfg, $options);
    $offersById = [];
    $settingsByOffer = [];
    $promoRowsByOffer = [];
    $feedErrors = [];
    $feedList = [];
    if (is_array($feed) && !empty($feed['__all_feeds']) && is_array($feed['__all_feeds'])) {
        foreach ($feed['__all_feeds'] as $candidateFeed) {
            if (is_array($candidateFeed) && (int)($candidateFeed['id'] ?? 0) > 0) {
                $feedList[] = $candidateFeed;
            }
        }
    } elseif (is_array($feed) && (int)($feed['id'] ?? 0) > 0) {
        $feedList[] = $feed;
    }

    if ($feedList) {
        foreach ($feedList as $feedRow) {
            $missingOfferIds = array_values(array_filter($offerIds, static fn(string $offerId): bool => !isset($offersById[$offerId])));
            if (!$missingOfferIds) {
                break;
            }
            try {
                $foundOffers = ozon_fbo_tool_feed_find_offers($feedRow, $missingOfferIds, $allowFeedDownload);
                foreach ($foundOffers as $foundOfferId => $foundOffer) {
                    $foundOfferId = trim((string)$foundOfferId);
                    if ($foundOfferId === '' || isset($offersById[$foundOfferId])) {
                        continue;
                    }
                    $offersById[$foundOfferId] = $foundOffer;
                    $settingsByOffer[$foundOfferId] = $feedRow;
                }
            } catch (Throwable $e) {
                $feedName = trim((string)($feedRow['name'] ?? ''));
                $message = $e->getMessage();
                if (str_contains(mb_strtolower($message, 'UTF-8'), 'быстрый режим: xml-фид price tool не скачивался')) {
                    continue;
                }
                $feedErrors[] = ($feedName !== '' ? $feedName . ': ' : '') . $message;
            }
        }

        $productIdsByOffer = [];
        foreach ($rows as $row) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            $productId = (int)($row['product_id'] ?? 0);
            if ($offerId !== '' && $productId > 0) {
                $productIdsByOffer[$offerId] = $productId;
            }
        }
        $promoRowsByOffer = $marketplace === 'ozon'
            ? ozon_actions_rows_for_offers_or_products($connectionId, $offerIds, $productIdsByOffer, $cfg)
            : [];
    }

    foreach ($rows as &$row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        $units = ozon_fbo_tool_row_units($row);
        $saleUnit = ozon_fbo_tool_row_sale_price($row);
        $row['_fbo_units'] = $units;
        $row['_sale_unit_price'] = $saleUnit;
        $row['_sale_total'] = $saleUnit !== null ? round($saleUnit * $units, 2) : null;
        $row['_sale_price_source'] = $saleUnit !== null && (float)($row['price'] ?? 0) > 0
            ? 'установленная цена'
            : 'резервная цена';
        $row['_cost_unit_price'] = $saleUnit !== null ? round($saleUnit * OZON_FBO_TOOL_APPROX_COST_RATIO, 2) : null;
        $row['_cost_total'] = $row['_cost_unit_price'] !== null ? round((float)$row['_cost_unit_price'] * $units, 2) : null;
        $row['_cost_estimated'] = true;
        $row['_cost_source'] = 'оценка 60% от цены продажи';
        $row['_price_tool_status'] = '';
        $row['_price_tool_warning'] = '';
        $row['_optimal_price'] = null;
        $row['_index_target_price'] = null;
        $row['_index_target_label'] = '';
        $row['_elastic_price'] = null;
        $row['_elastic_label'] = '';
        $rule = is_array($rulesByOffer[$offerId] ?? null) ? (array)$rulesByOffer[$offerId] : null;
        $ruleState = ozon_fbo_tool_rule_state($rule, $units);
        $row['_fbo_price_rule'] = $rule;
        $row['_fbo_price_rule_state'] = $ruleState;
        $row['_discount_price'] = is_array($rule) && (float)($rule['target_price'] ?? 0) > 0
            ? round((float)$rule['target_price'], 2)
            : null;
        $row['_discount_days_active'] = $ruleState['days_active'];
        $row['_discount_is_active'] = !empty($ruleState['is_active']);
        ozon_fbo_tool_apply_ozon_price_fallback($row);

        if ($feedErrors && !$offersById) {
            $row['_price_tool_status'] = 'warn';
            $row['_price_tool_warning'] = implode(' · ', array_slice($feedErrors, 0, 2));
            continue;
        }
        if (!$feedList) {
            $row['_price_tool_status'] = 'warn';
            $row['_price_tool_warning'] = 'Для расчёта Price Tool нужен хотя бы один профиль фида.';
            continue;
        }
        if ($offerId === '' || empty($offersById[$offerId])) {
            if (!$allowFeedDownload) {
                $row['_price_tool_status'] = 'cache';
                $row['_price_tool_warning'] = '';
                continue;
            }
            $row['_price_tool_status'] = 'warn';
            $row['_price_tool_warning'] = count($feedList) > 1
                ? 'Товар не найден ни в одном профиле Price Tool, себестоимость оценочная.'
                : 'Товар не найден в выбранном фиде Price Tool, себестоимость оценочная.';
            continue;
        }

        $settings = is_array($settingsByOffer[$offerId] ?? null) ? (array)$settingsByOffer[$offerId] : (array)$feedList[0];
        $settings['fulfillment_scheme'] = 'fbo';
        $offer = (array)$offersById[$offerId];
        $promoRows = (array)($promoRowsByOffer[$offerId] ?? []);
        $priceEnrichCacheKey = ozon_fbo_tool_price_enrich_cache_key($connectionId, $offerId, $row, $settings, $offer, $promoRows, $rule ?? []);
        $priceEnrichCache = ozon_price_tool_cache_read($priceEnrichCacheKey, 1800);
        if (is_array($priceEnrichCache)) {
            ozon_fbo_tool_apply_price_enrich_cache($row, $priceEnrichCache);
            ozon_fbo_tool_apply_ozon_price_fallback($row);
            continue;
        }

        $purchase = isset($offer['purchase_cost']) ? (float)$offer['purchase_cost'] : 0.0;
        if ($purchase > 0) {
            $row['_cost_unit_price'] = round($purchase, 2);
            $row['_cost_total'] = round($purchase * $units, 2);
            $row['_cost_estimated'] = false;
            $row['_cost_source'] = 'закупочная цена из фида';
        }

        if ($marketplace === 'wb') {
            $settings['connection_id'] = $connectionId;
            $good = ozon_fbo_tool_json_array($row['raw_price_json'] ?? null);
            if (!$good && is_array($connection)) {
                try {
                    if ($wbGoodsIndex === null) {
                        $wbGoodsIndex = wb_price_tool_fetch_all_goods($runtimeCfg, $connection, false, 300);
                    }
                    $good = ozon_fbo_tool_wb_current_good_for_offer_id($offerId, (int)($row['product_id'] ?? 0), $wbGoodsIndex) ?? [];
                } catch (Throwable $wbGoodError) {
                    $good = [];
                }
            }
            try {
                if ($wbRuntime === null && is_array($connection)) {
                    $wbRuntime = wb_price_tool_runtime_context($runtimeCfg, $connection, false, true);
                }
                $calc = wb_price_tool_calculate_offer($settings, $offer, $good ?: null, $wbRuntime);
                $row['_price_tool_status'] = (string)($calc['status'] ?? '');
                $warnings = array_values(array_filter((array)($calc['warnings'] ?? [])));
                $row['_price_tool_warning'] = ozon_fbo_tool_display_warnings($warnings, 2);
                $row['_optimal_price'] = isset($calc['recommended_effective_sale_price'])
                    ? round((float)$calc['recommended_effective_sale_price'], 2)
                    : null;
                if (!empty($calc['recommended_min_effective_sale_price'])) {
                    $row['_index_target_price'] = round((float)$calc['recommended_min_effective_sale_price'], 2);
                    $row['_index_target_label'] = 'min price';
                }
                if (!empty($calc['desired_state']['fbo_rule'])) {
                    $row['_elastic_price'] = isset($calc['desired_state']['target_effective_sale_price'])
                        ? round((float)$calc['desired_state']['target_effective_sale_price'], 2)
                        : null;
                    $row['_elastic_label'] = 'WB FBO';
                }
                ozon_price_tool_cache_write($priceEnrichCacheKey, ozon_fbo_tool_extract_price_enrich_cache($row));
            } catch (Throwable $wbCalcError) {
                $row['_price_tool_status'] = 'warn';
                $row['_price_tool_warning'] = $wbCalcError->getMessage();
            }
            continue;
        }

        $ozonItem = ozon_fbo_tool_json_array($row['raw_price_json'] ?? null);
        if (!$ozonItem && $offerId !== '') {
            $ozonItem = [
                'offer_id' => $offerId,
                'product_id' => (int)($row['product_id'] ?? 0),
                'price' => [
                    'price' => (string)($row['price'] ?? ''),
                    'min_price' => (string)($row['min_price'] ?? ''),
                    'marketing_seller_price' => (string)($row['marketing_seller_price'] ?? ''),
                ],
            ];
        }

        $calc = ozon_price_calculate_offer($settings, $offer, $ozonItem);
        $strategy = ozon_price_build_promotion_strategy($calc, $promoRows, $settings);
        $row['_price_tool_status'] = (string)($calc['status'] ?? '');
        $warnings = array_values(array_filter((array)($calc['warnings'] ?? [])));
        $row['_price_tool_warning'] = ozon_fbo_tool_display_warnings($warnings, 2);
        $row['_optimal_price'] = isset($strategy['final_price'])
            ? round((float)$strategy['final_price'], 2)
            : (isset($calc['recommended_price']) ? round((float)$calc['recommended_price'], 2) : null);

        $breakdown = (array)($calc['breakdown'] ?? []);
        if (!empty($breakdown['price_index_transition_target_price'])) {
            $row['_index_target_price'] = round((float)$breakdown['price_index_transition_target_price'], 2);
            $row['_index_target_label'] = trim((string)($breakdown['price_index_next_level'] ?? ''));
        } elseif (!empty($strategy['index_strategy']['best_candidate']['price'])) {
            $row['_index_target_price'] = round((float)$strategy['index_strategy']['best_candidate']['price'], 2);
            $row['_index_target_label'] = trim((string)($strategy['index_strategy']['best_candidate']['index']['label'] ?? ''));
        }

        $elasticPlans = [];
        foreach ((array)($strategy['actions'] ?? []) as $actionPlan) {
            $title = mb_strtolower((string)($actionPlan['title'] ?? ''));
            if (str_contains($title, 'эластичный бустинг')) {
                $elasticPlans[] = $actionPlan;
            }
        }
        if (!$elasticPlans) {
            foreach ((array)($strategy['all_actions'] ?? []) as $actionPlan) {
                $title = mb_strtolower((string)($actionPlan['title'] ?? ''));
                if (str_contains($title, 'эластичный бустинг')) {
                    $elasticPlans[] = $actionPlan;
                }
            }
        }
        if ($elasticPlans) {
            usort($elasticPlans, static function (array $a, array $b): int {
                $boostCmp = ((float)($b['recommended_action_boost'] ?? 0)) <=> ((float)($a['recommended_action_boost'] ?? 0));
                if ($boostCmp !== 0) {
                    return $boostCmp;
                }
                return ((float)($b['recommended_action_price'] ?? 0)) <=> ((float)($a['recommended_action_price'] ?? 0));
            });
            $bestElastic = $elasticPlans[0];
            $row['_elastic_price'] = round((float)($bestElastic['recommended_action_price'] ?? 0), 2);
            $row['_elastic_label'] = 'буст ' . round((float)($bestElastic['recommended_action_boost'] ?? 0), 2) . '%';
        }
        ozon_price_tool_cache_write($priceEnrichCacheKey, ozon_fbo_tool_extract_price_enrich_cache($row));
    }
    unset($row);

    return $rows;
}

function ozon_fbo_tool_valuation_summary(array $rows): array
{
    $summary = [
        'units' => 0,
        'sale_total' => 0.0,
        'cost_total' => 0.0,
        'exact_cost_items' => 0,
        'estimated_cost_items' => 0,
        'items_with_price' => 0,
    ];
    foreach ($rows as $row) {
        $units = (int)($row['_fbo_units'] ?? ozon_fbo_tool_row_units($row));
        if ($units <= 0) {
            continue;
        }
        $summary['units'] += $units;
        if (isset($row['_sale_total']) && (float)$row['_sale_total'] > 0) {
            $summary['sale_total'] += (float)$row['_sale_total'];
            $summary['items_with_price']++;
        }
        if (isset($row['_cost_total']) && (float)$row['_cost_total'] > 0) {
            $summary['cost_total'] += (float)$row['_cost_total'];
            if (!empty($row['_cost_estimated'])) {
                $summary['estimated_cost_items']++;
            } else {
                $summary['exact_cost_items']++;
            }
        }
    }
    $summary['sale_total'] = round($summary['sale_total'], 2);
    $summary['cost_total'] = round($summary['cost_total'], 2);
    return $summary;
}

function ozon_fbo_tool_valuation_summary_light(array $rows): array
{
    $summary = [
        'units' => 0,
        'sale_total' => 0.0,
        'cost_total' => 0.0,
        'exact_cost_items' => 0,
        'estimated_cost_items' => 0,
        'items_with_price' => 0,
    ];
    foreach ($rows as $row) {
        $units = ozon_fbo_tool_row_units($row);
        if ($units <= 0) {
            continue;
        }
        $summary['units'] += $units;
        $saleUnit = ozon_fbo_tool_row_sale_price($row);
        if ($saleUnit === null || $saleUnit <= 0) {
            continue;
        }
        $summary['items_with_price']++;
        $summary['sale_total'] += $saleUnit * $units;
        $summary['cost_total'] += ($saleUnit * OZON_FBO_TOOL_APPROX_COST_RATIO) * $units;
        $summary['estimated_cost_items']++;
    }
    $summary['sale_total'] = round($summary['sale_total'], 2);
    $summary['cost_total'] = round($summary['cost_total'], 2);
    return $summary;
}

function ozon_fbo_tool_sort_rows(array $rows, string $sortKey, string $direction): array
{
    $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
    $map = [
        'offer' => ['offer_id', 'string'],
        'name' => ['name', 'string'],
        'present' => ['fbo_present', 'number'],
        'reserved' => ['fbo_reserved', 'number'],
        'price' => ['price', 'number'],
        'min_price' => ['min_price', 'number'],
        'index' => ['price_index_value', 'number'],
        'days_without_sales' => ['days_without_sales', 'number'],
        'optimal' => ['_optimal_price', 'number'],
        'index_target' => ['_index_target_price', 'number'],
        'elastic' => ['_elastic_price', 'number'],
        'discount_price' => ['_discount_price', 'number'],
        'discount_days' => ['_discount_days_active', 'number'],
        'sale_total' => ['_sale_total', 'number'],
        'cost_total' => ['_cost_total', 'number'],
    ];
    if (empty($map[$sortKey])) {
        $sortKey = 'present';
    }
    [$field, $type] = $map[$sortKey];
    usort($rows, static function (array $a, array $b) use ($field, $type, $direction): int {
        if ($type === 'string') {
            $cmp = strnatcasecmp((string)($a[$field] ?? ''), (string)($b[$field] ?? ''));
        } else {
            $av = isset($a[$field]) && $a[$field] !== '' ? (float)$a[$field] : -INF;
            $bv = isset($b[$field]) && $b[$field] !== '' ? (float)$b[$field] : -INF;
            $cmp = $av <=> $bv;
        }
        if ($cmp === 0) {
            $cmp = strnatcasecmp((string)($a['offer_id'] ?? ''), (string)($b['offer_id'] ?? ''));
        }
        return $direction === 'asc' ? $cmp : -$cmp;
    });
    return $rows;
}

function ozon_fbo_tool_summary(int $connectionId, array $cfg = [], array $options = []): array
{
    ozon_fbo_tool_tables_ensure($cfg);
    $where = ['connection_id = ?'];
    $args = [$connectionId];
    $warehouse = ozon_fbo_tool_options_warehouse($options);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $marketplace = is_array($connection) ? (string)($connection['marketplace'] ?? 'ozon') : 'ozon';
    $activeItemsExpr = $marketplace === 'wb' || $warehouse['warehouse_key'] === OZON_FBO_TOOL_WB_WAREHOUSE_KEY
        ? 'CASE WHEN fbo_present > 0 THEN 1 ELSE 0 END'
        : 'CASE WHEN fbo_present > 0 OR fbo_reserved > 0 THEN 1 ELSE 0 END';
    if ($warehouse['warehouse_key'] !== '') {
        $where[] = 'warehouse_key = ?';
        $args[] = $warehouse['warehouse_key'];
    }
    $st = db()->prepare("
        SELECT
            COUNT(*) AS cached_items,
            COALESCE(SUM({$activeItemsExpr}), 0) AS active_items,
            COALESCE(SUM(fbo_present), 0) AS fbo_present,
            COALESCE(SUM(fbo_reserved), 0) AS fbo_reserved,
            MAX(last_refreshed_at) AS last_refreshed_at
        FROM feedtools_ozon_fbo_items
        WHERE " . implode(' AND ', $where) . "
    ");
    $st->execute($args);
    $row = $st->fetch();
    return is_array($row) ? $row : [
        'cached_items' => 0,
        'active_items' => 0,
        'fbo_present' => 0,
        'fbo_reserved' => 0,
        'last_refreshed_at' => null,
    ];
}
