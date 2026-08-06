<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/supplier_products.php';

// Export selected offers to Excel 2003 XML (SpreadsheetML).
// Excel opens it as a normal table; we return filename *.xls for convenience.

function hxml(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_category_path(string $categoryId, array $catMap): string
{
  $categoryId = trim($categoryId);
  if ($categoryId === '' || empty($catMap[$categoryId])) return '';

  $parts = [];
  $seen = [];
  $cur = $categoryId;
  for ($i = 0; $i < 20; $i++) {
    if ($cur === '' || isset($seen[$cur]) || empty($catMap[$cur])) break;
    $seen[$cur] = true;

    $name = trim((string)($catMap[$cur]['name'] ?? ''));
    if ($name !== '') array_unshift($parts, $name);

    $parent = (string)($catMap[$cur]['parentId'] ?? '');
    $cur = trim($parent);
  }
  return $parts ? implode(' -> ', $parts) : '';
}

function safe_realpath_under(string $baseDir, string $path): string
{
  $base = realpath($baseDir);
  $real = realpath($path);
  if (!$base || !$real || strpos($real, $base) !== 0 || !is_file($real)) {
    throw new RuntimeException('File not found');
  }
  return $real;
}

function supplier_products_export_xls_from_db(array $cfg, array $ds, int $supplierId, array $offerIds): void
{
  $selected = $offerIds ? array_fill_keys($offerIds, true) : null;
  $matchesSelection = static function (array $product) use ($selected): bool {
    if ($selected === null) return true;
    foreach ([
      trim((string)($product['offer_id'] ?? '')),
      trim((string)($product['vendor_code'] ?? '')),
    ] as $candidate) {
      if ($candidate !== '' && isset($selected[$candidate])) return true;
    }
    return false;
  };

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
    if (!$matchesSelection($product)) continue;
    $productId = (int)($product['id'] ?? 0);
    if ($productId <= 0) continue;
    $products[$productId] = $product;
    $productIds[] = $productId;
  }

  $fieldsByProduct = [];
  $tagNames = [];
  $paramNames = [];
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
      if ($productId <= 0) continue;
      $fieldsByProduct[$productId][] = $field;
      $kind = (string)($field['field_kind'] ?? '');
      $name = trim((string)($field['field_name'] ?? ''));
      if ($name === '') continue;
      if ($kind === 'tag' || $kind === 'attr') {
        if (!in_array($name, ['name', 'vendorCode', 'article', 'ozon_category', 'categoryId', 'category', 'description', 'picture'], true)) {
          $tagNames[$kind === 'attr' ? ('@' . $name) : $name] = true;
        }
      } elseif ($kind === 'param') {
        $paramNames[$name] = true;
      } elseif ($kind === 'wb_param') {
        $paramNames['[WB] ' . $name] = true;
      }
    }
  }

  $tagNames = array_keys($tagNames);
  sort($tagNames, SORT_NATURAL | SORT_FLAG_CASE);
  $paramNames = array_keys($paramNames);
  sort($paramNames, SORT_NATURAL | SORT_FLAG_CASE);

  $headers = ['Артикул', 'Название', 'Категория Ozon', 'Категория поставщика', 'Описание', 'Фишка 1', 'Фишка 2', 'Фишка 3'];
  foreach ($tagNames as $t) $headers[] = $t;
  $headers[] = 'Изображение 1';
  $headers[] = 'Изображения (прочие)';
  foreach ($paramNames as $p) $headers[] = $p;

  $baseName = preg_replace('/\.[a-z0-9]+$/i', '', (string)($ds['original_filename'] ?? ''));
  $baseName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $baseName);
  if ($baseName === '' || $baseName === '_') $baseName = 'supplier_dataset_' . (int)($ds['id'] ?? 0);
  $downloadName = $baseName . '_selected.xls';

  header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $downloadName . '"');

  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
  echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
  echo "<Worksheet ss:Name=\"Export\"><Table>\n";
  echo '<Row>';
  foreach ($headers as $h) echo '<Cell><Data ss:Type="String">' . hxml($h) . '</Data></Cell>';
  echo "</Row>\n";

  foreach ($products as $productId => $product) {
    $tags = [
      'price_original' => (string)($product['price_original'] ?? ''),
      'stock' => (string)($product['stock_qty'] ?? ''),
    ];
    $params = [];
    $features = [];
    foreach ((array)($fieldsByProduct[$productId] ?? []) as $field) {
      $kind = (string)($field['field_kind'] ?? '');
      $name = trim((string)($field['field_name'] ?? ''));
      $value = trim((string)($field['field_value'] ?? ''));
      if ($name === '' || $value === '') continue;
      if ($kind === 'tag') {
        $tags[$name] = isset($tags[$name]) ? ($tags[$name] . "\n" . $value) : $value;
      } elseif ($kind === 'attr') {
        $key = '@' . $name;
        $tags[$key] = isset($tags[$key]) ? ($tags[$key] . "\n" . $value) : $value;
      } elseif ($kind === 'standard') {
        if ($name === 'purchase_price') $tags['price_original'] = $value;
        if ($name === 'stock') $tags['stock'] = $value;
        if ($name === 'tnved_code') $params['Код ТН ВЭД'][] = $value;
      } elseif ($kind === 'param') {
        $params[$name][] = $value;
      } elseif ($kind === 'wb_param') {
        $params['[WB] ' . $name][] = $value;
      } elseif ($kind === 'feature' && count($features) < 3) {
        $features[] = $value;
      }
    }

    $pictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($product['pictures_json'] ?? null));
    $pic0 = (string)($pictures[0] ?? '');
    $picsOther = array_slice($pictures, 1);
    $supplierCat = trim((string)($product['category_path'] ?? ''));
    if ($supplierCat === '') $supplierCat = trim((string)($product['category_id'] ?? ''));
    $tags['stock'] = (string)supplier_products_apply_stock_modifier(
      (int)($tags['stock'] ?? 0),
      (int)($product['marketplace_enabled'] ?? 1),
      (int)($product['stock_modifier'] ?? 0)
    );
    $priceNum = supplier_products_parse_float((string)($tags['price_original'] ?? ''));
    if ($priceNum !== null && $priceNum > 0) {
      $tags['price_original'] = (string)max(1, (int)round($priceNum));
    }

    $row = [
      trim((string)($product['vendor_code'] ?? '')) !== '' ? (string)$product['vendor_code'] : (string)($product['offer_id'] ?? ''),
      (string)($product['name'] ?? ''),
      (string)($product['ozon_category'] ?? ''),
      $supplierCat,
      trim(html_entity_decode(strip_tags((string)($product['description_html'] ?? '')), ENT_QUOTES | ENT_XML1, 'UTF-8')),
      (string)($features[0] ?? ''),
      (string)($features[1] ?? ''),
      (string)($features[2] ?? ''),
    ];
    foreach ($tagNames as $t) $row[] = (string)($tags[$t] ?? '');
    $row[] = $pic0;
    $row[] = $picsOther ? implode("\n", $picsOther) : '';
    foreach ($paramNames as $p) {
      $row[] = empty($params[$p]) ? '' : implode("\n", array_values(array_unique($params[$p])));
    }

    echo '<Row>';
    foreach ($row as $cell) echo '<Cell><Data ss:Type="String">' . hxml((string)$cell) . '</Data></Cell>';
    echo "</Row>\n";
  }

  echo "</Table></Worksheet></Workbook>";
  exit;
}

