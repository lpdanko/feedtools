<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/op_params.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/brand_audit_storage.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

function brand_logo_request_payload(): array
{
    $raw = (string)file_get_contents('php://input');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        return $decoded;
    }
    return $_POST;
}

function brand_logo_spawn_worker_detached(array $cfg, int $datasetId, int $opId): void
{
    if ($opId <= 0 || !function_exists('exec')) {
        return;
    }

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);
    $spawnLogAbs = $outDir . '/spawn.log';
    @file_put_contents($spawnLogAbs, "brand logo spawn init\n", FILE_APPEND);

    $php = trim((string)($cfg['worker']['php_bin'] ?? PHP_BINARY));
    if ($php === '') {
        $php = 'php';
    }
    $script = trim((string)($cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php')));
    if ($script === '') {
        $script = __DIR__ . '/../bin/worker.php';
    }

    $cmd = 'nohup ' . escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --op_id=' . (int)$opId
        . ' >> ' . escapeshellarg($spawnLogAbs)
        . ' 2>&1 < /dev/null & echo $!';

    $pidLines = [];
    $exitCode = 0;
    @exec($cmd, $pidLines, $exitCode);
    $pid = trim((string)($pidLines[0] ?? ''));

    try {
        ops_append_log_tail($opId, "spawnLogAbs: {$spawnLogAbs}\n", 200000);
        ops_append_log_tail($opId, "spawn_pid: " . ($pid !== '' ? $pid : 'unknown') . " exit_code={$exitCode}\n", 200000);
    } catch (Throwable $e) {
        // queued operation is enough; spawn log stays on disk
    }
}

try {
    $payload = brand_logo_request_payload();
    $datasetId = (int)($payload['dataset_id'] ?? 0);
    $brand = trim((string)($payload['brand'] ?? ''));
    $marketplaces = trim((string)($payload['marketplaces'] ?? 'all'));
    $sourceOpId = (int)($payload['source_op_id'] ?? 0);

    if ($datasetId <= 0) {
        throw new RuntimeException('Не указан датасет товаров поставщика.');
    }
    if ($brand === '') {
        throw new RuntimeException('Не указано название бренда.');
    }

    $stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ? LIMIT 1");
    $stmt->execute([$datasetId]);
    $ds = $stmt->fetch();
    if (!$ds || !supplier_products_is_dataset_row($ds)) {
        throw new RuntimeException('Датасет товаров поставщика не найден.');
    }

    $registry = op_registry();
    $opType = 'gpt_generate_brand_logo';
    if (!isset($registry[$opType])) {
        throw new RuntimeException('Операция генерации логотипа не зарегистрирована.');
    }
    $logoKey = brand_audit_logo_key($brand);
    brand_audit_ensure_dir(brand_audit_logo_dir($cfg, $datasetId, $logoKey));
    $params = op_params_normalize((array)($registry[$opType]['params'] ?? []), [
        'brand' => $brand,
        'marketplaces' => $marketplaces !== '' ? $marketplaces : 'all',
        'source_op_id' => (string)$sourceOpId,
    ]);

    $opId = ops_create($datasetId, $opType, $params, ops_current_actor());
    ops_append_log_tail($opId, "Queued from brand audit report.\n", 200000);
    ops_update_progress($opId, 0, 1, 'queued', 'В очереди на генерацию логотипа');
    $logoState = brand_audit_write_logo_state($cfg, $datasetId, $brand, $opId, [
        'status' => 'queued',
        'marketplaces' => $params['marketplaces'] ?? 'all',
        'source_op_id' => $sourceOpId,
    ]);

    if (!empty($cfg['worker']['auto_spawn'])) {
        brand_logo_spawn_worker_detached($cfg, $datasetId, $opId);
    }

    echo json_encode([
        'ok' => true,
        'op_id' => $opId,
        'logo_key' => (string)($logoState['logo_key'] ?? $logoKey),
        'status_url' => 'brand_logo_status.php?dataset_id=' . rawurlencode((string)$datasetId)
            . '&logo_key=' . rawurlencode((string)($logoState['logo_key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
