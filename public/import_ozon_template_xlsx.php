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

function ozon_import_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function ozon_import_norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return ozon_import_lc(trim((string)$s));
}

function ozon_import_clean_name(string $s): string
{
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], (string)$s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function ozon_import_cell_addr(int $col, int $row): string
{
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function ozon_import_vstr($v): string
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

function ozon_import_cell_string(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $row): string
{
  $cell = $sheet->getCell(ozon_import_cell_addr($col, $row));
  $s = ozon_import_vstr($cell->getCalculatedValue());
  if ($s === '') $s = ozon_import_vstr($cell->getFormattedValue());
  return trim($s);
}

function ozon_import_norm_spaces(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/[\r\n\t]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function ozon_import_norm_hashtags(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = str_replace([',', ';'], ' ', $s);
  $s = preg_replace('/[\r\n\t]+/u', ' ', (string)$s);
  $parts = preg_split('/\s+/u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $out = [];
  $seen = [];
  foreach ($parts as $part) {
    $part = ltrim(trim((string)$part), '#');
    $part = preg_replace('/[^\p{L}\p{N}_]+/u', '_', (string)$part);
    $part = preg_replace('/_+/u', '_', (string)$part);
    $part = trim((string)$part, '_');
    if ($part === '') continue;
    if (mb_strlen($part, 'UTF-8') > 29) {
      $part = trim((string)mb_substr($part, 0, 29, 'UTF-8'), '_');
    }
    if ($part === '') continue;
    $tag = '#' . $part;
    $key = ozon_import_lc($tag);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $tag;
  }
  return implode(' ', $out);
}

function ozon_import_split_values(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];
  $parts = strpos($s, ';') === false ? [$s] : explode(';', $s);
  $out = [];
  $seen = [];
  foreach ($parts as $p) {
    $p = ozon_import_norm_spaces((string)$p);
    if ($p === '') continue;
    $k = ozon_import_lc($p);
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $out[] = $p;
  }
  return $out;
}

function ozon_import_split_pictures(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];
  $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
  $parts = preg_split('/\s+/u', $s) ?: [];
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

function ozon_import_num_str(float $n): string
{
  $s = number_format($n, 3, '.', '');
  $s = preg_replace('/\.?0+$/', '', $s);
  return $s === '-0' ? '0' : (string)$s;
}

function ozon_import_grams_to_kg(string $g): string
{
  $g = trim(str_replace(',', '.', (string)$g));
  if ($g === '' || !is_numeric($g)) return '';
  return ozon_import_num_str(((float)$g) / 1000.0);
}

function ozon_import_safe_realpath_under(?string $baseDir, string $path): string
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

function ozon_import_find_category_pair_by_type(string $type): string
{
  static $cache = [];
  $type = trim((string)$type);
  if ($type === '') return '';
  $key = ozon_import_lc($type);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $sql = "SELECT ozon_parent_id, ozon_leaf_id
          FROM feedtools_taxonomy_categories
          WHERE source='ozon' AND (full_path = ? OR name = ?)
          ORDER BY is_leaf DESC, level DESC
          LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute([$type, $type]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $stmt = db()->prepare("SELECT ozon_parent_id, ozon_leaf_id
                           FROM feedtools_taxonomy_categories
                           WHERE source='ozon' AND (full_path LIKE ? OR name LIKE ?)
                           ORDER BY is_leaf DESC, level DESC
                           LIMIT 1");
    $like = '%' . $type . '%';
    $stmt->execute([$like, $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  $parent = trim((string)($row['ozon_parent_id'] ?? ''));
  $leaf = trim((string)($row['ozon_leaf_id'] ?? ''));
  $cache[$key] = ($parent !== '' && $leaf !== '') ? ($parent . '_' . $leaf) : '';
  return $cache[$key];
}

function ozon_import_xml_start(XMLWriter $writer, XMLReader $reader): void
{
  $writer->startElement($reader->name);
  if ($reader->hasAttributes) {
    while ($reader->moveToNextAttribute()) {
      $writer->writeAttribute($reader->name, $reader->value);
    }
    $reader->moveToElement();
  }
}

function ozon_import_write_text_el(XMLWriter $writer, string $tag, string $value): void
{
  $value = trim((string)$value);
  if ($tag === '' || $value === '') return;
  $writer->startElement($tag);
  if ($tag === 'description') {
    $writer->writeCData($value);
  } else {
    $writer->text($value);
  }
  $writer->endElement();
}

function ozon_import_write_updates(XMLWriter $writer, ?array $data): array
{
  if (!$data) return ['tags' => 0, 'pictures' => 0, 'params' => 0];

  $writtenTags = 0;
  $writtenPictures = 0;
  $writtenParams = 0;
  $tagOrder = ['url', 'price', 'currencyId', 'categoryId', 'vendor', 'vendorCode', 'model', 'same_model', 'name', 'description', 'weight', 'dimensions', 'hashtags', 'barcode', 'ozon_category'];

  foreach ($tagOrder as $tag) {
    if (!isset($data['tags'][$tag])) continue;
    $v = trim((string)$data['tags'][$tag]);
    if ($v === '') continue;
    ozon_import_write_text_el($writer, $tag, $v);
    $writtenTags++;
  }

  foreach (($data['pictures'] ?? []) as $pic) {
    $pic = trim((string)$pic);
    if ($pic === '') continue;
    ozon_import_write_text_el($writer, 'picture', $pic);
    $writtenPictures++;
  }

  foreach (($data['params'] ?? []) as $row) {
    $name = ozon_import_clean_name((string)($row['name'] ?? ''));
    if ($name === '') continue;
    $values = array_values(array_filter(array_map('trim', (array)($row['values'] ?? [])), fn($x) => $x !== ''));
    if (!$values) continue;
    $seen = [];
    foreach ($values as $v) {
      $key = ozon_import_lc((string)$v);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $writer->startElement('param');
      $writer->writeAttribute('name', $name);
      $writer->text((string)$v);
      $writer->endElement();
      $writtenParams++;
    }
  }

  return ['tags' => $writtenTags, 'pictures' => $writtenPictures, 'params' => $writtenParams];
}

function ozon_import_add_param(array &$data, string $rawName, string $cellValue): void
{
  $name = ozon_import_clean_name($rawName);
  $norm = ozon_import_norm_header($name);
  if ($name === '' || $norm === '') return;
  $values = ozon_import_split_values($cellValue);
  if (!$values) return;
  if (!isset($data['params'][$norm])) {
    $data['params'][$norm] = ['name' => $name, 'values' => []];
  }
  foreach ($values as $v) {
    $data['params'][$norm]['values'][] = $v;
  }
  $data['remove_params'][$norm] = true;
}

$datasetId = (int)($_POST['dataset_id'] ?? 0);
$error = '';
$result = null;
$datasetLock = null;

try {
  if ($datasetId <= 0) throw new RuntimeException('Bad dataset_id.');
  if (empty($_FILES['xlsxfile']) || ($_FILES['xlsxfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Файл Ozon-шаблона не загружен.');
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

  $xmlPath = ozon_import_safe_realpath_under($cfg['paths']['uploads_dir'] ?? null, (string)$ds['stored_path']);
  $datasetLock = feedtools_dataset_lock_acquire($datasetId, null, 'import_ozon_template_xlsx');

  try {
    $spreadsheet = IOFactory::load((string)$_FILES['xlsxfile']['tmp_name']);
  } catch (Throwable $e) {
    throw new RuntimeException('Не удалось прочитать XLSX: ' . $e->getMessage());
  }

  $sheet = $spreadsheet->getSheetByName('Шаблон');
  if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

  $HEADER_ROW = 2;
  $DATA_START_ROW = 5;
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $highestRow = (int)$sheet->getHighestRow();

  $cols = [];
  $colOfferId = null;
  for ($c = 1; $c <= $highestColIndex; $c++) {
    $raw = ozon_import_cell_string($sheet, $c, $HEADER_ROW);
    if ($raw === '') continue;
    $hn = ozon_import_norm_header($raw);
    $cols[$c] = ['raw' => $raw, 'norm' => $hn];
    if ($hn === ozon_import_norm_header('Артикул') || $hn === ozon_import_norm_header('Партномер')) $colOfferId = $c;
  }
  if (!$cols) throw new RuntimeException('Не найдены заголовки Ozon-шаблона в строке 2.');
  if (!$colOfferId) throw new RuntimeException('Не найдена колонка "Артикул".');

  $SKIP = array_fill_keys(array_map('ozon_import_norm_header', [
    '№',
    'Цена до скидки, руб.',
    'НДС, %',
    'НДС, %*',
    'Рассрочка',
    'Баллы за отзывы',
    'SKU',
    'Ссылки на фото 360',
    'Артикул фото',
    'Минимальное количество оптом',
    'ТН ВЭД коды ЕАЭС',
    'Ошибка',
    'Предупреждение',
    'Количество заводских упаковок',
    'Наличие серийного номера',
    'Гарантийный срок',
    'Срок службы, лет',
    'Страна-изготовитель',
    'Класс опасности товара',
    'Срок годности в днях',
    'Гарантия на товар, мес.',
    'Признак 18+',
    'Признак 18+*',
  ]), true);

  $H = [
    ozon_import_norm_header('Артикул') => 'offer_id',
    ozon_import_norm_header('Партномер') => 'offer_id',
    ozon_import_norm_header('Штрихкод (Серийный номер / EAN)') => 'tag:barcode',
    ozon_import_norm_header('Штрихкод') => 'tag:barcode',
    ozon_import_norm_header('Тип*') => 'ozon_category_from_type',
    ozon_import_norm_header('Тип') => 'ozon_category_from_type',
    ozon_import_norm_header('Название товара') => 'tag:name',
    ozon_import_norm_header('Цена, руб.*') => 'tag:price',
    ozon_import_norm_header('Цена, руб.') => 'tag:price',
    ozon_import_norm_header('Вес в упаковке, г*') => 'weight_g',
    ozon_import_norm_header('Вес в упаковке, г') => 'weight_g',
    ozon_import_norm_header('Вес товара, г') => 'weight_g',
    ozon_import_norm_header('Длина упаковки, мм*') => 'dim:L',
    ozon_import_norm_header('Длина упаковки, мм') => 'dim:L',
    ozon_import_norm_header('Ширина упаковки, мм*') => 'dim:W',
    ozon_import_norm_header('Ширина упаковки, мм') => 'dim:W',
    ozon_import_norm_header('Высота упаковки, мм*') => 'dim:H',
    ozon_import_norm_header('Высота упаковки, мм') => 'dim:H',
    ozon_import_norm_header('Ссылка на главное фото*') => 'pic:main',
    ozon_import_norm_header('Ссылка на главное фото') => 'pic:main',
    ozon_import_norm_header('Ссылки на дополнительные фото') => 'pic:extra',
    ozon_import_norm_header('Бренд*') => 'tag:vendor',
    ozon_import_norm_header('Бренд') => 'tag:vendor',
    ozon_import_norm_header('Название модели (для объединения в одну карточку)*') => 'tag:same_model',
    ozon_import_norm_header('Название модели (для объединения в одну карточку)') => 'tag:same_model',
    ozon_import_norm_header('Объединить в похожие товары') => 'tag:same_model',
    ozon_import_norm_header('Название группы') => 'tag:same_model',
    ozon_import_norm_header('Аннотация') => 'tag:description',
    ozon_import_norm_header('#Хештеги') => 'tag:hashtags',
    ozon_import_norm_header('Количество товара в УЕИ') => 'param:количество_в_единице_товара',
    ozon_import_norm_header('Количество в упаковке, шт') => 'param:количество_в_единице_товара',
    ozon_import_norm_header('Единиц в одном товаре') => 'param:количество_в_единице_товара',
    ozon_import_norm_header('Цвет товара') => 'param:цвет',
    ozon_import_norm_header('Название цвета') => 'param:цвет',
  ];

  $updates = [];
  $rowsRead = 0;

  for ($r = $DATA_START_ROW; $r <= $highestRow; $r++) {
    $offerId = ozon_import_cell_string($sheet, (int)$colOfferId, $r);
    if ($offerId === '') continue;

    $data = $updates[$offerId] ?? [
      'tags' => [],
      'remove_tags' => [],
      'pictures' => null,
      'replace_pictures' => false,
      'params' => [],
      'remove_params' => [],
    ];
    $dim = ['L' => '', 'W' => '', 'H' => ''];
    $pictures = [];
    $hasAnyValue = false;

    foreach ($cols as $colIndex => $col) {
      if ($colIndex === $colOfferId) continue;
      $rawHeader = (string)$col['raw'];
      $hn = (string)$col['norm'];
      if ($hn === '' || isset($SKIP[$hn])) continue;

      $cellVal = ozon_import_cell_string($sheet, (int)$colIndex, $r);
      if ($cellVal === '') continue;
      $hasAnyValue = true;

      $rule = $H[$hn] ?? null;
      if ($rule === 'ozon_category_from_type') {
        $pair = ozon_import_find_category_pair_by_type($cellVal);
        if ($pair !== '') {
          $data['tags']['ozon_category'] = $pair;
          $data['remove_tags']['ozon_category'] = true;
        }
        continue;
      }
      if ($rule === 'weight_g') {
        $kg = ozon_import_grams_to_kg($cellVal);
        if ($kg !== '') {
          $data['tags']['weight'] = $kg;
          $data['remove_tags']['weight'] = true;
        }
        continue;
      }
      if ($rule && strpos($rule, 'dim:') === 0) {
        $dim[substr($rule, 4)] = preg_replace('/\s+/u', '', $cellVal);
        continue;
      }
      if ($rule === 'pic:main' || $rule === 'pic:extra') {
        foreach (ozon_import_split_pictures($cellVal) as $pic) $pictures[] = $pic;
        continue;
      }
      if ($rule && strpos($rule, 'tag:') === 0) {
        $tag = substr($rule, 4);
        $data['tags'][$tag] = $tag === 'hashtags' ? ozon_import_norm_hashtags($cellVal) : $cellVal;
        $data['remove_tags'][$tag] = true;
        continue;
      }
      if ($rule && strpos($rule, 'param:') === 0) {
        ozon_import_add_param($data, substr($rule, 6), $cellVal);
        continue;
      }

      ozon_import_add_param($data, $rawHeader, $cellVal);
    }

    if ($dim['L'] !== '' || $dim['W'] !== '' || $dim['H'] !== '') {
      $data['tags']['dimensions'] = $dim['L'] . '/' . $dim['W'] . '/' . $dim['H'];
      $data['remove_tags']['dimensions'] = true;
    }
    if ($pictures) {
      $seen = [];
      $uniq = [];
      foreach ($pictures as $pic) {
        if (isset($seen[$pic])) continue;
        $seen[$pic] = true;
        $uniq[] = $pic;
      }
      $data['pictures'] = $uniq;
      $data['replace_pictures'] = true;
    }

    if ($hasAnyValue || !empty($data['params'])) {
      $updates[$offerId] = $data;
      $rowsRead++;
    }
  }

  if (!$updates) throw new RuntimeException('В XLSX не найдено строк с данными для импорта.');

  $outputsDir = rtrim((string)$cfg['paths']['outputs_dir'], '/\\');
  if (!is_dir($outputsDir) && !mkdir($outputsDir, 0775, true)) {
    throw new RuntimeException('Не удалось создать outputs_dir.');
  }
  $runDir = $outputsDir . '/ozon_import_' . $datasetId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
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
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $skipDepth) $skipDepth = -1;
      continue;
    }

    switch ($reader->nodeType) {
      case XMLReader::ELEMENT: {
        $tag = $reader->localName ?: $reader->name;
        if ($tag === 'offer') {
          $inOffer = true;
          $offerDepth = $reader->depth;
          $offerId = trim((string)$reader->getAttribute('id'));
          $curUpdate = ($offerId !== '' && isset($updates[$offerId])) ? $updates[$offerId] : null;
          if ($curUpdate) $matchedOffers++;
          ozon_import_xml_start($writer, $reader);
          if ($reader->isEmptyElement) {
            $stats = ozon_import_write_updates($writer, $curUpdate);
            if ($curUpdate) {
              $updatedOffers++;
              $writtenTags += $stats['tags'];
              $writtenPictures += $stats['pictures'];
              $writtenParams += $stats['params'];
            }
            $writer->endElement();
            $inOffer = false;
            $offerDepth = -1;
            $curUpdate = null;
          }
          break;
        }

        if ($inOffer && $curUpdate) {
          if ($tag === 'param') {
            $pn = ozon_import_norm_header(ozon_import_clean_name((string)$reader->getAttribute('name')));
            if ($pn !== '' && isset($curUpdate['remove_params'][$pn])) {
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

        ozon_import_xml_start($writer, $reader);
        if ($reader->isEmptyElement) $writer->endElement();
        break;
      }

      case XMLReader::END_ELEMENT: {
        $tag = $reader->localName ?: $reader->name;
        if ($inOffer && $tag === 'offer' && $reader->depth === $offerDepth) {
          $stats = ozon_import_write_updates($writer, $curUpdate);
          if ($curUpdate) {
            $updatedOffers++;
            $writtenTags += $stats['tags'];
            $writtenPictures += $stats['pictures'];
            $writtenParams += $stats['params'];
          }
          $writer->endElement();
          $inOffer = false;
          $offerDepth = -1;
          $curUpdate = null;
          break;
        }
        $writer->endElement();
        break;
      }

      case XMLReader::TEXT:
      case XMLReader::SIGNIFICANT_WHITESPACE:
      case XMLReader::WHITESPACE:
        $writer->text($reader->value);
        break;

      case XMLReader::CDATA:
        $writer->writeCData($reader->value);
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
  $tmpAbs = $dstDir . '/.tmp_ozon_import_' . $datasetId . '_' . basename($xmlPath);
  if (!copy($outXmlAbs, $tmpAbs)) throw new RuntimeException('Не удалось записать временный XML.');
  if (!rename($tmpAbs, $xmlPath)) {
    @unlink($tmpAbs);
    throw new RuntimeException('Не удалось заменить XML датасета.');
  }

  $scan = scan_xml($xmlPath, (int)($cfg['limits']['sample_offers'] ?? 5));
  $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);
  $bytesXml = (int)filesize($xmlPath);

  $upd = db()->prepare("UPDATE feedtools_datasets SET bytes = ?, sha256 = ?, offers_count = ?, warnings_json = ? WHERE id = ?");
  $upd->execute([$bytesXml, $newSha, (int)$scan['offers_count'], $warningsJson, $datasetId]);

  $result = [
    'dataset_id' => $datasetId,
    'rows_read' => $rowsRead,
    'xlsx_articles' => count($updates),
    'matched_offers' => $matchedOffers,
    'updated_offers' => $updatedOffers,
    'written_tags' => $writtenTags,
    'written_pictures' => $writtenPictures,
    'written_params' => $writtenParams,
    'removed_tags' => $removedTags,
    'removed_pictures' => $removedPictures,
    'removed_params' => $removedParams,
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
  <title>Импорт Ozon XLSX</title>
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
    <h1>Импорт Ozon XLSX</h1>

    <?php if ($error): ?>
      <p class="err"><b>Ошибка:</b> <?= h($error) ?></p>
      <p class="muted">Проверь, что загружен заполненный Ozon-шаблон с листом «Шаблон» и колонкой «Артикул».</p>
    <?php elseif ($result): ?>
      <p class="ok"><b>Готово.</b> Текущий датасет обновлен.</p>
      <table>
        <tr><th>Строк XLSX прочитано</th><td><?= h((string)$result['rows_read']) ?></td></tr>
        <tr><th>Артикулов в XLSX</th><td><?= h((string)$result['xlsx_articles']) ?></td></tr>
        <tr><th>Найдено товаров в датасете</th><td><?= h((string)$result['matched_offers']) ?></td></tr>
        <tr><th>Записано обычных тегов</th><td><?= h((string)$result['written_tags']) ?></td></tr>
        <tr><th>Записано фото</th><td><?= h((string)$result['written_pictures']) ?></td></tr>
        <tr><th>Записано Ozon-характеристик</th><td><?= h((string)$result['written_params']) ?></td></tr>
        <tr><th>Backup XML</th><td><code><?= h((string)$result['backup']) ?></code></td></tr>
      </table>
      <p><a href="view.php?id=<?= h((string)$result['dataset_id']) ?>">Открыть датасет</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
