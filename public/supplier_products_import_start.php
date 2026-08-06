<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/supplier_products_import.php';
require_once __DIR__ . '/../app/supplier_products_marketplace_import.php';

header('Content-Type: application/json; charset=utf-8');

function supplier_import_start_json(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function supplier_import_start_radio(string $name, string $fallback): string
{
  $value = trim((string)($_POST[$name] ?? $fallback));
  return $value !== '' ? $value : $fallback;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  supplier_import_start_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
  $datasetId = (int)($_POST['id'] ?? 0);
  if ($datasetId <= 0) {
    throw new RuntimeException('Не указан датасет товаров поставщика.');
  }

  $st = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
  $st->execute([$datasetId]);
  $dataset = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($dataset) || !supplier_products_is_dataset_row($dataset)) {
    throw new RuntimeException('DB-датасет товаров поставщика не найден.');
  }

  $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
  if ($supplierId <= 0) {
    throw new RuntimeException('Поставщик для датасета не найден.');
  }

  $action = trim((string)($_POST['supplier_import_action'] ?? 'import'));
  $csvActions = ['remap_offer_ids', 'remap_wb_vendor_codes'];
  if (!in_array($action, ['import', 'quick_prices_stock', 'quick_dimensions', 'replace_catalog', 'delete_stale', 'remap_offer_ids', 'remap_wb_vendor_codes'], true)) {
    throw new RuntimeException('Неподдерживаемое действие импорта.');
  }

  $source = trim((string)($_POST['supplier_import_source'] ?? ''));
  if ($source === '' || !in_array($source, supplier_marketplace_import_sources(), true)) {
    throw new RuntimeException('Выбери источник импорта.');
  }
  if (in_array($action, $csvActions, true) && $source !== 'upload') {
    throw new RuntimeException('Для замены артикулов выбери источник «Загрузить файл» и CSV-карту.');
  }
  if ($action === 'remap_wb_vendor_codes' && (int)($_POST['wb_connection_id'] ?? 0) <= 0) {
    throw new RuntimeException('Выбери подключение Wildberries.');
  }

  $storedFile = trim((string)($_POST['supplier_import_stored_file'] ?? ''));
  $storedFileName = trim((string)($_POST['supplier_import_stored_file_name'] ?? ''));
  if ($source === 'upload') {
    $file = (array)($_FILES['supplier_import_file'] ?? []);
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $stored = supplier_import_store_uploaded_source($file, $cfg);
      $storedFile = (string)$stored['token'];
      $storedFileName = (string)$stored['name'];
    }
    if ($storedFile === '') {
      throw new RuntimeException('Сначала загрузи файл источника.');
    }
    $storedExt = supplier_import_lc(pathinfo($storedFileName !== '' ? $storedFileName : $storedFile, PATHINFO_EXTENSION));
    if (in_array($action, $csvActions, true) && $storedExt !== 'csv') {
      throw new RuntimeException('Для замены артикулов нужен CSV-файл с колонками: старый артикул; новый артикул.');
    }
    if (!in_array($action, $csvActions, true) && $storedExt === 'csv') {
      throw new RuntimeException('CSV-карта доступна только для операций замены артикулов по CSV.');
    }
  }

  $selectedRaw = trim((string)($_POST['supplier_import_offer_ids_json'] ?? ''));
  $selectedIds = [];
  if ($selectedRaw !== '') {
    $decoded = json_decode($selectedRaw, true);
    if (is_array($decoded)) {
      $selectedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $decoded
      ), static fn($value): bool => $value !== '')));
    }
  }

  $params = [
    'import_action' => $action,
    'source' => $source,
    'feed_url' => trim((string)($_POST['supplier_import_feed_url'] ?? '')),
    'stored_file' => $storedFile,
    'stored_file_name' => $storedFileName,
    'ozon_connection_id' => (string)(int)($_POST['ozon_connection_id'] ?? 0),
    'wb_connection_id' => (string)(int)($_POST['wb_connection_id'] ?? 0),
    'mode' => supplier_import_start_radio('supplier_import_mode', 'add_update'),
    'scope' => supplier_import_start_radio('supplier_import_scope', 'all'),
    'quick_scope' => supplier_import_start_radio('supplier_import_quick_scope', 'all'),
    'replace_names' => supplier_import_start_radio('supplier_import_replace_names', '0'),
    'brand_mode' => supplier_import_start_radio('supplier_import_brand_mode', 'no_update'),
    'model_mode' => supplier_import_start_radio('supplier_import_model_mode', 'no_update'),
    'description_mode' => supplier_import_start_radio('supplier_import_description_mode', 'no_update'),
    'update_supplier_category' => supplier_import_start_radio('supplier_import_update_supplier_category', '0'),
    'characteristics_mode' => supplier_import_start_radio('supplier_import_characteristics_mode', 'no_update'),
    'photos_mode' => supplier_import_start_radio('supplier_import_photos_mode', 'no_replace'),
    'zero_missing_stock' => !empty($_POST['supplier_import_zero_missing_stock']) ? '1' : '0',
    'selected_offer_ids' => $selectedIds,
    'offer_ids' => $selectedIds,
  ];

  $registry = op_registry();
  if (empty($registry['supplier_import_catalog'])) {
    throw new RuntimeException('Операция импорта не зарегистрирована.');
  }

  $duplicate = ops_find_recent_duplicate($datasetId, 'supplier_import_catalog', $params, 10);
  if (is_array($duplicate) && (int)($duplicate['id'] ?? 0) > 0) {
    $opId = (int)$duplicate['id'];
    supplier_import_start_json([
      'ok' => true,
      'op_id' => $opId,
      'duplicate' => true,
      'poll_url' => 'op_poll.php?id=' . urlencode((string)$opId),
      'op_url' => 'op.php?id=' . urlencode((string)$opId),
    ]);
  }

  $opId = ops_create($datasetId, 'supplier_import_catalog', $params);
  ops_append_log_tail($opId, "Queued.\n", 200000);
  ops_update_progress($opId, 0, 100, 'queued', 'Импорт поставлен в очередь');

  if (!empty($cfg['worker']['auto_spawn'])) {
    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);
    $spawnLogAbs = $outDir . '/spawn.log';
    @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);

    $php = $cfg['worker']['php_bin'] ?? PHP_BINARY;
    $script = $cfg['worker']['worker_script'] ?? (__DIR__ . '/../bin/worker.php');
    $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($script)
      . ' --op_id=' . (int)$opId
      . ' > ' . escapeshellarg($spawnLogAbs) . ' 2>&1 &';
    @exec($cmd);
    ops_append_log_tail($opId, "spawnLogAbs: {$spawnLogAbs}\n", 200000);
    ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
  }

  supplier_import_start_json([
    'ok' => true,
    'op_id' => $opId,
    'poll_url' => 'op_poll.php?id=' . urlencode((string)$opId),
    'op_url' => 'op.php?id=' . urlencode((string)$opId),
  ]);
} catch (Throwable $e) {
  supplier_import_start_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
