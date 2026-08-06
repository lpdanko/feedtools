#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg = require $root . '/app/config.php';
require_once $root . '/app/llm/OpenAIRequestLog.php';
require_once $root . '/app/ozon_price_tool.php';
require_once $root . '/app/orders_sync.php';
require_once $root . '/app/remote_image_cleanup.php';

function ft_cleanup_retention_loop(string $label, callable $callback, int $maxLoops = 100): int
{
    $total = 0;
    for ($i = 0; $i < $maxLoops; $i++) {
        $deleted = (int)$callback();
        $total += $deleted;
        if ($deleted <= 0) {
            break;
        }
    }
    echo $label . '=' . $total . PHP_EOL;
    return $total;
}

function ft_cleanup_old_output_files(string $dir, int $days, int $limit = 5000): int
{
    $dir = rtrim($dir, DIRECTORY_SEPARATOR);
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }
    $days = max(1, $days);
    $limit = max(1, min(50000, $limit));
    $threshold = time() - ($days * 86400);
    $deleted = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $path = $item->getPathname();
        if ($item->isFile()) {
            $name = $item->getFilename();
            if ($name === '.gitignore') {
                continue;
            }
            if ($item->getMTime() < $threshold && @unlink($path)) {
                $deleted++;
                if ($deleted >= $limit) {
                    break;
                }
            }
        } elseif ($item->isDir() && @count(scandir($path) ?: []) <= 2) {
            @rmdir($path);
        }
    }

    return $deleted;
}

function ft_cleanup_empty_dirs(string $dir): int
{
    $dir = rtrim($dir, DIRECTORY_SEPARATOR);
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }
    $removed = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item instanceof SplFileInfo && $item->isDir()) {
            $path = $item->getPathname();
            if (@count(scandir($path) ?: []) <= 2 && @rmdir($path)) {
                $removed++;
            }
        }
    }
    return $removed;
}

function ft_cleanup_remote_uploaded_local_files(array $cfg, int $days, int $limit = 50000): array
{
    $stats = [
        'deleted_files' => 0,
        'deleted_markers' => 0,
        'freed_bytes' => 0,
        'skipped' => 0,
    ];

    $remote = (array)($cfg['remote_images'] ?? []);
    if (empty($remote['enabled']) || trim((string)($remote['base_url'] ?? '')) === '') {
        return $stats;
    }

    $uploadsDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? ''), DIRECTORY_SEPARATOR);
    if ($uploadsDir === '') {
        return $stats;
    }
    $root = $uploadsDir . DIRECTORY_SEPARATOR . 'supplier_product_images';
    if (!is_dir($root)) {
        return $stats;
    }

    $days = max(1, $days);
    $limit = max(1, min(200000, $limit));
    $threshold = time() - ($days * 86400);
    $allowedUrlPrefix = rtrim(trim((string)$remote['base_url']), '/') . '/';
    $markerSuffix = '.remote-ok.json';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $markerPath = $item->getPathname();
        if (!str_ends_with($markerPath, $markerSuffix) || $item->getMTime() >= $threshold) {
            continue;
        }

        $localPath = substr($markerPath, 0, -strlen($markerSuffix));
        if (!is_file($localPath)) {
            if (@unlink($markerPath)) {
                $stats['deleted_markers']++;
            }
            continue;
        }

        $marker = json_decode((string)@file_get_contents($markerPath), true);
        $localSize = (int)@filesize($localPath);
        $localMtime = (int)@filemtime($localPath);
        $url = is_array($marker) ? (string)($marker['url'] ?? '') : '';
        $size = is_array($marker) ? (int)($marker['size'] ?? -1) : -1;
        $mtime = is_array($marker) ? (int)($marker['mtime'] ?? -1) : -1;

        if (
            $url === ''
            || !str_starts_with($url, $allowedUrlPrefix)
            || $size !== $localSize
            || $mtime !== $localMtime
        ) {
            $stats['skipped']++;
            continue;
        }

        if (@unlink($localPath)) {
            $stats['deleted_files']++;
            $stats['freed_bytes'] += $localSize;
            if (@unlink($markerPath)) {
                $stats['deleted_markers']++;
            }
        } else {
            $stats['skipped']++;
        }

        if ($stats['deleted_files'] >= $limit) {
            break;
        }
    }

    return $stats;
}

