<?php
declare(strict_types=1);

require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/ozon_actions.php';
require_once __DIR__ . '/stocks_tool.php';

function stock_tool_cfg_fallback(array $cfg = []): array
{
    if ($cfg) {
        return $cfg;
    }
    return ozon_price_cfg_fallback($cfg);
}

function stock_tool_tables_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = stock_tool_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    ozon_price_feeds_table_ensure($cfg);
    ozon_actions_tables_ensure();
    stocks_tool_profiles_table_ensure($cfg);

    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_stock_tool_settings (
            connection_id BIGINT UNSIGNED NOT NULL,
            ozon_warehouse_key VARCHAR(128) NOT NULL DEFAULT '',
            ozon_warehouse_id VARCHAR(64) NOT NULL DEFAULT '',
            ozon_warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            default_price_feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (connection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_stock_tool_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            offer_id VARCHAR(190) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name TEXT NULL,
            price_feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target_stock_qty INT NOT NULL DEFAULT 0,
            price_mode VARCHAR(16) NOT NULL DEFAULT 'exact',
            discount_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            regular_price_snapshot DECIMAL(14,2) NULL DEFAULT NULL,
            regular_min_price_snapshot DECIMAL(14,2) NULL DEFAULT NULL,
            current_price DECIMAL(14,2) NULL DEFAULT NULL,
            current_min_price DECIMAL(14,2) NULL DEFAULT NULL,
            current_marketing_price DECIMAL(14,2) NULL DEFAULT NULL,
            current_price_index_value DECIMAL(10,4) NULL DEFAULT NULL,
            current_price_index_level VARCHAR(32) NOT NULL DEFAULT '',
            current_price_index_level_label VARCHAR(64) NOT NULL DEFAULT '',
            current_price_index_source VARCHAR(32) NOT NULL DEFAULT '',
            last_known_stock_qty INT NOT NULL DEFAULT 0,
            last_known_reserved_qty INT NOT NULL DEFAULT 0,
            stock_push_required TINYINT(1) NOT NULL DEFAULT 1,
            price_push_required TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            last_stock_pushed_at DATETIME NULL DEFAULT NULL,
            discount_started_at DATETIME NULL DEFAULT NULL,
            sold_at DATETIME NULL DEFAULT NULL,
            restored_at DATETIME NULL DEFAULT NULL,
            last_sync_at DATETIME NULL DEFAULT NULL,
            last_error TEXT NULL,
            action_ids_json LONGTEXT NULL,
            last_result_json LONGTEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_offer (connection_id, offer_id),
            KEY idx_connection_status (connection_id, status, updated_at),
            KEY idx_connection_updated (connection_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'price_mode',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN price_mode VARCHAR(16) NOT NULL DEFAULT 'exact' AFTER target_stock_qty"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'discount_amount',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_price"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'current_price_index_value',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN current_price_index_value DECIMAL(10,4) NULL DEFAULT NULL AFTER current_marketing_price"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'current_price_index_level',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN current_price_index_level VARCHAR(32) NOT NULL DEFAULT '' AFTER current_price_index_value"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'current_price_index_level_label',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN current_price_index_level_label VARCHAR(64) NOT NULL DEFAULT '' AFTER current_price_index_level"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'current_price_index_source',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN current_price_index_source VARCHAR(32) NOT NULL DEFAULT '' AFTER current_price_index_level_label"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_stock_tool_items',
        'action_ids_json',
        "ALTER TABLE feedtools_stock_tool_items ADD COLUMN action_ids_json LONGTEXT NULL AFTER last_error"
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_stock_tool_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor VARCHAR(190) NULL DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'running',
            totals_json LONGTEXT NULL,
            log_text LONGTEXT NULL,
            error_text TEXT NULL,
            started_at DATETIME NULL DEFAULT NULL,
            finished_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_connection_created (connection_id, id),
            KEY idx_status_created (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function stock_tool_module_bootstrap(array $cfg = []): void
{
    stock_tool_tables_ensure($cfg);
}

function stock_tool_settings_default(int $connectionId = 0): array
{
    return [
        'connection_id' => max(0, $connectionId),
        'ozon_warehouse_key' => '',
        'ozon_warehouse_id' => '',
        'ozon_warehouse_name' => '',
        'default_price_feed_id' => 0,
        'is_enabled' => 1,
        'notes' => '',
    ];
}

function stock_tool_settings_get(int $connectionId, array $cfg = []): array
{
    stock_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return stock_tool_settings_default();
    }

    $st = db()->prepare("SELECT * FROM feedtools_stock_tool_settings WHERE connection_id = ? LIMIT 1");
    $st->execute([$connectionId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return stock_tool_settings_default($connectionId);
    }

    $row['connection_id'] = (int)($row['connection_id'] ?? $connectionId);
    $row['default_price_feed_id'] = (int)($row['default_price_feed_id'] ?? 0);
    $row['is_enabled'] = !empty($row['is_enabled']) ? 1 : 0;
    return $row + stock_tool_settings_default($connectionId);
}

function stock_tool_settings_save(int $connectionId, array $input, ?string $actor = null, array $cfg = []): void
{
    stock_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        throw new RuntimeException('Не удалось определить подключение Ozon.');
    }

    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? 'ozon') !== 'ozon') {
        throw new RuntimeException('stock pois доступен только для подключения Ozon.');
    }

    $warehouseKey = trim((string)($input['ozon_warehouse_key'] ?? ''));
    $warehouseId = trim((string)($input['ozon_warehouse_id'] ?? ''));
    $warehouseName = trim((string)($input['ozon_warehouse_name'] ?? ''));
    if ($warehouseKey !== '') {
        $options = stocks_tool_warehouse_options(ozon_price_cfg_with_connection($cfg, $connection), $connection);
        $selected = is_array($options[$warehouseKey] ?? null) ? $options[$warehouseKey] : null;
        if (is_array($selected)) {
            $warehouseId = (string)($selected['warehouse_id'] ?? '');
            $warehouseName = (string)($selected['warehouse_name'] ?? '');
        }
    }
    if ($warehouseId === '') {
        throw new RuntimeException('Выбери склад Ozon для stock pois.');
    }
    if ($warehouseKey === '') {
        $warehouseKey = 'id:' . $warehouseId;
    }
    if ($warehouseName === '') {
        $warehouseName = $warehouseId;
    }

    $defaultPriceFeedId = max(0, (int)($input['default_price_feed_id'] ?? 0));
    if ($defaultPriceFeedId > 0 && !ozon_price_feed_get($defaultPriceFeedId, $connectionId, $cfg)) {
        throw new RuntimeException('Выбранный профиль Price Tool не найден.');
    }

    $st = db()->prepare("
        INSERT INTO feedtools_stock_tool_settings (
            connection_id, ozon_warehouse_key, ozon_warehouse_id, ozon_warehouse_name,
            default_price_feed_id, is_enabled, notes, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ozon_warehouse_key = VALUES(ozon_warehouse_key),
            ozon_warehouse_id = VALUES(ozon_warehouse_id),
            ozon_warehouse_name = VALUES(ozon_warehouse_name),
            default_price_feed_id = VALUES(default_price_feed_id),
            is_enabled = VALUES(is_enabled),
            notes = VALUES(notes),
            updated_by = VALUES(updated_by)
    ");
    $st->execute([
        $connectionId,
        $warehouseKey,
        $warehouseId,
        $warehouseName,
        $defaultPriceFeedId,
        !empty($input['is_enabled']) ? 1 : 0,
        trim((string)($input['notes'] ?? '')),
        $actor,
    ]);
}

function stock_tool_price_feeds_for_connection(int $connectionId, array $cfg = []): array
{
    $feeds = ozon_price_feed_list($connectionId, $cfg);
    return array_values(array_filter($feeds, static fn(array $feed): bool => !ozon_price_feed_supplier_is_archived($feed)));
}

function stock_tool_count_by_connection(array $cfg = []): array
{
    stock_tool_tables_ensure($cfg);
    $rows = db()->query("
        SELECT connection_id, COUNT(*) AS qty
        FROM feedtools_stock_tool_items
        WHERE status <> 'paused'
        GROUP BY connection_id
    ")->fetchAll() ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[(int)($row['connection_id'] ?? 0)] = (int)($row['qty'] ?? 0);
    }
    return $out;
}

function stock_tool_normalize_price_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    return in_array($mode, ['exact', 'discount_amount'], true) ? $mode : 'exact';
}

function stock_tool_action_ids_decode($value): array
{
    $decoded = is_array($value) ? $value : json_decode((string)$value, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = [];
    foreach ($decoded as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function stock_tool_action_ids_encode(array $ids): string
{
    $normalized = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }
    return json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function stock_tool_item_hydrate(array $row): array
{
    foreach ([
        'id',
        'connection_id',
        'product_id',
        'price_feed_id',
        'target_stock_qty',
        'last_known_stock_qty',
        'last_known_reserved_qty',
        'stock_push_required',
        'price_push_required',
    ] as $key) {
        $row[$key] = (int)($row[$key] ?? 0);
    }
    foreach ([
        'discount_price',
        'discount_amount',
        'regular_price_snapshot',
        'regular_min_price_snapshot',
        'current_price',
        'current_min_price',
        'current_marketing_price',
    ] as $key) {
        $row[$key] = ($row[$key] === null || $row[$key] === '') ? null : round((float)$row[$key], 2);
    }
    $row['current_price_index_value'] = ($row['current_price_index_value'] ?? null) === null || ($row['current_price_index_value'] ?? '') === ''
        ? null
        : round((float)$row['current_price_index_value'], 4);
    $row['price_mode'] = stock_tool_normalize_price_mode((string)($row['price_mode'] ?? 'exact'));
    $row['action_ids'] = stock_tool_action_ids_decode($row['action_ids_json'] ?? null);
    return $row;
}

function stock_tool_item_default(int $connectionId = 0): array
{
    return [
        'id' => 0,
        'connection_id' => max(0, $connectionId),
        'offer_id' => '',
        'product_id' => 0,
        'name' => '',
        'price_feed_id' => 0,
        'target_stock_qty' => 0,
        'price_mode' => 'exact',
        'discount_price' => 0.0,
        'discount_amount' => 0.0,
        'regular_price_snapshot' => null,
        'regular_min_price_snapshot' => null,
        'current_price' => null,
        'current_min_price' => null,
        'current_marketing_price' => null,
        'current_price_index_value' => null,
        'current_price_index_level' => '',
        'current_price_index_level_label' => '',
        'current_price_index_source' => '',
        'last_known_stock_qty' => 0,
        'last_known_reserved_qty' => 0,
        'stock_push_required' => 1,
        'price_push_required' => 1,
        'status' => 'draft',
        'last_error' => '',
        'action_ids' => [],
    ];
}

function stock_tool_item_get(int $id, ?int $connectionId = null, array $cfg = []): ?array
{
    stock_tool_tables_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    if (($connectionId ?? 0) > 0) {
        $st = db()->prepare("SELECT * FROM feedtools_stock_tool_items WHERE id = ? AND connection_id = ? LIMIT 1");
        $st->execute([$id, $connectionId]);
    } else {
        $st = db()->prepare("SELECT * FROM feedtools_stock_tool_items WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }
    $row = $st->fetch();
    return is_array($row) ? stock_tool_item_hydrate($row) : null;
}

function stock_tool_item_by_offer(int $connectionId, string $offerId, array $cfg = []): ?array
{
    stock_tool_tables_ensure($cfg);
    $offerId = trim($offerId);
    if ($connectionId <= 0 || $offerId === '') {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_stock_tool_items WHERE connection_id = ? AND offer_id = ? LIMIT 1");
    $st->execute([$connectionId, $offerId]);
    $row = $st->fetch();
    return is_array($row) ? stock_tool_item_hydrate($row) : null;
}

function stock_tool_items_list(int $connectionId, array $cfg = []): array
{
    stock_tool_tables_ensure($cfg);
    if ($connectionId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_stock_tool_items
        WHERE connection_id = ?
        ORDER BY
            FIELD(status, 'error', 'ready', 'active', 'sold', 'draft', 'restored', 'paused'),
            updated_at DESC,
            id DESC
    ");
    $st->execute([$connectionId]);
    return array_map('stock_tool_item_hydrate', $st->fetchAll() ?: []);
}

function stock_tool_money_from_input($value): float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0.0;
    }
    $value = ozon_price_to_float($raw);
    return is_finite($value) ? round(max(0.0, $value), 2) : 0.0;
}

function stock_tool_first_positive_money(...$values): ?float
{
    foreach ($values as $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $num = (float)$value;
        if (is_finite($num) && $num > 0) {
            return round($num, 2);
        }
    }
    return null;
}

function stock_tool_price_mode_input(array $input, ?array $existing = null): string
{
    if (array_key_exists('price_mode', $input)) {
        return stock_tool_normalize_price_mode((string)$input['price_mode']);
    }
    return stock_tool_normalize_price_mode((string)($existing['price_mode'] ?? 'exact'));
}

function stock_tool_price_value_from_input(array $input, string $mode, ?array $existing = null): float
{
    if (array_key_exists('discount_value', $input)) {
        return stock_tool_money_from_input($input['discount_value']);
    }
    if ($mode === 'discount_amount' && array_key_exists('discount_amount', $input)) {
        return stock_tool_money_from_input($input['discount_amount']);
    }
    if ($mode === 'exact' && array_key_exists('discount_price', $input)) {
        return stock_tool_money_from_input($input['discount_price']);
    }
    return $mode === 'discount_amount'
        ? round((float)($existing['discount_amount'] ?? 0), 2)
        : round((float)($existing['discount_price'] ?? 0), 2);
}

function stock_tool_discount_price_from_amount(float $amount, array $row, ?float $currentPrice = null): float
{
    $basePrice = stock_tool_first_positive_money(
        $row['regular_price_snapshot'] ?? null,
        $currentPrice,
        $row['current_price'] ?? null
    );
    if ($basePrice === null || $amount <= 0) {
        return 0.0;
    }
    return round(max(0.0, $basePrice - $amount), 2);
}

function stock_tool_resolve_price_input(array $input, ?array $existing = null, ?float $currentPrice = null): array
{
    $existing = is_array($existing) ? $existing : [];
    $mode = stock_tool_price_mode_input($input, $existing);
    $value = stock_tool_price_value_from_input($input, $mode, $existing);
    if ($mode === 'discount_amount') {
        $discountAmount = $value;
        $discountPrice = stock_tool_discount_price_from_amount($discountAmount, $existing, $currentPrice);
    } else {
        $discountAmount = 0.0;
        $discountPrice = $value;
    }
    return [
        'price_mode' => $mode,
        'discount_price' => round($discountPrice, 2),
        'discount_amount' => round($discountAmount, 2),
    ];
}

function stock_tool_effective_discount_price(array $item, ?float $currentPrice = null): float
{
    if (stock_tool_normalize_price_mode((string)($item['price_mode'] ?? 'exact')) === 'discount_amount') {
        return stock_tool_discount_price_from_amount((float)($item['discount_amount'] ?? 0), $item, $currentPrice);
    }
    return round(max(0.0, (float)($item['discount_price'] ?? 0)), 2);
}

function stock_tool_price_item_money(array $item, string $field): ?float
{
    $value = $item['price'][$field] ?? $item[$field] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = ozon_price_to_float((string)$value);
    if (!is_finite($parsed) || $parsed <= 0) {
        return null;
    }
    return round($parsed, 2);
}

function stock_tool_price_index_snapshot(array $priceItem): array
{
    $priceIndexes = is_array($priceItem['price_indexes'] ?? null) ? (array)$priceItem['price_indexes'] : [];
    $metric = ozon_price_effective_index_metric($priceIndexes);
    $value = is_array($metric) && (float)($metric['value'] ?? 0) > 0
        ? round((float)$metric['value'], 4)
        : null;
    $level = ozon_price_index_level_from_api_color((string)($priceIndexes['color_index'] ?? ''));
    if ($level === 'without_index' && $value !== null) {
        $level = ozon_price_index_level_from_value((float)$value);
    }
    return [
        'current_price_index_value' => $value,
        'current_price_index_level' => $level,
        'current_price_index_level_label' => ozon_price_index_level_label($level),
        'current_price_index_source' => is_array($metric) ? (string)($metric['source'] ?? '') : '',
        'raw_price_indexes' => $priceIndexes,
    ];
}

function stock_tool_fetch_product_info_map(array $cfg, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(static fn($value): string => trim((string)$value), $offerIds))));
    if (!$offerIds) {
        return [];
    }

    $oz = ozon_cfg_or_fail($cfg);
    $out = [];
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $resp = ozon_post_json($oz, '/v3/product/info/list', ['offer_id' => array_values($chunk)]);
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

function stock_tool_fetch_current_item_data(array $cfg, string $offerId): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        throw new RuntimeException('Укажи артикул Ozon.');
    }

    $priceItems = ozon_price_fetch_price_items($cfg, [$offerId], 100);
    $priceItem = is_array($priceItems[$offerId] ?? null) ? (array)$priceItems[$offerId] : null;
    if (!is_array($priceItem)) {
        throw new RuntimeException('Ozon не вернул текущую цену по этому артикулу.');
    }
    if (!empty($priceItem['__invalid_offer_id'])) {
        throw new RuntimeException((string)($priceItem['__invalid_offer_id_reason'] ?? 'Артикул не подходит для Ozon API.'));
    }
    if (!empty($priceItem['__missing_on_ozon']) || !empty($priceItem['__missing_price_data'])) {
        throw new RuntimeException('Товар не найден в этом кабинете Ozon или Ozon не вернул цену.');
    }

    $infoMap = stock_tool_fetch_product_info_map($cfg, [$offerId]);
    $info = is_array($infoMap[$offerId] ?? null) ? (array)$infoMap[$offerId] : [];
    if (!empty($info['is_archived']) || !empty($priceItem['archived'])) {
        throw new RuntimeException('Товар находится в архиве Ozon.');
    }

    $productId = (int)($priceItem['product_id'] ?? $priceItem['id'] ?? $info['id'] ?? $info['product_id'] ?? 0);
    $name = trim((string)($priceItem['name'] ?? $priceItem['product_name'] ?? $info['name'] ?? ''));
    $indexSnapshot = stock_tool_price_index_snapshot($priceItem);

    return [
        'offer_id' => $offerId,
        'product_id' => $productId,
        'name' => $name,
        'current_price' => stock_tool_price_item_money($priceItem, 'price'),
        'current_min_price' => stock_tool_price_item_money($priceItem, 'min_price'),
        'current_old_price' => stock_tool_price_item_money($priceItem, 'old_price'),
        'current_marketing_price' => stock_tool_price_item_money($priceItem, 'marketing_seller_price'),
        'current_price_index_value' => $indexSnapshot['current_price_index_value'],
        'current_price_index_level' => $indexSnapshot['current_price_index_level'],
        'current_price_index_level_label' => $indexSnapshot['current_price_index_level_label'],
        'current_price_index_source' => $indexSnapshot['current_price_index_source'],
        'raw_price_item' => $priceItem,
        'raw_info_item' => $info,
    ];
}

function stock_tool_status_after_save(array $row): string
{
    if ((string)($row['status'] ?? '') === 'paused') {
        return 'paused';
    }
    if ((int)($row['target_stock_qty'] ?? 0) <= 0 || stock_tool_effective_discount_price($row) <= 0) {
        return 'draft';
    }
    return 'ready';
}

function stock_tool_item_add(int $connectionId, array $input, ?string $actor = null, array $cfg = []): int
{
    stock_tool_tables_ensure($cfg);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? 'ozon') !== 'ozon') {
        throw new RuntimeException('stock pois доступен только для подключения Ozon.');
    }

    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $offerId = trim((string)($input['offer_id'] ?? ''));
    $current = stock_tool_fetch_current_item_data($runtimeCfg, $offerId);
    $existing = stock_tool_item_by_offer($connectionId, $offerId, $cfg);
    if (is_array($existing)) {
        $input['id'] = (int)$existing['id'];
        return stock_tool_item_save($connectionId, array_merge($existing, $input, [
            'offer_id' => $offerId,
            'product_id' => (int)$current['product_id'],
            'name' => (string)$current['name'],
            'current_price' => $current['current_price'],
            'current_min_price' => $current['current_min_price'],
            'current_marketing_price' => $current['current_marketing_price'],
            'current_price_index_value' => $current['current_price_index_value'],
            'current_price_index_level' => $current['current_price_index_level'],
            'current_price_index_level_label' => $current['current_price_index_level_label'],
            'current_price_index_source' => $current['current_price_index_source'],
        ]), $actor, $cfg);
    }

    $settings = stock_tool_settings_get($connectionId, $cfg);
    $priceFeedId = max(0, (int)($input['price_feed_id'] ?? $settings['default_price_feed_id'] ?? 0));
    if ($priceFeedId > 0 && !ozon_price_feed_get($priceFeedId, $connectionId, $cfg)) {
        throw new RuntimeException('Выбранный профиль Price Tool не найден.');
    }

    $targetQty = max(0, (int)($input['target_stock_qty'] ?? 0));
    $priceInput = stock_tool_resolve_price_input($input, [
        'current_price' => $current['current_price'],
        'current_min_price' => $current['current_min_price'],
        'regular_price_snapshot' => $current['current_price'],
    ], $current['current_price']);
    $discountPrice = (float)$priceInput['discount_price'];
    $status = ($targetQty > 0 && $discountPrice > 0) ? 'ready' : 'draft';
    $regularSnapshot = $priceInput['price_mode'] === 'discount_amount' && (float)($current['current_price'] ?? 0) > 0
        ? (float)$current['current_price']
        : null;
    $regularMinSnapshot = $priceInput['price_mode'] === 'discount_amount' && (float)($current['current_min_price'] ?? 0) > 0
        ? (float)$current['current_min_price']
        : null;

    $st = db()->prepare("
        INSERT INTO feedtools_stock_tool_items (
            connection_id, offer_id, product_id, name, price_feed_id, target_stock_qty,
            price_mode, discount_price, discount_amount, regular_price_snapshot, regular_min_price_snapshot,
            current_price, current_min_price, current_marketing_price,
            current_price_index_value, current_price_index_level, current_price_index_level_label, current_price_index_source,
            stock_push_required, price_push_required, status, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?)
    ");
    $st->execute([
        $connectionId,
        $offerId,
        (int)$current['product_id'],
        (string)$current['name'],
        $priceFeedId,
        $targetQty,
        (string)$priceInput['price_mode'],
        $discountPrice,
        (float)$priceInput['discount_amount'],
        $regularSnapshot,
        $regularMinSnapshot,
        $current['current_price'],
        $current['current_min_price'],
        $current['current_marketing_price'],
        $current['current_price_index_value'],
        $current['current_price_index_level'],
        $current['current_price_index_level_label'],
        $current['current_price_index_source'],
        $status,
        $actor,
        $actor,
    ]);
    return (int)db()->lastInsertId();
}

function stock_tool_item_save(int $connectionId, array $input, ?string $actor = null, array $cfg = []): int
{
    stock_tool_tables_ensure($cfg);
    $id = (int)($input['id'] ?? 0);
    $existing = $id > 0 ? stock_tool_item_get($id, $connectionId, $cfg) : null;
    if ($id <= 0 || !is_array($existing)) {
        throw new RuntimeException('Товар stock pois не найден.');
    }

    $priceFeedId = max(0, (int)($input['price_feed_id'] ?? 0));
    if ($priceFeedId > 0 && !ozon_price_feed_get($priceFeedId, $connectionId, $cfg)) {
        throw new RuntimeException('Выбранный профиль Price Tool не найден.');
    }

    $targetQty = max(0, (int)($input['target_stock_qty'] ?? 0));
    $merged = array_replace($existing, array_intersect_key($input, array_flip([
        'product_id',
        'name',
        'current_price',
        'current_min_price',
        'current_marketing_price',
        'current_price_index_value',
        'current_price_index_level',
        'current_price_index_level_label',
        'current_price_index_source',
    ])));
    if (
        stock_tool_normalize_price_mode((string)($input['price_mode'] ?? $existing['price_mode'] ?? 'exact')) === 'discount_amount'
        && (float)($merged['regular_price_snapshot'] ?? 0) <= 0
        && (float)($merged['current_price'] ?? 0) > 0
    ) {
        $merged['regular_price_snapshot'] = (float)$merged['current_price'];
    }
    if (
        stock_tool_normalize_price_mode((string)($input['price_mode'] ?? $existing['price_mode'] ?? 'exact')) === 'discount_amount'
        && (float)($merged['regular_min_price_snapshot'] ?? 0) <= 0
        && (float)($merged['current_min_price'] ?? 0) > 0
    ) {
        $merged['regular_min_price_snapshot'] = (float)$merged['current_min_price'];
    }
    $priceInput = stock_tool_resolve_price_input($input, $merged, isset($merged['current_price']) ? (float)$merged['current_price'] : null);
    $discountPrice = (float)$priceInput['discount_price'];
    $stockPushRequired = (int)($existing['stock_push_required'] ?? 0);
    $pricePushRequired = (int)($existing['price_push_required'] ?? 0);
    if ($targetQty !== (int)($existing['target_stock_qty'] ?? 0)) {
        $stockPushRequired = 1;
    }
    if (
        abs($discountPrice - (float)($existing['discount_price'] ?? 0)) > 0.01
        || (string)$priceInput['price_mode'] !== (string)($existing['price_mode'] ?? 'exact')
        || abs((float)$priceInput['discount_amount'] - (float)($existing['discount_amount'] ?? 0)) > 0.01
    ) {
        $pricePushRequired = 1;
    }

    $row = $merged;
    $row['target_stock_qty'] = $targetQty;
    $row['price_mode'] = (string)$priceInput['price_mode'];
    $row['discount_price'] = $discountPrice;
    $row['discount_amount'] = (float)$priceInput['discount_amount'];
    $row['price_feed_id'] = $priceFeedId;
    $row['status'] = stock_tool_status_after_save($row);

    $st = db()->prepare("
        UPDATE feedtools_stock_tool_items
        SET price_feed_id = ?,
            target_stock_qty = ?,
            price_mode = ?,
            discount_price = ?,
            discount_amount = ?,
            product_id = ?,
            name = ?,
            regular_price_snapshot = ?,
            regular_min_price_snapshot = ?,
            current_price = ?,
            current_min_price = ?,
            current_marketing_price = ?,
            current_price_index_value = ?,
            current_price_index_level = ?,
            current_price_index_level_label = ?,
            current_price_index_source = ?,
            stock_push_required = ?,
            price_push_required = ?,
            status = ?,
            sold_at = CASE WHEN ? = 'ready' THEN NULL ELSE sold_at END,
            restored_at = CASE WHEN ? = 'ready' THEN NULL ELSE restored_at END,
            last_error = NULL,
            updated_by = ?
        WHERE id = ? AND connection_id = ?
    ");
    $st->execute([
        $priceFeedId,
        $targetQty,
        (string)$priceInput['price_mode'],
        $discountPrice,
        (float)$priceInput['discount_amount'],
        (int)($merged['product_id'] ?? $existing['product_id'] ?? 0),
        (string)($merged['name'] ?? $existing['name'] ?? ''),
        $merged['regular_price_snapshot'] ?? null,
        $merged['regular_min_price_snapshot'] ?? null,
        $merged['current_price'] ?? null,
        $merged['current_min_price'] ?? null,
        $merged['current_marketing_price'] ?? null,
        $merged['current_price_index_value'] ?? null,
        (string)($merged['current_price_index_level'] ?? ''),
        (string)($merged['current_price_index_level_label'] ?? ''),
        (string)($merged['current_price_index_source'] ?? ''),
        $stockPushRequired,
        $pricePushRequired,
        $row['status'],
        $row['status'],
        $row['status'],
        $actor,
        $id,
        $connectionId,
    ]);
    return $id;
}

function stock_tool_item_refresh(int $connectionId, int $id, ?string $actor = null, array $cfg = []): void
{
    stock_tool_tables_ensure($cfg);
    $item = stock_tool_item_get($id, $connectionId, $cfg);
    if (!is_array($item)) {
        throw new RuntimeException('Товар stock pois не найден.');
    }
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Подключение Ozon не найдено.');
    }
    $current = stock_tool_fetch_current_item_data(ozon_price_cfg_with_connection($cfg, $connection), (string)$item['offer_id']);
    $priceInput = stock_tool_resolve_price_input([], $item, $current['current_price']);
    if ((string)($item['price_mode'] ?? 'exact') === 'discount_amount' && (float)($item['regular_price_snapshot'] ?? 0) <= 0 && (float)($current['current_price'] ?? 0) > 0) {
        $item['regular_price_snapshot'] = (float)$current['current_price'];
        $priceInput = stock_tool_resolve_price_input([], $item, $current['current_price']);
    }
    $st = db()->prepare("
        UPDATE feedtools_stock_tool_items
        SET product_id = ?,
            name = ?,
            discount_price = ?,
            regular_price_snapshot = CASE WHEN regular_price_snapshot IS NULL AND ? = 'discount_amount' THEN ? ELSE regular_price_snapshot END,
            regular_min_price_snapshot = CASE WHEN regular_min_price_snapshot IS NULL AND ? = 'discount_amount' THEN ? ELSE regular_min_price_snapshot END,
            current_price = ?,
            current_min_price = ?,
            current_marketing_price = ?,
            current_price_index_value = ?,
            current_price_index_level = ?,
            current_price_index_level_label = ?,
            current_price_index_source = ?,
            last_error = NULL,
            updated_by = ?
        WHERE id = ? AND connection_id = ?
    ");
    $st->execute([
        (int)$current['product_id'],
        (string)$current['name'],
        (float)$priceInput['discount_price'],
        (string)($item['price_mode'] ?? 'exact'),
        $current['current_price'],
        (string)($item['price_mode'] ?? 'exact'),
        $current['current_min_price'],
        $current['current_price'],
        $current['current_min_price'],
        $current['current_marketing_price'],
        $current['current_price_index_value'],
        $current['current_price_index_level'],
        $current['current_price_index_level_label'],
        $current['current_price_index_source'],
        $actor,
        $id,
        $connectionId,
    ]);
}

function stock_tool_item_set_status(int $connectionId, int $id, string $status, ?string $actor = null, array $cfg = []): void
{
    stock_tool_tables_ensure($cfg);
    $allowed = ['paused', 'ready', 'draft'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Неподдерживаемый статус stock pois.');
    }
    $item = stock_tool_item_get($id, $connectionId, $cfg);
    if (!is_array($item)) {
        throw new RuntimeException('Товар stock pois не найден.');
    }
    if ($status !== 'paused') {
        $item['status'] = '';
        $status = stock_tool_status_after_save($item);
    }
    $st = db()->prepare("
        UPDATE feedtools_stock_tool_items
        SET status = ?, updated_by = ?
        WHERE id = ? AND connection_id = ?
    ");
    $st->execute([$status, $actor, $id, $connectionId]);
}

function stock_tool_item_delete(int $connectionId, int $id, array $cfg = []): void
{
    stock_tool_tables_ensure($cfg);
    if ($id <= 0) {
        throw new RuntimeException('Не удалось определить товар для удаления.');
    }
    $st = db()->prepare("DELETE FROM feedtools_stock_tool_items WHERE id = ? AND connection_id = ?");
    $st->execute([$id, $connectionId]);
}

function stock_tool_push_profile(array $settings, int $connectionId): array
{
    return [
        'id' => 0,
        'connection_id' => $connectionId,
        'marketplace' => 'ozon',
        'ozon_warehouse_key' => (string)($settings['ozon_warehouse_key'] ?? ''),
        'ozon_warehouse_id' => (string)($settings['ozon_warehouse_id'] ?? ''),
        'ozon_warehouse_name' => (string)($settings['ozon_warehouse_name'] ?? ''),
    ];
}

function stock_tool_regular_price_for_item(array $item, int $connectionId, array $cfg, callable $log): array
{
    $offerId = trim((string)($item['offer_id'] ?? ''));
    $feedId = (int)($item['price_feed_id'] ?? 0);
    if ($feedId > 0) {
        $feed = ozon_price_feed_get($feedId, $connectionId, $cfg);
        if (!is_array($feed)) {
            throw new RuntimeException('Профиль Price Tool для возврата цены не найден.');
        }
        $feedOffer = ozon_price_feed_find_offer($feed, $offerId);
        if (!is_array($feedOffer)) {
            throw new RuntimeException('Товар не найден в выбранном фиде Price Tool.');
        }
        $ozonItems = ozon_price_fetch_price_items($cfg, [$offerId], 100);
        $calc = ozon_price_calculate_offer($feed, $feedOffer, is_array($ozonItems[$offerId] ?? null) ? (array)$ozonItems[$offerId] : null);
        $strategy = ozon_price_build_promotion_strategy($calc, [], $feed);
        $desiredState = ozon_price_build_desired_state($calc, $strategy, [], $connectionId, $cfg);
        $regular = (float)($desiredState['regular_price'] ?? ($calc['recommended_price'] ?? 0));
        $min = (float)($desiredState['min_price'] ?? ($calc['recommended_min_price'] ?? 0));
        if ($regular > 0 && $min > 0) {
            return [
                'price' => ozon_price_round_rub($regular),
                'min_price' => ozon_price_round_rub($min),
                'old_price' => isset($desiredState['old_price']) ? ozon_price_round_rub((float)$desiredState['old_price']) : null,
                'current_old_price' => $desiredState['current_old_price'] ?? null,
                'source' => 'price_tool',
                'calc' => $calc,
                'desired_state' => $desiredState,
            ];
        }
        $log('[restore warn] ' . $offerId . ': расчет Price Tool не дал цену, пробую snapshot.' . "\n");
    }

    $snapshotPrice = (float)($item['regular_price_snapshot'] ?? 0);
    $snapshotMinPrice = (float)($item['regular_min_price_snapshot'] ?? 0);
    if ($snapshotPrice > 0 || $snapshotMinPrice > 0) {
        $validation = ozon_price_finalize_desired_prices($snapshotPrice, $snapshotMinPrice, null, null);
        if (!empty($validation['is_valid'])) {
            return [
                'price' => (float)$validation['regular_price'],
                'min_price' => (float)$validation['min_price'],
                'source' => 'snapshot',
                'calc' => null,
            ];
        }
    }

    throw new RuntimeException('Не удалось рассчитать обычную цену и нет сохраненного snapshot.');
}

function stock_tool_action_rows_for_price(int $connectionId, string $offerId, int $productId, float $discountPrice, array $cfg): array
{
    if ($connectionId <= 0 || $discountPrice <= 0 || ($offerId === '' && $productId <= 0)) {
        return [];
    }
    $rows = ozon_actions_rows_for_offer_or_product($connectionId, $offerId, $productId > 0 ? $productId : null, $cfg);
    $byAction = [];
    foreach ($rows as $row) {
        $sourceType = (string)($row['source_type'] ?? '');
        if (!in_array($sourceType, ['candidate', 'participating'], true)) {
            continue;
        }
        $actionId = (int)($row['action_id'] ?? 0);
        $rowProductId = (int)($row['product_id'] ?? 0);
        if ($actionId <= 0 || $rowProductId <= 0) {
            continue;
        }
        $maxActionPrice = (float)($row['max_action_price'] ?? 0);
        $elasticMin = (float)($row['price_min_elastic'] ?? 0);
        $elasticMax = (float)($row['price_max_elastic'] ?? 0);
        $fitsMaxPrice = $maxActionPrice > 0 && $discountPrice <= $maxActionPrice + 0.01;
        $fitsElasticRange = $elasticMin > 0 && $elasticMax > 0 && $discountPrice >= $elasticMin - 0.01 && $discountPrice <= $elasticMax + 0.01;
        if (!$fitsMaxPrice && !$fitsElasticRange) {
            continue;
        }
        $priority = $sourceType === 'participating' ? 2 : 1;
        $existingPriority = (int)($byAction[$actionId]['_priority'] ?? 0);
        if ($existingPriority > $priority) {
            continue;
        }
        $row['_priority'] = $priority;
        $row['product_id'] = $rowProductId;
        $row['action_price'] = $discountPrice;
        $byAction[$actionId] = $row;
    }
    return array_values(array_map(static function (array $row): array {
        unset($row['_priority']);
        return $row;
    }, $byAction));
}

function stock_tool_apply_action_upserts(array $runtimeCfg, array $actionRows): array
{
    $result = [
        'applied' => [],
        'errors' => [],
    ];
    foreach ($actionRows as $row) {
        $actionId = (int)($row['action_id'] ?? 0);
        $productId = (int)($row['product_id'] ?? 0);
        $actionPrice = (float)($row['action_price'] ?? 0);
        if ($actionId <= 0 || $productId <= 0 || $actionPrice <= 0) {
            continue;
        }
        try {
            $response = ozon_actions_activate_products($runtimeCfg, $actionId, [[
                'product_id' => $productId,
                'action_price' => $actionPrice,
            ]]);
            $result['applied'][] = [
                'action_id' => $actionId,
                'title' => (string)($row['title'] ?? ''),
                'product_id' => $productId,
                'action_price' => round($actionPrice, 2),
                'source_type' => (string)($row['source_type'] ?? ''),
                'response' => $response,
            ];
        } catch (Throwable $e) {
            $result['errors'][] = [
                'action_id' => $actionId,
                'title' => (string)($row['title'] ?? ''),
                'message' => $e->getMessage(),
            ];
        }
    }
    return $result;
}

function stock_tool_deactivate_tracked_actions(array $runtimeCfg, array $item, int $productId): array
{
    $actionIds = stock_tool_action_ids_decode($item['action_ids'] ?? ($item['action_ids_json'] ?? null));
    $result = [
        'removed' => [],
        'errors' => [],
    ];
    if (!$actionIds || $productId <= 0) {
        return $result;
    }
    foreach ($actionIds as $actionId) {
        try {
            $result['removed'][] = [
                'action_id' => (int)$actionId,
                'product_id' => $productId,
                'response' => ozon_actions_deactivate_products($runtimeCfg, (int)$actionId, [$productId]),
            ];
        } catch (Throwable $e) {
            $result['errors'][] = [
                'action_id' => (int)$actionId,
                'message' => $e->getMessage(),
            ];
        }
    }
    return $result;
}

function stock_tool_update_item_sync_state(int $id, int $connectionId, array $fields): void
{
    $allowed = [
        'product_id',
        'name',
        'price_mode',
        'discount_price',
        'discount_amount',
        'regular_price_snapshot',
        'regular_min_price_snapshot',
        'current_price',
        'current_min_price',
        'current_marketing_price',
        'current_price_index_value',
        'current_price_index_level',
        'current_price_index_level_label',
        'current_price_index_source',
        'last_known_stock_qty',
        'last_known_reserved_qty',
        'stock_push_required',
        'price_push_required',
        'status',
        'last_stock_pushed_at',
        'discount_started_at',
        'sold_at',
        'restored_at',
        'last_sync_at',
        'last_error',
        'action_ids_json',
        'last_result_json',
    ];
    $sets = [];
    $args = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        $sets[] = $key . ' = ?';
        $args[] = $fields[$key];
    }
    if (!$sets) {
        return;
    }
    $args[] = $id;
    $args[] = $connectionId;
    db()->prepare("
        UPDATE feedtools_stock_tool_items
        SET " . implode(', ', $sets) . "
        WHERE id = ? AND connection_id = ?
    ")->execute($args);
}

function stock_tool_sync_connection(int $connectionId, ?string $actor, array $cfg, callable $log): array
{
    stock_tool_tables_ensure($cfg);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection)) {
        throw new RuntimeException('Подключение Ozon не найдено.');
    }
    if ((string)($connection['marketplace'] ?? 'ozon') !== 'ozon') {
        throw new RuntimeException('stock pois доступен только для подключения Ozon.');
    }

    $settings = stock_tool_settings_get($connectionId, $cfg);
    if (empty($settings['is_enabled'])) {
        throw new RuntimeException('stock pois для этого подключения выключен.');
    }
    $warehouseId = trim((string)($settings['ozon_warehouse_id'] ?? ''));
    if ($warehouseId === '') {
        throw new RuntimeException('В настройках stock pois не выбран склад Ozon.');
    }

    $runtimeCfg = ozon_price_cfg_with_connection($cfg, $connection);
    $items = stock_tool_items_list($connectionId, $cfg);
    $items = array_values(array_filter($items, static function (array $item): bool {
        return !in_array((string)($item['status'] ?? ''), ['paused'], true);
    }));

    $offerIds = array_values(array_filter(array_map(
        static fn(array $item): string => trim((string)($item['offer_id'] ?? '')),
        $items
    )));
    $log('stock pois: connection=' . $connectionId . ' · warehouse=' . (string)$settings['ozon_warehouse_name'] . ' · items=' . count($items) . "\n");

    $stockMap = $offerIds
        ? stocks_tool_fetch_ozon_stock_map($runtimeCfg, $offerIds, $warehouseId, $log)
        : [];
    $priceItems = $offerIds
        ? ozon_price_fetch_price_items($runtimeCfg, $offerIds, 100)
        : [];
    $infoMap = $offerIds
        ? stock_tool_fetch_product_info_map($runtimeCfg, $offerIds)
        : [];

    $totals = [
        'items' => count($items),
        'draft' => 0,
        'stock_pushed' => 0,
        'discount_pushed' => 0,
        'kept_discount' => 0,
        'actions_upserted' => 0,
        'action_errors' => 0,
        'sold' => 0,
        'restored' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        $offerId = trim((string)($item['offer_id'] ?? ''));
        if ($id <= 0 || $offerId === '') {
            continue;
        }

        $fields = [
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ];
        $result = [
            'offer_id' => $offerId,
            'actions' => [],
        ];

        try {
            $targetQty = max(0, (int)($item['target_stock_qty'] ?? 0));
            $priceMode = stock_tool_normalize_price_mode((string)($item['price_mode'] ?? 'exact'));
            $hasUsablePriceInput = $priceMode === 'discount_amount'
                ? (float)($item['discount_amount'] ?? 0) > 0
                : (float)($item['discount_price'] ?? 0) > 0;
            if ($targetQty <= 0 || !$hasUsablePriceInput) {
                $fields['status'] = 'draft';
                $totals['draft']++;
                $result['actions'][] = 'draft_missing_qty_or_price';
                $fields['last_result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                stock_tool_update_item_sync_state($id, $connectionId, $fields);
                continue;
            }

            $priceItem = is_array($priceItems[$offerId] ?? null) ? (array)$priceItems[$offerId] : [];
            $infoItem = is_array($infoMap[$offerId] ?? null) ? (array)$infoMap[$offerId] : [];
            if (!empty($priceItem['__invalid_offer_id'])) {
                throw new RuntimeException((string)($priceItem['__invalid_offer_id_reason'] ?? 'Артикул не подходит для Ozon API.'));
            }
            if (!empty($priceItem['__missing_on_ozon']) || !empty($priceItem['__missing_price_data'])) {
                throw new RuntimeException('Ozon не вернул текущую цену по товару.');
            }
            if (!empty($priceItem['archived']) || !empty($infoItem['is_archived'])) {
                throw new RuntimeException('Товар находится в архиве Ozon.');
            }

            $currentPrice = $priceItem ? stock_tool_price_item_money($priceItem, 'price') : $item['current_price'];
            $currentMinPrice = $priceItem ? stock_tool_price_item_money($priceItem, 'min_price') : $item['current_min_price'];
            $currentOldPrice = $priceItem ? stock_tool_price_item_money($priceItem, 'old_price') : null;
            $currentMarketingPrice = $priceItem ? stock_tool_price_item_money($priceItem, 'marketing_seller_price') : $item['current_marketing_price'];
            $indexSnapshot = $priceItem ? stock_tool_price_index_snapshot($priceItem) : [
                'current_price_index_value' => $item['current_price_index_value'] ?? null,
                'current_price_index_level' => (string)($item['current_price_index_level'] ?? ''),
                'current_price_index_level_label' => (string)($item['current_price_index_level_label'] ?? ''),
                'current_price_index_source' => (string)($item['current_price_index_source'] ?? ''),
            ];
            $productId = (int)($priceItem['product_id'] ?? $priceItem['id'] ?? $infoItem['id'] ?? $infoItem['product_id'] ?? $item['product_id'] ?? 0);
            $name = trim((string)($priceItem['name'] ?? $priceItem['product_name'] ?? $infoItem['name'] ?? $item['name'] ?? ''));
            if ((string)($item['price_mode'] ?? 'exact') === 'discount_amount') {
                if ((float)($item['regular_price_snapshot'] ?? 0) <= 0 && $currentPrice !== null && $currentPrice > 0) {
                    $fields['regular_price_snapshot'] = $currentPrice;
                    $item['regular_price_snapshot'] = $currentPrice;
                }
                if ((float)($item['regular_min_price_snapshot'] ?? 0) <= 0 && $currentMinPrice !== null && $currentMinPrice > 0) {
                    $fields['regular_min_price_snapshot'] = $currentMinPrice;
                    $item['regular_min_price_snapshot'] = $currentMinPrice;
                }
            }
            $discountPrice = stock_tool_effective_discount_price($item, $currentPrice);
            if ($targetQty <= 0 || $discountPrice <= 0) {
                $fields['status'] = 'draft';
                $fields['discount_price'] = $discountPrice;
                $totals['draft']++;
                $result['actions'][] = 'draft_missing_qty_or_price';
                $fields['last_result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                stock_tool_update_item_sync_state($id, $connectionId, $fields);
                continue;
            }

            $stockRow = is_array($stockMap[$offerId] ?? null) ? (array)$stockMap[$offerId] : null;
            $presentQty = max(0, (int)($stockRow['present'] ?? 0));
            $reservedQty = max(0, (int)($stockRow['reserved'] ?? 0));
            $remainingQty = $presentQty + $reservedQty;

            $fields['product_id'] = $productId;
            $fields['name'] = $name;
            $fields['current_price'] = $currentPrice;
            $fields['current_min_price'] = $currentMinPrice;
            $fields['current_marketing_price'] = $currentMarketingPrice;
            $fields['current_price_index_value'] = $indexSnapshot['current_price_index_value'] ?? null;
            $fields['current_price_index_level'] = (string)($indexSnapshot['current_price_index_level'] ?? '');
            $fields['current_price_index_level_label'] = (string)($indexSnapshot['current_price_index_level_label'] ?? '');
            $fields['current_price_index_source'] = (string)($indexSnapshot['current_price_index_source'] ?? '');
            $fields['discount_price'] = $discountPrice;
            $fields['last_known_stock_qty'] = $presentQty;
            $fields['last_known_reserved_qty'] = $reservedQty;
            $result['before'] = [
                'present' => $presentQty,
                'reserved' => $reservedQty,
                'price' => $currentPrice,
                'min_price' => $currentMinPrice,
                'old_price' => $currentOldPrice,
                'price_index_value' => $fields['current_price_index_value'],
                'price_index_level' => $fields['current_price_index_level_label'],
            ];

            $stockWasPushed = trim((string)($item['last_stock_pushed_at'] ?? '')) !== '';
            if (!empty($item['stock_push_required']) || !$stockWasPushed) {
                $pushResult = stocks_tool_push_updates(
                    stock_tool_push_profile($settings, $connectionId),
                    [[
                        'offer_id' => $offerId,
                        'target_qty' => $targetQty,
                        'present_qty' => $presentQty,
                        'reserved_qty' => $reservedQty,
                        'product_id' => $productId,
                    ]],
                    $runtimeCfg,
                    $log
                );
                $fields['stock_push_required'] = 0;
                $fields['last_stock_pushed_at'] = date('Y-m-d H:i:s');
                $fields['last_known_stock_qty'] = $targetQty;
                $presentQty = $targetQty;
                $remainingQty = $presentQty + $reservedQty;
                $stockWasPushed = true;
                $totals['stock_pushed']++;
                $result['actions'][] = 'stock_pushed';
                $result['stock_push'] = $pushResult;
            }

            if ($stockWasPushed && $remainingQty <= 0 && (string)($item['status'] ?? '') !== 'restored') {
                $fields['sold_at'] = trim((string)($item['sold_at'] ?? '')) !== '' ? $item['sold_at'] : date('Y-m-d H:i:s');
                $regular = stock_tool_regular_price_for_item(array_replace($item, $fields), $connectionId, $runtimeCfg, $log);
                $restoreResp = ozon_price_import_prices($runtimeCfg, [[
                    'offer_id' => $offerId,
                    'product_id' => $productId,
                    'price' => (float)$regular['price'],
                    'min_price' => (float)$regular['min_price'],
                    'old_price' => $regular['old_price'] ?? null,
                    'current_old_price' => $regular['current_old_price'] ?? $currentOldPrice,
                ]]);
                $actionRemove = stock_tool_deactivate_tracked_actions($runtimeCfg, $item, $productId);
                $fields['status'] = 'restored';
                $fields['restored_at'] = date('Y-m-d H:i:s');
                $fields['price_push_required'] = 0;
                $fields['current_price'] = (float)$regular['price'];
                $fields['current_min_price'] = (float)$regular['min_price'];
                if (!$actionRemove['errors']) {
                    $fields['action_ids_json'] = stock_tool_action_ids_encode([]);
                } else {
                    $totals['action_errors'] += count($actionRemove['errors']);
                    $fields['last_error'] = 'Не удалось удалить из части акций: ' . implode('; ', array_map(
                        static fn(array $row): string => (string)($row['message'] ?? ''),
                        $actionRemove['errors']
                    ));
                }
                $totals['sold']++;
                $totals['restored']++;
                $result['actions'][] = 'regular_price_restored';
                $result['restore'] = [
                    'source' => (string)$regular['source'],
                    'price' => (float)$regular['price'],
                    'min_price' => (float)$regular['min_price'],
                    'response' => $restoreResp,
                    'actions_remove' => $actionRemove,
                ];
                $fields['last_result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                stock_tool_update_item_sync_state($id, $connectionId, $fields);
                continue;
            }

            if ((string)($item['status'] ?? '') === 'restored') {
                $totals['skipped']++;
                $result['actions'][] = 'already_restored';
                $fields['last_result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                stock_tool_update_item_sync_state($id, $connectionId, $fields);
                continue;
            }

            if ((float)($item['regular_price_snapshot'] ?? 0) <= 0 && $currentPrice !== null && $currentPrice > 0) {
                $fields['regular_price_snapshot'] = $currentPrice;
            }
            if ((float)($item['regular_min_price_snapshot'] ?? 0) <= 0 && $currentMinPrice !== null && $currentMinPrice > 0) {
                $fields['regular_min_price_snapshot'] = $currentMinPrice;
            }

            $needsDiscountPrice = !empty($item['price_push_required'])
                || $currentPrice === null
                || abs((float)$currentPrice - $discountPrice) > 0.01
                || $currentMinPrice === null
                || abs((float)$currentMinPrice - $discountPrice) > 0.01;

            if ($remainingQty > 0 && $needsDiscountPrice) {
                $priceResp = ozon_price_import_prices($runtimeCfg, [[
                    'offer_id' => $offerId,
                    'product_id' => $productId,
                    'price' => $discountPrice,
                    'min_price' => $discountPrice,
                    'current_old_price' => $currentOldPrice,
                ]]);
                $fields['price_push_required'] = 0;
                $fields['current_price'] = $discountPrice;
                $fields['current_min_price'] = $discountPrice;
                $fields['discount_started_at'] = trim((string)($item['discount_started_at'] ?? '')) !== ''
                    ? $item['discount_started_at']
                    : date('Y-m-d H:i:s');
                $fields['status'] = 'active';
                $totals['discount_pushed']++;
                $result['actions'][] = 'discount_price_pushed';
                $result['price_push'] = $priceResp;
            } elseif ($remainingQty > 0) {
                $fields['status'] = 'active';
                $totals['kept_discount']++;
                $result['actions'][] = 'discount_price_already_active';
            } else {
                $fields['status'] = $stockWasPushed ? 'sold' : 'ready';
                $totals['skipped']++;
                $result['actions'][] = 'no_remaining_qty_without_restore';
            }

            if ($remainingQty > 0 && in_array((string)($fields['status'] ?? $item['status'] ?? ''), ['active', 'ready'], true)) {
                $eligibleActions = stock_tool_action_rows_for_price($connectionId, $offerId, $productId, $discountPrice, $cfg);
                if ($eligibleActions) {
                    $actionUpsert = stock_tool_apply_action_upserts($runtimeCfg, $eligibleActions);
                    $result['promotion_actions'] = $actionUpsert;
                    if ($actionUpsert['applied']) {
                        $knownActionIds = stock_tool_action_ids_decode($item['action_ids'] ?? ($item['action_ids_json'] ?? null));
                        foreach ($actionUpsert['applied'] as $appliedAction) {
                            $knownActionIds[] = (int)($appliedAction['action_id'] ?? 0);
                        }
                        $fields['action_ids_json'] = stock_tool_action_ids_encode($knownActionIds);
                        $totals['actions_upserted'] += count($actionUpsert['applied']);
                        $result['actions'][] = 'promotion_actions_upserted';
                    }
                    if ($actionUpsert['errors']) {
                        $totals['action_errors'] += count($actionUpsert['errors']);
                        $fields['last_error'] = 'Не удалось добавить в часть акций: ' . implode('; ', array_map(
                            static fn(array $row): string => (string)($row['message'] ?? ''),
                            $actionUpsert['errors']
                        ));
                        $result['actions'][] = 'promotion_action_errors';
                    }
                }
            }
        } catch (Throwable $e) {
            $fields['status'] = 'error';
            $fields['last_error'] = $e->getMessage();
            $result['error'] = $e->getMessage();
            $totals['errors']++;
            $log('[item error] ' . $offerId . ': ' . $e->getMessage() . "\n");
        }

        $fields['last_result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        stock_tool_update_item_sync_state($id, $connectionId, $fields);
    }

    return $totals;
}

function stock_tool_run_create(int $connectionId, ?string $actor = null, array $cfg = []): int
{
    stock_tool_tables_ensure($cfg);
    $st = db()->prepare("
        INSERT INTO feedtools_stock_tool_runs (connection_id, actor, status, started_at)
        VALUES (?, ?, 'running', NOW())
    ");
    $st->execute([$connectionId, $actor]);
    return (int)db()->lastInsertId();
}

function stock_tool_run_append(int $runId, string $message): void
{
    if ($runId <= 0 || $message === '') {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_stock_tool_runs
        SET log_text = CONCAT(COALESCE(log_text, ''), ?)
        WHERE id = ?
    ");
    $st->execute([$message, $runId]);
}

function stock_tool_run_finish(int $runId, string $status, array $totals, ?string $error = null): void
{
    if ($runId <= 0) {
        return;
    }
    $st = db()->prepare("
        UPDATE feedtools_stock_tool_runs
        SET status = ?, totals_json = ?, error_text = ?, finished_at = NOW()
        WHERE id = ?
    ");
    $st->execute([
        $status,
        json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $error,
        $runId,
    ]);
}

function stock_tool_run_get(int $runId, array $cfg = []): ?array
{
    stock_tool_tables_ensure($cfg);
    if ($runId <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_stock_tool_runs WHERE id = ? LIMIT 1");
    $st->execute([$runId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['totals'] = json_decode((string)($row['totals_json'] ?? ''), true);
    if (!is_array($row['totals'])) {
        $row['totals'] = [];
    }
    return $row;
}

function stock_tool_recent_runs(int $connectionId, int $limit = 8, array $cfg = []): array
{
    stock_tool_tables_ensure($cfg);
    $st = db()->prepare("
        SELECT *
        FROM feedtools_stock_tool_runs
        WHERE connection_id = ?
        ORDER BY id DESC
        LIMIT " . max(1, min(50, $limit))
    );
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['totals'] = json_decode((string)($row['totals_json'] ?? ''), true);
        if (!is_array($row['totals'])) {
            $row['totals'] = [];
        }
    }
    unset($row);
    return $rows;
}

function stock_tool_run_connection(int $connectionId, ?string $actor = null, array $cfg = []): int
{
    stock_tool_tables_ensure($cfg);
    $runId = stock_tool_run_create($connectionId, $actor, $cfg);
    $log = static function (string $line) use ($runId): void {
        stock_tool_run_append($runId, '[' . date('Y-m-d H:i:s') . '] ' . $line);
    };

    try {
        $totals = stock_tool_sync_connection($connectionId, $actor, stock_tool_cfg_fallback($cfg), $log);
        $status = (int)($totals['errors'] ?? 0) > 0 ? 'partial' : 'success';
        stock_tool_run_finish($runId, $status, $totals, $status === 'partial' ? 'Часть товаров завершилась с ошибками.' : null);
        return $runId;
    } catch (Throwable $e) {
        $log('Фатальная ошибка: ' . $e->getMessage() . "\n");
        stock_tool_run_finish($runId, 'error', ['fatal_error' => $e->getMessage()], $e->getMessage());
        throw $e;
    }
}

function stock_tool_connection_ids_for_cli(array $cfg = [], ?int $connectionId = null): array
{
    stock_tool_tables_ensure($cfg);
    if (($connectionId ?? 0) > 0) {
        return [(int)$connectionId];
    }
    $rows = db()->query("
        SELECT DISTINCT s.connection_id
        FROM feedtools_stock_tool_settings s
        INNER JOIN feedtools_stock_tool_items i ON i.connection_id = s.connection_id
        INNER JOIN feedtools_marketplace_connections c ON c.id = s.connection_id
        WHERE s.is_enabled = 1
          AND c.marketplace = 'ozon'
          AND c.is_active = 1
          AND i.status IN ('ready', 'active', 'sold', 'error', 'draft')
        ORDER BY s.connection_id ASC
    ")->fetchAll() ?: [];
    return array_values(array_map(static fn(array $row): int => (int)($row['connection_id'] ?? 0), $rows));
}