$datasetId = isset($_POST['dataset_id']) ? (int)$_POST['dataset_id'] : (int)($_GET['dataset_id'] ?? 0);
if ($datasetId <= 0) { http_response_code(400); exit('Bad dataset_id'); }

$offerIdsJson = (string)($_POST['offer_ids_json'] ?? $_GET['offer_ids_json'] ?? '');
$offerIds = [];
if ($offerIdsJson !== '') {
  $tmp = json_decode($offerIdsJson, true);
  if (is_array($tmp)) {
    foreach ($tmp as $x) {
      $s = trim((string)$x);
      if ($s !== '') $offerIds[] = $s;
    }
  }
}
$offerIds = array_values(array_unique($offerIds));

$stmt = db()->prepare('SELECT id, original_filename, stored_path FROM feedtools_datasets WHERE id = ?');
$stmt->execute([$datasetId]);
$ds = $stmt->fetch();
if (!$ds) { http_response_code(404); exit('Dataset not found'); }

supplier_products_tables_ensure($cfg);
$stmt = db()->prepare('SELECT * FROM feedtools_datasets WHERE id = ?');
$stmt->execute([$datasetId]);
$fullDs = $stmt->fetch();
if (is_array($fullDs) && supplier_products_is_dataset_row($fullDs)) {
  $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
  if ($supplierId <= 0) { http_response_code(404); exit('Supplier dataset not found'); }
  supplier_products_export_xls_from_db($cfg, $fullDs, $supplierId, $offerIds);
}

