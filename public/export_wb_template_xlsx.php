<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/wildberries/WbRichContent.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
  http_response_code(500);
  exit("Missing vendor/autoload.php. Run: composer install");
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

register_shutdown_function(static function (): void {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) return;
  error_log('feedtools WB export fatal: ' . json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});

function wb_export_current_actor(): string
{
  foreach (['PHP_AUTH_USER', 'REMOTE_USER'] as $key) {
    $value = trim((string)($_SERVER[$key] ?? ''));
    if ($value !== '') return $value;
  }
  return PHP_SAPI === 'cli' ? 'cli' : 'anonymous';
}

function wb_export_client_disconnected(): bool
{
  return function_exists('connection_aborted') && connection_aborted();
}

function wb_cell_addr(int $col, int $row): string
{
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function wb_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function wb_norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
  $s = str_replace('ё', 'е', wb_lc($s));
  $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_strip_html_to_text(string $html): string
{
  return wb_rich_content_description($html, '', wb_rich_content_description_limit());
}

function wb_trim_description_to_limit(string $text, int $limit = 2000): string
{
  return wb_rich_content_trim_description($text, $limit);
}

function wb_safe_realpath_under(?string $baseDir, string $path): string
{
  $real = realpath($path);
  if (!$real || !is_file($real)) {
    throw new RuntimeException('File not found');
  }
  if ($baseDir) {
    $base = realpath($baseDir);
    if ($base && strpos($real, $base) !== 0) {
      throw new RuntimeException('File is outside storage');
    }
  }
  return $real;
}

function wb_norm_spaces(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/[\r\n\t]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_param_values(array $map, string $name): array
{
  $key = wb_norm_header($name);
  $vals = $map[$key] ?? [];
  if (!$vals) return [];

  $out = [];
  $seen = [];
  foreach ((array)$vals as $v) {
    $v = wb_norm_spaces((string)$v);
    if ($v === '') continue;
    $dedupeKey = wb_lc($v);
    if (isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;
    $out[] = $v;
  }
  return $out;
}

function wb_param_value(array $map, string $name, string $sep = ';'): string
{
  $vals = wb_param_values($map, $name);
  return $vals ? implode($sep, $vals) : '';
}

function wb_video_cover_value(array $paramsMap, array $wbParamsMap): string
{
  foreach ([
    'WB.Видео: ссылка',
    'Wildberries.Video: link',
    'Озон.Видеообложка: ссылка',
    'Ozon.VideoCover: link',
    'Видеообложка',
    'Видео-обложка',
  ] as $name) {
    $value = wb_param_value($wbParamsMap, $name);
    if ($value === '') $value = wb_param_value($paramsMap, $name);
    if ($value !== '') return $value;
  }
  return '';
}

function wb_is_tnved_header(string $header): bool
{
  if (function_exists('supplier_products_is_tnved_characteristic_name')
    && supplier_products_is_tnved_characteristic_name($header)
  ) {
    return true;
  }
  $norm = wb_norm_header($header);
  return in_array($norm, [
    'тн вэд',
    'тн вэд коды еаэс',
    'тнвэд',
    'код тн вэд',
    'код тнвэд',
    'tn ved',
    'tnved',
    'tnved code',
  ], true);
}

function wb_tnved_code_from_text(string $value): string
{
  if (function_exists('supplier_products_tnved_code_from_text')) {
    $code = supplier_products_tnved_code_from_text($value);
    if ($code !== '') return $code;
  }
  $digits = preg_replace('~\D+~u', '', $value);
  return is_string($digits) && preg_match('~^\d{10}$~', $digits) ? $digits : '';
}

function wb_tnved_value(array $wbParamsMap, array $paramsMap, string $rawHeader): string
{
  $names = array_values(array_unique(array_filter([
    $rawHeader,
    'ТН ВЭД',
    'ТН ВЭД коды ЕАЭС',
    'ТНВЭД',
    'Код ТН ВЭД',
    'Код ТНВЭД',
    'TN VED',
    'TNVED',
    'TNVED code',
  ], static fn($value): bool => trim((string)$value) !== '')));
  foreach ([$wbParamsMap, $paramsMap] as $map) {
    foreach ($names as $name) {
      foreach (wb_param_values($map, (string)$name) as $value) {
        $code = wb_tnved_code_from_text((string)$value);
        if ($code !== '') return $code;
      }
    }
  }
  return '';
}

function wb_header_contains(string $normalizedHeader, string $needle): bool
{
  $needle = wb_norm_header($needle);
  return $needle !== '' && str_contains($normalizedHeader, $needle);
}

function wb_is_brand_header(string $normalizedHeader): bool
{
  return in_array($normalizedHeader, [
    wb_norm_header('Бренд'),
    wb_norm_header('Бренд товара'),
  ], true);
}

function wb_brand_for_template(array $tags): string
{
  foreach (['brand_wb', 'wb_brand', 'brand_wildberries'] as $field) {
    $value = wb_norm_spaces((string)($tags[$field] ?? ''));
    if ($value !== '') {
      return $value;
    }
  }
  foreach (['brand', 'vendor'] as $field) {
    $value = wb_norm_spaces((string)($tags[$field] ?? ''));
    if ($value !== '') {
      return $value;
    }
  }
  return '';
}

function wb_is_seller_article_header(string $normalizedHeader): bool
{
  return wb_header_contains($normalizedHeader, 'Артикул продавца')
    || wb_header_contains($normalizedHeader, 'Артикул поставщика');
}

function wb_parse_dimensions(string $dim): array
{
  $dim = trim((string)$dim);
  if ($dim === '') return ['', '', ''];
  $parts = array_map('trim', explode('/', $dim));
  return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
}

function wb_detect_dim_unit(string $raw): string
{
  $lc = wb_lc(trim((string)$raw));
  if ($lc === '') return '';
  if (strpos($lc, 'мм') !== false || preg_match('/\bmm\b/u', $lc)) return 'mm';
  if (strpos($lc, 'см') !== false || preg_match('/\bcm\b/u', $lc)) return 'cm';
  return '';
}

function wb_num_str(float $n): string
{
  $s = number_format($n, 2, '.', '');
  $s = preg_replace('/\.?0+$/', '', $s);
  return $s === '-0' ? '0' : (string)$s;
}

function wb_to_kg(string $w): string
{
  $w = trim(str_replace(',', '.', (string)$w));
  if ($w === '' || !is_numeric($w)) return '';
  return wb_num_str((float)$w);
}

function wb_to_grams(string $w): string
{
  $w = trim(str_replace(',', '.', (string)$w));
  if ($w === '' || !is_numeric($w)) return '';
  return (string)((int)round(((float)$w) * 1000.0));
}

function wb_dim_to_cm(string $v, bool $inputIsCm): string
{
  $v = trim(str_replace(',', '.', (string)$v));
  if ($v === '' || !is_numeric($v)) return '';
  $n = (float)$v;
  $cm = $inputIsCm ? $n : ($n / 10.0);
  return wb_num_str($cm);
}

function wb_marketplace_price_to_rub_string($value): string
{
  $raw = trim(str_replace(["\xc2\xa0", ' '], '', (string)$value));
  $raw = str_replace(',', '.', $raw);
  if ($raw === '') return '';
  if (!is_numeric($raw)) {
    if (!preg_match('~[-+]?\d+(?:[\.,]\d+)?~u', (string)$value, $m)) return '';
    $raw = str_replace(',', '.', (string)$m[0]);
    if (!is_numeric($raw)) return '';
  }
  $num = (float)$raw;
  if ($num <= 0) return '';
  return (string)max(1, (int)ceil($num - 0.00001));
}

function wb_build_param_map(array $params): array
{
  $map = [];
  foreach ($params as $name => $vals) {
    $key = wb_norm_header((string)$name);
    if ($key === '') continue;
    foreach ((array)$vals as $v) {
      $v = wb_norm_spaces((string)$v);
      if ($v === '') continue;
      if (!isset($map[$key])) $map[$key] = [];
      $map[$key][] = $v;
    }
  }
  return $map;
}

function wb_map_put(array &$map, string $name, string $value): void
{
  $name = trim($name);
  $value = wb_norm_spaces($value);
  if ($name === '' || $value === '') return;
  if (!isset($map[$name])) $map[$name] = [];
  $map[$name][] = $value;
}

function wb_parse_offer_for_template(XMLReader $r): array
{
  $offerId = trim((string)$r->getAttribute('id'));
  $tags = [];
  $pictures = [];
  $params = [];
  $wbParams = [];

  $depth = $r->depth;
  while ($r->read()) {
    if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'offer' && $r->depth === $depth) {
      break;
    }
    if ($r->nodeType !== XMLReader::ELEMENT) continue;

    $tag = $r->localName ?: $r->name;

    if ($tag === 'picture') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') $pictures[] = $v;
      continue;
    }

    if ($tag === 'param') {
      $pname = trim((string)$r->getAttribute('name'));
      $pval = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      wb_map_put($params, $pname, $pval);
      continue;
    }

    if ($tag === 'wb_param') {
      $pname = trim((string)$r->getAttribute('name'));
      $pval = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      wb_map_put($wbParams, $pname, $pval);
      continue;
    }

    if ($tag === 'description') {
      $html = (string)$r->readInnerXml();
      $html = html_entity_decode($html, ENT_QUOTES | ENT_XML1, 'UTF-8');
      $tags['description'] = wb_strip_html_to_text($html);
      continue;
    }

    $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    if ($v !== '' && !isset($tags[$tag])) {
      $tags[$tag] = $v;
    }
  }

  return [
    'offer_id' => $offerId,
    'tags' => $tags,
    'pictures' => $pictures,
    'params' => $params,
    'wb_params' => $wbParams,
  ];
}

function wb_scan_group_ids(string $xmlPath, ?array $selected): array
{
  $reader = new XMLReader();
  if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    return [];
  }

  $byKey = [];
  $offerKey = [];

  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

    $offerId = trim((string)$reader->getAttribute('id'));
    $depth = $reader->depth;

    if ($offerId === '' || ($selected !== null && empty($selected[$offerId]))) {
      if (!$reader->isEmptyElement) {
        while ($reader->read()) {
          if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
        }
      }
      continue;
    }

    $sameModel = '';
    if (!$reader->isEmptyElement) {
      while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) {
          break;
        }
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        $tag = $reader->localName ?: $reader->name;
        if ($tag === 'same_model') {
          $sameModel = wb_norm_spaces(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
          continue;
        }
        if (!$reader->isEmptyElement) $reader->readString();
      }
    }

    if ($sameModel === '') continue;
    $key = wb_norm_header($sameModel);
    if ($key === '') continue;
    $offerKey[$offerId] = $key;
    if (!isset($byKey[$key])) $byKey[$key] = [];
    $byKey[$key][] = $offerId;
  }

  $reader->close();

  $keyToGroup = [];
  $next = 1;
  foreach ($byKey as $key => $ids) {
    $ids = array_values(array_unique($ids));
    if (count($ids) < 2) continue;
    $keyToGroup[$key] = (string)$next;
    $next++;
  }

  $out = [];
  foreach ($offerKey as $offerId => $key) {
    if (isset($keyToGroup[$key])) {
      $out[$offerId] = $keyToGroup[$key];
    }
  }
  return $out;
}

