#!/usr/bin/env php
<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/supplier_content_progress.php';

function content_snapshot_cli_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $args[substr($arg, 2)] = '1';
        } else {
            $args[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    }
    return $args;
}

function content_snapshot_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

$args = content_snapshot_cli_args($_SERVER['argv'] ?? []);
$supplierIdFilter = max(0, (int)($args['supplier_id'] ?? 0));
$includeInactive = isset($args['include_inactive']) && (string)$args['include_inactive'] !== '0';
$force = isset($args['force']) && (string)$args['force'] !== '0';
$limit = max(0, (int)($args['limit'] ?? 0));
$sleepMs = max(0, min(5000, (int)($args['sleep_ms'] ?? 0)));

try {
    supplier_content_progress_tables_ensure($cfg);
    $suppliers = suppliers_list($includeInactive, $cfg);
    $summary = [
        'started_at' => date('Y-m-d H:i:s'),
        'force' => $force,
        'include_inactive' => $includeInactive,
        'suppliers_seen' => 0,
        'snapshots_created' => 0,
        'skipped_today' => 0,
        'errors' => [],
    ];

    foreach ($suppliers as $supplier) {
        $supplierId = (int)($supplier['id'] ?? 0);
        if ($supplierId <= 0) {
            continue;
        }
        if ($supplierIdFilter > 0 && $supplierId !== $supplierIdFilter) {
            continue;
        }
        if ($limit > 0 && $summary['suppliers_seen'] >= $limit) {
            break;
        }
        $summary['suppliers_seen']++;
        $label = trim((string)($supplier['name'] ?? '')) ?: ('#' . $supplierId);
        try {
            if (!$force && supplier_content_progress_snapshot_today_exists($supplierId)) {
                $summary['skipped_today']++;
                content_snapshot_log("skip supplier={$supplierId} {$label}: snapshot already exists");
                continue;
            }
            $snapshot = supplier_content_progress_capture_snapshot($supplierId, $cfg);
            $summary['snapshots_created']++;
            content_snapshot_log(
                'captured supplier=' . $supplierId
                . ' ' . $label
                . ' score=' . (string)($snapshot['content_progress_score'] ?? 0)
                . ' target=' . (string)($snapshot['target_products_total'] ?? 0)
                . ' out_of_stock=' . (string)($snapshot['out_of_stock_total'] ?? 0)
            );
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        } catch (Throwable $supplierError) {
            $summary['errors'][] = [
                'supplier_id' => $supplierId,
                'supplier_name' => $label,
                'error' => $supplierError->getMessage(),
            ];
            content_snapshot_log("error supplier={$supplierId} {$label}: " . $supplierError->getMessage());
        }
    }

    $summary['finished_at'] = date('Y-m-d H:i:s');
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit($summary['errors'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