$xmlPath = safe_realpath_under($cfg['paths']['uploads_dir'], (string)$ds['stored_path']);

// Пустой выбор = экспорт всего датасета
$selected = $offerIds ? array_fill_keys($offerIds, true) : null;

// PASS 1: collect categories map, tag names, param names
$catMap = [];
$tagNames = [];      // other tags (not fixed ones)
$paramNames = [];

$reader = new XMLReader();
if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
  http_response_code(500);
  exit('Cannot open XML');
}

while ($reader->read()) {
  if ($reader->nodeType !== XMLReader::ELEMENT) continue;

  if ($reader->name === 'category') {
    $id = trim((string)$reader->getAttribute('id'));
    if ($id !== '') {
      $catMap[$id] = [
        'parentId' => (string)$reader->getAttribute('parentId'),
        'name' => trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8')),
      ];
    }
    continue;
  }

  if ($reader->name === 'offer') {
    $depth = $reader->depth;

    // offer attributes => columns as @attr
    if ($reader->hasAttributes) {
      while ($reader->moveToNextAttribute()) {
        if ($reader->name === 'id') continue;
        $tagNames['@' . $reader->name] = true;
      }
      $reader->moveToElement();
    }

    while ($reader->read()) {
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
      if ($reader->nodeType !== XMLReader::ELEMENT) continue;

      $tag = $reader->name;
      if ($tag === 'param') {
        $pname = trim((string)$reader->getAttribute('name'));
        if ($pname !== '') $paramNames[$pname] = true;
        // consume
        $reader->readString();
        continue;
      }

      // fixed columns are handled explicitly
      if (in_array($tag, ['name','vendorCode','ozon_category','categoryId','category','description','picture'], true)) {
        // consume inner content for large feeds, but don't store
        if ($tag === 'description') $reader->readInnerXml(); else $reader->readString();
        continue;
      }

      $tagNames[$tag] = true;
      $reader->readString();
    }
  }
}

$reader->close();

$tagNames = array_keys($tagNames);
sort($tagNames, SORT_NATURAL | SORT_FLAG_CASE);
$paramNames = array_keys($paramNames);
sort($paramNames, SORT_NATURAL | SORT_FLAG_CASE);

// Headers
$headers = [
  'Артикул',
  'Название',
  'Категория Ozon',
  'Категория поставщика',
  'Описание',
];
foreach ($tagNames as $t) $headers[] = $t;
$headers[] = 'Изображение 1';
$headers[] = 'Изображения (прочие)';
foreach ($paramNames as $p) $headers[] = $p;

// PASS 2: export selected offers
$reader = new XMLReader();
if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
  http_response_code(500);
  exit('Cannot open XML');
}

