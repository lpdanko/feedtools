<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/brand_audit_storage.php';

$datasetId = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
$logoKey = strtolower(trim((string)($_GET['logo_key'] ?? '')));
$fileKey = strtolower(trim((string)($_GET['file'] ?? '')));
if ($datasetId <= 0 || !preg_match('~^[a-f0-9]{24}$~D', $logoKey)) {
    http_response_code(400);
    exit('Bad request');
}

$path = brand_audit_logo_file_path($cfg, $datasetId, $logoKey, $fileKey);
if ($path === null) {
    http_response_code(404);
    exit('File not found');
}

$types = [
    'master' => 'image/png',
    'ozon' => 'image/png',
    'wb' => 'image/jpeg',
    'meta' => 'application/json; charset=utf-8',
];
header('Content-Type: ' . ($types[$fileKey] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
