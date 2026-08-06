<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/orders_sync.php';

orders_sync_module_bootstrap($cfg);

$args = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (!is_string($arg) || !str_starts_with($arg, '--')) {
        continue;
    }
    $eqPos = strpos($arg, '=');
    if ($eqPos === false) {
        $args[substr($arg, 2)] = '1';
        continue;
    }
    $args[substr($arg, 2, $eqPos - 2)] = substr($arg, $eqPos + 1);
}

$mode = trim((string)($args['mode'] ?? ''));
$profileId = (int)($args['profile_id'] ?? 0);
$runId = (int)($args['run_id'] ?? 0);
$actor = trim((string)($args['actor'] ?? ''));

if ($mode === '' || $profileId <= 0 || $runId <= 0) {
    fwrite(STDERR, "Usage: php orders_sync_run.php --mode=<ozon_sync|marketplace_sync|moysklad_export|moysklad_create_orders|moysklad_update_statuses> --profile_id=<id> --run_id=<id> [--actor=<user>]\n");
    exit(1);
}

try {
    if ($mode === 'ozon_sync' || $mode === 'marketplace_sync') {
        orders_sync_manual_sync_ozon_profile($profileId, $actor !== '' ? $actor : null, $cfg, $runId);
    } elseif ($mode === 'moysklad_export') {
        orders_sync_manual_export_moysklad_profile($profileId, $actor !== '' ? $actor : null, $cfg, $runId);
    } elseif ($mode === 'moysklad_create_orders') {
        orders_sync_manual_create_moysklad_profile($profileId, $actor !== '' ? $actor : null, $cfg, $runId);
    } elseif ($mode === 'moysklad_update_statuses') {
        orders_sync_manual_update_statuses_moysklad_profile($profileId, $actor !== '' ? $actor : null, $cfg, $runId);
    } else {
        throw new RuntimeException('Unknown mode: ' . $mode);
    }
    exit(0);
} catch (Throwable $e) {
    if ($runId > 0) {
        orders_sync_run_append($runId, "Фатальная ошибка фонового runner: " . $e->getMessage() . "\n");
        $existing = orders_sync_run_get($runId, $cfg);
        $summary = is_array($existing['summary'] ?? null) ? $existing['summary'] : [
            'kind' => $mode,
            'run_id' => $runId,
        ];
        $summary['fatal_error'] = $e->getMessage();
        orders_sync_run_finish($runId, 'error', $summary, $e->getMessage(), $cfg);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
