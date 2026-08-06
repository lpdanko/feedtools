<?php
declare(strict_types=1);

@set_time_limit(0);
@ini_set('max_execution_time', '0');

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/DatasetLock.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/xml_scan.php';
require_once __DIR__ . '/../app/supplier_products.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
  http_response_code(500);
  exit("Missing vendor/autoload.php. Run: composer install");
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function wb_import_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function wb_import_norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = str_replace('ё', 'е', wb_import_lc($s));
  $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_import_clean_name(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_import_cell_addr(int $col, int $row): string
{
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function wb_import_vstr($v): string
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

function wb_import_cell_string(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $row): string
{
  $cell = $sheet->getCell(wb_import_cell_addr($col, $row));
  $v = $cell->getCalculatedValue();
  $s = wb_import_vstr($v);
  if ($s === '') $s = wb_import_vstr($cell->getFormattedValue());
  return trim($s);
}

function wb_import_norm_spaces(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/[\r\n\t]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_import_split_values(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];

  $parts = strpos($s, ';') === false ? [$s] : explode(';', $s);
  $out = [];
  $seen = [];
  foreach ($parts as $p) {
    $p = wb_import_norm_spaces((string)$p);
    if ($p === '') continue;
    $key = wb_import_lc($p);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $p;
  }
  return $out;
}

function wb_import_split_pictures(string $s): array
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

function wb_import_num_str(float $n): string
{
  $s = number_format($n, 3, '.', '');
  $s = preg_replace('/\.?0+$/', '', $s);
  return $s === '-0' ? '0' : (string)$s;
}

function wb_import_grams_to_kg(string $g): string
{
  $g = trim(str_replace(',', '.', (string)$g));
  if ($g === '' || !is_numeric($g)) return '';
  return wb_import_num_str(((float)$g) / 1000.0);
}

function wb_import_cm_to_mm(string $cm): string
{
  $cm = trim(str_replace(',', '.', (string)$cm));
  if ($cm === '' || !is_numeric($cm)) return '';
  return wb_import_num_str(((float)$cm) * 10.0);
}

function wb_import_safe_realpath_under(?string $baseDir, string $path): string
{
  $real = realpath($path);
  if (!$real || !is_file($real)) {
    throw new RuntimeException('XML датасета не найден.');
  }
  if ($baseDir) {
    $base = realpath($baseDir);
    if ($base && strpos($real, $base) !== 0) {
      throw new RuntimeException('XML датасета находится вне storage.');
    }
  }
  return $real;
}

function wb_import_find_header_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): int
{
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $maxRow = min((int)$sheet->getHighestRow(), 30);
  for ($r = 1; $r <= $maxRow; $r++) {
    $foundArticle = false;
    $foundName = false;
    for ($c = 1; $c <= $highestColIndex; $c++) {
      $hn = wb_import_norm_header(wb_import_cell_string($sheet, $c, $r));
      if ($hn === wb_import_norm_header('Артикул продавца')) $foundArticle = true;
      if ($hn === wb_import_norm_header('Наименование')) $foundName = true;
    }
    if ($foundArticle && $foundName) return $r;
  }
  throw new RuntimeException('Не найдена строка заголовков WB-шаблона.');
}

function wb_import_xml_start(XMLWriter $writer, XMLReader $reader): void
{
  $writer->startElement($reader->name);
  if ($reader->hasAttributes) {
    while ($reader->moveToNextAttribute()) {
      $writer->writeAttribute($reader->name, $reader->value);
    }
    $reader->moveToElement();
  }
}

function wb_import_write_text_el(XMLWriter $writer, string $tag, string $value): void
{
  $value = trim((string)$value);
  if ($tag === '' || $value === '') return;
  $writer->startElement($tag);
  $writer->text($value);
  $writer->endElement();
}

function wb_import_write_updates(XMLWriter $writer, ?array $data): array
{
  if (!$data) return ['tags' => 0, 'pictures' => 0, 'params' => 0];

  $writtenTags = 0;
  $writtenPictures = 0;
  $writtenParams = 0;

  $tagOrder = ['price', 'vendor', 'name', 'description', 'weight', 'dimensions', 'barcode'];
  foreach ($tagOrder as $tag) {
    if (!isset($data['tags'][$tag])) continue;
    $v = trim((string)$data['tags'][$tag]);
    if ($v === '') continue;
    wb_import_write_text_el($writer, $tag, $v);
    $writtenTags++;
  }

  if (!empty($data['pictures']) && is_array($data['pictures'])) {
    foreach ($data['pictures'] as $pic) {
      $pic = trim((string)$pic);
      if ($pic === '') continue;
      wb_import_write_text_el($writer, 'picture', $pic);
      $writtenPictures++;
    }
  }

  foreach (($data['wb_params'] ?? []) as $row) {
    $name = wb_import_clean_name((string)($row['name'] ?? ''));
    if ($name === '') continue;
    $values = array_values(array_filter(array_map('trim', (array)($row['values'] ?? [])), fn($x) => $x !== ''));
    if (!$values) continue;

    $seen = [];
    foreach ($values as $v) {
      $key = wb_import_lc((string)$v);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;

      $writer->startElement('wb_param');
      $writer->writeAttribute('name', $name);
      $writer->text((string)$v);
      $writer->endElement();
      $writtenParams++;
    }
  }

  return ['tags' => $writtenTags, 'pictures' => $writtenPictures, 'params' => $writtenParams];
}

function wb_import_add_param(array &$data, string $rawName, string $cellValue): void
{
  $name = wb_import_clean_name($rawName);
  $norm = wb_import_norm_header($name);
  if ($name === '' || $norm === '') return;

  $values = wb_import_split_values($cellValue);
  if (!$values) return;

  if (!isset($data['wb_params'][$norm])) {
    $data['wb_params'][$norm] = ['name' => $name, 'values' => []];
  }
  foreach ($values as $v) {
    $data['wb_params'][$norm]['values'][] = $v;
  }
  $data['remove_wb_params'][$norm] = true;
}

$datasetId = (int)($_POST['dataset_id'] ?? 0);
$error = '';
$result = null;
$datasetLock = null;

try {
  if ($datasetId <= 0) throw new RuntimeException('Bad dataset_id.');
  if (empty($_FILES['xlsxfile']) || ($_FILES['xlsxfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Файл WB-шаблона не загружен.');
  }

  $origName = (string)($_FILES['xlsxfile']['name'] ?? '');
  $bytes = (int)($_FILES['xlsxfile']['size'] ?? 0);
  if ($bytes <= 0) throw new RuntimeException('Пустой файл.');
  if ($bytes > (int)($cfg['limits']['max_upload_bytes'] ?? 50_000_000)) throw new RuntimeException('Файл слишком большой.');
  if (!preg_match('/\.xlsx$/i', $origName)) throw new RuntimeException('Нужен файл .xlsx');

  $stmt = db()->prepare("SELECT id, original_filename, stored_path FROM feedtools_datasets WHERE id = ?");
  $stmt->execute([$datasetId]);
  $ds = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$ds) throw new RuntimeException('Dataset не найден.');

  supplier_products_tables_ensure($cfg);
  $fullStmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
  $fullStmt->execute([$datasetId]);
  $fullDs = $fullStmt->fetch(PDO::FETCH_ASSOC);
  if (is_array($fullDs) && supplier_products_is_dataset_row($fullDs)) {
    throw new RuntimeException('DB-датасеты поставщиков импортируются через раздел "Импорт" на странице товаров поставщика.');
  }

  $xmlPath = wb_import_safe_realpath_under($cfg['paths']['uploads_dir'] ?? null, (string)$ds['stored_path']);
  $datasetLock = feedtools_dataset_lock_acquire($datasetId, null, 'import_wb_template_xlsx');

  try {
    $spreadsheet = IOFactory::load((string)$_FILES['xlsxfile']['tmp_name']);
  } catch (Throwable $e) {
    throw new RuntimeException('Не удалось прочитать XLSX: ' . $e->getMessage());
  }

  $sheet = $spreadsheet->getSheetByName('Товары');
  if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

  $HEADER_ROW = wb_import_find_header_row($sheet);
  $DATA_START_ROW = $HEADER_ROW + 2;
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $highestRow = (int)$sheet->getHighestRow();

  $cols = [];
  $colArticle = null;
  for ($c = 1; $c <= $highestColIndex; $c++) {
    $raw = wb_import_cell_string($sheet, $c, $HEADER_ROW);
    if ($raw === '') continue;
    $hn = wb_import_norm_header($raw);
    $cols[$c] = ['raw' => $raw, 'norm' => $hn];
    if ($hn === wb_import_norm_header('Артикул продавца')) $colArticle = $c;
  }
  if (!$colArticle) throw new RuntimeException('Не найдена колонка "Артикул продавца".');

  $baseMap = [
    wb_import_norm_header('Наименование') => 'tag:name',
    wb_import_norm_header('Бренд') => 'tag:vendor',
    wb_import_norm_header('Описание') => 'tag:description',
    wb_import_norm_header('Фото') => 'pictures',
    wb_import_norm_header('Цена') => 'tag:price',
    wb_import_norm_header('Баркоды') => 'tag:barcode',
    wb_import_norm_header('Вес с упаковкой (кг)') => 'weight_kg',
    wb_import_norm_header('Вес товара с упаковкой (г)') => 'weight_g',
    wb_import_norm_header('Высота упаковки') => 'dim:H',
    wb_import_norm_header('Длина упаковки') => 'dim:L',
    wb_import_norm_header('Ширина упаковки') => 'dim:W',
  ];

  $systemHeaders = array_fill_keys(array_map('wb_import_norm_header', [
    'Группа',
    'Артикул продавца',
    'Артикул WB',
    'Категория продавца',
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

  $updates = [];
  $rowsRead = 0;

  for ($r = $DATA_START_ROW; $r <= $highestRow; $r++) {
    $offerId = wb_import_cell_string($sheet, (int)$colArticle, $r);
    if ($offerId === '') continue;

    $data = $updates[$offerId] ?? [
      'tags' => [],
      'remove_tags' => [],
      'pictures' => null,
      'replace_pictures' => false,
      'wb_params' => [],
      'remove_wb_params' => [],
    ];
    $dim = ['L' => '', 'W' => '', 'H' => ''];
    $weightKg = '';
    $weightG = '';
    $hasAnyValue = false;

    foreach ($cols as $colIndex => $col) {
      if ($colIndex === $colArticle) continue;

      $rawHeader = (string)$col['raw'];
      $hn = (string)$col['norm'];
      if ($hn === '' || isset($systemHeaders[$hn])) continue;

      $cellVal = wb_import_cell_string($sheet, (int)$colIndex, $r);
      if ($cellVal === '') continue;
      $hasAnyValue = true;

      $rule = $baseMap[$hn] ?? null;
      if ($rule === 'pictures') {
        $pics = wb_import_split_pictures($cellVal);
        if ($pics) {
          $data['pictures'] = $pics;
          $data['replace_pictures'] = true;
        }
        continue;
      }
      if ($rule === 'weight_kg') {
        $weightKg = wb_import_norm_spaces($cellVal);
        continue;
      }
      if ($rule === 'weight_g') {
        $weightG = wb_import_norm_spaces($cellVal);
        continue;
      }
      if ($rule && strpos($rule, 'dim:') === 0) {
        $dim[substr($rule, 4)] = wb_import_cm_to_mm($cellVal);
        continue;
      }
      if ($rule && strpos($rule, 'tag:') === 0) {
        $tag = substr($rule, 4);
        $data['tags'][$tag] = $cellVal;
        $data['remove_tags'][$tag] = true;
        continue;
      }

      wb_import_add_param($data, $rawHeader, $cellVal);
    }

    if ($weightKg !== '') {
      $data['tags']['weight'] = str_replace(',', '.', $weightKg);
      $data['remove_tags']['weight'] = true;
    } elseif ($weightG !== '') {
      $kg = wb_import_grams_to_kg($weightG);
      if ($kg !== '') {
        $data['tags']['weight'] = $kg;
        $data['remove_tags']['weight'] = true;
      }
    }

    if ($dim['L'] !== '' || $dim['W'] !== '' || $dim['H'] !== '') {
      $data['tags']['dimensions'] = $dim['L'] . '/' . $dim['W'] . '/' . $dim['H'];
      $data['remove_tags']['dimensions'] = true;
    }

    if ($hasAnyValue || !empty($data['wb_params'])) {
      $updates[$offerId] = $data;
      $rowsRead++;
    }
  }

  if (!$updates) throw new RuntimeException('В XLSX не найдено строк с данными для импорта.');

  $outputsDir = rtrim((string)$cfg['paths']['outputs_dir'], '/\\');
  if (!is_dir($outputsDir) && !mkdir($outputsDir, 0775, true)) {
    throw new RuntimeException('Не удалось создать outputs_dir.');
  }
  $runDir = $outputsDir . '/wb_import_' . $datasetId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
  if (!mkdir($runDir, 0775, true) && !is_dir($runDir)) {
    throw new RuntimeException('Не удалось создать папку импорта.');
  }
  $outXmlAbs = $runDir . '/result.xml';
  $backupAbs = $runDir . '/backup_before_import.xml';

  $reader = new XMLReader();
  if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Не удалось открыть XML датасета.');
  }

  $writer = new XMLWriter();
  if (!$writer->openURI($outXmlAbs)) {
    $reader->close();
    throw new RuntimeException('Не удалось создать result.xml.');
  }
  $writer->startDocument('1.0', 'UTF-8');
  $writer->setIndent(false);

  $inOffer = false;
  $offerDepth = -1;
  $curOfferId = '';
  $curUpdate = null;
  $skipDepth = -1;

  $matchedOffers = 0;
  $updatedOffers = 0;
  $removedTags = 0;
  $removedParams = 0;
  $removedPictures = 0;
  $writtenTags = 0;
  $writtenParams = 0;
  $writtenPictures = 0;

  while ($reader->read()) {
    if ($skipDepth >= 0) {
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $skipDepth) {
        $skipDepth = -1;
      }
      continue;
    }

    switch ($reader->nodeType) {
      case XMLReader::ELEMENT: {
        $tag = $reader->localName ?: $reader->name;

        if ($tag === 'offer') {
          $inOffer = true;
          $offerDepth = $reader->depth;
          $curOfferId = trim((string)$reader->getAttribute('id'));
          $curUpdate = ($curOfferId !== '' && isset($updates[$curOfferId])) ? $updates[$curOfferId] : null;
          if ($curUpdate) $matchedOffers++;

          wb_import_xml_start($writer, $reader);
          if ($reader->isEmptyElement) {
            $stats = wb_import_write_updates($writer, $curUpdate);
            if ($curUpdate) {
              $updatedOffers++;
              $writtenTags += $stats['tags'];
              $writtenPictures += $stats['pictures'];
              $writtenParams += $stats['params'];
            }
            $writer->endElement();
            $inOffer = false;
            $offerDepth = -1;
            $curOfferId = '';
            $curUpdate = null;
          }
          break;
        }

        if ($inOffer && $curUpdate) {
          if ($tag === 'wb_param') {
            $pname = wb_import_clean_name((string)$reader->getAttribute('name'));
            $pn = wb_import_norm_header($pname);
            if ($pn !== '' && isset($curUpdate['remove_wb_params'][$pn])) {
              $removedParams++;
              if (!$reader->isEmptyElement) $skipDepth = $reader->depth;
              break;
            }
          }

          if ($tag === 'picture' && !empty($curUpdate['replace_pictures'])) {
            $removedPictures++;
            if (!$reader->isEmptyElement) $skipDepth = $reader->depth;
            break;
          }

          if (!empty($curUpdate['remove_tags'][$tag])) {
            $removedTags++;
            if (!$reader->isEmptyElement) $skipDepth = $reader->depth;
            break;
          }
        }

        wb_import_xml_start($writer, $reader);
        if ($reader->isEmptyElement) $writer->endElement();
        break;
      }

      case XMLReader::END_ELEMENT: {
        $tag = $reader->localName ?: $reader->name;
        if ($inOffer && $tag === 'offer' && $reader->depth === $offerDepth) {
          $stats = wb_import_write_updates($writer, $curUpdate);
          if ($curUpdate) {
            $updatedOffers++;
            $writtenTags += $stats['tags'];
            $writtenPictures += $stats['pictures'];
            $writtenParams += $stats['params'];
          }
          $writer->endElement();
          $inOffer = false;
          $offerDepth = -1;
          $curOfferId = '';
          $curUpdate = null;
          break;
        }
        $writer->endElement();
        break;
      }

      case XMLReader::TEXT:
        $writer->text($reader->value);
        break;

      case XMLReader::CDATA:
        $writer->writeCData($reader->value);
        break;

      case XMLReader::SIGNIFICANT_WHITESPACE:
      case XMLReader::WHITESPACE:
        $writer->text($reader->value);
        break;

      case XMLReader::COMMENT:
        $writer->writeComment($reader->value);
        break;

      case XMLReader::PI:
        $writer->writePI($reader->name, $reader->value);
        break;
    }
  }

  $reader->close();
  $writer->endDocument();
  $writer->flush();

  if ($matchedOffers <= 0) {
    @unlink($outXmlAbs);
    throw new RuntimeException('Ни один артикул из XLSX не найден в текущем датасете.');
  }

  $newSha = hash_file('sha256', $outXmlAbs);
  $dup = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ? AND id <> ?");
  $dup->execute([$newSha, $datasetId]);
  $dupId = $dup->fetchColumn();
  if ($dupId) {
    @unlink($outXmlAbs);
    throw new RuntimeException("Импорт заблокирован: результат совпадает с dataset #{$dupId}.");
  }

  @copy($xmlPath, $backupAbs);

  $dstDir = dirname($xmlPath);
  $tmpAbs = $dstDir . '/.tmp_wb_import_' . $datasetId . '_' . basename($xmlPath);
  if (!copy($outXmlAbs, $tmpAbs)) {
    throw new RuntimeException('Не удалось записать временный XML.');
  }
  if (!rename($tmpAbs, $xmlPath)) {
    @unlink($tmpAbs);
    throw new RuntimeException('Не удалось заменить XML датасета.');
  }

  $scan = scan_xml($xmlPath, (int)($cfg['limits']['sample_offers'] ?? 5));
  $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);
  $bytesXml = (int)filesize($xmlPath);

  $upd = db()->prepare("
    UPDATE feedtools_datasets
    SET bytes = ?, sha256 = ?, offers_count = ?, warnings_json = ?
    WHERE id = ?
  ");
  $upd->execute([
    $bytesXml,
    $newSha,
    (int)$scan['offers_count'],
    $warningsJson,
    $datasetId,
  ]);

  $result = [
    'dataset_id' => $datasetId,
    'rows_read' => $rowsRead,
    'xlsx_articles' => count($updates),
    'matched_offers' => $matchedOffers,
    'updated_offers' => $updatedOffers,
    'written_tags' => $writtenTags,
    'written_pictures' => $writtenPictures,
    'written_wb_params' => $writtenParams,
    'removed_tags' => $removedTags,
    'removed_pictures' => $removedPictures,
    'removed_wb_params' => $removedParams,
    'backup' => $backupAbs,
    'sha256' => $newSha,
  ];
  file_put_contents($runDir . '/report.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
} catch (Throwable $e) {
  $error = $e->getMessage();
} finally {
  if (is_array($datasetLock) && !empty($datasetLock['owner_token'])) {
    feedtools_dataset_lock_release($datasetId, (string)$datasetLock['owner_token']);
  }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Импорт WB XLSX</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;color:#111827}
    .card{border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:16px}
    .muted{color:#6b7280}
    .err{color:#b91c1c}
    .ok{color:#047857}
    a{color:#111827}
    code{background:#f3f4f6;border-radius:6px;padding:1px 5px}
    table{border-collapse:collapse;width:100%;margin-top:12px}
    th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:8px;font-size:14px}
  </style>
</head>
<body>
  <?= ft_top_navigation([
    'back_href' => 'view.php?id=' . rawurlencode((string)$datasetId),
    'back_label' => 'К датасету',
    'active' => 'xml',
  ]) ?>

  <div class="card">
    <h1>Импорт WB XLSX</h1>

    <?php if ($error): ?>
      <p class="err"><b>Ошибка:</b> <?= h($error) ?></p>
      <p class="muted">Проверь, что загружен заполненный WB-шаблон с листом «Товары» и колонкой «Артикул продавца».</p>
    <?php elseif ($result): ?>
      <p class="ok"><b>Готово.</b> Текущий датасет обновлен.</p>
      <table>
        <tr><th>Строк XLSX прочитано</th><td><?= h((string)$result['rows_read']) ?></td></tr>
        <tr><th>Артикулов в XLSX</th><td><?= h((string)$result['xlsx_articles']) ?></td></tr>
        <tr><th>Найдено товаров в датасете</th><td><?= h((string)$result['matched_offers']) ?></td></tr>
        <tr><th>Записано обычных тегов</th><td><?= h((string)$result['written_tags']) ?></td></tr>
        <tr><th>Записано фото</th><td><?= h((string)$result['written_pictures']) ?></td></tr>
        <tr><th>Записано WB-характеристик</th><td><?= h((string)$result['written_wb_params']) ?></td></tr>
        <tr><th>Backup XML</th><td><code><?= h((string)$result['backup']) ?></code></td></tr>
      </table>
      <p><a href="view.php?id=<?= h((string)$result['dataset_id']) ?>">Открыть датасет</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
