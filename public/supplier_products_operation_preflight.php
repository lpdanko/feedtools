<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/op_params.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/supplier_products_db_ops.php';

header('Content-Type: application/json; charset=utf-8');

function supplier_products_preflight_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$datasetId = max(0, (int)($_POST['dataset_id'] ?? 0));
$opType = trim((string)($_POST['op_type'] ?? ''));
if ($datasetId <= 0 || $opType === '') {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Bad request'], 400);
}

$registry = op_registry();
if (!isset($registry[$opType])) {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Unknown op_type'], 400);
}

$st = db()->prepare('SELECT * FROM feedtools_datasets WHERE id = ?');
$st->execute([$datasetId]);
$ds = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($ds)) {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Dataset not found'], 404);
}
if (!supplier_products_is_dataset_row($ds)) {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Operation preflight is available only for supplier products'], 400);
}

$opMeta = (array)$registry[$opType];
if (!supplier_products_db_operation_is_supported($opType, $opMeta)) {
    supplier_products_preflight_json(['ok' => false, 'error' => 'Operation is not available for supplier products'], 400);
}

$params = op_params_normalize((array)($opMeta['params'] ?? []), $_POST);
if (!empty($_POST['offer_ids_json']) && is_string($_POST['offer_ids_json'])) {
    $raw = trim((string)$_POST['offer_ids_json']);
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            $clean = [];
            foreach ($arr as $value) {
                $offerId = trim((string)$value);
                if ($offerId !== '') {
                    $clean[] = $offerId;
                }
            }
            $clean = array_values(array_unique($clean));
            if ($clean) {
                $params['offer_ids'] = $clean;
            }
        }
    }
}

try {
    if ($opType === 'gpt_generate_cover_image') {
        supplier_products_preflight_json(['ok' => true] + supplier_products_db_cover_features_preflight($cfg, $ds, $params));
    }
    supplier_products_preflight_json([
        'ok' => true,
        'op_type' => $opType,
        'blocked' => false,
        'warning' => false,
        'message' => '',
    ]);
} catch (Throwable $e) {
    supplier_products_preflight_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
