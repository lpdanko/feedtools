#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/suppliers.php';
require_once __DIR__ . '/../app/supplier_products_import.php';

$cfg = require __DIR__ . '/../app/config.php';

function mm_sync_arg_value(array $args, string $name, ?string $default = null): ?string
{
    $prefix = $name . '=';
    foreach ($args as $arg) {
        if ($arg === $name) {
            return '1';
        }
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function mm_sync_has_arg(array $args, string $name): bool
{
    return mm_sync_arg_value($args, $name) !== null;
}

function mm_sync_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/master_mobile_sync_supplier.php [options]\n\n";
    echo "Options:\n";
    echo "  --feed=PATH           Full built feed. Default: storage/master_mobile/master_mobile_info.xml\n";
    echo "  --supplier-id=ID      Supplier id. If omitted, supplier-code is used.\n";
    echo "  --supplier-code=CODE  Supplier code. Default: 24\n";
    echo "  --zero-missing        Set stock=0 for DB products absent from the feed.\n";
    echo "  --source-url=TEXT     Source label stored in import metadata.\n";
}

function mm_sync_find_supplier(?int $supplierId, string $supplierCode, array $cfg): array
{
    suppliers_table_ensure($cfg);
    if ($supplierId !== null && $supplierId > 0) {
        $supplier = suppliers_get($supplierId, $cfg);
        if (is_array($supplier)) {
            return $supplier;
        }
        throw new RuntimeException('Supplier not found: id=' . $supplierId);
    }

    $st = db()->prepare("SELECT * FROM feedtools_suppliers WHERE supplier_code = ? LIMIT 1");
    $st->execute([$supplierCode]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($supplier)) {
        return array_replace(suppliers_default(), $supplier);
    }

    $st = db()->prepare("SELECT * FROM feedtools_suppliers WHERE name LIKE ? LIMIT 1");
    $st->execute(['%Master%Mobile%']);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($supplier)) {
        return array_replace(suppliers_default(), $supplier);
    }

    throw new RuntimeException('Master Mobile supplier not found.');
}

$args = array_slice($argv, 1);
if (mm_sync_has_arg($args, '--help') || mm_sync_has_arg($args, '-h')) {
    mm_sync_usage();
    exit(0);
}

try {
    $root = dirname(__DIR__);
    $feed = mm_sync_arg_value($args, '--feed', 'storage/master_mobile/master_mobile_info.xml') ?: 'storage/master_mobile/master_mobile_info.xml';
    $feedPath = str_starts_with($feed, '/') ? $feed : ($root . '/' . ltrim($feed, '/'));
    if (!is_file($feedPath)) {
        throw new RuntimeException('Feed not found: ' . $feedPath);
    }

    $supplierCode = suppliers_normalize_code(mm_sync_arg_value($args, '--supplier-code', '24') ?: '24');
    $supplierIdRaw = mm_sync_arg_value($args, '--supplier-id');
    $supplier = mm_sync_find_supplier($supplierIdRaw !== null ? (int)$supplierIdRaw : null, $supplierCode, $cfg);
    $supplierId = (int)($supplier['id'] ?? 0);
    $zeroMissing = mm_sync_has_arg($args, '--zero-missing');
    $sourceUrl = mm_sync_arg_value($args, '--source-url', $feedPath) ?: $feedPath;

    $sourceData = supplier_import_source_data_from_file($supplierId, $feedPath, basename($feedPath), $cfg);
    $stats = supplier_products_update_prices_stock_from_source(
        $supplierId,
        $sourceData,
        $zeroMissing,
        $cfg,
        $sourceUrl,
        [
            'progress' => static function (int $done, int $total, string $stage, string $message): void {
                if ($done === 0 || $done >= $total || ($done % 1000) === 0) {
                    fwrite(STDERR, "{$stage}: {$done}/{$total} {$message}\n");
                }
            },
        ]
    );
    $stats['supplier_id'] = $supplierId;
    $stats['supplier_code'] = (string)($supplier['supplier_code'] ?? '');
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
