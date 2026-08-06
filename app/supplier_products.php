<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/xml_scan.php';
require_once __DIR__ . '/text_sanitize.php';
require_once __DIR__ . '/BundleOffer.php';
require_once __DIR__ . '/taxonomy/GlobalAttributeExclusions.php';

const SUPPLIER_PRODUCTS_STORAGE_KIND = 'supplier_products_db';
const SUPPLIER_PRODUCTS_FEATURE_MAX = 3;

function supplier_products_standard_field_labels(): array
{
    return [
        'purchase_price' => 'Закупочная цена',
        'brand' => 'Бренд',
        'brand_ozon' => 'Бренд Ozon',
        'brand_wb' => 'Бренд WB',
        'short_name' => 'Короткое название',
        'tnved_code' => 'Код ТН ВЭД',
        'weight' => 'Вес',
        'length' => 'Длина',
        'width' => 'Ширина',
        'height' => 'Высота',
        'stock' => 'Остаток',
    ];
}

function supplier_products_standard_field_keys(): array
{
    return array_keys(supplier_products_standard_field_labels());
}

function supplier_products_is_standard_field_name(string $name): bool
{
    return array_key_exists($name, supplier_products_standard_field_labels());
}

function supplier_products_standard_source_tag_names(): array
{
    return [
        'price_original' => true,
        'brand' => true,
        'vendor' => true,
        'weight' => true,
        'dimensions' => true,
        'count' => true,
        'stock' => true,
        'quantity' => true,
    ];
}

function supplier_products_standard_empty_values(): array
{
    return array_fill_keys(supplier_products_standard_field_keys(), '');
}

function supplier_products_feature_field_name(int $index): string
{
    $index = max(1, min(SUPPLIER_PRODUCTS_FEATURE_MAX, $index));
    return 'feature_' . $index;
}

function supplier_products_feature_field_index(string $name): int
{
    $name = trim($name);
    if (preg_match('~^feature[_-]?([1-3])$~i', $name, $m)) {
        return (int)$m[1];
    }
    if (preg_match('~^(?:фишка|feature)\s*([1-3])$~iu', $name, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function supplier_products_is_feature_source_field_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (supplier_products_feature_field_index($name) > 0) {
        return true;
    }
    $norm = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $norm = str_replace('ё', 'е', $norm);
    $norm = preg_replace('~[^\p{L}\p{N}]+~u', ' ', (string)$norm);
    $norm = trim(preg_replace('~\s+~u', ' ', (string)$norm));
    return in_array($norm, ['feature', 'features', 'фишка', 'фишки'], true);
}

function supplier_products_normalize_feature_value(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~\s+~u', ' ', $value);
    return trim((string)$value);
}

function supplier_products_next_feature_field_name(PDO $pdo, int $productId): string
{
    $st = $pdo->prepare("
        SELECT field_name
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'feature'
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId]);

    $used = [];
    $count = 0;
    while ($name = $st->fetchColumn()) {
        $count++;
        $index = supplier_products_feature_field_index((string)$name);
        if ($index > 0) {
            $used[$index] = true;
        }
    }
    if ($count >= SUPPLIER_PRODUCTS_FEATURE_MAX) {
        throw new RuntimeException('У товара может быть не больше 3 фишек.');
    }
    for ($index = 1; $index <= SUPPLIER_PRODUCTS_FEATURE_MAX; $index++) {
        if (empty($used[$index])) {
            return supplier_products_feature_field_name($index);
        }
    }
    return supplier_products_feature_field_name($count + 1);
}

function supplier_products_standard_fields_from_values(array $values, int $sortStart = 10): array
{
    $brand = trim((string)($values['brand'] ?? ''));
    if ($brand !== '') {
        if (trim((string)($values['brand_ozon'] ?? '')) === '') {
            $values['brand_ozon'] = $brand;
        }
        if (trim((string)($values['brand_wb'] ?? '')) === '') {
            $values['brand_wb'] = $brand;
        }
    }

    $out = [];
    $sort = $sortStart;
    foreach (supplier_products_standard_field_keys() as $key) {
        $out[] = [
            'field_kind' => 'standard',
            'field_name' => $key,
            'field_value' => (string)($values[$key] ?? ''),
            'sort_order' => $sort,
        ];
        $sort += 10;
    }
    return $out;
}

function supplier_products_table_has_column(PDO $pdo, string $table, string $column): bool
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

function supplier_products_table_has_index(PDO $pdo, string $table, string $indexName): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    return (int)$st->fetchColumn() > 0;
}

function supplier_products_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!supplier_products_table_has_column($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
}

function supplier_products_add_index_if_missing(PDO $pdo, string $table, string $indexName, string $definition): void
{
    if (!supplier_products_table_has_index($pdo, $table, $indexName)) {
        $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
    }
}

function supplier_products_tables_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    suppliers_table_ensure($cfg);
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_product_meta (
            supplier_id BIGINT UNSIGNED NOT NULL,
            dataset_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_url TEXT NULL,
            source_sha256 VARCHAR(64) NOT NULL DEFAULT '',
            source_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            categories_json LONGTEXT NULL,
            offers_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            warnings_json LONGTEXT NULL,
            last_imported_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (supplier_id),
            KEY idx_dataset_id (dataset_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id BIGINT UNSIGNED NOT NULL,
            offer_key VARCHAR(191) NOT NULL,
            offer_id VARCHAR(191) NOT NULL DEFAULT '',
            raw_hash VARCHAR(64) NOT NULL DEFAULT '',
            sort_order BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name TEXT NULL,
            vendor_code VARCHAR(191) NOT NULL DEFAULT '',
            category_id VARCHAR(191) NOT NULL DEFAULT '',
            category_path TEXT NULL,
            ozon_category VARCHAR(191) NOT NULL DEFAULT '',
            wb_category VARCHAR(191) NOT NULL DEFAULT '',
            brand VARCHAR(191) NOT NULL DEFAULT '',
            description_html LONGTEXT NULL,
            count_qty BIGINT NOT NULL DEFAULT 0,
            stock_qty BIGINT NOT NULL DEFAULT 0,
            price_original DECIMAL(18,4) NULL DEFAULT NULL,
            marketplace_enabled TINYINT(1) NOT NULL DEFAULT 1,
            stock_modifier INT NOT NULL DEFAULT 0,
            price_modifier VARCHAR(64) NOT NULL DEFAULT '',
            pictures_json LONGTEXT NULL,
            params_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_supplier_offer_key (supplier_id, offer_key),
            KEY idx_supplier_sort (supplier_id, sort_order, id),
            KEY idx_supplier_offer_id (supplier_id, offer_id),
            KEY idx_supplier_vendor_code (supplier_id, vendor_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!supplier_products_table_has_column($pdo, 'feedtools_supplier_products', 'description_html')) {
        $pdo->exec("ALTER TABLE feedtools_supplier_products ADD COLUMN description_html LONGTEXT NULL AFTER brand");
    }
    if (!supplier_products_table_has_column($pdo, 'feedtools_supplier_product_meta', 'categories_json')) {
        $pdo->exec("ALTER TABLE feedtools_supplier_product_meta ADD COLUMN categories_json LONGTEXT NULL AFTER source_bytes");
    }
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'dataset_id', 'dataset_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'source_url', 'source_url TEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'source_sha256', "source_sha256 VARCHAR(64) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'source_bytes', 'source_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'offers_count', 'offers_count BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'warnings_json', 'warnings_json LONGTEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_meta', 'last_imported_at', 'last_imported_at DATETIME NULL');

    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'offer_key', "offer_key VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'offer_id', "offer_id VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'raw_hash', "raw_hash VARCHAR(64) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'sort_order', 'sort_order BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'name', 'name TEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'vendor_code', "vendor_code VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'category_id', "category_id VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'category_path', 'category_path TEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'ozon_category', "ozon_category VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'wb_category', "wb_category VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'brand', "brand VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'count_qty', 'count_qty BIGINT NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'stock_qty', 'stock_qty BIGINT NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'price_original', 'price_original DECIMAL(18,4) NULL DEFAULT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'marketplace_enabled', 'marketplace_enabled TINYINT(1) NOT NULL DEFAULT 1');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'stock_modifier', 'stock_modifier INT NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'price_modifier', "price_modifier VARCHAR(64) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'pictures_json', 'pictures_json LONGTEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_products', 'params_json', 'params_json LONGTEXT NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_product_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            field_kind VARCHAR(32) NOT NULL DEFAULT 'tag',
            field_name VARCHAR(191) NOT NULL DEFAULT '',
            field_value LONGTEXT NULL,
            sort_order BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_supplier_product (supplier_id, product_id),
            KEY idx_product_kind_sort (product_id, field_kind, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'supplier_id', 'supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'product_id', 'product_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'field_kind', "field_kind VARCHAR(32) NOT NULL DEFAULT 'tag'");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'field_name', "field_name VARCHAR(191) NOT NULL DEFAULT ''");
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'field_value', 'field_value LONGTEXT NULL');
    supplier_products_add_column_if_missing($pdo, 'feedtools_supplier_product_fields', 'sort_order', 'sort_order BIGINT UNSIGNED NOT NULL DEFAULT 0');

    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_meta', 'idx_dataset_id', 'KEY idx_dataset_id (dataset_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'uniq_supplier_offer_key', 'UNIQUE KEY uniq_supplier_offer_key (supplier_id, offer_key)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_sort', 'KEY idx_supplier_sort (supplier_id, sort_order, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_offer_id', 'KEY idx_supplier_offer_id (supplier_id, offer_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_vendor_code', 'KEY idx_supplier_vendor_code (supplier_id, vendor_code)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_updated', 'KEY idx_supplier_updated (supplier_id, updated_at)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_ozon_category', 'KEY idx_supplier_ozon_category (supplier_id, ozon_category)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_wb_category', 'KEY idx_supplier_wb_category (supplier_id, wb_category)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_category_id', 'KEY idx_supplier_category_id (supplier_id, category_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_category_path', 'KEY idx_supplier_category_path (supplier_id, category_path(191))');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_brand', 'KEY idx_supplier_brand (supplier_id, brand)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_price', 'KEY idx_supplier_price (supplier_id, price_original, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_stock', 'KEY idx_supplier_stock (supplier_id, stock_qty, count_qty, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_products', 'idx_supplier_marketplace_enabled', 'KEY idx_supplier_marketplace_enabled (supplier_id, marketplace_enabled, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_supplier_product', 'KEY idx_supplier_product (supplier_id, product_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_product_kind_sort', 'KEY idx_product_kind_sort (product_id, field_kind, sort_order, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_product_kind_name', 'KEY idx_product_kind_name (product_id, field_kind, field_name, id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_supplier_kind_name', 'KEY idx_supplier_kind_name (supplier_id, field_kind, field_name, product_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_supplier_kind_name_value', 'KEY idx_supplier_kind_name_value (supplier_id, field_kind, field_name, field_value(191), product_id)');
    supplier_products_add_index_if_missing($pdo, 'feedtools_supplier_product_fields', 'idx_supplier_updated', 'KEY idx_supplier_updated (supplier_id, updated_at)');

    $done = true;
}

function supplier_products_ensure_writable_dir(string $dir, string $errorMessage): string
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException($errorMessage);
    }
    @chmod($dir, 0775);
    clearstatcache(true, $dir);
    if (!is_writable($dir)) {
        throw new RuntimeException($errorMessage . ' Папка существует, но недоступна для записи: ' . $dir);
    }
    return $dir;
}

function supplier_products_image_storage_dir(array $cfg): string
{
    $uploadsDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? (dirname(__DIR__) . '/storage/uploads')), '/\\');
    $dir = $uploadsDir . '/supplier_product_images';
    return supplier_products_ensure_writable_dir($dir, 'Не удалось создать папку для фотографий товаров поставщика.');
}

function supplier_products_public_base_url(array $cfg = []): string
{
    $base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
    if ($base !== '') {
        return $base;
    }
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    return ($https ? 'https' : 'http') . '://' . $host;
}

function supplier_products_image_url(string $relativePath, array $cfg = []): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $path = '/supplier_product_image.php?f=' . rawurlencode($relativePath);
    $base = supplier_products_public_base_url($cfg);
    return $base !== '' ? ($base . $path) : $path;
}

function supplier_products_normalize_local_image_relative_path(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    $relativePath = preg_replace('~/+~', '/', $relativePath) ?: '';
    if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
        return '';
    }
    $segments = [];
    foreach (explode('/', $relativePath) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return '';
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

function supplier_products_public_image_mirror_root(array $cfg = []): string
{
    return dirname(__DIR__) . '/public/supplier-product-images';
}

function supplier_products_remote_images_enabled(array $cfg = []): bool
{
    $remote = (array)($cfg['remote_images'] ?? []);
    if (empty($remote['enabled'])) {
        return false;
    }
    $baseUrl = trim((string)($remote['base_url'] ?? ''));
    $driver = strtolower(trim((string)($remote['driver'] ?? '')));
    return $baseUrl !== '' && in_array($driver, ['ftp'], true);
}

function supplier_products_remote_image_path_prefix(array $cfg = []): string
{
    $prefix = str_replace('\\', '/', trim((string)($cfg['remote_images']['path_prefix'] ?? ''), "/ \t\n\r\0\x0B"));
    if ($prefix === '' || str_contains($prefix, '../') || str_contains($prefix, '..\\')) {
        return '';
    }
    $segments = [];
    foreach (explode('/', $prefix) as $segment) {
        $segment = trim($segment);
        if ($segment !== '' && $segment !== '.' && $segment !== '..') {
            $segments[] = $segment;
        }
    }
    return implode('/', $segments);
}

function supplier_products_remote_image_url(string $relativePath, array $cfg = []): string
{
    if (!supplier_products_remote_images_enabled($cfg)) {
        return '';
    }
    $base = rtrim(trim((string)($cfg['remote_images']['base_url'] ?? '')), '/');
    if ($base === '') {
        return '';
    }
    $relativePath = supplier_products_normalize_local_image_relative_path($relativePath);
    if ($relativePath === '') {
        return '';
    }
    $prefix = supplier_products_remote_image_path_prefix($cfg);
    $path = $prefix !== '' ? ($prefix . '/' . $relativePath) : $relativePath;
    $segments = array_map('rawurlencode', explode('/', $path));
    return $base . '/' . implode('/', $segments);
}

function supplier_products_remote_image_relative_from_url(string $url, array $cfg = []): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = str_replace('\\', '/', rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? '')));
    if ($path === '') {
        return '';
    }

    $basePath = '';
    $baseUrl = trim((string)($cfg['remote_images']['base_url'] ?? ''));
    if ($baseUrl !== '') {
        $basePath = str_replace('\\', '/', rawurldecode((string)(parse_url($baseUrl, PHP_URL_PATH) ?? '')));
    }

    $candidates = [];
    $prefix = supplier_products_remote_image_path_prefix($cfg);
    if ($basePath !== '') {
        $basePath = '/' . trim($basePath, '/');
        $remotePrefix = trim($basePath . '/' . ($prefix !== '' ? $prefix . '/' : ''), '/');
        if ($remotePrefix !== '') {
            $candidates[] = $remotePrefix;
        }
    }
    if ($prefix !== '') {
        $candidates[] = trim($prefix, '/');
    }
    $candidates[] = 'feedtools-images/feedtools/blur';
    $candidates[] = 'feedtools/blur';

    $pathTrimmed = trim($path, '/');
    foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
        $candidate = trim((string)$candidate, '/');
        if ($candidate !== '' && ($pathTrimmed === $candidate || str_starts_with($pathTrimmed, $candidate . '/'))) {
            $relative = substr($pathTrimmed, strlen($candidate));
            return supplier_products_normalize_local_image_relative_path((string)$relative);
        }
    }

    return '';
}

function supplier_products_ftp_chdir_mkdirs($ftp, string $dir): bool
{
    $dir = str_replace('\\', '/', trim($dir));
    if ($dir === '') {
        return true;
    }

    $start = @ftp_pwd($ftp);
    if (str_starts_with($dir, '/')) {
        @ftp_chdir($ftp, '/');
    }

    foreach (explode('/', trim($dir, '/')) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.') {
            continue;
        }
        if (@ftp_chdir($ftp, $part)) {
            continue;
        }
        if (!@ftp_mkdir($ftp, $part) && !@ftp_chdir($ftp, $part)) {
            if (is_string($start) && $start !== '') {
                @ftp_chdir($ftp, $start);
            }
            return false;
        }
        if (!@ftp_chdir($ftp, $part)) {
            if (is_string($start) && $start !== '') {
                @ftp_chdir($ftp, $start);
            }
            return false;
        }
    }
    return true;
}

function supplier_products_upload_remote_image_ftp(string $src, string $relativePath, array $cfg = []): bool
{
    if (!is_file($src) || !function_exists('ftp_connect')) {
        return false;
    }
    $remote = (array)($cfg['remote_images'] ?? []);
    $ftpCfg = (array)($remote['ftp'] ?? []);
    $host = trim((string)($ftpCfg['host'] ?? ''));
    $user = trim((string)($ftpCfg['user'] ?? ''));
    $pass = (string)($ftpCfg['pass'] ?? '');
    $rootDir = str_replace('\\', '/', trim((string)($ftpCfg['root_dir'] ?? '')));
    if ($host === '' || $user === '' || $pass === '' || $rootDir === '') {
        return false;
    }

    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
        return false;
    }

    $port = max(1, (int)($ftpCfg['port'] ?? 21));
    $timeout = max(5, (int)($ftpCfg['timeout_sec'] ?? 30));
    $useSsl = !empty($ftpCfg['ssl']);

    $prefix = supplier_products_remote_image_path_prefix($cfg);
    $remoteRelative = $prefix !== '' ? ($prefix . '/' . $relativePath) : $relativePath;
    $remoteRelative = str_replace('\\', '/', ltrim($remoteRelative, '/'));
    $remoteDir = rtrim($rootDir, '/') . '/' . ltrim(dirname($remoteRelative), '/');
    $remoteName = basename($remoteRelative);
    if ($remoteName === '' || $remoteName === '.' || $remoteName === '..') {
        return false;
    }

    static $pool = [];
    static $shutdownRegistered = false;
    if (!$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function(static function () use (&$pool): void {
            foreach ($pool as $ftp) {
                if (is_resource($ftp) || $ftp instanceof FTP\Connection) {
                    @ftp_close($ftp);
                }
            }
            $pool = [];
        });
    }

    $key = hash('sha256', json_encode([
        $host,
        $port,
        $user,
        $rootDir,
        $useSsl ? 'ssl' : 'plain',
        !array_key_exists('passive', $ftpCfg) || !empty($ftpCfg['passive']) ? 'passive' : 'active',
    ], JSON_UNESCAPED_SLASHES));

    $connect = static function () use ($host, $port, $timeout, $useSsl, $user, $pass, $ftpCfg) {
        $ftp = $useSsl && function_exists('ftp_ssl_connect')
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);
        if (!$ftp) {
            return null;
        }
        if (!@ftp_login($ftp, $user, $pass)) {
            @ftp_close($ftp);
            return null;
        }
        @ftp_pasv($ftp, !array_key_exists('passive', $ftpCfg) || !empty($ftpCfg['passive']));
        return $ftp;
    };

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ftp = $pool[$key] ?? null;
        if (!(is_resource($ftp) || $ftp instanceof FTP\Connection) || @ftp_pwd($ftp) === false) {
            if (is_resource($ftp) || $ftp instanceof FTP\Connection) {
                @ftp_close($ftp);
            }
            $ftp = $connect();
            if (!$ftp) {
                unset($pool[$key]);
                return false;
            }
            $pool[$key] = $ftp;
        }

        try {
            if (!supplier_products_ftp_chdir_mkdirs($ftp, $remoteDir)) {
                throw new RuntimeException('remote_dir_failed');
            }

            $tmpName = '.' . $remoteName . '.tmp-' . bin2hex(random_bytes(4));
            if (!@ftp_put($ftp, $tmpName, $src, FTP_BINARY)) {
                throw new RuntimeException('put_failed');
            }
            @ftp_delete($ftp, $remoteName);
            if (!@ftp_rename($ftp, $tmpName, $remoteName)) {
                @ftp_delete($ftp, $tmpName);
                throw new RuntimeException('rename_failed');
            }
            return true;
        } catch (Throwable $e) {
            if (is_resource($ftp) || $ftp instanceof FTP\Connection) {
                @ftp_close($ftp);
            }
            unset($pool[$key]);
        }
    }

    return false;
}

function supplier_products_upload_remote_image(string $src, string $relativePath, array $cfg = []): string
{
    if (!supplier_products_remote_images_enabled($cfg)) {
        return '';
    }
    $driver = strtolower(trim((string)($cfg['remote_images']['driver'] ?? '')));
    $ok = false;
    if ($driver === 'ftp') {
        $ok = supplier_products_upload_remote_image_ftp($src, $relativePath, $cfg);
    }
    return $ok ? supplier_products_remote_image_url($relativePath, $cfg) : '';
}

function supplier_products_remote_upload_marker_path(string $localPath): string
{
    return $localPath . '.remote-ok.json';
}

function supplier_products_remote_marker_matches(string $markerPath, string $remoteUrl, int $size, int $mtime): bool
{
    if (!is_file($markerPath)) {
        return false;
    }
    $marker = json_decode((string)@file_get_contents($markerPath), true);
    return is_array($marker)
        && (string)($marker['url'] ?? '') === $remoteUrl
        && (int)($marker['size'] ?? -1) === $size
        && (int)($marker['mtime'] ?? -1) === $mtime;
}

function supplier_products_write_remote_upload_marker(string $markerPath, string $remoteUrl, int $size, int $mtime): void
{
    @file_put_contents($markerPath, json_encode([
        'url' => $remoteUrl,
        'size' => $size,
        'mtime' => $mtime,
        'stored_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES));
    @chmod($markerPath, 0664);
}

function supplier_products_publish_stored_image(string $localPath, string $relativePath, array $cfg = [], bool $strictRemote = true): string
{
    $relativePath = supplier_products_normalize_local_image_relative_path($relativePath);
    if ($relativePath === '' || !is_file($localPath)) {
        throw new RuntimeException('Не удалось подготовить публичную ссылку на изображение.');
    }

    if (!supplier_products_remote_images_enabled($cfg)) {
        return supplier_products_image_url($relativePath, $cfg);
    }

    $remoteUrl = supplier_products_remote_image_url($relativePath, $cfg);
    $size = (int)@filesize($localPath);
    $mtime = (int)@filemtime($localPath);
    $markerPath = supplier_products_remote_upload_marker_path($localPath);
    if ($remoteUrl !== '' && supplier_products_remote_marker_matches($markerPath, $remoteUrl, $size, $mtime)) {
        return $remoteUrl;
    }

    $uploadedUrl = supplier_products_upload_remote_image($localPath, $relativePath, $cfg);
    if ($uploadedUrl !== '') {
        supplier_products_write_remote_upload_marker($markerPath, $uploadedUrl, $size, $mtime);
        return $uploadedUrl;
    }

    if ($strictRemote) {
        throw new RuntimeException('Не удалось загрузить изображение в публичную папку lpdankoscr.tmweb.ru.');
    }
    return supplier_products_image_url($relativePath, $cfg);
}

function supplier_products_is_http_url(string $url): bool
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string)$parts['scheme']);
    return $scheme === 'http' || $scheme === 'https';
}

function supplier_products_absolute_public_url(string $url, array $cfg = []): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $base = supplier_products_public_base_url($cfg);
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }
    if (supplier_products_is_http_url($url)) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        $base = supplier_products_public_base_url($cfg);
        return $base !== '' ? (rtrim($base, '/') . $url) : '';
    }
    return '';
}

function supplier_products_public_image_mirror_url(string $relativePath, array $cfg = []): string
{
    $base = supplier_products_public_base_url($cfg);
    if ($base === '') {
        return '';
    }
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
        return '';
    }
    $segments = array_map('rawurlencode', explode('/', $relativePath));
    return rtrim($base, '/') . '/supplier-product-images/' . implode('/', $segments);
}

function supplier_products_materialize_local_image_url(string $url, array $cfg = []): string
{
    $relative = supplier_products_local_image_relative_from_url($url, $cfg);
    if ($relative === '') {
        return '';
    }

    $src = supplier_products_local_image_abs_path($relative, $cfg);
    if ($src === '' || !is_file($src)) {
        return '';
    }

    $relative = str_replace('\\', '/', ltrim($relative, '/'));
    if ($relative === '' || str_contains($relative, '../') || str_contains($relative, '..\\')) {
        return '';
    }

    $publicRoot = supplier_products_public_image_mirror_root($cfg);
    $dst = rtrim($publicRoot, '/\\') . '/' . $relative;
    $dstDir = dirname($dst);
    if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true) && !is_dir($dstDir)) {
        return '';
    }
    @chmod($publicRoot, 0777);
    @chmod($dstDir, 0777);

    $needCopy = !is_file($dst)
        || (int)@filesize($dst) !== (int)@filesize($src)
        || (int)@filemtime($dst) < (int)@filemtime($src);
    if ($needCopy && !@copy($src, $dst)) {
        return '';
    }
    @chmod($dst, 0664);

    try {
        return supplier_products_publish_stored_image($dst, $relative, $cfg, false);
    } catch (Throwable $e) {
        return supplier_products_public_image_mirror_url($relative, $cfg);
    }
}

function supplier_products_prepare_public_picture_url(string $url, array $cfg = []): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (supplier_products_is_http_url($url) && supplier_products_remote_image_relative_from_url($url, $cfg) !== '') {
        return supplier_products_absolute_public_url($url, $cfg);
    }

    if (supplier_products_local_image_relative_from_url($url, $cfg) !== '') {
        return supplier_products_materialize_local_image_url($url, $cfg);
    }

    return supplier_products_absolute_public_url($url, $cfg);
}

