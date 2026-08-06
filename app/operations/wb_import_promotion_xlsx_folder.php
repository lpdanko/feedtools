<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../wb_promotions.php';

function op_wb_import_promotion_xlsx_folder(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Missing vendor/autoload.php. Run: composer install');
    }
    require_once $autoload;

    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для автоимпорта XLSX автоакций WB нужно передать connection_id.');
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Автоимпорт XLSX автоакций доступен только для подключения Wildberries.');
    }

    $inboxDir = trim((string)($params['inbox_dir'] ?? ''));
    $maxFiles = max(0, (int)($params['max_files'] ?? 0));
    $minAgeSec = max(0, (int)($params['min_age_sec'] ?? 10));
    $moveProcessed = !array_key_exists('move_processed', $params) || in_array(strtolower((string)$params['move_processed']), ['1', 'true', 'yes', 'on'], true);
    $moveFailed = !array_key_exists('move_failed', $params) || in_array(strtolower((string)$params['move_failed']), ['1', 'true', 'yes', 'on'], true);

    $resolvedInbox = wb_promotions_resolve_import_dir($inboxDir, $connectionId);
    $log("wb_import_promotion_xlsx_folder: connection_id={$connectionId}\n");
    $log("inbox_dir={$resolvedInbox}\n");
    $log("max_files={$maxFiles}, min_age_sec={$minAgeSec}, move_processed=" . ($moveProcessed ? '1' : '0') . ", move_failed=" . ($moveFailed ? '1' : '0') . "\n");

    ops_update_progress($opId, 0, 1, 'scan', 'Сканируем папку XLSX автоакций WB...');
    $summary = wb_promotions_import_xlsx_folder($connectionId, $resolvedInbox, $cfg, [
        'max_files' => $maxFiles,
        'min_age_sec' => $minAgeSec,
        'move_processed' => $moveProcessed,
        'move_failed' => $moveFailed,
    ]);

    $total = max(1, (int)($summary['files_seen'] ?? 0));
    ops_update_progress($opId, $total, $total, 'done', 'Автоимпорт XLSX автоакций WB завершён.');

    foreach ((array)($summary['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $status = (string)($item['status'] ?? '');
        $filename = (string)($item['filename'] ?? '');
        $message = (string)($item['message'] ?? '');
        $promotionId = (int)($item['promotion_id'] ?? 0);
        $products = (int)($item['products_stored'] ?? 0);
        if ($status === 'imported') {
            $log("imported: {$filename}, promotion_id={$promotionId}, products={$products}\n");
        } elseif ($status === 'skipped_duplicate') {
            $log("duplicate: {$filename}, promotion_id={$promotionId}, products={$products}\n");
        } elseif ($message !== '') {
            $log("{$status}: {$filename}: {$message}\n");
        } else {
            $log("{$status}: {$filename}\n");
        }
    }

    $log(
        "done: seen={$summary['files_seen']}, imported={$summary['imported']}, duplicate={$summary['skipped_duplicate']}, recent={$summary['skipped_recent']}, failed={$summary['failed']}, products={$summary['products_stored']}\n"
    );

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'wb_promotion_xlsx_folder_imported' => true,
            'inbox_dir' => (string)($summary['inbox_dir'] ?? $resolvedInbox),
        ],
    ];
}