function wb_supplier_template_offers_from_db(int $supplierId, ?array $selected = null, array $cfg = []): array
{
  $productsStmt = db()->prepare("
    SELECT *
    FROM feedtools_supplier_products
    WHERE supplier_id = ?
    ORDER BY sort_order ASC, id ASC
  ");
  $productsStmt->execute([$supplierId]);
  $products = [];
  $productIds = [];
  while ($product = $productsStmt->fetch(PDO::FETCH_ASSOC)) {
    $offerId = trim((string)($product['offer_id'] ?? ''));
    if ($offerId === '') continue;
    $vendorCode = trim((string)($product['vendor_code'] ?? ''));
    if ($selected !== null && empty($selected[$offerId]) && ($vendorCode === '' || empty($selected[$vendorCode]))) {
      continue;
    }
    $productId = (int)($product['id'] ?? 0);
    if ($productId <= 0) continue;
    $products[$productId] = $product;
    $productIds[] = $productId;
  }

  $fieldsByProduct = [];
  if ($productIds) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $fieldsStmt = db()->prepare("
      SELECT *
      FROM feedtools_supplier_product_fields
      WHERE product_id IN ({$placeholders})
      ORDER BY product_id ASC, sort_order ASC, id ASC
    ");
    $fieldsStmt->execute($productIds);
    while ($field = $fieldsStmt->fetch(PDO::FETCH_ASSOC)) {
      $productId = (int)($field['product_id'] ?? 0);
      if ($productId > 0) $fieldsByProduct[$productId][] = $field;
    }
  }

  $out = [];
  foreach ($products as $productId => $product) {
    $tags = [
      'name' => (string)($product['name'] ?? ''),
      'vendorCode' => (string)($product['vendor_code'] ?? ''),
      'categoryId' => (string)($product['category_id'] ?? ''),
      'ozon_category' => (string)($product['ozon_category'] ?? ''),
      'wb_category' => (string)($product['wb_category'] ?? ''),
      'brand' => (string)($product['brand'] ?? ''),
      'description' => wb_strip_html_to_text((string)($product['description_html'] ?? '')),
      'price' => (string)($product['price_original'] ?? ''),
      'stock' => (string)($product['stock_qty'] ?? ''),
    ];
    $params = [];
    $wbParams = [];
	    foreach ((array)($fieldsByProduct[$productId] ?? []) as $field) {
	      $kind = (string)($field['field_kind'] ?? '');
	      $name = trim((string)($field['field_name'] ?? ''));
	      $value = trim((string)($field['field_value'] ?? ''));
	      if ($name === '' || $value === '') continue;
      if ($kind === 'tag' || $kind === 'attr') {
        if (!isset($tags[$name])) $tags[$name] = $value;
      } elseif ($kind === 'standard') {
        if ($name === 'purchase_price') $tags['price'] = $value;
        if (in_array($name, ['brand', 'brand_ozon', 'brand_wb'], true)) $tags[$name] = $value;
        if ($name === 'short_name') $tags['short_name'] = $value;
        if ($name === 'tnved_code') {
          foreach (['ТН ВЭД', 'Код ТН ВЭД'] as $tnvedName) {
            if (!isset($wbParams[$tnvedName])) $wbParams[$tnvedName] = [];
            array_unshift($wbParams[$tnvedName], $value);
          }
          foreach (['ТН ВЭД коды ЕАЭС', 'Код ТН ВЭД'] as $tnvedName) {
            if (!isset($params[$tnvedName])) $params[$tnvedName] = [];
            array_unshift($params[$tnvedName], $value);
          }
        }
        if ($name === 'weight') $tags['weight'] = $value;
        if ($name === 'stock') $tags['stock'] = $value;
        if (in_array($name, ['length', 'width', 'height'], true)) {
          $dims = explode('/', (string)($tags['dimensions'] ?? '//'));
          $dims = array_pad($dims, 3, '');
          if ($name === 'length') $dims[0] = $value;
          if ($name === 'width') $dims[1] = $value;
          if ($name === 'height') $dims[2] = $value;
          $tags['dimensions'] = implode('/', $dims);
        }
      } elseif ($kind === 'param') {
        wb_map_put($params, $name, $value);
	      } elseif ($kind === 'wb_param') {
	        wb_map_put($wbParams, $name, $value);
	      }
	    }
	    $tags['stock'] = (string)supplier_products_apply_stock_modifier(
	      (int)($tags['stock'] ?? 0),
	      (int)($product['marketplace_enabled'] ?? 1),
	      (int)($product['stock_modifier'] ?? 0)
	    );
		    $priceNum = supplier_products_parse_float((string)($tags['price'] ?? ''));
		    if ($priceNum !== null && $priceNum > 0) {
		      $tags['price'] = (string)max(1, (int)round($priceNum));
		    }
	    $out[] = [
      'offer_id' => (string)($product['offer_id'] ?? ''),
      'tags' => $tags,
      'pictures' => supplier_products_public_picture_urls(
        supplier_products_decode_json_array($product['pictures_json'] ?? null),
        $cfg
      ),
      'params' => $params,
      'wb_params' => $wbParams,
    ];
  }
  return $out;
}

function wb_group_ids_from_offers(array $offers): array
{
  $byKey = [];
  $offerKey = [];
  foreach ($offers as $offer) {
    $offerId = trim((string)($offer['offer_id'] ?? ''));
    $sameModel = wb_norm_spaces((string)($offer['tags']['same_model'] ?? ''));
    if ($offerId === '' || $sameModel === '') continue;
    $key = wb_norm_header($sameModel);
    if ($key === '') continue;
    $offerKey[$offerId] = $key;
    $byKey[$key][] = $offerId;
  }

  $keyToGroup = [];
  $next = 1;
  foreach ($byKey as $key => $ids) {
    $ids = array_values(array_unique($ids));
    if (count($ids) < 2) continue;
    $keyToGroup[$key] = (string)$next;
    $next++;
  }

  $out = [];
  foreach ($offerKey as $offerId => $key) {
    if (isset($keyToGroup[$key])) $out[$offerId] = $keyToGroup[$key];
  }
  return $out;
}

function wb_find_header_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): int
{
  $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
  $maxRow = min((int)$sheet->getHighestRow(), 20);
  $knownHeaders = array_fill_keys(array_map('wb_norm_header', [
    'Наименование',
    'Категория продавца',
    'Бренд',
    'Описание',
    'Фото',
    'Баркоды',
    'Цена',
    'Вес с упаковкой (кг)',
    'Количество штук в упаковке',
  ]), true);
  for ($r = 1; $r <= $maxRow; $r++) {
    $foundArticle = false;
    $knownCount = 0;
    for ($c = 1; $c <= $highestColIndex; $c++) {
      $raw = trim((string)($sheet->getCell(wb_cell_addr($c, $r))->getValue() ?? ''));
      $hn = wb_norm_header($raw);
      if (wb_is_seller_article_header($hn)) $foundArticle = true;
      if (isset($knownHeaders[$hn])
        || wb_header_contains($hn, 'Наименование')
        || wb_header_contains($hn, 'Категория продавца')
        || wb_header_contains($hn, 'Бренд')
        || wb_header_contains($hn, 'Описание')
        || wb_header_contains($hn, 'Фото')
        || wb_header_contains($hn, 'Баркоды')
        || wb_header_contains($hn, 'Цена')
      ) $knownCount++;
    }
    if ($foundArticle && $knownCount > 0) return $r;
  }
  throw new RuntimeException('Cannot find Wildberries header row');
}

