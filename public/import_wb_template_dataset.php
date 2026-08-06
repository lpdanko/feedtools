<?php
declare(strict_types=1);

@set_time_limit(0);
@ini_set('max_execution_time', '0');

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/xml_scan.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
  http_response_code(500);
  exit("Missing vendor/autoload.php. Run: composer install");
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function wb_dataset_import_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function wb_dataset_import_norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = str_replace('ё', 'е', wb_dataset_import_lc($s));
  $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_dataset_import_clean_name(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_dataset_import_cell_addr(int $col, int $row): string
{
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function wb_dataset_import_vstr($v): string
{
  if ($v === null) return '';
  if (is_bool($v)) return $v ? '1' : '0';
  if (is_float($v)) {
    $s = rtrim(rtrim(sprintf('%.12F', $v), '0'), '.');
    return $s === '-0' ? '0' : trim($s);
  }
  if (is_int($v)) return (string)$v;
  return trim((string)$v);
}

function wb_dataset_import_cell_string(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $row): string
{
  $cell = $sheet->getCell(wb_dataset_import_cell_addr($col, $row));
  $v = $cell->getCalculatedValue();
  $s = wb_dataset_import_vstr($v);
  if ($s === '') $s = wb_dataset_import_vstr($cell->getFormattedValue());
  return trim($s);
}

function wb_dataset_import_norm_spaces(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/[\r\n\t]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_dataset_import_split_values(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];

  $parts = strpos($s, ';') === false ? [$s] : explode(';', $s);
  $out = [];
  $seen = [];
  foreach ($parts as $p) {
    $p = wb_dataset_import_norm_spaces((string)$p);
    if ($p === '') continue;
    $key = wb_dataset_import_lc($p);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $p;
  }
  return $out;
}

function wb_dataset_import_split_pictures(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];
  $s = str_replace(["\r\n", "\r", "\n", "\t"], ';', $s);
  $parts = preg_split('/\s*;\s*/u', $s) ?: [];
  if (count($parts) <= 1) {
    $parts = preg_split('/\s+/u', $s) ?: [];
  }

  $out = [];
  $seen = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p === '') continue;
    if (isset($seen[$p])) continue;
    $seen[$p] = true;
    $out[] = $p;
  }
  return $out;
}

function wb_dataset_import_num_str(float $n): string
{
  $s = number_format($n, 3, '.', '');
  $s = preg_replace('/\.?0+$/', '', $s);
  return $s === '-0' ? '0' : (string)$s;
}

function wb_dataset_import_grams_to_kg(string $g): string
{
  $g = trim(str_replace(',', '.', (string)$g));
  if ($g === '' || !is_numeric($g)) return '';
  return wb_dataset_import_num_str(((float)$g) / 1000.0);
}

function wb_dataset_import_cm_to_mm(string $cm): string
{
  $cm = trim(str_replace(',', '.', (string)$cm));
  if ($cm === '' || !is_numeric($cm)) return '';
  return wb_dataset_import_num_str(((float)$cm) * 10.0);
}

function wb_dataset_import_find_header_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): int
{
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $maxRow = min((int)$sheet->getHighestRow(), 30);
  for ($r = 1; $r <= $maxRow; $r++) {
    $foundArticle = false;
    $foundName = false;
    for ($c = 1; $c <= $highestColIndex; $c++) {
      $hn = wb_dataset_import_norm_header(wb_dataset_import_cell_string($sheet, $c, $r));
      if ($hn === wb_dataset_import_norm_header('Артикул продавца')) $foundArticle = true;
      if ($hn === wb_dataset_import_norm_header('Наименование')) $foundName = true;
    }
    if ($foundArticle && $foundName) return $r;
  }
  throw new RuntimeException('Не найдена строка заголовков WB-шаблона.');
}

