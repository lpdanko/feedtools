<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/../app/xml_scan.php';

$opId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$key  = isset($_GET['key']) ? (string)$_GET['key'] : 'result_xml';

if ($opId <= 0) { http_response_code(400); exit('Bad id'); }

$op = ops_get($opId);
if (!$op) { http_response_code(404); exit('Operation not found'); }

$outputs = [];
if (!empty($op['outputs_json'])) $outputs = json_decode($op['outputs_json'], true) ?: [];
if (!isset($outputs[$key])) { http_response_code(404); exit('Output key not found'); }

$srcAbs = realpath(abs_from_outputs($cfg, (string)$outputs[$key]));
if (!$srcAbs || !is_file($srcAbs)) { http_response_code(404); exit('Output file not found'); }

// дедуп по sha256
$sha256 = hash_file('sha256', $srcAbs);

$stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
$stmt->execute([$sha256]);
$existing = $stmt->fetchColumn();

if ($existing) {
  // Записываем происхождение: операция -> существующий датасет (дубликат)
  $ins = db()->prepare("
    INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
    VALUES (?, ?, ?, ?, 1)
  ");
  $ins->execute([(int)$opId, (string)$key, (int)$existing, (string)$sha256]);

  $target = "view.php?id=" . urlencode((string)$existing);
  $opIdEsc = htmlspecialchars((string)$opId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $dsIdEsc = htmlspecialchars((string)$existing, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<meta http-equiv="refresh" content="7;url=' . htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
  echo '<title>Датасет не создан</title>';
  echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:30px auto;padding:0 12px}';
  echo '.card{border:1px solid #e5e7eb;border-radius:12px;padding:16px} .muted{color:#6b7280}</style>';
  echo '</head><body>';
  echo '<div class="card">';
  echo '<h2>Новый датасет не создан</h2>';
  echo '<p>Результат операции <b>#' . $opIdEsc . '</b> полностью идентичен уже существующему датасету <b>#' . $dsIdEsc . '</b> (совпадает SHA-256).</p>';
  echo '<p class="muted">Чтобы не плодить дубликаты, мы используем существующий датасет.</p>';
  echo '<p><a href="' . htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Перейти к датасету #' . $dsIdEsc . '</a></p>';
  echo '<p class="muted">Автопереход через 5 секунд…</p>';
  echo '</div></body></html>';
  exit;

}



$uploadsDir = $cfg['paths']['uploads_dir'];
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true)) {
  http_response_code(500); exit('Cannot create uploads dir');
}

$originalFilename = "from_op_{$opId}_{$key}.xml";
$storedFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $originalFilename;
$storedPath = $uploadsDir . '/' . $storedFilename;

if (!copy($srcAbs, $storedPath)) {
  http_response_code(500);
  exit('Copy failed');
}

// сканируем: offers_count + warnings
$scan = scan_xml($storedPath, 0);
$warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);

$stmt = db()->prepare("
  INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
  VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
  $originalFilename,
  $storedFilename,
  $storedPath,
  (int)filesize($storedPath),
  $sha256,
  (int)$scan['offers_count'],
  $warningsJson,
]);

$newId = (int)db()->lastInsertId();

// Записываем происхождение: операция -> новый датасет
$ins = db()->prepare("
  INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
  VALUES (?, ?, ?, ?, 0)
");
$ins->execute([(int)$opId, (string)$key, (int)$newId, (string)$sha256]);

header("Location: view.php?id=" . urlencode((string)$newId));
