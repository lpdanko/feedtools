<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/supplier_products.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = db()->prepare("SELECT original_filename, stored_path FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

supplier_products_tables_ensure($cfg);
$stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$id]);
$fullRow = $stmt->fetch();
if (is_array($fullRow) && supplier_products_is_dataset_row($fullRow)) {
  http_response_code(404);
  exit('У DB-датасета поставщика нет XML-файла для скачивания.');
}

$uploadsDir = realpath($cfg['paths']['uploads_dir']);
$path = realpath($row['stored_path']);

if (!$uploadsDir || !$path || strpos($path, $uploadsDir) !== 0 || !is_file($path)) {
  http_response_code(404);
  exit('File not found');
}

$downloadName = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', basename($row['original_filename']));
if ($downloadName === '' || $downloadName === '_') $downloadName = 'feed.xml';

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$downloadName.'"');
header('Content-Length: ' . filesize($path));
readfile($path);
