<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/xml_scan.php';
require_once __DIR__ . '/supplier_products.php';

function supplier_import_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function supplier_import_norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = str_replace('ё', 'е', supplier_import_lc($s));
  $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function supplier_import_cell_addr(int $col, int $row): string
{
  return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
}

function supplier_import_cell_string(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $row): string
{
  $cell = $sheet->getCell(supplier_import_cell_addr($col, $row));
  $value = $cell->getCalculatedValue();
  if ($value === null || $value === '') {
    $value = $cell->getFormattedValue();
  }
  if (is_float($value)) {
    $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
  }
  return trim((string)$value);
}

function supplier_import_count_filled_rows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $startRow): int
{
  $count = 0;
  $highestRow = (int)$sheet->getHighestRow();
  for ($row = $startRow; $row <= $highestRow; $row++) {
    if (supplier_import_cell_string($sheet, $col, $row) !== '') {
      $count++;
    }
  }
  return $count;
}

function supplier_import_detect_ozon_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?array
{
  $sheet = $spreadsheet->getSheetByName('Шаблон') ?: $spreadsheet->getActiveSheet();
  $headerRow = 2;
  $dataStartRow = 5;
  $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $articleCol = null;
  $signals = 0;
  $signalHeaders = array_fill_keys(array_map('supplier_import_norm_header', [
    'Тип',
    'Тип*',
    'Название товара',
    'Цена, руб.',
    'Цена, руб.*',
    'Ссылка на главное фото',
    'Ссылка на главное фото*',
    'Бренд',
    'Бренд*',
    'Аннотация',
  ]), true);

  for ($col = 1; $col <= $highestColIndex; $col++) {
    $hn = supplier_import_norm_header(supplier_import_cell_string($sheet, $col, $headerRow));
    if ($hn === '') continue;
    if ($hn === supplier_import_norm_header('Артикул') || $hn === supplier_import_norm_header('Партномер')) {
      $articleCol = $col;
    }
    if (isset($signalHeaders[$hn])) {
      $signals++;
    }
  }

  if (!$articleCol || $signals < 2) {
    return null;
  }

  return [
    'type' => 'ozon_template_xlsx',
    'label' => 'Заполненный шаблон Ozon',
    'sheet' => $sheet->getTitle(),
    'header_row' => $headerRow,
    'data_start_row' => $dataStartRow,
    'items_count' => supplier_import_count_filled_rows($sheet, $articleCol, $dataStartRow),
    'score' => $signals + 3,
  ];
}

function supplier_import_detect_wb_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?array
{
  $sheet = $spreadsheet->getSheetByName('Товары') ?: $spreadsheet->getActiveSheet();
  $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $maxRow = min((int)$sheet->getHighestRow(), 30);
  $headerRow = 0;
  $articleCol = null;
  $signals = 0;
  $signalHeaders = array_fill_keys(array_map('supplier_import_norm_header', [
    'Наименование',
    'Бренд',
    'Описание',
    'Фото',
    'Цена',
    'Баркоды',
    'Категория продавца',
  ]), true);

  for ($row = 1; $row <= $maxRow; $row++) {
    $rowArticleCol = null;
    $foundName = false;
    $rowSignals = 0;
    for ($col = 1; $col <= $highestColIndex; $col++) {
      $hn = supplier_import_norm_header(supplier_import_cell_string($sheet, $col, $row));
      if ($hn === '') continue;
      if ($hn === supplier_import_norm_header('Артикул продавца')) {
        $rowArticleCol = $col;
      }
      if ($hn === supplier_import_norm_header('Наименование')) {
        $foundName = true;
      }
      if (isset($signalHeaders[$hn])) {
        $rowSignals++;
      }
    }
    if ($rowArticleCol && $foundName) {
      $headerRow = $row;
      $articleCol = $rowArticleCol;
      $signals = $rowSignals;
      break;
    }
  }

  if (!$articleCol || $headerRow <= 0) {
    return null;
  }

  $dataStartRow = $headerRow + 2;
  return [
    'type' => 'wb_template_xlsx',
    'label' => 'Заполненный шаблон WB',
    'sheet' => $sheet->getTitle(),
    'header_row' => $headerRow,
    'data_start_row' => $dataStartRow,
    'items_count' => supplier_import_count_filled_rows($sheet, $articleCol, $dataStartRow),
    'score' => $signals + 4,
  ];
}

