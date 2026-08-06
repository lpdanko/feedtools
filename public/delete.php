<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/supplier_products.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = db()->prepare("SELECT stored_path FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

supplier_products_tables_ensure($cfg);
$stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$id]);
$fullRow = $stmt->fetch();
if (is_array($fullRow) && supplier_products_is_dataset_row($fullRow)) {
  header("Location: suppliers.php");
  exit;
}

try {
  db()->prepare("DELETE FROM feedtools_datasets WHERE id = ?")->execute([$id]);
} catch (Throwable $e) {
  http_response_code(500);
  exit("DB error: " . $e->getMessage());
}

$path = $row['stored_path'];
if ($path && is_file($path)) {
  @unlink($path);
}

header("Location: index.php");
