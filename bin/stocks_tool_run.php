<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/stocks_tool.php';

stocks_tool_module_bootstrap($cfg);

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

$runId = (int)($args['run_id'] ?? 0);
$actor = trim((string)($args['actor'] ?? ''));

if ($runId <= 0) {
    fwrite(STDERR, "Usage: php stocks_tool_run.php --run_id=<id> [--actor=<user>]\n");
    exit(1);
}

try {
    stocks_tool_manual_run_execute($runId, $actor !== '' ? $actor : null, $cfg);
    exit(0);
} catch (Throwable $e) {
    stocks_tool_run_append($runId, "Фатальная ошибка runner: " . $e->getMessage() . "\n");
    $existing = stocks_tool_run_get($runId, $cfg);
    $summary = is_array($existing['summary'] ?? null) ? $existing['summary'] : [
        'kind' => (string)($existing['kind'] ?? 'manual_stock_sync'),
        'run_id' => $runId,
        'totals' => [],
    ];
    $summary['fatal_error'] = $e->getMessage();
    stocks_tool_run_finish($runId, 'error', $summary, $e->getMessage(), $cfg);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