function supplier_import_check_xlsx(string $path): array
{
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (!is_file($autoload)) {
    throw new RuntimeException('Не найден vendor/autoload.php для чтения XLSX.');
  }
  require_once $autoload;

  try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
  } catch (Throwable $e) {
    throw new RuntimeException('Не удалось прочитать XLSX: ' . $e->getMessage());
  }

  $ozon = supplier_import_detect_ozon_xlsx($spreadsheet);
  $wb = supplier_import_detect_wb_xlsx($spreadsheet);

  if ($wb && (!$ozon || (int)$wb['score'] >= (int)$ozon['score'])) {
    return $wb;
  }
  if ($ozon) {
    return $ozon;
  }

  throw new RuntimeException('Не удалось определить тип XLSX: не найден шаблон Ozon или WB.');
}

function supplier_import_check_offer_id_map_csv(string $path): array
{
  $csv = supplier_products_offer_id_remap_read_csv($path);
  return [
    'type' => 'offer_id_map_csv',
    'label' => 'CSV-карта замены артикулов',
    'items_count' => count((array)($csv['map'] ?? [])),
    'rows_total' => (int)($csv['rows_total'] ?? 0),
    'warnings' => array_values(array_filter([
      ((int)($csv['invalid'] ?? 0) > 0) ? ('Строк с пустыми значениями: ' . (int)$csv['invalid']) : '',
      ((int)($csv['duplicates'] ?? 0) > 0) ? ('Дублей старого артикула с разными новыми значениями: ' . (int)$csv['duplicates']) : '',
    ])),
  ];
}

function supplier_import_check_uploaded_file(array $file, array $cfg): array
{
  $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($errorCode !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Файл не загружен или произошла ошибка загрузки.');
  }

  $tmpPath = (string)($file['tmp_name'] ?? '');
  $originalName = (string)($file['name'] ?? '');
  $bytes = (int)($file['size'] ?? 0);
  if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    throw new RuntimeException('Не удалось прочитать загруженный файл.');
  }
  if ($bytes <= 0) {
    throw new RuntimeException('Пустой файл.');
  }
  if ($bytes > (int)($cfg['limits']['max_upload_bytes'] ?? 50_000_000)) {
    throw new RuntimeException('Файл слишком большой.');
  }

  $ext = supplier_import_lc(pathinfo($originalName, PATHINFO_EXTENSION));
  if (in_array($ext, ['xml', 'yml'], true)) {
    $scan = scan_xml($tmpPath, 0);
    return [
      'type' => 'feed_xml',
      'label' => 'XML-фид',
      'items_count' => (int)($scan['offers_count'] ?? 0),
      'warnings' => (array)($scan['warnings'] ?? []),
    ];
  }

  if ($ext === 'xlsx') {
    return supplier_import_check_xlsx($tmpPath);
  }

  if ($ext === 'csv') {
    return supplier_import_check_offer_id_map_csv($tmpPath);
  }

  throw new RuntimeException('Поддерживаются XML/YML-фиды, XLSX-шаблоны Ozon/WB и CSV-карты замены артикулов.');
}

function supplier_import_upload_storage_dir(array $cfg): string
{
  $uploadsDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? (dirname(__DIR__) . '/storage/uploads')), '/\\');
  $dir = $uploadsDir . '/supplier_import_sources';
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Не удалось создать папку для временного источника импорта.');
  }
  return $dir;
}

function supplier_import_store_uploaded_source(array $file, array $cfg): array
{
  $tmpPath = (string)($file['tmp_name'] ?? '');
  $originalName = basename((string)($file['name'] ?? 'source'));
  if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    throw new RuntimeException('Не удалось сохранить загруженный источник.');
  }
  $ext = supplier_import_lc(pathinfo($originalName, PATHINFO_EXTENSION));
  if (!in_array($ext, ['xml', 'yml', 'xlsx', 'csv'], true)) {
    throw new RuntimeException('Поддерживаются XML/YML-фиды, XLSX-шаблоны Ozon/WB и CSV-карты замены артикулов.');
  }
  $token = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
  $dst = supplier_import_upload_storage_dir($cfg) . '/' . $token;
  if (!move_uploaded_file($tmpPath, $dst)) {
    throw new RuntimeException('Не удалось сохранить загруженный источник.');
  }
  @chmod($dst, 0664);
  return [
    'token' => $token,
    'path' => $dst,
    'name' => $originalName,
  ];
}

