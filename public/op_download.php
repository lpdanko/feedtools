<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/brand_audit_storage.php';

$opId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fileKey = isset($_GET['file']) ? urldecode((string)$_GET['file']) : '';

if ($opId <= 0 || $fileKey === '') { http_response_code(400); exit('Bad request'); }

$op = ops_get($opId);
if (!$op) { http_response_code(404); exit('Not found'); }

$outputs = [];
if (!empty($op['outputs_json'])) {
  $outputs = json_decode($op['outputs_json'], true) ?: [];
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

if (!$normalPathOk && (string)($op['op_type'] ?? '') === 'analyze_brand_category_map') {
  $formatByKey = [
    'report_html' => 'html',
    'report_json' => 'json',
    'brand_category_audit_csv' => 'csv',
  ];
  $format = $formatByKey[$fileKey] ?? '';
  if ($format !== '') {
    $path = brand_audit_report_path($cfg, (int)($op['dataset_id'] ?? 0), (int)($op['id'] ?? 0), $format);
  }
}

if (!$path || !is_file($path)) {
  http_response_code(404);
  exit('File not accessible');
}

$basename = basename($path);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$basename.'"');
header('Content-Length: ' . filesize($path));
readfile($path);