// response headers
$baseName = preg_replace('/\.[a-z0-9]+$/i', '', (string)$ds['original_filename']);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $baseName);
if ($baseName === '' || $baseName === '_') $baseName = 'dataset_' . $datasetId;
$downloadName = $baseName . '_selected.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
echo "<Worksheet ss:Name=\"Export\"><Table>\n";

// header row
echo '<Row>';
foreach ($headers as $h) {
  echo '<Cell><Data ss:Type="String">' . hxml($h) . '</Data></Cell>';
}
echo "</Row>\n";

while ($reader->read()) {
  if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

  $offerId = trim((string)$reader->getAttribute('id'));
if ($offerId === '') {
  // skip offer quickly
  $depth = $reader->depth;
  while ($reader->read()) {
    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
  }
  continue;
}

// если $selected === null — экспортируем все offers
if ($selected !== null && empty($selected[$offerId])) {
  // skip offer quickly
  $depth = $reader->depth;
  while ($reader->read()) {
    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
  }
  continue;
}


  $values = [
    'vendorCode' => '',
    'name' => '',
    'ozon_category' => '',
    'category_id' => '',
    'description' => '',
    'tags' => [],
    'pic0' => '',
    'picsOther' => [],
    'params' => [],
  ];

  // offer attributes
  if ($reader->hasAttributes) {
    while ($reader->moveToNextAttribute()) {
      if ($reader->name === 'id') continue;
      $values['tags']['@' . $reader->name] = $reader->value;
    }
    $reader->moveToElement();
  }

  $depth = $reader->depth;
  while ($reader->read()) {
    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
    if ($reader->nodeType !== XMLReader::ELEMENT) continue;

    $tag = $reader->name;

    if ($tag === 'vendorCode') {
      $values['vendorCode'] = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'name') {
      $values['name'] = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'ozon_category') {
      $values['ozon_category'] = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'categoryId' || $tag === 'category') {
      $values['category_id'] = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'description') {
      $raw = trim($reader->readInnerXml());
      // keep as plain text (strip tags)
      $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      $values['description'] = $plain;
      continue;
    }
    if ($tag === 'picture') {
      $pic = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pic !== '') {
        if ($values['pic0'] === '') $values['pic0'] = $pic;
        else $values['picsOther'][] = $pic;
      }
      continue;
    }
    if ($tag === 'param') {
      $pname = trim((string)$reader->getAttribute('name'));
      $pval  = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pname !== '') {
        if (!isset($values['params'][$pname])) $values['params'][$pname] = [];
        if ($pval !== '') $values['params'][$pname][] = $pval;
      }
      continue;
    }

    // any other tag
    $val = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    if ($val !== '') {
      if (!isset($values['tags'][$tag])) $values['tags'][$tag] = $val;
      else $values['tags'][$tag] .= "\n" . $val;
    }
  }

  $article = $values['vendorCode'] !== '' ? $values['vendorCode'] : $offerId;
  $catPath = build_category_path($values['category_id'], $catMap);
  $supplierCat = $catPath !== '' ? $catPath : $values['category_id'];

  $row = [];
  $row[] = $article;
  $row[] = $values['name'];
  $row[] = $values['ozon_category'];
  $row[] = $supplierCat;
  $row[] = $values['description'];

  foreach ($tagNames as $t) {
    $row[] = (string)($values['tags'][$t] ?? '');
  }

  $row[] = $values['pic0'];
  $row[] = $values['picsOther'] ? implode("\n", $values['picsOther']) : '';

  foreach ($paramNames as $p) {
    if (empty($values['params'][$p])) {
      $row[] = '';
    } else {
      $row[] = implode("\n", array_values(array_unique(array_filter($values['params'][$p], fn($x) => (string)$x !== ''))));
    }
  }

  echo '<Row>';
  foreach ($row as $cell) {
    echo '<Cell><Data ss:Type="String">' . hxml((string)$cell) . '</Data></Cell>';
  }
  echo "</Row>\n";
}

$reader->close();

echo "</Table></Worksheet></Workbook>";