function wb_dataset_import_find_category_id(string $rawValue): string
{
  $rawValue = trim((string)$rawValue);
  if ($rawValue === '') return '';
  if (ctype_digit($rawValue)) return $rawValue;

  static $cache = [];
  $cacheKey = wb_dataset_import_lc($rawValue);
  if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

  $stmt = db()->prepare("
    SELECT meta_json
    FROM feedtools_taxonomy_categories
    WHERE source='wildberries'
      AND is_leaf=1
      AND (name = ? OR full_path = ?)
    ORDER BY full_path = ? DESC, name = ? DESC
    LIMIT 1
  ");
  $stmt->execute([$rawValue, $rawValue, $rawValue, $rawValue]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $cache[$cacheKey] = '';
    return '';
  }

  $meta = [];
  if (!empty($row['meta_json'])) {
    $tmp = json_decode((string)$row['meta_json'], true);
    if (is_array($tmp)) $meta = $tmp;
  }
  $subjectId = trim((string)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? '')));
  $cache[$cacheKey] = ctype_digit($subjectId) ? $subjectId : '';
  return $cache[$cacheKey];
}

function wb_dataset_import_append_text(DOMDocument $dom, DOMElement $parent, string $tag, string $value): void
{
  $value = trim((string)$value);
  if ($tag === '' || $value === '') return;
  $el = $dom->createElement($tag);
  $el->appendChild($dom->createTextNode($value));
  $parent->appendChild($el);
}

$error = '';