function supplier_import_stored_source_path(string $token, array $cfg): string
{
  $token = basename(trim($token));
  if ($token === '' || !preg_match('~^[A-Za-z0-9_.-]+$~', $token)) {
    throw new RuntimeException('Сохранённый источник импорта не найден.');
  }
  $dir = supplier_import_upload_storage_dir($cfg);
  $path = $dir . '/' . $token;
  $realDir = realpath($dir);
  $realPath = realpath($path);
  if (!$realDir || !$realPath || strpos($realPath, $realDir) !== 0 || !is_file($realPath)) {
    throw new RuntimeException('Сохранённый источник импорта не найден.');
  }
  return $realPath;
}

function supplier_import_clean_header(string $s): string
{
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function supplier_import_split_values(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];
  $parts = strpos($s, ';') === false ? [$s] : explode(';', $s);
  $out = [];
  $seen = [];
  foreach ($parts as $part) {
    $part = trim(preg_replace('/\s+/u', ' ', (string)$part));
    if ($part === '') continue;
    $key = supplier_import_lc($part);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $part;
  }
  return $out;
}

function supplier_import_split_pictures(string $s): array
{
  $s = trim((string)$s);
  if ($s === '') return [];
  $s = str_replace(["\r\n", "\r", "\n", "\t"], ';', $s);
  $parts = preg_split('/\s*[;\s]\s*/u', $s) ?: [];
  $out = [];
  $seen = [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '' || isset($seen[$part])) continue;
    $seen[$part] = true;
    $out[] = $part;
  }
  return $out;
}

function supplier_import_num_str(float $n): string
{
  $s = number_format($n, 3, '.', '');
  $s = preg_replace('/\.?0+$/', '', $s);
  return $s === '-0' ? '0' : (string)$s;
}

function supplier_import_grams_to_kg(string $g): string
{
  $g = trim(str_replace(',', '.', $g));
  if ($g === '' || !is_numeric($g)) return '';
  return supplier_import_num_str(((float)$g) / 1000.0);
}

function supplier_import_cm_to_mm(string $cm): string
{
  $cm = trim(str_replace(',', '.', $cm));
  if ($cm === '' || !is_numeric($cm)) return '';
  return supplier_import_num_str(((float)$cm) * 10.0);
}