function ft_cleanup_wb_promotion_price_history(int $days, int $limit = 50000): int
{
    $days = max(1, $days);
    $limit = max(1, min(100000, $limit));

    if (!function_exists('db')) {
        return 0;
    }

    $pdo = db();
    $connectionIds = [];
    try {
        $rows = $pdo->query("
            SELECT DISTINCT connection_id
            FROM feedtools_wb_promotions
            WHERE connection_id > 0
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $value) {
            $connectionId = (int)$value;
            if ($connectionId > 0) {
                $connectionIds[$connectionId] = $connectionId;
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    if (!$connectionIds) {
        return 0;
    }

    $deletedTotal = 0;
    $sql = "
        DELETE FROM feedtools_wb_promotion_price_history
        WHERE connection_id = ?
          AND observed_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
        ORDER BY observed_at ASC
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($connectionIds as $connectionId) {
        $stmt->execute([$connectionId]);
        $deletedTotal += $stmt->rowCount();
        if ($deletedTotal >= $limit) {
            break;
        }
    }

    return $deletedTotal;
}

$llmDays = (int)($cfg['retention']['llm_request_days'] ?? 1);
$priceDays = (int)($cfg['retention']['price_push_days'] ?? 1);
$outputDays = (int)($cfg['retention']['operation_output_days'] ?? 7);
$remoteUploadedLocalDays = (int)($cfg['retention']['remote_uploaded_local_days'] ?? 1);
$wbPromotionPriceHistoryDays = (int)($cfg['retention']['wb_promotion_price_history_days'] ?? 14);
$orderDays = (int)($cfg['retention']['order_snapshot_history_days'] ?? 7);

echo 'retention: llm_days=' . max(1, $llmDays)
    . ' price_push_days=' . max(1, $priceDays)
    . ' operation_output_days=' . max(1, $outputDays)
    . ' remote_uploaded_local_days=' . max(1, $remoteUploadedLocalDays)
    . ' wb_promotion_price_history_days=' . max(1, $wbPromotionPriceHistoryDays)
    . ' order_snapshot_history_days=' . max(1, $orderDays)
    . PHP_EOL;

ft_cleanup_retention_loop(
    'llm_requests_deleted',
    static fn(): int => OpenAIRequestLog::cleanupOld(max(1, $llmDays), 5000)
);

ft_cleanup_retention_loop(
    'price_push_deleted',
    static fn(): int => ozon_price_push_log_cleanup_old($cfg, 5000)
);

ft_cleanup_retention_loop(
    'order_snapshot_history_deleted',
    static fn(): int => orders_sync_order_snapshot_history_cleanup_old($cfg, 5000)
);

$outputsDir = (string)($cfg['paths']['outputs_dir'] ?? '');
ft_cleanup_retention_loop(
    'operation_outputs_deleted',
    static fn(): int => ft_cleanup_old_output_files($outputsDir, max(1, $outputDays), 5000)
);
echo 'operation_output_empty_dirs_deleted=' . ft_cleanup_empty_dirs($outputsDir) . PHP_EOL;

$remoteUploadedStats = ft_cleanup_remote_uploaded_local_files($cfg, max(1, $remoteUploadedLocalDays));
echo 'remote_uploaded_local_files_deleted=' . $remoteUploadedStats['deleted_files'] . PHP_EOL;
echo 'remote_uploaded_local_markers_deleted=' . $remoteUploadedStats['deleted_markers'] . PHP_EOL;
echo 'remote_uploaded_local_freed_mb=' . round(((int)$remoteUploadedStats['freed_bytes']) / 1024 / 1024, 1) . PHP_EOL;
echo 'remote_uploaded_local_skipped=' . $remoteUploadedStats['skipped'] . PHP_EOL;
echo 'remote_uploaded_local_empty_dirs_deleted=' . ft_cleanup_empty_dirs(
    rtrim((string)($cfg['paths']['uploads_dir'] ?? ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'supplier_product_images'
) . PHP_EOL;

try {
    $remoteImageStats = ft_remote_image_cleanup_run($cfg, [
        'apply' => true,
        'min_age_days' => 7,
        'limit' => 5000,
    ]);
    echo 'remote_unreferenced_files_deleted=' . (int)$remoteImageStats['deleted_files'] . PHP_EOL;
    echo 'remote_unreferenced_freed_mb=' . round(((int)$remoteImageStats['freed_bytes']) / 1024 / 1024, 1) . PHP_EOL;
    echo 'remote_unreferenced_failed=' . (int)$remoteImageStats['failed_files'] . PHP_EOL;
} catch (Throwable $e) {
    echo 'remote_unreferenced_cleanup_error=' . $e->getMessage() . PHP_EOL;
}

ft_cleanup_retention_loop(
    'wb_promotion_price_history_deleted',
    static fn(): int => ft_cleanup_wb_promotion_price_history(max(1, $wbPromotionPriceHistoryDays), 50000)
);
