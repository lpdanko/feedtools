#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/op_registry.php';

function cli_fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

$args = [
    'op_type' => '',
    'params_json' => '{}',
    'created_by' => 'cron',
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--op_type=(.+)$/', $arg, $m)) {
        $args['op_type'] = trim((string)$m[1]);
        continue;
    }
    if (preg_match('/^--params_json=(.+)$/', $arg, $m)) {
        $args['params_json'] = (string)$m[1];
        continue;
    }
    if (preg_match('/^--created_by=(.+)$/', $arg, $m)) {
        $args['created_by'] = trim((string)$m[1]) ?: 'cron';
        continue;
    }
}

$opType = $args['op_type'];
if ($opType === '') {
    cli_fail('Usage: enqueue_global_op.php --op_type=ozon_sync_actions [--params_json=\'{"limit":"100"}\'] [--created_by=cron]');
}

$params = json_decode((string)$args['params_json'], true);
if (!is_array($params)) {
    cli_fail('params_json must be a valid JSON object.');
}

$registry = op_registry();
if (!isset($registry[$opType])) {
    cli_fail("Unknown op_type: {$opType}");
}
if (empty($registry[$opType]['global_op'])) {
    cli_fail("op_type {$opType} is not a global operation.");
}

$datasetId = feedtools_global_ops_dataset_id();
$existing = ops_find_recent_duplicate($datasetId, $opType, $params, 3600);
if ($existing) {
    $id = (int)($existing['id'] ?? 0);
    echo json_encode([
        'status' => 'duplicate',
        'op_id' => $id,
        'message' => 'Recent duplicate operation already exists.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$opId = ops_create($datasetId, $opType, $params, (string)$args['created_by']);
ops_append_log_tail($opId, "Queued by CLI automation.\n", 200000);

echo json_encode([
    'status' => 'queued',
    'op_id' => $opId,
    'op_type' => $opType,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
