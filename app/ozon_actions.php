<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ozon_products.php';

function ozon_actions_tables_ensure(): void
{
    static $ready = false;
    if ($ready) return;

    $schemaCacheDir = dirname(__DIR__) . '/storage/cache';
    $schemaCachePath = $schemaCacheDir . '/ozon_actions_schema_20260508_v1.ready';
    if (is_file($schemaCachePath) && (time() - (int)@filemtime($schemaCachePath)) < 86400) {
        $ready = true;
        return;
    }

    $pdo = db();
    ozon_products_tables_ensure();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ozon_client_id VARCHAR(64) NOT NULL,
            action_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            date_from DATETIME NULL,
            date_to DATETIME NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_participating TINYINT(1) NOT NULL DEFAULT 0,
            participating_products_count INT NOT NULL DEFAULT 0,
            potential_products_count INT NOT NULL DEFAULT 0,
            action_type VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            sync_token VARCHAR(32) NULL,
            synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_action (connection_id, action_id),
            KEY idx_connection_synced (connection_id, synced_at),
            KEY idx_synced_at (synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_action_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ozon_client_id VARCHAR(64) NOT NULL,
            action_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            offer_id VARCHAR(191) NULL,
            source_type VARCHAR(32) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            action_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            max_action_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            add_mode VARCHAR(32) NULL,
            stock INT NOT NULL DEFAULT 0,
            min_stock INT NOT NULL DEFAULT 0,
            alert_max_action_price_failed TINYINT(1) NOT NULL DEFAULT 0,
            alert_max_action_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            current_boost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            price_min_elastic DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price_max_elastic DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_boost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_boost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            raw_json LONGTEXT NULL,
            sync_token VARCHAR(32) NULL,
            synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connection_action_product_source (connection_id, action_id, product_id, source_type),
            KEY idx_offer (connection_id, offer_id),
            KEY idx_product (connection_id, product_id),
            KEY idx_action_source (connection_id, action_id, source_type),
            KEY idx_client_offer (ozon_client_id, offer_id),
            KEY idx_client_product (ozon_client_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_ozon_actions',
        'connection_id',
        "ALTER TABLE feedtools_ozon_actions ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );
    ozon_products_table_add_column_if_missing(
        $pdo,
        'feedtools_ozon_action_products',
        'connection_id',
        "ALTER TABLE feedtools_ozon_action_products ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );

    $needsActionsBackfill = (bool)$pdo
        ->query("SELECT 1 FROM feedtools_ozon_actions WHERE connection_id = 0 LIMIT 1")
        ->fetchColumn();
    if ($needsActionsBackfill) {
        $pdo->exec("
            UPDATE feedtools_ozon_actions a
            JOIN (
                SELECT client_id, MIN(id) AS connection_id
                FROM feedtools_marketplace_connections
                WHERE marketplace = 'ozon' AND client_id <> ''
                GROUP BY client_id
            ) c ON BINARY c.client_id = BINARY a.ozon_client_id
            SET a.connection_id = c.connection_id
            WHERE a.connection_id = 0
        ");
    }
    $needsActionProductsBackfill = (bool)$pdo
        ->query("SELECT 1 FROM feedtools_ozon_action_products WHERE connection_id = 0 LIMIT 1")
        ->fetchColumn();
    if ($needsActionProductsBackfill) {
        $pdo->exec("
            UPDATE feedtools_ozon_action_products ap
            JOIN (
                SELECT client_id, MIN(id) AS connection_id
                FROM feedtools_marketplace_connections
                WHERE marketplace = 'ozon' AND client_id <> ''
                GROUP BY client_id
            ) c ON BINARY c.client_id = BINARY ap.ozon_client_id
            SET ap.connection_id = c.connection_id
            WHERE ap.connection_id = 0
        ");
    }

    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_ozon_actions',
        'uniq_connection_action',
        "ALTER TABLE feedtools_ozon_actions ADD UNIQUE KEY uniq_connection_action (connection_id, action_id)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_ozon_actions',
        'idx_connection_synced',
        "ALTER TABLE feedtools_ozon_actions ADD KEY idx_connection_synced (connection_id, synced_at)"
    );
    ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_actions', 'uniq_client_action');

    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_ozon_action_products',
        'uniq_connection_action_product_source',
        "ALTER TABLE feedtools_ozon_action_products ADD UNIQUE KEY uniq_connection_action_product_source (connection_id, action_id, product_id, source_type)"
    );
    if (ozon_products_table_index_columns($pdo, 'feedtools_ozon_action_products', 'idx_offer') !== ['connection_id', 'offer_id']) {
        ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_action_products', 'idx_offer');
        ozon_products_table_add_index_if_missing(
            $pdo,
            'feedtools_ozon_action_products',
            'idx_offer',
            "ALTER TABLE feedtools_ozon_action_products ADD KEY idx_offer (connection_id, offer_id)"
        );
    }
    if (ozon_products_table_index_columns($pdo, 'feedtools_ozon_action_products', 'idx_product') !== ['connection_id', 'product_id']) {
        ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_action_products', 'idx_product');
        ozon_products_table_add_index_if_missing(
            $pdo,
            'feedtools_ozon_action_products',
            'idx_product',
            "ALTER TABLE feedtools_ozon_action_products ADD KEY idx_product (connection_id, product_id)"
        );
    }
    if (ozon_products_table_index_columns($pdo, 'feedtools_ozon_action_products', 'idx_action_source') !== ['connection_id', 'action_id', 'source_type']) {
        ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_action_products', 'idx_action_source');
        ozon_products_table_add_index_if_missing(
            $pdo,
            'feedtools_ozon_action_products',
            'idx_action_source',
            "ALTER TABLE feedtools_ozon_action_products ADD KEY idx_action_source (connection_id, action_id, source_type)"
        );
    }
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_ozon_action_products',
        'idx_client_offer',
        "ALTER TABLE feedtools_ozon_action_products ADD KEY idx_client_offer (ozon_client_id, offer_id)"
    );
    ozon_products_table_add_index_if_missing(
        $pdo,
        'feedtools_ozon_action_products',
        'idx_client_product',
        "ALTER TABLE feedtools_ozon_action_products ADD KEY idx_client_product (ozon_client_id, product_id)"
    );
    ozon_products_table_drop_index_if_exists($pdo, 'feedtools_ozon_action_products', 'uniq_client_action_product_source');

    if (!is_dir($schemaCacheDir)) {
        @mkdir($schemaCacheDir, 0775, true);
    }
    @touch($schemaCachePath);

    $ready = true;
}

function ozon_get_json(array $oz, string $path): array
{
    $url = rtrim((string)$oz['base_url'], '/') . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Client-Id: ' . (string)$oz['client_id'],
            'Api-Key: ' . (string)$oz['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => (int)($oz['timeout_sec'] ?? 30),
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($raw === false) {
        throw new RuntimeException('Ozon request failed: ' . ($curlErr ?: 'curl error'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $snippet = trim(substr((string)$raw, 0, 200));
        throw new RuntimeException('Ozon вернул не-JSON (HTTP ' . $http . ') URL=' . $url . ' BODY=' . $snippet);
    }

    if ($http >= 400) {
        $msg = $data['message'] ?? ($data['error']['message'] ?? 'HTTP error');
        throw new RuntimeException('Ozon HTTP ' . $http . ': ' . $msg);
    }

    return $data;
}

function ozon_actions_fetch_list(array $oz): array
{
    $resp = ozon_get_json($oz, '/v1/actions');
    $items = $resp['result'] ?? [];
    return is_array($items) ? $items : [];
}

function ozon_actions_fetch_page(array $oz, string $mode, int $actionId, string $lastId = '', int $limit = 100): array
{
    $path = $mode === 'candidate' ? '/v1/actions/candidates' : '/v1/actions/products';
    $payload = [
        'action_id' => $actionId,
        'limit' => $limit,
    ];
    if ($lastId !== '') $payload['last_id'] = $lastId;
    $resp = ozon_post_json($oz, $path, $payload);
    $result = $resp['result'] ?? [];
    return [
        'products' => is_array($result['products'] ?? null) ? $result['products'] : [],
        'last_id' => (string)($result['last_id'] ?? ''),
    ];
}

function ozon_action_product_offer_map(int|string|null $scopeRef = null, array $cfg = []): array
{
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $args] = ozon_products_scope_clause($scope);
    $st = db()->prepare("
        SELECT product_id, offer_id
        FROM feedtools_ozon_products
        WHERE {$whereSql}
          AND product_id IS NOT NULL
          AND offer_id IS NOT NULL
          AND offer_id <> ''
    ");
    $st->execute($args);
    $map = [];
    foreach ($st->fetchAll() as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $oid = trim((string)($row['offer_id'] ?? ''));
        if ($pid > 0 && $oid !== '') $map[$pid] = $oid;
    }
    return $map;
}

function ozon_actions_upsert_action(PDO $pdo, int $connectionId, string $clientId, array $item, string $syncToken, string $syncedAt): void
{
    $st = $pdo->prepare("
        INSERT INTO feedtools_ozon_actions (
            connection_id, ozon_client_id, action_id, title, description, date_from, date_to,
            is_enabled, is_participating, participating_products_count, potential_products_count,
            action_type, raw_json, sync_token, synced_at
        ) VALUES (
            :connection_id, :cid, :action_id, :title, :description, :date_from, :date_to,
            :is_enabled, :is_participating, :participating_products_count, :potential_products_count,
            :action_type, :raw_json, :sync_token, :synced_at
        )
        ON DUPLICATE KEY UPDATE
            ozon_client_id = VALUES(ozon_client_id),
            title = VALUES(title),
            description = VALUES(description),
            date_from = VALUES(date_from),
            date_to = VALUES(date_to),
            is_enabled = VALUES(is_enabled),
            is_participating = VALUES(is_participating),
            participating_products_count = VALUES(participating_products_count),
            potential_products_count = VALUES(potential_products_count),
            action_type = VALUES(action_type),
            raw_json = VALUES(raw_json),
            sync_token = VALUES(sync_token),
            synced_at = VALUES(synced_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':cid' => $clientId,
        ':action_id' => (int)($item['id'] ?? 0),
        ':title' => trim((string)($item['title'] ?? '')),
        ':description' => trim((string)($item['description'] ?? '')) ?: null,
        ':date_from' => ozon_actions_normalize_datetime($item['date_from'] ?? null),
        ':date_to' => ozon_actions_normalize_datetime($item['date_to'] ?? null),
        ':is_enabled' => !empty($item['is_enabled']) ? 1 : 0,
        ':is_participating' => !empty($item['is_participating']) ? 1 : 0,
        ':participating_products_count' => (int)($item['participating_products_count'] ?? 0),
        ':potential_products_count' => (int)($item['potential_products_count'] ?? 0),
        ':action_type' => trim((string)($item['action_type'] ?? '')) ?: null,
        ':raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE),
        ':sync_token' => $syncToken,
        ':synced_at' => $syncedAt,
    ]);
}

function ozon_actions_upsert_product(PDO $pdo, int $connectionId, string $clientId, int $actionId, string $sourceType, array $item, ?string $offerId, string $syncToken, string $syncedAt): void
{
    $st = $pdo->prepare("
        INSERT INTO feedtools_ozon_action_products (
            connection_id, ozon_client_id, action_id, product_id, offer_id, source_type, price, action_price, max_action_price,
            add_mode, stock, min_stock, alert_max_action_price_failed, alert_max_action_price,
            current_boost, price_min_elastic, price_max_elastic, min_boost, max_boost,
            raw_json, sync_token, synced_at
        ) VALUES (
            :connection_id, :cid, :action_id, :product_id, :offer_id, :source_type, :price, :action_price, :max_action_price,
            :add_mode, :stock, :min_stock, :alert_failed, :alert_price,
            :current_boost, :price_min_elastic, :price_max_elastic, :min_boost, :max_boost,
            :raw_json, :sync_token, :synced_at
        )
        ON DUPLICATE KEY UPDATE
            ozon_client_id = VALUES(ozon_client_id),
            offer_id = VALUES(offer_id),
            price = VALUES(price),
            action_price = VALUES(action_price),
            max_action_price = VALUES(max_action_price),
            add_mode = VALUES(add_mode),
            stock = VALUES(stock),
            min_stock = VALUES(min_stock),
            alert_max_action_price_failed = VALUES(alert_max_action_price_failed),
            alert_max_action_price = VALUES(alert_max_action_price),
            current_boost = VALUES(current_boost),
            price_min_elastic = VALUES(price_min_elastic),
            price_max_elastic = VALUES(price_max_elastic),
            min_boost = VALUES(min_boost),
            max_boost = VALUES(max_boost),
            raw_json = VALUES(raw_json),
            sync_token = VALUES(sync_token),
            synced_at = VALUES(synced_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':connection_id' => $connectionId,
        ':cid' => $clientId,
        ':action_id' => $actionId,
        ':product_id' => (int)($item['id'] ?? 0),
        ':offer_id' => $offerId,
        ':source_type' => $sourceType,
        ':price' => ozon_actions_decimal($item['price'] ?? 0),
        ':action_price' => ozon_actions_decimal($item['action_price'] ?? 0),
        ':max_action_price' => ozon_actions_decimal($item['max_action_price'] ?? 0),
        ':add_mode' => trim((string)($item['add_mode'] ?? '')) ?: null,
        ':stock' => (int)($item['stock'] ?? 0),
        ':min_stock' => (int)($item['min_stock'] ?? 0),
        ':alert_failed' => !empty($item['alert_max_action_price_failed']) ? 1 : 0,
        ':alert_price' => ozon_actions_decimal($item['alert_max_action_price'] ?? 0),
        ':current_boost' => ozon_actions_decimal($item['current_boost'] ?? 0),
        ':price_min_elastic' => ozon_actions_decimal($item['price_min_elastic'] ?? 0),
        ':price_max_elastic' => ozon_actions_decimal($item['price_max_elastic'] ?? 0),
        ':min_boost' => ozon_actions_decimal($item['min_boost'] ?? 0),
        ':max_boost' => ozon_actions_decimal($item['max_boost'] ?? 0),
        ':raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE),
        ':sync_token' => $syncToken,
        ':synced_at' => $syncedAt,
    ]);
}

function ozon_actions_finalize_sync(PDO $pdo, int|string|null $scopeRef, string $syncToken, array $cfg = []): void
{
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $args] = ozon_products_scope_clause($scope);
    $args[] = $syncToken;
    $st = $pdo->prepare("DELETE FROM feedtools_ozon_actions WHERE {$whereSql} AND (sync_token IS NULL OR sync_token <> ?)");
    $st->execute($args);

    $args = ozon_products_scope_clause($scope)[1];
    $args[] = $syncToken;
    $st = $pdo->prepare("DELETE FROM feedtools_ozon_action_products WHERE {$whereSql} AND (sync_token IS NULL OR sync_token <> ?)");
    $st->execute($args);
}

function ozon_actions_sync_summary(int|string|null $scopeRef = null, array $cfg = []): array
{
    ozon_actions_tables_ensure();
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $args] = ozon_products_scope_clause($scope);

    $summary = [
        'actions_count' => 0,
        'products_count' => 0,
        'participating_count' => 0,
        'candidate_count' => 0,
        'last_synced_at' => null,
    ];

    $st = db()->prepare("
        SELECT COUNT(*) AS actions_count, MAX(synced_at) AS last_synced_at
        FROM feedtools_ozon_actions
        WHERE {$whereSql}
    ");
    $st->execute($args);
    $row = $st->fetch() ?: [];
    $summary['actions_count'] = (int)($row['actions_count'] ?? 0);
    $summary['last_synced_at'] = (string)($row['last_synced_at'] ?? '') ?: null;

    $st = db()->prepare("
        SELECT
            COUNT(*) AS products_count,
            SUM(CASE WHEN source_type = 'participating' THEN 1 ELSE 0 END) AS participating_count,
            SUM(CASE WHEN source_type = 'candidate' THEN 1 ELSE 0 END) AS candidate_count
        FROM feedtools_ozon_action_products
        WHERE {$whereSql}
    ");
    $st->execute($args);
    $row = $st->fetch() ?: [];
    $summary['products_count'] = (int)($row['products_count'] ?? 0);
    $summary['participating_count'] = (int)($row['participating_count'] ?? 0);
    $summary['candidate_count'] = (int)($row['candidate_count'] ?? 0);

    return $summary;
}

function ozon_actions_rows_for_offer(int|string|null $scopeRef, string $offerId, array $cfg = []): array
{
    ozon_actions_tables_ensure();
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $args] = ozon_products_scope_clause($scope, 'ap.connection_id', 'ap.ozon_client_id');
    $st = db()->prepare("
        SELECT
            ap.action_id,
            ap.offer_id,
            ap.product_id,
            ap.source_type,
            ap.price,
            ap.action_price,
            ap.max_action_price,
            ap.current_boost,
            ap.price_min_elastic,
            ap.price_max_elastic,
            ap.min_boost,
            ap.max_boost,
            ap.synced_at,
            a.title,
            a.date_from,
            a.date_to
        FROM feedtools_ozon_action_products ap
        JOIN feedtools_ozon_actions a
          ON a.connection_id = ap.connection_id
         AND a.action_id = ap.action_id
        WHERE {$whereSql}
          AND ap.offer_id = ?
        ORDER BY a.title ASC, ap.source_type ASC
    ");
    $args[] = $offerId;
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function ozon_actions_rows_for_offer_or_product(int|string|null $scopeRef, string $offerId, ?int $productId = null, array $cfg = []): array
{
    $rows = [];
    if ($offerId !== '') {
        $rows = ozon_actions_rows_for_offer($scopeRef, $offerId, $cfg);
    }

    if (!empty($rows) || $productId === null || $productId <= 0) {
        return $rows;
    }

    ozon_actions_tables_ensure();
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $args] = ozon_products_scope_clause($scope, 'ap.connection_id', 'ap.ozon_client_id');
    $st = db()->prepare("
        SELECT
            ap.action_id,
            ap.offer_id,
            ap.product_id,
            ap.source_type,
            ap.price,
            ap.action_price,
            ap.max_action_price,
            ap.current_boost,
            ap.price_min_elastic,
            ap.price_max_elastic,
            ap.min_boost,
            ap.max_boost,
            ap.synced_at,
            a.title,
            a.date_from,
            a.date_to
        FROM feedtools_ozon_action_products ap
        JOIN feedtools_ozon_actions a
          ON a.connection_id = ap.connection_id
         AND a.action_id = ap.action_id
        WHERE {$whereSql}
          AND ap.product_id = ?
        ORDER BY a.title ASC, ap.source_type ASC
    ");
    $args[] = $productId;
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function ozon_actions_rows_for_offers_or_products(int|string|null $scopeRef, array $offerIds, array $productIdsByOffer = [], array $cfg = []): array
{
    ozon_actions_tables_ensure();
    $scope = ozon_products_scope_from_ref($scopeRef, $cfg);
    [$whereSql, $scopeArgs] = ozon_products_scope_clause($scope, 'ap.connection_id', 'ap.ozon_client_id');

    $offerIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    $out = array_fill_keys($offerIds, []);
    if (!$offerIds) {
        return [];
    }

    $selectSql = "
        SELECT
            ap.action_id,
            ap.offer_id,
            ap.product_id,
            ap.source_type,
            ap.price,
            ap.action_price,
            ap.max_action_price,
            ap.current_boost,
            ap.price_min_elastic,
            ap.price_max_elastic,
            ap.min_boost,
            ap.max_boost,
            ap.synced_at,
            a.title,
            a.date_from,
            a.date_to
        FROM feedtools_ozon_action_products ap
        JOIN feedtools_ozon_actions a
          ON a.connection_id = ap.connection_id
         AND a.action_id = ap.action_id
        WHERE {$whereSql}
    ";

    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $st = db()->prepare($selectSql . "
          AND ap.offer_id IN ({$placeholders})
        ORDER BY a.title ASC, ap.source_type ASC
        ");
        $st->execute(array_merge($scopeArgs, $chunk));
        foreach ($st->fetchAll() ?: [] as $row) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId !== '' && array_key_exists($offerId, $out)) {
                $out[$offerId][] = $row;
            }
        }
    }

    $productToOffers = [];
    foreach ($offerIds as $offerId) {
        if (!empty($out[$offerId])) {
            continue;
        }
        $productId = (int)($productIdsByOffer[$offerId] ?? 0);
        if ($productId > 0) {
            $productToOffers[$productId][] = $offerId;
        }
    }
    if (!$productToOffers) {
        return $out;
    }

    foreach (array_chunk(array_keys($productToOffers), 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $st = db()->prepare($selectSql . "
          AND ap.product_id IN ({$placeholders})
        ORDER BY a.title ASC, ap.source_type ASC
        ");
        $st->execute(array_merge($scopeArgs, $chunk));
        foreach ($st->fetchAll() ?: [] as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            foreach ((array)($productToOffers[$productId] ?? []) as $offerId) {
                $out[$offerId][] = $row;
            }
        }
    }

    return $out;
}

function ozon_actions_activate_products(array $cfg, int $actionId, array $products): array
{
    if ($actionId <= 0) {
        throw new RuntimeException('Не удалось определить action_id для добавления в акцию.');
    }
    $normalized = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $productId = (int)($product['product_id'] ?? 0);
        $actionPrice = ozon_actions_price_to_rub((float)($product['action_price'] ?? 0));
        if ($productId <= 0 || $actionPrice <= 0) {
            continue;
        }
        $normalized[] = [
            'product_id' => $productId,
            'action_price' => $actionPrice,
        ];
    }
    if (!$normalized) {
        return ['result' => ['product_ids' => [], 'rejected' => []]];
    }
    $oz = ozon_cfg_or_fail($cfg);
    return ozon_post_json($oz, '/v1/actions/products/activate', [
        'action_id' => $actionId,
        'products' => $normalized,
    ]);
}

function ozon_actions_price_to_rub(float $value): int
{
    if ($value <= 0 || !is_finite($value)) {
        return 0;
    }
    return max(1, (int)ceil($value - 0.00001));
}

function ozon_actions_deactivate_products(array $cfg, int $actionId, array $productIds): array
{
    if ($actionId <= 0) {
        throw new RuntimeException('Не удалось определить action_id для удаления из акции.');
    }
    $normalized = array_values(array_filter(array_map(static fn($v) => (int)$v, $productIds), static fn(int $v): bool => $v > 0));
    if (!$normalized) {
        return ['result' => ['product_ids' => [], 'rejected' => []]];
    }
    $oz = ozon_cfg_or_fail($cfg);
    return ozon_post_json($oz, '/v1/actions/products/deactivate', [
        'action_id' => $actionId,
        'product_ids' => $normalized,
    ]);
}

function ozon_actions_normalize_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') return null;
    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function ozon_actions_decimal($value): float
{
    return round((float)$value, 2);
}