function wb_find_template_sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
  $candidates = [];
  foreach (['Товары', 'Номенклатура', 'Карточки товаров'] as $name) {
    $sheet = $spreadsheet->getSheetByName($name);
    if ($sheet) $candidates[] = $sheet;
  }
  $candidates[] = $spreadsheet->getActiveSheet();
  foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
    $candidates[] = $sheet;
  }

  $seen = [];
  foreach ($candidates as $sheet) {
    $hash = spl_object_hash($sheet);
    if (isset($seen[$hash])) continue;
    $seen[$hash] = true;
    try {
      wb_find_header_row($sheet);
      return $sheet;
    } catch (Throwable $e) {
      // Try the next worksheet.
    }
  }

  throw new RuntimeException('Cannot find Wildberries template sheet');
}

function wb_copy_row_template(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $srcRow, int $dstRow, int $maxCol): void
{
  if ($dstRow === $srcRow) return;
  $sheet->getRowDimension($dstRow)->setRowHeight($sheet->getRowDimension($srcRow)->getRowHeight());
  for ($c = 1; $c <= $maxCol; $c++) {
    $src = wb_cell_addr($c, $srcRow);
    $dst = wb_cell_addr($c, $dstRow);
    $sheet->duplicateStyle($sheet->getStyle($src), $dst);
    $dv = $sheet->getCell($src)->getDataValidation();
    if ($dv && $dv->getType() !== \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_NONE) {
      $sheet->setDataValidation($dst, clone $dv);
    }
  }
}

