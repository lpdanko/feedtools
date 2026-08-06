<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/brand_audit_storage.php';

$opId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fileKey = isset($_GET['file']) ? urldecode((string)$_GET['file']) : '';

if ($opId <= 0 || $fileKey === '') {
  http_response_code(400);
  exit('Bad request');
}

$op = ops_get($opId);
if (!$op) {
  http_response_code(404);
  exit('Operation not found');
}

$outputs = [];
if (!empty($op['outputs_json'])) {
  $outputs = json_decode((string)$op['outputs_json'], true) ?: [];
}

$path = false;
if (isset($outputs[$fileKey])) {
  try {
    $rel = (string)$outputs[$fileKey];
    $path = realpath(abs_from_outputs($cfg, $rel));
  } catch (Throwable $e) {
    $path = false;
  }
}
$outputsRoot = realpath($cfg['paths']['outputs_dir']);
$normalPathOk = $path && $outputsRoot && strpos($path, $outputsRoot) === 0 && is_file($path);
if (!$normalPathOk) {
  $path = false;
}

if (!$normalPathOk && (string)($op['op_type'] ?? '') === 'analyze_brand_category_map' && $fileKey === 'report_html') {
  $path = brand_audit_report_path($cfg, (int)($op['dataset_id'] ?? 0), (int)($op['id'] ?? 0), 'html');
}

if (!$path || !is_file($path)) {
  http_response_code(404);
  exit('File not accessible');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!in_array($ext, ['html', 'htm'], true)) {
  http_response_code(400);
  exit('Artifact cannot be opened inline');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
readfile($path);
