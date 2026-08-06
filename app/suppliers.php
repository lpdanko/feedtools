<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function suppliers_table_has_column(PDO $pdo, string $table, string $column): bool
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
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    $cache[$key] = (int)$st->fetchColumn() > 0;
    return $cache[$key];
}

function suppliers_table_has_index(PDO $pdo, string $table, string $indexName): bool
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
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    $cache[$key] = (int)$st->fetchColumn() > 0;
    return $cache[$key];
}

function suppliers_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    $cache[$table] = (int)$st->fetchColumn() > 0;
    return $cache[$table];
}

function suppliers_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_suppliers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            supplier_code VARCHAR(64) NOT NULL DEFAULT '',
            feed_url TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME NULL,
            archived_by VARCHAR(190) NULL DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            notes TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active_sort (is_active, sort_order, name, id),
            KEY idx_archived_active_sort (is_archived, is_active, sort_order, name, id),
            KEY idx_supplier_code (supplier_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!suppliers_table_has_column($pdo, 'feedtools_suppliers', 'is_archived')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    }
    if (!suppliers_table_has_column($pdo, 'feedtools_suppliers', 'archived_at')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD COLUMN archived_at DATETIME NULL AFTER is_archived");
    }
    if (!suppliers_table_has_column($pdo, 'feedtools_suppliers', 'archived_by')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD COLUMN archived_by VARCHAR(190) NULL DEFAULT NULL AFTER archived_at");
    }
    if (!suppliers_table_has_index($pdo, 'feedtools_suppliers', 'idx_active_sort')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD KEY idx_active_sort (is_active, sort_order, name, id)");
    }
    if (!suppliers_table_has_index($pdo, 'feedtools_suppliers', 'idx_archived_active_sort')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD KEY idx_archived_active_sort (is_archived, is_active, sort_order, name, id)");
    }
    if (!suppliers_table_has_index($pdo, 'feedtools_suppliers', 'idx_supplier_code')) {
        $pdo->exec("ALTER TABLE feedtools_suppliers ADD KEY idx_supplier_code (supplier_code)");
    }

    $done = true;
}

function suppliers_normalize_code(string $code): string
{
    return preg_replace('~[^A-Za-z0-9_-]+~u', '', trim($code)) ?: '';
}

function suppliers_default(): array
{
    return [
        'id' => 0,
        'name' => '',
        'supplier_code' => '',
        'feed_url' => '',
        'is_active' => 1,
        'is_archived' => 0,
        'archived_at' => null,
        'archived_by' => null,
        'sort_order' => 100,
        'notes' => '',
    ];
}

function suppliers_normalize_input(array $input): array
{
    $row = suppliers_default();
    $row['id'] = max(0, (int)($input['id'] ?? 0));
    $row['name'] = trim((string)($input['name'] ?? ''));
    $row['supplier_code'] = suppliers_normalize_code((string)($input['supplier_code'] ?? ''));
    $row['feed_url'] = trim((string)($input['feed_url'] ?? ''));
    $row['is_active'] = 1;
    $row['sort_order'] = max(1, (int)($input['sort_order'] ?? 100));
    $row['notes'] = trim((string)($input['notes'] ?? ''));

    if ($row['name'] === '') {
        throw new RuntimeException('Укажи название поставщика.');
    }
    if ($row['supplier_code'] === '') {
        throw new RuntimeException('Укажи код поставщика.');
    }
    if ($row['feed_url'] === '') {
        throw new RuntimeException('Укажи ссылку на источник данных поставщика.');
    }
    if (!preg_match('~^https?://~i', $row['feed_url'])) {
        throw new RuntimeException('Ссылка на источник данных должна начинаться с http:// или https://.');
    }

    return $row;
}

function suppliers_get(int $id, array $cfg = []): ?array
{
    suppliers_table_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_suppliers WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? array_replace(suppliers_default(), $row) : null;
}