function supplier_import_find_ozon_category_pair_by_type(string $type): string
{
  static $cache = [];
  $type = trim($type);
  if ($type === '') return '';
  $key = supplier_import_lc($type);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $sql = "SELECT ozon_parent_id, ozon_leaf_id
          FROM feedtools_taxonomy_categories
          WHERE source='ozon' AND (full_path = ? OR name = ?)
          ORDER BY is_leaf DESC, level DESC
          LIMIT 1";
  $st = db()->prepare($sql);
  $st->execute([$type, $type]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $st = db()->prepare("
      SELECT ozon_parent_id, ozon_leaf_id
      FROM feedtools_taxonomy_categories
      WHERE source='ozon' AND (full_path LIKE ? OR name LIKE ?)
      ORDER BY is_leaf DESC, level DESC
      LIMIT 1
    ");
    $like = '%' . $type . '%';
    $st->execute([$like, $like]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }
  $parent = trim((string)($row['ozon_parent_id'] ?? ''));
  $leaf = trim((string)($row['ozon_leaf_id'] ?? ''));
  $cache[$key] = ($parent !== '' && $leaf !== '') ? ($parent . '_' . $leaf) : '';
  return $cache[$key];
}

function supplier_import_add_param_values(array &$params, string $name, string $value): void
{
  $name = supplier_import_clean_header($name);
  if ($name === '') return;
  $values = supplier_import_split_values($value);
  if (!$values) return;
  if (!isset($params[$name])) $params[$name] = [];
  foreach ($values as $v) $params[$name][] = $v;
}

function supplier_import_xlsx_to_source_data(int $supplierId, string $path, array $typeInfo, array $cfg): array
{
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (!is_file($autoload)) {
    throw new RuntimeException('Не найден vendor/autoload.php для чтения XLSX.');
  }
  require_once $autoload;

  $supplier = suppliers_get($supplierId, $cfg);
  if (!is_array($supplier)) {
    throw new RuntimeException('Поставщик не найден.');
  }
  $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
  $parseOptions = supplier_products_parse_options_for_supplier($supplier, $cfg);

  $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
  $type = (string)($typeInfo['type'] ?? '');
  $sheet = $type === 'wb_template_xlsx'
    ? ($spreadsheet->getSheetByName('Товары') ?: $spreadsheet->getActiveSheet())
    : ($spreadsheet->getSheetByName('Шаблон') ?: $spreadsheet->getActiveSheet());
  $headerRow = (int)($typeInfo['header_row'] ?? ($type === 'wb_template_xlsx' ? 1 : 2));
  $dataStartRow = (int)($typeInfo['data_start_row'] ?? ($headerRow + 1));
  $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $highestRow = (int)$sheet->getHighestRow();

  $cols = [];
  $articleCol = null;
  for ($col = 1; $col <= $highestColIndex; $col++) {
    $raw = supplier_import_cell_string($sheet, $col, $headerRow);
    if ($raw === '') continue;
    $norm = supplier_import_norm_header($raw);
    $cols[$col] = ['raw' => $raw, 'norm' => $norm];
    if ($type === 'wb_template_xlsx') {
      if ($norm === supplier_import_norm_header('Артикул продавца')) $articleCol = $col;
    } else {
      if ($norm === supplier_import_norm_header('Артикул') || $norm === supplier_import_norm_header('Партномер')) $articleCol = $col;
    }
  }
  if (!$articleCol) {
    throw new RuntimeException('В XLSX не найдена колонка артикула.');
  }

  $ozonMap = [
    supplier_import_norm_header('Название товара') => 'tag:name',
    supplier_import_norm_header('Аннотация') => 'tag:description',
    supplier_import_norm_header('Бренд') => 'tag:vendor',
    supplier_import_norm_header('Бренд*') => 'tag:vendor',
    supplier_import_norm_header('Цена, руб.') => 'tag:price_original',
    supplier_import_norm_header('Цена, руб.*') => 'tag:price_original',
    supplier_import_norm_header('Вес в упаковке, г') => 'weight_g',
    supplier_import_norm_header('Вес в упаковке, г*') => 'weight_g',
    supplier_import_norm_header('Вес товара, г') => 'weight_g',
    supplier_import_norm_header('Длина упаковки, мм') => 'dim:L',
    supplier_import_norm_header('Длина упаковки, мм*') => 'dim:L',
    supplier_import_norm_header('Ширина упаковки, мм') => 'dim:W',
    supplier_import_norm_header('Ширина упаковки, мм*') => 'dim:W',
    supplier_import_norm_header('Высота упаковки, мм') => 'dim:H',
    supplier_import_norm_header('Высота упаковки, мм*') => 'dim:H',
    supplier_import_norm_header('Ссылка на главное фото') => 'pictures',
    supplier_import_norm_header('Ссылка на главное фото*') => 'pictures',
    supplier_import_norm_header('Ссылки на дополнительные фото') => 'pictures',
    supplier_import_norm_header('Штрихкод') => 'tag:barcode',
    supplier_import_norm_header('Штрихкод (Серийный номер / EAN)') => 'tag:barcode',
    supplier_import_norm_header('#Хештеги') => 'tag:hashtags',
    supplier_import_norm_header('Фишка') => 'feature',
    supplier_import_norm_header('Фишки') => 'feature',
    supplier_import_norm_header('Фишка 1') => 'feature',
    supplier_import_norm_header('Фишка 2') => 'feature',
    supplier_import_norm_header('Фишка 3') => 'feature',
    supplier_import_norm_header('Feature') => 'feature',
    supplier_import_norm_header('Feature 1') => 'feature',
    supplier_import_norm_header('Feature 2') => 'feature',
    supplier_import_norm_header('Feature 3') => 'feature',
    supplier_import_norm_header('Тип') => 'ozon_category_from_type',
    supplier_import_norm_header('Тип*') => 'ozon_category_from_type',
  ];
  $wbMap = [
    supplier_import_norm_header('Наименование') => 'tag:name',
    supplier_import_norm_header('Бренд') => 'tag:vendor',
    supplier_import_norm_header('Описание') => 'tag:description',
    supplier_import_norm_header('Фото') => 'pictures',
    supplier_import_norm_header('Цена') => 'tag:price_original',
    supplier_import_norm_header('Баркоды') => 'tag:barcode',
    supplier_import_norm_header('Вес с упаковкой (кг)') => 'tag:weight',
    supplier_import_norm_header('Вес товара с упаковкой (г)') => 'weight_g',
    supplier_import_norm_header('Высота упаковки') => 'dim:Hcm',
    supplier_import_norm_header('Длина упаковки') => 'dim:Lcm',
    supplier_import_norm_header('Ширина упаковки') => 'dim:Wcm',
    supplier_import_norm_header('Фишка') => 'feature',
    supplier_import_norm_header('Фишки') => 'feature',
    supplier_import_norm_header('Фишка 1') => 'feature',
    supplier_import_norm_header('Фишка 2') => 'feature',
    supplier_import_norm_header('Фишка 3') => 'feature',
    supplier_import_norm_header('Feature') => 'feature',
    supplier_import_norm_header('Feature 1') => 'feature',
    supplier_import_norm_header('Feature 2') => 'feature',
    supplier_import_norm_header('Feature 3') => 'feature',
  ];
  $skip = array_fill_keys(array_map('supplier_import_norm_header', [
    '№', 'Артикул', 'Партномер', 'Артикул продавца', 'Артикул WB', 'SKU', 'Ошибка', 'Предупреждение',
    'Группа', 'Категория продавца', 'Видео', 'КИЗ', '18+', 'НДС, %', 'НДС, %*',
  ]), true);

  $records = [];
  $seenOfferKeys = [];
  $sortOrder = 0;
  for ($row = $dataStartRow; $row <= $highestRow; $row++) {
    $rawOfferId = supplier_import_cell_string($sheet, (int)$articleCol, $row);
    if ($rawOfferId === '') continue;

    $tags = [];
    $pictures = [];
    $params = [];
    $wbParams = [];
    $features = [];
    $dim = ['L' => '', 'W' => '', 'H' => ''];

    foreach ($cols as $col => $meta) {
      if ($col === $articleCol) continue;
      $value = supplier_import_cell_string($sheet, (int)$col, $row);
      if ($value === '') continue;
      $norm = (string)$meta['norm'];
      if ($norm === '' || isset($skip[$norm])) continue;
      $raw = (string)$meta['raw'];
      $rule = $type === 'wb_template_xlsx' ? ($wbMap[$norm] ?? null) : ($ozonMap[$norm] ?? null);

      if ($rule === 'pictures') {
        foreach (supplier_import_split_pictures($value) as $picture) $pictures[] = $picture;
      } elseif ($rule === 'feature' || supplier_products_is_feature_source_field_name($raw)) {
        if (count($features) < SUPPLIER_PRODUCTS_FEATURE_MAX) {
          $features[] = $value;
        }
      } elseif ($rule === 'weight_g') {
        $kg = supplier_import_grams_to_kg($value);
        if ($kg !== '') $tags['weight'] = $kg;
      } elseif ($rule === 'ozon_category_from_type') {
        $pair = supplier_import_find_ozon_category_pair_by_type($value);
        if ($pair !== '') $tags['ozon_category'] = $pair;
      } elseif (is_string($rule) && str_starts_with($rule, 'dim:')) {
        $axis = substr($rule, 4, 1);
        $dim[$axis] = str_ends_with($rule, 'cm') ? supplier_import_cm_to_mm($value) : preg_replace('/\s+/u', '', $value);
      } elseif (is_string($rule) && str_starts_with($rule, 'tag:')) {
        $tags[substr($rule, 4)] = $value;
      } else {
        if ($type === 'wb_template_xlsx') {
          supplier_import_add_param_values($wbParams, $raw, $value);
        } else {
          supplier_import_add_param_values($params, $raw, $value);
        }
      }
    }

    if ($dim['L'] !== '' || $dim['W'] !== '' || $dim['H'] !== '') {
      $tags['dimensions'] = $dim['L'] . '/' . $dim['W'] . '/' . $dim['H'];
    }

    $sortOrder++;
    $codedOfferId = suppliers_apply_supplier_code($rawOfferId, $supplierCode);
    $parsed = supplier_products_parsed_from_source_parts(
      $codedOfferId,
      $tags,
      array_values(array_unique($pictures)),
      $params,
      $wbParams,
      $features,
      $parseOptions
    );
    $baseKey = $codedOfferId !== '' ? $codedOfferId : ('__empty_' . $sortOrder);
    $seenOfferKeys[$baseKey] = (int)($seenOfferKeys[$baseKey] ?? 0) + 1;
    $offerKey = $baseKey;
    if ($seenOfferKeys[$baseKey] > 1) {
      $offerKey .= '__dup_' . $seenOfferKeys[$baseKey];
    }
    $records[] = [
      'sort_order' => $sortOrder,
      'offer_key' => mb_substr($offerKey, 0, 191, 'UTF-8'),
      'offer_id' => mb_substr($codedOfferId, 0, 191, 'UTF-8'),
      'raw_offer_id' => $rawOfferId,
      'parsed' => $parsed,
      'category_path' => '',
    ];
  }

  return [
    'records' => $records,
    'categories_json' => '',
    'source_sha256' => hash_file('sha256', $path) ?: '',
    'source_bytes' => (int)(@filesize($path) ?: 0),
    'warnings_json' => '[]',
    'offers_count' => count($records),
    'source_type' => $type,
  ];
}


function supplier_import_source_data_from_file(int $supplierId, string $path, string $name, array $cfg = [], ?array $typeInfo = null): array
{
  $path = trim($path);
  if ($path === '' || !is_file($path)) {
    throw new RuntimeException('Источник импорта не найден.');
  }

  $ext = supplier_import_lc(pathinfo($name !== '' ? $name : $path, PATHINFO_EXTENSION));
  if ($ext === 'xlsx') {
    $typeInfo = is_array($typeInfo) ? $typeInfo : supplier_import_check_xlsx($path);
    return supplier_import_xlsx_to_source_data($supplierId, $path, $typeInfo, $cfg);
  }
  if (in_array($ext, ['xml', 'yml', ''], true)) {
    return supplier_products_source_records_from_feed_path($supplierId, $path, $cfg);
  }

  throw new RuntimeException('Поддерживаются XML/YML-фиды и XLSX-шаблоны Ozon/WB.');
}

function supplier_import_source_info_from_file(int $supplierId, string $path, string $name, array $cfg = [], bool $includeSourceData = false, ?array $typeInfo = null): array
{
  $path = trim($path);
  if ($path === '' || !is_file($path)) {
    throw new RuntimeException('Источник импорта не найден.');
  }

  $ext = supplier_import_lc(pathinfo($name !== '' ? $name : $path, PATHINFO_EXTENSION));
  if ($ext === 'xlsx') {
    $typeInfo = is_array($typeInfo) ? $typeInfo : supplier_import_check_xlsx($path);
    $info = $typeInfo;
    if ($includeSourceData) {
      $sourceData = supplier_import_xlsx_to_source_data($supplierId, $path, $typeInfo, $cfg);
      $info['items_count'] = (int)($sourceData['offers_count'] ?? count((array)($sourceData['records'] ?? [])));
      $info['source_data'] = $sourceData;
    }
    return $info;
  }

  if ($ext === 'csv') {
    return supplier_import_check_offer_id_map_csv($path);
  }

  if (in_array($ext, ['xml', 'yml', ''], true)) {
    if ($includeSourceData) {
      $sourceData = supplier_products_source_records_from_feed_path($supplierId, $path, $cfg);
      return [
        'type' => 'feed_xml',
        'label' => 'XML-фид',
        'items_count' => (int)($sourceData['offers_count'] ?? count((array)($sourceData['records'] ?? []))),
        'warnings' => json_decode((string)($sourceData['warnings_json'] ?? '[]'), true) ?: [],
        'source_data' => $sourceData,
      ];
    }

    $scan = scan_xml($path, 0);
    return [
      'type' => 'feed_xml',
      'label' => 'XML-фид',
      'items_count' => (int)($scan['offers_count'] ?? 0),
      'warnings' => (array)($scan['warnings'] ?? []),
    ];
  }

  throw new RuntimeException('Поддерживаются XML/YML-фиды и XLSX-шаблоны Ozon/WB.');
}
