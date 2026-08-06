<?php

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

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ft_lc(string $s): string {
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function norm_header(string $s): string {
  $s = trim((string)$s);
  $s = str_replace('*', '', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return ft_lc(trim($s));
}

function cellAddr(int $col, int $row): string {
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function vstr($v): string {
  if ($v === null) return '';
  if (is_bool($v)) return $v ? '1' : '0';
  if (is_numeric($v)) {
    // важное: Excel числа иногда "1" как 1.0 — приводим аккуратно
    $s = (string)$v;
    $s = preg_replace('/\.0$/', '', $s);
    return trim($s);
  }
  return trim((string)$v);
}

function grams_to_kg_str(string $g): string {
  $g = trim($g);
  if ($g === '') return '';
  $g = str_replace(',', '.', $g);
  if (!is_numeric($g)) return '';
  $kg = ((float)$g) / 1000.0;
  // до 3 знаков, без лишних нулей
  $s = number_format($kg, 3, '.', '');
  $s = rtrim(rtrim($s, '0'), '.');
  return $s;
}

function split_pictures(string $s): array {
  $s = trim((string)$s);
  if ($s === '') return [];
  $s = str_replace(["\r", "\n", "\t"], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  $parts = array_filter(array_map('trim', explode(' ', $s)), fn($x) => $x !== '');
  // уникальные, сохраняя порядок
  $seen = [];
  $out = [];
  foreach ($parts as $p) {
    if (isset($seen[$p])) continue;
    $seen[$p] = true;
    $out[] = $p;
  }
  return $out;
}

function hashtags_to_store(string $s): string {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = str_replace(["\r", "\n", "\t", ",", ";"], ' ', $s);
  $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
    $key = mb_strtolower($tag, 'UTF-8');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $tag;
  }
  return implode(' ', $out);
}

// Если в ячейке несколько значений одной характеристики, экспорт пишет их через ";".
// Импорт должен разложить их обратно в массив значений.
function split_param_values(string $s): array {
  $s = trim((string)$s);
  if ($s === '') return [];

  // единый разделитель — ";" (пробелы вокруг допускаются)
  if (strpos($s, ';') === false) {
    return [$s];
  }

  $parts = array_map('trim', explode(';', $s));
  $parts = array_values(array_filter($parts, fn($x) => $x !== ''));

  // уникальные, сохраняем порядок
  $seen = [];
  $out = [];
  foreach ($parts as $p) {
    if (isset($seen[$p])) continue;
    $seen[$p] = true;
    $out[] = $p;
  }
  return $out;
}

function find_ozon_category_pair_by_type(string $type): string {
  static $cache = []; // key => pair (or '')

  $type = trim($type);
  if ($type === '') return '';

  // нормализуем ключ кеша
  $key = mb_strtolower($type, 'UTF-8');
  if (array_key_exists($key, $cache)) return $cache[$key];

  // 1) пробуем полное совпадение по full_path / name
  $sql = "SELECT ozon_parent_id, ozon_leaf_id
          FROM feedtools_taxonomy_categories
          WHERE source='ozon' AND (full_path = ? OR name = ?)
          ORDER BY is_leaf DESC, level DESC
          LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute([$type, $type]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    // 2) fallback: LIKE (если в типе не полный путь)
    $sql = "SELECT ozon_parent_id, ozon_leaf_id
            FROM feedtools_taxonomy_categories
            WHERE source='ozon' AND (full_path LIKE ? OR name LIKE ?)
            ORDER BY is_leaf DESC, level DESC
            LIMIT 1";
    $stmt = db()->prepare($sql);
    $like = '%' . $type . '%';
    $stmt->execute([$like, $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  if (!$row) {
    $cache[$key] = '';
    return '';
  }

  $parent = trim((string)($row['ozon_parent_id'] ?? ''));
  $leaf   = trim((string)($row['ozon_leaf_id'] ?? ''));
  if ($parent === '' || $leaf === '') {
    $cache[$key] = '';
    return '';
  }

  $cache[$key] = $parent . '_' . $leaf;
  return $cache[$key];
}



$error = '';
$result = null;

try {
  if (empty($_FILES['xlsxfile']) || $_FILES['xlsxfile']['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Файл не загружен или произошла ошибка загрузки.');
  }

  $tmpPath = $_FILES['xlsxfile']['tmp_name'];
  $origName = $_FILES['xlsxfile']['name'];
  $bytes = (int)$_FILES['xlsxfile']['size'];

  if ($bytes <= 0) throw new RuntimeException('Пустой файл.');
  if ($bytes > ($cfg['limits']['max_upload_bytes'] ?? 50_000_000)) throw new RuntimeException('Файл слишком большой.');
  if (!preg_match('/\.xlsx$/i', $origName)) throw new RuntimeException('Нужен файл .xlsx');

  // читаем xlsx
  $wb = IOFactory::load($tmpPath);
  $sheet = $wb->getSheetByName('Шаблон');
  if (!$sheet) $sheet = $wb->getActiveSheet();

  $HEADER_ROW = 2;
  $DATA_START_ROW = 5;

  $highestCol = $sheet->getHighestColumn();
  $highestColIndex = Coordinate::columnIndexFromString($highestCol);

  // колонки: colIndex => ['raw','norm']
  $cols = [];
  for ($c = 1; $c <= $highestColIndex; $c++) {
    $raw = vstr($sheet->getCell(cellAddr($c, $HEADER_ROW))->getValue());
    if ($raw === '') continue;
    $cols[$c] = ['raw' => $raw, 'norm' => norm_header($raw)];
  }

  if (!$cols) throw new RuntimeException('Не найдены заголовки (строка 2).');

  // skip columns (не заполнять и не импортировать)
  $SKIP = array_fill_keys(array_map('norm_header', [
    '№',
    'Цена до скидки, руб.',
    'НДС, %*',
    'Рассрочка',
    'Баллы за отзывы',
    'SKU',
    'Штрихкод (Серийный номер / EAN)',
    'Ссылки на фото 360',
    'Артикул фото',
    'Минимальное количество оптом',
    'ТН ВЭД коды ЕАЭС',
    'Ошибка',
    'Предупреждение',
  ]), true);

  // constants: если есть колонка — ставим, иначе игнорируем
  $CONST = [
    norm_header('Количество заводских упаковок') => '1',
    norm_header('Наличие серийного номера')     => 'Нет',
    norm_header('Гарантийный срок')             => '1 год',
    norm_header('Срок службы, лет')             => '10',
  ];

  // mapping header -> rule (обратный экспорт)
  $H = [
    norm_header('Артикул') => 'offer_id',
    norm_header('Тип*') => 'ozon_category_from_type',

    norm_header('Название товара') => 'tag:name',
    norm_header('Цена, руб.*') => 'tag:price',
    norm_header('Цена, руб.')  => 'tag:price',

    // веса в шаблоне в граммах -> в датасете weight в кг
    norm_header('Вес в упаковке, г*') => 'tag_weight_from_grams',
    norm_header('Вес в упаковке, г')  => 'tag_weight_from_grams',
    norm_header('Вес товара, г')      => 'tag_weight_from_grams',

    // размеры в шаблоне в мм -> в датасете dimensions пишем L/W/H в мм
    norm_header('Длина упаковки, мм*') => 'dim:L',
    norm_header('Длина упаковки, мм')  => 'dim:L',
    norm_header('Ширина упаковки, мм*') => 'dim:W',
    norm_header('Ширина упаковки, мм')  => 'dim:W',
    norm_header('Высота упаковки, мм*') => 'dim:H',
    norm_header('Высота упаковки, мм')  => 'dim:H',

    norm_header('Ссылка на главное фото*') => 'pic:main',
    norm_header('Ссылка на главное фото')  => 'pic:main',
    norm_header('Ссылки на дополнительные фото') => 'pic:extra',

    norm_header('Бренд*') => 'tag:vendor',
    norm_header('Бренд')  => 'tag:vendor',

    norm_header('Название модели (для объединения в одну карточку)*') => 'tag:same_model',
    norm_header('Название модели (для объединения в одну карточку)')  => 'tag:same_model',
    norm_header('Название группы') => 'tag:same_model',

    norm_header('Аннотация') => 'tag:description',
    norm_header('#Хештеги') => 'tag:hashtags',

    // ВАЖНО: это param, не tag
    norm_header('Количество товара в УЕИ') => 'param:количество_в_единице_товара',
    norm_header('Количество в упаковке, шт') => 'param:количество_в_единице_товара',

    norm_header('Цвет товара')    => 'param:цвет',
    norm_header('Название цвета') => 'param:цвет',
  ];

  // ---- построение XML ----
  $dom = new DOMDocument('1.0', 'UTF-8');
  $dom->formatOutput = true;

  $yml = $dom->createElement('yml_catalog');
  $yml->setAttribute('date', date('Y-m-d H:i:s'));
  $dom->appendChild($yml);

  $shop = $dom->createElement('shop');
  $yml->appendChild($shop);

  $shop->appendChild($dom->createElement('name', 'feedtools-import'));
  $shop->appendChild($dom->createElement('company', 'feedtools-import'));
  $shop->appendChild($dom->createElement('url', 'https://example.local'));

  $currencies = $dom->createElement('currencies');
  $c = $dom->createElement('currency');
  $c->setAttribute('id', 'RUB');
  $c->setAttribute('rate', '1');
  $currencies->appendChild($c);
  $shop->appendChild($currencies);

  // пустые categories допустимы для импорта шаблона
  $categories = $dom->createElement('categories');
  $shop->appendChild($categories);

  $offersEl = $dom->createElement('offers');
  $shop->appendChild($offersEl);

  $row = $DATA_START_ROW;
  $imported = 0;

  // вспомогательные: какие колонки относятся к размерам
  $colDim = ['L' => null, 'W' => null, 'H' => null];
  $colPicMain = null;
  $colPicExtra = null;
  $colOfferId = null;

  foreach ($cols as $colIndex => $col) {
    $hn = $col['norm'];
    if ($hn === norm_header('Артикул')) $colOfferId = $colIndex;
    if ($hn === norm_header('Артикул*')) $colOfferId = $colIndex; // на всякий
    if ($hn === norm_header('Ссылка на главное фото')) $colPicMain = $colIndex;
    if ($hn === norm_header('Ссылка на главное фото*')) $colPicMain = $colIndex;
    if ($hn === norm_header('Ссылки на дополнительные фото')) $colPicExtra = $colIndex;

    if (isset($H[$hn]) && $H[$hn] === 'dim:L') $colDim['L'] = $colIndex;
    if (isset($H[$hn]) && $H[$hn] === 'dim:W') $colDim['W'] = $colIndex;
    if (isset($H[$hn]) && $H[$hn] === 'dim:H') $colDim['H'] = $colIndex;
  }

  if (!$colOfferId) {
    throw new RuntimeException('Не найдена колонка "Артикул*" / "Артикул".');
  }

  while (true) {
    // критерий конца: пустой артикул
    $offerId = vstr($sheet->getCell(cellAddr($colOfferId, $row))->getValue());
    if ($offerId === '') break;

    $offerEl = $dom->createElement('offer');
    $offerEl->setAttribute('id', $offerId);

    $tags = [];            // tag => string
    $params = [];          // name => list values
    $pictures = [];        // list
    $dimL = $dimW = $dimH = '';

    // читаем колонки строки
    foreach ($cols as $colIndex => $colInfo) {
      $rawHeader = $colInfo['raw'];
      $hn = $colInfo['norm'];

      if (isset($SKIP[$hn])) continue;

      $cellVal = vstr($sheet->getCell(cellAddr($colIndex, $row))->getValue());
      if ($cellVal === '' && !isset($CONST[$hn])) {
        continue;
      }

      // константы
      if (isset($CONST[$hn])) {
        if ($cellVal === '') $cellVal = $CONST[$hn];
      }

      // маппинг
      if (isset($H[$hn])) {
        $rule = $H[$hn];

        if ($rule === 'offer_id') {
          // уже в атрибуте id
          continue;
        }
if ($rule === 'ozon_category_from_type') {
  $pair = find_ozon_category_pair_by_type($cellVal);
  if ($pair !== '') $tags['ozon_category'] = $pair;
  continue;
}
        if ($rule === 'tag_weight_from_grams') {
          $kg = grams_to_kg_str($cellVal);
          if ($kg !== '') $tags['weight'] = $kg;
          continue;
        }

        if (strpos($rule, 'tag:') === 0) {
          $t = substr($rule, 4);
          if ($t === 'hashtags') $cellVal = hashtags_to_store($cellVal);
          if ($cellVal !== '') $tags[$t] = $cellVal;
          continue;
        }

        if ($rule === 'pic:main') {
          $pics = split_pictures($cellVal);
          foreach ($pics as $p) $pictures[] = $p;
          continue;
        }

        if ($rule === 'pic:extra') {
          $pics = split_pictures($cellVal);
          foreach ($pics as $p) $pictures[] = $p;
          continue;
        }

        if ($rule === 'dim:L') { $dimL = $cellVal; continue; }
        if ($rule === 'dim:W') { $dimW = $cellVal; continue; }
        if ($rule === 'dim:H') { $dimH = $cellVal; continue; }

        if (strpos($rule, 'param:') === 0) {
          $pname = substr($rule, 6);
          foreach (split_param_values($cellVal) as $v) {
            $v = trim($v);
            if ($v === '') continue;
            $params[$pname] ??= [];
            $params[$pname][] = $v;
          }
          continue;
        }
      }

      // Остальные колонки: это характеристики => param name == заголовок колонки (сырой заголовок)
      foreach (split_param_values($cellVal) as $v) {
        $v = trim($v);
        if ($v === '') continue;
        $params[$rawHeader] ??= [];
        $params[$rawHeader][] = $v;
      }
    }

    // dimensions: пишем только если есть хоть что-то
    $dimL = trim($dimL); $dimW = trim($dimW); $dimH = trim($dimH);
    if ($dimL !== '' || $dimW !== '' || $dimH !== '') {
      // нормализуем числа (оставляем как есть, но уберём пробелы)
      $dim = ($dimL !== '' ? $dimL : '') . '/' . ($dimW !== '' ? $dimW : '') . '/' . ($dimH !== '' ? $dimH : '');
      $dim = preg_replace('/\s+/u', '', $dim);
      $tags['dimensions'] = $dim;
    }

    // pictures: уникальные, порядок сохраняем
    $picsUniq = [];
    $seen = [];
    foreach ($pictures as $p) {
      $p = trim($p);
      if ($p === '') continue;
      if (isset($seen[$p])) continue;
      $seen[$p] = true;
      $picsUniq[] = $p;
    }

    // записываем теги (детерминированно)
$tagOrder = ['url','price','currencyId','categoryId','picture','vendor','vendorCode','model','same_model','name','description','weight','dimensions','hashtags'];

// helper: безопасно вставить текст в XML (без проблем с & и т.п.)
$appendTextEl = function(string $tag, string $val) use ($dom, $offerEl) {
  $el = $dom->createElement($tag);
  $el->appendChild($dom->createTextNode($val)); // важно: НЕ createElement(tag, value)
  $offerEl->appendChild($el);
};

// сначала то, что у нас есть из order
foreach ($tagOrder as $t) {
  if ($t === 'picture') continue;
  if (isset($tags[$t]) && $tags[$t] !== '') {
    $appendTextEl($t, (string)$tags[$t]);
  }
}

// остальные теги (если появятся)
foreach ($tags as $t => $v) {
  if (in_array($t, $tagOrder, true)) continue;
  if ($v === '') continue;
  $appendTextEl((string)$t, (string)$v);
}

// pictures
foreach ($picsUniq as $p) {
  if ($p === '') continue;
  $appendTextEl('picture', (string)$p);
}


    // params
    foreach ($params as $pname => $vals) {
      $vals = array_values(array_filter(array_map('trim', (array)$vals), fn($x) => $x !== ''));
      if (!$vals) continue;

      // уникальные значения
      $u = [];
      $s2 = [];
      foreach ($vals as $vv) {
        if (isset($s2[$vv])) continue;
        $s2[$vv] = true;
        $u[] = $vv;
      }

      foreach ($u as $vv) {
        $p = $dom->createElement('param');
        $p->setAttribute('name', $pname);
        $p->appendChild($dom->createTextNode($vv));
        $offerEl->appendChild($p);
      }
    }

    $offersEl->appendChild($offerEl);

    $imported++;
    $row++;
    // предохранитель от бесконечных листов
    if ($imported > 200000) throw new RuntimeException('Слишком много строк (предохранитель).');
  }

  if ($imported <= 0) {
    throw new RuntimeException('Не найдено ни одного товара (проверь: данные должны начинаться с 5-й строки, артикул в колонке Артикул*).');
  }

  // сохраняем XML как новый датасет (как upload.php)
  $uploadsDir = $cfg['paths']['uploads_dir'];
  if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true)) {
    throw new RuntimeException('Не удалось создать папку uploads.');
  }

  $safeBase = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', basename($origName));
  $safeBase = preg_replace('/\.xlsx$/i', '.xml', $safeBase);
  if ($safeBase === '' || $safeBase === '_') $safeBase = 'import.xml';

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

  // дедупликация
  $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
  $stmt->execute([$sha256]);
  $existing = $stmt->fetchColumn();
  if ($existing) {
    // уже есть такой
    @unlink($storedPath);
    header("Location: view.php?id=" . urlencode($existing));
    exit;
  }

  // анализ XML (как upload.php)
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

  header("Location: view.php?id=" . urlencode($datasetId));
  exit;

} catch (Throwable $e) {
  $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Import XLSX → Dataset</title>
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
  <h2>Импорт XLSX-шаблона → новый датасет</h2>

  <?php if ($error): ?>
    <p class="err"><b>Ошибка:</b> <?=h($error)?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <p class="muted">Лист: <b>Шаблон</b>. Заголовки: строка <b>2</b>. Данные: с строки <b>5</b>. Конец — пустой <b>Артикул*</b>.</p>
    <input type="file" name="xlsxfile" accept=".xlsx" required>
    <button type="submit">Импортировать</button>
  </form>
</div>

</body>
</html>