function suppliers_list(bool $includeInactive = true, array $cfg = [], bool $includeArchived = false): array
{
    suppliers_table_ensure($cfg);
    $archiveWhere = $includeArchived ? '' : 'WHERE is_archived = 0';
    if ($includeInactive) {
        $st = db()->query("
            SELECT *
            FROM feedtools_suppliers
            {$archiveWhere}
            ORDER BY is_archived ASC, is_active DESC, sort_order ASC, name ASC, id ASC
        ");
        return $st->fetchAll() ?: [];
    }

    $where = $includeArchived ? 'WHERE is_active = 1' : 'WHERE is_active = 1 AND is_archived = 0';
    $st = db()->query("
        SELECT *
        FROM feedtools_suppliers
        {$where}
        ORDER BY is_archived ASC, sort_order ASC, name ASC, id ASC
    ");
    return $st->fetchAll() ?: [];
}

function suppliers_archive_list(array $cfg = []): array
{
    suppliers_table_ensure($cfg);
    $st = db()->query("
        SELECT *
        FROM feedtools_suppliers
        WHERE is_archived = 1
        ORDER BY archived_at DESC, sort_order ASC, name ASC, id ASC
    ");
    return $st->fetchAll() ?: [];
}

function suppliers_set_archived(int $supplierId, bool $archived, ?string $actor = null, array $cfg = []): void
{
    suppliers_table_ensure($cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Не удалось определить поставщика.');
    }
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    if ($archived) {
        $st = db()->prepare("
            UPDATE feedtools_suppliers
            SET is_archived = 1,
                archived_at = COALESCE(archived_at, NOW()),
                archived_by = ?,
                updated_by = ?
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$actor, $actor, $supplierId]);
        return;
    }

    $st = db()->prepare("
        UPDATE feedtools_suppliers
        SET is_archived = 0,
            archived_at = NULL,
            archived_by = NULL,
            updated_by = ?
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$actor, $supplierId]);
}

function suppliers_code_exists(string $supplierCode, int $excludeId = 0, array $cfg = []): bool
{
    suppliers_table_ensure($cfg);
    $supplierCode = suppliers_normalize_code($supplierCode);
    if ($supplierCode === '') {
        return false;
    }
    $st = db()->prepare("
        SELECT id
        FROM feedtools_suppliers
        WHERE supplier_code = ?
          AND id <> ?
        LIMIT 1
    ");
    $st->execute([$supplierCode, max(0, $excludeId)]);
    return (int)($st->fetchColumn() ?: 0) > 0;
}

function suppliers_save(array $input, ?string $actor = null, array $cfg = []): int
{
    suppliers_table_ensure($cfg);
    $row = suppliers_normalize_input($input);

    if (suppliers_code_exists((string)$row['supplier_code'], (int)$row['id'], $cfg)) {
        throw new RuntimeException('Поставщик с таким кодом уже существует: ' . (string)$row['supplier_code']);
    }

    if ((int)$row['id'] > 0) {
        $st = db()->prepare("
            UPDATE feedtools_suppliers
            SET name = ?, supplier_code = ?, feed_url = ?, is_active = ?, sort_order = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        $st->execute([
            $row['name'],
            $row['supplier_code'],
            $row['feed_url'],
            $row['is_active'],
            $row['sort_order'],
            $row['notes'],
            $actor,
            $row['id'],
        ]);
        return (int)$row['id'];
    }

    $st = db()->prepare("
        INSERT INTO feedtools_suppliers (
            name, supplier_code, feed_url, is_active, sort_order, notes, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        $row['name'],
        $row['supplier_code'],
        $row['feed_url'],
        $row['is_active'],
        $row['sort_order'],
        $row['notes'],
        $actor,
        $actor,
    ]);
    return (int)db()->lastInsertId();
}

function suppliers_reference_counts(int $supplierId, array $cfg = []): array
{
    suppliers_table_ensure($cfg);
    $supplierId = max(0, $supplierId);
    $counts = [
        'price_profiles' => 0,
        'stock_profiles' => 0,
        'products' => 0,
    ];
    if ($supplierId <= 0) {
        return $counts;
    }

    $pdo = db();
    if (suppliers_table_exists($pdo, 'feedtools_ozon_price_feeds') && suppliers_table_has_column($pdo, 'feedtools_ozon_price_feeds', 'supplier_id')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM feedtools_ozon_price_feeds WHERE supplier_id = ?");
        $st->execute([$supplierId]);
        $counts['price_profiles'] = (int)$st->fetchColumn();
    }
    if (suppliers_table_exists($pdo, 'feedtools_marketplace_stock_profile_feeds')) {
        if (suppliers_table_has_column($pdo, 'feedtools_marketplace_stock_profile_feeds', 'supplier_id')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM feedtools_marketplace_stock_profile_feeds WHERE supplier_id = ? OR (supplier_id = 0 AND feed_id = ?)");
            $st->execute([$supplierId, $supplierId]);
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM feedtools_marketplace_stock_profile_feeds WHERE feed_id = ?");
            $st->execute([$supplierId]);
        }
        $counts['stock_profiles'] = (int)$st->fetchColumn();
    }
    if (suppliers_table_exists($pdo, 'feedtools_supplier_products')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM feedtools_supplier_products WHERE supplier_id = ?");
        $st->execute([$supplierId]);
        $counts['products'] = (int)$st->fetchColumn();
    }

    return $counts;
}

function suppliers_delete_product_dataset_if_exists(int $supplierId): void
{
    $pdo = db();
    if (!suppliers_table_exists($pdo, 'feedtools_supplier_product_meta')) {
        return;
    }

    $st = $pdo->prepare("SELECT dataset_id FROM feedtools_supplier_product_meta WHERE supplier_id = ? LIMIT 1");
    $st->execute([$supplierId]);
    $datasetId = (int)($st->fetchColumn() ?: 0);

    $pictureUrls = [];
    if (function_exists('supplier_products_picture_urls_for_supplier') && suppliers_table_exists($pdo, 'feedtools_supplier_products')) {
        $pictureUrls = supplier_products_picture_urls_for_supplier($pdo, $supplierId);
    }

    if (suppliers_table_exists($pdo, 'feedtools_supplier_product_fields')) {
        $delFields = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE supplier_id = ?");
        $delFields->execute([$supplierId]);
    }

    if (suppliers_table_exists($pdo, 'feedtools_supplier_products')) {
        $delProducts = $pdo->prepare("DELETE FROM feedtools_supplier_products WHERE supplier_id = ?");
        $delProducts->execute([$supplierId]);
    }

    $delMeta = $pdo->prepare("DELETE FROM feedtools_supplier_product_meta WHERE supplier_id = ?");
    $delMeta->execute([$supplierId]);

    if ($datasetId > 0 && suppliers_table_exists($pdo, 'feedtools_datasets')) {
        $path = '';
        $ds = $pdo->prepare("SELECT stored_path FROM feedtools_datasets WHERE id = ? LIMIT 1");
        $ds->execute([$datasetId]);
        $path = (string)($ds->fetchColumn() ?: '');

        $delDs = $pdo->prepare("DELETE FROM feedtools_datasets WHERE id = ?");
        $delDs->execute([$datasetId]);

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    if ($pictureUrls && function_exists('supplier_products_delete_local_images_if_unreferenced')) {
        supplier_products_delete_local_images_if_unreferenced($pictureUrls);
    }
}

function suppliers_delete(int $supplierId, array $cfg = []): void
{
    suppliers_table_ensure($cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Не удалось определить поставщика для удаления.');
    }
    $counts = suppliers_reference_counts($supplierId, $cfg);
    if (($counts['price_profiles'] ?? 0) > 0 || ($counts['stock_profiles'] ?? 0) > 0) {
        throw new RuntimeException('Поставщик уже используется в Price Tool или Stocks Tool. Сначала отвяжи его от профилей.');
    }
    suppliers_delete_product_dataset_if_exists($supplierId);
    $st = db()->prepare("DELETE FROM feedtools_suppliers WHERE id = ? LIMIT 1");
    $st->execute([$supplierId]);
}

function suppliers_find_for_legacy_feed(string $name, string $supplierCode, string $feedUrl, array $cfg = []): int
{
    suppliers_table_ensure($cfg);
    $name = trim($name);
    $supplierCode = suppliers_normalize_code($supplierCode);
    $feedUrl = trim($feedUrl);

    if ($supplierCode !== '' && $feedUrl !== '') {
        $st = db()->prepare("
            SELECT id
            FROM feedtools_suppliers
            WHERE supplier_code = ?
              AND feed_url = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$supplierCode, $feedUrl]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    if ($supplierCode === '' && $feedUrl !== '' && $name !== '') {
        $st = db()->prepare("
            SELECT id
            FROM feedtools_suppliers
            WHERE supplier_code = ''
              AND feed_url = ?
              AND name = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$feedUrl, $name]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    $insert = db()->prepare("
        INSERT INTO feedtools_suppliers (
            name, supplier_code, feed_url, is_active, sort_order, notes, created_by, updated_by
        ) VALUES (?, ?, ?, 1, 100, ?, 'migration', 'migration')
    ");
    $insert->execute([
        $name !== '' ? $name : ('Поставщик #' . substr(sha1($feedUrl . $supplierCode), 0, 8)),
        $supplierCode,
        $feedUrl,
        '',
    ]);
    return (int)db()->lastInsertId();
}

function suppliers_migrate_from_price_feeds(array $cfg = []): void
{
    suppliers_table_ensure($cfg);
    $pdo = db();
    if (!suppliers_table_exists($pdo, 'feedtools_ozon_price_feeds')
        || !suppliers_table_has_column($pdo, 'feedtools_ozon_price_feeds', 'supplier_id')) {
        return;
    }

    $st = $pdo->query("
        SELECT id, name, supplier_code, feed_url
        FROM feedtools_ozon_price_feeds
        WHERE supplier_id = 0
          AND feed_url <> ''
        ORDER BY id ASC
    ");
    foreach ($st->fetchAll() ?: [] as $row) {
        $feedId = (int)($row['id'] ?? 0);
        if ($feedId <= 0) {
            continue;
        }
        $supplierId = suppliers_find_for_legacy_feed(
            (string)($row['name'] ?? ''),
            (string)($row['supplier_code'] ?? ''),
            (string)($row['feed_url'] ?? ''),
            $cfg
        );
        if ($supplierId <= 0) {
            continue;
        }
        $up = $pdo->prepare("UPDATE feedtools_ozon_price_feeds SET supplier_id = ? WHERE id = ? AND supplier_id = 0");
        $up->execute([$supplierId, $feedId]);
    }
}

function suppliers_as_feed_row(array $supplier): array
{
    $id = (int)($supplier['id'] ?? 0);
    $isArchived = (int)($supplier['is_archived'] ?? 0) === 1;
    return [
        'id' => $id,
        'supplier_id' => $id,
        'name' => (string)($supplier['name'] ?? ''),
        'supplier_name' => (string)($supplier['name'] ?? ''),
        'feed_url' => (string)($supplier['feed_url'] ?? ''),
        'supplier_feed_url' => (string)($supplier['feed_url'] ?? ''),
        'supplier_code' => (string)($supplier['supplier_code'] ?? ''),
        'cost_tag' => '',
        'is_active' => (!$isArchived && (int)($supplier['is_active'] ?? 1) === 1) ? 1 : 0,
        'is_archived' => $isArchived ? 1 : 0,
        'sort_order' => (int)($supplier['sort_order'] ?? 100),
        'source_kind' => 'supplier',
    ];
}

function suppliers_feed_count_cache_path(): string
{
    return dirname(__DIR__) . '/storage/cache/supplier_feed_counts.json';
}

function suppliers_apply_supplier_code(string $offerId, string $supplierCode): string
{
    $offerId = trim($offerId);
    $supplierCode = trim($supplierCode);
    if ($offerId === '' || $supplierCode === '') {
        return $offerId;
    }
    if (strpos($offerId, '__') !== false) {
        return $offerId;
    }
    return $offerId . '__' . $supplierCode;
}

function suppliers_offer_count_cached(array $supplier, int $ttlSeconds = 3600, bool $allowRemoteRefresh = true): ?int
{
    $supplierId = (int)($supplier['supplier_id'] ?? $supplier['id'] ?? 0);
    $feedUrl = trim((string)($supplier['feed_url'] ?? $supplier['supplier_feed_url'] ?? ''));
    if ($feedUrl === '') {
        return null;
    }

    if ($supplierId > 0 && suppliers_table_exists(db(), 'feedtools_supplier_products')) {
        $st = db()->prepare("SELECT COUNT(DISTINCT offer_id) FROM feedtools_supplier_products WHERE supplier_id = ? AND offer_id <> ''");
        $st->execute([$supplierId]);
        $dbCount = (int)$st->fetchColumn();
        if ($dbCount > 0) {
            return $dbCount;
        }
    }

    $cachePath = suppliers_feed_count_cache_path();
    $cache = [];
    if (is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }

    $cacheKey = $supplierId > 0 ? (string)$supplierId : sha1($feedUrl);
    $signature = sha1(json_encode([
        'feed_url' => $feedUrl,
        'supplier_code' => (string)($supplier['supplier_code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $entry = is_array($cache[$cacheKey] ?? null) ? $cache[$cacheKey] : null;
    $now = time();

    if (
        $entry
        && (string)($entry['signature'] ?? '') === $signature
        && (int)($entry['updated_at'] ?? 0) > ($now - $ttlSeconds)
        && array_key_exists('count', $entry)
    ) {
        return (int)$entry['count'];
    }

    if (!$allowRemoteRefresh || !function_exists('ozon_price_feed_fetch_remote_xml')) {
        if ($entry && (string)($entry['signature'] ?? '') === $signature && array_key_exists('count', $entry)) {
            return (int)$entry['count'];
        }
        return null;
    }

    try {
        $download = ozon_price_feed_fetch_remote_xml($feedUrl);
        $reader = new XMLReader();
        if (!$reader->open((string)$download['path'], null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Не удалось открыть XML-фид поставщика.');
        }
        $counted = [];
        $supplierCode = (string)($supplier['supplier_code'] ?? '');
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') {
                continue;
            }
            $offerId = suppliers_apply_supplier_code(trim((string)$reader->getAttribute('id')), $supplierCode);
            if ($offerId !== '') {
                $counted[$offerId] = true;
            }
        }
        $reader->close();
        @unlink((string)$download['path']);

        $cache[$cacheKey] = [
            'signature' => $signature,
            'count' => count($counted),
            'updated_at' => $now,
        ];
        @file_put_contents($cachePath, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return count($counted);
    } catch (Throwable $e) {
        if (isset($download['path'])) {
            @unlink((string)$download['path']);
        }
        if ($entry && array_key_exists('count', $entry)) {
            return (int)$entry['count'];
        }
        return null;
    }
}