function wb_clear_row_values(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $maxCol): void
{
  for ($c = 1; $c <= $maxCol; $c++) {
    $sheet->getCell(wb_cell_addr($c, $row))->setValue(null);
  }
}

function wb_write_cell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col, int $row, string $value, bool $numeric = false): void
{
  $value = trim($value);
  if ($value === '') return;
  $addr = wb_cell_addr($col, $row);
  if ($numeric && is_numeric(str_replace(',', '.', $value))) {
    $sheet->setCellValue($addr, (float)str_replace(',', '.', $value));
  } else {
    $sheet->setCellValueExplicit($addr, $value, DataType::TYPE_STRING);
  }
  if (strpos($value, "\n") !== false) {
    $sheet->getStyle($addr)->getAlignment()->setWrapText(true);
    $sheet->getStyle($addr)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $sheet->getRowDimension($row)->setRowHeight(-1);
  }
}

function wb_strip_brand_from_name(string $name, string $brand): string
{
  $name = wb_norm_spaces($name);
  $brand = wb_norm_spaces($brand);
  if ($name === '' || $brand === '') return $name;

  $pattern = preg_quote($brand, '/');
  $clean = preg_replace('/(?<!\p{L})' . $pattern . '(?!\p{L})/iu', ' ', $name);
  if ((string)$clean === $name) {
    return $name;
  }
  $clean = preg_replace('/\s*([,;:\/\-])\s*/u', '$1 ', (string)$clean);
  $clean = preg_replace('/([\(\[])\s+/u', '$1', (string)$clean);
  $clean = preg_replace('/\s*([\)\]])\s*/u', '$1 ', (string)$clean);
  $clean = preg_replace('/(?:^| )[,;:\/\-]+(?= )/u', ' ', (string)$clean);
  $clean = preg_replace('~\s+([\)\]\},;:.!?])~u', '$1', (string)$clean);
  $clean = preg_replace('~([\(\[\{])\s+~u', '$1', (string)$clean);
  $clean = preg_replace('/\s+/u', ' ', (string)$clean);
  $clean = trim((string)$clean, " \t\n\r\0\x0B-–—,;:/()[]");

  return $clean !== '' ? $clean : $name;
}

