<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/brand_audit_storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$datasetId = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
$logoKey = strtolower(trim((string)($_GET['logo_key'] ?? '')));
if ($datasetId <= 0 || !preg_match('~^[a-f0-9]{24}$~D', $logoKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный идентификатор логотипа.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$state = brand_audit_logo_state($cfg, $datasetId, $logoKey);
if ($state === null) {
    echo json_encode(['status' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = [];
foreach (['ozon', 'wb', 'master'] as $fileKey) {
    $path = brand_audit_logo_file_path($cfg, $datasetId, $logoKey, $fileKey);
    if ($path !== null) {
        $files[$fileKey] = [
            'url' => 'brand_logo_file.php?dataset_id=' . rawurlencode((string)$datasetId)
                . '&logo_key=' . rawurlencode($logoKey)
                . '&file=' . rawurlencode($fileKey),
            'bytes' => (int)filesize($path),
        ];
    }
}
if ((string)($state['status'] ?? '') === 'done' && isset($files['ozon'], $files['wb'], $files['master'])) {
    echo json_encode([
        'status' => 'done',
        'brand' => (string)($state['brand'] ?? ''),
        'op_id' => (int)($state['op_id'] ?? 0),
        'generated_at' => (string)($state['generated_at'] ?? $state['updated_at'] ?? ''),
        'outputs' => $files,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$opId = (int)($state['op_id'] ?? 0);
$op = $opId > 0 ? ops_get($opId) : null;
if (!$op || (int)($op['dataset_id'] ?? 0) !== $datasetId || (string)($op['op_type'] ?? '') !== 'gpt_generate_brand_logo') {
    echo json_encode(['status' => 'error', 'error_text' => 'Операция генерации логотипа не найдена.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = (string)($op['status'] ?? 'queued');
echo json_encode([
    'status' => $status,
    'op_id' => $opId,
    'msg' => (string)($op['progress_msg'] ?? ''),
    'error_text' => (string)($op['error_text'] ?? ''),
], JSON_UNESCAPED_UNICODE);