function supplier_products_public_picture_urls(array $pictures, array $cfg = [], int $limit = 0): array
{
    $out = [];
    $seen = [];
    static $preparedCache = [];
    foreach ($pictures as $picture) {
        $picture = trim((string)$picture);
        if ($picture === '') {
            continue;
        }
        $cacheKey = hash('sha256', $picture);
        if (array_key_exists($cacheKey, $preparedCache)) {
            $url = $preparedCache[$cacheKey];
        } else {
            $url = supplier_products_prepare_public_picture_url($picture, $cfg);
            if (count($preparedCache) > 2000) {
                array_shift($preparedCache);
            }
            $preparedCache[$cacheKey] = $url;
        }
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        if (!ft_looks_like_image_url($url)) {
            continue;
        }
        $seen[$url] = true;
        $out[] = $url;
        if ($limit > 0 && count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function supplier_products_local_image_relative_from_url(string $url, array $cfg = []): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $remoteRelative = supplier_products_remote_image_relative_from_url($url, $cfg);
    if ($remoteRelative !== '') {
        return $remoteRelative;
    }

    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
    if ($path === '') {
        $parts = explode('?', $url, 2);
        $path = (string)($parts[0] ?? '');
        $query = (string)($parts[1] ?? '');
    }
    if ($path !== '/supplier_product_image.php' && !str_ends_with($path, '/supplier_product_image.php')) {
        return '';
    }

    parse_str($query, $params);
    return supplier_products_normalize_local_image_relative_path((string)($params['f'] ?? ''));
}

function supplier_products_local_image_abs_path(string $relativePath, array $cfg = []): string
{
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
        return '';
    }

    $base = supplier_products_image_storage_dir($cfg);
    $path = $base . '/' . $relativePath;
    $realBase = realpath($base);
    $realDir = realpath(dirname($path));
    if (!$realBase || !$realDir || !str_starts_with($realDir, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return '';
    }
    return $path;
}

function supplier_products_local_image_reference_count(string $relativePath, int $excludeProductId = 0): int
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return 0;
    }
    $sql = "
        SELECT COUNT(*)
        FROM feedtools_supplier_products
        WHERE pictures_json LIKE ?
    ";
    $args = ['%' . $relativePath . '%'];
    if ($excludeProductId > 0) {
        $sql .= " AND id <> ?";
        $args[] = $excludeProductId;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

function supplier_products_delete_local_images_if_unreferenced(array $urls, array $cfg = [], int $excludeProductId = 0): void
{
    $relativeSet = [];
    foreach ($urls as $url) {
        $relative = supplier_products_local_image_relative_from_url((string)$url, $cfg);
        if ($relative !== '') {
            $relativeSet[$relative] = true;
        }
    }
    foreach (array_keys($relativeSet) as $relative) {
        if (supplier_products_local_image_reference_count($relative, $excludeProductId) > 0) {
            continue;
        }
        $path = supplier_products_local_image_abs_path($relative, $cfg);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function supplier_products_db_dataset_path(int $supplierId): string
{
    return 'supplier-products-db://' . $supplierId;
}

function supplier_products_db_dataset_filename(int $supplierId): string
{
    return 'supplier_' . $supplierId . '_products.db';
}

function supplier_products_dataset_sha(int $supplierId, string $dataSha): string
{
    return hash('sha256', SUPPLIER_PRODUCTS_STORAGE_KIND . ':' . $supplierId . ':' . $dataSha);
}

function supplier_products_db_fingerprint(int $supplierId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($supplierId <= 0) {
        return [
            'offers_count' => 0,
            'bytes' => 0,
            'sha256' => supplier_products_dataset_sha($supplierId, 'db-empty'),
        ];
    }

    $pdo = db();
    $meta = supplier_products_meta_get($supplierId, $cfg);
    $hash = hash_init('sha256');
    hash_update($hash, SUPPLIER_PRODUCTS_STORAGE_KIND . ':db:' . $supplierId . "\n");
    hash_update($hash, hash('sha256', (string)($meta['categories_json'] ?? '')) . "\n");

    $st = $pdo->prepare("
        SELECT id, offer_key, offer_id, raw_hash, updated_at
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$supplierId]);

    $count = 0;
    $bytes = strlen((string)($meta['categories_json'] ?? ''));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        $bytes += strlen((string)($row['raw_hash'] ?? ''));
        hash_update(
            $hash,
            implode("\t", [
                (string)($row['id'] ?? ''),
                (string)($row['offer_key'] ?? ''),
                (string)($row['offer_id'] ?? ''),
                (string)($row['raw_hash'] ?? ''),
                (string)($row['updated_at'] ?? ''),
            ]) . "\n"
        );
    }

    return [
        'offers_count' => $count,
        'bytes' => $bytes,
        'sha256' => supplier_products_dataset_sha($supplierId, hash_final($hash)),
    ];
}

function supplier_products_update_dataset_row_from_db(int $supplierId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $datasetId = supplier_products_dataset_id($supplierId, $cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $stats = supplier_products_db_fingerprint($supplierId, $cfg);
    $path = supplier_products_db_dataset_path($supplierId);
    $displayName = 'Товары поставщика: ' . trim((string)($supplier['name'] ?? ('#' . $supplierId)));
    $warningsJson = (string)(supplier_products_meta_get($supplierId, $cfg)['warnings_json'] ?? '[]');

    $upd = db()->prepare("
        UPDATE feedtools_datasets
        SET original_filename = ?,
            stored_filename = ?,
            stored_path = ?,
            bytes = ?,
            sha256 = ?,
            offers_count = ?,
            warnings_json = ?
        WHERE id = ?
    ");
    $upd->execute([
        $displayName,
        supplier_products_db_dataset_filename($supplierId),
        $path,
        (int)$stats['bytes'],
        (string)$stats['sha256'],
        (int)$stats['offers_count'],
        $warningsJson,
        $datasetId,
    ]);

    $meta = db()->prepare("
        INSERT INTO feedtools_supplier_product_meta (
            supplier_id, dataset_id, offers_count, warnings_json
        ) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            dataset_id = VALUES(dataset_id),
            offers_count = VALUES(offers_count),
            warnings_json = VALUES(warnings_json)
    ");
    $meta->execute([$supplierId, $datasetId, (int)$stats['offers_count'], $warningsJson]);

    return [
        'dataset_id' => $datasetId,
        'offers_count' => (int)$stats['offers_count'],
        'bytes' => (int)$stats['bytes'],
        'sha256' => (string)$stats['sha256'],
        'path' => $path,
    ];
}

function supplier_products_is_dataset_row(array $row): bool
{
    $datasetId = (int)($row['id'] ?? 0);
    return $datasetId > 0 && supplier_products_supplier_id_for_dataset($datasetId) > 0;
}

function supplier_products_supplier_id_for_dataset(int $datasetId, array $cfg = []): int
{
    static $cache = [];
    if ($datasetId <= 0) {
        return 0;
    }
    if (array_key_exists($datasetId, $cache)) {
        return (int)$cache[$datasetId];
    }

    try {
        supplier_products_tables_ensure($cfg);
        $st = db()->prepare("
            SELECT supplier_id
            FROM feedtools_supplier_product_meta
            WHERE dataset_id = ?
            LIMIT 1
        ");
        $st->execute([$datasetId]);
        $supplierId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $supplierId = 0;
    }
    $cache[$datasetId] = $supplierId;
    return $supplierId;
}

function supplier_products_count(int $supplierId, array $cfg = []): int
{
    supplier_products_tables_ensure($cfg);
    if ($supplierId <= 0) {
        return 0;
    }
    $st = db()->prepare("SELECT COUNT(*) FROM feedtools_supplier_products WHERE supplier_id = ?");
    $st->execute([$supplierId]);
    return (int)$st->fetchColumn();
}

function supplier_products_offer_id_remap_norm_header(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~^\xEF\xBB\xBF~', '', (string)$value);
    $value = function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value);
    $value = str_replace('ё', 'е', $value);
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
    $value = preg_replace('~[^\p{L}\p{N}]+~u', ' ', (string)$value);
    $value = preg_replace('~\s+~u', ' ', (string)$value);
    return trim((string)$value);
}

function supplier_products_offer_id_remap_detect_delimiter(string $line): string
{
    $bestDelimiter = ';';
    $bestCount = 0;
    foreach ([";", ",", "\t"] as $delimiter) {
        $count = count(str_getcsv($line, $delimiter, '"', ''));
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }
    return $bestDelimiter;
}

function supplier_products_offer_id_remap_header_indices(array $columns): ?array
{
    $oldHeaders = array_fill_keys(array_map('supplier_products_offer_id_remap_norm_header', [
        'old',
        'old id',
        'old offer id',
        'old_offer_id',
        'old article',
        'old_article',
        'from',
        'source',
        'старый',
        'старый id',
        'старый offer id',
        'старый артикул',
        'старое значение',
        'исходный артикул',
        'текущий артикул',
        'неверный артикул',
    ]), true);
    $newHeaders = array_fill_keys(array_map('supplier_products_offer_id_remap_norm_header', [
        'new',
        'new id',
        'new offer id',
        'new_offer_id',
        'new article',
        'new_article',
        'to',
        'target',
        'новый',
        'новый id',
        'новый offer id',
        'новый артикул',
        'новое значение',
        'правильный артикул',
    ]), true);

    $oldIndex = null;
    $newIndex = null;
    foreach ($columns as $index => $column) {
        $header = supplier_products_offer_id_remap_norm_header((string)$column);
        if ($header === '') {
            continue;
        }
        if ($oldIndex === null && isset($oldHeaders[$header])) {
            $oldIndex = (int)$index;
        }
        if ($newIndex === null && isset($newHeaders[$header])) {
            $newIndex = (int)$index;
        }
    }

    if ($oldIndex !== null && $newIndex !== null && $oldIndex !== $newIndex) {
        return ['old' => $oldIndex, 'new' => $newIndex];
    }
    return null;
}

function supplier_products_offer_id_remap_read_csv(string $path): array
{
    $path = trim($path);
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('CSV-карта артикулов не найдена.');
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Не удалось открыть CSV-карту артикулов.');
    }

    $delimiter = null;
    $header = null;
    $rowsTotal = 0;
    $invalid = [];
    $duplicates = [];
    $map = [];

    try {
        while (($line = fgets($fh)) !== false) {
            $line = rtrim((string)$line, "\r\n");
            if (trim($line) === '') {
                continue;
            }
            if ($delimiter === null) {
                $line = preg_replace('~^\xEF\xBB\xBF~', '', $line);
                $delimiter = supplier_products_offer_id_remap_detect_delimiter($line);
            }

            $columns = str_getcsv($line, $delimiter, '"', '');
            if ($header === null) {
                $header = supplier_products_offer_id_remap_header_indices($columns);
                if ($header !== null) {
                    continue;
                }
                $header = ['old' => 0, 'new' => 1];
            }

            $rowsTotal++;
            $old = trim((string)($columns[(int)$header['old']] ?? ''));
            $new = trim((string)($columns[(int)$header['new']] ?? ''));
            $old = preg_replace('~^\xEF\xBB\xBF~', '', $old);
            if ($old === '' || $new === '') {
                if (count($invalid) < 20) {
                    $invalid[] = [
                        'row' => $rowsTotal,
                        'old' => $old,
                        'new' => $new,
                        'reason' => 'Пустой старый или новый артикул',
                    ];
                }
                continue;
            }
            if (isset($map[$old]) && $map[$old] !== $new) {
                if (count($duplicates) < 20) {
                    $duplicates[] = [
                        'old' => $old,
                        'first_new' => $map[$old],
                        'second_new' => $new,
                    ];
                }
                continue;
            }
            $map[$old] = $new;
        }
    } finally {
        fclose($fh);
    }

    if (!$map) {
        throw new RuntimeException('В CSV-карте не найдено ни одной пары старый артикул → новый артикул.');
    }

    return [
        'map' => $map,
        'rows_total' => $rowsTotal,
        'invalid_examples' => $invalid,
        'duplicate_examples' => $duplicates,
        'invalid' => count($invalid),
        'duplicates' => count($duplicates),
        'delimiter' => $delimiter ?? ';',
    ];
}

function supplier_products_offer_id_remap_candidates(string $offerId, string $supplierCode): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [];
    }
    $out = [$offerId => true];
    $coded = suppliers_apply_supplier_code($offerId, $supplierCode);
    if ($coded !== '') {
        $out[$coded] = true;
    }
    return array_keys($out);
}

function supplier_products_remap_offer_ids_from_csv_file(
    int $supplierId,
    string $csvPath,
    array $cfg = [],
    ?callable $progress = null,
    ?callable $log = null
): array {
    supplier_products_tables_ensure($cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $csv = supplier_products_offer_id_remap_read_csv($csvPath);
    $map = (array)($csv['map'] ?? []);
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $total = max(1, count($map));

    if ($log) {
        $log('offer_id_remap: loaded mappings=' . count($map) . ', rows=' . (int)($csv['rows_total'] ?? 0) . "\n");
    }
    if ($progress) {
        $progress(0, $total, 'remap', 'Читаю CSV-карту артикулов');
    }

    $result = [
        'source_offers' => count($map),
        'rows_total' => (int)($csv['rows_total'] ?? count($map)),
        'updated' => 0,
        'missing' => 0,
        'conflicts' => 0,
        'noop' => 0,
        'invalid' => (int)($csv['invalid'] ?? 0),
        'duplicates' => (int)($csv['duplicates'] ?? 0),
        'missing_examples' => [],
        'conflict_examples' => [],
        'invalid_examples' => (array)($csv['invalid_examples'] ?? []),
        'duplicate_examples' => (array)($csv['duplicate_examples'] ?? []),
    ];

    $pdo = db();
    $updatedProducts = [];
    $findProduct = static function (array $candidates) use ($pdo, $supplierId): array {
        if (!$candidates) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $args = array_merge([$supplierId], $candidates, $candidates);
        $st = $pdo->prepare("
            SELECT id, offer_id, offer_key
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND (offer_id IN ({$placeholders}) OR offer_key IN ({$placeholders}))
            ORDER BY id ASC
        ");
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $targetExists = $pdo->prepare("
        SELECT id, offer_id, offer_key
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND id <> ?
          AND (offer_id = ? OR offer_key = ?)
        LIMIT 1
    ");
    $update = $pdo->prepare("
        UPDATE feedtools_supplier_products
        SET offer_id = ?,
            offer_key = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $processed = 0;
    $pdo->beginTransaction();
    try {
        foreach ($map as $oldRaw => $newRaw) {
            $processed++;
            $oldRaw = trim((string)$oldRaw);
            $newRaw = trim((string)$newRaw);
            $newOfferId = trim(suppliers_apply_supplier_code($newRaw, $supplierCode));
            if ($progress && ($processed % 50 === 0 || $processed === count($map))) {
                $progress($processed, $total, 'remap', 'Заменяю артикулы: ' . $processed . ' из ' . count($map));
            }

            if ($newOfferId === '' || (function_exists('mb_strlen') ? mb_strlen($newOfferId, 'UTF-8') : strlen($newOfferId)) > 191) {
                $result['invalid']++;
                if (count($result['invalid_examples']) < 20) {
                    $result['invalid_examples'][] = [
                        'old' => $oldRaw,
                        'new' => $newRaw,
                        'reason' => 'Новый артикул пустой или длиннее 191 символа',
                    ];
                }
                continue;
            }

            $matches = $findProduct(supplier_products_offer_id_remap_candidates($oldRaw, $supplierCode));
            if (count($matches) < 1) {
                $result['missing']++;
                if (count($result['missing_examples']) < 20) {
                    $result['missing_examples'][] = ['old' => $oldRaw, 'new' => $newRaw];
                }
                continue;
            }
            if (count($matches) > 1) {
                $result['conflicts']++;
                if (count($result['conflict_examples']) < 20) {
                    $result['conflict_examples'][] = [
                        'old' => $oldRaw,
                        'new' => $newRaw,
                        'reason' => 'Старый артикул найден у нескольких товаров',
                    ];
                }
                continue;
            }

            $product = $matches[0];
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0) {
                $result['missing']++;
                continue;
            }
            if (trim((string)($product['offer_id'] ?? '')) === $newOfferId && trim((string)($product['offer_key'] ?? '')) === $newOfferId) {
                $result['noop']++;
                continue;
            }

            $targetExists->execute([$supplierId, $productId, $newOfferId, $newOfferId]);
            $conflict = $targetExists->fetch(PDO::FETCH_ASSOC);
            if (is_array($conflict)) {
                $result['conflicts']++;
                if (count($result['conflict_examples']) < 20) {
                    $result['conflict_examples'][] = [
                        'old' => $oldRaw,
                        'new' => $newRaw,
                        'reason' => 'Новый артикул уже занят другим товаром',
                        'conflict_product_id' => (int)($conflict['id'] ?? 0),
                    ];
                }
                continue;
            }

            $update->execute([$newOfferId, $newOfferId, $productId]);
            supplier_products_sync_product_summary_from_db($productId, $cfg);
            $updatedProducts[] = [
                'product_id' => $productId,
                'old' => trim((string)($product['offer_id'] ?? $oldRaw)),
                'new' => $newOfferId,
            ];
            $result['updated']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    if ($progress) {
        $progress($total, $total, 'done', 'Артикулы заменены');
    }
    if ($log) {
        $log('offer_id_remap: updated=' . (int)$result['updated']
            . ', missing=' . (int)$result['missing']
            . ', conflicts=' . (int)$result['conflicts']
            . ', noop=' . (int)$result['noop']
            . ', invalid=' . (int)$result['invalid']
            . ', duplicates=' . (int)$result['duplicates'] . "\n");
        foreach (array_slice($updatedProducts, 0, 20) as $row) {
            $log('offer_id_remap: ' . (string)$row['old'] . ' -> ' . (string)$row['new'] . "\n");
        }
    }

    $result['updated_examples'] = array_slice($updatedProducts, 0, 20);
    $result['dataset'] = supplier_products_db_fingerprint($supplierId, $cfg);
    return $result;
}

function supplier_products_meta_get(int $supplierId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $st = db()->prepare("SELECT * FROM feedtools_supplier_product_meta WHERE supplier_id = ? LIMIT 1");
    $st->execute([$supplierId]);
    $row = $st->fetch();
    return is_array($row) ? $row : [
        'supplier_id' => $supplierId,
        'dataset_id' => 0,
        'source_url' => '',
        'source_sha256' => '',
        'source_bytes' => 0,
        'categories_json' => '',
        'offers_count' => 0,
        'warnings_json' => null,
        'last_imported_at' => null,
    ];
}

function supplier_products_dataset_id(int $supplierId, array $cfg = []): int
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $pdo = db();
    $meta = supplier_products_meta_get($supplierId, $cfg);
    $datasetId = (int)($meta['dataset_id'] ?? 0);
    if ($datasetId > 0) {
        $st = $pdo->prepare("SELECT id FROM feedtools_datasets WHERE id = ? LIMIT 1");
        $st->execute([$datasetId]);
        if ((int)($st->fetchColumn() ?: 0) > 0) {
            return $datasetId;
        }
    }

    $storedPath = supplier_products_db_dataset_path($supplierId);
    $storedFilename = supplier_products_db_dataset_filename($supplierId);
    $name = trim((string)($supplier['name'] ?? ''));
    $originalFilename = 'Товары поставщика' . ($name !== '' ? ': ' . $name : (' #' . $supplierId));
    $sha = supplier_products_dataset_sha($supplierId, 'empty');

    $ins = $pdo->prepare("
        INSERT INTO feedtools_datasets (
            original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json
        ) VALUES (?, ?, ?, 0, ?, 0, NULL)
    ");
    $ins->execute([
        $originalFilename,
        $storedFilename,
        $storedPath,
        $sha,
    ]);
    $datasetId = (int)$pdo->lastInsertId();

    $up = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_meta (supplier_id, dataset_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE dataset_id = VALUES(dataset_id)
    ");
    $up->execute([$supplierId, $datasetId]);

    return $datasetId;
}

function supplier_products_dataset_row(int $datasetId, array $cfg = []): ?array
{
    supplier_products_tables_ensure($cfg);
    if ($datasetId <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ? LIMIT 1");
    $st->execute([$datasetId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function supplier_products_context_for_dataset(array $datasetRow, array $cfg = []): ?array
{
    $datasetId = (int)($datasetRow['id'] ?? 0);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        return null;
    }
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        return null;
    }
    $meta = supplier_products_meta_get($supplierId, $cfg);
    return [
        'supplier' => $supplier,
        'meta' => $meta,
        'supplier_id' => $supplierId,
        'dataset_id' => $datasetId,
        'products_count' => supplier_products_count($supplierId, $cfg),
    ];
}

function supplier_products_skip_current_element(XMLReader $reader, string $name, int $depth): void
{
    if ($reader->isEmptyElement) {
        return;
    }
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === $name && $reader->depth === $depth) {
            break;
        }
    }
}

function supplier_products_xml_text($value): string
{
    if ($value instanceof SimpleXMLElement) {
        return trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    return trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function supplier_products_parse_float(string $value): ?float
{
    $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : null;
}

function supplier_products_normalize_marketplace_enabled($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    $raw = mb_strtolower(trim((string)$value), 'UTF-8');
    if ($raw === '' || in_array($raw, ['1', 'true', 'yes', 'on', 'да', 'вкл'], true)) {
        return 1;
    }
    if (in_array($raw, ['0', 'false', 'no', 'off', 'нет', 'выкл'], true)) {
        return 0;
    }
    return ((int)$raw) !== 0 ? 1 : 0;
}

function supplier_products_normalize_stock_modifier($value): int
{
    $raw = str_replace(["\xc2\xa0", ' '], '', trim((string)$value));
    if ($raw === '') {
        return 0;
    }
    if (!preg_match('~^[+-]?\d+$~', $raw)) {
        throw new RuntimeException('Модификатор остатка должен быть целым числом, например +5 или -2.');
    }
    return max(-1000000, min(1000000, (int)$raw));
}

function supplier_products_normalize_price_modifier($value): string
{
    $raw = str_replace(["\xc2\xa0", ' '], '', trim((string)$value));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '') {
        return '';
    }
    if (mb_strlen($raw, 'UTF-8') > 64) {
        throw new RuntimeException('Модификатор цены слишком длинный.');
    }
    if (!preg_match('~^(?:[+-]\d+(?:\.\d+)?%?|=\d+(?:\.\d+)?|\d+(?:\.\d+)?)$~', $raw)) {
        throw new RuntimeException('Модификатор цены: используй +100, -50, +5%, -7%, =999 или 999.');
    }
    return $raw;
}

function supplier_products_apply_stock_modifier(int $baseStock, int $marketplaceEnabled = 1, int $stockModifier = 0): int
{
    if ($marketplaceEnabled <= 0) {
        return 0;
    }
    return max(0, $baseStock + $stockModifier);
}

function supplier_products_apply_price_modifier(?float $basePrice, string $priceModifier): ?float
{
    if ($basePrice === null || !is_finite($basePrice) || $basePrice <= 0) {
        return $basePrice;
    }
    $modifier = supplier_products_normalize_price_modifier($priceModifier);
    if ($modifier === '') {
        return $basePrice;
    }
    if ($modifier[0] === '=') {
        return max(0.0, (float)substr($modifier, 1));
    }
    $isPercent = str_ends_with($modifier, '%');
    $numRaw = $isPercent ? substr($modifier, 0, -1) : $modifier;
    $num = (float)$numRaw;
    if ($modifier[0] !== '+' && $modifier[0] !== '-' && !$isPercent) {
        return max(0.0, $num);
    }
    if ($isPercent) {
        return max(0.0, $basePrice + ($basePrice * ($num / 100.0)));
    }
    return max(0.0, $basePrice + $num);
}

function supplier_products_effective_stock_from_product(array $product): int
{
    $base = max((int)($product['stock_qty'] ?? 0), (int)($product['count_qty'] ?? 0));
    return supplier_products_apply_stock_modifier(
        $base,
        (int)($product['marketplace_enabled'] ?? 1),
        (int)($product['stock_modifier'] ?? 0)
    );
}

function supplier_products_effective_price_from_product(array $product): ?float
{
    if (($product['price_original'] ?? null) !== null && (string)$product['price_original'] !== '') {
        return (float)$product['price_original'];
    }
    return null;
}

function supplier_products_marketplace_controls_by_offer_ids(int $supplierId, array $offerIds, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = max(0, $supplierId);
    $offerIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ), static fn(string $value): bool => $value !== '')));
    if ($supplierId <= 0 || !$offerIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($offerIds, 800) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT offer_id, marketplace_enabled, stock_modifier, price_modifier
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId === '') {
                continue;
            }
            $out[$offerId] = [
                'marketplace_enabled' => supplier_products_normalize_marketplace_enabled($row['marketplace_enabled'] ?? 1),
                'stock_modifier' => (int)($row['stock_modifier'] ?? 0),
                'price_modifier' => supplier_products_normalize_price_modifier($row['price_modifier'] ?? ''),
            ];
        }
    }
    return $out;
}

function supplier_products_parse_dimensions_triplet(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['', '', ''];
    }
    $parts = preg_split('/\s*[xх×*\/]\s*/iu', $raw);
    if (!is_array($parts) || count($parts) < 3) {
        $parts = preg_split('/[\s;,]+/u', $raw);
    }
    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== '')) : [];
    return [
        (string)($parts[0] ?? ''),
        (string)($parts[1] ?? ''),
        (string)($parts[2] ?? ''),
    ];
}

function supplier_products_extract_image_urls_from_description(string $description): array
{
    $description = trim($description);
    if ($description === '') {
        return [];
    }

    $out = [];
    $seen = [];
    foreach (ft_extract_urls($description) as $url) {
        $url = trim((string)$url);
        $url = preg_replace('~[\)\]\}\>\"\'\,\.;!\?]+$~u', '', $url);
        $url = trim((string)$url);
        if ($url === '' || !ft_looks_like_image_url($url) || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $out[] = $url;
    }
    return $out;
}

function supplier_products_append_description_images_to_pictures(array $pictures, string $description): array
{
    $out = supplier_products_normalize_picture_urls($pictures);
    $seen = array_fill_keys($out, true);
    foreach (supplier_products_extract_image_urls_from_description($description) as $url) {
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $out[] = $url;
    }
    return $out;
}

function supplier_products_xml_name_is_valid(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $name);
}

function supplier_products_validate_field_kind(string $kind): string
{
    $kind = trim($kind);
    if (!in_array($kind, ['tag', 'param', 'wb_param', 'attr', 'standard', 'feature'], true)) {
        throw new RuntimeException('Неподдерживаемый тип поля товара.');
    }
    return $kind;
}

function supplier_products_validate_field_name(string $kind, string $name): string
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Название поля не может быть пустым.');
    }
    if ($kind === 'standard') {
        if (!supplier_products_is_standard_field_name($name)) {
            throw new RuntimeException('Неподдерживаемая постоянная характеристика товара.');
        }
        return $name;
    }
    if ($kind === 'feature') {
        $index = supplier_products_feature_field_index($name);
        return $index > 0 ? supplier_products_feature_field_name($index) : mb_substr($name, 0, 191, 'UTF-8');
    }
    if (($kind === 'tag' || $kind === 'attr') && !supplier_products_xml_name_is_valid($name)) {
        throw new RuntimeException('Название XML-тега или атрибута содержит недопустимые символы.');
    }
    return mb_substr($name, 0, 191, 'UTF-8');
}

function supplier_products_normalize_inline_field_value(string $fieldName, string $value): string
{
    $fieldName = trim($fieldName);
    if ($fieldName === '' || trim($value) === '') {
        return $value;
    }

    $current = $value;
    $namePattern = preg_quote($fieldName, '~');
    $prefixPattern = '(?:(?:oz|ozon|wb|wildberries)\s*(?:\|\s*)?)?';
    for ($i = 0; $i < 4; $i++) {
        $candidate = trim($current);
        if (!preg_match('~^' . $prefixPattern . $namePattern . '\s*:\s*(.*)$~isu', $candidate, $m)) {
            break;
        }
        $current = (string)($m[1] ?? '');
    }
    return $current;
}

function supplier_products_supplier_matches_westline(array $supplier): bool
{
    $haystack = mb_strtolower(implode(' ', [
        (string)($supplier['name'] ?? ''),
        (string)($supplier['supplier_code'] ?? ''),
        (string)($supplier['feed_url'] ?? ''),
    ]), 'UTF-8');
    return str_contains($haystack, 'westline') || str_contains($haystack, 'вестлайн');
}

function supplier_products_supplier_matches_ctrade(array $supplier): bool
{
    $haystack = mb_strtolower(implode(' ', [
        (string)($supplier['name'] ?? ''),
        (string)($supplier['supplier_code'] ?? ''),
        (string)($supplier['feed_url'] ?? ''),
    ]), 'UTF-8');
    return str_contains($haystack, 'ctrade')
        || str_contains($haystack, 'c-trade')
        || str_contains($haystack, 'c trade')
        || str_contains($haystack, 'ctradei')
        || str_contains($haystack, 'ситрейд');
}

function supplier_products_parse_options_for_supplier(array $supplier, array $cfg = []): array
{
    return [
        'skip_unit_quantity_params' => supplier_products_supplier_matches_westline($supplier),
        'price_tag_as_price_original' => supplier_products_supplier_matches_ctrade($supplier),
    ];
}

function supplier_products_parse_options_for_supplier_id(int $supplierId, array $cfg = []): array
{
    static $cache = [];
    if ($supplierId <= 0) {
        return [];
    }
    if (array_key_exists($supplierId, $cache)) {
        return $cache[$supplierId];
    }

    $supplier = suppliers_get($supplierId, $cfg);
    $cache[$supplierId] = is_array($supplier)
        ? supplier_products_parse_options_for_supplier($supplier, $cfg)
        : [];
    return $cache[$supplierId];
}

function supplier_products_should_skip_source_param(string $fieldKind, string $name, array $options = []): bool
{
    if (empty($options['skip_unit_quantity_params'])) {
        return false;
    }
    if (!in_array($fieldKind, ['param', 'wb_param'], true)) {
        return false;
    }
    return supplier_products_characteristic_group_key($name) === 'alias:unit_quantity';
}

