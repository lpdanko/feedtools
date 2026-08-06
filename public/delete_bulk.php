<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/supplier_products.php';

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || !$ids) {
  header('Location: index.php');
  exit;
}

// Normalize: unique positive integers
$uniq = [];
foreach ($ids as $v) {
  $id = (int)$v;
  if ($id > 0) {
    $uniq[$id] = true;
  }
}
$ids = array_keys($uniq);
if (!$ids) {
  header('Location: index.php');
  exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

try {
  supplier_products_tables_ensure($cfg);
  // Collect stored_path for later deletion
  $stmt = db()->prepare("
    SELECT id, stored_path
    FROM feedtools_datasets
    WHERE id IN ($placeholders)
      AND id NOT IN (
        SELECT dataset_id
        FROM feedtools_supplier_product_meta
        WHERE dataset_id > 0
      )
  ");
  $stmt->execute($ids);
  $rows = $stmt->fetchAll();
  $deleteIds = array_map(static fn(array $row): int => (int)$row['id'], $rows ?: []);
  if (!$deleteIds) {
    header('Location: index.php');
    exit;
  }
  $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));

  // Delete DB rows
  db()->beginTransaction();
  $del = db()->prepare("
    DELETE FROM feedtools_datasets
    WHERE id IN ($deletePlaceholders)
      AND id NOT IN (
        SELECT dataset_id
        FROM feedtools_supplier_product_meta
        WHERE dataset_id > 0
      )
  ");
  $del->execute($deleteIds);
  db()->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
  http_response_code(500);
  exit('DB error: ' . $e->getMessage());
}

// Delete files on disk (best-effort)
foreach ($rows as $r) {
  $path = $r['stored_path'] ?? '';
  if ($path && is_file($path)) {
    @unlink($path);
  }
}

header('Location: index.php');
