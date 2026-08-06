#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/ozon_price_tool.php';

try {
    ozon_price_automation_table_ensure();
    $connections = ozon_price_connection_list([], null);
    $summaries = [];
    foreach ($connections as $connection) {
        if (empty($connection['is_active'])) {
            continue;
        }
        if (!price_tool_connection_supports($connection, 'automations')) {
            continue;
        }
        $connectionId = (int)($connection['id'] ?? 0);
        if ($connectionId <= 0) {
            continue;
        }
        $buffer = '';
        $log = static function (string $message) use (&$buffer): void {
            $buffer .= '[' . date('Y-m-d H:i:s') . '] ' . $message;
        };
        try {
            $summary = ozon_price_automation_run_due($log, $connectionId);
            if (ozon_price_automation_summary_is_significant($summary)) {
                $runId = ozon_price_automation_run_log_create('cron', $connectionId);
                if ($buffer !== '') {
                    fwrite(STDOUT, $buffer);
                    ozon_price_automation_run_log_append($runId, $buffer);
                }
                ozon_price_automation_run_log_finish($runId, 'success', $summary);
            }
            $summaries[] = $summary;
        } catch (Throwable $connectionError) {
            $runId = ozon_price_automation_run_log_create('cron', $connectionId);
            if ($buffer !== '') {
                fwrite(STDOUT, $buffer);
                ozon_price_automation_run_log_append($runId, $buffer);
            }
            $errorLine = '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $connectionError->getMessage() . PHP_EOL;
            fwrite(STDERR, $errorLine);
            ozon_price_automation_run_log_append($runId, $errorLine);
            ozon_price_automation_run_log_finish($runId, 'error', [
                'connection_id' => $connectionId,
                'error' => $connectionError->getMessage(),
            ]);
            $summaries[] = [
                'connection_id' => $connectionId,
                'error' => $connectionError->getMessage(),
            ];
        }
    }
    fwrite(STDOUT, json_encode($summaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    $errorLine = '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL;
    fwrite(STDERR, $errorLine);
    exit(1);
}
