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

$profileId = (int)($args['profile_id'] ?? 0);
$connectionId = (int)($args['connection_id'] ?? 0);

$log = static function (string $line): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $line);
};

try {
    $summary = stocks_tool_automation_run_due(
        $log,
        $profileId > 0 ? $profileId : null,
        $connectionId > 0 ? $connectionId : null,
        $cfg
    );
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