try {
  if (empty($_FILES['xlsxfile']) || ($_FILES['xlsxfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Файл WB-шаблона не загружен.');
  }

  $origName = (string)($_FILES['xlsxfile']['name'] ?? '');
  $bytes = (int)($_FILES['xlsxfile']['size'] ?? 0);
  if ($bytes <= 0) throw new RuntimeException('Пустой файл.');
  if ($bytes > (int)($cfg['limits']['max_upload_bytes'] ?? 50_000_000)) throw new RuntimeException('Файл слишком большой.');
  if (!preg_match('/\.xlsx$/i', $origName)) throw new RuntimeException('Нужен файл .xlsx');

  try {
    $spreadsheet = IOFactory::load((string)$_FILES['xlsxfile']['tmp_name']);
  } catch (Throwable $e) {
    throw new RuntimeException('Не удалось прочитать XLSX: ' . $e->getMessage());
  }

  $sheet = $spreadsheet->getSheetByName('Товары');
  if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

  $headerRow = wb_dataset_import_find_header_row($sheet);
  $dataStartRow = $headerRow + 2;
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $highestRow = (int)$sheet->getHighestRow();

  $cols = [];
  $colArticle = null;
  for ($c = 1; $c <= $highestColIndex; $c++) {
    $raw = wb_dataset_import_cell_string($sheet, $c, $headerRow);
    if ($raw === '') continue;
    $hn = wb_dataset_import_norm_header($raw);
    $cols[$c] = ['raw' => $raw, 'norm' => $hn];
    if ($hn === wb_dataset_import_norm_header('Артикул продавца')) $colArticle = $c;
  }
  if (!$colArticle) throw new RuntimeException('Не найдена колонка "Артикул продавца".');

  $baseMap = [
    wb_dataset_import_norm_header('Наименование') => 'tag:name',
    wb_dataset_import_norm_header('Бренд') => 'tag:vendor',
    wb_dataset_import_norm_header('Описание') => 'tag:description',
    wb_dataset_import_norm_header('Фото') => 'pictures',
    wb_dataset_import_norm_header('Цена') => 'tag:price',
    wb_dataset_import_norm_header('Баркоды') => 'tag:barcode',
    wb_dataset_import_norm_header('Вес с упаковкой (кг)') => 'weight_kg',
    wb_dataset_import_norm_header('Вес товара с упаковкой (г)') => 'weight_g',
    wb_dataset_import_norm_header('Высота упаковки') => 'dim:H',
    wb_dataset_import_norm_header('Длина упаковки') => 'dim:L',
    wb_dataset_import_norm_header('Ширина упаковки') => 'dim:W',
    wb_dataset_import_norm_header('Категория продавца') => 'wb_category',
  ];

  $systemHeaders = array_fill_keys(array_map('wb_dataset_import_norm_header', [
    'Группа',
    'Артикул продавца',
    'Артикул WB',
    'Видео',
    'КИЗ',
    '18+',
    'Только для ИП и юрлиц',
    'Минимальное количество штук в заказе',
    'Подтверждаю, что товар промаркирован',
    'Ставка НДС',
    'Дата окончания действия сертификата/декларации',
    'Дата регистрации сертификата/декларации',
    'Номер декларации соответствия',
    'Номер сертификата соответствия',
    'NTIN',
    'Артикул OZON',
    'ИКПУ',
    'Код упаковки',
  ]), true);

  $dom = new DOMDocument('1.0', 'UTF-8');
  $dom->formatOutput = true;

  $yml = $dom->createElement('yml_catalog');
  $yml->setAttribute('date', date('Y-m-d H:i:s'));
  $dom->appendChild($yml);

  $shop = $dom->createElement('shop');
  $yml->appendChild($shop);
  $shop->appendChild($dom->createElement('name', 'feedtools-wb-import'));
  $shop->appendChild($dom->createElement('company', 'feedtools-wb-import'));
  $shop->appendChild($dom->createElement('url', 'https://example.local'));

  $currencies = $dom->createElement('currencies');
  $currency = $dom->createElement('currency');
  $currency->setAttribute('id', 'RUB');
  $currency->setAttribute('rate', '1');
  $currencies->appendChild($currency);
  $shop->appendChild($currencies);

  $shop->appendChild($dom->createElement('categories'));
  $offersEl = $dom->createElement('offers');
  $shop->appendChild($offersEl);

  $imported = 0;
  for ($r = $dataStartRow; $r <= $highestRow; $r++) {
    $offerId = wb_dataset_import_cell_string($sheet, (int)$colArticle, $r);
    if ($offerId === '') continue;

    $tags = [];
    $wbParams = [];
    $pictures = [];
    $dim = ['L' => '', 'W' => '', 'H' => ''];
    $weightKg = '';
    $weightG = '';

    foreach ($cols as $colIndex => $col) {
      if ($colIndex === $colArticle) continue;

      $rawHeader = (string)$col['raw'];
      $hn = (string)$col['norm'];
      if ($hn === '' || isset($systemHeaders[$hn])) continue;

      $cellVal = wb_dataset_import_cell_string($sheet, (int)$colIndex, $r);
      if ($cellVal === '') continue;

      $rule = $baseMap[$hn] ?? null;
      if ($rule === 'pictures') {
        $pictures = wb_dataset_import_split_pictures($cellVal);
        continue;
      }
      if ($rule === 'weight_kg') {
        $weightKg = wb_dataset_import_norm_spaces($cellVal);
        continue;
      }
      if ($rule === 'weight_g') {
        $weightG = wb_dataset_import_norm_spaces($cellVal);
        continue;
      }
      if ($rule === 'wb_category') {
        $wbCategoryId = wb_dataset_import_find_category_id($cellVal);
        if ($wbCategoryId !== '') $tags['wb_category'] = $wbCategoryId;
        continue;
      }
      if ($rule && strpos($rule, 'dim:') === 0) {
        $dim[substr($rule, 4)] = wb_dataset_import_cm_to_mm($cellVal);
        continue;
      }
      if ($rule && strpos($rule, 'tag:') === 0) {
        $tags[substr($rule, 4)] = $cellVal;
        continue;
      }

      $paramName = wb_dataset_import_clean_name($rawHeader);
      if ($paramName === '') continue;
      foreach (wb_dataset_import_split_values($cellVal) as $value) {
        $wbParams[$paramName] ??= [];
        $wbParams[$paramName][] = $value;
      }
    }

    if ($weightKg !== '') {
      $tags['weight'] = str_replace(',', '.', $weightKg);
    } elseif ($weightG !== '') {
      $kg = wb_dataset_import_grams_to_kg($weightG);
      if ($kg !== '') $tags['weight'] = $kg;
    }

    if ($dim['L'] !== '' || $dim['W'] !== '' || $dim['H'] !== '') {
      $tags['dimensions'] = $dim['L'] . '/' . $dim['W'] . '/' . $dim['H'];
    }

    $offerEl = $dom->createElement('offer');
    $offerEl->setAttribute('id', $offerId);

    $tagOrder = ['price', 'vendor', 'name', 'description', 'weight', 'dimensions', 'barcode', 'wb_category'];
    foreach ($tagOrder as $tag) {
      if (!isset($tags[$tag])) continue;
      wb_dataset_import_append_text($dom, $offerEl, $tag, (string)$tags[$tag]);
    }
    foreach ($tags as $tag => $value) {
      if (in_array($tag, $tagOrder, true)) continue;
      wb_dataset_import_append_text($dom, $offerEl, (string)$tag, (string)$value);
    }

    $seenPics = [];
    foreach ($pictures as $pic) {
      $pic = trim((string)$pic);
      if ($pic === '' || isset($seenPics[$pic])) continue;
      $seenPics[$pic] = true;
      wb_dataset_import_append_text($dom, $offerEl, 'picture', $pic);
    }

    foreach ($wbParams as $paramName => $values) {
      $seenValues = [];
      foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seenValues[$value])) continue;
        $seenValues[$value] = true;

        $paramEl = $dom->createElement('wb_param');
        $paramEl->setAttribute('name', $paramName);
        $paramEl->appendChild($dom->createTextNode($value));
        $offerEl->appendChild($paramEl);
      }
    }

    $offersEl->appendChild($offerEl);
    $imported++;
    if ($imported > 200000) {
      throw new RuntimeException('Слишком много строк (предохранитель).');
    }
  }

  if ($imported <= 0) {
    throw new RuntimeException('Не найдено ни одного товара с заполненным "Артикул продавца".');
  }

  $uploadsDir = $cfg['paths']['uploads_dir'];
  if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true)) {
    throw new RuntimeException('Не удалось создать папку uploads.');
  }

  $safeBase = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', basename($origName));
  $safeBase = preg_replace('/\.xlsx$/i', '.xml', $safeBase);
  if ($safeBase === '' || $safeBase === '_') $safeBase = 'wb_import.xml';

  $storedFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase;
  $storedPath = $uploadsDir . '/' . $storedFilename;

  $xmlString = $dom->saveXML();
  if ($xmlString === false || trim($xmlString) === '') {
    throw new RuntimeException('Не удалось сгенерировать XML.');
  }
  if (file_put_contents($storedPath, $xmlString) === false) {
    throw new RuntimeException('Не удалось сохранить XML в uploads.');
  }

  $bytesXml = filesize($storedPath);
  $sha256 = hash_file('sha256', $storedPath);

  $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
  $stmt->execute([$sha256]);
  $existing = $stmt->fetchColumn();
  if ($existing) {
    @unlink($storedPath);
    header("Location: view.php?id=" . urlencode((string)$existing));
    exit;
  }

  $scan = scan_xml($storedPath, (int)($cfg['limits']['sample_offers'] ?? 5));
  $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);
  $origXmlName = preg_replace('/\.xlsx$/i', '.xml', $origName);

  $stmt = db()->prepare("
    INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $origXmlName,
    $storedFilename,
    $storedPath,
    (int)$bytesXml,
    $sha256,
    (int)$scan['offers_count'],
    $warningsJson,
  ]);

  $datasetId = db()->lastInsertId();
  header("Location: view.php?id=" . urlencode((string)$datasetId));
  exit;
} catch (Throwable $e) {
  $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Import WB XLSX → Dataset</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px;}
    .muted{color:#6b7280;}
    .err{color:#b91c1c;}
    input,button{font-size:14px;}
  </style>
</head>
<body>

<?= ft_top_navigation([
  'back_href' => 'xml_feeds.php',
  'back_label' => 'Назад',
  'active' => 'xml',
]) ?>

<div class="card">
  <h2>Импорт WB XLSX-шаблона → новый датасет</h2>

  <?php if ($error): ?>
    <p class="err"><b>Ошибка:</b> <?= h($error) ?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <p class="muted">Лист: <b>Товары</b>. Заголовки: строка с <b>Артикул продавца</b>. Данные: через <b>2 строки</b> после заголовков. Пустые строки пропускаются.</p>
    <input type="file" name="xlsxfile" accept=".xlsx" required>
    <button type="submit">Импортировать</button>
  </form>
</div>

</body>
</html>
