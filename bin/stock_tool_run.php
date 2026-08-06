<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/stock_tool.php';

stock_tool_module_bootstrap($cfg);

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

$connectionId = (int)($args['connection_id'] ?? 0);
$actor = trim((string)($args['actor'] ?? 'stock-tool-cli'));

try {
    $connectionIds = stock_tool_connection_ids_for_cli($cfg, $connectionId > 0 ? $connectionId : null);
    if (!$connectionIds) {
        fwrite(STDOUT, "No active stock pois connections.\n");
        exit(0);
    }

    $summary = [];
    foreach ($connectionIds as $id) {
        $runId = stock_tool_run_connection((int)$id, $actor !== '' ? $actor : null, $cfg);
        $run = stock_tool_run_get($runId, $cfg);
        $summary[] = [
            'connection_id' => (int)$id,
            'run_id' => $runId,
            'status' => (string)($run['status'] ?? ''),
            'totals' => is_array($run['totals'] ?? null) ? $run['totals'] : [],
        ];
    }

    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
