<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/brand_audit_storage.php';
require_once __DIR__ . '/../app/supplier_products_db_ops.php';

$datasetId = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
$opId = isset($_GET['op_id']) ? (int)$_GET['op_id'] : 0;
$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($datasetId <= 0 || !in_array($format, ['html', 'json', 'csv'], true)) {
    http_response_code(400);
    exit('Bad request');
}

$stmt = db()->prepare('SELECT id FROM feedtools_datasets WHERE id = ? LIMIT 1');
$stmt->execute([$datasetId]);
if (!(int)$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Dataset not found');
}

if ($opId <= 0) {
    $latest = supplier_products_db_brand_audit_latest_report_info($cfg, $datasetId);
    $opId = (int)($latest['op_id'] ?? 0);
}
$path = $opId > 0 ? brand_audit_report_path($cfg, $datasetId, $opId, $format) : null;
if ($path === null) {
    http_response_code(404);
    exit('Brand audit report not found');
}

$types = [
    'html' => 'text/html; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
];
header('Content-Type: ' . $types[$format]);
header('X-Content-Type-Options: nosniff');
if ($format !== 'html') {
    header('Content-Disposition: attachment; filename="brand_audit_dataset_' . $datasetId . '_op_' . $opId . '.' . $format . '"');
}
header('Content-Length: ' . (string)filesize($path));
readfile($path);
