<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/paths.php';

function master_mobile_supplier_key(): string
{
    return 'master_mobile';
}

function master_mobile_root_dir(): string
{
    return dirname(__DIR__);
}

function master_mobile_default_settings(): array
{
    $pricePath = master_mobile_current_pricelist_path();
    $defaultWorkers = (int)(getenv('MASTER_MOBILE_DEFAULT_WORKERS') ?: 4);
    $defaultWorkers = max(1, min(8, $defaultWorkers));
    return [
        'supplier_key' => master_mobile_supplier_key(),
        'supplier_name' => 'Master Mobile',
        'supplier_code' => '24',
        'source_url' => 'https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml',
        'public_feed_url' => 'https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml',
        'snapshot_path' => 'storage/master_mobile/price_stock_snapshot.yml',
        'feed_path' => 'storage/master_mobile/master_mobile_info.xml',
        'source_fallback_feed_path' => 'storage/master_mobile/master_mobile_info.xml',
        'image_replacements_path' => 'storage/master_mobile/clean_images/feed_picture_replacements_new_articles.csv',
        'purchase_prices_path' => $pricePath,
        'store_id' => '2',
        'store_name' => 'ТК «Савеловский» Мобильный',
        'workers' => $defaultWorkers,
        'min_coverage' => '0.95',
    ];
}

function master_mobile_abs_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return master_mobile_root_dir();
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }
    return master_mobile_root_dir() . '/' . ltrim($path, '/');
}

function master_mobile_rel_path(string $absPath): string
{
    $root = rtrim(master_mobile_root_dir(), '/') . '/';
    if (str_starts_with($absPath, $root)) {
        return substr($absPath, strlen($root));
    }
    return $absPath;
}

function master_mobile_current_pricelist_path(): string
{
    $dir = master_mobile_root_dir() . '/storage/master_mobile/pricelists';
    $candidates = [
        $dir . '/master_mobile_prices_current.xlsx',
        $dir . '/master_mobile_prices_current.csv',
    ];
    $existing = array_values(array_filter($candidates, static fn(string $path): bool => is_file($path)));
    if (!$existing) {
        return 'storage/master_mobile/pricelists/master_mobile_prices_current.xlsx';
    }
    usort($existing, static fn(string $a, string $b): int => ((int)filemtime($b)) <=> ((int)filemtime($a)));
    return master_mobile_rel_path($existing[0]);
}

function master_mobile_file_info(string $path): array
{
    $abs = master_mobile_abs_path($path);
    $exists = is_file($abs);
    return [
        'path' => $path,
        'abs_path' => $abs,
        'exists' => $exists,
        'bytes' => $exists ? (int)filesize($abs) : 0,
        'mtime' => $exists ? (int)filemtime($abs) : 0,
    ];
}

function master_mobile_format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 Б';
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string)(int)$value : number_format($value, 1, '.', ' ')) . ' ' . $units[$unit];
}

function master_mobile_format_dt(?int $ts): string
{
    if (!$ts) return 'нет файла';
    return date('Y-m-d H:i', $ts);
}

function master_mobile_bool($value, bool $default = false): bool
{
    if ($value === null || $value === '') return $default;
    if (is_bool($value)) return $value;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'да'], true);
}

function master_mobile_task_options(): array
{
    return [
        'parse_stock' => 'Обновить остатки и фид',
        'build_feed' => 'Получить источник и пересобрать фид',
        'parse_and_build' => 'Обновить остатки и пересобрать фид',
    ];
}

function master_mobile_price_mode_options(): array
{
    return [
        'pricelist' => 'Закупочные цены из прайса',
        'parsed' => 'Цены, полученные парсингом',
    ];
}

function master_mobile_task_label(string $task): string
{
    $options = master_mobile_task_options();
    return $options[$task] ?? $task;
}

function master_mobile_price_mode_label(string $mode): string
{
    $options = master_mobile_price_mode_options();
    return $options[$mode] ?? $mode;
}

function master_mobile_normalize_task(string $task): string
{
    $task = trim($task);
    return array_key_exists($task, master_mobile_task_options()) ? $task : 'parse_and_build';
}

function master_mobile_normalize_price_mode(string $mode): string
{
    $mode = trim($mode);
    return array_key_exists($mode, master_mobile_price_mode_options()) ? $mode : 'pricelist';
}