function wb_offer_id_without_supplier(string $offerId): string
{
  $offerId = wb_norm_spaces($offerId);
  if ($offerId === '') return '';
  $pos = strpos($offerId, '__');
  if ($pos === false) return $offerId;
  return trim((string)substr($offerId, 0, $pos));
}

function wb_remove_token_from_name(string $name, string $token): string
{
  $name = wb_norm_spaces($name);
  $token = wb_norm_spaces($token);
  if ($name === '' || $token === '') return $name;

  $pattern = preg_quote($token, '/');
  $clean = preg_replace('/(?<!\p{L}|\p{N})' . $pattern . '(?!\p{L}|\p{N})/iu', ' ', $name);
  if ((string)$clean === $name) {
    return $name;
  }
  $clean = preg_replace('/\s*([,;:\/\-])\s*/u', '$1 ', (string)$clean);
  $clean = preg_replace('/([\(\[])\s+/u', '$1', (string)$clean);
  $clean = preg_replace('/\s*([\)\]])\s*/u', '$1 ', (string)$clean);
  $clean = preg_replace('/(?:^| )[,;:\/\-]+(?= )/u', ' ', (string)$clean);
  $clean = preg_replace('~\s+([\)\]\},;:.!?])~u', '$1', (string)$clean);
  $clean = preg_replace('~([\(\[\{])\s+~u', '$1', (string)$clean);
  $clean = preg_replace('/\s+/u', ' ', (string)$clean);
  $clean = trim((string)$clean, " \t\n\r\0\x0B-–—,;:/()[]");
  return $clean !== '' ? $clean : $name;
}

function wb_trim_title_to_limit(string $title, int $limit = 200): string
{
  $title = wb_norm_spaces($title);
  if ($title === '' || $limit <= 0) return '';
  if (mb_strlen($title, 'UTF-8') <= $limit) return $title;

  $slice = trim((string)mb_substr($title, 0, $limit, 'UTF-8'));
  $spacePos = mb_strrpos($slice, ' ', 0, 'UTF-8');
  if ($spacePos !== false && $spacePos >= (int)floor($limit * 0.55)) {
    $slice = trim((string)mb_substr($slice, 0, $spacePos, 'UTF-8'));
  }

  $slice = rtrim($slice, " \t\n\r\0\x0B,;:-/()");
  return $slice !== '' ? $slice : trim((string)mb_substr($title, 0, $limit, 'UTF-8'));
}

function wb_prepare_title_for_export(string $name, string $brand, string $offerId, string $vendorCode, int $limit = 200): string
{
  $title = wb_strip_brand_from_name($name, $brand);

  $offerId = wb_norm_spaces($offerId);
  if ($offerId !== '') {
    $title = wb_remove_token_from_name($title, $offerId);
  }
  if ($vendorCode !== '' && $vendorCode !== $offerId) {
    $title = wb_remove_token_from_name($title, $vendorCode);
  }
  $offerCore = wb_offer_id_without_supplier($offerId);
  if ($offerCore !== '') {
    $title = wb_remove_token_from_name($title, $offerCore);
  }

  $title = wb_norm_spaces($title);
  if ($title === '') {
    $title = wb_norm_spaces($name);
  }

  return wb_trim_title_to_limit($title, $limit);
}

