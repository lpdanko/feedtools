#!/usr/bin/env php
<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/master_mobile_admin.php';

$log = static function (string $line): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $line);
};

try {
    $summary = master_mobile_automation_run_due($log, $cfg);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