function supplier_products_parse_offer_node_xml(string $offerNodeXml, array $options = []): array
{
    $out = [
        'offer_id' => '',
        'name' => '',
        'vendor_code' => '',
        'category_id' => '',
        'ozon_category' => '',
        'wb_category' => '',
        'brand' => '',
        'description_html' => '',
        'count_qty' => 0,
        'stock_qty' => 0,
        'price_original' => null,
        'pictures' => [],
        'params' => [],
        'fields' => [],
    ];
    $standardValues = supplier_products_standard_empty_values();
    $stockPriority = 0;
    $setStock = static function (string $value, int $priority) use (&$standardValues, &$stockPriority): void {
        $value = trim($value);
        if ($value === '' || $priority < $stockPriority) {
            return;
        }
        $standardValues['stock'] = $value;
        $stockPriority = $priority;
    };

    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($offerNodeXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$xml instanceof SimpleXMLElement) {
        if (preg_match('~<offer\b[^>]*\bid=("|\')([^"\']*)\1~i', $offerNodeXml, $m)) {
            $out['offer_id'] = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $out;
    }

    $attrs = $xml->attributes();
    $out['offer_id'] = trim((string)($attrs['id'] ?? ''));
    $sortOrder = 0;
    $featureCount = 0;
    foreach ($attrs as $attrName => $attrValue) {
        $attrName = trim((string)$attrName);
        if ($attrName === '' || $attrName === 'id') {
            continue;
        }
        $sortOrder++;
        $out['fields'][] = [
            'field_kind' => 'attr',
            'field_name' => mb_substr($attrName, 0, 191, 'UTF-8'),
            'field_value' => supplier_products_xml_text($attrValue),
            'sort_order' => $sortOrder,
        ];
    }
    $vendor = '';

    foreach ($xml->children() as $child) {
        $tag = $child->getName();
        $value = supplier_products_xml_text($child);
        $sortOrder++;
        if ($tag === 'name') {
            $out['name'] = $value;
        } elseif ($tag === 'vendorCode' || $tag === 'article') {
            if ($out['vendor_code'] === '') {
                $out['vendor_code'] = $value;
            }
            $out['fields'][] = [
                'field_kind' => 'tag',
                'field_name' => mb_substr($tag, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
        } elseif ($tag === 'categoryId' || $tag === 'category') {
            if ($out['category_id'] === '') {
                $out['category_id'] = $value;
            }
            $out['fields'][] = [
                'field_kind' => 'tag',
                'field_name' => mb_substr($tag, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
        } elseif ($tag === 'ozon_category') {
            $out['ozon_category'] = $value;
            $out['fields'][] = [
                'field_kind' => 'tag',
                'field_name' => 'ozon_category',
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
        } elseif ($tag === 'wb_category' || $tag === 'wb_subject_id') {
            $out['wb_category'] = $value;
            $out['fields'][] = [
                'field_kind' => 'tag',
                'field_name' => mb_substr($tag, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
        } elseif ($tag === 'brand') {
            $out['brand'] = $value;
            if ($value !== '') {
                $standardValues['brand'] = $value;
            }
        } elseif ($tag === 'vendor') {
            $vendor = $value;
            if ($value !== '' && $standardValues['brand'] === '') {
                $standardValues['brand'] = $value;
            }
        } elseif ($tag === 'count' || $tag === 'quantity') {
            $out['count_qty'] = (int)$value;
            $setStock($value, $tag === 'quantity' ? 2 : 1);
        } elseif ($tag === 'stock') {
            $out['stock_qty'] = (int)$value;
            $setStock($value, 3);
        } elseif ($tag === 'price_original' || ($tag === 'price' && !empty($options['price_tag_as_price_original']))) {
            $out['price_original'] = supplier_products_parse_float($value);
            if ($value !== '') {
                $standardValues['purchase_price'] = $value;
            }
        } elseif ($tag === 'weight') {
            if ($value !== '') {
                $standardValues['weight'] = $value;
            }
        } elseif ($tag === 'dimensions') {
            [$length, $width, $height] = supplier_products_parse_dimensions_triplet($value);
            if ($length !== '') $standardValues['length'] = $length;
            if ($width !== '') $standardValues['width'] = $width;
            if ($height !== '') $standardValues['height'] = $height;
        } elseif ($tag === 'description') {
            $out['description_html'] = $value;
        } elseif ($tag === 'picture') {
            if ($value !== '') {
                $out['pictures'][] = $value;
            }
        } elseif (supplier_products_is_feature_source_field_name($tag)) {
            if ($value !== '' && $featureCount < SUPPLIER_PRODUCTS_FEATURE_MAX) {
                $featureCount++;
                $index = supplier_products_feature_field_index($tag);
                $out['fields'][] = [
                    'field_kind' => 'feature',
                    'field_name' => supplier_products_feature_field_name($index > 0 ? $index : $featureCount),
                    'field_value' => supplier_products_normalize_feature_value($value),
                    'sort_order' => $sortOrder,
                ];
            }
        } elseif ($tag === 'param' || $tag === 'wb_param') {
            $pname = trim((string)($child->attributes()['name'] ?? ''));
            if ($pname !== '' && !supplier_products_should_skip_source_param($tag, $pname, $options)) {
                $out['fields'][] = [
                    'field_kind' => $tag,
                    'field_name' => mb_substr($pname, 0, 191, 'UTF-8'),
                    'field_value' => $value,
                    'sort_order' => $sortOrder,
                ];
                if ($tag === 'wb_param') {
                    $pname = '[WB] ' . $pname;
                }
                if (!isset($out['params'][$pname])) {
                    $out['params'][$pname] = [];
                }
                $out['params'][$pname][] = $value;
            }
        } else {
            $out['fields'][] = [
                'field_kind' => 'tag',
                'field_name' => mb_substr($tag, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
        }
    }

    if ($out['brand'] === '' && $vendor !== '') {
        $out['brand'] = $vendor;
    }
    if ($standardValues['brand'] === '' && (string)$out['brand'] !== '') {
        $standardValues['brand'] = (string)$out['brand'];
    }
    if ($standardValues['purchase_price'] === '' && $out['price_original'] !== null) {
        $standardValues['purchase_price'] = (string)$out['price_original'];
    }
    if ($standardValues['stock'] === '') {
        $effectiveStock = max((int)($out['stock_qty'] ?? 0), (int)($out['count_qty'] ?? 0));
        if ($effectiveStock > 0) {
            $standardValues['stock'] = (string)$effectiveStock;
        }
    }
    $out['pictures'] = supplier_products_append_description_images_to_pictures(
        (array)($out['pictures'] ?? []),
        (string)($out['description_html'] ?? '')
    );
    $out['fields'] = array_merge(
        supplier_products_standard_fields_from_values($standardValues),
        $out['fields']
    );

    return $out;
}

function supplier_products_parsed_from_source_parts(
    string $offerId,
    array $tags,
    array $pictures,
    array $params,
    array $wbParams,
    array $features = [],
    array $options = []
): array {
    $out = [
        'offer_id' => trim($offerId),
        'name' => '',
        'vendor_code' => '',
        'category_id' => '',
        'ozon_category' => '',
        'wb_category' => '',
        'brand' => '',
        'description_html' => '',
        'count_qty' => 0,
        'stock_qty' => 0,
        'price_original' => null,
        'pictures' => [],
        'params' => [],
        'fields' => [],
    ];
    $standardValues = supplier_products_standard_empty_values();
    $sortOrder = 0;
    $stockPriority = 0;
    $setStock = static function (string $value, int $priority) use (&$standardValues, &$stockPriority): void {
        $value = trim($value);
        if ($value === '' || $priority < $stockPriority) {
            return;
        }
        $standardValues['stock'] = $value;
        $stockPriority = $priority;
    };

    $vendor = '';
    $featureValues = $features;
    foreach ($tags as $tag => $value) {
        $tag = trim((string)$tag);
        $value = trim((string)$value);
        if ($tag === '' || $value === '') {
            continue;
        }
        $sortOrder++;
        if ($tag === 'name') {
            $out['name'] = $value;
        } elseif ($tag === 'vendorCode' || $tag === 'article') {
            if ($out['vendor_code'] === '') {
                $out['vendor_code'] = $value;
            }
            $out['fields'][] = ['field_kind' => 'tag', 'field_name' => $tag, 'field_value' => $value, 'sort_order' => $sortOrder];
        } elseif ($tag === 'categoryId' || $tag === 'category') {
            if ($out['category_id'] === '') {
                $out['category_id'] = $value;
            }
            $out['fields'][] = ['field_kind' => 'tag', 'field_name' => $tag, 'field_value' => $value, 'sort_order' => $sortOrder];
        } elseif ($tag === 'ozon_category') {
            $out['ozon_category'] = $value;
            $out['fields'][] = ['field_kind' => 'tag', 'field_name' => 'ozon_category', 'field_value' => $value, 'sort_order' => $sortOrder];
        } elseif ($tag === 'wb_category' || $tag === 'wb_subject_id') {
            $out['wb_category'] = $value;
            $out['fields'][] = ['field_kind' => 'tag', 'field_name' => $tag, 'field_value' => $value, 'sort_order' => $sortOrder];
        } elseif ($tag === 'brand') {
            $out['brand'] = $value;
            $standardValues['brand'] = $value;
        } elseif ($tag === 'vendor') {
            $vendor = $value;
            if ($standardValues['brand'] === '') {
                $standardValues['brand'] = $value;
            }
        } elseif ($tag === 'count' || $tag === 'quantity') {
            $out['count_qty'] = (int)$value;
            $setStock($value, $tag === 'quantity' ? 2 : 1);
        } elseif ($tag === 'stock') {
            $out['stock_qty'] = (int)$value;
            $setStock($value, 3);
        } elseif ($tag === 'price_original' || $tag === 'price') {
            $out['price_original'] = supplier_products_parse_float($value);
            if ($standardValues['purchase_price'] === '') {
                $standardValues['purchase_price'] = $value;
            }
            if ($tag !== 'price') {
                $out['fields'][] = ['field_kind' => 'tag', 'field_name' => $tag, 'field_value' => $value, 'sort_order' => $sortOrder];
            }
        } elseif ($tag === 'weight') {
            $standardValues['weight'] = $value;
        } elseif ($tag === 'dimensions') {
            [$length, $width, $height] = supplier_products_parse_dimensions_triplet($value);
            if ($length !== '') $standardValues['length'] = $length;
            if ($width !== '') $standardValues['width'] = $width;
            if ($height !== '') $standardValues['height'] = $height;
        } elseif ($tag === 'description') {
            $out['description_html'] = $value;
        } elseif (supplier_products_is_feature_source_field_name($tag)) {
            $featureValues[] = $value;
        } else {
            if (supplier_products_xml_name_is_valid($tag)) {
                $out['fields'][] = ['field_kind' => 'tag', 'field_name' => mb_substr($tag, 0, 191, 'UTF-8'), 'field_value' => $value, 'sort_order' => $sortOrder];
            }
        }
    }

    if ($out['brand'] === '' && $vendor !== '') {
        $out['brand'] = $vendor;
    }
    if ($standardValues['brand'] === '' && $out['brand'] !== '') {
        $standardValues['brand'] = $out['brand'];
    }
    if ($standardValues['purchase_price'] === '' && $out['price_original'] !== null) {
        $standardValues['purchase_price'] = (string)$out['price_original'];
    }
    if ($standardValues['stock'] === '') {
        $effectiveStock = max((int)($out['stock_qty'] ?? 0), (int)($out['count_qty'] ?? 0));
        if ($effectiveStock > 0) {
            $standardValues['stock'] = (string)$effectiveStock;
        }
    }

    $out['pictures'] = supplier_products_append_description_images_to_pictures(
        $pictures,
        (string)($out['description_html'] ?? '')
    );
    $featureCount = 0;
    foreach ($featureValues as $value) {
        $value = supplier_products_normalize_feature_value((string)$value);
        if ($value === '') {
            continue;
        }
        $featureCount++;
        if ($featureCount > SUPPLIER_PRODUCTS_FEATURE_MAX) {
            break;
        }
        $sortOrder++;
        $out['fields'][] = [
            'field_kind' => 'feature',
            'field_name' => supplier_products_feature_field_name($featureCount),
            'field_value' => $value,
            'sort_order' => $sortOrder,
        ];
    }
    foreach ($params as $name => $values) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        if (supplier_products_should_skip_source_param('param', $name, $options)) {
            continue;
        }
        foreach ((array)$values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $sortOrder++;
            $out['fields'][] = [
                'field_kind' => 'param',
                'field_name' => mb_substr($name, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
            $out['params'][$name][] = $value;
        }
    }
    foreach ($wbParams as $name => $values) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        if (supplier_products_should_skip_source_param('wb_param', $name, $options)) {
            continue;
        }
        foreach ((array)$values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $sortOrder++;
            $out['fields'][] = [
                'field_kind' => 'wb_param',
                'field_name' => mb_substr($name, 0, 191, 'UTF-8'),
                'field_value' => $value,
                'sort_order' => $sortOrder,
            ];
            $out['params']['[WB] ' . $name][] = $value;
        }
    }

    $out['fields'] = array_merge(
        supplier_products_standard_fields_from_values($standardValues),
        $out['fields']
    );
    return $out;
}

function supplier_products_source_record_hash(array $record): string
{
    return hash('sha256', json_encode([
        'offer_key' => (string)($record['offer_key'] ?? ''),
        'offer_id' => (string)($record['offer_id'] ?? ''),
        'raw_offer_id' => (string)($record['raw_offer_id'] ?? ''),
        'category_path' => (string)($record['category_path'] ?? ''),
        'parsed' => (array)($record['parsed'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

function supplier_products_insert_product_fields(PDO $pdo, int $supplierId, int $productId, array $fields): void
{
    if ($supplierId <= 0 || $productId <= 0 || !$fields) {
        return;
    }
    $parseOptions = supplier_products_parse_options_for_supplier_id($supplierId);
    $insert = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_fields (
            supplier_id, product_id, field_kind, field_name, field_value, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $featureCount = 0;
    foreach ($fields as $field) {
        $kind = supplier_products_validate_field_kind((string)($field['field_kind'] ?? 'tag'));
        $name = trim((string)($field['field_name'] ?? ''));
        if ($kind === 'feature') {
            if ($featureCount >= SUPPLIER_PRODUCTS_FEATURE_MAX) {
                continue;
            }
            $featureCount++;
            $featureIndex = supplier_products_feature_field_index($name);
            $name = supplier_products_feature_field_name($featureIndex > 0 ? $featureIndex : $featureCount);
        }
        if ($name === '') {
            continue;
        }
        if (($kind === 'tag' || $kind === 'attr') && !supplier_products_xml_name_is_valid($name)) {
            continue;
        }
        if (supplier_products_should_skip_source_param($kind, $name, $parseOptions)) {
            continue;
        }
        $insert->execute([
            $supplierId,
            $productId,
            $kind,
            mb_substr($name, 0, 191, 'UTF-8'),
            $kind === 'feature'
                ? supplier_products_normalize_feature_value((string)($field['field_value'] ?? ''))
                : (string)($field['field_value'] ?? ''),
            (int)($field['sort_order'] ?? 0),
        ]);
    }
}

function supplier_products_category_map_from_xml(string $categoriesXml): array
{
    $categoriesXml = trim($categoriesXml);
    if ($categoriesXml === '') {
        return [];
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($categoriesXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$xml instanceof SimpleXMLElement) {
        return [];
    }

    $map = [];
    foreach ($xml->xpath('.//category') ?: [] as $category) {
        if (!$category instanceof SimpleXMLElement) {
            continue;
        }
        $attrs = $category->attributes();
        $id = trim((string)($attrs['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'name' => supplier_products_xml_text($category),
            'parentId' => trim((string)($attrs['parentId'] ?? '')),
        ];
    }
    return $map;
}

function supplier_products_category_map_from_json(string $categoriesJson): array
{
    $categoriesJson = trim($categoriesJson);
    if ($categoriesJson === '') {
        return [];
    }
    $decoded = json_decode($categoriesJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $map = [];
    foreach ($decoded as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? $id));
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'id' => $id,
            'name' => trim((string)($row['name'] ?? '')),
            'parentId' => trim((string)($row['parentId'] ?? '')),
        ];
    }
    return $map;
}

function supplier_products_build_category_path(string $categoryId, array $catMap): string
{
    $categoryId = trim($categoryId);
    if ($categoryId === '' || empty($catMap[$categoryId])) {
        return '';
    }

    $parts = [];
    $seen = [];
    $cur = $categoryId;
    for ($i = 0; $i < 20; $i++) {
        if ($cur === '' || isset($seen[$cur]) || empty($catMap[$cur])) {
            break;
        }
        $seen[$cur] = true;
        $name = trim((string)($catMap[$cur]['name'] ?? ''));
        if ($name !== '') {
            array_unshift($parts, $name);
        }
        $cur = trim((string)($catMap[$cur]['parentId'] ?? ''));
    }
    return $parts ? implode(' -> ', $parts) : '';
}

function supplier_products_replace_catalog_from_feed_path(int $supplierId, string $feedPath, array $cfg = [], string $sourceUrl = ''): array
{
    $source = supplier_products_source_records_from_feed_path($supplierId, $feedPath, $cfg);
    $result = supplier_products_replace_catalog_from_source($supplierId, $source, $cfg, $sourceUrl);
    return [
        'supplier_id' => $supplierId,
        'dataset_id' => supplier_products_dataset_id($supplierId, $cfg),
        'offers_count' => (int)($result['added'] ?? 0),
        'source_sha256' => (string)($source['source_sha256'] ?? ''),
        'source_bytes' => (int)($source['source_bytes'] ?? 0),
        'warnings_json' => (string)($source['warnings_json'] ?? '[]'),
        'dataset' => $result['dataset'] ?? supplier_products_update_dataset_row_from_db($supplierId, $cfg),
    ];
}

function supplier_products_source_records_from_feed_path(int $supplierId, string $feedPath, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    if (!is_file($feedPath)) {
        throw new RuntimeException('Источник товаров поставщика не найден.');
    }

    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $parseOptions = supplier_products_parse_options_for_supplier($supplier, $cfg);
    $scan = scan_xml($feedPath, 0);
    $reader = new XMLReader();
    if (!$reader->open($feedPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть источник товаров поставщика.');
    }

    $categoriesXml = '';
    $catMap = [];
    $records = [];
    $seenOfferKeys = [];
    $sortOrder = 0;

    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'categories') {
                $categoriesXml = trim((string)$reader->readOuterXml());
                $catMap = supplier_products_category_map_from_xml($categoriesXml);
                supplier_products_skip_current_element($reader, 'categories', $reader->depth);
                continue;
            }

            if ($reader->name !== 'offer') {
                continue;
            }

            $sortOrder++;
            $offerXml = trim((string)$reader->readOuterXml());
            supplier_products_skip_current_element($reader, 'offer', $reader->depth);
            if ($offerXml === '') {
                continue;
            }

            $parsed = supplier_products_parse_offer_node_xml($offerXml, $parseOptions);
            $rawOfferId = trim((string)($parsed['offer_id'] ?? ''));
            $codedOfferId = suppliers_apply_supplier_code($rawOfferId, $supplierCode);
            $parsed['offer_id'] = $codedOfferId;

            $baseKey = $codedOfferId !== '' ? $codedOfferId : ('__empty_' . $sortOrder);
            $seenOfferKeys[$baseKey] = (int)($seenOfferKeys[$baseKey] ?? 0) + 1;
            $offerKey = $baseKey;
            if ($seenOfferKeys[$baseKey] > 1) {
                $offerKey .= '__dup_' . $seenOfferKeys[$baseKey];
            }

            $categoryPath = supplier_products_build_category_path((string)($parsed['category_id'] ?? ''), $catMap);
            $records[] = [
                'sort_order' => $sortOrder,
                'offer_key' => mb_substr($offerKey, 0, 191, 'UTF-8'),
                'offer_id' => mb_substr($codedOfferId, 0, 191, 'UTF-8'),
                'raw_offer_id' => $rawOfferId,
                'parsed' => $parsed,
                'category_path' => $categoryPath,
            ];
        }
    } finally {
        $reader->close();
    }

    return [
        'records' => $records,
        'categories_json' => json_encode($catMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_sha256' => hash_file('sha256', $feedPath) ?: '',
        'source_bytes' => (int)(@filesize($feedPath) ?: 0),
        'warnings_json' => json_encode($scan['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
        'offers_count' => count($records),
    ];
}

function supplier_products_unique_offer_key(string $baseKey, array &$used): string
{
    $baseKey = mb_substr(trim($baseKey), 0, 180, 'UTF-8');
    if ($baseKey === '') {
        $baseKey = '__empty_' . (count($used) + 1);
    }
    $key = $baseKey;
    $i = 2;
    while (isset($used[$key])) {
        $suffix = '__dup_' . $i;
        $key = mb_substr($baseKey, 0, 191 - strlen($suffix), 'UTF-8') . $suffix;
        $i++;
    }
    $used[$key] = true;
    return $key;
}

function supplier_products_insert_source_record(PDO $pdo, int $supplierId, array $record, int $sortOrder, array $cfg = []): int
{
    $parsed = (array)($record['parsed'] ?? []);
    $insert = $pdo->prepare("
        INSERT INTO feedtools_supplier_products (
            supplier_id, offer_key, offer_id, raw_hash, sort_order, name, vendor_code,
            category_id, category_path, ozon_category, wb_category, brand, description_html, count_qty, stock_qty,
            price_original, pictures_json, params_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $supplierId,
        mb_substr((string)($record['offer_key'] ?? ''), 0, 191, 'UTF-8'),
        mb_substr((string)($record['offer_id'] ?? ''), 0, 191, 'UTF-8'),
        supplier_products_source_record_hash($record),
        $sortOrder,
        (string)($parsed['name'] ?? ''),
        mb_substr((string)($parsed['vendor_code'] ?? ''), 0, 191, 'UTF-8'),
        mb_substr((string)($parsed['category_id'] ?? ''), 0, 191, 'UTF-8'),
        (string)($record['category_path'] ?? ''),
        mb_substr((string)($parsed['ozon_category'] ?? ''), 0, 191, 'UTF-8'),
        mb_substr((string)($parsed['wb_category'] ?? ''), 0, 191, 'UTF-8'),
        mb_substr((string)($parsed['brand'] ?? ''), 0, 191, 'UTF-8'),
        (string)($parsed['description_html'] ?? ''),
        (int)($parsed['count_qty'] ?? 0),
        (int)($parsed['stock_qty'] ?? 0),
        $parsed['price_original'] ?? null,
        json_encode(array_values((array)($parsed['pictures'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode((array)($parsed['params'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $productId = (int)$pdo->lastInsertId();
    supplier_products_insert_product_fields($pdo, $supplierId, $productId, (array)($parsed['fields'] ?? []));
    return $productId;
}

function supplier_products_bundle_format_number(float $value): string
{
    if (abs($value - round($value)) < 0.00001) {
        return (string)(int)round($value);
    }
    $text = number_format($value, 4, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' ? '0' : $text;
}

function supplier_products_bundle_offer_id(string $baseOfferId, int $qty): string
{
    $baseOfferId = trim($baseOfferId);
    if ($baseOfferId === '') {
        throw new RuntimeException('У исходного товара пустой артикул.');
    }
    if ($qty < 2) {
        throw new RuntimeException('Количество в комплекте должно быть больше 1.');
    }
    if (str_contains($baseOfferId, '##') || bundle_offer_is_bundle($baseOfferId)) {
        throw new RuntimeException('Комплект можно создать только из обычного товара.');
    }
    $offerId = bundle_offer_build($baseOfferId, $qty);
    if ($offerId === '') {
        throw new RuntimeException('Не удалось собрать артикул комплекта.');
    }
    if (mb_strlen($offerId, 'UTF-8') > 191) {
        throw new RuntimeException('Артикул комплекта получился слишком длинным.');
    }
    return $offerId;
}

function supplier_products_bundle_is_unit_quantity_field(array $field): bool
{
    $kind = (string)($field['field_kind'] ?? '');
    if (!in_array($kind, ['param', 'wb_param'], true)) {
        return false;
    }
    return supplier_products_characteristic_group_key((string)($field['field_name'] ?? '')) === 'alias:unit_quantity';
}

function supplier_products_bundle_photo_font_path(array $cfg = []): string
{
    $custom = trim((string)($cfg['paths']['bundle_photo_font'] ?? ''));
    $candidates = array_filter([
        $custom,
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Не найден шрифт для шаблона фото комплекта.');
}

function supplier_products_bundle_photo_source_path(string $url, array $cfg = []): array
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('У исходного товара нет главного фото для шаблона комплекта.');
    }

    $relative = supplier_products_local_image_relative_from_url($url, $cfg);
    if ($relative !== '') {
        $path = supplier_products_local_image_abs_path($relative, $cfg);
        if ($path !== '' && is_file($path)) {
            return ['path' => $path, 'temporary' => false];
        }
    }

    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Главное фото комплекта должно быть локальным или доступным по http/https.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ft_bundle_photo_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл для фото комплекта.');
    }

    $fh = fopen($tmp, 'wb');
    if (!$fh) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось подготовить загрузку фото комплекта.');
    }

    $origin = '';
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if ($host !== '') {
        $origin = $scheme . '://' . $host . '/';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8,*/*;q=0.1',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
    ]);
    if ($origin !== '') {
        curl_setopt($ch, CURLOPT_REFERER, $origin);
    }
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $err = curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    fclose($fh);

    $size = is_file($tmp) ? (int)filesize($tmp) : 0;
    if (!$ok || $http >= 400 || $size <= 0 || $size > 25 * 1024 * 1024) {
        @unlink($tmp);
        $details = [];
        if ($http > 0) {
            $details[] = 'HTTP ' . $http;
        }
        if ($contentType !== '') {
            $details[] = $contentType;
        }
        if ($size > 0) {
            $details[] = $size . ' байт';
        }
        if ($size > 25 * 1024 * 1024) {
            $details[] = 'больше 25 МБ';
        }
        if ($err !== '') {
            $details[] = $err;
        }
        throw new RuntimeException('Не удалось загрузить главное фото для шаблона комплекта' . ($details ? ': ' . implode(', ', $details) : '.'));
    }

    return ['path' => $tmp, 'temporary' => true];
}

function supplier_products_bundle_photo_load(string $path): GdImage
{
    $info = @getimagesize($path);
    if (!is_array($info)) {
        throw new RuntimeException('Файл главного фото не распознан как изображение.');
    }
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width <= 0 || $height <= 0 || ($width * $height) > 36_000_000) {
        throw new RuntimeException('Главное фото слишком большое для шаблона комплекта.');
    }

    $mime = strtolower((string)($info['mime'] ?? ''));
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif' => @imagecreatefromgif($path),
        default => false,
    };
    if (!$image instanceof GdImage) {
        throw new RuntimeException('Поддерживаются только JPG, PNG, WebP и GIF для шаблона комплекта.');
    }
    return $image;
}

function supplier_products_bundle_photo_rounded_rect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    $radius = max(0, min($radius, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2)));
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function supplier_products_bundle_photo_text_box(string $font, int $fontSize, string $text): array
{
    $box = imagettfbbox($fontSize, 0, $font, $text);
    if (!is_array($box)) {
        return ['width' => 0, 'height' => 0, 'min_x' => 0, 'min_y' => 0];
    }
    $xs = [$box[0], $box[2], $box[4], $box[6]];
    $ys = [$box[1], $box[3], $box[5], $box[7]];
    return [
        'width' => max($xs) - min($xs),
        'height' => max($ys) - min($ys),
        'min_x' => min($xs),
        'min_y' => min($ys),
    ];
}

function supplier_products_bundle_photo_template_create(string $sourceUrl, int $supplierId, int $qty, array $cfg = []): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('На сервере не включена библиотека GD для обработки фото.');
    }
    if ($qty < 2) {
        throw new RuntimeException('Количество в комплекте должно быть больше 1.');
    }

    $source = supplier_products_bundle_photo_source_path($sourceUrl, $cfg);
    try {
        $original = supplier_products_bundle_photo_load((string)$source['path']);
        $width = imagesx($original);
        $height = imagesy($original);

        $font = supplier_products_bundle_photo_font_path($cfg);
        $text = $qty . ' шт';
        $fontSize = max(16, min(54, (int)round(min($width, $height) * 0.062)));
        $maxBadgeWidth = (int)round($width * 0.34);
        do {
            $box = supplier_products_bundle_photo_text_box($font, $fontSize, $text);
            $padX = max(14, (int)round($fontSize * 0.62));
            $padY = max(9, (int)round($fontSize * 0.34));
            $iconSize = max(18, (int)round($fontSize * 1.02));
            $gap = max(8, (int)round($fontSize * 0.22));
            $badgeWidth = $iconSize + $gap + (int)$box['width'] + $padX * 2;
            $badgeHeight = max($iconSize + $padY * 2, (int)$box['height'] + $padY * 2);
            if ($badgeWidth <= $maxBadgeWidth || $fontSize <= 14) {
                break;
            }
            $fontSize -= 2;
        } while (true);

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof GdImage) {
            throw new RuntimeException('Не удалось подготовить фото комплекта.');
        }
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

        $clearBottomRight = min(0.95, max(0.84, ($height - ($badgeHeight * 0.72)) / max(1, $height)));
        $scale = min(0.93, $clearBottomRight);
        $dstWidth = max(1, (int)round($width * $scale));
        $dstHeight = max(1, (int)round($height * $scale));
        $dstX = max(0, (int)round(($width - $dstWidth) * 0.22));
        $dstY = max(0, (int)round(($height - $dstHeight) * 0.12));
        imagecopyresampled($canvas, $original, $dstX, $dstY, 0, 0, $dstWidth, $dstHeight, $width, $height);
        unset($original);

        $margin = max(16, (int)round(min($width, $height) * 0.034));
        $badgeX2 = $width - $margin;
        $badgeY2 = $height - $margin;
        $badgeX1 = max($margin, $badgeX2 - $badgeWidth);
        $badgeY1 = max($margin, $badgeY2 - $badgeHeight);
        $radius = max(16, (int)round($badgeHeight * 0.42));

        $shadow = imagecolorallocatealpha($canvas, 15, 23, 42, 104);
        $border = imagecolorallocatealpha($canvas, 37, 99, 235, 0);
        $fill = imagecolorallocatealpha($canvas, 248, 251, 255, 2);
        $accent = imagecolorallocate($canvas, 37, 99, 235);
        $accentDark = imagecolorallocate($canvas, 30, 64, 175);
        $textColor = imagecolorallocate($canvas, 23, 37, 84);
        $textWhite = imagecolorallocate($canvas, 255, 255, 255);

        $borderWidth = max(2, (int)round($fontSize * 0.055));
        supplier_products_bundle_photo_rounded_rect($canvas, $badgeX1 + 3, $badgeY1 + 5, $badgeX2 + 3, $badgeY2 + 5, $radius, $shadow);
        supplier_products_bundle_photo_rounded_rect($canvas, $badgeX1, $badgeY1, $badgeX2, $badgeY2, $radius, $border);
        supplier_products_bundle_photo_rounded_rect(
            $canvas,
            $badgeX1 + $borderWidth,
            $badgeY1 + $borderWidth,
            $badgeX2 - $borderWidth,
            $badgeY2 - $borderWidth,
            max(0, $radius - $borderWidth),
            $fill
        );

        $iconX = $badgeX1 + $padX;
        $iconY = $badgeY1 + (int)round(($badgeHeight - $iconSize) / 2);
        imagefilledellipse(
            $canvas,
            $iconX + (int)round($iconSize / 2),
            $iconY + (int)round($iconSize / 2),
            $iconSize,
            $iconSize,
            $accent
        );
        imagearc(
            $canvas,
            $iconX + (int)round($iconSize / 2),
            $iconY + (int)round($iconSize / 2),
            max(4, $iconSize - 5),
            max(4, $iconSize - 5),
            0,
            360,
            $accentDark
        );
        $iconText = '×';
        $iconBox = supplier_products_bundle_photo_text_box($font, max(10, (int)round($fontSize * 0.58)), $iconText);
        $iconTextX = $iconX + (int)round(($iconSize - (int)$iconBox['width']) / 2) - (int)$iconBox['min_x'];
        $iconTextY = $iconY + (int)round(($iconSize - (int)$iconBox['height']) / 2) - (int)$iconBox['min_y'];
        imagettftext($canvas, max(10, (int)round($fontSize * 0.58)), 0, $iconTextX, $iconTextY, $textWhite, $font, $iconText);

        $textX = $iconX + $iconSize + $gap - (int)$box['min_x'];
        $textY = $badgeY1 + $padY - (int)$box['min_y'];
        imagettftext($canvas, $fontSize, 0, $textX, $textY, $textColor, $font, $text);

        $month = date('Ym');
        $relativeDir = 'supplier_' . max(0, $supplierId) . '/' . $month;
        $baseDir = supplier_products_image_storage_dir($cfg);
        $targetDir = supplier_products_ensure_writable_dir($baseDir . '/' . $relativeDir, 'Не удалось создать папку для фото комплекта.');

        $fileName = date('Ymd_His') . '_bundle_' . $qty . '_' . bin2hex(random_bytes(6)) . '.jpg';
        $targetPath = $targetDir . '/' . $fileName;
        if (!imagejpeg($canvas, $targetPath, 92)) {
            throw new RuntimeException('Не удалось сохранить фото комплекта.');
        }
        unset($canvas);
        @chmod($targetPath, 0664);

        return supplier_products_publish_stored_image($targetPath, $relativeDir . '/' . $fileName, $cfg, true);
    } finally {
        if (!empty($source['temporary'])) {
            @unlink((string)$source['path']);
        }
    }
}

function supplier_products_row_is_bundle(array $product): bool
{
    $offerId = trim((string)($product['offer_id'] ?? ''));
    if ($offerId === '') {
        $offerId = trim((string)($product['offer_key'] ?? ''));
    }
    return $offerId !== '' && bundle_offer_is_bundle($offerId);
}

function supplier_products_delete_candidate_ids_excluding_bundles(array $productsById): array
{
    $ids = [];
    foreach ($productsById as $productId => $product) {
        $productId = (int)$productId;
        if ($productId > 0 && is_array($product) && !supplier_products_row_is_bundle($product)) {
            $ids[] = $productId;
        }
    }
    return $ids;
}

function supplier_products_create_bundle(int $productId, int $qty, string $name, array $values = [], array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }
    if ($qty < 2 || $qty > 9999) {
        throw new RuntimeException('Количество в комплекте должно быть от 2 до 9999.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $source = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($source)) {
        throw new RuntimeException('Товар не найден.');
    }

    $supplierId = (int)($source['supplier_id'] ?? 0);
    $baseOfferId = trim((string)($source['offer_id'] ?? ''));
    $newOfferId = supplier_products_bundle_offer_id($baseOfferId, $qty);
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Название комплекта не может быть пустым.');
    }

    $dup = $pdo->prepare("
        SELECT id
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND (offer_id = ? OR offer_key = ?)
        LIMIT 1
    ");
    $dup->execute([$supplierId, $newOfferId, $newOfferId]);
    if ((int)($dup->fetchColumn() ?: 0) > 0) {
        throw new RuntimeException('Комплект с артикулом ' . $newOfferId . ' уже существует.');
    }

    $fields = supplier_products_fields_for_product($productId);
    $standardValues = supplier_products_standard_empty_values();
    foreach ($fields as $field) {
        if ((string)($field['field_kind'] ?? '') !== 'standard') {
            continue;
        }
        $fieldName = trim((string)($field['field_name'] ?? ''));
        if (supplier_products_is_standard_field_name($fieldName)) {
            $standardValues[$fieldName] = trim((string)($field['field_value'] ?? ''));
        }
    }

    foreach (['length', 'width', 'height', 'weight'] as $key) {
        if (array_key_exists($key, $values)) {
            $standardValues[$key] = trim((string)$values[$key]);
        }
    }

    $priceNum = null;
    $priceRaw = trim((string)($standardValues['purchase_price'] ?? ''));
    if ($priceRaw !== '') {
        $priceNum = supplier_products_parse_float($priceRaw);
    }
    if ($priceNum === null && $source['price_original'] !== null && (string)$source['price_original'] !== '') {
        $priceNum = (float)$source['price_original'];
    }
    $bundlePrice = $priceNum !== null ? ($priceNum * $qty) : null;
    if ($bundlePrice !== null) {
        $standardValues['purchase_price'] = supplier_products_bundle_format_number($bundlePrice);
    }

    $sourceStockRaw = trim((string)($standardValues['stock'] ?? ''));
    $sourceStock = $sourceStockRaw !== '' ? (int)$sourceStockRaw : (int)($source['stock_qty'] ?? 0);
    $bundleStock = bundle_offer_bundle_units_from_base($newOfferId, $sourceStock);
    $standardValues['stock'] = (string)$bundleStock;
    $bundleCount = bundle_offer_bundle_units_from_base($newOfferId, (int)($source['count_qty'] ?? 0));
    $bundlePictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($source['pictures_json'] ?? null));

    $nextFields = [];
    $maxSort = 0;
    foreach ($fields as $field) {
        $kind = supplier_products_validate_field_kind((string)($field['field_kind'] ?? 'tag'));
        $fieldName = trim((string)($field['field_name'] ?? ''));
        if ($fieldName === '' || supplier_products_bundle_is_unit_quantity_field($field)) {
            continue;
        }

        $value = (string)($field['field_value'] ?? '');
        if ($kind === 'standard' && supplier_products_is_standard_field_name($fieldName)) {
            $value = (string)($standardValues[$fieldName] ?? '');
        }
        if ($kind === 'tag' && in_array($fieldName, ['vendorCode', 'article', 'offer_id', 'offerId'], true)) {
            $value = $newOfferId;
        }

        $sortOrder = (int)($field['sort_order'] ?? 0);
        $maxSort = max($maxSort, $sortOrder);
        $nextFields[] = [
            'field_kind' => $kind,
            'field_name' => $fieldName,
            'field_value' => $value,
            'sort_order' => $sortOrder,
        ];
    }

    $nextFields[] = [
        'field_kind' => 'param',
        'field_name' => 'количество_в_единице_товара',
        'field_value' => (string)$qty,
        'sort_order' => $maxSort + 10,
    ];

    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }

    try {
        $insert = $pdo->prepare("
            INSERT INTO feedtools_supplier_products (
                supplier_id, offer_key, offer_id, raw_hash, sort_order, name, vendor_code,
                category_id, category_path, ozon_category, wb_category, brand, description_html, count_qty, stock_qty,
                price_original, pictures_json, params_json
            ) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $supplierId,
            $newOfferId,
            $newOfferId,
            ((int)($source['sort_order'] ?? 0)) + 1,
            $name,
            $newOfferId,
            mb_substr((string)($source['category_id'] ?? ''), 0, 191, 'UTF-8'),
            (string)($source['category_path'] ?? ''),
            mb_substr((string)($source['ozon_category'] ?? ''), 0, 191, 'UTF-8'),
            mb_substr((string)($source['wb_category'] ?? ''), 0, 191, 'UTF-8'),
            mb_substr((string)($source['brand'] ?? ''), 0, 191, 'UTF-8'),
            (string)($source['description_html'] ?? ''),
            $bundleCount,
            $bundleStock,
            $bundlePrice,
            json_encode($bundlePictures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)($source['params_json'] ?? '{}'),
        ]);
        $newProductId = (int)$pdo->lastInsertId();

        supplier_products_insert_product_fields($pdo, $supplierId, $newProductId, $nextFields);
        foreach (['length', 'width', 'height', 'weight', 'stock', 'purchase_price'] as $key) {
            if (array_key_exists($key, $standardValues)) {
                supplier_products_set_standard_field_value($pdo, $supplierId, $newProductId, $key, (string)$standardValues[$key]);
            }
        }

        $fresh = supplier_products_sync_product_summary_from_db($newProductId, $cfg);
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);

        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($generatedPictureUrl !== '') {
            supplier_products_delete_local_images_if_unreferenced([$generatedPictureUrl], $cfg);
        }
        throw $e;
    }

    return [
        'product_id' => $newProductId,
        'offer_id' => $newOfferId,
        'bundle_qty' => $qty,
        'product' => $fresh,
    ];
}

function supplier_products_standard_values_for_product(int $productId): array
{
    $values = supplier_products_standard_empty_values();
    if ($productId <= 0) {
        return $values;
    }
    foreach (supplier_products_fields_for_product($productId) as $field) {
        if ((string)($field['field_kind'] ?? '') !== 'standard') {
            continue;
        }
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name !== '' && supplier_products_is_standard_field_name($name)) {
            $values[$name] = trim((string)($field['field_value'] ?? ''));
        }
    }
    return $values;
}

function supplier_products_bulk_bundle_factor($value, float $fallback = 1.0): float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    $parsed = supplier_products_parse_float($raw);
    if ($parsed === null || $parsed <= 0) {
        throw new RuntimeException('Коэффициент для комплектов должен быть положительным числом.');
    }
    return $parsed;
}

function supplier_products_bulk_bundle_numeric_parts(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $pure = supplier_products_parse_float($value);
    if ($pure !== null) {
        return ['number' => $pure, 'suffix' => ''];
    }
    if (!preg_match('/^([+-]?\d+(?:[.,]\d+)?)(.*)$/u', $value, $m)) {
        return null;
    }
    $number = supplier_products_parse_float((string)$m[1]);
    if ($number === null) {
        return null;
    }
    return [
        'number' => $number,
        'suffix' => (string)($m[2] ?? ''),
    ];
}

function supplier_products_bulk_bundle_multiply_value(string $value, float $factor): ?string
{
    $parts = supplier_products_bulk_bundle_numeric_parts($value);
    if ($parts === null) {
        return null;
    }
    return supplier_products_bundle_format_number((float)$parts['number'] * $factor) . (string)$parts['suffix'];
}

function supplier_products_bulk_bundle_name(array $product, array $options): string
{
    $name = (string)($product['name'] ?? '');
    if (!empty($options['name_prefix_enabled'])) {
        $name = (string)($options['name_prefix_text'] ?? '') . $name;
    }
    if (!empty($options['name_suffix_enabled'])) {
        $name .= (string)($options['name_suffix_text'] ?? '');
    }
    $name = trim($name);
    if ($name === '') {
        $offerId = trim((string)($product['offer_id'] ?? ''));
        $name = $offerId !== '' ? ('Комплект ' . $offerId) : 'Комплект товара';
    }
    return $name;
}

function supplier_products_bulk_bundle_values(int $productId, int $qty, array $options): array
{
    $standard = supplier_products_standard_values_for_product($productId);
    $values = [];

    $weightMode = (string)($options['weight_mode'] ?? 'keep');
    if ($weightMode === 'fixed') {
        $weight = trim((string)($options['weight_fixed'] ?? ''));
        if ($weight === '') {
            throw new RuntimeException('Для фиксированного веса укажи значение.');
        }
        $values['weight'] = $weight;
    } elseif ($weightMode === 'multiply') {
        $factor = supplier_products_bulk_bundle_factor($options['weight_factor'] ?? $qty, (float)$qty);
        $next = supplier_products_bulk_bundle_multiply_value((string)($standard['weight'] ?? ''), $factor);
        if ($next !== null) {
            $values['weight'] = $next;
        }
    } elseif ($weightMode !== 'keep') {
        throw new RuntimeException('Неизвестный режим изменения веса комплекта.');
    }

    $sizeMode = (string)($options['size_mode'] ?? 'keep');
    if ($sizeMode === 'fixed') {
        foreach (['length', 'width', 'height'] as $key) {
            $value = trim((string)($options[$key . '_fixed'] ?? ''));
            if ($value === '') {
                throw new RuntimeException('Для фиксированных размеров укажи длину, ширину и высоту.');
            }
            $values[$key] = $value;
        }
    } elseif ($sizeMode === 'multiply_min_side') {
        $factor = supplier_products_bulk_bundle_factor($options['size_factor'] ?? $qty, (float)$qty);
        $numericSides = [];
        foreach (['length', 'width', 'height'] as $key) {
            $parts = supplier_products_bulk_bundle_numeric_parts((string)($standard[$key] ?? ''));
            $num = $parts !== null ? (float)$parts['number'] : null;
            if ($parts !== null && $num !== null && $num > 0) {
                $numericSides[$key] = $parts;
            }
        }
        if ($numericSides) {
            $minKey = array_key_first($numericSides);
            $minValue = (float)$numericSides[$minKey]['number'];
            foreach ($numericSides as $key => $parts) {
                $value = (float)$parts['number'];
                if ($value < $minValue) {
                    $minKey = $key;
                    $minValue = $value;
                }
            }
            $values[$minKey] = supplier_products_bundle_format_number($minValue * $factor) . (string)$numericSides[$minKey]['suffix'];
        }
    } elseif ($sizeMode !== 'keep') {
        throw new RuntimeException('Неизвестный режим изменения размеров комплекта.');
    }

    return $values;
}

function supplier_products_bulk_create_bundles(int $datasetId, array $offerIds, int $qty, array $options = [], array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    if ($qty < 2 || $qty > 9999) {
        throw new RuntimeException('Количество в комплекте должно быть от 2 до 9999.');
    }
    $scope = 'selected';

    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $productsMap = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($productsMap);
    $products = [];
    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT id, offer_id, name
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $products[(int)($row['id'] ?? 0)] = $row;
        }
    }

    $stats = [
        'requested' => count($productsMap),
        'created' => 0,
        'skipped_bundles' => 0,
        'skipped_existing' => 0,
        'failed' => 0,
    ];
    $created = [];
    $errors = [];

    foreach ($products as $productId => $product) {
        $offerId = trim((string)($product['offer_id'] ?? ''));
        if ($offerId === '' || bundle_offer_is_bundle($offerId) || str_contains($offerId, '##')) {
            $stats['skipped_bundles']++;
            continue;
        }

        try {
            $name = supplier_products_bulk_bundle_name($product, $options);
            $values = supplier_products_bulk_bundle_values((int)$productId, $qty, $options);
            $result = supplier_products_create_bundle((int)$productId, $qty, $name, $values, $cfg);
            $stats['created']++;
            $created[] = [
                'source_product_id' => (int)$productId,
                'source_offer_id' => $offerId,
                'product_id' => (int)($result['product_id'] ?? 0),
                'offer_id' => (string)($result['offer_id'] ?? ''),
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'уже существует')) {
                $stats['skipped_existing']++;
            } else {
                $stats['failed']++;
                if (count($errors) < 20) {
                    $errors[] = [
                        'offer_id' => $offerId,
                        'error' => $message,
                    ];
                }
            }
        }
    }

    supplier_products_update_dataset_row_from_db($supplierId, $cfg);

    return [
        'stats' => $stats,
        'created' => $created,
        'errors' => $errors,
    ];
}

function supplier_products_source_characteristic_fields(array $parsed): array
{
    $out = [];
    foreach ((array)($parsed['fields'] ?? []) as $field) {
        $kind = (string)($field['field_kind'] ?? '');
        if (!in_array($kind, ['param', 'wb_param'], true)) {
            continue;
        }
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $out[] = [
            'field_kind' => $kind,
            'field_name' => mb_substr($name, 0, 191, 'UTF-8'),
            'field_value' => (string)($field['field_value'] ?? ''),
            'sort_order' => (int)($field['sort_order'] ?? 0),
        ];
    }
    return $out;
}

function supplier_products_source_standard_values(array $parsed): array
{
    $values = supplier_products_standard_empty_values();
    foreach ((array)($parsed['fields'] ?? []) as $field) {
        if ((string)($field['field_kind'] ?? '') !== 'standard') {
            continue;
        }
        $name = trim((string)($field['field_name'] ?? ''));
        if (!supplier_products_is_standard_field_name($name)) {
            continue;
        }
        $values[$name] = (string)($field['field_value'] ?? '');
    }
    return $values;
}

function supplier_products_source_tag_value(array $parsed, array $names): string
{
    $wanted = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $wanted[$name] = true;
        }
    }
    if (!$wanted) {
        return '';
    }
    foreach ((array)($parsed['fields'] ?? []) as $field) {
        if ((string)($field['field_kind'] ?? '') !== 'tag') {
            continue;
        }
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name === '' || !isset($wanted[$name])) {
            continue;
        }
        $value = trim((string)($field['field_value'] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function supplier_products_set_standard_field_value(PDO $pdo, int $supplierId, int $productId, string $name, string $value): bool
{
    if ($supplierId <= 0 || $productId <= 0 || !supplier_products_is_standard_field_name($name)) {
        return false;
    }

    $st = $pdo->prepare("
        SELECT id, field_name, field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'standard'
          AND field_name = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId, $name]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $changed = false;

    if ($rows) {
        $first = $rows[0];
        if ((string)($first['field_value'] ?? '') !== $value) {
            $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_value = ? WHERE id = ?");
            $upd->execute([$value, (int)$first['id']]);
            $changed = true;
        }
        $duplicateIds = [];
        foreach (array_slice($rows, 1) as $row) {
            $duplicateIds[] = (int)($row['id'] ?? 0);
        }
        $duplicateIds = array_values(array_filter($duplicateIds));
        if ($duplicateIds) {
            $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN (" . implode(',', array_fill(0, count($duplicateIds), '?')) . ")");
            $del->execute($duplicateIds);
            $changed = true;
        }
        return $changed;
    }

    $keys = supplier_products_standard_field_keys();
    $idx = array_search($name, $keys, true);
    $sortOrder = $idx === false ? 10 : (($idx + 1) * 10);
    $ins = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_fields (
            supplier_id, product_id, field_kind, field_name, field_value, sort_order
        ) VALUES (?, ?, 'standard', ?, ?, ?)
    ");
    $ins->execute([$supplierId, $productId, $name, $value, $sortOrder]);
    return true;
}

function supplier_products_standard_field_value(PDO $pdo, int $productId, string $name): string
{
    if ($productId <= 0 || !supplier_products_is_standard_field_name($name)) {
        return '';
    }

    $st = $pdo->prepare("
        SELECT field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'standard'
          AND field_name = ?
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");
    $st->execute([$productId, $name]);
    return (string)($st->fetchColumn() ?: '');
}

function supplier_products_tag_field_value(PDO $pdo, int $productId, string $name): string
{
    $name = trim($name);
    if ($productId <= 0 || $name === '') {
        return '';
    }

    $st = $pdo->prepare("
        SELECT field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'tag'
          AND field_name = ?
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");
    $st->execute([$productId, $name]);
    return (string)($st->fetchColumn() ?: '');
}

function supplier_products_brand_bulk_replace_field(string $marketplace): array
{
    $marketplace = trim($marketplace);
    if ($marketplace === 'wb' || $marketplace === 'wildberries') {
        return [
            'marketplace' => 'wb',
            'field' => 'brand_wb',
            'category_column' => 'wb_category',
            'category_label' => 'WB',
        ];
    }
    if ($marketplace === 'ozon') {
        return [
            'marketplace' => 'ozon',
            'field' => 'brand_ozon',
            'category_column' => 'ozon_category',
            'category_label' => 'Ozon',
        ];
    }
    throw new RuntimeException('Неподдерживаемый маркетплейс бренда.');
}

function supplier_products_norm_brand_compare(string $value): string
{
    $value = preg_replace('~\s+~u', ' ', trim($value));
    return function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value);
}

function supplier_products_brand_bulk_replace_candidates(int $productId, string $marketplace, string $oldBrand, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $oldBrand = trim($oldBrand);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }
    if ($oldBrand === '') {
        return [
            'scope' => [],
            'products' => [],
        ];
    }

    $meta = supplier_products_brand_bulk_replace_field($marketplace);
    $categoryColumn = (string)$meta['category_column'];
    $fieldName = (string)$meta['field'];
    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id, {$categoryColumn} AS category_value FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($base)) {
        throw new RuntimeException('Товар не найден.');
    }
    $supplierId = (int)($base['supplier_id'] ?? 0);
    $categoryValue = trim((string)($base['category_value'] ?? ''));
    if ($supplierId <= 0 || $categoryValue === '') {
        return [
            'scope' => [
                'supplier_id' => $supplierId,
                'marketplace' => (string)$meta['marketplace'],
                'field' => $fieldName,
                'category_column' => $categoryColumn,
                'category_value' => $categoryValue,
            ],
            'products' => [],
        ];
    }

    $sql = "
        SELECT
            p.id,
            p.offer_id,
            p.name,
            p.brand,
            (
                SELECT f.id
                FROM feedtools_supplier_product_fields f
                WHERE f.product_id = p.id
                  AND f.field_kind = 'standard'
                  AND f.field_name = ?
                ORDER BY f.sort_order ASC, f.id ASC
                LIMIT 1
            ) AS target_field_id,
            (
                SELECT f.field_value
                FROM feedtools_supplier_product_fields f
                WHERE f.product_id = p.id
                  AND f.field_kind = 'standard'
                  AND f.field_name = ?
                ORDER BY f.sort_order ASC, f.id ASC
                LIMIT 1
            ) AS target_brand,
            (
                SELECT f.field_value
                FROM feedtools_supplier_product_fields f
                WHERE f.product_id = p.id
                  AND f.field_kind = 'standard'
                  AND f.field_name = 'brand'
                ORDER BY f.sort_order ASC, f.id ASC
                LIMIT 1
            ) AS base_brand
        FROM feedtools_supplier_products p
        WHERE p.supplier_id = ?
          AND p.{$categoryColumn} = ?
        ORDER BY p.sort_order ASC, p.id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$fieldName, $fieldName, $supplierId, $categoryValue]);
    $oldKey = supplier_products_norm_brand_compare($oldBrand);
    $products = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $targetFieldId = (int)($row['target_field_id'] ?? 0);
        $targetBrand = (string)($row['target_brand'] ?? '');
        $baseBrand = trim((string)($row['base_brand'] ?? ''));
        $productBrand = trim((string)($row['brand'] ?? ''));
        $effective = $targetFieldId > 0 ? trim($targetBrand) : ($baseBrand !== '' ? $baseBrand : $productBrand);
        if (supplier_products_norm_brand_compare($effective) !== $oldKey) {
            continue;
        }
        $products[] = [
            'id' => (int)($row['id'] ?? 0),
            'offer_id' => (string)($row['offer_id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'brand' => $effective,
        ];
    }

    return [
        'scope' => [
            'supplier_id' => $supplierId,
            'marketplace' => (string)$meta['marketplace'],
            'field' => $fieldName,
            'category_column' => $categoryColumn,
            'category_value' => $categoryValue,
            'category_label' => (string)$meta['category_label'],
        ],
        'products' => $products,
    ];
}

function supplier_products_brand_bulk_replace_preview(int $productId, string $marketplace, string $oldBrand, string $newBrand, array $cfg = []): array
{
    $oldBrand = trim($oldBrand);
    $newBrand = trim($newBrand);
    if ($oldBrand === '' || $newBrand === '' || $oldBrand === $newBrand) {
        return [
            'count' => 0,
            'products' => [],
            'old_brand' => $oldBrand,
            'new_brand' => $newBrand,
        ];
    }
    $scope = supplier_products_brand_bulk_replace_candidates($productId, $marketplace, $oldBrand, $cfg);
    $products = (array)($scope['products'] ?? []);
    return [
        'count' => count($products),
        'products' => array_slice($products, 0, 10),
        'old_brand' => $oldBrand,
        'new_brand' => $newBrand,
        'scope' => (array)($scope['scope'] ?? []),
    ];
}

function supplier_products_brand_bulk_replace_by_category(int $productId, string $marketplace, string $oldBrand, string $newBrand, array $cfg = []): array
{
    $oldBrand = trim($oldBrand);
    $newBrand = trim($newBrand);
    if ($oldBrand === '' || $newBrand === '') {
        throw new RuntimeException('Укажи старый и новый бренд.');
    }
    if ($oldBrand === $newBrand) {
        return [
            'products_changed' => 0,
            'old_brand' => $oldBrand,
            'new_brand' => $newBrand,
        ];
    }
    $scope = supplier_products_brand_bulk_replace_candidates($productId, $marketplace, $oldBrand, $cfg);
    $scopeInfo = (array)($scope['scope'] ?? []);
    $products = (array)($scope['products'] ?? []);
    $supplierId = (int)($scopeInfo['supplier_id'] ?? 0);
    $fieldName = (string)($scopeInfo['field'] ?? '');
    if ($supplierId <= 0 || $fieldName === '' || !$products) {
        return [
            'products_changed' => 0,
            'old_brand' => $oldBrand,
            'new_brand' => $newBrand,
            'scope' => $scopeInfo,
        ];
    }

    $pdo = db();
    $changed = 0;
    foreach ($products as $product) {
        $pid = (int)($product['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (supplier_products_set_standard_field_value($pdo, $supplierId, $pid, $fieldName, $newBrand)) {
            supplier_products_sync_product_summary_from_db($pid, $cfg);
            $changed++;
        }
    }
    if ($changed > 0) {
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    }

    return [
        'products_changed' => $changed,
        'old_brand' => $oldBrand,
        'new_brand' => $newBrand,
        'scope' => $scopeInfo,
    ];
}

function supplier_products_field_group_key(array $field): string
{
    $kind = (string)($field['field_kind'] ?? '');
    $name = trim((string)($field['field_name'] ?? ''));
    $nameKey = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return $kind . "\0" . $nameKey;
}

function supplier_products_apply_characteristic_import(PDO $pdo, int $supplierId, int $productId, array $sourceFields, string $mode): array
{
    $stats = ['added' => 0, 'replaced' => 0, 'deleted' => 0, 'changed' => false];
    if ($mode === 'no_update') {
        return $stats;
    }

    $st = $pdo->prepare("
        SELECT *
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind IN ('param', 'wb_param')
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId]);
    $existingGroups = [];
    $allExistingIds = [];
    while ($field = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = supplier_products_field_group_key($field);
        $existingGroups[$key][] = $field;
        $allExistingIds[] = (int)($field['id'] ?? 0);
    }

    $sourceGroups = [];
    foreach ($sourceFields as $field) {
        $sourceGroups[supplier_products_field_group_key($field)][] = $field;
    }

    $deleteIds = static function (PDO $pdo, array $ids) use (&$stats): void {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN ({$placeholders})");
        $del->execute($ids);
        $stats['deleted'] += count($ids);
        $stats['changed'] = true;
    };

    $insertFields = static function (PDO $pdo, int $supplierId, int $productId, array $fields) use (&$stats): void {
        if (!$fields) {
            return;
        }
        $ins = $pdo->prepare("
            INSERT INTO feedtools_supplier_product_fields (
                supplier_id, product_id, field_kind, field_name, field_value, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($fields as $field) {
            $ins->execute([
                $supplierId,
                $productId,
                supplier_products_validate_field_kind((string)($field['field_kind'] ?? 'param')),
                supplier_products_validate_field_name((string)($field['field_kind'] ?? 'param'), (string)($field['field_name'] ?? '')),
                (string)($field['field_value'] ?? ''),
                (int)($field['sort_order'] ?? 0),
            ]);
            $stats['added']++;
            $stats['changed'] = true;
        }
    };

    if ($mode === 'replace_all') {
        $deleteIds($pdo, $allExistingIds);
        foreach ($sourceGroups as $fields) {
            $insertFields($pdo, $supplierId, $productId, $fields);
        }
        return $stats;
    }

    foreach ($sourceGroups as $key => $fields) {
        $existing = (array)($existingGroups[$key] ?? []);
        if ($mode === 'add_only' && $existing) {
            continue;
        }
        if ($mode === 'add_replace' && $existing) {
            $deleteIds($pdo, array_map(static fn($f) => (int)($f['id'] ?? 0), $existing));
            $stats['replaced']++;
        }
        $insertFields($pdo, $supplierId, $productId, $fields);
    }

    return $stats;
}

function supplier_products_record_source_meta(PDO $pdo, int $supplierId, array $source, array $cfg = [], string $sourceUrl = ''): void
{
    $categoriesJson = trim((string)($source['categories_json'] ?? ''));
    $meta = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_meta (
            supplier_id, dataset_id, source_url, source_sha256, source_bytes, categories_json,
            offers_count, warnings_json, last_imported_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            dataset_id = VALUES(dataset_id),
            source_url = CASE WHEN VALUES(source_url) <> '' THEN VALUES(source_url) ELSE source_url END,
            source_sha256 = VALUES(source_sha256),
            source_bytes = VALUES(source_bytes),
            categories_json = CASE WHEN VALUES(categories_json) <> '' THEN VALUES(categories_json) ELSE categories_json END,
            offers_count = VALUES(offers_count),
            warnings_json = VALUES(warnings_json),
            last_imported_at = NOW()
    ");
    $meta->execute([
        $supplierId,
        supplier_products_dataset_id($supplierId, $cfg),
        $sourceUrl,
        (string)($source['source_sha256'] ?? ''),
        (int)($source['source_bytes'] ?? 0),
        $categoriesJson,
        supplier_products_count($supplierId, $cfg),
        (string)($source['warnings_json'] ?? '[]'),
    ]);
}

function supplier_products_existing_maps(PDO $pdo, int $supplierId, string $supplierCode): array
{
    $stmt = $pdo->prepare("SELECT * FROM feedtools_supplier_products WHERE supplier_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$supplierId]);

    $byOfferId = [];
    $byOfferKey = [];
    $byId = [];
    while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int)($product['id'] ?? 0);
        if ($productId > 0) {
            $byId[$productId] = $product;
        }
        $offerKey = trim((string)($product['offer_key'] ?? ''));
        if ($offerKey !== '' && !isset($byOfferKey[$offerKey])) {
            $byOfferKey[$offerKey] = $product;
        }
        $offerId = trim((string)($product['offer_id'] ?? ''));
        if ($offerId !== '' && !isset($byOfferId[$offerId])) {
            $byOfferId[$offerId] = $product;
        }
        $coded = suppliers_apply_supplier_code($offerId, $supplierCode);
        if ($coded !== '' && !isset($byOfferId[$coded])) {
            $byOfferId[$coded] = $product;
        }
    }

    return ['by_offer_id' => $byOfferId, 'by_offer_key' => $byOfferKey, 'by_id' => $byId];
}

function supplier_products_match_existing_product(array $record, array $existingByOfferId, array $existingByOfferKey = []): ?array
{
    $offerKey = trim((string)($record['offer_key'] ?? ''));
    if ($offerKey !== '' && isset($existingByOfferKey[$offerKey]) && is_array($existingByOfferKey[$offerKey])) {
        return $existingByOfferKey[$offerKey];
    }
    foreach ([
        trim((string)($record['offer_id'] ?? '')),
        trim((string)($record['raw_offer_id'] ?? '')),
    ] as $candidate) {
        if ($candidate !== '' && isset($existingByOfferId[$candidate]) && is_array($existingByOfferId[$candidate])) {
            return $existingByOfferId[$candidate];
        }
    }
    return null;
}

function supplier_products_selected_offer_id_set(array $selectedIds, string $supplierCode): array
{
    $set = [];
    foreach ($selectedIds as $selectedId) {
        $selectedId = trim((string)$selectedId);
        if ($selectedId === '') {
            continue;
        }
        $set[$selectedId] = true;
        $coded = suppliers_apply_supplier_code($selectedId, $supplierCode);
        if ($coded !== '') {
            $set[$coded] = true;
        }
    }
    return $set;
}

function supplier_products_existing_product_is_selected(array $existing, array $selectedIds, string $supplierCode): bool
{
    if (!$selectedIds) {
        return false;
    }
    $offerId = trim((string)($existing['offer_id'] ?? ''));
    if ($offerId !== '' && isset($selectedIds[$offerId])) {
        return true;
    }
    $coded = suppliers_apply_supplier_code($offerId, $supplierCode);
    return $coded !== '' && isset($selectedIds[$coded]);
}

function supplier_products_record_is_selected(array $record, array $existing, array $selectedIds, string $supplierCode): bool
{
    if (!$selectedIds) {
        return false;
    }
    foreach ([
        trim((string)($record['offer_id'] ?? '')),
        trim((string)($record['raw_offer_id'] ?? '')),
        trim((string)($existing['offer_id'] ?? '')),
    ] as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (isset($selectedIds[$candidate])) {
            return true;
        }
        $coded = suppliers_apply_supplier_code($candidate, $supplierCode);
        if ($coded !== '' && isset($selectedIds[$coded])) {
            return true;
        }
    }
    return false;
}

function supplier_products_update_prices_stock_from_source(int $supplierId, array $source, bool $zeroMissing, array $cfg = [], string $sourceUrl = '', array $options = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $records = (array)($source['records'] ?? []);
    if (!$records) {
        throw new RuntimeException('В источнике не найдено товаров.');
    }
    $progress = is_callable($options['progress'] ?? null) ? $options['progress'] : null;

    $pdo = db();
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }
    $selectedIds = supplier_products_selected_offer_id_set((array)($options['selected_offer_ids'] ?? []), $supplierCode);
    if ($scope === 'selected' && !$selectedIds) {
        throw new RuntimeException('Для операции только по выбранным товарам сначала выбери товары в таблице.');
    }

    $maps = supplier_products_existing_maps($pdo, $supplierId, $supplierCode);
    $existingByOfferId = (array)($maps['by_offer_id'] ?? []);
    $existingByOfferKey = (array)($maps['by_offer_key'] ?? []);
    $existingById = (array)($maps['by_id'] ?? []);

    $stats = [
        'source_offers' => count($records),
        'matched' => 0,
        'updated' => 0,
        'prices_updated' => 0,
        'stocks_updated' => 0,
        'zeroed_missing' => 0,
        'added' => 0,
        'skipped_existing' => 0,
        'skipped_new' => 0,
        'skipped_scope' => 0,
    ];
    $seenProducts = [];
    $changedProducts = [];
    $done = 0;
    $total = count($records) + ($zeroMissing ? count($existingById) : 0);
    if ($progress) {
        $progress(0, max(1, $total), 'import', 'Обновляю цены и остатки');
    }

    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
            $done++;
            $existing = supplier_products_match_existing_product($record, $existingByOfferId, $existingByOfferKey);
            if (!is_array($existing)) {
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю цены и остатки');
                }
                continue;
            }
            $productId = (int)($existing['id'] ?? 0);
            if ($productId <= 0) {
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю цены и остатки');
                }
                continue;
            }
            if ($scope === 'selected' && !supplier_products_record_is_selected($record, $existing, $selectedIds, $supplierCode)) {
                $stats['skipped_scope']++;
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю цены и остатки');
                }
                continue;
            }
            $stats['matched']++;
            $seenProducts[$productId] = true;

            $standard = supplier_products_source_standard_values((array)($record['parsed'] ?? []));
            $price = trim((string)($standard['purchase_price'] ?? ''));
            if ($price !== '' && supplier_products_set_standard_field_value($pdo, $supplierId, $productId, 'purchase_price', $price)) {
                $stats['prices_updated']++;
                $changedProducts[$productId] = true;
            }

            $stock = trim((string)($standard['stock'] ?? ''));
            if ($stock !== '' && supplier_products_set_standard_field_value($pdo, $supplierId, $productId, 'stock', $stock)) {
                $stats['stocks_updated']++;
                $changedProducts[$productId] = true;
            }
            if ($progress && ($done % 100) === 0) {
                $progress($done, max(1, $total), 'import', 'Обновляю цены и остатки');
            }
        }

        if ($zeroMissing) {
            foreach ($existingById as $productId => $product) {
                $done++;
                $productId = (int)$productId;
                if ($productId <= 0 || isset($seenProducts[$productId])) {
                    if ($progress && ($done % 100) === 0) {
                        $progress($done, max(1, $total), 'import', 'Обнуляю отсутствующие товары');
                    }
                    continue;
                }
                if ($scope === 'selected' && !supplier_products_existing_product_is_selected((array)$product, $selectedIds, $supplierCode)) {
                    if ($progress && ($done % 100) === 0) {
                        $progress($done, max(1, $total), 'import', 'Обнуляю отсутствующие товары');
                    }
                    continue;
                }
                if (supplier_products_row_is_bundle((array)$product)) {
                    if ($progress && ($done % 100) === 0) {
                        $progress($done, max(1, $total), 'import', 'Обнуляю отсутствующие товары');
                    }
                    continue;
                }
                if (supplier_products_set_standard_field_value($pdo, $supplierId, $productId, 'stock', '0')) {
                    $stats['zeroed_missing']++;
                    $changedProducts[$productId] = true;
                }
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обнуляю отсутствующие товары');
                }
            }
        }

        if ($progress) {
            $progress($done, max(1, $total), 'summary', 'Обновляю сводные данные товаров');
        }
        foreach (array_keys($changedProducts) as $changedProductId) {
            supplier_products_sync_product_summary_from_db((int)$changedProductId, $cfg);
        }
        supplier_products_record_source_meta($pdo, $supplierId, $source, $cfg, $sourceUrl);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    $stats['updated'] = count($changedProducts);
    $stats['changed_products'] = count($changedProducts);
    $stats['dataset'] = $datasetInfo;
    return $stats;
}

function supplier_products_update_dimensions_from_source(int $supplierId, array $source, array $cfg = [], string $sourceUrl = '', array $options = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $records = (array)($source['records'] ?? []);
    if (!$records) {
        throw new RuntimeException('В источнике не найдено товаров.');
    }
    $progress = is_callable($options['progress'] ?? null) ? $options['progress'] : null;

    $pdo = db();
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }
    $selectedIds = supplier_products_selected_offer_id_set((array)($options['selected_offer_ids'] ?? []), $supplierCode);
    if ($scope === 'selected' && !$selectedIds) {
        throw new RuntimeException('Для операции только по выбранным товарам сначала выбери товары в таблице.');
    }

    $maps = supplier_products_existing_maps($pdo, $supplierId, $supplierCode);
    $existingByOfferId = (array)($maps['by_offer_id'] ?? []);
    $existingByOfferKey = (array)($maps['by_offer_key'] ?? []);

    $stats = [
        'source_offers' => count($records),
        'matched' => 0,
        'updated' => 0,
        'dimensions_updated' => 0,
        'weights_updated' => 0,
        'added' => 0,
        'skipped_existing' => 0,
        'skipped_new' => 0,
        'skipped_scope' => 0,
    ];
    $changedProducts = [];
    $done = 0;
    $total = count($records);
    if ($progress) {
        $progress(0, max(1, $total), 'import', 'Обновляю размеры');
    }

    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
            $done++;
            $existing = supplier_products_match_existing_product($record, $existingByOfferId, $existingByOfferKey);
            if (!is_array($existing)) {
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю размеры');
                }
                continue;
            }
            $productId = (int)($existing['id'] ?? 0);
            if ($productId <= 0) {
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю размеры');
                }
                continue;
            }
            if ($scope === 'selected' && !supplier_products_record_is_selected($record, $existing, $selectedIds, $supplierCode)) {
                $stats['skipped_scope']++;
                if ($progress && ($done % 100) === 0) {
                    $progress($done, max(1, $total), 'import', 'Обновляю размеры');
                }
                continue;
            }
            $stats['matched']++;

            $standard = supplier_products_source_standard_values((array)($record['parsed'] ?? []));
            $dimensionChanged = false;
            foreach (['length', 'width', 'height'] as $key) {
                $value = trim((string)($standard[$key] ?? ''));
                if ($value !== '' && supplier_products_set_standard_field_value($pdo, $supplierId, $productId, $key, $value)) {
                    $dimensionChanged = true;
                    $changedProducts[$productId] = true;
                }
            }
            if ($dimensionChanged) {
                $stats['dimensions_updated']++;
            }

            $weight = trim((string)($standard['weight'] ?? ''));
            if ($weight !== '' && supplier_products_set_standard_field_value($pdo, $supplierId, $productId, 'weight', $weight)) {
                $stats['weights_updated']++;
                $changedProducts[$productId] = true;
            }
            if ($progress && ($done % 100) === 0) {
                $progress($done, max(1, $total), 'import', 'Обновляю размеры');
            }
        }

        if ($progress) {
            $progress($done, max(1, $total), 'summary', 'Обновляю сводные данные товаров');
        }
        foreach (array_keys($changedProducts) as $changedProductId) {
            supplier_products_sync_product_summary_from_db((int)$changedProductId, $cfg);
        }
        supplier_products_record_source_meta($pdo, $supplierId, $source, $cfg, $sourceUrl);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    $stats['updated'] = count($changedProducts);
    $stats['changed_products'] = count($changedProducts);
    $stats['dataset'] = $datasetInfo;
    return $stats;
}

function supplier_products_replace_catalog_from_source(int $supplierId, array $source, array $cfg = [], string $sourceUrl = ''): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $records = (array)($source['records'] ?? []);
    if (!$records) {
        throw new RuntimeException('В источнике не найдено товаров.');
    }
    $progress = is_callable($source['progress'] ?? null) ? $source['progress'] : null;

    $pdo = db();
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $existingMaps = supplier_products_existing_maps($pdo, $supplierId, $supplierCode);
    $existingById = (array)($existingMaps['by_id'] ?? []);
    $deleteIds = supplier_products_delete_candidate_ids_excluding_bundles($existingById);
    $deletedBefore = count($deleteIds);
    $deletedPictures = $deleteIds ? supplier_products_picture_urls_for_supplier($pdo, $supplierId, $deleteIds) : [];
    $insertedIds = [];
    $done = 0;
    $total = count($records);
    if ($progress) {
        $progress(0, max(1, $total), 'replace', 'Очищаю текущий каталог');
    }
    $pdo->beginTransaction();
    try {
        $usedOfferKeys = [];
        if ($deleteIds) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $delFields = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE supplier_id = ? AND product_id IN ({$placeholders})");
            $delFields->execute(array_merge([$supplierId], $deleteIds));

            $delProducts = $pdo->prepare("DELETE FROM feedtools_supplier_products WHERE supplier_id = ? AND id IN ({$placeholders})");
            $delProducts->execute(array_merge([$supplierId], $deleteIds));
        }
        foreach ($existingById as $existingProduct) {
            if (!is_array($existingProduct) || !supplier_products_row_is_bundle($existingProduct)) {
                continue;
            }
            $offerKey = trim((string)($existingProduct['offer_key'] ?? ''));
            if ($offerKey !== '') {
                $usedOfferKeys[$offerKey] = true;
            }
        }
        $sortOrder = 0;
        foreach ($records as $record) {
            $done++;
            $record['offer_key'] = supplier_products_unique_offer_key(
                (string)($record['offer_key'] ?? ($record['offer_id'] ?? '')),
                $usedOfferKeys
            );
            $productId = supplier_products_insert_source_record($pdo, $supplierId, $record, ++$sortOrder, $cfg);
            if ($productId > 0) {
                $insertedIds[] = $productId;
            }
            if ($progress && ($done % 100) === 0) {
                $progress($done, max(1, $total), 'replace', 'Загружаю новый каталог');
            }
        }
        if ($progress) {
            $progress($done, max(1, $total), 'replace', 'Новый каталог загружен');
        }

        supplier_products_record_source_meta($pdo, $supplierId, $source, $cfg, $sourceUrl);

        if ($progress) {
            $progress($done, max(1, $total), 'summary', 'Обновляю сводные данные товаров');
        }
        foreach ($insertedIds as $productId) {
            supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    if ($deletedPictures) {
        supplier_products_delete_local_images_if_unreferenced($deletedPictures, $cfg);
    }
    return [
        'source_offers' => count($records),
        'added' => count($insertedIds),
        'updated' => 0,
        'deleted' => $deletedBefore,
        'skipped_existing' => 0,
        'skipped_new' => 0,
        'skipped_scope' => 0,
        'changed_products' => count($insertedIds),
        'dataset' => $datasetInfo,
    ];
}

function supplier_products_delete_stale_from_source(int $supplierId, array $source, array $cfg = [], string $sourceUrl = ''): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $records = (array)($source['records'] ?? []);
    if (!$records) {
        throw new RuntimeException('В источнике не найдено товаров.');
    }
    $progress = is_callable($source['progress'] ?? null) ? $source['progress'] : null;

    $pdo = db();
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $maps = supplier_products_existing_maps($pdo, $supplierId, $supplierCode);
    $existingByOfferId = (array)($maps['by_offer_id'] ?? []);
    $existingByOfferKey = (array)($maps['by_offer_key'] ?? []);
    $existingById = (array)($maps['by_id'] ?? []);
    $seenProductIds = [];
    $done = 0;
    $total = count($records) + count($existingById);
    if ($progress) {
        $progress(0, max(1, $total), 'compare', 'Сравниваю каталог с источником');
    }
    foreach ($records as $record) {
        $done++;
        $existing = supplier_products_match_existing_product($record, $existingByOfferId, $existingByOfferKey);
        if (is_array($existing)) {
            $productId = (int)($existing['id'] ?? 0);
            if ($productId > 0) {
                $seenProductIds[$productId] = true;
            }
        }
        if ($progress && ($done % 100) === 0) {
            $progress($done, max(1, $total), 'compare', 'Сравниваю каталог с источником');
        }
    }

    $deleteIds = [];
    foreach ($existingById as $productId => $product) {
        $done++;
        $productId = (int)$productId;
        if ($productId > 0 && !isset($seenProductIds[$productId]) && is_array($product) && !supplier_products_row_is_bundle($product)) {
            $deleteIds[] = $productId;
        }
        if ($progress && ($done % 100) === 0) {
            $progress($done, max(1, $total), 'compare', 'Ищу неактуальные товары');
        }
    }

    $deletedPictures = $deleteIds ? supplier_products_picture_urls_for_supplier($pdo, $supplierId, $deleteIds) : [];

    $pdo->beginTransaction();
    try {
        if ($deleteIds) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $delFields = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE supplier_id = ? AND product_id IN ({$placeholders})");
            $delFields->execute(array_merge([$supplierId], $deleteIds));

            $delProducts = $pdo->prepare("DELETE FROM feedtools_supplier_products WHERE supplier_id = ? AND id IN ({$placeholders})");
            $delProducts->execute(array_merge([$supplierId], $deleteIds));
        }

        supplier_products_record_source_meta($pdo, $supplierId, $source, $cfg, $sourceUrl);
        if ($progress) {
            $progress($done, max(1, $total), 'delete', 'Неактуальные товары удалены');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    if ($deletedPictures) {
        supplier_products_delete_local_images_if_unreferenced($deletedPictures, $cfg);
    }
    return [
        'source_offers' => count($records),
        'matched' => count($seenProductIds),
        'added' => 0,
        'updated' => 0,
        'deleted' => count($deleteIds),
        'skipped_existing' => 0,
        'skipped_new' => 0,
        'skipped_scope' => 0,
        'changed_products' => count($deleteIds),
        'dataset' => $datasetInfo,
    ];
}

function supplier_products_import_preview(int $supplierId, array $source, array $options = [], array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $records = (array)($source['records'] ?? []);
    $pdo = db();
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $maps = supplier_products_existing_maps($pdo, $supplierId, $supplierCode);
    $existingByOfferId = (array)($maps['by_offer_id'] ?? []);
    $existingByOfferKey = (array)($maps['by_offer_key'] ?? []);
    $existingById = (array)($maps['by_id'] ?? []);

    $mode = (string)($options['mode'] ?? 'add_update');
    if (!in_array($mode, ['add_only', 'add_update', 'update_only'], true)) {
        $mode = 'add_update';
    }
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }
    if ($mode === 'add_only') {
        $scope = 'all';
    }

    $selectedIds = supplier_products_selected_offer_id_set((array)($options['selected_offer_ids'] ?? []), $supplierCode);
    $seenProductIds = [];
    $matchedExisting = 0;
    $newRecords = 0;
    $addCandidates = 0;
    $updateCandidates = 0;
    $skippedExisting = 0;
    $skippedNew = 0;
    $skippedScope = 0;
    $selectedMatched = 0;

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $existing = supplier_products_match_existing_product($record, $existingByOfferId, $existingByOfferKey);
        if (!is_array($existing)) {
            $newRecords++;
            if ($mode === 'update_only' || $scope === 'selected') {
                $skippedNew++;
            } else {
                $addCandidates++;
            }
            continue;
        }

        $matchedExisting++;
        $productId = (int)($existing['id'] ?? 0);
        if ($productId > 0) {
            $seenProductIds[$productId] = true;
        }

        if ($mode === 'add_only') {
            $skippedExisting++;
            continue;
        }

        if ($scope === 'selected') {
            if (!$selectedIds || !supplier_products_record_is_selected($record, $existing, $selectedIds, $supplierCode)) {
                $skippedScope++;
                continue;
            }
            $selectedMatched++;
        }

        $updateCandidates++;
    }

    $staleProducts = 0;
    foreach ($existingById as $productId => $_product) {
        $productId = (int)$productId;
        if ($productId > 0 && !isset($seenProductIds[$productId])) {
            $staleProducts++;
        }
    }

    return [
        'source_offers' => count($records),
        'current_products' => count($existingById),
        'matched_existing' => $matchedExisting,
        'new_records' => $newRecords,
        'add_candidates' => $addCandidates,
        'update_candidates' => $updateCandidates,
        'stale_products' => $staleProducts,
        'skipped_existing' => $skippedExisting,
        'skipped_new' => $skippedNew,
        'skipped_scope' => $skippedScope,
        'selected_requested' => count($selectedIds),
        'selected_matched' => $selectedMatched,
        'mode' => $mode,
        'scope' => $scope,
    ];
}

function supplier_products_set_import_tag_field(PDO $pdo, int $supplierId, int $productId, array $names, string $canonicalName, string $value): bool
{
    $names = array_values(array_filter(array_map('strval', $names), static fn($v) => trim($v) !== ''));
    if (!$names) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $st = $pdo->prepare("
        SELECT id
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'tag'
          AND field_name IN ({$placeholders})
    ");
    $st->execute(array_merge([$productId], $names));
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $changed = false;
    if ($ids) {
        $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $del->execute($ids);
        $changed = true;
    }
    $value = trim($value);
    if ($value !== '') {
        $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
        $sort->execute([$productId]);
        $ins = $pdo->prepare("
            INSERT INTO feedtools_supplier_product_fields (
                supplier_id, product_id, field_kind, field_name, field_value, sort_order
            ) VALUES (?, ?, 'tag', ?, ?, ?)
        ");
        $ins->execute([$supplierId, $productId, $canonicalName, $value, (int)$sort->fetchColumn()]);
        $changed = true;
    }
    return $changed;
}

function supplier_products_import_source_with_options(int $supplierId, array $source, array $options, array $cfg = [], string $sourceUrl = ''): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $mode = (string)($options['mode'] ?? 'add_update');
    if (!in_array($mode, ['add_only', 'add_update', 'update_only'], true)) {
        $mode = 'add_update';
    }
    $scope = (string)($options['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'selected'], true)) {
        $scope = 'all';
    }
    if ($mode === 'add_only') {
        $scope = 'all';
    }

    $selectedIds = [];
    foreach ((array)($options['selected_offer_ids'] ?? []) as $selectedId) {
        $selectedId = trim((string)$selectedId);
        if ($selectedId === '') {
            continue;
        }
        $selectedIds[$selectedId] = true;
        $selectedIds[suppliers_apply_supplier_code($selectedId, (string)($supplier['supplier_code'] ?? ''))] = true;
    }
    if ($scope === 'selected' && !$selectedIds) {
        throw new RuntimeException('Для импорта только выбранных товаров сначала выбери товары в таблице.');
    }

    $records = (array)($source['records'] ?? []);
    if (!$records) {
        throw new RuntimeException('В источнике не найдено товаров.');
    }
    $progress = is_callable($options['progress'] ?? null) ? $options['progress'] : null;
    $done = 0;
    $total = count($records);
    $progressTick = static function (string $message = 'Импортирую товары') use (&$done, $total, $progress): void {
        if ($progress && (($done % 100) === 0 || $done === $total)) {
            $progress($done, max(1, $total), 'import', $message);
        }
    };
    if ($progress) {
        $progress(0, max(1, $total), 'import', 'Импортирую товары');
    }

    $pdo = db();
    $existingStmt = $pdo->prepare("SELECT * FROM feedtools_supplier_products WHERE supplier_id = ? ORDER BY sort_order ASC, id ASC");
    $existingStmt->execute([$supplierId]);
    $existingByOfferId = [];
    $existingByOfferKey = [];
    $usedOfferKeys = [];
    $maxSortOrder = 0;
    while ($product = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $offerId = trim((string)($product['offer_id'] ?? ''));
        if ($offerId !== '' && !isset($existingByOfferId[$offerId])) {
            $existingByOfferId[$offerId] = $product;
        }
        $coded = suppliers_apply_supplier_code($offerId, (string)($supplier['supplier_code'] ?? ''));
        if ($coded !== '' && !isset($existingByOfferId[$coded])) {
            $existingByOfferId[$coded] = $product;
        }
        $rawKey = trim((string)($product['offer_key'] ?? ''));
        if ($rawKey !== '') {
            $usedOfferKeys[$rawKey] = true;
            if (!isset($existingByOfferKey[$rawKey])) {
                $existingByOfferKey[$rawKey] = $product;
            }
        }
        $maxSortOrder = max($maxSortOrder, (int)($product['sort_order'] ?? 0));
    }

    $replaceNames = !empty($options['replace_names']);
    $descriptionMode = (string)($options['description_mode'] ?? 'no_update');
    if (!in_array($descriptionMode, ['no_update', 'fill_empty', 'replace'], true)) {
        $descriptionMode = 'no_update';
    }
    $brandMode = (string)($options['brand_mode'] ?? 'no_update');
    if (!in_array($brandMode, ['no_update', 'add_only', 'replace'], true)) {
        $brandMode = 'no_update';
    }
    $modelMode = (string)($options['model_mode'] ?? 'no_update');
    if (!in_array($modelMode, ['no_update', 'update'], true)) {
        $modelMode = 'no_update';
    }
    $updateSupplierCategory = !empty($options['update_supplier_category']);
    $characteristicsMode = (string)($options['characteristics_mode'] ?? 'no_update');
    if (!in_array($characteristicsMode, ['no_update', 'add_only', 'add_replace', 'replace_all'], true)) {
        $characteristicsMode = 'no_update';
    }
    $photosMode = (string)($options['photos_mode'] ?? 'no_replace');
    if (!in_array($photosMode, ['no_replace', 'add_only', 'replace_keep_generated', 'replace_all'], true)) {
        $photosMode = 'no_replace';
    }

    $stats = [
        'source_offers' => count($records),
        'added' => 0,
        'matched' => 0,
        'updated' => 0,
        'skipped_existing' => 0,
        'skipped_new' => 0,
        'skipped_scope' => 0,
        'names_updated' => 0,
        'brands_updated' => 0,
        'models_updated' => 0,
        'descriptions_updated' => 0,
        'categories_updated' => 0,
        'characteristic_fields_added' => 0,
        'characteristic_groups_replaced' => 0,
        'photos_updated' => 0,
    ];

    $changedProducts = [];
    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
            $done++;
            $offerId = trim((string)($record['offer_id'] ?? ''));
            $rawOfferId = trim((string)($record['raw_offer_id'] ?? ''));
            $existing = supplier_products_match_existing_product($record, $existingByOfferId, $existingByOfferKey);

            if (!is_array($existing)) {
                if ($mode === 'update_only' || $scope === 'selected') {
                    $stats['skipped_new']++;
                    $progressTick();
                    continue;
                }
                $record['offer_key'] = supplier_products_unique_offer_key((string)($record['offer_key'] ?? $offerId), $usedOfferKeys);
                $productId = supplier_products_insert_source_record($pdo, $supplierId, $record, ++$maxSortOrder, $cfg);
                $stats['added']++;
                $changedProducts[$productId] = true;
                if ($offerId !== '') {
                    $fresh = $pdo->prepare("SELECT * FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
                    $fresh->execute([$productId]);
                    $freshRow = $fresh->fetch(PDO::FETCH_ASSOC);
                    if (is_array($freshRow)) {
                        $existingByOfferId[$offerId] = $freshRow;
                        $freshOfferKey = trim((string)($freshRow['offer_key'] ?? ''));
                        if ($freshOfferKey !== '') {
                            $existingByOfferKey[$freshOfferKey] = $freshRow;
                        }
                    }
                }
                $progressTick();
                continue;
            }

            $stats['matched']++;
            $productId = (int)($existing['id'] ?? 0);
            if ($productId <= 0) {
                $progressTick();
                continue;
            }
            if ($mode === 'add_only') {
                $stats['skipped_existing']++;
                $progressTick();
                continue;
            }
            if ($scope === 'selected') {
                $existingOfferId = trim((string)($existing['offer_id'] ?? ''));
                if (!isset($selectedIds[$offerId]) && !isset($selectedIds[$rawOfferId]) && !isset($selectedIds[$existingOfferId])) {
                    $stats['skipped_scope']++;
                    $progressTick();
                    continue;
                }
            }

            $parsed = (array)($record['parsed'] ?? []);
            $standardValues = supplier_products_source_standard_values($parsed);
            $changed = false;
            $updates = [];
            $args = [];

            if ($offerId !== '' && $offerId !== (string)($existing['offer_id'] ?? '')) {
                $updates[] = 'offer_id = ?';
                $args[] = mb_substr($offerId, 0, 191, 'UTF-8');
                $changed = true;
            }
            if ($replaceNames) {
                $updates[] = 'name = ?';
                $args[] = (string)($parsed['name'] ?? '');
                $stats['names_updated']++;
                $changed = true;
            }
            $sourceDescription = (string)($parsed['description_html'] ?? '');
            if ($descriptionMode === 'replace' && trim($sourceDescription) !== '') {
                $updates[] = 'description_html = ?';
                $args[] = $sourceDescription;
                $stats['descriptions_updated']++;
                $changed = true;
            } elseif ($descriptionMode === 'fill_empty' && trim((string)($existing['description_html'] ?? '')) === '' && trim($sourceDescription) !== '') {
                $updates[] = 'description_html = ?';
                $args[] = $sourceDescription;
                $stats['descriptions_updated']++;
                $changed = true;
            }
            if ($updateSupplierCategory) {
                $sourceSupplierCategory = trim((string)($parsed['category_id'] ?? ''));
                $sourceOzonCategory = trim((string)($parsed['ozon_category'] ?? ''));
                $sourceWbCategory = trim((string)($parsed['wb_category'] ?? ''));
                if ($sourceSupplierCategory !== '') {
                    $updates[] = 'category_id = ?';
                    $args[] = mb_substr($sourceSupplierCategory, 0, 191, 'UTF-8');
                    $updates[] = 'category_path = ?';
                    $args[] = (string)($record['category_path'] ?? '');
                }
                if ($sourceSupplierCategory !== '' && supplier_products_set_import_tag_field($pdo, $supplierId, $productId, ['categoryId', 'category'], 'categoryId', $sourceSupplierCategory)) {
                    $changed = true;
                }
                if ($sourceOzonCategory !== '' && supplier_products_set_import_tag_field($pdo, $supplierId, $productId, ['ozon_category'], 'ozon_category', $sourceOzonCategory)) {
                    $changed = true;
                }
                if ($sourceWbCategory !== '' && supplier_products_set_import_tag_field($pdo, $supplierId, $productId, ['wb_category', 'wb_subject_id'], 'wb_category', $sourceWbCategory)) {
                    $changed = true;
                }
                if ($sourceSupplierCategory !== '' || $sourceOzonCategory !== '' || $sourceWbCategory !== '') {
                    $stats['categories_updated']++;
                    $changed = true;
                }
            }
            if ($brandMode !== 'no_update') {
                $brandChanged = false;
                foreach (['brand', 'brand_ozon', 'brand_wb'] as $brandField) {
                    $sourceBrand = trim((string)($standardValues[$brandField] ?? ''));
                    if ($sourceBrand === '') {
                        continue;
                    }
                    $currentBrand = trim(supplier_products_standard_field_value($pdo, $productId, $brandField));
                    if ($brandField === 'brand' && $currentBrand === '') {
                        $currentBrand = trim((string)($existing['brand'] ?? ''));
                    }
                    $shouldSetBrand = $brandMode === 'replace' || ($brandMode === 'add_only' && $currentBrand === '');
                    if ($shouldSetBrand && supplier_products_set_standard_field_value($pdo, $supplierId, $productId, $brandField, $sourceBrand)) {
                        $brandChanged = true;
                        $changed = true;
                    }
                }
                if ($brandChanged) {
                    $stats['brands_updated']++;
                }
            }
            if ($modelMode === 'update') {
                $sourceSameModel = supplier_products_source_tag_value($parsed, ['same_model']);
                $currentSameModel = trim(supplier_products_tag_field_value($pdo, $productId, 'same_model'));
                if ($sourceSameModel !== '' && $sourceSameModel !== $currentSameModel && supplier_products_set_import_tag_field($pdo, $supplierId, $productId, ['same_model'], 'same_model', $sourceSameModel)) {
                    $stats['models_updated']++;
                    $changed = true;
                }
            }

            $sourcePictures = supplier_products_normalize_picture_urls((array)($parsed['pictures'] ?? []));
            if ($photosMode !== 'no_replace') {
                $currentPictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($existing['pictures_json'] ?? null));
                $newPictures = supplier_products_import_merge_pictures($currentPictures, $sourcePictures, $photosMode, $cfg);
                if ($newPictures !== $currentPictures) {
                    $updates[] = 'pictures_json = ?';
                    $args[] = json_encode($newPictures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stats['photos_updated']++;
                    $changed = true;
                }
            }

            if ($updates) {
                $args[] = $productId;
                $upd = $pdo->prepare('UPDATE feedtools_supplier_products SET ' . implode(', ', $updates) . ' WHERE id = ?');
                $upd->execute($args);
            }

            $charStats = supplier_products_apply_characteristic_import(
                $pdo,
                $supplierId,
                $productId,
                supplier_products_source_characteristic_fields($parsed),
                $characteristicsMode
            );
            if (!empty($charStats['changed'])) {
                $changed = true;
                $stats['characteristic_fields_added'] += (int)($charStats['added'] ?? 0);
                $stats['characteristic_groups_replaced'] += (int)($charStats['replaced'] ?? 0);
            }

            if ($changed) {
                $changedProducts[$productId] = true;
                $stats['updated']++;
            }
            $progressTick();
        }

        supplier_products_record_source_meta($pdo, $supplierId, $source, $cfg, $sourceUrl);

        if ($progress) {
            $progress($done, max(1, $total), 'summary', 'Обновляю сводные данные товаров');
        }
        foreach (array_keys($changedProducts) as $changedProductId) {
            supplier_products_sync_product_summary_from_db((int)$changedProductId, $cfg);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    $stats['changed_products'] = count($changedProducts);
    $stats['dataset'] = $datasetInfo;
    return $stats;
}

function supplier_products_decode_json_array($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function supplier_products_picture_urls_for_supplier(PDO $pdo, int $supplierId, array $productIds = []): array
{
    if ($supplierId <= 0) {
        return [];
    }
    $args = [$supplierId];
    $sql = "
        SELECT pictures_json
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
    ";
    $productIds = array_values(array_filter(array_unique(array_map('intval', $productIds))));
    if ($productIds) {
        $sql .= " AND id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")";
        $args = array_merge($args, $productIds);
    }

    $st = $pdo->prepare($sql);
    $st->execute($args);
    $urls = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        foreach (supplier_products_decode_json_array($row['pictures_json'] ?? null) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
    }
    return $urls;
}

function supplier_products_fields_for_product(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId]);
    $rows = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function supplier_products_group_fields(array $fields): array
{
    $grouped = [
        'standard' => [],
        'attr' => [],
        'tag' => [],
        'param' => [],
        'wb_param' => [],
        'feature' => [],
    ];
    foreach ($fields as $field) {
        $kind = (string)($field['field_kind'] ?? 'tag');
        if (!isset($grouped[$kind])) {
            $kind = 'tag';
        }
        $grouped[$kind][] = $field;
    }
    return $grouped;
}

function supplier_products_sync_product_summary_from_db(int $productId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $supplierId = (int)($product['supplier_id'] ?? 0);
    $parseOptions = supplier_products_parse_options_for_supplier_id($supplierId, $cfg);
    $fields = supplier_products_fields_for_product($productId);
    $grouped = supplier_products_group_fields($fields);

    $standardValues = supplier_products_standard_empty_values();
    foreach ($grouped['standard'] as $field) {
        $key = trim((string)($field['field_name'] ?? ''));
        if (supplier_products_is_standard_field_name($key)) {
            $standardValues[$key] = trim((string)($field['field_value'] ?? ''));
        }
    }

    $tagValues = [];
    foreach ($grouped['tag'] as $field) {
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $tagValues[$name][] = (string)($field['field_value'] ?? '');
    }

    $firstTag = static function (array $names) use ($tagValues): string {
        foreach ($names as $name) {
            foreach ((array)($tagValues[$name] ?? []) as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    };

    $offerId = trim((string)($product['offer_id'] ?? ''));
    $vendorCode = $firstTag(['vendorCode', 'article']);
    if ($vendorCode === '') {
        $vendorCode = trim((string)($product['vendor_code'] ?? ''));
    }
    $categoryId = $firstTag(['categoryId', 'category']);
    if ($categoryId === '') {
        $categoryId = trim((string)($product['category_id'] ?? ''));
    }
    $ozonCategory = $firstTag(['ozon_category']);
    if ($ozonCategory === '') {
        $ozonCategory = trim((string)($product['ozon_category'] ?? ''));
    }
    $wbCategory = $firstTag(['wb_category', 'wb_subject_id']);
    if ($wbCategory === '') {
        $wbCategory = trim((string)($product['wb_category'] ?? ''));
    }

    $brand = trim((string)($standardValues['brand'] ?? ''));
    if ($brand === '') {
        $brand = trim((string)($product['brand'] ?? ''));
    }

    $priceOriginal = null;
    $priceRaw = trim((string)($standardValues['purchase_price'] ?? ''));
    if ($priceRaw !== '') {
        $priceOriginal = supplier_products_parse_float($priceRaw);
    } elseif ($product['price_original'] !== null && (string)$product['price_original'] !== '') {
        $priceOriginal = (float)$product['price_original'];
    }

    $stockRaw = trim((string)($standardValues['stock'] ?? ''));
    $stockQty = $stockRaw !== '' ? (int)$stockRaw : (int)($product['stock_qty'] ?? 0);
    $countQty = (int)($product['count_qty'] ?? 0);

    $params = [];
    foreach ($grouped['param'] as $field) {
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (supplier_products_should_skip_source_param('param', $name, $parseOptions)) {
            continue;
        }
        $params[$name][] = (string)($field['field_value'] ?? '');
    }
    foreach ($grouped['wb_param'] as $field) {
        $name = trim((string)($field['field_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (supplier_products_should_skip_source_param('wb_param', $name, $parseOptions)) {
            continue;
        }
        $params['[WB] ' . $name][] = (string)($field['field_value'] ?? '');
    }

    $meta = supplier_products_meta_get($supplierId, $cfg);
    $categoryPath = supplier_products_build_category_path(
        $categoryId,
        supplier_products_category_map_from_json((string)($meta['categories_json'] ?? ''))
    );
    if ($categoryPath === '') {
        $categoryPath = (string)($product['category_path'] ?? '');
    }

    $structuredHash = hash('sha256', json_encode([
        'offer_id' => $offerId,
        'name' => (string)($product['name'] ?? ''),
        'vendor_code' => $vendorCode,
        'category_id' => $categoryId,
        'category_path' => $categoryPath,
        'ozon_category' => $ozonCategory,
        'wb_category' => $wbCategory,
        'brand' => $brand,
        'description_html' => (string)($product['description_html'] ?? ''),
        'count_qty' => $countQty,
        'stock_qty' => $stockQty,
        'price_original' => $priceOriginal,
        'marketplace_enabled' => (int)($product['marketplace_enabled'] ?? 1),
        'stock_modifier' => (int)($product['stock_modifier'] ?? 0),
        'price_modifier' => (string)($product['price_modifier'] ?? ''),
        'pictures_json' => (string)($product['pictures_json'] ?? ''),
        'fields' => array_map(static function (array $field): array {
            return [
                'kind' => (string)($field['field_kind'] ?? ''),
                'name' => (string)($field['field_name'] ?? ''),
                'value' => (string)($field['field_value'] ?? ''),
                'sort' => (int)($field['sort_order'] ?? 0),
            ];
        }, $fields),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $upd = $pdo->prepare("
        UPDATE feedtools_supplier_products
        SET raw_hash = ?,
            vendor_code = ?,
            category_id = ?,
            category_path = ?,
            ozon_category = ?,
            wb_category = ?,
            brand = ?,
            count_qty = ?,
            stock_qty = ?,
            price_original = ?,
            params_json = ?
        WHERE id = ?
    ");
    $upd->execute([
        $structuredHash,
        mb_substr($vendorCode, 0, 191, 'UTF-8'),
        mb_substr($categoryId, 0, 191, 'UTF-8'),
        $categoryPath,
        mb_substr($ozonCategory, 0, 191, 'UTF-8'),
        mb_substr($wbCategory, 0, 191, 'UTF-8'),
        mb_substr($brand, 0, 191, 'UTF-8'),
        $countQty,
        $stockQty,
        $priceOriginal,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $productId,
    ]);

    $st->execute([$productId]);
    $fresh = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($fresh) ? $fresh : $product;
}

function supplier_products_rows_for_view_order_sql(string $sort = '', string $dir = 'asc'): string
{
    $dir = strtolower(trim($dir)) === 'desc' ? 'DESC' : 'ASC';
    $emptyFirst = $dir === 'ASC' ? '0' : '1';
    $emptyLast = $dir === 'ASC' ? '1' : '0';
    $textOrder = static function (string $expr) use ($dir, $emptyFirst, $emptyLast): string {
        return "CASE WHEN {$expr} IS NULL OR TRIM({$expr}) = '' THEN {$emptyFirst} ELSE {$emptyLast} END ASC, {$expr} {$dir}";
    };
    $numberOrder = static function (string $expr) use ($dir, $emptyFirst, $emptyLast): string {
        return "CASE WHEN {$expr} IS NULL THEN {$emptyFirst} ELSE {$emptyLast} END ASC, {$expr} {$dir}";
    };

    switch (trim($sort)) {
        case 'id':
            return 'ORDER BY ' . $textOrder('offer_id') . ', id ASC';
        case 'name':
            return 'ORDER BY ' . $textOrder('name') . ', id ASC';
        case 'description':
            return 'ORDER BY ' . $textOrder('description_html') . ', id ASC';
        case 'brand':
            return 'ORDER BY ' . $textOrder('brand') . ', id ASC';
        case 'price':
            return 'ORDER BY ' . $numberOrder('price_original') . ', id ASC';
        case 'stock':
            return "ORDER BY CASE
                WHEN COALESCE(marketplace_enabled, 1) <= 0 THEN 0
                ELSE GREATEST(0, GREATEST(COALESCE(stock_qty, 0), COALESCE(count_qty, 0)) + COALESCE(stock_modifier, 0))
            END {$dir}, id ASC";
        case 'category_supplier':
            return 'ORDER BY ' . $textOrder('category_path') . ', id ASC';
        case 'category_ozon':
            return 'ORDER BY ' . $textOrder('ozon_category') . ', id ASC';
        case 'category_wb':
            return 'ORDER BY ' . $textOrder('wb_category') . ', id ASC';
    }

    return 'ORDER BY sort_order ASC, id ASC';
}

function supplier_products_rows_for_view(int $supplierId, array $cfg = [], ?int $limit = null, int $offset = 0, string $sort = '', string $dir = 'asc'): array
{
    $pdo = db();
    $orderSql = supplier_products_rows_for_view_order_sql($sort, $dir);
    $limitSql = '';
    if ($limit !== null && $limit > 0) {
        $offset = max(0, $offset);
        $limitSql = ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    $st = $pdo->prepare("
        SELECT *
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
        {$orderSql}
        {$limitSql}
    ");
    $st->execute([$supplierId]);
    $rows = [];
    $productIds = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $row['fields'] = [
            'standard' => [],
            'attr' => [],
            'tag' => [],
            'param' => [],
            'wb_param' => [],
            'feature' => [],
        ];
        $rows[] = $row;
        $productIds[] = (int)($row['id'] ?? 0);
    }
    $productIds = array_values(array_filter(array_unique($productIds)));
    if (!$productIds) {
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $fields = $pdo->prepare("
        SELECT *
        FROM feedtools_supplier_product_fields
        WHERE supplier_id = ? AND product_id IN ({$placeholders})
        ORDER BY product_id ASC, sort_order ASC, id ASC
    ");
    $fields->execute(array_merge([$supplierId], $productIds));
    $byProductId = [];
    foreach ($rows as $rowIndex => $row) {
        $byProductId[(int)$row['id']] = $rowIndex;
    }
    while ($field = $fields->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int)($field['product_id'] ?? 0);
        if (!isset($byProductId[$productId])) {
            continue;
        }
        $sortOrder = $byProductId[$productId];
        $kind = (string)($field['field_kind'] ?? 'tag');
        if (!isset($rows[$sortOrder]['fields'][$kind])) {
            $kind = 'tag';
        }
        $rows[$sortOrder]['fields'][$kind][] = $field;
    }

    return $rows;
}

function supplier_products_offer_ids_for_view(int $supplierId, int $max = 50000, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($supplierId <= 0 || $max <= 0) {
        return ['ids' => [], 'truncated' => false, 'count' => 0];
    }

    $st = db()->prepare("
        SELECT offer_id
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND offer_id <> ''
        ORDER BY sort_order ASC, id ASC
        LIMIT " . (int)($max + 1) . "
    ");
    $st->execute([$supplierId]);

    $ids = [];
    while ($offerId = $st->fetchColumn()) {
        $offerId = trim((string)$offerId);
        if ($offerId !== '') {
            $ids[] = $offerId;
        }
    }

    $truncated = count($ids) > $max;
    if ($truncated) {
        $ids = array_slice($ids, 0, $max);
    }

    return [
        'ids' => $ids,
        'truncated' => $truncated,
        'count' => count($ids),
    ];
}

function supplier_products_update_basic_field(int $productId, string $field, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $allowed = [
        'name' => 'name',
        'description_html' => 'description_html',
    ];
    if (!isset($allowed[$field])) {
        throw new RuntimeException('Это поле товара нельзя редактировать здесь.');
    }
    $column = $allowed[$field];
    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id, pictures_json FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }
    $upd = $pdo->prepare("UPDATE feedtools_supplier_products SET {$column} = ? WHERE id = ?");
    $upd->execute([$value, $productId]);
    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db((int)($product['supplier_id'] ?? 0), $cfg);
    return $fresh;
}

function supplier_products_update_offer_controls(int $productId, $marketplaceEnabled, $stockModifier, $priceModifier, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }

    $enabled = supplier_products_normalize_marketplace_enabled($marketplaceEnabled);
    $stockMod = supplier_products_normalize_stock_modifier($stockModifier);
    $priceMod = supplier_products_normalize_price_modifier($priceModifier);

    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $upd = $pdo->prepare("
        UPDATE feedtools_supplier_products
        SET marketplace_enabled = ?,
            stock_modifier = ?,
            price_modifier = ?
        WHERE id = ?
    ");
    $upd->execute([$enabled, $stockMod, $priceMod, $productId]);

    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db((int)($product['supplier_id'] ?? 0), $cfg);
    return $fresh;
}

function supplier_products_bulk_update_offer_controls(int $datasetId, array $offerIds, array $updates, array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $normalized = [];
    if (array_key_exists('marketplace_enabled', $updates)) {
        $normalized['marketplace_enabled'] = supplier_products_normalize_marketplace_enabled($updates['marketplace_enabled']);
    }
    if (array_key_exists('stock_modifier', $updates)) {
        $normalized['stock_modifier'] = supplier_products_normalize_stock_modifier($updates['stock_modifier']);
    }
    if (array_key_exists('price_modifier', $updates)) {
        $normalized['price_modifier'] = supplier_products_normalize_price_modifier($updates['price_modifier']);
    }
    if (!$normalized) {
        throw new RuntimeException('Не выбрано, что обновлять.');
    }

    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_map('intval', array_keys($products));
    if (!$productIds) {
        throw new RuntimeException('Товары не найдены.');
    }

    $pdo = db();
    $changedProductIds = [];
    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT id, marketplace_enabled, stock_modifier, price_modifier
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND id IN ({$placeholders})
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $changed = false;
            if (array_key_exists('marketplace_enabled', $normalized)) {
                $current = supplier_products_normalize_marketplace_enabled($row['marketplace_enabled'] ?? 1);
                $changed = $changed || $current !== (int)$normalized['marketplace_enabled'];
            }
            if (array_key_exists('stock_modifier', $normalized)) {
                $current = supplier_products_normalize_stock_modifier($row['stock_modifier'] ?? 0);
                $changed = $changed || $current !== (int)$normalized['stock_modifier'];
            }
            if (array_key_exists('price_modifier', $normalized)) {
                $current = supplier_products_normalize_price_modifier($row['price_modifier'] ?? '');
                $changed = $changed || $current !== (string)$normalized['price_modifier'];
            }
            if ($changed) {
                $changedProductIds[] = $productId;
            }
        }
    }

    if ($changedProductIds) {
        $sets = [];
        $args = [];
        foreach ($normalized as $column => $value) {
            $sets[] = $column . ' = ?';
            $args[] = $value;
        }
        $setSql = implode(', ', $sets);
        foreach (array_chunk($changedProductIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $upd = $pdo->prepare("
                UPDATE feedtools_supplier_products
                SET {$setSql}
                WHERE supplier_id = ?
                  AND id IN ({$placeholders})
            ");
            $upd->execute(array_merge($args, [$supplierId], $chunk));
        }
    }

    $datasetInfo = supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    return [
        'requested' => $scope === 'all' ? count($products) : count($offerIds),
        'matched' => count($products),
        'products_changed' => count($changedProductIds),
        'scope' => $scope === 'all' ? 'all' : 'selected',
        'updates' => $normalized,
        'dataset' => $datasetInfo,
    ];
}

function supplier_products_bulk_replace_value(string $current, string $oldText, string $newText): string
{
    if ($oldText === '') {
        return $newText;
    }
    $pattern = '~' . preg_quote($oldText, '~') . '~iu';
    $next = preg_replace_callback($pattern, static fn(): string => $newText, $current);
    return is_string($next) ? $next : $current;
}

function supplier_products_characteristic_norm_name(string $name): string
{
    $name = str_replace('ё', 'е', mb_strtolower(trim($name), 'UTF-8'));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$name);
    $name = preg_replace('/\s+/u', ' ', (string)$name);
    return trim((string)$name);
}

function supplier_products_characteristic_norm_value(string $value): string
{
    return supplier_products_characteristic_norm_name($value);
}

function supplier_products_characteristic_alias_groups(): array
{
    return [
        'color' => ['цвет', 'цвет товара', 'название цвета', 'основной цвет'],
        'material' => ['материал', 'материал изделия', 'основной материал', 'материал корпуса'],
        'country_of_origin' => ['страна производства', 'страна-изготовитель', 'страна изготовитель', 'страна происхождения'],
        'unit_quantity' => ['количество товара в уеи', 'количество_в_единице_товара', 'количество в единице товара', 'количество в упаковке шт', 'количество штук в упаковке', 'количество товаров в упаковке', 'количество в упаковке', 'количество единиц в товаре', 'единиц в одном товаре'],
        'model_name' => ['модель', 'модель товара', 'название модели', 'название модели для объединения в одну карточку'],
        'tnved' => ['тн вэд', 'тн вэд коды еаэс', 'тнвэд', 'код тн вэд', 'код тнвэд', 'tn ved', 'tnved', 'tnved code'],
    ];
}

function supplier_products_characteristic_group_key(string $name): string
{
    $norm = supplier_products_characteristic_norm_name($name);
    if ($norm === '') {
        return '';
    }
    foreach (supplier_products_characteristic_alias_groups() as $groupKey => $aliases) {
        foreach ($aliases as $alias) {
            if ($norm === supplier_products_characteristic_norm_name((string)$alias)) {
                return 'alias:' . $groupKey;
            }
        }
    }
    return 'name:' . $norm;
}

function supplier_products_characteristic_is_color_name(string $name): bool
{
    return supplier_products_characteristic_group_key($name) === 'alias:color';
}

function supplier_products_is_tnved_characteristic_name(string $name): bool
{
    $norm = supplier_products_characteristic_norm_name($name);
    return supplier_products_characteristic_group_key($name) === 'alias:tnved'
        || in_array($norm, ['тн вэд', 'тн вэд коды еаэс', 'тнвэд', 'код тн вэд', 'код тнвэд', 'tn ved', 'tnved', 'tnved code'], true)
        || str_contains($norm, 'тн вэд')
        || str_contains($norm, 'тнвэд')
        || str_contains($norm, 'tnved');
}

function supplier_products_is_ozon_release_type_characteristic_name(string $name): bool
{
    return supplier_products_characteristic_norm_name($name) === 'вид выпуска товара';
}

function supplier_products_ozon_release_type_allowed_values(): array
{
    return [
        'Фабричное производство',
        'Ручная, авторская работа',
        'По индивидуальному дизайну',
    ];
}

function supplier_products_ozon_release_type_value_for_input(string $value, array $allowedValues = []): string
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }

    $allowedByNorm = [];
    foreach ($allowedValues ?: supplier_products_ozon_release_type_allowed_values() as $allowed) {
        if (is_array($allowed)) {
            $allowed = (string)($allowed['value'] ?? ($allowed['name'] ?? ''));
        }
        $allowed = trim((string)$allowed);
        $allowedNorm = supplier_products_characteristic_norm_value($allowed);
        if ($allowed !== '' && $allowedNorm !== '' && !isset($allowedByNorm[$allowedNorm])) {
            $allowedByNorm[$allowedNorm] = $allowed;
        }
    }
    if (!$allowedByNorm) {
        return '';
    }

    $norm = supplier_products_characteristic_norm_value($value);
    if (isset($allowedByNorm[$norm])) {
        return $allowedByNorm[$norm];
    }

    $canonical = static function (string $wanted) use ($allowedByNorm): string {
        $wantedNorm = supplier_products_characteristic_norm_value($wanted);
        return $wantedNorm !== '' ? (string)($allowedByNorm[$wantedNorm] ?? '') : '';
    };

    $factory = $canonical('Фабричное производство');
    if ($factory !== '' && (
        str_contains($norm, 'не ручн')
        || str_contains($norm, 'не авторск')
        || str_contains($norm, 'не является ручн')
        || str_contains($norm, 'обычн')
        || str_contains($norm, 'не требует')
        || str_contains($norm, 'не требуется')
        || in_array($norm, ['нет', 'не указано', 'без особенностей'], true)
    )) {
        return $factory;
    }

    $manual = $canonical('Ручная, авторская работа');
    if ($manual !== '' && (
        str_contains($norm, 'ручн')
        || str_contains($norm, 'авторск')
        || str_contains($norm, 'handmade')
        || str_contains($norm, 'hand made')
    )) {
        return $manual;
    }

    $custom = $canonical('По индивидуальному дизайну');
    if ($custom !== '' && (
        str_contains($norm, 'индивидуальн')
        || str_contains($norm, 'на заказ')
        || str_contains($norm, 'под заказ')
        || str_contains($norm, 'custom')
        || str_contains($norm, 'дизайн')
    )) {
        return $custom;
    }

    if ($factory !== '' && (
        str_contains($norm, 'фабрич')
        || str_contains($norm, 'заводск')
        || str_contains($norm, 'серийн')
        || str_contains($norm, 'массов')
        || str_contains($norm, 'стандартн')
        || str_contains($norm, 'factory')
    )) {
        return $factory;
    }

    return '';
}

function supplier_products_ozon_release_type_sanitized_allowed_values(array $values = []): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = (string)($value['value'] ?? ($value['name'] ?? ''));
        }
        $mapped = supplier_products_ozon_release_type_value_for_input((string)$value);
        $mappedNorm = supplier_products_characteristic_norm_value($mapped);
        if ($mapped !== '' && $mappedNorm !== '' && !isset($seen[$mappedNorm])) {
            $seen[$mappedNorm] = true;
            $out[] = $mapped;
        }
    }
    return $out ?: supplier_products_ozon_release_type_allowed_values();
}

function supplier_products_characteristic_should_restrict_to_allowed(string $source, string $name): bool
{
    $source = $source === 'wb' ? 'wildberries' : trim($source);
    if ($source === 'ozon' && supplier_products_is_ozon_release_type_characteristic_name($name)) {
        return true;
    }
    return in_array($source, ['ozon', 'wildberries'], true)
        && supplier_products_characteristic_is_color_name($name);
}

function supplier_products_limit_wb_characteristic_value(string $value, int $limit = 100): string
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    if ($value === '' || $limit <= 0 || mb_strlen($value, 'UTF-8') <= $limit) {
        return $value;
    }

    $cut = mb_substr($value, 0, $limit, 'UTF-8');
    $wordCut = preg_replace('/\s+\S*$/u', '', $cut);
    if (is_string($wordCut) && mb_strlen($wordCut, 'UTF-8') >= 50) {
        $cut = $wordCut;
    }
    $cut = rtrim($cut, " \t\n\r\0\x0B,;:./\\|-");
    if ($cut === '') {
        $cut = mb_substr($value, 0, $limit, 'UTF-8');
    }
    return $cut;
}

function supplier_products_normalize_characteristic_values(array $values, array $allowedValues = [], bool $restrictToAllowed = false): array
{
    $allowedByNorm = [];
    foreach ($allowedValues as $allowed) {
        if (is_array($allowed)) {
            $allowed = (string)($allowed['value'] ?? ($allowed['name'] ?? ''));
        }
        $allowed = trim((string)$allowed);
        $allowedNorm = supplier_products_characteristic_norm_value($allowed);
        if ($allowed !== '' && $allowedNorm !== '' && !isset($allowedByNorm[$allowedNorm])) {
            $allowedByNorm[$allowedNorm] = $allowed;
        }
    }

    $out = [];
    $seen = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = (string)($value['value'] ?? ($value['name'] ?? ''));
        }
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $parts = preg_split('/\s*[;\|\n\r]+\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [$value];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            $partNorm = supplier_products_characteristic_norm_value($part);
            if ($part === '' || $partNorm === '') {
                continue;
            }
            if ($allowedByNorm) {
                if (isset($allowedByNorm[$partNorm])) {
                    $part = $allowedByNorm[$partNorm];
                    $partNorm = supplier_products_characteristic_norm_value($part);
                } elseif (supplier_products_ozon_release_type_value_for_input($part, array_values($allowedByNorm)) !== '') {
                    $part = supplier_products_ozon_release_type_value_for_input($part, array_values($allowedByNorm));
                    $partNorm = supplier_products_characteristic_norm_value($part);
                } elseif ($restrictToAllowed) {
                    continue;
                }
            }
            if (isset($seen[$partNorm])) {
                continue;
            }
            $seen[$partNorm] = true;
            $out[] = $part;
        }
    }
    return $out;
}

function supplier_products_is_hashtags_field_name(string $name): bool
{
    $norm = supplier_products_characteristic_norm_name(ltrim(trim($name), "# \t\n\r\0\x0B"));
    return in_array($norm, ['hashtags', 'hashtag', 'хештеги', 'хэштеги'], true);
}

function supplier_products_characteristic_exclude_map(string $source, array $cfg = []): array
{
    $out = [];
    $names = [];
    try {
        $names = taxonomy_get_global_exclude_attribute_names($source, $cfg ?: null);
    } catch (Throwable $e) {
        $names = taxonomy_global_exclusions_config_fallback($source, $cfg ?: null);
    }
    foreach ($names as $name) {
        $key = supplier_products_characteristic_norm_name((string)$name);
        if ($key !== '') {
            $out[$key] = true;
        }
    }
    return $out;
}

function supplier_products_is_excluded_characteristic(string $source, string $name, array $cfg = []): bool
{
    $key = supplier_products_characteristic_norm_name($name);
    if ($key === '') {
        return false;
    }
    $map = supplier_products_characteristic_exclude_map($source, $cfg);
    return isset($map[$key]);
}

function supplier_products_characteristic_names_from_taxonomy_meta(array $meta, string $source, array $cfg = []): array
{
    return array_map(
        static fn(array $row): string => (string)($row['name'] ?? ''),
        supplier_products_characteristic_rows_from_taxonomy_meta($meta, $source, $cfg)
    );
}

function supplier_products_characteristic_alias_norms(string $name): array
{
    $names = [trim($name)];
    $norm = supplier_products_characteristic_norm_name($name);
    if ($norm !== '') {
        foreach (supplier_products_characteristic_alias_groups() as $aliases) {
            $groupNorms = [];
            foreach ($aliases as $alias) {
                $aliasNorm = supplier_products_characteristic_norm_name((string)$alias);
                if ($aliasNorm !== '') {
                    $groupNorms[$aliasNorm] = true;
                }
            }
            if (isset($groupNorms[$norm])) {
                foreach ($aliases as $alias) {
                    $names[] = (string)$alias;
                }
            }
        }
    }

    $out = [];
    foreach ($names as $candidate) {
        $candidateNorm = supplier_products_characteristic_norm_name((string)$candidate);
        if ($candidateNorm !== '') {
            $out[$candidateNorm] = true;
        }
    }
    return array_keys($out);
}

function supplier_products_characteristic_allowed_values_from_meta_row(array $row): array
{
    $name = (string)($row['name'] ?? '');
    $isOzonReleaseType = supplier_products_is_ozon_release_type_characteristic_name($name);
    if (!$isOzonReleaseType && !supplier_products_is_tnved_characteristic_name($name) && supplier_products_characteristic_meta_has_free_text_hint($row)) {
        return [];
    }
    $values = [];
    $seen = [];
    $push = static function ($value) use (&$values, &$seen): void {
        if (is_array($value)) {
            $value = (string)($value['value'] ?? ($value['name'] ?? ''));
        }
        $value = trim((string)$value);
        if ($value === '') {
            return;
        }
        $key = supplier_products_characteristic_norm_name($value);
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $values[] = $value;
    };

    if (is_array($row['allowed_values'] ?? null)) {
        foreach ($row['allowed_values'] as $value) {
            $push($value);
        }
    }

    if (!$values) {
        $description = (string)($row['description'] ?? '');
        if ($description !== '' && preg_match_all('~(?:^|\R)\s*[—-]\s*([^\r\n]+)~u', $description, $matches)) {
            foreach ((array)($matches[1] ?? []) as $value) {
                $push($value);
            }
        }
    }

    if ($isOzonReleaseType) {
        return supplier_products_ozon_release_type_sanitized_allowed_values($values);
    }
    return array_slice($values, 0, 500);
}

function supplier_products_characteristic_meta_has_free_text_hint(array $row): bool
{
    $description = trim((string)($row['description'] ?? ''));
    if ($description === '') {
        return false;
    }
    $lower = str_replace('ё', 'е', mb_strtolower($description, 'UTF-8'));
    return str_contains($lower, 'любой удобный формат')
        || str_contains($lower, 'можно указать только целое число')
        || str_contains($lower, 'десятичную дробь');
}

function supplier_products_characteristic_meta_has_dictionary(array $row): bool
{
    $nameNorm = supplier_products_characteristic_norm_name((string)($row['name'] ?? ''));
    if (in_array($nameNorm, ['бренд', 'бренд товара'], true)) {
        return false;
    }
    if (supplier_products_is_ozon_release_type_characteristic_name((string)($row['name'] ?? ''))) {
        return true;
    }
    if (in_array($nameNorm, ['тн вэд', 'тн вэд коды еаэс', 'tn ved', 'tnved'], true)
        && ((int)($row['dictionary_id'] ?? 0) > 0 || !empty($row['allowed_values']))) {
        return true;
    }
    if (supplier_products_characteristic_meta_has_free_text_hint($row)) {
        return false;
    }
    if ((int)($row['dictionary_id'] ?? 0) > 0) {
        return true;
    }
    if (!empty($row['allowed_values'])) {
        return true;
    }
    $selectionMode = trim((string)($row['selection_mode'] ?? ''));
    $valueSource = trim((string)($row['value_source'] ?? ''));
    return $selectionMode !== '' && $selectionMode !== 'free'
        || str_contains($valueSource, 'dictionary')
        || str_contains($valueSource, 'directory');
}

function supplier_products_characteristic_rows_from_taxonomy_meta(array $meta, string $source, array $cfg = []): array
{
    $listKey = $source === 'ozon' ? 'ozon_required_attributes' : 'wb_required_attributes';
    $metaKey = $source === 'ozon' ? 'ozon_required_attributes_meta' : 'wb_characteristics_meta';
    $excludeMap = supplier_products_characteristic_exclude_map($source, $cfg);
    $metaByNorm = [];

    foreach ((array)($meta[$metaKey] ?? []) as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $norms = supplier_products_characteristic_alias_norms($name);
        $keyNorm = is_string($key) ? supplier_products_characteristic_norm_name($key) : '';
        if ($keyNorm !== '') {
            $norms[] = $keyNorm;
        }
        foreach (array_unique($norms) as $norm) {
            if ($norm !== '' && !isset($metaByNorm[$norm])) {
                $metaByNorm[$norm] = $row;
            }
        }
    }

    $rows = [];
    foreach ((array)($meta[$listKey] ?? []) as $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $norm = supplier_products_characteristic_norm_name($name);
        if ($norm === '' || isset($excludeMap[$norm]) || isset($rows[$norm])) {
            continue;
        }
        $metaRow = null;
        foreach (supplier_products_characteristic_alias_norms($name) as $candidateNorm) {
            if (isset($metaByNorm[$candidateNorm])) {
                $metaRow = $metaByNorm[$candidateNorm];
                break;
            }
        }
        $rows[$norm] = [
            'name' => $name,
            'allowed_values' => $metaRow ? supplier_products_characteristic_allowed_values_from_meta_row($metaRow) : [],
            'required' => $metaRow ? (!empty($metaRow['required']) || !empty($metaRow['is_required'])) : false,
            'has_dictionary' => $metaRow ? supplier_products_characteristic_meta_has_dictionary($metaRow) : false,
            'dictionary_id' => $metaRow ? (int)($metaRow['dictionary_id'] ?? 0) : 0,
        ];
    }

    foreach ((array)($meta[$metaKey] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $norm = supplier_products_characteristic_norm_name($name);
        if ($name !== '' && $norm !== '' && !isset($excludeMap[$norm]) && !isset($rows[$norm])) {
            $rows[$norm] = [
                'name' => $name,
                'allowed_values' => supplier_products_characteristic_allowed_values_from_meta_row($row),
                'required' => !empty($row['required']) || !empty($row['is_required']),
                'has_dictionary' => supplier_products_characteristic_meta_has_dictionary($row),
                'dictionary_id' => (int)($row['dictionary_id'] ?? 0),
            ];
        }
    }
    return $rows;
}

function supplier_products_allowed_values_from_taxonomy_meta(array $meta, string $source, string $name, array $cfg = []): array
{
    $wanted = array_fill_keys(supplier_products_characteristic_alias_norms($name), true);
    if (!$wanted) {
        return [];
    }
    foreach (supplier_products_characteristic_rows_from_taxonomy_meta($meta, $source, $cfg) as $row) {
        $rowName = (string)($row['name'] ?? '');
        foreach (supplier_products_characteristic_alias_norms($rowName) as $norm) {
            if (isset($wanted[$norm])) {
                return array_values((array)($row['allowed_values'] ?? []));
            }
        }
    }
    return [];
}

function supplier_products_taxonomy_meta_for_category_value(string $source, string $categoryValue): array
{
    $source = $source === 'wb' ? 'wildberries' : $source;
    $categoryValue = trim($categoryValue);
    if ($categoryValue === '') {
        return [];
    }

    if ($source === 'ozon') {
        if (preg_match('~^leaf:(\d+_\d+)$~', $categoryValue, $m)) {
            $categoryValue = (string)$m[1];
        }
        if (preg_match('~^(\d+)_(\d+)$~', $categoryValue, $m)) {
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND ozon_parent_id = ?
                  AND ozon_leaf_id = ?
                LIMIT 1
            ");
            $st->execute([(int)$m[1], (int)$m[2]]);
        } else {
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'ozon'
                  AND is_leaf = 1
                  AND (name = ? OR full_path = ? OR full_path LIKE ?)
                ORDER BY full_path ASC
                LIMIT 1
            ");
            $st->execute([$categoryValue, $categoryValue, '% > ' . $categoryValue]);
        }
    } elseif ($source === 'wildberries') {
        if (preg_match('~^wb:(?:subject|parent):(\d+)$~', $categoryValue, $m)) {
            $categoryValue = (string)$m[1];
        }
        if (ctype_digit($categoryValue)) {
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'wildberries'
                  AND external_id IN (?, ?)
                ORDER BY is_leaf DESC
                LIMIT 1
            ");
            $st->execute(['wb:subject:' . $categoryValue, 'wb:parent:' . $categoryValue]);
        } else {
            $st = db()->prepare("
                SELECT meta_json
                FROM feedtools_taxonomy_categories
                WHERE source = 'wildberries'
                  AND is_leaf = 1
                  AND (external_id = ? OR name = ? OR full_path = ? OR full_path LIKE ?)
                ORDER BY full_path ASC
                LIMIT 1
            ");
            $st->execute([$categoryValue, $categoryValue, $categoryValue, '% > ' . $categoryValue]);
        }
    } else {
        return [];
    }

    $raw = (string)($st->fetchColumn() ?: '');
    $meta = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($meta) ? $meta : [];
}

function supplier_products_marketplace_characteristic_allowed_values_for_product(int $productId, string $source, string $name, array $cfg = []): array
{
    $source = $source === 'wb' ? 'wildberries' : $source;
    if ($productId <= 0 || !in_array($source, ['ozon', 'wildberries'], true) || trim($name) === '') {
        return [];
    }
    $valuesByProduct = supplier_products_bulk_category_values_for_products([$productId]);
    $categories = (array)($valuesByProduct[$productId] ?? []);
    $categoryValue = $source === 'ozon' ? (string)($categories['ozon'] ?? '') : (string)($categories['wb'] ?? '');
    if ($categoryValue === '') {
        return [];
    }
    $meta = supplier_products_taxonomy_meta_for_category_value($source, $categoryValue);
    return supplier_products_allowed_values_from_taxonomy_meta($meta, $source, $name, $cfg);
}

function supplier_products_normalize_marketplace_field_value_for_product(int $productId, string $kind, string $name, string $value, array $cfg = []): string
{
    $kind = trim($kind);
    if ($productId <= 0 || !in_array($kind, ['param', 'wb_param'], true) || trim($name) === '' || trim($value) === '') {
        return $value;
    }

    $source = $kind === 'wb_param' ? 'wildberries' : 'ozon';
    $allowedValues = supplier_products_marketplace_characteristic_allowed_values_for_product($productId, $source, $name, $cfg);
    $restrict = supplier_products_characteristic_should_restrict_to_allowed($source, $name);
    $normalized = supplier_products_normalize_characteristic_values([$value], $allowedValues, $restrict);
    if ($kind === 'wb_param') {
        $normalized = array_values(array_filter(array_map(
            static fn($part): string => supplier_products_limit_wb_characteristic_value((string)$part),
            $normalized
        ), static fn(string $part): bool => $part !== ''));
    }
    return implode('; ', $normalized);
}

function supplier_products_bulk_add_characteristic_option(array &$items, string $kind, string $name, string $source, int $count = 1, array $cfg = [], array $allowedValues = []): void
{
    $kind = trim($kind);
    $name = trim($name);
    $source = trim($source);
    if (!in_array($kind, ['param', 'wb_param'], true) || $name === '' || supplier_products_is_hashtags_field_name($name)) {
        return;
    }
    if ($kind === 'param' && ($source === 'ozon' || $source === '') && supplier_products_is_excluded_characteristic('ozon', $name, $cfg)) {
        return;
    }
    $groupKey = supplier_products_characteristic_group_key($name);
    if ($groupKey === '') {
        return;
    }
    if (!isset($items[$groupKey])) {
        $items[$groupKey] = [
            'name' => $name,
            'count' => 0,
            'sources' => [],
            'targets' => [],
            'allowed_values' => [],
        ];
    }
    if ($source !== '') {
        $items[$groupKey]['sources'][$source] = true;
    }
    foreach ($allowedValues as $value) {
        $value = trim((string)$value);
        $valueKey = supplier_products_characteristic_norm_name($value);
        if ($value !== '' && $valueKey !== '') {
            $items[$groupKey]['allowed_values'][$valueKey] = $value;
        }
    }
    $targetKey = $kind . "\n" . supplier_products_characteristic_norm_name($name);
    if (!isset($items[$groupKey]['targets'][$targetKey])) {
        $items[$groupKey]['targets'][$targetKey] = [
            'kind' => $kind,
            'field' => $name,
            'sources' => [],
        ];
    }
    if ($source !== '') {
        $items[$groupKey]['targets'][$targetKey]['sources'][$source] = true;
    }
    $items[$groupKey]['count'] += max(0, $count);
}

function supplier_products_bulk_characteristic_label(string $groupKey, array $item, array $targets, array $badges): string
{
    $fallback = trim((string)($item['name'] ?? ''));
    if (!$targets) {
        return ($badges ? '[' . implode('][', $badges) . '] ' : '') . $fallback;
    }

    if ($groupKey === 'alias:unit_quantity') {
        $preferred = [
            'количество в упаковке шт',
            'количество товара в уеи',
            'количество в единице товара',
            'количество_в_единице_товара',
        ];
        $byNorm = [];
        foreach ($targets as $target) {
            $field = trim((string)($target['field'] ?? ''));
            $norm = supplier_products_characteristic_norm_name($field);
            if ($field !== '' && $norm !== '' && !isset($byNorm[$norm])) {
                $byNorm[$norm] = $field;
            }
        }
        $names = [];
        foreach ($preferred as $norm) {
            if (isset($byNorm[$norm])) {
                $names[] = $byNorm[$norm];
                unset($byNorm[$norm]);
            }
        }
        foreach ($byNorm as $field) {
            $names[] = $field;
        }
        if ($names) {
            return ($badges ? '[' . implode('][', $badges) . '] ' : '') . implode(' / ', $names);
        }
    }

    $primary = $targets[0];
    return ($badges ? '[' . implode('][', $badges) . '] ' : '') . ($fallback !== '' ? $fallback : (string)($primary['field'] ?? ''));
}

function supplier_products_bulk_category_values_for_products(array $productIds): array
{
    $out = [];
    foreach ($productIds as $productId) {
        $productId = (int)$productId;
        if ($productId > 0) {
            $out[$productId] = ['ozon' => '', 'wb' => ''];
        }
    }
    if (!$out) {
        return [];
    }

    $pdo = db();
    foreach (array_chunk(array_keys($out), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT id, ozon_category, wb_category
            FROM feedtools_supplier_products
            WHERE id IN ({$placeholders})
        ");
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['id'] ?? 0);
            if (!isset($out[$productId])) {
                continue;
            }
            $ozon = trim((string)($row['ozon_category'] ?? ''));
            if (preg_match('~^leaf:(\d+_\d+)$~', $ozon, $m)) {
                $ozon = (string)$m[1];
            }
            $wb = trim((string)($row['wb_category'] ?? ''));
            if (preg_match('~^wb:(?:subject|parent):(\d+)$~', $wb, $m)) {
                $wb = (string)$m[1];
            }
            $out[$productId]['ozon'] = $ozon;
            $out[$productId]['wb'] = $wb;
        }

        $tags = $pdo->prepare("
            SELECT product_id, field_name, field_value
            FROM feedtools_supplier_product_fields
            WHERE product_id IN ({$placeholders})
              AND field_kind = 'tag'
              AND field_name IN ('ozon_category', 'wb_category', 'wb_subject_id')
            ORDER BY product_id ASC, sort_order ASC, id ASC
        ");
        $tags->execute($chunk);
        while ($row = $tags->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['product_id'] ?? 0);
            if (!isset($out[$productId])) {
                continue;
            }
            $field = (string)($row['field_name'] ?? '');
            $value = trim((string)($row['field_value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($field === 'ozon_category' && $out[$productId]['ozon'] === '') {
                if (preg_match('~^leaf:(\d+_\d+)$~', $value, $m)) {
                    $value = (string)$m[1];
                }
                $out[$productId]['ozon'] = $value;
            } elseif (($field === 'wb_category' || $field === 'wb_subject_id') && $out[$productId]['wb'] === '') {
                if (preg_match('~^wb:(?:subject|parent):(\d+)$~', $value, $m)) {
                    $value = (string)$m[1];
                }
                $out[$productId]['wb'] = $value;
            }
        }
    }
    return $out;
}

function supplier_products_bulk_marketplace_characteristics(array $productIds, array $cfg = []): array
{
    $categoryValues = supplier_products_bulk_category_values_for_products($productIds);
    if (!$categoryValues) {
        return [];
    }

    $ozonProductCounts = [];
    $wbProductCounts = [];
    foreach ($categoryValues as $values) {
        $ozon = trim((string)($values['ozon'] ?? ''));
        if ($ozon !== '' && preg_match('~^(\d+)_(\d+)$~', $ozon)) {
            $ozonProductCounts[$ozon] = (int)($ozonProductCounts[$ozon] ?? 0) + 1;
        }
        $wb = trim((string)($values['wb'] ?? ''));
        if ($wb !== '') {
            if (preg_match('~^wb:(?:subject|parent):(\d+)$~', $wb, $m)) {
                $wb = (string)$m[1];
            }
            if (ctype_digit($wb)) {
                $wbProductCounts[$wb] = (int)($wbProductCounts[$wb] ?? 0) + 1;
            }
        }
    }

    $items = [];
    $pdo = db();
    foreach (array_chunk(array_keys($ozonProductCounts), 100) as $chunk) {
        $clauses = [];
        $args = ['ozon'];
        foreach ($chunk as $pair) {
            if (!preg_match('~^(\d+)_(\d+)$~', (string)$pair, $m)) {
                continue;
            }
            $clauses[] = '(ozon_parent_id = ? AND ozon_leaf_id = ?)';
            $args[] = $m[1];
            $args[] = $m[2];
        }
        if (!$clauses) {
            continue;
        }
        $st = $pdo->prepare("
            SELECT ozon_parent_id, ozon_leaf_id, meta_json
            FROM feedtools_taxonomy_categories
            WHERE source = ? AND is_leaf = 1 AND (" . implode(' OR ', $clauses) . ")
        ");
        $st->execute($args);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pair = (string)($row['ozon_parent_id'] ?? '') . '_' . (string)($row['ozon_leaf_id'] ?? '');
            $count = (int)($ozonProductCounts[$pair] ?? 0);
            $meta = json_decode((string)($row['meta_json'] ?? ''), true);
            foreach (is_array($meta) ? supplier_products_characteristic_rows_from_taxonomy_meta($meta, 'ozon', $cfg) : [] as $attr) {
                supplier_products_bulk_add_characteristic_option(
                    $items,
                    'param',
                    (string)($attr['name'] ?? ''),
                    'ozon',
                    $count,
                    $cfg,
                    (array)($attr['allowed_values'] ?? [])
                );
            }
        }
    }

    foreach (array_chunk(array_keys($wbProductCounts), 200) as $chunk) {
        $externalIds = [];
        foreach ($chunk as $id) {
            $externalIds[] = 'wb:subject:' . $id;
            $externalIds[] = 'wb:parent:' . $id;
        }
        if (!$externalIds) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $st = $pdo->prepare("
            SELECT external_id, is_leaf, meta_json
            FROM feedtools_taxonomy_categories
            WHERE source = 'wildberries' AND external_id IN ({$placeholders})
            ORDER BY is_leaf DESC
        ");
        $st->execute($externalIds);
        $loaded = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $externalId = (string)($row['external_id'] ?? '');
            if (!preg_match('~^wb:(?:subject|parent):(\d+)$~', $externalId, $m)) {
                continue;
            }
            $id = (string)$m[1];
            if (isset($loaded[$id])) {
                continue;
            }
            $loaded[$id] = true;
            $count = (int)($wbProductCounts[$id] ?? 0);
            $meta = json_decode((string)($row['meta_json'] ?? ''), true);
            foreach (is_array($meta) ? supplier_products_characteristic_rows_from_taxonomy_meta($meta, 'wildberries', $cfg) : [] as $attr) {
                supplier_products_bulk_add_characteristic_option(
                    $items,
                    'wb_param',
                    (string)($attr['name'] ?? ''),
                    'wb',
                    $count,
                    $cfg,
                    (array)($attr['allowed_values'] ?? [])
                );
            }
        }
    }

    return $items;
}

function supplier_products_bulk_field_targets(array $target): array
{
    $targets = $target['targets'] ?? [];
    if (!is_array($targets) || !$targets) {
        $kind = (string)($target['kind'] ?? '');
        $field = (string)($target['field'] ?? '');
        if ($kind !== '' || $field !== '') {
            $targets = [['kind' => $kind, 'field' => $field]];
        }
    }

    $out = [];
    $seen = [];
    foreach ($targets as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = supplier_products_validate_field_kind((string)($row['kind'] ?? 'param'));
        if (!in_array($kind, ['param', 'wb_param'], true)) {
            throw new RuntimeException('Для массовой замены доступны только обычные и WB характеристики.');
        }
        $field = supplier_products_validate_field_name($kind, (string)($row['field'] ?? ''));
        $key = $kind . "\n" . supplier_products_characteristic_norm_name($field);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['kind' => $kind, 'field' => $field];
    }
    if (!$out) {
        throw new RuntimeException('Выбери характеристику.');
    }
    return $out;
}

function supplier_products_bulk_replace_target(array $target): array
{
    $type = trim((string)($target['type'] ?? ''));
    if ($type === 'basic') {
        $field = trim((string)($target['field'] ?? ''));
        if ($field === 'same_model') {
            return ['type' => 'tag', 'kind' => 'tag', 'field' => 'same_model', 'basic_alias' => 'same_model'];
        }
        if (!in_array($field, ['name', 'description_html'], true)) {
            throw new RuntimeException('Это поле нельзя массово заменять.');
        }
        return ['type' => 'basic', 'field' => $field];
    }

    if ($type === 'standard') {
        $field = supplier_products_validate_field_name('standard', (string)($target['field'] ?? ''));
        return ['type' => 'standard', 'kind' => 'standard', 'field' => $field];
    }

    if ($type === 'tag') {
        $field = supplier_products_validate_field_name('tag', (string)($target['field'] ?? ''));
        if (!in_array($field, ['same_model'], true)) {
            throw new RuntimeException('Это поле нельзя массово заменять.');
        }
        return ['type' => 'tag', 'kind' => 'tag', 'field' => $field];
    }

    if ($type === 'field') {
        $targets = supplier_products_bulk_field_targets($target);
        return [
            'type' => 'field',
            'kind' => (string)$targets[0]['kind'],
            'field' => (string)$targets[0]['field'],
            'targets' => $targets,
        ];
    }

    throw new RuntimeException('Не выбрано поле для массовой замены.');
}

function supplier_products_ids_for_selected_offers(int $supplierId, array $offerIds, array $cfg = []): array
{
    $supplier = suppliers_get($supplierId, $cfg);
    $supplierCode = is_array($supplier) ? suppliers_normalize_code((string)($supplier['supplier_code'] ?? '')) : '';
    $selectedSet = supplier_products_selected_offer_id_set($offerIds, $supplierCode);
    $selectedOfferIds = array_keys($selectedSet);
    if (!$selectedOfferIds) {
        throw new RuntimeException('Не выбраны товары.');
    }

    $pdo = db();
    $products = [];
    foreach (array_chunk($selectedOfferIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT id, offer_id
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['id'] ?? 0);
            if ($productId > 0) {
                $products[$productId] = (string)($row['offer_id'] ?? '');
            }
        }
    }

    if (!$products) {
        throw new RuntimeException('Выбранные товары не найдены.');
    }
    return $products;
}

function supplier_products_ids_for_bulk_replace_scope(int $supplierId, array $offerIds, string $scope, array $cfg = []): array
{
    if ($scope !== 'all') {
        return supplier_products_ids_for_selected_offers($supplierId, $offerIds, $cfg);
    }

    $pdo = db();
    $st = $pdo->prepare("
        SELECT id, offer_id
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$supplierId]);
    $products = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int)($row['id'] ?? 0);
        if ($productId > 0) {
            $products[$productId] = (string)($row['offer_id'] ?? '');
        }
    }
    if (!$products) {
        throw new RuntimeException('Товары поставщика не найдены.');
    }
    return $products;
}

function supplier_products_bulk_copy_basic_field_labels(): array
{
    return [
        'name' => 'Название',
        'same_model' => 'Модель товара',
        'description_html' => 'Описание',
    ];
}

function supplier_products_bulk_copy_common_tag_labels(): array
{
    return [
        'vendorCode' => 'Артикул продавца / vendorCode',
        'article' => 'Артикул / article',
        'model' => 'Тег model',
        'brand' => 'Бренд / brand',
        'vendor' => 'Производитель / vendor',
        'same_model' => 'Тег same_model',
        'categoryId' => 'Категория поставщика / categoryId',
        'category' => 'Категория / category',
        'ozon_category' => 'Категория Ozon',
        'wb_category' => 'Категория WB',
        'wb_subject_id' => 'WB subject ID',
        'dimensions' => 'Размеры / dimensions',
        'barcode' => 'Баркод / barcode',
        'price_original' => 'Закупочная цена / price_original',
        'price' => 'Цена / price',
        'stock' => 'Остаток / stock',
        'quantity' => 'Количество / quantity',
        'count' => 'Количество / count',
        'weight' => 'Вес / weight',
    ];
}

function supplier_products_bulk_copy_group_label(string $type, string $kind = ''): string
{
    if ($type === 'basic') return 'Основные поля';
    return match ($kind) {
        'standard' => 'Стандартные поля',
        'tag' => 'Теги',
        'param' => 'Характеристики Ozon',
        'wb_param' => 'Характеристики WB',
        'attr' => 'Атрибуты',
        'feature' => 'Фишки',
        default => 'Поля',
    };
}

function supplier_products_bulk_copy_group_rank(string $type, string $kind = ''): int
{
    if ($type === 'basic') return 10;
    return match ($kind) {
        'standard' => 20,
        'tag' => 30,
        'param' => 40,
        'wb_param' => 50,
        'feature' => 60,
        'attr' => 70,
        default => 90,
    };
}

function supplier_products_bulk_copy_field_label(string $type, string $kind, string $field): string
{
    if ($type === 'basic') {
        return (string)(supplier_products_bulk_copy_basic_field_labels()[$field] ?? $field);
    }
    if ($kind === 'standard') {
        return (string)(supplier_products_standard_field_labels()[$field] ?? $field);
    }
    if ($kind === 'tag') {
        return (string)(supplier_products_bulk_copy_common_tag_labels()[$field] ?? $field);
    }
    if ($kind === 'feature') {
        $idx = supplier_products_feature_field_index($field);
        return $idx > 0 ? ('Фишка ' . $idx) : $field;
    }
    return $field;
}

function supplier_products_bulk_copy_field_ref(array $ref): array
{
    $type = trim((string)($ref['type'] ?? ''));
    if ($type === '' && isset($ref['kind'])) {
        $type = 'field';
    }

    if ($type === 'basic') {
        $field = trim((string)($ref['field'] ?? ''));
        if (!array_key_exists($field, supplier_products_bulk_copy_basic_field_labels())) {
            throw new RuntimeException('Неподдерживаемое основное поле.');
        }
        return ['type' => 'basic', 'field' => $field];
    }

    if ($type === 'field') {
        $kind = supplier_products_validate_field_kind((string)($ref['kind'] ?? ''));
        if (!in_array($kind, ['tag', 'param', 'wb_param', 'attr', 'standard', 'feature'], true)) {
            throw new RuntimeException('Неподдерживаемый тип поля.');
        }
        $field = supplier_products_validate_field_name($kind, (string)($ref['field'] ?? ''));
        return ['type' => 'field', 'kind' => $kind, 'field' => $field];
    }

    throw new RuntimeException('Не выбрано поле.');
}

function supplier_products_bulk_copy_ref_key(array $ref): string
{
    if (($ref['type'] ?? '') === 'basic') {
        if ((string)($ref['field'] ?? '') === 'same_model') {
            return 'field:tag:' . supplier_products_characteristic_norm_name('same_model');
        }
        return 'basic:' . (string)($ref['field'] ?? '');
    }
    return 'field:' . (string)($ref['kind'] ?? '') . ':' . supplier_products_characteristic_norm_name((string)($ref['field'] ?? ''));
}

function supplier_products_bulk_copy_format_scalar($value): string
{
    if ($value === null) return '';
    if (is_float($value)) {
        $text = number_format($value, 4, '.', '');
        $text = preg_replace('/\.?0+$/', '', $text);
        return $text === '-0' ? '0' : (string)$text;
    }
    $text = trim((string)$value);
    if ($text !== '' && preg_match('/^-?\d+\.\d+$/', $text)) {
        $text = preg_replace('/\.?0+$/', '', $text);
    }
    return $text === '-0' ? '0' : (string)$text;
}

function supplier_products_bulk_copy_fallback_value(array $product, string $kind, string $field): string
{
    if ($kind === 'standard') {
        return match ($field) {
            'purchase_price' => supplier_products_bulk_copy_format_scalar($product['price_original'] ?? ''),
            'brand', 'brand_ozon', 'brand_wb' => trim((string)($product['brand'] ?? '')),
            'stock' => supplier_products_bulk_copy_format_scalar($product['stock_qty'] ?? ''),
            default => '',
        };
    }

    if ($kind !== 'tag') {
        return '';
    }

    return match ($field) {
        'vendorCode', 'article' => trim((string)($product['vendor_code'] ?? '')),
        'categoryId', 'category' => trim((string)($product['category_id'] ?? '')),
        'ozon_category' => trim((string)($product['ozon_category'] ?? '')),
        'wb_category', 'wb_subject_id' => trim((string)($product['wb_category'] ?? '')),
        'brand', 'vendor' => trim((string)($product['brand'] ?? '')),
        'price_original', 'price' => supplier_products_bulk_copy_format_scalar($product['price_original'] ?? ''),
        'stock', 'quantity' => supplier_products_bulk_copy_format_scalar($product['stock_qty'] ?? ''),
        'count' => supplier_products_bulk_copy_format_scalar($product['count_qty'] ?? ''),
        default => '',
    };
}

function supplier_products_bulk_copy_field_options(int $datasetId, array $offerIds, array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($products);
    $fieldCounts = [];
    $pdo = db();

    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT field_kind, field_name, COUNT(DISTINCT product_id) AS products_count
            FROM feedtools_supplier_product_fields
            WHERE product_id IN ({$placeholders})
              AND field_kind IN ('tag', 'param', 'wb_param', 'attr', 'standard', 'feature')
              AND field_name <> ''
            GROUP BY field_kind, field_name
        ");
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $kind = (string)($row['field_kind'] ?? '');
            $field = trim((string)($row['field_name'] ?? ''));
            if ($kind === '' || $field === '') {
                continue;
            }
            $key = $kind . "\n" . $field;
            $fieldCounts[$key] = ($fieldCounts[$key] ?? 0) + (int)($row['products_count'] ?? 0);
        }
    }

    $options = [];
    $seen = [];
    $add = static function (string $type, string $kind, string $field, int $count = 0, ?string $label = null) use (&$options, &$seen): void {
        $key = $type === 'basic' ? ('basic:' . $field) : ('field:' . $kind . ':' . supplier_products_characteristic_norm_name($field));
        if (isset($seen[$key])) {
            if ($count > 0) {
                foreach ($options as &$option) {
                    if (($option['key'] ?? '') === $key) {
                        $option['count'] = max((int)($option['count'] ?? 0), $count);
                        break;
                    }
                }
                unset($option);
            }
            return;
        }
        $seen[$key] = true;
        $options[] = [
            'key' => $key,
            'type' => $type,
            'kind' => $kind,
            'field' => $field,
            'group' => supplier_products_bulk_copy_group_label($type, $kind),
            'group_rank' => supplier_products_bulk_copy_group_rank($type, $kind),
            'label' => $label ?? supplier_products_bulk_copy_field_label($type, $kind, $field),
            'count' => max(0, $count),
        ];
    };

    foreach (supplier_products_bulk_copy_basic_field_labels() as $field => $label) {
        $count = $field === 'same_model'
            ? (int)($fieldCounts['tag' . "\n" . 'same_model'] ?? 0)
            : count($products);
        $add('basic', '', (string)$field, $count, (string)$label);
    }
    foreach (supplier_products_standard_field_labels() as $field => $label) {
        $add('field', 'standard', (string)$field, (int)($fieldCounts['standard' . "\n" . $field] ?? 0), (string)$label);
    }
    foreach (supplier_products_bulk_copy_common_tag_labels() as $field => $label) {
        $add('field', 'tag', (string)$field, (int)($fieldCounts['tag' . "\n" . $field] ?? 0), (string)$label);
    }
    foreach ($fieldCounts as $compound => $count) {
        [$kind, $field] = array_pad(explode("\n", $compound, 2), 2, '');
        if ($kind === '' || $field === '') {
            continue;
        }
        $add('field', $kind, $field, (int)$count, null);
    }

    usort($options, static function (array $a, array $b): int {
        $rankCmp = ((int)($a['group_rank'] ?? 90)) <=> ((int)($b['group_rank'] ?? 90));
        if ($rankCmp !== 0) return $rankCmp;
        $groupCmp = strnatcasecmp((string)($a['group'] ?? ''), (string)($b['group'] ?? ''));
        if ($groupCmp !== 0) return $groupCmp;
        return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    return [
        'found' => count($products),
        'options' => array_map(static function (array $option): array {
            unset($option['group_rank']);
            return $option;
        }, $options),
    ];
}

function supplier_products_bulk_copy_read_values(PDO $pdo, array $productRows, array $source): array
{
    $values = [];
    foreach ($productRows as $productId => $product) {
        $productId = (int)$productId;
        if ($productId > 0) {
            $values[$productId] = '';
        }
    }
    if (!$values) {
        return [];
    }

    if (($source['type'] ?? '') === 'basic') {
        $field = (string)($source['field'] ?? '');
        if ($field === 'same_model') {
            $source = ['type' => 'field', 'kind' => 'tag', 'field' => 'same_model'];
        } else {
            foreach ($productRows as $productId => $product) {
                $values[(int)$productId] = (string)($product[$field] ?? '');
            }
            return $values;
        }
    }

    $kind = (string)($source['kind'] ?? '');
    $field = (string)($source['field'] ?? '');
    $productIds = array_keys($values);
    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT product_id, field_value
            FROM feedtools_supplier_product_fields
            WHERE product_id IN ({$placeholders})
              AND field_kind = ?
              AND field_name = ?
            ORDER BY product_id ASC, sort_order ASC, id ASC
        ");
        $st->execute(array_merge($chunk, [$kind, $field]));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0 || !array_key_exists($productId, $values)) {
                continue;
            }
            $value = (string)($row['field_value'] ?? '');
            if ($values[$productId] === '' || trim($values[$productId]) === '') {
                $values[$productId] = $value;
            }
        }
    }

    foreach ($values as $productId => $value) {
        if (trim((string)$value) !== '') {
            continue;
        }
        $values[(int)$productId] = supplier_products_bulk_copy_fallback_value((array)($productRows[$productId] ?? []), $kind, $field);
    }

    return $values;
}

function supplier_products_bulk_copy_write_value(PDO $pdo, int $supplierId, int $productId, array $target, string $value, array $cfg = []): bool
{
    if (($target['type'] ?? '') === 'basic') {
        $field = (string)($target['field'] ?? '');
        if ($field === 'same_model') {
            $target = ['type' => 'field', 'kind' => 'tag', 'field' => 'same_model'];
        } else {
            if (!array_key_exists($field, supplier_products_bulk_copy_basic_field_labels())) {
                return false;
            }
            $st = $pdo->prepare("SELECT {$field} FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
            $st->execute([$productId]);
            $current = (string)($st->fetchColumn() ?? '');
            if ($current === $value) {
                return false;
            }
            $upd = $pdo->prepare("UPDATE feedtools_supplier_products SET {$field} = ? WHERE id = ?");
            $upd->execute([$value, $productId]);
            return true;
        }
    }

    $kind = (string)($target['kind'] ?? '');
    $field = (string)($target['field'] ?? '');
    if ($kind === 'standard') {
        $value = supplier_products_normalize_inline_field_value($field, $value);
        return supplier_products_set_standard_field_value($pdo, $supplierId, $productId, $field, $value);
    }

    if ($kind === 'feature') {
        $field = supplier_products_validate_field_name($kind, $field);
        $value = supplier_products_normalize_feature_value($value);
    } else {
        $field = supplier_products_validate_field_name($kind, $field);
        $value = supplier_products_normalize_inline_field_value($field, $value);
        $value = supplier_products_normalize_marketplace_field_value_for_product($productId, $kind, $field, $value, $cfg);
    }

    $st = $pdo->prepare("
        SELECT id, field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = ?
          AND field_name = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$productId, $kind, $field]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $changed = false;

    if ($rows) {
        $first = $rows[0];
        if ((string)($first['field_value'] ?? '') !== $value) {
            $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_value = ? WHERE id = ?");
            $upd->execute([$value, (int)$first['id']]);
            $changed = true;
        }
        $duplicateIds = [];
        foreach (array_slice($rows, 1) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $duplicateIds[] = $id;
        }
        if ($duplicateIds) {
            $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN (" . implode(',', array_fill(0, count($duplicateIds), '?')) . ")");
            $del->execute($duplicateIds);
            $changed = true;
        }
        return $changed;
    }

    if (trim($value) === '') {
        return false;
    }

    $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
    $sort->execute([$productId]);
    $sortOrder = (int)$sort->fetchColumn();
    $ins = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_fields (
            supplier_id, product_id, field_kind, field_name, field_value, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$supplierId, $productId, $kind, $field, $value, $sortOrder]);
    return true;
}

function supplier_products_bulk_copy_field_values(int $datasetId, array $offerIds, array $targetRef, array $sourceRef, array $cfg = [], string $scope = 'selected', bool $skipEmpty = true): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $target = supplier_products_bulk_copy_field_ref($targetRef);
    $source = supplier_products_bulk_copy_field_ref($sourceRef);
    if (supplier_products_bulk_copy_ref_key($target) === supplier_products_bulk_copy_ref_key($source)) {
        throw new RuntimeException('Выбери разные поля: куда записать и откуда взять.');
    }

    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($products);
    $pdo = db();
    $productRows = [];
    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT id, offer_id, name, vendor_code, category_id, ozon_category, wb_category, brand,
                   description_html, count_qty, stock_qty, price_original
            FROM feedtools_supplier_products
            WHERE id IN ({$placeholders})
        ");
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['id'] ?? 0);
            if ($productId > 0) {
                $productRows[$productId] = $row;
            }
        }
    }
    if (!$productRows) {
        throw new RuntimeException('Товары не найдены.');
    }

    $sourceValues = supplier_products_bulk_copy_read_values($pdo, $productRows, $source);
    $changedProducts = [];
    $valuesChanged = 0;
    $emptySkipped = 0;

    foreach ($productRows as $productId => $product) {
        $productId = (int)$productId;
        $value = (string)($sourceValues[$productId] ?? '');
        if ($skipEmpty && trim($value) === '') {
            $emptySkipped++;
            continue;
        }
        if (supplier_products_bulk_copy_write_value($pdo, $supplierId, $productId, $target, $value, $cfg)) {
            $changedProducts[$productId] = true;
            $valuesChanged++;
        }
    }

    foreach (array_keys($changedProducts) as $productId) {
        supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
    }
    if ($changedProducts) {
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    }

    return [
        'requested' => $scope === 'all' ? count($products) : count($offerIds),
        'found' => count($products),
        'products_changed' => count($changedProducts),
        'values_changed' => $valuesChanged,
        'empty_skipped' => $emptySkipped,
        'target' => $target,
        'source' => $source,
    ];
}

function supplier_products_bulk_replace_values(int $datasetId, array $offerIds, array $target, array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $target = supplier_products_bulk_replace_target($target);
    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($products);
    $values = [];

    if ($target['type'] === 'basic') {
        $column = (string)$target['field'];
        $pdo = db();
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("
                SELECT {$column} AS field_value
                FROM feedtools_supplier_products
                WHERE id IN ({$placeholders})
            ");
            $st->execute($chunk);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $value = trim((string)($row['field_value'] ?? ''));
                if ($value !== '') {
                    $values[$value] = ($values[$value] ?? 0) + 1;
                }
            }
        }
    } elseif ($target['type'] === 'standard') {
        $field = (string)$target['field'];
        $pdo = db();
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $productsSt = $pdo->prepare("
                SELECT id, brand
                FROM feedtools_supplier_products
                WHERE id IN ({$placeholders})
            ");
            $productsSt->execute($chunk);
            $productBrands = [];
            while ($row = $productsSt->fetch(PDO::FETCH_ASSOC)) {
                $productBrands[(int)($row['id'] ?? 0)] = (string)($row['brand'] ?? '');
            }

            $fieldNames = [$field];
            if (in_array($field, ['brand_ozon', 'brand_wb'], true)) {
                $fieldNames[] = 'brand';
            }
            $fieldNames = array_values(array_unique($fieldNames));
            $fieldPlaceholders = implode(',', array_fill(0, count($fieldNames), '?'));
            $st = $pdo->prepare("
                SELECT product_id, field_name, field_value
                FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$placeholders})
                  AND field_kind = 'standard'
                  AND field_name IN ({$fieldPlaceholders})
                ORDER BY product_id ASC, sort_order ASC, id ASC
            ");
            $st->execute(array_merge($chunk, $fieldNames));
            $standardValues = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $productId = (int)($row['product_id'] ?? 0);
                $name = (string)($row['field_name'] ?? '');
                if ($productId > 0 && $name !== '' && !array_key_exists($name, $standardValues[$productId] ?? [])) {
                    $standardValues[$productId][$name] = (string)($row['field_value'] ?? '');
                }
            }

            foreach ($chunk as $productId) {
                $productId = (int)$productId;
                $current = (string)($standardValues[$productId][$field] ?? '');
                if (!array_key_exists($field, $standardValues[$productId] ?? []) && in_array($field, ['brand_ozon', 'brand_wb'], true)) {
                    $current = (string)($standardValues[$productId]['brand'] ?? ($productBrands[$productId] ?? ''));
                }
                $value = trim($current);
                if ($value !== '') {
                    $values[$value] = ($values[$value] ?? 0) + 1;
                }
            }
        }
    } elseif ($target['type'] === 'tag') {
        $kind = 'tag';
        $field = (string)$target['field'];
        $pdo = db();
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("
                SELECT field_value
                FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$placeholders})
                  AND field_kind = ?
                  AND field_name = ?
            ");
            $st->execute(array_merge($chunk, [$kind, $field]));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $value = trim((string)($row['field_value'] ?? ''));
                if ($value !== '') {
                    $values[$value] = ($values[$value] ?? 0) + 1;
                }
            }
        }
    } else {
        $targets = supplier_products_bulk_field_targets($target);
        $pdo = db();
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($targets as $fieldTarget) {
                $kind = (string)$fieldTarget['kind'];
                $field = (string)$fieldTarget['field'];
                $st = $pdo->prepare("
                    SELECT field_value
                    FROM feedtools_supplier_product_fields
                    WHERE product_id IN ({$placeholders})
                      AND field_kind = ?
                      AND field_name = ?
                ");
                $st->execute(array_merge($chunk, [$kind, $field]));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $value = trim((string)($row['field_value'] ?? ''));
                    if ($value !== '') {
                        $values[$value] = ($values[$value] ?? 0) + 1;
                    }
                }
            }
        }
    }

    uksort($values, 'strnatcasecmp');
    $items = [];
    foreach ($values as $value => $count) {
        $items[] = ['value' => $value, 'count' => (int)$count];
        if (count($items) >= 200) {
            break;
        }
    }

    return [
        'target' => $target,
        'found' => count($products),
        'values' => $items,
    ];
}

function supplier_products_bulk_replace_characteristics(int $datasetId, array $offerIds, array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($products);
    $items = supplier_products_bulk_marketplace_characteristics($productIds, $cfg);
    $pdo = db();
    foreach (array_chunk($productIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT field_kind, field_name, COUNT(*) AS items_count
            FROM feedtools_supplier_product_fields
            WHERE product_id IN ({$placeholders})
              AND field_kind IN ('param', 'wb_param')
              AND field_name <> ''
            GROUP BY field_kind, field_name
        ");
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $kind = (string)($row['field_kind'] ?? '');
            $name = trim((string)($row['field_name'] ?? ''));
            if (!in_array($kind, ['param', 'wb_param'], true) || $name === '') {
                continue;
            }
            supplier_products_bulk_add_characteristic_option($items, $kind, $name, $kind === 'wb_param' ? 'wb' : '', (int)($row['items_count'] ?? 0), $cfg);
        }
    }

    $out = [];
    foreach ($items as $groupKey => $item) {
        if (!is_array($item)) {
            continue;
        }
        $targets = [];
        foreach ((array)($item['targets'] ?? []) as $target) {
            if (!is_array($target)) {
                continue;
            }
            $kind = (string)($target['kind'] ?? '');
            $field = (string)($target['field'] ?? '');
            if (!in_array($kind, ['param', 'wb_param'], true) || trim($field) === '') {
                continue;
            }
            $sources = array_keys((array)($target['sources'] ?? []));
            sort($sources, SORT_NATURAL | SORT_FLAG_CASE);
            $targets[] = [
                'kind' => $kind,
                'field' => $field,
                'sources' => $sources,
            ];
        }
        if (!$targets) {
            continue;
        }

        usort($targets, static function (array $a, array $b): int {
            $rank = static function (array $target): int {
                $sources = (array)($target['sources'] ?? []);
                if (in_array('ozon', $sources, true)) return 0;
                if (in_array('wb', $sources, true)) return 1;
                return 2;
            };
            $rankCmp = $rank($a) <=> $rank($b);
            if ($rankCmp !== 0) {
                return $rankCmp;
            }
            return strnatcasecmp((string)($a['field'] ?? ''), (string)($b['field'] ?? ''));
        });

        $sources = array_keys((array)($item['sources'] ?? []));
        $badges = [];
        if (in_array('ozon', $sources, true)) {
            $badges[] = 'oz';
        }
        if (in_array('wb', $sources, true)) {
            $badges[] = 'WB';
        }
        $primary = $targets[0];
        $label = supplier_products_bulk_characteristic_label((string)$groupKey, $item, $targets, $badges);
        $allowedValues = array_values((array)($item['allowed_values'] ?? []));
        usort($allowedValues, static fn($a, $b): int => strnatcasecmp((string)$a, (string)$b));
        $out[] = [
            'kind' => (string)$primary['kind'],
            'name' => (string)$primary['field'],
            'label' => $label,
            'count' => (int)($item['count'] ?? 0),
            'sources' => $sources,
            'targets' => $targets,
            'allowed_values' => $allowedValues,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    return [
        'found' => count($products),
        'characteristics' => $out,
    ];
}

function supplier_products_bulk_replace_text(int $datasetId, array $offerIds, array $target, string $oldText, string $newText, array $cfg = [], string $scope = 'selected'): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $target = supplier_products_bulk_replace_target($target);
    $products = supplier_products_ids_for_bulk_replace_scope($supplierId, $offerIds, $scope, $cfg);
    $productIds = array_keys($products);
    $changedProducts = [];
    $valuesChanged = 0;
    $pdo = db();

    if ($target['type'] === 'basic') {
        $column = (string)$target['field'];
        $select = $pdo->prepare("SELECT id, {$column} AS current_value FROM feedtools_supplier_products WHERE id = ?");
        $update = $pdo->prepare("UPDATE feedtools_supplier_products SET {$column} = ? WHERE id = ?");
        foreach ($productIds as $productId) {
            $select->execute([(int)$productId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }
            $current = (string)($row['current_value'] ?? '');
            $next = supplier_products_bulk_replace_value($current, $oldText, $newText);
            if ($next === $current) {
                continue;
            }
            $update->execute([$next, (int)$productId]);
            $changedProducts[(int)$productId] = true;
            $valuesChanged++;
        }
    } elseif ($target['type'] === 'standard') {
        $field = (string)$target['field'];
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $productsSt = $pdo->prepare("
                SELECT id, brand
                FROM feedtools_supplier_products
                WHERE id IN ({$placeholders})
            ");
            $productsSt->execute($chunk);
            $productBrands = [];
            while ($row = $productsSt->fetch(PDO::FETCH_ASSOC)) {
                $productBrands[(int)($row['id'] ?? 0)] = (string)($row['brand'] ?? '');
            }

            $fieldNames = [$field];
            if (in_array($field, ['brand_ozon', 'brand_wb'], true)) {
                $fieldNames[] = 'brand';
            }
            $fieldNames = array_values(array_unique($fieldNames));
            $fieldPlaceholders = implode(',', array_fill(0, count($fieldNames), '?'));
            $st = $pdo->prepare("
                SELECT id, product_id, field_name, field_value
                FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$placeholders})
                  AND field_kind = 'standard'
                  AND field_name IN ({$fieldPlaceholders})
                ORDER BY product_id ASC, sort_order ASC, id ASC
            ");
            $st->execute(array_merge($chunk, $fieldNames));
            $standardValues = [];
            while ($fieldRow = $st->fetch(PDO::FETCH_ASSOC)) {
                $productId = (int)($fieldRow['product_id'] ?? 0);
                $name = (string)($fieldRow['field_name'] ?? '');
                if ($productId > 0 && $name !== '' && !array_key_exists($name, $standardValues[$productId] ?? [])) {
                    $standardValues[$productId][$name] = (string)($fieldRow['field_value'] ?? '');
                }
            }

            foreach ($chunk as $productId) {
                $productId = (int)$productId;
                if ($productId <= 0) {
                    continue;
                }
                $hasField = array_key_exists($field, $standardValues[$productId] ?? []);
                $current = $hasField ? (string)$standardValues[$productId][$field] : '';
                if (!$hasField && in_array($field, ['brand_ozon', 'brand_wb'], true)) {
                    $current = (string)($standardValues[$productId]['brand'] ?? ($productBrands[$productId] ?? ''));
                }
                $next = supplier_products_bulk_replace_value($current, $oldText, $newText);
                if ($next === $current) {
                    continue;
                }
                if (supplier_products_set_standard_field_value($pdo, $supplierId, $productId, $field, $next)) {
                    $changedProducts[$productId] = true;
                    $valuesChanged++;
                }
            }
        }
    } else {
        if ($target['type'] === 'tag') {
            $targets = [['kind' => 'tag', 'field' => (string)$target['field']]];
        } else {
            $targets = supplier_products_bulk_field_targets($target);
        }
        $update = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_value = ? WHERE id = ?");
        $insert = $pdo->prepare("
            INSERT INTO feedtools_supplier_product_fields
                (supplier_id, product_id, field_kind, field_name, field_value, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $chunkProductIds = array_values(array_map('intval', $chunk));
            foreach ($targets as $fieldTarget) {
                $kind = (string)$fieldTarget['kind'];
                $field = (string)$fieldTarget['field'];
                $seenProductIds = [];
                $st = $pdo->prepare("
                    SELECT id, product_id, field_value
                    FROM feedtools_supplier_product_fields
                    WHERE product_id IN ({$placeholders})
                      AND field_kind = ?
                      AND field_name = ?
                    ORDER BY product_id ASC, sort_order ASC, id ASC
                ");
                $st->execute(array_merge($chunk, [$kind, $field]));
                while ($fieldRow = $st->fetch(PDO::FETCH_ASSOC)) {
                    $fieldId = (int)($fieldRow['id'] ?? 0);
                    $productId = (int)($fieldRow['product_id'] ?? 0);
                    if ($fieldId <= 0 || $productId <= 0) {
                        continue;
                    }
                    $seenProductIds[$productId] = true;
                    $current = (string)($fieldRow['field_value'] ?? '');
                    $next = supplier_products_bulk_replace_value($current, $oldText, $newText);
                    if ($next === $current) {
                        continue;
                    }
                    $update->execute([$next, $fieldId]);
                    $changedProducts[$productId] = true;
                    $valuesChanged++;
                }

                if ($oldText !== '' || trim($newText) === '') {
                    continue;
                }

                $missingProductIds = [];
                foreach ($chunkProductIds as $productId) {
                    if ($productId > 0 && !isset($seenProductIds[$productId])) {
                        $missingProductIds[] = $productId;
                    }
                }
                if (!$missingProductIds) {
                    continue;
                }

                $missingPlaceholders = implode(',', array_fill(0, count($missingProductIds), '?'));
                $sortSt = $pdo->prepare("
                    SELECT product_id, COALESCE(MAX(sort_order), 0) + 10 AS next_sort
                    FROM feedtools_supplier_product_fields
                    WHERE product_id IN ({$missingPlaceholders})
                    GROUP BY product_id
                ");
                $sortSt->execute($missingProductIds);
                $nextSortByProduct = array_fill_keys($missingProductIds, 10);
                while ($sortRow = $sortSt->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)($sortRow['product_id'] ?? 0);
                    if ($pid > 0) {
                        $nextSortByProduct[$pid] = (int)($sortRow['next_sort'] ?? 10);
                    }
                }

                foreach ($missingProductIds as $productId) {
                    $nextValue = supplier_products_bulk_replace_value('', $oldText, $newText);
                    $insert->execute([
                        $supplierId,
                        $productId,
                        $kind,
                        $field,
                        $nextValue,
                        (int)($nextSortByProduct[$productId] ?? 10),
                    ]);
                    $changedProducts[$productId] = true;
                    $valuesChanged++;
                }
            }
        }
    }

    foreach (array_keys($changedProducts) as $productId) {
        supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
    }
    if ($changedProducts) {
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    }

    return [
        'requested' => $scope === 'all' ? count($products) : count($offerIds),
        'found' => count($products),
        'products_changed' => count($changedProducts),
        'values_changed' => $valuesChanged,
        'target' => $target,
    ];
}

function supplier_products_add_field(int $productId, string $kind, string $name, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $kind = supplier_products_validate_field_kind($kind);

    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }
    $supplierId = (int)($product['supplier_id'] ?? 0);
    if ($kind === 'feature') {
        $name = supplier_products_next_feature_field_name($pdo, $productId);
        $value = supplier_products_normalize_feature_value($value);
    } else {
        $name = supplier_products_validate_field_name($kind, $name);
        $value = supplier_products_normalize_inline_field_value($name, $value);
        $value = supplier_products_normalize_marketplace_field_value_for_product($productId, $kind, $name, $value, $cfg);
    }

    if ($kind === 'standard') {
        supplier_products_set_standard_field_value($pdo, $supplierId, $productId, $name, $value);
        $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);

        $field = $pdo->prepare("
            SELECT *
            FROM feedtools_supplier_product_fields
            WHERE product_id = ?
              AND field_kind = 'standard'
              AND field_name = ?
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $field->execute([$productId, $name]);
        $fieldRow = $field->fetch(PDO::FETCH_ASSOC);
        return [
            'product' => $fresh,
            'field' => is_array($fieldRow) ? $fieldRow : ['id' => 0, 'field_kind' => 'standard', 'field_name' => $name, 'field_value' => $value],
        ];
    }

    $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
    $sort->execute([$productId]);
    $sortOrder = (int)$sort->fetchColumn();

    $ins = $pdo->prepare("
        INSERT INTO feedtools_supplier_product_fields (
            supplier_id, product_id, field_kind, field_name, field_value, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$supplierId, $productId, $kind, $name, $value, $sortOrder]);
    $fieldId = (int)$pdo->lastInsertId();
    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);

    $field = $pdo->prepare("SELECT * FROM feedtools_supplier_product_fields WHERE id = ? LIMIT 1");
    $field->execute([$fieldId]);
    $fieldRow = $field->fetch(PDO::FETCH_ASSOC);
    return [
        'product' => $fresh,
        'field' => is_array($fieldRow) ? $fieldRow : ['id' => $fieldId],
    ];
}

function supplier_products_update_field(int $fieldId, string $name, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM feedtools_supplier_product_fields WHERE id = ? LIMIT 1");
    $st->execute([$fieldId]);
    $field = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($field)) {
        throw new RuntimeException('Поле товара не найдено.');
    }
    $kind = supplier_products_validate_field_kind((string)($field['field_kind'] ?? 'tag'));
    if ($kind !== 'standard' && $kind !== 'feature' && trim($name) === '') {
        return supplier_products_delete_field($fieldId, $cfg);
    }
    if ($kind === 'feature') {
        $name = (string)($field['field_name'] ?? '');
        if (supplier_products_feature_field_index($name) <= 0) {
            $name = supplier_products_feature_field_name(1);
        }
        $value = supplier_products_normalize_feature_value($value);
    } elseif ($kind === 'standard') {
        $name = (string)($field['field_name'] ?? '');
        $name = supplier_products_validate_field_name($kind, $name);
        $value = supplier_products_normalize_inline_field_value($name, $value);
    } else {
        $name = supplier_products_validate_field_name($kind, $name);
        $value = supplier_products_normalize_inline_field_value($name, $value);
    }
    $productId = (int)($field['product_id'] ?? 0);
    $supplierId = (int)($field['supplier_id'] ?? 0);
    if ($kind !== 'feature') {
        $value = supplier_products_normalize_marketplace_field_value_for_product($productId, $kind, $name, $value, $cfg);
    }

    $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_name = ?, field_value = ? WHERE id = ?");
    $upd->execute([$name, $value, $fieldId]);
    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);

    $st->execute([$fieldId]);
    $fieldRow = $st->fetch(PDO::FETCH_ASSOC);
    return [
        'product' => $fresh,
        'field' => is_array($fieldRow) ? $fieldRow : ['id' => $fieldId],
    ];
}

function supplier_products_set_same_model(int $productId, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $supplierId = (int)($product['supplier_id'] ?? 0);
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $value = mb_substr((string)$value, 0, 255, 'UTF-8');

    $fields = $pdo->prepare("
        SELECT id
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'tag'
          AND field_name = 'same_model'
        ORDER BY sort_order ASC, id ASC
    ");
    $fields->execute([$productId]);
    $fieldIds = array_map('intval', $fields->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $keepFieldId = (int)($fieldIds[0] ?? 0);

    if ($value === '') {
        if ($fieldIds) {
            $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
            $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN ({$placeholders})");
            $del->execute($fieldIds);
        }
        $keepFieldId = 0;
    } elseif ($keepFieldId > 0) {
        $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_value = ? WHERE id = ?");
        $upd->execute([$value, $keepFieldId]);
        if (count($fieldIds) > 1) {
            $extraIds = array_slice($fieldIds, 1);
            $placeholders = implode(',', array_fill(0, count($extraIds), '?'));
            $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN ({$placeholders})");
            $del->execute($extraIds);
        }
    } else {
        $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
        $sort->execute([$productId]);
        $sortOrder = (int)$sort->fetchColumn();

        $ins = $pdo->prepare("
            INSERT INTO feedtools_supplier_product_fields (
                supplier_id, product_id, field_kind, field_name, field_value, sort_order
            ) VALUES (?, ?, 'tag', 'same_model', ?, ?)
        ");
        $ins->execute([$supplierId, $productId, $value, $sortOrder]);
        $keepFieldId = (int)$pdo->lastInsertId();
    }

    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    return [
        'product' => $fresh,
        'field_id' => $keepFieldId,
        'value' => $value,
    ];
}

function supplier_products_set_category_field(int $productId, string $source, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $source = trim($source);
    if ($source === 'wb') {
        $source = 'wildberries';
    }
    if (!in_array($source, ['ozon', 'wildberries'], true)) {
        throw new RuntimeException('Неподдерживаемый источник категории.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $supplierId = (int)($product['supplier_id'] ?? 0);
    $names = $source === 'ozon' ? ['ozon_category'] : ['wb_category', 'wb_subject_id'];
    $canonicalName = $names[0];
    $categoryColumn = $source === 'ozon' ? 'ozon_category' : 'wb_category';
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $fieldStmt = $pdo->prepare("
        SELECT *
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'tag'
          AND field_name IN ({$placeholders})
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");
    $fieldStmt->execute(array_merge([$productId], $names));
    $field = $fieldStmt->fetch(PDO::FETCH_ASSOC);

    $value = trim($value);
    if ($value === '') {
        $del = $pdo->prepare("
            DELETE FROM feedtools_supplier_product_fields
            WHERE product_id = ?
              AND field_kind = 'tag'
              AND field_name IN ({$placeholders})
        ");
        $del->execute(array_merge([$productId], $names));
        $clear = $pdo->prepare("UPDATE feedtools_supplier_products SET {$categoryColumn} = '' WHERE id = ?");
        $clear->execute([$productId]);
    } elseif (is_array($field)) {
        $upd = $pdo->prepare("UPDATE feedtools_supplier_product_fields SET field_name = ?, field_value = ? WHERE id = ?");
        $upd->execute([$canonicalName, $value, (int)$field['id']]);
    } else {
        $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM feedtools_supplier_product_fields WHERE product_id = ?");
        $sort->execute([$productId]);
        $sortOrder = (int)$sort->fetchColumn();
        $ins = $pdo->prepare("
            INSERT INTO feedtools_supplier_product_fields (
                supplier_id, product_id, field_kind, field_name, field_value, sort_order
            ) VALUES (?, ?, 'tag', ?, ?, ?)
        ");
        $ins->execute([$supplierId, $productId, $canonicalName, $value, $sortOrder]);
    }

    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    return [
        'product' => $fresh,
        'source' => $source,
        'value' => $value,
    ];
}

function supplier_products_set_category_field_bulk(int $datasetId, array $offerIds, string $source, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $source = trim($source);
    if ($source === 'wb') {
        $source = 'wildberries';
    }
    if (!in_array($source, ['ozon', 'wildberries'], true)) {
        throw new RuntimeException('Неподдерживаемый источник категории.');
    }

    $selectedOfferIds = [];
    foreach ($offerIds as $offerId) {
        $offerId = trim((string)$offerId);
        if ($offerId === '') {
            continue;
        }
        $selectedOfferIds[$offerId] = true;
    }
    $selectedOfferIds = array_keys($selectedOfferIds);
    if (!$selectedOfferIds) {
        throw new RuntimeException('Не выбраны товары.');
    }

    $pdo = db();
    $products = [];
    foreach (array_chunk($selectedOfferIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT id, offer_id
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($row['id'] ?? 0);
            if ($productId > 0) {
                $products[$productId] = (string)($row['offer_id'] ?? '');
            }
        }
    }

    if (!$products) {
        throw new RuntimeException('Выбранные товары не найдены.');
    }

    $productIds = array_keys($products);
    $names = $source === 'ozon' ? ['ozon_category'] : ['wb_category', 'wb_subject_id'];
    $canonicalName = $names[0];
    $categoryColumn = $source === 'ozon' ? 'ozon_category' : 'wb_category';
    $value = trim($value);

    $pdo->beginTransaction();
    try {
        foreach (array_chunk($productIds, 500) as $chunk) {
            $productPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $namePlaceholders = implode(',', array_fill(0, count($names), '?'));
            $del = $pdo->prepare("
                DELETE FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$productPlaceholders})
                  AND field_kind = 'tag'
                  AND field_name IN ({$namePlaceholders})
            ");
            $del->execute(array_merge($chunk, $names));
        }

        if ($value !== '') {
            $sortByProduct = [];
            foreach (array_chunk($productIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sort = $pdo->prepare("
                    SELECT product_id, COALESCE(MAX(sort_order), 0) + 10 AS next_sort
                    FROM feedtools_supplier_product_fields
                    WHERE product_id IN ({$placeholders})
                    GROUP BY product_id
                ");
                $sort->execute($chunk);
                while ($row = $sort->fetch(PDO::FETCH_ASSOC)) {
                    $sortByProduct[(int)($row['product_id'] ?? 0)] = (int)($row['next_sort'] ?? 10);
                }
            }

            $ins = $pdo->prepare("
                INSERT INTO feedtools_supplier_product_fields (
                    supplier_id, product_id, field_kind, field_name, field_value, sort_order
                ) VALUES (?, ?, 'tag', ?, ?, ?)
            ");
            foreach ($productIds as $productId) {
                $ins->execute([
                    $supplierId,
                    $productId,
                    $canonicalName,
                    $value,
                    (int)($sortByProduct[$productId] ?? 10),
                ]);
            }
        } else {
            foreach (array_chunk($productIds, 500) as $chunk) {
                $productPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
                $clear = $pdo->prepare("
                    UPDATE feedtools_supplier_products
                    SET {$categoryColumn} = ''
                    WHERE id IN ({$productPlaceholders})
                ");
                $clear->execute($chunk);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $freshProducts = [];
    foreach ($productIds as $productId) {
        $fresh = supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
        $freshProducts[] = [
            'product_id' => (int)($fresh['id'] ?? $productId),
            'offer_id' => (string)($fresh['offer_id'] ?? ($products[$productId] ?? '')),
            'brand' => (string)($fresh['brand'] ?? ''),
            'ozon_category' => (string)($fresh['ozon_category'] ?? ''),
            'wb_category' => (string)($fresh['wb_category'] ?? ''),
        ];
    }
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);

    return [
        'source' => $source,
        'value' => $value,
        'updated' => count($freshProducts),
        'requested' => count($selectedOfferIds),
        'products' => $freshProducts,
    ];
}

function supplier_products_tnved_field_name_for_product(int $productId, array $categoryValues, array $cfg = []): string
{
    $categoryValue = trim((string)(($categoryValues[$productId] ?? [])['ozon'] ?? ''));
    if ($categoryValue !== '') {
        $meta = supplier_products_taxonomy_meta_for_category_value('ozon', $categoryValue);
        foreach (supplier_products_characteristic_rows_from_taxonomy_meta($meta, 'ozon', $cfg) as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '' && supplier_products_is_tnved_characteristic_name($name)) {
                return $name;
            }
        }
    }
    return 'ТН ВЭД коды ЕАЭС';
}

function supplier_products_tnved_standard_field_name(): string
{
    return 'tnved_code';
}

function supplier_products_tnved_code_from_text(string $value): string
{
    if (preg_match('~(?<!\d)(\d{10})(?!\d)~', $value, $m)) {
        return (string)$m[1];
    }
    return '';
}

function supplier_products_tnved_standard_value(string $value): string
{
    $value = supplier_products_normalize_inline_field_value('ТН ВЭД коды ЕАЭС', trim($value));
    $code = supplier_products_tnved_code_from_text($value);
    return $code !== '' ? $code : $value;
}

function supplier_products_tnved_description_from_text(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', trim($value)) ?: '');
    if ($value === '') {
        return '';
    }
    $value = preg_replace('~^\d{10}\s*[-–—]?\s*~u', '', $value);
    $value = preg_replace('~^[-–—]\s*~u', '', (string)$value);
    return trim(preg_replace('/\s+/u', ' ', trim((string)$value)) ?: '');
}

function supplier_products_tnved_allowed_value_for_input(string $value, array $allowedValues): string
{
    $value = trim(preg_replace('/\s+/u', ' ', trim($value)) ?: '');
    if ($value === '' || !$allowedValues) {
        return '';
    }
    $valueNorm = supplier_products_characteristic_norm_value($value);
    $valueCode = supplier_products_tnved_code_from_text($value);
    $valueDescNorm = supplier_products_characteristic_norm_value(supplier_products_tnved_description_from_text($value));

    foreach ($allowedValues as $allowed) {
        if (is_array($allowed)) {
            $allowed = (string)($allowed['value'] ?? ($allowed['name'] ?? ''));
        }
        $allowed = trim(preg_replace('/\s+/u', ' ', trim((string)$allowed)) ?: '');
        if ($allowed === '') {
            continue;
        }
        if (supplier_products_characteristic_norm_value($allowed) === $valueNorm) {
            return $allowed;
        }
        $allowedCode = supplier_products_tnved_code_from_text($allowed);
        if ($valueCode !== '' && $allowedCode === $valueCode) {
            return $allowed;
        }
        if ($valueCode === '' && $valueDescNorm !== '') {
            $allowedDescNorm = supplier_products_characteristic_norm_value(supplier_products_tnved_description_from_text($allowed));
            if ($allowedDescNorm !== '' && $allowedDescNorm === $valueDescNorm) {
                return $allowed;
            }
        }
    }

    return '';
}

function supplier_products_tnved_value_for_product(
    int $productId,
    string $value,
    array $categoryValues,
    array $cfg = []
): string {
    $value = supplier_products_normalize_inline_field_value('ТН ВЭД коды ЕАЭС', trim($value));
    if ($productId <= 0 || $value === '') {
        return '';
    }
    $categoryValue = trim((string)(($categoryValues[$productId] ?? [])['ozon'] ?? ''));
    if ($categoryValue === '') {
        return '';
    }
    $meta = supplier_products_taxonomy_meta_for_category_value('ozon', $categoryValue);
    foreach (supplier_products_characteristic_rows_from_taxonomy_meta($meta, 'ozon', $cfg) as $row) {
        if (!supplier_products_is_tnved_characteristic_name((string)($row['name'] ?? ''))) {
            continue;
        }
        $allowed = supplier_products_characteristic_allowed_values_from_meta_row($row);
        return supplier_products_tnved_allowed_value_for_input($value, $allowed);
    }
    return '';
}

function supplier_products_set_tnved_code_bulk(int $datasetId, array $offerIds, string $value, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('Датасет поставщика не найден.');
    }

    $products = supplier_products_ids_for_selected_offers($supplierId, $offerIds, $cfg);
    $productIds = array_keys($products);
    if (!$productIds) {
        throw new RuntimeException('Не выбраны товары.');
    }

    $value = supplier_products_tnved_standard_value($value);
    $pdo = db();
    $changedProducts = [];
    $valuesChanged = 0;

    if ($value === '') {
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($productIds, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("
                    SELECT id, product_id, field_kind, field_name, field_value
                    FROM feedtools_supplier_product_fields
                    WHERE product_id IN ({$placeholders})
                      AND field_kind IN ('standard', 'param', 'wb_param')
                    ORDER BY product_id ASC, sort_order ASC, id ASC
                ");
                $st->execute($chunk);

                $deleteIds = [];
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $productId = (int)($row['product_id'] ?? 0);
                    $kind = (string)($row['field_kind'] ?? '');
                    $fieldName = (string)($row['field_name'] ?? '');
                    $isStandardTnved = $kind === 'standard' && $fieldName === supplier_products_tnved_standard_field_name();
                    $isMarketplaceTnved = ($kind === 'param' || $kind === 'wb_param')
                        && supplier_products_is_tnved_characteristic_name($fieldName);
                    if ($productId <= 0 || (!$isStandardTnved && !$isMarketplaceTnved)) {
                        continue;
                    }
                    $deleteIds[] = (int)($row['id'] ?? 0);
                    $changedProducts[$productId] = true;
                    if (trim((string)($row['field_value'] ?? '')) !== '') {
                        $valuesChanged++;
                    }
                }

                if ($deleteIds) {
                    $delete = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id = ?");
                    foreach ($deleteIds as $deleteId) {
                        if ($deleteId > 0) {
                            $delete->execute([$deleteId]);
                        }
                    }
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $freshProducts = [];
        foreach (array_keys($changedProducts) as $productId) {
            $fresh = supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
            $freshProducts[] = [
                'product_id' => (int)($fresh['id'] ?? $productId),
                'offer_id' => (string)($fresh['offer_id'] ?? ($products[$productId] ?? '')),
            ];
        }
        if ($changedProducts) {
            supplier_products_update_dataset_row_from_db($supplierId, $cfg);
        }

        return [
            'value' => '',
            'requested' => count($offerIds),
            'found' => count($products),
            'products_changed' => count($changedProducts),
            'values_changed' => $valuesChanged,
            'products' => $freshProducts,
        ];
    }

    $categoryValues = supplier_products_bulk_category_values_for_products($productIds);
    $fieldNameByProduct = [];
    foreach ($productIds as $productIdRaw) {
        $productId = (int)$productIdRaw;
        if ($productId > 0) {
            $fieldNameByProduct[$productId] = supplier_products_tnved_field_name_for_product($productId, $categoryValues, $cfg);
        }
    }

    $pdo->beginTransaction();
    try {
        foreach (array_chunk($productIds, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("
                SELECT id, product_id, field_name, field_value
                FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$placeholders})
                  AND field_kind = 'param'
                ORDER BY product_id ASC, sort_order ASC, id ASC
            ");
            $st->execute($chunk);

            $existingByProduct = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $productId = (int)($row['product_id'] ?? 0);
                $fieldName = (string)($row['field_name'] ?? '');
                if ($productId <= 0 || !supplier_products_is_tnved_characteristic_name($fieldName)) {
                    continue;
                }
                $existingByProduct[$productId][] = [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => $fieldName,
                    'value' => (string)($row['field_value'] ?? ''),
                ];
            }

            $sortSt = $pdo->prepare("
                SELECT product_id, COALESCE(MAX(sort_order), 0) + 10 AS next_sort
                FROM feedtools_supplier_product_fields
                WHERE product_id IN ({$placeholders})
                GROUP BY product_id
            ");
            $sortSt->execute($chunk);
            $sortByProduct = array_fill_keys(array_map('intval', $chunk), 10);
            while ($sortRow = $sortSt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)($sortRow['product_id'] ?? 0);
                if ($pid > 0) {
                    $sortByProduct[$pid] = (int)($sortRow['next_sort'] ?? 10);
                }
            }

            $update = $pdo->prepare("
                UPDATE feedtools_supplier_product_fields
                SET field_name = ?, field_value = ?
                WHERE id = ?
            ");
            $delete = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id = ?");
            $insert = $pdo->prepare("
                INSERT INTO feedtools_supplier_product_fields (
                    supplier_id, product_id, field_kind, field_name, field_value, sort_order
                ) VALUES (?, ?, 'param', ?, ?, ?)
            ");

            foreach ($chunk as $productIdRaw) {
                $productId = (int)$productIdRaw;
                if ($productId <= 0) {
                    continue;
                }
                if (supplier_products_set_standard_field_value($pdo, $supplierId, $productId, supplier_products_tnved_standard_field_name(), $value)) {
                    $changedProducts[$productId] = true;
                    $valuesChanged++;
                }
                $fieldName = (string)($fieldNameByProduct[$productId] ?? 'ТН ВЭД коды ЕАЭС');
                $nextValue = supplier_products_tnved_value_for_product($productId, $value, $categoryValues, $cfg);
                $existing = $existingByProduct[$productId] ?? [];
                if ($existing) {
                    if ($nextValue === '') {
                        foreach ($existing as $row) {
                            $delete->execute([(int)$row['id']]);
                            $changedProducts[$productId] = true;
                            if (trim((string)($row['value'] ?? '')) !== '') {
                                $valuesChanged++;
                            }
                        }
                        continue;
                    }
                    $keep = $existing[0];
                    if ((string)$keep['name'] !== $fieldName || (string)$keep['value'] !== $nextValue) {
                        $update->execute([$fieldName, $nextValue, (int)$keep['id']]);
                        $changedProducts[$productId] = true;
                        $valuesChanged++;
                    }
                    foreach (array_slice($existing, 1) as $extra) {
                        $delete->execute([(int)$extra['id']]);
                        $changedProducts[$productId] = true;
                    }
                } elseif ($nextValue !== '') {
                    $insert->execute([
                        $supplierId,
                        $productId,
                        $fieldName,
                        $nextValue,
                        (int)($sortByProduct[$productId] ?? 10),
                    ]);
                    $changedProducts[$productId] = true;
                    $valuesChanged++;
                }
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $freshProducts = [];
    foreach (array_keys($changedProducts) as $productId) {
        $fresh = supplier_products_sync_product_summary_from_db((int)$productId, $cfg);
        $freshProducts[] = [
            'product_id' => (int)($fresh['id'] ?? $productId),
            'offer_id' => (string)($fresh['offer_id'] ?? ($products[$productId] ?? '')),
        ];
    }
    if ($changedProducts) {
        supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    }

    return [
        'value' => $value,
        'requested' => count($offerIds),
        'found' => count($products),
        'products_changed' => count($changedProducts),
        'values_changed' => $valuesChanged,
        'products' => $freshProducts,
    ];
}

function supplier_products_delete_field(int $fieldId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM feedtools_supplier_product_fields WHERE id = ? LIMIT 1");
    $st->execute([$fieldId]);
    $field = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($field)) {
        throw new RuntimeException('Поле товара не найдено.');
    }
    $productId = (int)($field['product_id'] ?? 0);
    $supplierId = (int)($field['supplier_id'] ?? 0);

    $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id = ?");
    $del->execute([$fieldId]);
    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    return [
        'product' => $fresh,
        'deleted_field_id' => $fieldId,
    ];
}

function supplier_products_video_cover_field_names(): array
{
    return [
        'Озон.Видеообложка: ссылка',
        'Ozon.VideoCover: link',
        'Видеообложка',
        'Видео-обложка',
    ];
}

function supplier_products_video_field_names(): array
{
    return [
        'Видео',
        'Video',
        'Яндекс Видео',
        'Yandex Video',
        'Озон.Видео: ссылка',
        'Озон.Видео',
        'Ozon.Video: link',
        'Ozon.Video',
    ];
}

function supplier_products_video_cover_field_norm(string $name): string
{
    $name = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
    $name = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $name) ?: '';
    return trim(preg_replace('~\s+~u', ' ', $name) ?: '');
}

function supplier_products_extract_video_urls(string $value): array
{
    $out = [];
    $seen = [];
    $parts = preg_split('~[\s,;\r\n]+~u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($parts as $part) {
        $url = trim((string)$part, " \t\n\r\0\x0B\"'<>");
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($url, 'UTF-8') : strtolower($url);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $url;
    }
    return $out;
}

function supplier_products_local_media_reference_count(string $relativePath, int $excludeProductId = 0): int
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return 0;
    }

    $needles = array_values(array_unique(array_filter([
        $relativePath,
        rawurlencode($relativePath),
    ], static fn($value): bool => trim((string)$value) !== '')));
    $likeSql = implode(' OR ', array_fill(0, count($needles), '%s LIKE ?'));
    $likeArgs = array_map(static fn(string $value): string => '%' . $value . '%', $needles);
    $productWhere = '';
    $fieldWhere = '';
    if ($excludeProductId > 0) {
        $productWhere = ' AND id <> ?';
        $fieldWhere = ' AND product_id <> ?';
    }

    $productSql = "SELECT COUNT(*) FROM feedtools_supplier_products WHERE (" . sprintf($likeSql, ...array_fill(0, count($needles), 'pictures_json')) . "){$productWhere}";
    $st = db()->prepare($productSql);
    $st->execute($excludeProductId > 0 ? array_merge($likeArgs, [$excludeProductId]) : $likeArgs);
    $count = (int)$st->fetchColumn();

    $fieldSql = "SELECT COUNT(*) FROM feedtools_supplier_product_fields WHERE (" . sprintf($likeSql, ...array_fill(0, count($needles), 'field_value')) . "){$fieldWhere}";
    $st = db()->prepare($fieldSql);
    $st->execute($excludeProductId > 0 ? array_merge($likeArgs, [$excludeProductId]) : $likeArgs);
    return $count + (int)$st->fetchColumn();
}

function supplier_products_delete_local_media_if_unreferenced(array $urls, array $cfg = [], int $excludeProductId = 0): void
{
    $relativeSet = [];
    foreach ($urls as $url) {
        $relative = supplier_products_local_image_relative_from_url((string)$url, $cfg);
        if ($relative !== '') {
            $relativeSet[$relative] = true;
        }
    }
    foreach (array_keys($relativeSet) as $relative) {
        if (supplier_products_local_media_reference_count($relative, $excludeProductId) > 0) {
            continue;
        }
        $path = supplier_products_local_image_abs_path($relative, $cfg);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function supplier_products_delete_video_cover(int $productId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }
    $supplierId = (int)($product['supplier_id'] ?? 0);

    $wanted = [];
    foreach (supplier_products_video_cover_field_names() as $name) {
        $norm = supplier_products_video_cover_field_norm($name);
        if ($norm !== '') {
            $wanted[$norm] = true;
        }
    }

    $fields = $pdo->prepare("
        SELECT id, field_value
        FROM feedtools_supplier_product_fields
        WHERE product_id = ?
          AND field_kind = 'param'
    ");
    $fields->execute([$productId]);
    $deleteIds = [];
    $urls = [];
    while ($field = $fields->fetch(PDO::FETCH_ASSOC)) {
        $nameNorm = supplier_products_video_cover_field_norm((string)($field['field_name'] ?? ''));
        if ($nameNorm === '' || !isset($wanted[$nameNorm])) {
            continue;
        }
        $deleteIds[] = (int)($field['id'] ?? 0);
        $value = trim((string)($field['field_value'] ?? ''));
        if ($value !== '') {
            $urls[] = $value;
        }
    }

    if ($deleteIds) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $del = $pdo->prepare("DELETE FROM feedtools_supplier_product_fields WHERE id IN ({$placeholders})");
        $del->execute($deleteIds);
    }

    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    if ($urls) {
        supplier_products_delete_local_media_if_unreferenced($urls, $cfg);
    }

    return [
        'product' => $fresh,
        'deleted_video_cover_fields' => count($deleteIds),
        'video_cover_url' => '',
    ];
}

function supplier_products_normalize_picture_urls(array $pictures): array
{
    $out = [];
    foreach ($pictures as $picture) {
        $url = trim((string)$picture);
        if ($url === '') {
            continue;
        }
        $out[] = $url;
    }
    return array_values($out);
}

function supplier_products_import_preserved_picture_kind(string $url, array $cfg = []): string
{
    $relative = supplier_products_local_image_relative_from_url($url, $cfg);
    if ($relative === '') {
        return '';
    }
    $relative = str_replace('\\', '/', $relative);
    if (str_contains($relative, '/gpt_cover/')) {
        return 'cover';
    }
    if (str_contains($relative, '/product_specs_photo/')) {
        return 'specs';
    }
    return '';
}

function supplier_products_import_merge_pictures(array $currentPictures, array $sourcePictures, string $photosMode, array $cfg = []): array
{
    $currentPictures = supplier_products_normalize_picture_urls($currentPictures);
    $sourcePictures = supplier_products_normalize_picture_urls($sourcePictures);

    if ($photosMode === 'replace_all') {
        return $sourcePictures;
    }

    if ($photosMode === 'replace_keep_generated') {
        $covers = [];
        $specs = [];
        foreach ($currentPictures as $picture) {
            $kind = supplier_products_import_preserved_picture_kind($picture, $cfg);
            if ($kind === 'cover') {
                $covers[] = $picture;
            } elseif ($kind === 'specs') {
                $specs[] = $picture;
            }
        }

        $out = [];
        $seen = [];
        $append = static function (array $pictures) use (&$out, &$seen): void {
            foreach ($pictures as $picture) {
                $picture = trim((string)$picture);
                if ($picture === '' || isset($seen[$picture])) {
                    continue;
                }
                $seen[$picture] = true;
                $out[] = $picture;
            }
        };
        $append($covers);
        $append($sourcePictures);
        $append($specs);
        return $out;
    }

    $seenPictures = array_fill_keys($currentPictures, true);
    $newPictures = $currentPictures;
    foreach ($sourcePictures as $picture) {
        if (!isset($seenPictures[$picture])) {
            $seenPictures[$picture] = true;
            $newPictures[] = $picture;
        }
    }
    return $newPictures;
}

function supplier_products_picture_multiset(array $pictures): array
{
    $set = [];
    foreach (supplier_products_normalize_picture_urls($pictures) as $picture) {
        $set[$picture] = (int)($set[$picture] ?? 0) + 1;
    }
    ksort($set);
    return $set;
}

function supplier_products_update_pictures(int $productId, array $pictures, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT id, supplier_id FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $oldPictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($product['pictures_json'] ?? null));
    $pictures = supplier_products_normalize_picture_urls($pictures);
    $upd = $pdo->prepare("UPDATE feedtools_supplier_products SET pictures_json = ? WHERE id = ?");
    $upd->execute([
        json_encode($pictures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $productId,
    ]);

    $fresh = supplier_products_sync_product_summary_from_db($productId, $cfg);
    supplier_products_update_dataset_row_from_db((int)($product['supplier_id'] ?? 0), $cfg);
    $newSet = array_fill_keys($pictures, true);
    $removedPictures = [];
    foreach ($oldPictures as $oldPicture) {
        if (!isset($newSet[$oldPicture])) {
            $removedPictures[] = $oldPicture;
        }
    }
    if ($removedPictures) {
        supplier_products_delete_local_images_if_unreferenced($removedPictures, $cfg, $productId);
    }

    return [
        'product' => $fresh,
        'pictures' => $pictures,
    ];
}

function supplier_products_set_picture_order(int $productId, array $pictures, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $st = db()->prepare("SELECT pictures_json FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Товар не найден.');
    }
    $existing = supplier_products_decode_json_array($row['pictures_json'] ?? null);
    $pictures = supplier_products_normalize_picture_urls($pictures);
    if (supplier_products_picture_multiset($existing) !== supplier_products_picture_multiset($pictures)) {
        throw new RuntimeException('Список фотографий изменился. Обнови страницу и попробуй ещё раз.');
    }
    return supplier_products_update_pictures($productId, $pictures, $cfg);
}

function supplier_products_delete_picture(int $productId, int $index, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $st = db()->prepare("SELECT pictures_json FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Товар не найден.');
    }
    $pictures = supplier_products_decode_json_array($row['pictures_json'] ?? null);
    $pictures = supplier_products_normalize_picture_urls($pictures);
    if (!isset($pictures[$index])) {
        throw new RuntimeException('Фотография не найдена.');
    }
    array_splice($pictures, $index, 1);
    return supplier_products_update_pictures($productId, $pictures, $cfg);
}

function supplier_products_add_picture_url(int $productId, string $url, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('Ссылка на фото не заполнена.');
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) && !str_starts_with($url, '/supplier_product_image.php?')) {
        throw new RuntimeException('Фото можно добавить только по ссылке http/https.');
    }

    $st = db()->prepare("SELECT pictures_json FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Товар не найден.');
    }
    $pictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($row['pictures_json'] ?? null));
    $pictures[] = $url;
    return supplier_products_update_pictures($productId, $pictures, $cfg);
}

function supplier_products_upload_picture_file(int $productId, array $file, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    if ($productId <= 0) {
        throw new RuntimeException('Товар не найден.');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить файл фотографии.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Временный файл фотографии не найден.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        throw new RuntimeException('Файл фотографии должен быть не больше 20 МБ.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($tmp) ?: '');
    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extByMime[$mime])) {
        throw new RuntimeException('Поддерживаются только JPG, PNG, WebP и GIF.');
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT supplier_id, pictures_json FROM feedtools_supplier_products WHERE id = ? LIMIT 1");
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        throw new RuntimeException('Товар не найден.');
    }

    $supplierId = (int)($product['supplier_id'] ?? 0);
    $month = date('Ym');
    $relativeDir = 'supplier_' . $supplierId . '/' . $month;
    $baseDir = supplier_products_image_storage_dir($cfg);
    $targetDir = supplier_products_ensure_writable_dir($baseDir . '/' . $relativeDir, 'Не удалось создать папку для фотографии.');

    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extByMime[$mime];
    $targetPath = $targetDir . '/' . $fileName;
    if (!move_uploaded_file($tmp, $targetPath)) {
        if (!@rename($tmp, $targetPath)) {
            throw new RuntimeException('Не удалось сохранить фотографию.');
        }
    }
    @chmod($targetPath, 0664);

    $relativePath = $relativeDir . '/' . $fileName;
    $url = supplier_products_publish_stored_image($targetPath, $relativePath, $cfg, true);
    $pictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($product['pictures_json'] ?? null));
    $pictures[] = $url;
    return supplier_products_update_pictures($productId, $pictures, $cfg) + ['url' => $url];
}

function supplier_products_refresh_from_source(int $supplierId, array $cfg = []): array
{
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $feedUrl = trim((string)($supplier['feed_url'] ?? ''));
    if ($feedUrl === '') {
        throw new RuntimeException('У поставщика не заполнена ссылка на источник данных.');
    }

    if (!function_exists('ozon_price_feed_fetch_remote_xml')) {
        require_once __DIR__ . '/ozon_price_tool.php';
    }
    $download = ozon_price_feed_fetch_remote_xml($feedUrl, (int)($cfg['limits']['max_upload_bytes'] ?? (200 * 1024 * 1024)));
    try {
        return supplier_products_replace_catalog_from_feed_path($supplierId, (string)$download['path'], $cfg, (string)($download['final_url'] ?? $feedUrl));
    } finally {
        if (isset($download['path'])) {
            @unlink((string)$download['path']);
        }
    }
}