function wb_get_category_name(string $subjectId, array &$cache): string
{
  $subjectId = trim($subjectId);
  if ($subjectId === '') return '';
  if (isset($cache[$subjectId])) return $cache[$subjectId];

  $st = db()->prepare("
    SELECT name
    FROM feedtools_taxonomy_categories
    WHERE source='wildberries' AND is_leaf=1 AND external_id=?
    LIMIT 1
  ");
  $st->execute(['wb:subject:' . $subjectId]);
  $name = trim((string)($st->fetchColumn() ?: ''));
  $cache[$subjectId] = $name;
  return $name;
}

$datasetId = (int)($_POST['dataset_id'] ?? 0);
if ($datasetId <= 0) {
  http_response_code(400);
  exit('Bad dataset_id');
}

$offerIdsJson = (string)($_POST['offer_ids_json'] ?? '');
$offerIds = [];
if (trim($offerIdsJson) !== '') {
  $tmp = json_decode($offerIdsJson, true);
  if (is_array($tmp)) {
    foreach ($tmp as $x) {
      $s = trim((string)$x);
      if ($s !== '') $offerIds[] = $s;
    }
  }
}
$offerIds = array_values(array_unique($offerIds));
$selected = $offerIds ? array_fill_keys($offerIds, true) : null;

if (empty($_FILES['template_file']) || ($_FILES['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  exit('Template file is required (.xlsx)');
}
$ext = strtolower(pathinfo($_FILES['template_file']['name'] ?? '', PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
  http_response_code(400);
  exit('Template must be .xlsx');
}

$stmt = db()->prepare("SELECT id, original_filename, stored_path FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$datasetId]);
$ds = $stmt->fetch();
if (!$ds) {
  http_response_code(404);
  exit('Dataset not found');
}

supplier_products_tables_ensure($cfg);
$stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
$stmt->execute([$datasetId]);
$fullDs = $stmt->fetch();
$supplierDbOffers = null;
if (is_array($fullDs) && supplier_products_is_dataset_row($fullDs)) {
  $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
  if ($supplierId <= 0) {
    http_response_code(404);
    exit('Supplier dataset not found');
  }
  $supplierDbOffers = wb_supplier_template_offers_from_db($supplierId, $selected, $cfg);
} else {
  try {
    $xmlPath = wb_safe_realpath_under($cfg['paths']['uploads_dir'] ?? null, (string)$ds['stored_path']);
  } catch (Throwable $e) {
    http_response_code(404);
    exit('Dataset XML not found');
  }
}

$outDir = $cfg['paths']['outputs_dir'];
if (!is_dir($outDir)) @mkdir($outDir, 0777, true);

$storageDir = (string)($cfg['paths']['storage_dir'] ?? '');
if ($storageDir === '') {
  $storageDir = dirname((string)($cfg['paths']['outputs_dir'] ?? (__DIR__ . '/../storage/outputs')));
}
$lockDir = '';
$lockBaseCandidates = array_values(array_unique(array_filter([
  $storageDir,
  (string)($cfg['paths']['outputs_dir'] ?? ''),
  sys_get_temp_dir(),
])));
foreach ($lockBaseCandidates as $lockBase) {
  $candidate = rtrim((string)$lockBase, '/\\') . '/locks';
  if ((!is_dir($candidate) && !@mkdir($candidate, 0777, true)) || !is_writable($candidate)) {
    continue;
  }
  $lockDir = $candidate;
  break;
}
if ($lockDir === '') {
  http_response_code(500);
  exit('Cannot create export lock directory');
}
$lockKey = 'wb_export_' . $datasetId . '_' . preg_replace('/[^a-zA-Z0-9_.-]+/', '_', wb_export_current_actor());
$lockPath = $lockDir . '/' . $lockKey . '.lock';
$lockHandle = @fopen($lockPath, 'c+');
if (!$lockHandle) {
  http_response_code(500);
  exit('Cannot create export lock');
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
  http_response_code(409);
  exit('WB export is already running for this dataset. Please wait for the current export to finish.');
}

$tmpTpl = null;
$tmpOut = null;
register_shutdown_function(static function () use (&$lockHandle, &$tmpTpl, &$tmpOut): void {
  if (is_resource($lockHandle)) {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
  }
  if ($tmpTpl && is_file($tmpTpl)) @unlink($tmpTpl);
  if ($tmpOut && is_file($tmpOut)) @unlink($tmpOut);
});

$tmpTpl = $outDir . '/wb_tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
$tmpOut = $outDir . '/wb_out_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';

if (!move_uploaded_file($_FILES['template_file']['tmp_name'], $tmpTpl)) {
  http_response_code(500);
  exit('Failed to save uploaded template');
}

try {
  $spreadsheet = IOFactory::load($tmpTpl);
} catch (Throwable $e) {
  @unlink($tmpTpl);
  http_response_code(400);
  exit('Cannot read template xlsx');
}

try {
  $sheet = wb_find_template_sheet($spreadsheet);
  $HEADER_ROW = wb_find_header_row($sheet);
} catch (Throwable $e) {
  @unlink($tmpTpl);
  http_response_code(400);
  exit('Cannot find WB template headers: ' . $e->getMessage());
}
$DATA_START_ROW = $HEADER_ROW + 2;

$highestCol = $sheet->getHighestColumn();
$highestColIndex = Coordinate::columnIndexFromString($highestCol);

$cols = [];
for ($c = 1; $c <= $highestColIndex; $c++) {
  $raw = trim((string)($sheet->getCell(wb_cell_addr($c, $HEADER_ROW))->getValue() ?? ''));
  if ($raw === '') continue;
  $cols[$c] = ['raw' => $raw, 'norm' => wb_norm_header($raw)];
}
$templateColLimit = $cols ? max(array_keys($cols)) : 1;

$maxRow = max((int)$sheet->getHighestRow(), $DATA_START_ROW);
for ($r = $DATA_START_ROW; $r <= $maxRow; $r++) {
  wb_clear_row_values($sheet, $r, $templateColLimit);
}

$SKIP = array_fill_keys(array_map('wb_norm_header', [
  'Артикул WB',
  'Дата окончания действия сертификата/декларации',
  'Дата регистрации сертификата/декларации',
  'Номер декларации соответствия',
  'Номер сертификата соответствия',
  'NTIN',
  'Артикул OZON',
  'ИКПУ',
  'Код упаковки',
]), true);

$CONST = [
  wb_norm_header('КИЗ') => 'Не нужен',
  wb_norm_header('18+') => 'Нет',
  wb_norm_header('Только для ИП и юрлиц') => 'Нет',
  wb_norm_header('Минимальное количество штук в заказе') => '1',
  wb_norm_header('Подтверждаю, что товар промаркирован') => 'Нет',
  wb_norm_header('Ставка НДС') => 'Без НДС',
  wb_norm_header('Гарантийный срок') => '1 год',
];

$NUMERIC_HEADERS = array_fill_keys(array_map('wb_norm_header', [
  'Вес с упаковкой (кг)',
  'Количество штук в упаковке',
  'Минимальное количество штук в заказе',
  'Цена',
  'Вес товара с упаковкой (г)',
  'Высота предмета',
  'Высота упаковки',
  'Глубина предмета',
  'Длина упаковки',
  'Ширина предмета',
  'Ширина упаковки',
  'Емкость стандартного аккумулятора',
]), true);

$wbCategoryCache = [];
$offersForExport = [];
if (is_array($supplierDbOffers)) {
  $offersForExport = $supplierDbOffers;
  $groupIdByOffer = wb_group_ids_from_offers($offersForExport);
} else {
  $groupIdByOffer = wb_scan_group_ids($xmlPath, $selected);
  $reader = new XMLReader();
  if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    @unlink($tmpTpl);
    http_response_code(500);
    exit('Cannot open dataset XML');
  }

  while ($reader->read()) {
    if (wb_export_client_disconnected()) {
      $reader->close();
      http_response_code(499);
      exit('Client disconnected');
    }
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

    $offerId = trim((string)$reader->getAttribute('id'));
    if ($offerId === '') {
      $depth = $reader->depth;
      while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
      }
      continue;
    }

    if ($selected !== null && empty($selected[$offerId])) {
      $depth = $reader->depth;
      while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
      }
      continue;
    }

    $offersForExport[] = wb_parse_offer_for_template($reader);
  }
  $reader->close();
}

$rowNum = $DATA_START_ROW - 1;

foreach ($offersForExport as $offer) {
  if (wb_export_client_disconnected()) {
    http_response_code(499);
    exit('Client disconnected');
  }
  $offerId = trim((string)($offer['offer_id'] ?? ''));
  if ($offerId === '') continue;
  $tags = $offer['tags'];
  $pictures = $offer['pictures'];
  $paramsMap = wb_build_param_map($offer['params']);
  $wbParamsMap = wb_build_param_map($offer['wb_params']);

  $picAll = '';
  if ($pictures) {
    $picAll = implode(';', wb_rich_content_media_urls($pictures, 30));
  }

  $dimRaw = (string)($tags['dimensions'] ?? '');
  [$Ld, $Wd, $Hd] = wb_parse_dimensions($dimRaw);
  $unit = wb_detect_dim_unit($dimRaw);
  if ($unit === 'cm') {
    $dimInputIsCm = true;
  } elseif ($unit === 'mm') {
    $dimInputIsCm = false;
  } else {
    $nums = [];
    foreach ([$Ld, $Wd, $Hd] as $v) {
      $v = trim(str_replace(',', '.', (string)$v));
      if ($v !== '' && is_numeric($v)) $nums[] = (float)$v;
    }
    $dimInputIsCm = ($nums && max($nums) < 50.0);
  }

  $Lcm = wb_dim_to_cm($Ld, $dimInputIsCm);
  $Wcm = wb_dim_to_cm($Wd, $dimInputIsCm);
  $Hcm = wb_dim_to_cm($Hd, $dimInputIsCm);

  $wbCategoryId = trim((string)($tags['wb_category'] ?? ($tags['wb_subject_id'] ?? '')));
  $wbCategoryName = wb_get_category_name($wbCategoryId, $wbCategoryCache);

  $sellerArticle = $offerId;
  if ($sellerArticle === '') $sellerArticle = trim((string)($tags['article'] ?? ''));
  if ($sellerArticle === '') $sellerArticle = trim((string)($tags['vendorCode'] ?? ''));

  $brand = wb_brand_for_template($tags);
  $vendorCode = trim((string)($tags['vendorCode'] ?? ''));
  $shortName = wb_norm_spaces((string)($tags['short_name'] ?? ''));
  $titleSource = $shortName !== '' ? $shortName : (string)($tags['name'] ?? '');
  $wbTitle = wb_prepare_title_for_export($titleSource, $brand, $offerId, $vendorCode, $shortName !== '' ? 60 : 200);

  $group = (string)($groupIdByOffer[$offerId] ?? '');

  $rowNum++;
  wb_copy_row_template($sheet, $DATA_START_ROW, $rowNum, $templateColLimit);
  wb_clear_row_values($sheet, $rowNum, $templateColLimit);

  foreach ($cols as $colIndex => $col) {
    $hn = $col['norm'];
    $rawHeader = $col['raw'];
    $value = '';

    if (isset($SKIP[$hn])) {
      continue;
    }

    if (isset($CONST[$hn])) {
      $value = $CONST[$hn];
    } elseif ($hn === wb_norm_header('Группа')) {
      $value = $group;
    } elseif (wb_is_seller_article_header($hn)) {
      $value = $sellerArticle;
    } elseif ($hn === wb_norm_header('Наименование')) {
      $value = $wbTitle;
    } elseif ($hn === wb_norm_header('Категория продавца')) {
      $value = $wbCategoryName !== '' ? $wbCategoryName : $wbCategoryId;
    } elseif (wb_is_brand_header($hn)) {
      $value = $brand;
    } elseif ($hn === wb_norm_header('Описание')) {
      $value = wb_trim_description_to_limit((string)($tags['description'] ?? ''), 2000);
    } elseif ($hn === wb_norm_header('Фото')) {
      $value = $picAll;
    } elseif ($hn === wb_norm_header('Видео')) {
      $value = wb_video_cover_value($paramsMap, $wbParamsMap);
    } elseif ($hn === wb_norm_header('Вес с упаковкой (кг)')) {
      $value = wb_to_kg((string)($tags['weight'] ?? ''));
    } elseif ($hn === wb_norm_header('Количество штук в упаковке')) {
      $value = wb_param_value($wbParamsMap, 'Количество штук в упаковке');
      if ($value === '') $value = wb_param_value($wbParamsMap, 'количество_в_единице_товара');
      if ($value === '') $value = wb_param_value($paramsMap, 'количество_в_единице_товара');
      if ($value === '') $value = '1';
    } elseif ($hn === wb_norm_header('Баркоды')) {
      $value = (string)($tags['barcode'] ?? '');
      if ($value === '') $value = wb_param_value($paramsMap, 'Баркод');
      if ($value === '') $value = wb_param_value($wbParamsMap, 'Баркод');
    } elseif ($hn === wb_norm_header('Цена')) {
      $value = wb_marketplace_price_to_rub_string($tags['price'] ?? '');
    } elseif ($hn === wb_norm_header('Вес товара с упаковкой (г)')) {
      $value = wb_to_grams((string)($tags['weight'] ?? ''));
    } elseif ($hn === wb_norm_header('Высота упаковки')) {
      $value = $Hcm;
    } elseif ($hn === wb_norm_header('Длина упаковки')) {
      $value = $Lcm;
    } elseif ($hn === wb_norm_header('Ширина упаковки')) {
      $value = $Wcm;
    } elseif ($hn === wb_norm_header('Высота предмета')) {
      $value = wb_param_value($wbParamsMap, 'Высота предмета');
    } elseif ($hn === wb_norm_header('Глубина предмета')) {
      $value = wb_param_value($wbParamsMap, 'Глубина предмета');
    } elseif ($hn === wb_norm_header('Ширина предмета')) {
      $value = wb_param_value($wbParamsMap, 'Ширина предмета');
    } elseif ($hn === wb_norm_header('Цвет')) {
      $value = wb_param_value($wbParamsMap, 'Цвет');
      if ($value === '') $value = wb_param_value($paramsMap, 'Цвет');
    } elseif (wb_is_tnved_header($rawHeader)) {
      $value = wb_tnved_value($wbParamsMap, $paramsMap, $rawHeader);
    } else {
      $value = wb_param_value($wbParamsMap, $rawHeader);
      if ($value === '') $value = wb_param_value($paramsMap, $rawHeader);
    }

    wb_write_cell($sheet, $colIndex, $rowNum, (string)$value, isset($NUMERIC_HEADERS[$hn]));
  }
}

try {
  $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
  $writer->save($tmpOut);
} catch (Throwable $e) {
  @unlink($tmpTpl);
  @unlink($tmpOut);
  http_response_code(500);
  exit('Failed to write result xlsx');
}

@unlink($tmpTpl);

$baseName = preg_replace('/\.[a-z0-9]+$/i', '', (string)$ds['original_filename']);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $baseName);
if ($baseName === '' || $baseName === '_') $baseName = 'dataset_' . $datasetId;
$downloadName = $baseName . '_wb_template.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpOut));

if (wb_export_client_disconnected()) {
  http_response_code(499);
  exit('Client disconnected');
}

readfile($tmpOut);
@unlink($tmpOut);
$tmpOut = null;