function master_mobile_automation_table_ensure(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedtools_supplier_feed_automations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_key VARCHAR(64) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            task_key VARCHAR(64) NOT NULL DEFAULT 'parse_and_build',
            frequency_minutes INT UNSIGNED NOT NULL DEFAULT 120,
            price_mode VARCHAR(32) NOT NULL DEFAULT 'pricelist',
            upload_feed TINYINT(1) NOT NULL DEFAULT 1,
            workers INT UNSIGNED NOT NULL DEFAULT 12,
            last_run_at DATETIME NULL,
            last_run_op_id BIGINT UNSIGNED NULL,
            created_by VARCHAR(191) NULL,
            updated_by VARCHAR(191) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_supplier_key (supplier_key),
            KEY idx_enabled_supplier (enabled, supplier_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function master_mobile_automation_default(): array
{
    return [
        'id' => 0,
        'supplier_key' => master_mobile_supplier_key(),
        'enabled' => 0,
        'task_key' => 'parse_and_build',
        'frequency_minutes' => 120,
        'price_mode' => 'pricelist',
        'upload_feed' => 1,
        'workers' => 12,
        'last_run_at' => null,
        'last_run_op_id' => null,
    ];
}

function master_mobile_automation_get(string $supplierKey = ''): array
{
    master_mobile_automation_table_ensure();
    $supplierKey = $supplierKey !== '' ? $supplierKey : master_mobile_supplier_key();
    $st = db()->prepare("SELECT * FROM feedtools_supplier_feed_automations WHERE supplier_key = ? LIMIT 1");
    $st->execute([$supplierKey]);
    $row = $st->fetch();
    if (!$row) {
        return master_mobile_automation_default();
    }
    $row['task_key'] = master_mobile_normalize_task((string)($row['task_key'] ?? ''));
    $row['price_mode'] = master_mobile_normalize_price_mode((string)($row['price_mode'] ?? ''));
    $row['frequency_minutes'] = max(15, min(10080, (int)($row['frequency_minutes'] ?? 120)));
    $row['workers'] = max(1, min(32, (int)($row['workers'] ?? 12)));
    return $row;
}

function master_mobile_automation_save(array $input, ?string $actor = null): int
{
    master_mobile_automation_table_ensure();
    $supplierKey = master_mobile_supplier_key();
    $enabled = master_mobile_bool($input['enabled'] ?? null, false) ? 1 : 0;
    $task = master_mobile_normalize_task((string)($input['task_key'] ?? 'parse_and_build'));
    $priceMode = master_mobile_normalize_price_mode((string)($input['price_mode'] ?? 'pricelist'));
    $frequency = max(15, min(10080, (int)($input['frequency_minutes'] ?? 120)));
    $workers = max(1, min(32, (int)($input['workers'] ?? 12)));
    $uploadFeed = master_mobile_bool($input['upload_feed'] ?? null, true) ? 1 : 0;

    $existing = master_mobile_automation_get($supplierKey);
    if ((int)($existing['id'] ?? 0) > 0) {
        db()->prepare("
            UPDATE feedtools_supplier_feed_automations
            SET enabled = ?, task_key = ?, frequency_minutes = ?, price_mode = ?, upload_feed = ?, workers = ?, updated_by = ?
            WHERE id = ?
        ")->execute([$enabled, $task, $frequency, $priceMode, $uploadFeed, $workers, $actor, (int)$existing['id']]);
        return (int)$existing['id'];
    }

    db()->prepare("
        INSERT INTO feedtools_supplier_feed_automations
          (supplier_key, enabled, task_key, frequency_minutes, price_mode, upload_feed, workers, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$supplierKey, $enabled, $task, $frequency, $priceMode, $uploadFeed, $workers, $actor, $actor]);
    return (int)db()->lastInsertId();
}

function master_mobile_active_ops(int $limit = 5): array
{
    ops_table_ensure();
    $st = db()->prepare("
        SELECT *
        FROM feedtools_operations
        WHERE op_type = 'master_mobile_feed'
          AND status IN ('queued', 'running')
        ORDER BY id DESC
        LIMIT ?
    ");
    $st->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll() ?: [];
}

function master_mobile_recent_ops(int $limit = 12): array
{
    ops_table_ensure();
    $st = db()->prepare("
        SELECT *
        FROM feedtools_operations
        WHERE op_type = 'master_mobile_feed'
        ORDER BY id DESC
        LIMIT ?
    ");
    $st->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll() ?: [];
}

function master_mobile_default_op_params(array $input = []): array
{
    $settings = master_mobile_default_settings();
    $task = master_mobile_normalize_task((string)($input['task_key'] ?? $input['task'] ?? 'parse_and_build'));
    $priceMode = master_mobile_normalize_price_mode((string)($input['price_mode'] ?? 'pricelist'));
    $workers = max(1, min(32, (int)($input['workers'] ?? $settings['workers'])));
    $uploadFeed = master_mobile_bool($input['upload_feed'] ?? null, true) ? '1' : '0';
    $limit = max(0, (int)($input['limit'] ?? 0));

    return [
        'supplier_key' => master_mobile_supplier_key(),
        'task_key' => $task,
        'price_mode' => $priceMode,
        'workers' => (string)$workers,
        'upload_feed' => $uploadFeed,
        'limit' => (string)$limit,
        'source_url' => (string)$settings['source_url'],
        'source_fallback_feed_path' => (string)$settings['source_fallback_feed_path'],
        'snapshot_path' => (string)$settings['snapshot_path'],
        'feed_path' => (string)$settings['feed_path'],
        'image_replacements_path' => (string)$settings['image_replacements_path'],
        'purchase_prices_path' => (string)$settings['purchase_prices_path'],
        'store_id' => (string)$settings['store_id'],
        'min_coverage' => (string)$settings['min_coverage'],
    ];
}

function master_mobile_spawn_worker_if_needed(array $cfg, int $datasetId, int $opId): void
{
    if (empty($cfg['worker']['auto_spawn'])) {
        return;
    }

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);
    $spawnLogAbs = $outDir . '/spawn.log';
    @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);

    $php = $cfg['worker']['php_bin'] ?? PHP_BINARY;
    $script = $cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php');
    $cmd = escapeshellcmd((string)$php) . ' ' . escapeshellarg((string)$script)
        . ' --op_id=' . (int)$opId
        . ' > ' . escapeshellarg($spawnLogAbs) . ' 2>&1 &';
    @exec($cmd);
    ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
}

function master_mobile_enqueue_task(array $cfg, array $params, ?string $actor = null): int
{
    $params = master_mobile_default_op_params($params);
    if ($actor !== null && trim($actor) !== '') {
        $params['_actor'] = trim($actor);
    }

    $datasetId = feedtools_global_ops_dataset_id();
    $opId = ops_create($datasetId, 'master_mobile_feed', $params, $actor);
    ops_append_log_tail($opId, "Queued Master Mobile task: " . master_mobile_task_label((string)$params['task_key']) . "\n", 200000);
    ops_update_progress($opId, 0, 1000, 'queued', 'Ожидает запуска');
    master_mobile_spawn_worker_if_needed($cfg, $datasetId, $opId);
    return $opId;
}

function master_mobile_has_active_task(): bool
{
    return count(master_mobile_active_ops(1)) > 0;
}

function master_mobile_automation_is_due(array $automation): bool
{
    if (empty($automation['enabled'])) {
        return false;
    }
    $lastRunAt = trim((string)($automation['last_run_at'] ?? ''));
    if ($lastRunAt === '') {
        return true;
    }
    $lastTs = strtotime($lastRunAt);
    if (!$lastTs) {
        return true;
    }
    $frequency = max(15, min(10080, (int)($automation['frequency_minutes'] ?? 120)));
    return (time() - $lastTs) >= ($frequency * 60);
}

function master_mobile_automation_run_due(callable $log, array $cfg = []): array
{
    master_mobile_automation_table_ensure();
    $cfg = $cfg ?: require __DIR__ . '/config.php';
    $automation = master_mobile_automation_get();
    $summary = [
        'supplier_key' => master_mobile_supplier_key(),
        'checked' => 1,
        'queued' => 0,
        'skipped' => [],
    ];

    if (empty($automation['enabled'])) {
        $summary['skipped'][] = 'disabled';
        $log("Master Mobile automation disabled\n");
        return $summary;
    }
    if (!master_mobile_automation_is_due($automation)) {
        $summary['skipped'][] = 'not_due';
        $log("Master Mobile automation is not due yet\n");
        return $summary;
    }
    if (master_mobile_has_active_task()) {
        $summary['skipped'][] = 'active_task';
        $log("Master Mobile automation waits for active task\n");
        return $summary;
    }

    $params = [
        'task_key' => (string)$automation['task_key'],
        'price_mode' => (string)$automation['price_mode'],
        'upload_feed' => !empty($automation['upload_feed']) ? '1' : '0',
        'workers' => (string)max(1, (int)$automation['workers']),
    ];
    $opId = master_mobile_enqueue_task($cfg, $params, 'automation');
    db()->prepare("
        UPDATE feedtools_supplier_feed_automations
        SET last_run_at = NOW(), last_run_op_id = ?
        WHERE id = ?
    ")->execute([$opId, (int)$automation['id']]);
    $summary['queued'] = 1;
    $summary['op_id'] = $opId;
    $log("Master Mobile automation queued op #{$opId}\n");
    return $summary;
}

function master_mobile_save_uploaded_pricelist(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Файл прайса не загружен.');
    }

    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        throw new RuntimeException('Прайс должен быть в формате XLSX или CSV.');
    }

    $dir = master_mobile_root_dir() . '/storage/master_mobile/pricelists';
    ensure_dir($dir);
    $target = $dir . '/master_mobile_prices_current.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Не удалось сохранить прайс.');
    }
    @chmod($target, 0664);
    return master_mobile_rel_path($target);
}
