<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
$viewRequestId = bin2hex(random_bytes(8));
$viewDiagMode = isset($_GET['diag']) && (string)$_GET['diag'] !== '0';
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/xml_scan.php'; // можно не подключать, если не нужно
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/op_registry.php';
require_once __DIR__ . '/../app/ozon_products.php';
require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/wildberries/WildberriesProducts.php';
require_once __DIR__ . '/../app/taxonomy/MarketplaceCategoryContext.php';
require_once __DIR__ . '/../app/llm/LLM.php';





function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function sanitize_description_html(string $html): string
{
  $html = str_replace(['<![CDATA[', ']]>'], '', $html);
  $html = preg_replace('~<!--.*?-->~s', '', $html);
  $html = preg_replace('~<\s*(script|style|iframe|object|embed|svg|canvas|form|input|button|textarea|select|video|audio|source|picture|img)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);
  $html = preg_replace('~<\s*(script|style|iframe|object|embed|svg|canvas|form|input|button|textarea|select|video|audio|source|picture|img)\b[^>]*\/?\s*>~is', '', (string)$html);

  $allowed = '<p><br><ul><ol><li><b><strong><i><em><span><div><h1><h2><h3><h4>';
  $html = strip_tags((string)$html, $allowed);

  // Оставляем только безопасные теги без любых inline-style/class/id/on*.
  $html = preg_replace_callback('~<\s*(/?)\s*(p|br|ul|ol|li|b|strong|i|em|span|div|h1|h2|h3|h4)\b[^>]*>~i', static function (array $m): string {
    $closing = ($m[1] ?? '') === '/';
    $tag = strtolower((string)$m[2]);
    if ($tag === 'br') return '<br>';
    return $closing ? "</{$tag}>" : "<{$tag}>";
  }, (string)$html);

  return trim((string)$html);
}

function parse_offer_block(XMLReader $r): array
{
  $offerId = (string)$r->getAttribute('id');
  $details = [];
  $pictures = [];
  $params = [];
  $params_kv = []; // name => [values...]
  $wb_params_kv = []; // [WB] name => [values...]
  $hashtags = [];

  $name = '';
  $desc = '';
  $url = '';
  $categoryId = '';
  $brand = '';
  $vendor = '';
  $article = '';
  $vendorCode = '';
  $count = 0;
  $stock = 0;
  $priceOriginal = null;
  $weight = null;
  $dimensions = '';



  $ozonCategory = '';
  $wbCategory = '';

  if ($r->hasAttributes) {
    while ($r->moveToNextAttribute()) {
      if ($r->name === 'id') continue;
      $details[] = '@' . $r->name . ': ' . $r->value;
    }
    $r->moveToElement();
  }

  $depth = $r->depth;

  while ($r->read()) {
    if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'offer' && $r->depth === $depth) {
      break;
    }
    if ($r->nodeType !== XMLReader::ELEMENT) continue;

    $tag = $r->localName ?: $r->name;


    if ($tag === 'name') {
      $name = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'categoryId' || $tag === 'category') {
      $categoryId = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }

    if ($tag === 'ozon_category') {
      $ozonCategory = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'wb_category' || $tag === 'wb_subject_id') {
      $wbCategory = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'article' || $tag === 'vendorCode') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($tag === 'article') {
        $article = $v;
      } else {
        $vendorCode = $v;
      }
      if ($v !== '') {
        $details[] = $tag . ': ' . $v;
      }
      continue;
    }
    if ($tag === 'brand') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $brand = $v;
        $details[] = 'brand: ' . $v;
      }
      continue;
    }
    if ($tag === 'vendor') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $vendor = $v;
        $details[] = 'vendor: ' . $v;
      }
      continue;
    }
    if ($tag === 'count') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $count = (int)$v;
        $details[] = 'count: ' . $count;
      }
      continue;
    }

    if ($tag === 'stock') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $stock = (int)$v;
        $details[] = 'stock: ' . $stock;
      }
      continue;
    }

    if ($tag === 'price_original') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $num = str_replace(["\xc2\xa0", ' '], '', $v);
        $num = str_replace(',', '.', $num);
        if (is_numeric($num)) {
          $priceOriginal = (float)$num;
          $details[] = 'price_original: ' . $v;
        }
      }
      continue;
    }

    if ($tag === 'weight') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $num = str_replace(["\xc2\xa0", ' '], '', $v);
        $num = str_replace(',', '.', $num);
        if (is_numeric($num)) {
          $weight = (float)$num;
          $details[] = 'weight: ' . $v;
        }
      }
      continue;
    }

    if ($tag === 'dimensions') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') {
        $dimensions = $v;
        $details[] = 'dimensions: ' . $v;
      }
      continue;
    }



    if ($tag === 'description') {
      // В XML description может быть:
      // 1) plain text внутри <description>...</description>
      // 2) HTML внутри <![CDATA[ ... ]]>
      // readString() корректно возвращает содержимое CDATA без маркеров <![CDATA[]]>.
      $v = (string)$r->readString();
      $v = html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
      // На всякий случай чистим маркеры, если где-то попали как текст.
      $v = str_replace(['<![CDATA[', ']]>'], '', $v);
      $desc = trim($v);
      continue;
    }
    if ($tag === 'url') {
      $url = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      continue;
    }
    if ($tag === 'picture') {
      $pic = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pic !== '') $pictures[] = $pic;
      continue;
    }
    if ($tag === 'param') {
      $pname = trim((string)$r->getAttribute('name'));
      $pval  = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pname !== '') {
        // строки как раньше (для UI)
        $params[] = $pname . ': ' . $pval;

        // KV для фильтров/фасетов
        if (!isset($params_kv[$pname])) $params_kv[$pname] = [];
        $params_kv[$pname][] = $pval;
      }
      continue;
    }
    if ($tag === 'wb_param') {
      $pname = trim((string)$r->getAttribute('name'));
      $pval  = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pname !== '') {
        $label = '[WB] ' . $pname;
        $params[] = $label . ': ' . $pval;
        if (!isset($wb_params_kv[$label])) $wb_params_kv[$label] = [];
        $wb_params_kv[$label][] = $pval;
      }
      continue;
    }
    if ($tag === 'hashtags') {
  $raw = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
  $parts = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);

  $set = [];
  foreach ($parts as $h) {
    $h = trim((string)$h);
    if ($h === '') continue;
    if ($h[0] !== '#') $h = '#'.$h;
    $set[$h] = true;
  }
  $hashtags = array_keys($set);
  if (!empty($hashtags)) {
  $details[] = 'hashtags: ' . implode(' ', $hashtags);
}

  continue;
}



    // Прочие теги: хотим показывать в "Прочие данные" всё, кроме <param>.
    // ВАЖНО: аккуратно читаем содержимое, чтобы не "съесть" вложенные элементы.
    // readInnerXml() вернёт текст/CDATA для простых тегов, а для вложенных — XML.
    if ($r->isEmptyElement) {
      // например <foo/>
      $details[] = $tag . ': ';
      continue;
    }

    $inner = (string)$r->readInnerXml();
    $v = trim(html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $v = str_replace(['<![CDATA[', ']]>'], '', $v);

    // Если внутри вложенный XML — берём только текст (чтобы не тащить теги в колонку)
    $v_text = trim(strip_tags($v));

    if ($v_text !== '') {
      $details[] = $tag . ': ' . $v_text;
    } else {
      // если текста нет — просто пометим наличие тега
      $details[] = $tag . ': ';
    }
    continue;

  }

  return [
    'id' => $offerId,
    'name' => $name,
    'category_id' => $categoryId,
    'ozon_category' => $ozonCategory,
    'wb_category' => $wbCategory,
    'wb_subject_id' => $wbCategory,
    'article' => $article,
    'vendorCode' => $vendorCode,
    'vendor_code' => $vendorCode,
    'brand_effective' => ($brand !== '' ? $brand : $vendor),

    'details' => $details,
    'description_html' => $desc,
    'params_lines' => $params,
    'params_kv' => array_replace($params_kv, $wb_params_kv),

    'url' => $url,
    'pictures' => $pictures,
    'count' => $count,
    'stock' => $stock,
    'price_original' => $priceOriginal,
    'weight' => $weight,
    'dimensions' => $dimensions,
    'hashtags' => $hashtags,


  ];
}

function build_category_path(string $categoryId, array $catMap): string
{
  $categoryId = trim($categoryId);
  if ($categoryId === '' || empty($catMap[$categoryId])) return '';

  $parts = [];
  $seen = [];
  $cur = $categoryId;

  // защита от циклов + ограничение глубины
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


function url_with(array $extra): string
{
  $q = array_merge($_GET, $extra);
  return '?' . http_build_query($q);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
  );

  session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
  ]);

  session_start();
}

function ft_request_has_filter_payload(array $src): bool
{
  if (array_key_exists('filter_apply', $src)) return true;
  foreach (['f_catpath', 'f_ozoncat', 'f_wbcat', 'f_brand', 'f_hashtag', 'f_param', 'q_name', 'f_instock', 'f_not_in_ozon', 'f_not_in_ozon_archive', 'f_not_in_wb', 'f_has_picture', 'f_not_bulky_ozon', 'f_price_min', 'f_price_max', 'f_stock_min', 'f_stock_max'] as $key) {
    if (array_key_exists($key, $src)) return true;
  }
  return false;
}

function ft_parse_filter_float($value): ?float
{
  $value = trim((string)$value);
  if ($value === '') return null;
  $value = str_replace(["\xc2\xa0", ' '], '', $value);
  $value = str_replace(',', '.', $value);
  return is_numeric($value) ? (float)$value : null;
}

function ft_parse_filter_int($value): ?int
{
  $value = trim((string)$value);
  if ($value === '') return null;
  $value = str_replace(["\xc2\xa0", ' '], '', $value);
  return preg_match('/^-?\d+$/', $value) ? (int)$value : null;
}

function ft_parse_dimensions_triplet(string $raw): array
{
  $raw = trim((string)$raw);
  if ($raw === '') return ['', '', ''];
  $parts = preg_split('~\s*[xх×*/]\s*~u', $raw) ?: [];
  $parts = array_map('trim', $parts);
  return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
}

function ft_detect_dimensions_unit(string $raw): string
{
  $lc = mb_strtolower(trim((string)$raw), 'UTF-8');
  if ($lc === '') return '';
  if (strpos($lc, 'мм') !== false || preg_match('/\bmm\b/u', $lc)) return 'mm';
  if (strpos($lc, 'см') !== false || preg_match('/\bcm\b/u', $lc)) return 'cm';
  return '';
}

function ft_dimensions_value_to_cm(string $value, bool $inputIsCm): ?float
{
  $value = trim(str_replace(',', '.', (string)$value));
  if ($value === '' || !is_numeric($value)) return null;
  $n = (float)$value;
  return $inputIsCm ? $n : ($n / 10.0);
}

function ft_offer_is_ozon_bulky(array $offer): bool
{
  $dimRaw = trim((string)($offer['dimensions'] ?? ''));
  if ($dimRaw === '') return false;

  [$lRaw, $wRaw, $hRaw] = ft_parse_dimensions_triplet($dimRaw);
  $unit = ft_detect_dimensions_unit($dimRaw);
  if ($unit === 'cm') {
    $dimInputIsCm = true;
  } elseif ($unit === 'mm') {
    $dimInputIsCm = false;
  } else {
    $nums = [];
    foreach ([$lRaw, $wRaw, $hRaw] as $v) {
      $v = trim(str_replace(',', '.', (string)$v));
      if ($v !== '' && is_numeric($v)) $nums[] = (float)$v;
    }
    $dimInputIsCm = ($nums && max($nums) < 50.0);
  }

  $dimsCm = [];
  foreach ([$lRaw, $wRaw, $hRaw] as $v) {
    $cm = ft_dimensions_value_to_cm($v, $dimInputIsCm);
    if ($cm !== null && $cm > 0) $dimsCm[] = $cm;
  }

  if (!$dimsCm) return false;

  $maxSide = max($dimsCm);
  if ($maxSide >= 200.0) return true;

  if (count($dimsCm) === 3) {
    $volumeLiters = ($dimsCm[0] * $dimsCm[1] * $dimsCm[2]) / 1000.0;
    if ($volumeLiters >= 500.0) return true;
  }

  $weightKg = isset($offer['weight']) && $offer['weight'] !== null ? (float)$offer['weight'] : null;
  if ($weightKg !== null && $weightKg >= 35.0 && $weightKg <= 125.0) return true;

  return false;
}

function ft_dataset_marketplace_connections_ensure(): void
{
  $pdo = db();
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_datasets',
    'ozon_connection_id',
    "ALTER TABLE feedtools_datasets ADD COLUMN ozon_connection_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER warnings_json"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_datasets',
    'wb_connection_id',
    "ALTER TABLE feedtools_datasets ADD COLUMN wb_connection_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER ozon_connection_id"
  );
  ozon_products_table_add_column_if_missing(
    $pdo,
    'feedtools_datasets',
    'yandex_connection_id',
    "ALTER TABLE feedtools_datasets ADD COLUMN yandex_connection_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER wb_connection_id"
  );
}

function ft_connection_id_exists(array $connections, int $id): bool
{
  if ($id <= 0) return false;
  foreach ($connections as $connection) {
    if ((int)($connection['id'] ?? 0) === $id) return true;
  }
  return false;
}

function ft_first_connection_id(array $connections): int
{
  $first = reset($connections);
  return is_array($first) ? (int)($first['id'] ?? 0) : 0;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
ft_dataset_marketplace_connections_ensure();
$filtersSessionKey = 'feedtools_view_filters';

if ($id > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $filterAction = trim((string)($_POST['filter_action'] ?? ''));
  if ($filterAction === 'apply' || $filterAction === 'clear') {
    if (!isset($_SESSION[$filtersSessionKey]) || !is_array($_SESSION[$filtersSessionKey])) {
      $_SESSION[$filtersSessionKey] = [];
    }

    if ($filterAction === 'clear') {
      unset($_SESSION[$filtersSessionKey][$id]);
    } else {
      $_SESSION[$filtersSessionKey][$id] = [
        'f_catpath' => $_POST['f_catpath'] ?? [],
        'f_ozoncat' => $_POST['f_ozoncat'] ?? [],
        'f_wbcat' => $_POST['f_wbcat'] ?? [],
        'f_brand' => $_POST['f_brand'] ?? [],
        'f_hashtag' => $_POST['f_hashtag'] ?? [],
        'f_param' => $_POST['f_param'] ?? [],
        'q_name' => $_POST['q_name'] ?? '',
        'f_instock' => $_POST['f_instock'] ?? '',
        'f_not_in_ozon' => $_POST['f_not_in_ozon'] ?? '',
        'f_not_in_ozon_archive' => $_POST['f_not_in_ozon_archive'] ?? '',
        'f_not_in_wb' => $_POST['f_not_in_wb'] ?? '',
        'f_has_picture' => $_POST['f_has_picture'] ?? '',
        'f_not_bulky_ozon' => $_POST['f_not_bulky_ozon'] ?? '',
        'f_price_min' => $_POST['f_price_min'] ?? '',
        'f_price_max' => $_POST['f_price_max'] ?? '',
        'f_stock_min' => $_POST['f_stock_min'] ?? '',
        'f_stock_max' => $_POST['f_stock_max'] ?? '',
      ];
    }

    $redirect = ['id' => $id, 'page' => 1];
    $limitFromPost = trim((string)($_POST['limit'] ?? ''));
    if ($limitFromPost !== '') {
      $redirect['limit'] = $limitFromPost;
    }

    header('Location: view.php?' . http_build_query($redirect), true, 303);
    exit;
  }
}

$limitRaw = $_GET['limit'] ?? '10';
$limit = 10;
$showAll = false;
if ($limitRaw === 'all') {
  $showAll = true;
} else {
  $limit = (int)$limitRaw;
  if (!in_array($limit, [5, 10, 20, 50, 100], true)) $limit = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// ---- offers filters (server-side) ----
const EMPTY_TOKEN = '__EMPTY__';

function norm_filter_array($v): array
{
  if ($v === null) return [];
  if (!is_array($v)) $v = [$v];
  $out = [];
  foreach ($v as $x) {
    $s = trim((string)$x);
    if ($s === '') continue;
    if ($s === EMPTY_TOKEN) $s = ''; // empty marker
    $out[] = $s;
  }
  $out = array_values(array_unique($out));
  return $out;
}

$savedFilterState = [];
if ($id > 0 && array_key_exists('filter_clear', $_GET)) {
  unset($_SESSION[$filtersSessionKey][$id]);
}
if ($id > 0 && isset($_SESSION[$filtersSessionKey][$id]) && is_array($_SESSION[$filtersSessionKey][$id])) {
  $savedFilterState = $_SESSION[$filtersSessionKey][$id];
}

$requestFilterSource = ft_request_has_filter_payload($_GET) ? $_GET : $savedFilterState;

if ($id > 0 && ft_request_has_filter_payload($_GET)) {
  if (!isset($_SESSION[$filtersSessionKey]) || !is_array($_SESSION[$filtersSessionKey])) {
    $_SESSION[$filtersSessionKey] = [];
  }
  $_SESSION[$filtersSessionKey][$id] = [
    'f_catpath' => $_GET['f_catpath'] ?? [],
    'f_ozoncat' => $_GET['f_ozoncat'] ?? [],
    'f_wbcat' => $_GET['f_wbcat'] ?? [],
    'f_brand' => $_GET['f_brand'] ?? [],
    'f_hashtag' => $_GET['f_hashtag'] ?? [],
    'f_param' => $_GET['f_param'] ?? [],
    'q_name' => $_GET['q_name'] ?? '',
    'f_instock' => $_GET['f_instock'] ?? '',
    'f_not_in_ozon' => $_GET['f_not_in_ozon'] ?? '',
    'f_not_in_ozon_archive' => $_GET['f_not_in_ozon_archive'] ?? '',
    'f_not_in_wb' => $_GET['f_not_in_wb'] ?? '',
    'f_has_picture' => $_GET['f_has_picture'] ?? '',
    'f_not_bulky_ozon' => $_GET['f_not_bulky_ozon'] ?? '',
    'f_price_min' => $_GET['f_price_min'] ?? '',
    'f_price_max' => $_GET['f_price_max'] ?? '',
    'f_stock_min' => $_GET['f_stock_min'] ?? '',
    'f_stock_max' => $_GET['f_stock_max'] ?? '',
  ];
}

$filterCatpath = norm_filter_array($requestFilterSource['f_catpath'] ?? []);
$filterOzoncat = norm_filter_array($requestFilterSource['f_ozoncat'] ?? []);
$filterWbcat = norm_filter_array($requestFilterSource['f_wbcat'] ?? []);
$filterBrand   = norm_filter_array($requestFilterSource['f_brand'] ?? []);
$filterHashtags = norm_filter_array($requestFilterSource['f_hashtag'] ?? []);

$filterInStock = ((string)($requestFilterSource['f_instock'] ?? '') === '1');
$filterNotInOzon = ((string)($requestFilterSource['f_not_in_ozon'] ?? '') === '1');
$filterNotInOzonArchive = ((string)($requestFilterSource['f_not_in_ozon_archive'] ?? '') === '1');
$filterNotInWb = ((string)($requestFilterSource['f_not_in_wb'] ?? '') === '1');
$filterHasPicture = ((string)($requestFilterSource['f_has_picture'] ?? '') === '1');
$filterNotBulkyOzon = ((string)($requestFilterSource['f_not_bulky_ozon'] ?? '') === '1');
$filterPriceMin = ft_parse_filter_float($requestFilterSource['f_price_min'] ?? '');
$filterPriceMax = ft_parse_filter_float($requestFilterSource['f_price_max'] ?? '');
$filterStockMin = ft_parse_filter_int($requestFilterSource['f_stock_min'] ?? '');
$filterStockMax = ft_parse_filter_int($requestFilterSource['f_stock_max'] ?? '');
$filterPriceMinRaw = trim((string)($requestFilterSource['f_price_min'] ?? ''));
$filterPriceMaxRaw = trim((string)($requestFilterSource['f_price_max'] ?? ''));
$filterStockMinRaw = trim((string)($requestFilterSource['f_stock_min'] ?? ''));
$filterStockMaxRaw = trim((string)($requestFilterSource['f_stock_max'] ?? ''));

// Param filters: f_param[<param_name>] = <value>
// Param filters: f_param[<param_name>] = [values...]
$filterParams = []; // pname => array(values) ; value '' означает "пусто/нет значения"
$filterParamsRaw = $requestFilterSource['f_param'] ?? [];
if (is_array($filterParamsRaw)) {
  foreach ($filterParamsRaw as $k => $vv) {
    $pname = trim((string)$k);
    if ($pname === '') continue;

    $vals = norm_filter_array($vv); // умеет и строку, и массив; EMPTY_TOKEN -> ''
    if (empty($vals)) continue;

    $filterParams[$pname] = $vals;
  }
}

// Для UI: '' -> EMPTY_TOKEN (в каждом значении)
$filterParamsUi = [];
foreach ($filterParams as $k => $vals) {
  $out = [];
  foreach ($vals as $v) $out[] = ($v === '' ? EMPTY_TOKEN : $v);
  $filterParamsUi[$k] = $out;
}

$filterHashtagsUi = $filterHashtags; // они уже массив строк '#...'





$filterNameRaw = trim((string)($requestFilterSource['q_name'] ?? ''));
$filterNameRaw = preg_replace('/\s+/u', ' ', $filterNameRaw);

function ft_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function ft_query_matches_offer(string $offerId, string $name, array $tokens): bool
{
  // 1) если ВСЕ токены найдены в offer_id — матч (приоритет артикула)
  // 2) иначе если ВСЕ токены найдены в названии — матч
  // 3) иначе — не матч
  $oid = ft_lc($offerId);
  $n = ft_lc($name);

  $allInId = true;
  foreach ($tokens as $t) {
    $t = (string)$t;
    if ($t === '') continue;
    if (strpos($oid, $t) === false) {
      $allInId = false;
      break;
    }
  }
  if ($allInId) return true;

  foreach ($tokens as $t) {
    $t = (string)$t;
    if ($t === '') continue;
    if (strpos($n, $t) === false) return false;
  }
  return true;
}

function ft_op_ui_label(string $key, array $def): string
{
  $title = trim((string)($def['title'] ?? $key));
  $ru = $title;

  if (preg_match('/^' . preg_quote($key, '/') . '\s*\((.+)\)\s*$/u', $title, $m)) {
    $ru = trim((string)$m[1]);
  }

  return $ru . ' (' . $key . ')';
}


$filterNameTokens = [];
if ($filterNameRaw !== '') {
  $parts = preg_split('/\s+/u', ft_lc($filterNameRaw));
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p === '') continue;
    $filterNameTokens[] = $p;
  }
  $filterNameTokens = array_values(array_unique($filterNameTokens));
}

$filterCatSet = $filterCatpath ? array_fill_keys($filterCatpath, true) : null;
$filterOzonSet = $filterOzoncat ? array_fill_keys($filterOzoncat, true) : null;
$filterWbSet = $filterWbcat ? array_fill_keys($filterWbcat, true) : null;
$filterBrandSet = $filterBrand ? array_fill_keys($filterBrand, true) : null;

$filtersActive = ($filterCatSet !== null || $filterOzonSet !== null || $filterWbSet !== null || $filterBrandSet !== null || !empty($filterNameTokens) || $filterInStock || $filterNotInOzon || $filterNotInOzonArchive || $filterNotInWb || $filterHasPicture || $filterNotBulkyOzon || !empty($filterParams) || !empty($filterHashtags) || $filterPriceMin !== null || $filterPriceMax !== null || $filterStockMin !== null || $filterStockMax !== null);



$row = null;

if ($id > 0) {
  $stmt = db()->prepare("SELECT * FROM feedtools_datasets WHERE id = ?");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
}

if (!$row) {
  header('HTTP/1.1 404 Not Found');
  echo "Dataset not found";
  exit;
}

if (supplier_products_is_dataset_row($row)) {
  header('Location: supplier_products_view.php?id=' . urlencode((string)$row['id']), true, 303);
  exit;
}

$ozonConnections = ozon_price_connection_list($cfg, 'ozon');
$wbConnections = ozon_price_connection_list($cfg, 'wb');
$yandexConnections = ozon_price_connection_list($cfg, 'yandex_market');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && trim((string)($_POST['dataset_action'] ?? '')) === 'save_marketplace_connections') {
  $postedOzonConnectionId = (int)($_POST['ozon_connection_id'] ?? 0);
  $postedWbConnectionId = (int)($_POST['wb_connection_id'] ?? 0);
  $postedYandexConnectionId = (int)($_POST['yandex_connection_id'] ?? 0);

  $postedOzonConnectionId = ft_connection_id_exists($ozonConnections, $postedOzonConnectionId) ? $postedOzonConnectionId : 0;
  $postedWbConnectionId = ft_connection_id_exists($wbConnections, $postedWbConnectionId) ? $postedWbConnectionId : 0;
  $postedYandexConnectionId = ft_connection_id_exists($yandexConnections, $postedYandexConnectionId) ? $postedYandexConnectionId : 0;

  $st = db()->prepare("
    UPDATE feedtools_datasets
    SET ozon_connection_id = ?, wb_connection_id = ?, yandex_connection_id = ?
    WHERE id = ?
  ");
  $st->execute([
    $postedOzonConnectionId > 0 ? $postedOzonConnectionId : null,
    $postedWbConnectionId > 0 ? $postedWbConnectionId : null,
    $postedYandexConnectionId > 0 ? $postedYandexConnectionId : null,
    (int)$row['id'],
  ]);

  $redirect = ['id' => (int)$row['id'], 'marketplace_connections_saved' => 1];
  $limitFromPost = trim((string)($_POST['limit'] ?? ''));
  if ($limitFromPost !== '') {
    $redirect['limit'] = $limitFromPost;
  }
  header('Location: view.php?' . http_build_query($redirect), true, 303);
  exit;
}

$datasetOzonConnectionId = (int)($row['ozon_connection_id'] ?? 0);
$datasetWbConnectionId = (int)($row['wb_connection_id'] ?? 0);
$datasetYandexConnectionId = (int)($row['yandex_connection_id'] ?? 0);

if (!ft_connection_id_exists($ozonConnections, $datasetOzonConnectionId)) {
  $datasetOzonConnectionId = ft_first_connection_id($ozonConnections);
}
if (!ft_connection_id_exists($wbConnections, $datasetWbConnectionId)) {
  $datasetWbConnectionId = ft_first_connection_id($wbConnections);
}
if (!ft_connection_id_exists($yandexConnections, $datasetYandexConnectionId)) {
  $datasetYandexConnectionId = ft_first_connection_id($yandexConnections);
}

$ops = ops_list_by_dataset((int)$row['id'], 15);
$reg = op_registry();
$llmModelDefault = LLM::modelForOp($cfg, []);
$llmModelOptions = LLM::modelOptions($cfg);
if (!$llmModelOptions) {
  $llmModelOptions = [$llmModelDefault];
}

// Скрываем эти операции только в выпадающем списке на странице датасета
$hide_ops_on_dataset_page = [
  'taxonomy_import_ozon' => true,
  'taxonomy_import_ozon_api' => true,
  'taxonomy_import_wb_api' => true,
  'set_ozon_category' => true,
  'set_wb_category' => true,
  'set_wb_subject' => true,
  'gpt_fill_offer_params_wb' => true,
  'run_pipeline' => true,
  'ozon_sync_products' => true,
  'ozon_sync_actions' => true,
  'ozon_push_selected_feeds' => true,
  'wb_sync_products' => true,
];

$hide_ops_in_pipeline = [
  'gpt_rewrite_title_marketplace' => true,
  'update_stock_from_feed' => true,
  'ozon_sync_products' => true,
  'wb_sync_products' => true,

  // 'gpt_fill_offer_params' => true,
];


// UI-версия реестра (для селекта и описаний на этой странице)
$reg_ui = array_diff_key($reg, $hide_ops_on_dataset_page);




$warnings = [];
if (!empty($row['warnings_json'])) {
  $warnings = json_decode($row['warnings_json'], true) ?: [];
}

$inlineOpId = isset($_GET['inline_op']) ? (int)$_GET['inline_op'] : 0;
$inlineError = trim((string)($_GET['inline_error'] ?? ''));


$path = $row['stored_path'];
$ozonOfferSet = null; // позже заполним по offer_id из $offers
$ozonFilterError = '';

$ozonArchiveSet = null; // offer_id => true (есть в архиве Ozon, включая неактивные)
$ozonScope = null;

if (($filterNotInOzon || $filterNotInOzonArchive) && $ozonFilterError === '') {
  try {
    if ($datasetOzonConnectionId <= 0) {
      throw new RuntimeException('Для проверки наличия на Ozon выбери Ozon-подключение в настройках датасета.');
    }
    $ozonScope = ozon_products_scope_from_ref($datasetOzonConnectionId, $cfg);
  } catch (Throwable $e) {
    $ozonFilterError = $e->getMessage();
    $ozonScope = null;
  }
}

$ozonPresentSet = null; // offer_id => true (есть в Ozon)
if ($filterNotInOzon && $ozonFilterError === '' && is_array($ozonScope)) {
  try {
    [$ozonWhereSql, $ozonWhereArgs] = ozon_products_scope_clause($ozonScope);
    $ozonPresentSet = [];
    $pdo = db();
    $st = $pdo->prepare("
      SELECT offer_id
      FROM feedtools_ozon_products
      WHERE {$ozonWhereSql}
        AND is_active = 1
    ");
    $st->execute($ozonWhereArgs);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = trim((string)($r['offer_id'] ?? ''));
      if ($id !== '') $ozonPresentSet[$id] = true;
    }
  } catch (Throwable $e) {
    $ozonFilterError = $e->getMessage();
    $ozonPresentSet = null;
  }
}

$wbPresentSet = null; // vendorCode / article / offer_id => true (есть на Wildberries)
$wbFilterError = '';
$wbFilterWarning = '';
$wbFilterMeta = null;
if ($filterNotInWb) {
  try {
    if ($datasetWbConnectionId <= 0) {
      throw new RuntimeException('Для проверки наличия на WB выбери WB-подключение в настройках датасета.');
    }
    $pdo = db();
    $wbPresentSet = wb_products_load_present_set($pdo, $datasetWbConnectionId);
    $wbStats = wb_products_stats($pdo, $datasetWbConnectionId);
    $wbFilterMeta = [
      'count' => count($wbPresentSet),
      'source' => 'db',
      'last_success_at' => (string)($wbStats['last_success_at'] ?? ''),
      'last_success_total' => (int)($wbStats['last_success_total'] ?? 0),
    ];
    if (($wbFilterMeta['last_success_at'] ?? '') === '') {
      $wbFilterWarning = 'Список товаров WB ещё не синхронизирован. Запусти операцию wb_sync_products, чтобы фильтр работал по актуальной базе.';
      $wbPresentSet = null;
    }
  } catch (Throwable $e) {
    $wbFilterError = $e->getMessage();
    $wbPresentSet = null;
  }
}


if ($filterNotInOzonArchive && $ozonFilterError === '' && is_array($ozonScope)) {
  try {
    [$ozonWhereSql, $ozonWhereArgs] = ozon_products_scope_clause($ozonScope);
    $ozonArchiveSet = [];
    $pdo = db();
    $st = $pdo->prepare("
      SELECT offer_id
      FROM feedtools_ozon_products
      WHERE {$ozonWhereSql}
        AND is_archived = 1
    ");
    $st->execute($ozonWhereArgs);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = trim((string)($r['offer_id'] ?? ''));
      if ($id !== '') $ozonArchiveSet[$id] = true;
    }
  } catch (Throwable $e) {
    $ozonFilterError = $e->getMessage();
    $ozonArchiveSet = null;
  }
}



$offers = [];
$allOffersTotal = (int)$row['offers_count'];
$totalOffers = $allOffersTotal;

$catMap = []; // category_id => ['name' => ..., 'parentId' => ...]
$facets = [
  'catpath' => [],
  'ozoncat' => [],
  'wbcat' => [],
  'brand'   => [],
];

$filteredTotal = 0;
// how many offers exist in the file (computed during scan; used if dataset counter is missing)
$scannedOffersTotal = 0;

// offer IDs for "select all across all pages" (current filtered set)
$matchingOfferIds = [];
$matchingOfferIdsTruncated = false;
$matchingOfferIdsMax = 50000;
$matchingOfferIdsCount = 0;


$pathIsLocalFile = $path !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) && is_file($path);
if ($pathIsLocalFile) {
  $reader = new XMLReader();
  if ($reader->open($path, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {

    $startMatch = 0;
    $endMatch = PHP_INT_MAX;
    if (!$showAll) {
      $startMatch = ($page - 1) * $limit;
      $endMatch = $startMatch + $limit;
    }

    $facetCat = [];
    $facetOzon = [];
    $facetWb = [];
    $facetBrand = [];

    $facetParams = []; // pname => [pvalToken => true]
    $facetHashtag = [];



    $inCategories = false;

    while ($reader->read()) {

      // Track <categories> block to avoid collisions and extra parsing.
      if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'categories') {
        $inCategories = true;
        continue;
      }
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'categories') {
        $inCategories = false;
        continue;
      }

      // Parse category definitions: <categories><category id=".." parentId="..">Name</category></categories>
      if ($inCategories && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
        $cid = trim((string)$reader->getAttribute('id'));
        if ($cid !== '') {
          $pid = trim((string)$reader->getAttribute('parentId'));
          $cname = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
          $catMap[$cid] = ['name' => $cname, 'parentId' => $pid];
        } else {
          $reader->readString(); // consume node
        }
        continue;
      }

      // Parse offers (offer subtree is consumed by parse_offer_block)
      if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
        $scannedOffersTotal++;
        $o = parse_offer_block($reader);


        $cid = (string)($o['category_id'] ?? '');
        $o['category_path'] = build_category_path($cid, $catMap);

        $vCat = (string)($o['category_path'] ?? '');
        $vOzon = (string)($o['ozon_category'] ?? '');
        $vWb = (string)($o['wb_category'] ?? ($o['wb_subject_id'] ?? ''));
        $vBrand = (string)($o['brand_effective'] ?? '');

        // Facets across the whole dataset
        $facetCat[$vCat === '' ? EMPTY_TOKEN : $vCat] = true;
        $facetOzon[$vOzon === '' ? EMPTY_TOKEN : $vOzon] = true;
        $facetWb[$vWb === '' ? EMPTY_TOKEN : $vWb] = true;
        $facetBrand[$vBrand === '' ? EMPTY_TOKEN : $vBrand] = true;

        $hs = (array)($o['hashtags'] ?? []);
foreach ($hs as $h) {
  $h = trim((string)$h);
  if ($h === '') continue;
  $facetHashtag[$h] = true;
}


        // Facets: params
        $pmap = (array)($o['params_kv'] ?? []);
        foreach ($pmap as $pname => $vals) {
          $pname = trim((string)$pname);
          if ($pname === '') continue;

          if (!isset($facetParams[$pname])) $facetParams[$pname] = [];

          if (!is_array($vals)) $vals = [$vals];
          foreach ($vals as $pv) {
            $pv = trim((string)$pv);
            $tok = ($pv === '' ? EMPTY_TOKEN : $pv);
            $facetParams[$pname][$tok] = true;
          }
        }


        // Apply server-side filters (empty string represents missing value)
        $ok = true;
        if ($filterCatSet !== null)  $ok = $ok && isset($filterCatSet[$vCat]);
        if ($filterOzonSet !== null) $ok = $ok && isset($filterOzonSet[$vOzon]);
        if ($filterWbSet !== null) $ok = $ok && isset($filterWbSet[$vWb]);
        if ($filterBrandSet !== null) $ok = $ok && isset($filterBrandSet[$vBrand]);
if ($ok && !empty($filterHashtags)) {
  $hs = (array)($o['hashtags'] ?? []);
  $set = [];
  foreach ($hs as $h) {
    $h = trim((string)$h);
    if ($h !== '') $set[$h] = true;
  }

  $hit = false;
  foreach ($filterHashtags as $want) {
    $want = trim((string)$want);
    if ($want === '') continue;
    if ($want[0] !== '#') $want = '#'.$want;
    if (isset($set[$want])) { $hit = true; break; }
  }

  if (!$hit) $ok = false;
}


        if (!empty($filterNameTokens)) {
          $ok = $ok && ft_query_matches_offer((string)($o['id'] ?? ''), (string)($o['name'] ?? ''), $filterNameTokens);
        }

        
        // Param filters (multi-select)
if ($ok && !empty($filterParams)) {
  $pmapOffer = (array)($o['params_kv'] ?? []);

  foreach ($filterParams as $fpName => $fpVals) {
    $fpName = (string)$fpName;
    if ($fpName === '') continue;

    if (!is_array($fpVals)) $fpVals = [$fpVals];
    $fpVals = array_values(array_unique($fpVals)); // на всякий

    // Офферные значения по этой характеристике
    $offerVals = [];
    if (isset($pmapOffer[$fpName])) {
      $ov = $pmapOffer[$fpName];
      if (!is_array($ov)) $ov = [$ov];
      foreach ($ov as $x) $offerVals[] = trim((string)$x);
    }

    $match = false;

    // Если выбран вариант "пусто" (''), то матч:
    // - параметр отсутствует
    // - или есть пустое значение
    if (in_array('', $fpVals, true)) {
      if (!isset($pmapOffer[$fpName])) {
        $match = true;
      } else {
        foreach ($offerVals as $x) {
          if ($x === '') { $match = true; break; }
        }
      }
    }

    // Иначе (или дополнительно) матч по любому выбранному НЕпустому значению
    if (!$match) {
      $want = [];
      foreach ($fpVals as $v) {
        $v = (string)$v;
        if ($v === '') continue;
        $want[$v] = true;
      }
      if (!empty($want)) {
        foreach ($offerVals as $x) {
          if (isset($want[$x])) { $match = true; break; }
        }
      }
    }

    if (!$match) { $ok = false; break; }
  }
}



        if ($filterInStock) {
          $cnt = (int)($o['count'] ?? 0);
          $stk = (int)($o['stock'] ?? 0);
          $ok = $ok && (($cnt > 0) || ($stk > 0));
        }

        if ($ok && $filterHasPicture) {
          $pics = (array)($o['pictures'] ?? []);
          $hasPicture = false;
          foreach ($pics as $pic) {
            if (trim((string)$pic) !== '') {
              $hasPicture = true;
              break;
            }
          }
          $ok = $ok && $hasPicture;
        }

        if ($ok && $filterNotBulkyOzon) {
          $ok = $ok && !ft_offer_is_ozon_bulky($o);
        }

        if ($ok && ($filterPriceMin !== null || $filterPriceMax !== null)) {
          $priceOriginal = isset($o['price_original']) && $o['price_original'] !== null ? (float)$o['price_original'] : null;
          if ($filterPriceMin !== null) {
            $ok = $ok && ($priceOriginal !== null) && ($priceOriginal >= $filterPriceMin);
          }
          if ($ok && $filterPriceMax !== null) {
            $ok = $ok && ($priceOriginal !== null) && ($priceOriginal <= $filterPriceMax);
          }
        }

        if ($ok && ($filterStockMin !== null || $filterStockMax !== null)) {
          $cnt = (int)($o['count'] ?? 0);
          $stk = (int)($o['stock'] ?? 0);
          $effectiveStock = max($cnt, $stk);
          if ($filterStockMin !== null) {
            $ok = $ok && ($effectiveStock >= $filterStockMin);
          }
          if ($ok && $filterStockMax !== null) {
            $ok = $ok && ($effectiveStock <= $filterStockMax);
          }
        }

        if ($filterNotInOzon && $ozonFilterError === '' && is_array($ozonPresentSet)) {
          $oid = trim((string)($o['id'] ?? ''));
          $ok = $ok && ($oid !== '') && !isset($ozonPresentSet[$oid]);
        }

        if ($filterNotInOzonArchive && $ozonFilterError === '' && is_array($ozonArchiveSet)) {
          $oid = trim((string)($o['id'] ?? ''));
          $ok = $ok && ($oid !== '') && !isset($ozonArchiveSet[$oid]);
        }

        if ($filterNotInWb && $wbFilterError === '' && is_array($wbPresentSet)) {
          $ok = $ok && !wb_products_offer_exists($o, $wbPresentSet);
        }





        if ($ok) {
          // collect IDs for "select all across all pages" (cap to avoid huge POST/localStorage)
          $oidAll = (string)($o['id'] ?? '');
          if ($oidAll !== '' && !$matchingOfferIdsTruncated) {
            if ($matchingOfferIdsCount < $matchingOfferIdsMax) {
              $matchingOfferIds[] = $oidAll;
              $matchingOfferIdsCount++;
            } else {
              $matchingOfferIdsTruncated = true;
            }
          }

          if ($filteredTotal >= $startMatch && $filteredTotal < $endMatch) {
            $offers[] = $o;
          }
          $filteredTotal++;
        }
        continue;
      }
    }

    $reader->close();

    // normalize dataset total counter if it wasn't computed earlier
    if ($allOffersTotal <= 0) {
      $allOffersTotal = $scannedOffersTotal;
    }

    // Totals for pagination
    $totalOffers = $filtersActive ? $filteredTotal : ($allOffersTotal > 0 ? $allOffersTotal : $filteredTotal);


    $cmp = function ($a, $b) {
      $la = ($a === EMPTY_TOKEN) ? '∅ (пусто)' : $a;
      $lb = ($b === EMPTY_TOKEN) ? '∅ (пусто)' : $b;
      return strcasecmp($la, $lb);
    };

    // Facets to arrays (sorted)
    $facets['catpath'] = array_keys($facetCat);
    $facets['ozoncat'] = array_keys($facetOzon);
    $facets['wbcat'] = array_keys($facetWb);
    $facets['brand'] = array_keys($facetBrand);
    $facets['hashtag'] = array_keys($facetHashtag);


    usort($facets['catpath'], $cmp);
    usort($facets['ozoncat'], $cmp);
    usort($facets['wbcat'], $cmp);
    usort($facets['brand'], $cmp);
    usort($facets['hashtag'], $cmp);

    $facets['params'] = [];
    foreach ($facetParams as $pname => $valsMap) {
      $pname = trim((string)$pname);
      if ($pname === '') continue;

      $vals = array_keys((array)$valsMap);
      usort($vals, $cmp);

      $facets['params'][$pname] = $vals;
    }
    uksort($facets['params'], function ($a, $b) {
      return strcasecmp((string)$a, (string)$b);
    });
  }
}

$totalPages = $showAll ? 1 : max(1, (int)ceil($totalOffers / $limit));


$ozonTaxonomyOptions = [];
try {
  $ozonTaxonomyOptions = ft_load_ozon_taxonomy_options();
} catch (Throwable $e) {
  $ozonTaxonomyOptions = [];
}

$ozonTaxonomyLabelByValue = ft_taxonomy_label_map($ozonTaxonomyOptions);
$ozonFacetLabels = [];
foreach (($facets['ozoncat'] ?? []) as $facetValue) {
  $facetValue = (string)$facetValue;
  if ($facetValue === '' || $facetValue === EMPTY_TOKEN) continue;
  if (isset($ozonTaxonomyLabelByValue[$facetValue])) {
    $ozonFacetLabels[$facetValue] = $ozonTaxonomyLabelByValue[$facetValue];
  }
}

$wbTaxonomyOptions = [];
try {
  $wbTaxonomyOptions = ft_load_wb_taxonomy_options();
} catch (Throwable $e) {
  $wbTaxonomyOptions = [];
}

$wbTaxonomyLabelByValue = ft_taxonomy_label_map($wbTaxonomyOptions);
$wbFacetLabels = [];
foreach (($facets['wbcat'] ?? []) as $facetValue) {
  $facetValue = (string)$facetValue;
  if ($facetValue === '' || $facetValue === EMPTY_TOKEN) continue;
  if (isset($wbTaxonomyLabelByValue[$facetValue])) {
    $wbFacetLabels[$facetValue] = $wbTaxonomyLabelByValue[$facetValue];
  }
}


?>
<!doctype html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <title>Dataset #<?= h($row['id']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    window.ftViewDiag = {
      requestId: <?= json_encode($viewRequestId, JSON_UNESCAPED_UNICODE) ?>,
      datasetId: <?= json_encode((string)($row['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
      expectedRows: <?= json_encode(count($offers), JSON_UNESCAPED_UNICODE) ?>,
      diagMode: <?= $viewDiagMode ? 'true' : 'false' ?>,
      sent: {}
    };

    (function() {
      const diag = window.ftViewDiag;
      if (!diag) return;

      function safeText(value, limit) {
        value = String(value || '');
        return value.length > limit ? value.slice(0, limit) + '...' : value;
      }

      function log(payload) {
        payload = Object.assign({
          request_id: diag.requestId,
          dataset_id: diag.datasetId,
          url: window.location.href,
          user_agent: navigator.userAgent,
          ready_state: document.readyState
        }, payload || {});

        try {
          const body = JSON.stringify(payload);
          if (navigator.sendBeacon) {
            navigator.sendBeacon('client_diagnostic.php', new Blob([body], { type: 'application/json' }));
            return;
          }
          fetch('client_diagnostic.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body,
            keepalive: true
          }).catch(function() {});
        } catch (e) {}
      }

      function ensureDiagnosticBox() {
        let box = document.getElementById('ftViewDiagnosticBanner');
        if (box) return box;

        const root = document.body || document.documentElement;
        if (!root) return null;

        box = document.createElement('div');
        box.id = 'ftViewDiagnosticBanner';
        box.className = 'view-diagnostic-banner';
        box.style.cssText = 'position:fixed;left:16px;right:16px;top:16px;z-index:99999;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;padding:12px 14px;box-shadow:0 14px 34px rgba(153,27,27,.12);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;';
        box.innerHTML = '<strong style="display:block;margin-bottom:4px;color:#7f1d1d;">Страница загрузилась с ошибкой</strong>' +
          'Код: <code id="ftViewDiagnosticCode" style="background:rgba(255,255,255,.82);color:#7f1d1d;padding:2px 6px;border-radius:6px;">UNKNOWN</code>' +
          ' <span style="color:#64748b;">·</span> ID проверки: ' +
          '<code id="ftViewDiagnosticRequest" style="background:rgba(255,255,255,.82);color:#7f1d1d;padding:2px 6px;border-radius:6px;"></code>' +
          '<div id="ftViewDiagnosticDetail" style="margin-top:6px;"></div>';
        root.insertBefore(box, root.firstChild || null);
        return box;
      }

      function show(code, detail) {
        const key = code + ':' + safeText(detail, 160);
        if (diag.sent[key]) return;
        diag.sent[key] = true;

        const box = ensureDiagnosticBox();
        if (box) {
          box.classList.add('view-diagnostic-banner--visible');
          const codeEl = document.getElementById('ftViewDiagnosticCode');
          const detailEl = document.getElementById('ftViewDiagnosticDetail');
          const reqEl = document.getElementById('ftViewDiagnosticRequest');
          if (codeEl) codeEl.textContent = code;
          if (detailEl) detailEl.textContent = safeText(detail, 260);
          if (reqEl) reqEl.textContent = diag.requestId;
          box.style.display = '';
        }

        log({
          event: 'view_page_problem',
          code,
          detail: safeText(detail, 500),
          has_offers_table: !!document.getElementById('offersTable'),
          has_page_tail: !!document.getElementById('ft-page-tail'),
          rows: document.querySelectorAll('#offersTable tbody tr').length
        });
      }

      function inspect(stage) {
        const table = document.getElementById('offersTable');
        const tail = document.getElementById('ft-page-tail');
        if (!table) {
          if (diag.diagMode) {
            show('OFFERS_TABLE_MISSING', 'stage=' + stage + ', tail=' + (tail ? 'yes' : 'no'));
          } else {
            log({
              event: 'view_page_problem',
              code: 'OFFERS_TABLE_MISSING',
              detail: 'stage=' + stage + ', tail=' + (tail ? 'yes' : 'no'),
              has_offers_table: false,
              has_page_tail: !!tail,
              rows: 0
            });
          }
          return;
        }
        if (!tail) {
          // If the offers table is already visible, the useful part of the page loaded.
          // A slow VPN can delay the lower service blocks, so keep this diagnostic in logs only.
          log({
            event: 'view_page_tail_missing',
            code: 'PAGE_TAIL_MISSING',
            detail: 'stage=' + stage + ', table=yes',
            has_offers_table: true,
            has_page_tail: false,
            rows: table.querySelectorAll('tbody tr').length
          });
          return;
        }

        const rows = table.querySelectorAll('tbody tr').length;
        if (Number(diag.expectedRows || 0) > 0 && rows === 0) {
          if (diag.diagMode) {
            show('OFFERS_ROWS_MISSING', 'stage=' + stage + ', expected=' + diag.expectedRows);
          } else {
            log({
              event: 'view_page_problem',
              code: 'OFFERS_ROWS_MISSING',
              detail: 'stage=' + stage + ', expected=' + diag.expectedRows,
              has_offers_table: true,
              has_page_tail: !!tail,
              rows: rows
            });
          }
          return;
        }

        const rect = table.getBoundingClientRect();
        if (rows > 0 && (rect.width === 0 || rect.height === 0)) {
          if (diag.diagMode) {
            show('OFFERS_TABLE_HIDDEN', 'stage=' + stage + ', rows=' + rows);
          } else {
            log({
              event: 'view_page_problem',
              code: 'OFFERS_TABLE_HIDDEN',
              detail: 'stage=' + stage + ', rows=' + rows,
              has_offers_table: true,
              has_page_tail: !!tail,
              rows: rows
            });
          }
        }
      }

      window.addEventListener('error', function(event) {
        const message = event && event.message ? event.message : 'unknown error';
        show('JS_ERROR', message + ' @ ' + (event.filename || '') + ':' + (event.lineno || 0));
      });

      window.addEventListener('unhandledrejection', function(event) {
        const reason = event && event.reason ? (event.reason.message || event.reason) : 'unknown rejection';
        show('JS_REJECTION', reason);
      });

      if (diag.diagMode) {
        log({ event: 'view_diag_started' });
        window.setTimeout(function() {
          show('DIAG_MODE', 'Диагностика включена. Если таблица товаров не появится, пришлите скриншот этого блока. Ожидается строк: ' + diag.expectedRows + '.');
        }, 250);
        window.setTimeout(function() {
          if (!document.getElementById('offersTable')) {
            show('DIAG_MODE_REPEAT', 'Повторная проверка: страница всё ещё загружается или часть HTML не дошла до браузера. Ожидается строк: ' + diag.expectedRows + '.');
          }
        }, 8000);
      }

      document.addEventListener('DOMContentLoaded', function() {
        if (diag.diagMode) {
          show('DIAG_MODE', 'Диагностика включена. Таблица ожидается: ' + diag.expectedRows + ' строк на текущей странице.');
          inspect('domcontentloaded');
          window.setTimeout(function() { inspect('after_1500ms'); }, 1500);
        }
      });

      window.addEventListener('load', function() {
        if (diag.diagMode) {
          inspect('load');
          window.setTimeout(function() { inspect('after_load_2500ms'); }, 2500);
        }
      });

      if (diag.diagMode) {
        window.setTimeout(function() { inspect('head_timer_4000ms'); }, 4000);
        window.setTimeout(function() { inspect('head_timer_9000ms'); }, 9000);
      } else {
        window.setTimeout(function() {
          if (!document.getElementById('offersTable')) {
            show('OFFERS_TABLE_MISSING', 'stage=head_timer_10000ms, normal mode. Таблица товаров не дошла до браузера.');
          }
        }, 10000);
      }
    })();
  </script>
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      --bg: #f6f8fb;
      --surface: #ffffff;
      --surface-soft: #f8fafc;
      --border: #dbe3ee;
      --border-strong: #cbd5e1;
      --text: #111827;
      --muted: #64748b;
      --accent: #2563eb;
      --accent-soft: #eff6ff;
      --success: #0f766e;
      --success-soft: #ecfdf5;
      --warning-soft: #fffbeb;
      --shadow: 0 14px 34px rgba(15, 23, 42, .07);
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      max-width: 1700px;
      margin: 30px auto;
      padding: 0 16px;
      background:
        radial-gradient(circle at 12% -10%, rgba(37, 99, 235, .09), transparent 34%),
        radial-gradient(circle at 90% 0%, rgba(15, 118, 110, .08), transparent 30%),
        var(--bg);
      color: var(--text);
      font-size: 13px;
    }

    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 1000;
      display: inline-flex;
      align-items: center;
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid #f59e0b;
      background: rgba(255, 251, 235, 0.97);
      color: #92400e;
      box-shadow: 0 12px 28px rgba(146, 64, 14, .14);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .view-diagnostic-banner {
      position: fixed;
      top: 16px;
      left: 16px;
      right: 16px;
      z-index: 100000;
      border: 1px solid #fecaca;
      background: #fff1f2;
      color: #991b1b;
      border-radius: 14px;
      padding: 12px 14px;
      margin: 0 auto;
      max-width: 980px;
      box-shadow: 0 14px 34px rgba(153, 27, 27, .12);
      line-height: 1.45;
    }

    .view-diagnostic-banner--visible {
      display: block !important;
    }

    .view-diagnostic-banner strong {
      display: block;
      margin-bottom: 4px;
      color: #7f1d1d;
    }

    .view-diagnostic-banner code {
      background: rgba(255, 255, 255, .82);
      color: #7f1d1d;
    }

    .card {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 14px;
      margin-bottom: 16px;
      font-size: 13px;
      background: rgba(255, 255, 255, .92);
      box-shadow: var(--shadow);
      overflow: visible;
    }

    /* offers table: thin gray grid */
    #offersTable {
      border-collapse: collapse;
    }

    #offersTable th,
    #offersTable td {
      border: 1px solid var(--border);
      /* тонкие серые границы */
    }

    #offersTable {
      min-width: 1180px;
      font-size: 10px;
    }

    #offersTable th {
      background: #f8fafc !important;
      color: #475569;
      z-index: 20;
      box-shadow: inset 0 -1px 0 var(--border-strong);
    }

    #offersTable td {
      background: #fff;
    }

    #offersTable tbody tr:nth-child(even) td {
      background: #fbfdff;
    }

    #offersTable tbody tr:hover td {
      background: #f0f7ff;
    }

    #offersTable img {
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 3px;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
    }

    #offersTable input[type="checkbox"] {
      width: 14px;
      height: 14px;
      min-width: 14px;
      margin: 0;
      vertical-align: middle;
      accent-color: var(--accent);
    }

    #offersTable th:first-child,
    #offersTable td:first-child {
      width: 32px;
      min-width: 32px;
      max-width: 32px;
      text-align: center;
    }

    .muted {
      color: var(--muted);
    }

    a {
      color: var(--accent);
      text-decoration-thickness: 1px;
      text-underline-offset: 3px;
    }

    code {
      background: #eef2f7;
      padding: 2px 6px;
      border-radius: 6px;
    }

    .dd {
      position: relative;
      display: inline-block;
    }

    .ddbtn {
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      border-radius: 10px;
      padding: 6px 10px;
      font-size: 12px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      user-select: none;
      max-width: min(100%, 520px);
      min-height: 34px;
      color: #334155;
      font-weight: 500;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }

    .ddbtn:hover {
      border-color: #93c5fd;
      background: var(--accent-soft);
    }

    .ddbtn .pill {
      background: #dbeafe;
      color: #1d4ed8;
      border-radius: 999px;
      padding: 1px 8px;
      font-size: 12px;
    }

    .ddpanel {
      position: fixed;
      z-index: 5000;
      top: 0;
      left: 0;
      min-width: 280px;
      width: min(560px, calc(100vw - 24px));
      max-height: min(430px, calc(100vh - 24px));
      overflow: auto;
      overscroll-behavior: contain;
      border: 1px solid var(--border-strong);
      border-radius: 14px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(15, 23, 42, .18);
      padding: 10px;
      display: none;
    }

    .ddpanel.open {
      display: block;
    }

    .toggleline {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      user-select: none;
      cursor: pointer;
      color: #111827;
      opacity: 0.85;
    }

    .toggleline:hover {
      opacity: 1;
    }

    .togglechev {
      display: inline-block;
      width: 14px;
      height: 14px;
      transition: transform .12s ease;
    }

    .togglechev.open {
      transform: rotate(90deg);
    }

    .dditem {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      padding: 4px 2px;
      font-size: 12px;
      line-height: 1.2;
      word-break: break-word;
    }

    .ddsearch {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 6px 10px;
      font-size: 12px;
      margin-bottom: 8px;
      background: var(--surface-soft);
    }

    .ddactions {
      display: flex;
      gap: 8px;
      margin: 0 0 8px;
      flex-wrap: wrap;
    }

    .ddactions button {
      border: 1px solid var(--border);
      background: #fff;
      border-radius: 10px;
      padding: 4px 10px;
      font-size: 12px;
      cursor: pointer;
      color: #475569;
      font-weight: 600;
    }

    button {
      border: 1px solid var(--border-strong);
      background: linear-gradient(180deg, #ffffff 0%, #f4f7fb 100%);
      border-radius: 10px;
      padding: 8px 12px;
      font-size: 12px;
      cursor: pointer;
      color: var(--text);
      font-weight: 600;
      transition: transform .12s ease, background .15s ease, border-color .15s ease, box-shadow .15s ease;
    }

    button:hover {
      background: #ffffff;
      border-color: #94a3b8;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
      transform: translateY(-1px);
    }

    button[type="submit"] {
      background: linear-gradient(135deg, #2563eb 0%, #0f766e 100%);
      border-color: transparent;
      color: #fff;
      font-weight: 700;
    }

    button[type="submit"]:hover {
      background: linear-gradient(135deg, #1d4ed8 0%, #0d6b63 100%);
      border-color: transparent;
      color: #fff;
    }

    button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .btn-primary {
      background: linear-gradient(135deg, #2563eb 0%, #0f766e 100%) !important;
      border-color: transparent !important;
      color: #fff !important;
      box-shadow: 0 10px 22px rgba(37, 99, 235, .18);
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, #1d4ed8 0%, #0d6b63 100%) !important;
      border-color: transparent !important;
      color: #fff !important;
      box-shadow: 0 14px 26px rgba(37, 99, 235, .22);
    }

    .btn-secondary {
      background: linear-gradient(180deg, #ffffff 0%, #eef4fb 100%);
      border-color: #cbd5e1;
      color: #0f172a;
    }

    .btn-secondary:hover {
      border-color: #94a3b8;
      background: #ffffff;
    }

    .btn-neutral {
      background: #fff;
      border-color: #dbe3ee;
      color: #475569;
      box-shadow: none;
    }

    .btn-neutral:hover {
      background: #f8fafc;
      color: #334155;
      box-shadow: none;
    }

    .btn-danger {
      background: linear-gradient(180deg, #fff5f5 0%, #fee2e2 100%);
      border-color: #fecaca;
      color: #b91c1c;
    }

    .btn-danger:hover {
      background: #fff1f2;
      border-color: #fca5a5;
      color: #991b1b;
    }

    .btn-inline {
      padding-left: 10px;
      padding-right: 10px;
      font-size: 11px;
    }

    .fold-card {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      margin-bottom: 16px;
      overflow: hidden;
    }

    .fold-card summary {
      list-style: none;
      cursor: pointer;
      padding: 14px 16px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
    }

    .fold-card summary::-webkit-details-marker {
      display: none;
    }

    .fold-label {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 0;
    }

    .fold-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
    }

    .fold-meta {
      font-size: 12px;
      color: var(--muted);
      font-weight: 400;
      line-height: 1.45;
    }

    .fold-body {
      padding: 0 16px 16px;
      border-top: 1px solid #f3f4f6;
    }

    .dataset-meta-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin: 14px 0 0;
    }

    .dataset-meta-item {
      border: 1px solid var(--border);
      border-radius: 12px;
      background: var(--surface-soft);
      padding: 12px;
    }

    .dataset-meta-label {
      display: block;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 4px;
    }

    .dataset-meta-value {
      display: block;
      font-size: 14px;
      color: var(--text);
      font-weight: 600;
      line-height: 1.4;
      word-break: break-word;
    }

    .dataset-marketplace-card {
      margin-bottom: 14px;
      border: 1px solid #cfe0f7;
      border-radius: 18px;
      padding: 14px;
      background:
        radial-gradient(circle at top right, rgba(191, 219, 254, .38), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
      box-shadow: 0 12px 28px rgba(24, 57, 90, .06);
    }

    .dataset-marketplace-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      align-items: end;
      margin-top: 12px;
    }

    .dataset-marketplace-field {
      display: grid;
      gap: 8px;
    }

    .dataset-marketplace-field label {
      min-width: 0;
    }

    .dataset-marketplace-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }

    .dataset-marketplace-actions form {
      margin: 0;
    }

    .dataset-marketplace-actions button {
      min-height: 36px;
      padding: 0 12px;
      font-size: 12px;
    }

    .dataset-marketplace-hint {
      margin-top: 8px;
      color: var(--muted);
      font-size: 12px;
    }

    .dataset-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin-top: 14px;
    }

    .dataset-actions a,
    .dataset-actions button {
      min-height: 36px;
    }

    .section-block {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: var(--surface-soft);
      padding: 14px;
      margin-bottom: 12px;
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .section-title {
      font-size: 15px;
      font-weight: 700;
      margin: 0;
      color: var(--text);
    }

    .section-subtitle {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.45;
      margin: 4px 0 0;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: 12px;
      color: #334155;
      min-width: 0;
    }

    .control-input,
    .control-select {
      border: 1px solid var(--border-strong);
      border-radius: 10px;
      padding: 8px 10px;
      font-size: 12px;
      background: #fff;
      color: var(--text);
      min-width: 0;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }

    .control-input:focus,
    .control-select:focus,
    .ddsearch:focus {
      outline: none;
      border-color: #60a5fa;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }

    .control-input--sm {
      width: 110px;
    }

    .control-input--md {
      width: 200px;
    }

    .control-input--wide {
      width: 100%;
      min-width: 280px;
    }

    .count-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      background: #eef2ff;
      color: #1e3a8a;
      font-size: 12px;
      line-height: 1.2;
      white-space: nowrap;
    }

    .text-button {
      border: 0;
      background: transparent;
      color: #2563eb;
      padding: 0;
      font-size: 12px;
      font-weight: 600;
    }

    .text-button:hover {
      background: transparent;
      color: #1d4ed8;
    }

    .ops-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 8px;
    }

    .op-choice {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      padding: 8px 10px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #fff;
      font-size: 11px;
    }

    .op-choice-title {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: var(--text);
      line-height: 1.25;
    }

    .op-choice-description {
      display: block;
      margin-top: 3px;
      color: var(--muted);
      line-height: 1.3;
    }

    .is-descriptions-hidden .op-choice-description {
      display: none;
    }

    .op-reference-list {
      display: grid;
      gap: 8px;
      padding: 0 12px 12px;
    }

    .op-reference-item {
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      padding: 8px 10px;
      font-size: 11px;
      line-height: 1.35;
    }

    .inline-toggle-card {
      border: 1px dashed var(--border-strong);
      border-radius: 12px;
      background: #fff;
      overflow: visible;
    }

    .inline-toggle-card summary {
      list-style: none;
      cursor: pointer;
      padding: 10px 12px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }

    .inline-toggle-card summary::-webkit-details-marker {
      display: none;
    }

    .inline-toggle-card[open] > .op-reference-list,
    .inline-toggle-card[open] > .param-filters-host,
    .inline-toggle-card[open] > .inline-toggle-body {
      border-top: 1px solid #f3f4f6;
    }

    .offers-toolbar {
      display: grid;
      gap: 12px;
      margin: 8px 0 14px;
    }

    .toolbar-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }

    .toolbar-card {
      border: 1px solid var(--border);
      border-radius: 16px;
      background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
      padding: 16px;
      min-width: 0;
      box-shadow: 0 10px 24px rgba(15, 23, 42, .045);
    }

    .toolbar-card--wide {
      grid-column: 1 / -1;
    }

    .toolbar-card--action {
      border-color: #d6e4ff;
      background:
        radial-gradient(circle at top right, rgba(37, 99, 235, .08), transparent 28%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    .toolbar-card--filters {
      border-color: #d9e7de;
      background:
        radial-gradient(circle at top left, rgba(15, 118, 110, .07), transparent 24%),
        linear-gradient(180deg, #ffffff 0%, #fbfefd 100%);
    }

    .toolbar-card--export {
      border-color: #d7e2ff;
      background:
        radial-gradient(circle at top right, rgba(37, 99, 235, .10), transparent 26%),
        linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
      box-shadow: 0 14px 32px rgba(37, 99, 235, .08);
    }

    .toolbar-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .toolbar-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
      margin: 0;
    }

    .toolbar-subtitle {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.45;
      margin-top: 4px;
    }

    .toolbar-row {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .toolbar-row--search {
      align-items: flex-end;
      display: grid;
      grid-template-columns: minmax(260px, 1fr) auto;
      width: 100%;
    }

    .toolbar-row--assign {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      width: 100%;
    }

    .toolbar-row + .toolbar-row {
      margin-top: 10px;
    }

    .marketplace-assign-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }

    .marketplace-assign-panel {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      padding: 14px;
      min-width: 0;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, .8);
    }

    .marketplace-assign-panel:nth-child(1) {
      border-left: 4px solid var(--accent);
    }

    .marketplace-assign-panel:nth-child(2) {
      border-left: 4px solid var(--success);
    }

    .marketplace-assign-panel .toolbar-title {
      font-size: 13px;
    }

    .marketplace-assign-panel .toolbar-subtitle {
      margin-top: 2px;
      min-height: 34px;
    }

    .assign-picker {
      flex: 1 1 260px;
      min-width: 0;
    }

    .assign-picker .dd {
      display: block;
      width: 100%;
      min-width: 0;
    }

    .assign-picker .ddbtn {
      display: flex;
      width: 100%;
      max-width: none;
      min-width: 0;
      box-sizing: border-box;
    }

    .toolbar-row--assign .toolbar-actions {
      justify-content: flex-end;
      align-self: stretch;
    }

    .toolbar-actions {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .toolbar-actions > button {
      min-height: 38px;
    }

    .toolbar-actions--right {
      margin-left: auto;
      justify-content: flex-end;
      min-width: max-content;
    }

    .search-field {
      flex: 1 1 380px;
      width: 100%;
    }

    .search-field .control-input {
      width: 100%;
    }

    .range-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 280px));
      gap: 12px;
      width: 100%;
    }

    .range-card {
      display: grid;
      gap: 8px;
      padding: 12px 14px;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .range-card-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .range-fields {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .range-fields .field {
      min-width: 0;
    }

    .range-fields .control-input {
      width: 100%;
      min-width: 0;
    }

    .checkbox-chip {
      display: inline-flex;
      align-items: flex-start;
      gap: 8px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      padding: 8px 10px;
      font-size: 12px;
      color: #334155;
      line-height: 1.35;
    }

    .filter-host {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      padding: 10px;
      border: 1px solid #d8e4ef;
      border-radius: 14px;
      background: linear-gradient(180deg, #f8fbff 0%, #f4f8fc 100%);
    }

    .filter-info {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.45;
    }

    .param-filters-host {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      padding: 0 12px 12px;
    }

    .selection-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .selection-actions button {
      min-height: 38px;
    }

    .toolbar-note {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.45;
    }

    .inline-toggle-body {
      padding: 12px;
    }

    .inline-status-error {
      margin-top: 10px;
      font-size: 12px;
      color: #b00020;
      line-height: 1.45;
    }

    .view-settings-form {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin: 0;
    }

    .pager {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .compact-table-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin: 0 0 12px;
    }

    .compact-limit-form {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin: 0;
      padding: 8px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: var(--surface-soft);
    }

    .compact-limit-form .control-select {
      min-width: 0;
      width: auto;
    }

    .collapsible-section {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      overflow: visible;
    }

    .offers-table-wrap {
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: #fff;
      max-height: calc(200vh - 220px);
      min-height: 320px;
      box-shadow: 0 12px 28px rgba(15, 23, 42, .055);
    }

    .offer-description-preview {
      max-height: 190px;
      overflow: auto;
      border: 1px solid #eef2f7;
      border-radius: 12px;
      padding: 8px;
      background: #fff;
      color: #1f2937;
      font-size: 8px;
      line-height: 1.35;
      isolation: isolate;
      contain: content;
    }

    .offer-description-preview,
    .offer-description-preview * {
      max-width: 100% !important;
      position: static !important;
      float: none !important;
      transform: none !important;
      animation: none !important;
      transition: none !important;
    }

    .offer-description-preview img,
    .offer-description-preview video,
    .offer-description-preview iframe,
    .offer-description-preview svg {
      display: none !important;
    }

    .offer-description-preview p,
    .offer-description-preview ul,
    .offer-description-preview ol {
      margin: 0 0 8px;
    }

    .offer-description-preview h1,
    .offer-description-preview h2,
    .offer-description-preview h3,
    .offer-description-preview h4 {
      margin: 0 0 8px;
      font-size: 9px;
      line-height: 1.3;
    }

    .collapsible-section summary {
      list-style: none;
      cursor: pointer;
      padding: 14px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }

    .collapsible-section summary::-webkit-details-marker {
      display: none;
    }

    .collapsible-section-body {
      padding: 0 14px 14px;
      border-top: 1px solid #f3f4f6;
    }

    .pipeline-compact {
      font-size: 11px;
    }

    .pipeline-compact .field,
    .pipeline-compact .checkbox-chip,
    .pipeline-compact .count-chip,
    .pipeline-compact .text-button,
    .pipeline-compact .toolbar-note {
      font-size: 11px;
    }

    .pipeline-compact .control-input,
    .pipeline-compact .control-select,
    .pipeline-compact button {
      font-size: 11px;
      padding-top: 7px;
      padding-bottom: 7px;
    }

    .pipeline-compact .op-choice {
      font-size: 10px;
      padding: 7px 9px;
    }

    .pipeline-compact .op-choice-title {
      font-size: 11px;
    }

    @media (max-width: 900px) {
      .fold-card summary,
      .inline-toggle-card summary,
      .toolbar-header,
      .section-head {
        flex-direction: column;
        align-items: flex-start;
      }

      .toolbar-card--wide {
        grid-column: auto;
      }

      .marketplace-assign-grid {
        grid-template-columns: 1fr;
      }

      .toolbar-row--search {
        grid-template-columns: 1fr;
      }

      .range-grid {
        grid-template-columns: 1fr;
      }

	      .toolbar-row--assign {
	        grid-template-columns: 1fr;
	      }

	      .dataset-marketplace-grid {
	        grid-template-columns: 1fr;
	      }

	      .toolbar-actions--right {
        margin-left: 0;
        justify-content: flex-start;
      }

      .control-input--wide {
        min-width: 220px;
      }
    }
  </style>
</head>

<body>
  <?php if (ft_is_staging_env($cfg)): ?>
    <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
  <?php endif; ?>

  <div id="ftViewDiagnosticBanner" class="view-diagnostic-banner<?= $viewDiagMode ? ' view-diagnostic-banner--visible' : '' ?>" style="<?= $viewDiagMode ? '' : 'display:none;' ?>">
    <strong>Страница загрузилась с ошибкой</strong>
    Код: <code id="ftViewDiagnosticCode"><?= $viewDiagMode ? 'DIAG_MODE' : 'UNKNOWN' ?></code>
    <span class="muted">·</span>
    ID проверки: <code id="ftViewDiagnosticRequest"><?= h($viewRequestId) ?></code>
    <div id="ftViewDiagnosticDetail" style="margin-top:6px;"><?= $viewDiagMode ? 'Диагностика включена. Если таблица товаров не появится, пришлите скриншот этого блока.' : '' ?></div>
  </div>

  <noscript>
    <div class="view-diagnostic-banner view-diagnostic-banner--visible">
      <strong>Страница не может показать таблицу</strong>
      В браузере отключён JavaScript. Включите JavaScript и обновите страницу.
    </div>
  </noscript>

  <?= ft_top_navigation([
    'back_href' => 'xml_feeds.php',
    'back_label' => 'Назад',
    'active' => 'xml',
    'links' => [
      ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
      ['key' => 'xml', 'label' => 'XML-фиды', 'href' => 'xml_feeds.php'],
      ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
      ['key' => 'param-map', 'label' => 'Замены характеристик', 'href' => 'param_value_map.php'],
    ],
  ]) ?>

  <?php if ($inlineError !== ''): ?>
    <div class="inline-status-error" style="margin-bottom:12px;">
      Быстрое назначение категории не выполнено: <?= h($inlineError) ?>.
      <?php if ($inlineOpId > 0): ?>
        <a href="op.php?id=<?= h($inlineOpId) ?>" style="margin-left:8px;">Открыть операцию #<?= h($inlineOpId) ?></a>
      <?php endif; ?>
    </div>
	  <?php elseif ($inlineOpId > 0): ?>
	    <div class="toolbar-note" style="margin-bottom:12px;">
	      Изменения применены сразу на странице. Операция #<?= h($inlineOpId) ?> завершена без перехода в отчёт.
	    </div>
	  <?php endif; ?>

	  <?php if (isset($_GET['marketplace_connections_saved']) && (string)$_GET['marketplace_connections_saved'] === '1'): ?>
	    <div class="toolbar-note" style="margin-bottom:12px;">
	      Подключения маркетплейсов для этого датасета сохранены.
	    </div>
	  <?php endif; ?>

	  <form method="post" class="dataset-marketplace-card">
	    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
	    <input type="hidden" name="dataset_action" value="save_marketplace_connections">
	    <input type="hidden" name="limit" value="<?= h($showAll ? 'all' : (string)$limit) ?>">
	    <div class="section-head" style="margin-bottom:0;">
	      <div>
	        <p class="section-title">Подключения для проверки маркетплейсов</p>
	        <p class="section-subtitle">Выбери кабинеты, по которым этот датасет будет проверять наличие товаров на Ozon, WB и Яндекс.</p>
	      </div>
	    </div>
	    <div class="dataset-marketplace-grid">
	      <div class="dataset-marketplace-field">
	        <label class="field">
	          <span>Ozon</span>
	          <select class="control-select" name="ozon_connection_id" id="datasetOzonConnectionSelect">
	            <option value="0">Не выбрано</option>
	            <?php foreach ($ozonConnections as $connection): ?>
	              <?php $cid = (int)($connection['id'] ?? 0); ?>
	              <option value="<?= h((string)$cid) ?>" <?= $cid === $datasetOzonConnectionId ? 'selected' : '' ?>>
	                <?= h((string)($connection['title'] ?? 'Ozon')) ?><?= !empty($connection['client_id']) ? ' · ' . h((string)$connection['client_id']) : '' ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
	        </label>
	        <div class="dataset-marketplace-actions">
	          <button type="submit" class="btn-primary">Сохранить</button>
	          <button type="submit" class="btn-secondary" form="datasetOzonProductsSyncForm" <?= $datasetOzonConnectionId <= 0 ? 'disabled' : '' ?>>Синхронизировать товары</button>
	        </div>
	      </div>
	      <div class="dataset-marketplace-field">
	        <label class="field">
	          <span>Wildberries</span>
	          <select class="control-select" name="wb_connection_id" id="datasetWbConnectionSelect">
	            <option value="0">Не выбрано</option>
	            <?php foreach ($wbConnections as $connection): ?>
	              <?php $cid = (int)($connection['id'] ?? 0); ?>
	              <option value="<?= h((string)$cid) ?>" <?= $cid === $datasetWbConnectionId ? 'selected' : '' ?>>
	                <?= h((string)($connection['title'] ?? 'WB')) ?><?= !empty($connection['client_id']) ? ' · ' . h((string)$connection['client_id']) : '' ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
	        </label>
	        <div class="dataset-marketplace-actions">
	          <button type="submit" class="btn-primary">Сохранить</button>
	          <button type="submit" class="btn-secondary" form="datasetWbProductsSyncForm" <?= $datasetWbConnectionId <= 0 ? 'disabled' : '' ?>>Синхронизировать товары</button>
	        </div>
	      </div>
	      <div class="dataset-marketplace-field">
	        <label class="field">
	          <span>Яндекс</span>
	          <select class="control-select" name="yandex_connection_id" id="datasetYandexConnectionSelect">
	            <option value="0">Не выбрано</option>
	            <?php foreach ($yandexConnections as $connection): ?>
	              <?php $cid = (int)($connection['id'] ?? 0); ?>
	              <option value="<?= h((string)$cid) ?>" <?= $cid === $datasetYandexConnectionId ? 'selected' : '' ?>>
	                <?= h((string)($connection['title'] ?? 'Яндекс')) ?><?= !empty($connection['client_id']) ? ' · ' . h((string)$connection['client_id']) : '' ?>
	              </option>
	            <?php endforeach; ?>
	          </select>
	        </label>
	        <div class="dataset-marketplace-actions">
	          <button type="submit" class="btn-primary">Сохранить</button>
	          <button type="button" class="btn-secondary" disabled title="Синхронизация товаров Яндекса ещё не подключена">Синхронизация позже</button>
	        </div>
	      </div>
	    </div>
	    <div class="dataset-marketplace-hint">
	      Фильтры “отсутствует на маркетплейсе” берут данные из локальной синхронизации выбранного кабинета. Если список кажется устаревшим, сначала запусти синхронизацию товаров на странице подключений.
	    </div>
	  </form>

	  <form id="datasetOzonProductsSyncForm" method="post" action="run_op.php">
	    <input type="hidden" name="op_type" value="ozon_sync_products">
	    <input type="hidden" name="connection_id" value="<?= h((string)$datasetOzonConnectionId) ?>" data-sync-connection-from="datasetOzonConnectionSelect">
	    <input type="hidden" name="mode" value="full_new">
	    <input type="hidden" name="visibility" value="both">
	  </form>
	  <form id="datasetWbProductsSyncForm" method="post" action="run_op.php">
	    <input type="hidden" name="op_type" value="wb_sync_products">
	    <input type="hidden" name="connection_id" value="<?= h((string)$datasetWbConnectionId) ?>" data-sync-connection-from="datasetWbConnectionSelect">
	  </form>
	  <script>
	    (() => {
	      document.querySelectorAll('[data-sync-connection-from]').forEach((input) => {
	        const select = document.getElementById(input.getAttribute('data-sync-connection-from') || '');
	        if (!select) return;
	        const sync = () => {
	          input.value = select.value || '0';
	          const form = input.form;
	          const button = form ? document.querySelector('button[form="' + form.id + '"]') : null;
	          if (button) button.disabled = !input.value || input.value === '0';
	        };
	        select.addEventListener('change', sync);
	        sync();
	      });
	    })();
	  </script>

	  <div class="card">
    <details class="fold-card">
      <summary>
        <span class="fold-label">
          <span class="fold-title">Dataset #<?= h($row['id']) ?></span>
          <span class="fold-meta">
            <?= h($row['original_filename']) ?> · offers: <?= h($row['offers_count']) ?> · загружен: <?= ft_local_datetime_html((string)($row['created_at'] ?? ''), ['show_seconds' => true]) ?>
          </span>
        </span>
        <span class="fold-meta">Показать информацию о датасете</span>
      </summary>
      <div class="fold-body">
        <div class="dataset-meta-grid">
          <div class="dataset-meta-item">
            <span class="dataset-meta-label">Дата загрузки</span>
            <span class="dataset-meta-value"><?= ft_local_datetime_html((string)($row['created_at'] ?? ''), ['show_seconds' => true]) ?></span>
          </div>
          <div class="dataset-meta-item">
            <span class="dataset-meta-label">Исходный файл</span>
            <span class="dataset-meta-value"><?= h($row['original_filename']) ?></span>
          </div>
          <div class="dataset-meta-item">
            <span class="dataset-meta-label">Количество offers</span>
            <span class="dataset-meta-value"><?= h($row['offers_count']) ?></span>
          </div>
          <div class="dataset-meta-item">
            <span class="dataset-meta-label">SHA256</span>
            <span class="dataset-meta-value"><?= h($row['sha256']) ?></span>
          </div>
        </div>

        <div class="section-block">
          <div class="section-head">
            <div>
              <p class="section-title">Предупреждения</p>
              <p class="section-subtitle">Быстрая проверка проблемных offer перед обработкой.</p>
            </div>
          </div>
          <ul style="margin:0;padding-left:18px;font-size:12px;line-height:1.5;">
            <li>offers без url: <b><?= h($warnings['offers_missing_url'] ?? 0) ?></b></li>
            <li>offers без product_id в url: <b><?= h($warnings['offers_missing_product_id'] ?? 0) ?></b></li>
          </ul>
        </div>

        <div class="dataset-actions">
          <a href="download.php?id=<?= h($row['id']) ?>">Скачать оригинальный XML</a>
          <button type="button" id="btnExportXls" class="btn-secondary" title="Скачать выбранные товары в Excel (XLS)">Скачать XLS</button>
          <span id="exportXlsHint" class="muted" style="font-size:12px;"></span>
          <a href="delete.php?id=<?= h($row['id']) ?>" onclick="return confirm('Удалить датасет и файл?')">Удалить датасет</a>
        </div>

        <form method="post" action="export_xls.php" id="formExportXls" style="display:none;">
          <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
          <input type="hidden" name="offer_ids_json" id="exportOfferIdsJson" value="">
        </form>
      </div>
    </details>

    <?php if (false): // Ранний блок таблицы отключён: таблица остаётся на прежнем месте ниже. ?>
    <div class="card offers-fast-card">
      <div class="section-head">
        <div>
          <h3 style="margin:0;">Товары в прайсе</h3>
          <p class="section-subtitle">Компактная таблица выводится в начале страницы, чтобы она открывалась даже при медленном VPN-соединении.</p>
        </div>
        <span class="count-chip">Показано: <?= h((string)count($offers)) ?> из <?= h((string)$totalOffers) ?></span>
      </div>

      <div class="compact-table-bar">
        <?php if (!$showAll): ?>
          <div class="pager">
            <span class="count-chip">Страница <?= h($page) ?> / <?= h($totalPages) ?></span>
            <?php if ($page > 1): ?>
              <a href="<?= h('view.php?' . http_build_query(['id' => $row['id'], 'page' => $page - 1, 'limit' => ($showAll ? 'all' : $limit)])) ?>">← предыдущая</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a href="<?= h('view.php?' . http_build_query(['id' => $row['id'], 'page' => $page + 1, 'limit' => ($showAll ? 'all' : $limit)])) ?>">следующая →</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div></div>
        <?php endif; ?>

        <form class="compact-limit-form" method="get" action="view.php">
          <input type="hidden" name="id" value="<?= h($row['id']) ?>">
          <select class="control-select" name="limit" onchange="this.form.submit()" title="Сколько строк показывать в таблице">
            <option value="5" <?= (!$showAll && $limit === 5 ? 'selected' : '') ?>>5</option>
            <option value="10" <?= (!$showAll && $limit === 10 ? 'selected' : '') ?>>10</option>
            <option value="20" <?= (!$showAll && $limit === 20 ? 'selected' : '') ?>>20</option>
            <option value="50" <?= (!$showAll && $limit === 50 ? 'selected' : '') ?>>50</option>
            <option value="100" <?= (!$showAll && $limit === 100 ? 'selected' : '') ?>>100</option>
            <option value="all" <?= ($showAll ? 'selected' : '') ?>>все</option>
          </select>
          <?php if (!$showAll): ?>
            <input type="hidden" name="page" value="<?= h($page) ?>">
          <?php endif; ?>
        </form>
      </div>

      <div class="offers-table-wrap">
        <table id="offersTable" style="width:100%;border-collapse:collapse;table-layout:fixed;">
          <thead>
            <tr>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:32px;text-align:center;">
                <input type="checkbox" id="chkAllPage" title="Выбрать все на странице">
              </th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:76px;">id</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:150px;">Название</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:78px;">Фото</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:118px;">Категория Ozon</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:118px;">Категория WB</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:130px;">Категория поставщика</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:170px;">Параметры</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:70px;">Ссылки</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($offers as $o): ?>
              <?php
              $rowCat = (string)($o['category_path'] ?? '');
              $rowOzon = (string)($o['ozon_category'] ?? '');
              $rowWb = (string)($o['wb_category'] ?? ($o['wb_subject_id'] ?? ''));
              $rowBrand = (string)($o['brand_effective'] ?? '');
              ?>
              <tr data-catpath="<?= h($rowCat) ?>" data-ozoncat="<?= h($rowOzon) ?>" data-wbcat="<?= h($rowWb) ?>" data-brand="<?= h($rowBrand) ?>">
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;text-align:center;">
                  <?php $oid = (string)($o['id'] ?? '');
                  $disabled = (trim($oid) === ''); ?>
                  <input type="checkbox" class="offerChk" data-offer-id="<?= h($oid) ?>" <?= $disabled ? 'disabled' : '' ?>>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($o['id']) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($o['name']) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;text-align:center;">
                  <?php $pic0 = (!empty($o['pictures']) ? (string)$o['pictures'][0] : ''); ?>
                  <?php if ($pic0 !== ''): ?>
                    <a href="<?= h($pic0) ?>" target="_blank" rel="noopener">
                      <img
                        src="<?= h($pic0) ?>"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        style="height:74px;width:auto;max-width:74px;object-fit:contain;background:#fff;display:block;margin:0 auto;">
                    </a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php
                  $oz = (string)($o['ozon_category'] ?? '');
                  $ozLabel = ($oz !== '' && isset($ozonTaxonomyLabelByValue[$oz])) ? $ozonTaxonomyLabelByValue[$oz] : '';
                  ?>
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($ozLabel !== '' ? $ozLabel : $oz) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php
                  $wb = (string)($o['wb_category'] ?? ($o['wb_subject_id'] ?? ''));
                  $wbLabel = ($wb !== '' && isset($wbTaxonomyLabelByValue[$wb])) ? $wbTaxonomyLabelByValue[$wb] : '';
                  ?>
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($wbLabel !== '' ? $wbLabel : $wb) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php $cp = (string)($o['category_path'] ?? ''); ?>
                  <?php if ($cp !== ''): ?>
                    <div style="white-space:pre-wrap;line-height:1.25;"><?= h($cp) ?></div>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h(implode("\n", $o['params_lines'])) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;">
                    <?php if ($o['url']): ?>
                      <a href="<?= h($o['url']) ?>" target="_blank" rel="noopener">open</a>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="section-head">
        <div>
          <h3 style="margin:0;">Operations</h3>
          <p class="section-subtitle">Собрал основные действия в отдельные сценарии: конвейер, точечный запуск и служебные выгрузки.</p>
        </div>
      </div>

      <details class="collapsible-section section-block" open>
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:15px;">Конвейер операций</span>
            <span class="fold-meta">Несколько шагов подряд на выбранных товарах. Если товаров не выбрано, операция уйдёт на весь датасет.</span>
          </span>
        </summary>
        <div class="collapsible-section-body pipeline-compact">
          <div class="section-head">
            <div></div>
            <button type="button" class="text-button" id="pipelineDescriptionsToggle" aria-expanded="false">Показать описания операций</button>
          </div>

          <form method="post" action="run_op.php" id="pipelineForm" class="toolbar-row" style="align-items:flex-start;" data-queue-aware="1">
            <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
            <input type="hidden" name="op_type" value="run_pipeline">
            <input type="hidden" name="offer_ids_json" id="pipelineOfferIdsJson" value="">
            <input type="hidden" name="ops_json" id="pipelineOpsJson" value="[]">

            <span class="selectedCountBadge count-chip">выбрано: 0</span>

            <label class="field">
              <span>LLM model</span>
              <select class="control-select control-input--md" name="model">
                <?php foreach ($llmModelOptions as $modelOption): ?>
                  <?php $modelOption = (string)$modelOption; ?>
                  <option value="<?= h($modelOption) ?>" <?= $modelOption === $llmModelDefault ? 'selected' : '' ?>><?= h($modelOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">
              <span>max_items</span>
              <input type="text" class="control-input control-input--sm" name="max_items" placeholder="0">
            </label>
            <label class="field">
              <span>force_inplace</span>
              <select class="control-select" name="force_inplace">
                <option value="1" selected>1</option>
                <option value="0">0</option>
              </select>
            </label>
          </form>

          <div class="toolbar-row" style="margin:10px 0 12px;">
            <label class="checkbox-chip">
              <input type="checkbox" id="pipelineSelectAll">
              <span>Выбрать все операции в конвейере</span>
            </label>
          </div>

          <div id="pipelineOpsGrid" class="ops-grid is-descriptions-hidden">
            <?php foreach (array_diff_key($reg_ui, $hide_ops_in_pipeline) as $key => $def): ?>
              <label class="op-choice">
                <input type="checkbox" class="pipelineOpChk" value="<?= h($key) ?>">
                <span>
                  <span class="op-choice-title"><?= h(ft_op_ui_label($key, $def)) ?></span>
                  <span class="op-choice-description"><?= h($def['description'] ?? '') ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="toolbar-row" style="margin-top:12px;">
            <button type="submit" form="pipelineForm" class="btn-primary">Запустить конвейер</button>
            <span id="pipelineHint" class="muted" style="font-size:11px;"></span>
          </div>
        </div>
      </details>

      <script>
        document.addEventListener('DOMContentLoaded', function() {
          const modelStorageKey = 'feedtools.llm_model';
          const modelSelects = Array.from(document.querySelectorAll('select[name="model"]'));
          const hasModelOption = function(select, value) {
            return !!select && value !== '' && Array.from(select.options).some(function(option) {
              return String(option.value || '') === value;
            });
          };
          const setAllModelSelects = function(value, source) {
            if (value === '') return;
            modelSelects.forEach(function(select) {
              if (select !== source && hasModelOption(select, value)) {
                select.value = value;
              }
            });
          };
          if (modelSelects.length) {
            try {
              const savedModel = String(localStorage.getItem(modelStorageKey) || '').trim();
              modelSelects.forEach(function(select) {
                if (hasModelOption(select, savedModel)) {
                  select.value = savedModel;
                }
              });
            } catch (e) {}
            modelSelects.forEach(function(select) {
              select.addEventListener('change', function() {
                const value = String(select.value || '');
                try {
                  localStorage.setItem(modelStorageKey, value);
                } catch (e) {}
                setAllModelSelects(value, select);
              });
            });
          }

          const form = document.getElementById('pipelineForm');
          const opsOut = document.getElementById('pipelineOpsJson');
          const idsOut = document.getElementById('pipelineOfferIdsJson');
          const idsFrom = document.getElementById('offerIdsJson'); // теперь уже существует
          const hint = document.getElementById('pipelineHint');
          const descToggle = document.getElementById('pipelineDescriptionsToggle');
          const opsGrid = document.getElementById('pipelineOpsGrid');

          if (!form || !opsOut || !idsOut || !idsFrom) return;

          const selAll = document.getElementById('pipelineSelectAll');
          const opChks = Array.from(document.querySelectorAll('.pipelineOpChk'));
          const descStorageKey = 'ft_pipeline_descriptions_open';

          function setDescriptionsOpen(isOpen) {
            if (!descToggle || !opsGrid) return;
            opsGrid.classList.toggle('is-descriptions-hidden', !isOpen);
            descToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            descToggle.textContent = isOpen ? 'Скрыть описания операций' : 'Показать описания операций';
            try {
              localStorage.setItem(descStorageKey, isOpen ? '1' : '0');
            } catch (e) {}
          }

          if (descToggle && opsGrid) {
            let isOpen = false;
            try {
              isOpen = localStorage.getItem(descStorageKey) === '1';
            } catch (e) {}
            setDescriptionsOpen(isOpen);
            descToggle.addEventListener('click', function() {
              setDescriptionsOpen(opsGrid.classList.contains('is-descriptions-hidden'));
            });
          }

          function syncSelectAllState() {
            if (!selAll) return;
            const total = opChks.length;
            const checked = opChks.filter(x => x.checked).length;
            selAll.indeterminate = (checked > 0 && checked < total);
            selAll.checked = (total > 0 && checked === total);
          }

          if (selAll) {
            selAll.addEventListener('change', () => {
              const v = !!selAll.checked;
              opChks.forEach(x => {
                x.checked = v;
              });
              selAll.indeterminate = false;
              if (hint) hint.textContent = '';
            });
          }

          opChks.forEach(x => {
            x.addEventListener('change', () => {
              syncSelectAllState();
              if (hint) hint.textContent = '';
            });
          });

          // при загрузке страницы
          syncSelectAllState();


          form.addEventListener('submit', function(e) {
            const ops = Array.from(document.querySelectorAll('.pipelineOpChk'))
              .filter(x => x.checked)
              .map(x => String(x.value));

            if (!ops.length) {
              e.preventDefault();
              if (hint) hint.textContent = 'Выбери хотя бы одну операцию.';
              return;
            }

            opsOut.value = JSON.stringify(ops);
            idsOut.value = String(idsFrom.value || '').trim(); // пусто = весь датасет
          });
        });
      </script>

      <details class="collapsible-section section-block" open>
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:15px;">Одна операция вручную</span>
            <span class="fold-meta">Точечный запуск, когда не нужен целый конвейер.</span>
          </span>
        </summary>
        <div class="collapsible-section-body">
          <form method="post" action="run_op.php" id="manualOpForm" class="toolbar-row" data-queue-aware="1">
            <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
            <input type="hidden" name="offer_ids_json" id="offerIdsJson" value="">
            <span class="selectedCountBadge count-chip">выбрано: 0</span>

            <label class="field">
              <span>Операция</span>
              <select class="control-select" name="op_type">
                <?php foreach ($reg_ui as $key => $def): ?>
                  <option value="<?= h($key) ?>"><?= h(ft_op_ui_label($key, $def)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field">
              <span>LLM model</span>
              <select class="control-select control-input--md" name="model">
                <?php foreach ($llmModelOptions as $modelOption): ?>
                  <?php $modelOption = (string)$modelOption; ?>
                  <option value="<?= h($modelOption) ?>" <?= $modelOption === $llmModelDefault ? 'selected' : '' ?>><?= h($modelOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field">
              <span>max_items</span>
              <input type="text" class="control-input control-input--md" name="max_items" placeholder="0 = без лимита">
            </label>

            <button type="submit" class="btn-primary">Запустить</button>
          </form>

          <details class="inline-toggle-card" style="margin-top:12px;">
            <summary>
              <span class="fold-label">
                <span class="fold-title" style="font-size:13px;">Список операций и описания</span>
                <span class="fold-meta">Открывай подсказки только когда нужно вспомнить назначение конкретной операции.</span>
              </span>
            </summary>
            <div class="op-reference-list">
              <?php foreach ($reg_ui as $key => $def): ?>
                <div class="op-reference-item"><b><?= h(ft_op_ui_label($key, $def)) ?></b>: <?= h($def['description'] ?? '') ?></div>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
      </details>

      <details class="collapsible-section section-block" open>
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:15px;">Экспорт XLSX по шаблону</span>
            <span class="fold-meta">Если товары не выбраны, экспортируется весь датасет. Ozon и Wildberries используют разные шаблоны и правила заполнения.</span>
          </span>
          <span class="count-chip">Экспорт</span>
        </summary>
        <div class="collapsible-section-body">
          <div class="marketplace-assign-grid">
            <form id="formExportTplXlsx" method="post" action="export_template_xlsx.php" enctype="multipart/form-data" class="marketplace-assign-panel">
              <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
              <input type="hidden" name="offer_ids_json" id="tplOfferIdsJson" value="">

              <div class="toolbar-title">Ozon XLSX</div>
              <div class="toolbar-subtitle">Старый экспорт по Ozon-шаблону. Характеристики берутся из обычных <code>param</code>.</div>

              <div class="toolbar-row">
                <label class="field">
                  <span>Шаблон Ozon (.xlsx)</span>
                  <input type="file" name="template_file" id="tplXlsxFile" accept=".xlsx" />
                </label>

                <button type="submit" class="btn-primary" title="Пустой выбор = весь датасет">Скачать Ozon XLSX</button>
              </div>
            </form>

            <form id="formExportWbTplXlsx" method="post" action="export_wb_template_xlsx.php" enctype="multipart/form-data" class="marketplace-assign-panel">
              <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
              <input type="hidden" name="offer_ids_json" id="wbTplOfferIdsJson" value="">

              <div class="toolbar-title">Wildberries XLSX</div>
              <div class="toolbar-subtitle">Экспорт под WB-шаблон. WB-характеристики берутся из <code>wb_param</code>, обычные <code>param</code> используются только как fallback.</div>

              <div class="toolbar-row">
                <label class="field">
                  <span>Шаблон WB (.xlsx)</span>
                  <input type="file" name="template_file" id="wbTplXlsxFile" accept=".xlsx" />
                </label>

                <button type="submit" class="btn-primary" title="Пустой выбор = весь датасет">Скачать WB XLSX</button>
              </div>
            </form>
          </div>
        </div>
      </details>

      <script>
        (function() {
          const forms = [
            {
              form: document.getElementById('formExportTplXlsx'),
              file: document.getElementById('tplXlsxFile'),
              idsOut: document.getElementById('tplOfferIdsJson'),
              label: 'Ozon'
            },
            {
              form: document.getElementById('formExportWbTplXlsx'),
              file: document.getElementById('wbTplXlsxFile'),
              idsOut: document.getElementById('wbTplOfferIdsJson'),
              label: 'WB'
            }
          ];
          const selectedIdsHidden = document.getElementById('offerIdsJson'); // уже есть в странице

          if (!selectedIdsHidden) return;

          forms.forEach(function(item) {
            if (!item.form || !item.file || !item.idsOut) return;
            const submitBtn = item.form.querySelector('button[type="submit"]');
            const defaultButtonText = submitBtn ? submitBtn.textContent : '';
            const resetSubmitState = function() {
              item.form.dataset.submitting = '';
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = defaultButtonText || ('Скачать ' + item.label + ' XLSX');
              }
            };
            item.form.addEventListener('submit', function(e) {
              if (!item.file.files || !item.file.files[0]) {
                e.preventDefault();
                alert('Выбери файл шаблона ' + item.label + ' .xlsx');
                return;
              }
              if (item.form.dataset.submitting === '1') {
                e.preventDefault();
                return;
              }
              item.form.dataset.submitting = '1';
              if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Готовим ' + item.label + ' XLSX...';
              }
              // пусто = весь датасет
              item.idsOut.value = String(selectedIdsHidden.value || '').trim();
              window.setTimeout(resetSubmitState, 3000);
            });
            item.file.addEventListener('change', resetSubmitState);
            window.addEventListener('pageshow', resetSubmitState);
          });
        })();
      </script>

    </div>



    <div class="card">
      <h3>Offers (таблица)</h3>

      <div class="offers-toolbar">
        <div class="toolbar-card toolbar-card--wide toolbar-card--action">
          <div class="toolbar-header">
            <div>
              <div class="toolbar-title">Назначение категорий маркетплейсов</div>
              <div class="toolbar-subtitle">Сначала отметь товары, затем выбери категорию Ozon и/или WB и запусти нужное массовое назначение.</div>
            </div>
            <span id="selectedCountBadge" class="selectedCountBadge count-chip">выбрано: 0</span>
          </div>

          <div class="marketplace-assign-grid">
            <div class="marketplace-assign-panel">
              <div class="toolbar-title">Категория Ozon</div>
              <div class="toolbar-subtitle">Выбери категорию Ozon и назначь её всем отмеченным товарам.</div>

              <div class="toolbar-row toolbar-row--assign">
                <div id="ozonTaxonomyDropdownHost" class="assign-picker"></div>
                <input type="hidden" id="ozonTaxonomySelected" value="">
                <div class="toolbar-actions">
                  <button type="button" id="btnAssignOzonCategory" class="btn-primary">Назначить Ozon</button>
                  <button type="button" id="btnClearOzonCategory" class="btn-secondary">Очистить Ozon</button>
                </div>
              </div>

              <?php if (empty($ozonTaxonomyOptions)): ?>
                <div class="toolbar-note" style="margin-top:10px;">Список пуст, импортируй категории в <code>/taxonomy/import.php</code>.</div>
              <?php endif; ?>

              <div id="assignOzonCategoryHint" class="filter-info" style="margin-top:10px;"></div>
            </div>

            <div class="marketplace-assign-panel">
              <div class="toolbar-title">Категория WB</div>
              <div class="toolbar-subtitle">Выбери категорию Wildberries и назначь её тем же отмеченным товарам.</div>

              <div class="toolbar-row toolbar-row--assign">
                <div id="wbTaxonomyDropdownHost" class="assign-picker"></div>
                <input type="hidden" id="wbTaxonomySelected" value="">
                <div class="toolbar-actions">
                  <button type="button" id="btnAssignWbSubject" class="btn-primary">Назначить WB</button>
                  <button type="button" id="btnClearWbCategory" class="btn-secondary">Очистить WB</button>
                </div>
              </div>

              <?php if (empty($wbTaxonomyOptions)): ?>
                <div class="toolbar-note" style="margin-top:10px;">Список пуст, импортируй категории WB в <code>/taxonomy/import.php?source=wildberries</code>.</div>
              <?php endif; ?>

              <div id="assignWbSubjectHint" class="filter-info" style="margin-top:10px;"></div>
            </div>
          </div>
        </div>

        <details class="toolbar-card toolbar-card--wide toolbar-card--filters collapsible-section" open>
          <summary>
            <span class="fold-label">
              <span class="fold-title" style="font-size:14px;">Фильтры и поиск</span>
              <span class="fold-meta">Поиск, быстрые чекбоксы, категории, бренд, хештеги и характеристики собраны в одном блоке.</span>
            </span>
            <span class="count-chip">Показано: <?= h($totalOffers) ?> из <?= h(($allOffersTotal > 0) ? $allOffersTotal : $totalOffers) ?></span>
          </summary>
          <div class="collapsible-section-body">
            <div class="toolbar-row toolbar-row--search">
              <label class="field search-field">
                <span>Поиск по offer id или названию</span>
                <input
                  type="text"
                  id="nameSearchInput"
                  class="control-input control-input--wide"
                  value="<?= h($filterNameRaw) ?>"
                  placeholder="Сначала ищем по артикулу, потом по названию">
              </label>

              <div class="toolbar-actions toolbar-actions--right">
                <button type="button" id="btnApplyFilters" class="btn-primary">Применить фильтры</button>
                <button type="button" id="btnClearFilters" class="btn-secondary">Снять все</button>
              </div>
            </div>

            <div class="toolbar-row">
              <label class="checkbox-chip">
                <input type="checkbox" id="inStockOnly" <?= $filterInStock ? 'checked' : '' ?>>
                <span>Только в наличии (<code>count</code> / <code>stock</code> &gt; 0)</span>
              </label>

              <label class="checkbox-chip">
                <input type="checkbox" id="notInOzonOnly" <?= $filterNotInOzon ? 'checked' : '' ?>>
                <span>Только отсутствующие в Ozon по <code>offer_id</code></span>
              </label>

              <label class="checkbox-chip">
                <input type="checkbox" id="notInOzonArchiveOnly" <?= $filterNotInOzonArchive ? 'checked' : '' ?>>
                <span>Только отсутствующие в архиве Ozon</span>
              </label>

              <label class="checkbox-chip">
                <input type="checkbox" id="notInWbOnly" <?= $filterNotInWb ? 'checked' : '' ?>>
                <span>Только отсутствующие на WB по <code>offer_id</code></span>
              </label>

              <label class="checkbox-chip">
                <input type="checkbox" id="hasPictureOnly" <?= $filterHasPicture ? 'checked' : '' ?>>
                <span>Скрыть товары без фотографии</span>
              </label>

              <label class="checkbox-chip">
                <input type="checkbox" id="notBulkyOzonOnly" <?= $filterNotBulkyOzon ? 'checked' : '' ?>>
                <span>Скрыть крупногабаритные для Ozon</span>
              </label>
            </div>

            <div class="toolbar-row">
              <div class="range-grid">
                <div class="range-card">
                  <div class="range-card-title">Цена по <code>price_original</code></div>
                  <div class="range-fields">
                    <label class="field">
                      <span>от</span>
                      <input type="text" id="priceMinInput" class="control-input" inputmode="decimal" value="<?= h($filterPriceMinRaw) ?>" placeholder="например 1000">
                    </label>
                    <label class="field">
                      <span>до</span>
                      <input type="text" id="priceMaxInput" class="control-input" inputmode="decimal" value="<?= h($filterPriceMaxRaw) ?>" placeholder="например 5000">
                    </label>
                  </div>
                </div>

                <div class="range-card">
                  <div class="range-card-title">Остатки по <code>stock</code> / <code>count</code></div>
                  <div class="range-fields">
                    <label class="field">
                      <span>от</span>
                      <input type="text" id="stockMinInput" class="control-input" inputmode="numeric" value="<?= h($filterStockMinRaw) ?>" placeholder="например 1">
                    </label>
                    <label class="field">
                      <span>до</span>
                      <input type="text" id="stockMaxInput" class="control-input" inputmode="numeric" value="<?= h($filterStockMaxRaw) ?>" placeholder="например 100">
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <?php if (($filterNotInOzon || $filterNotInOzonArchive) && !empty($ozonFilterError)): ?>
              <div class="inline-status-error">Ozon API: <?= h($ozonFilterError) ?></div>
            <?php endif; ?>
            <?php if ($filterNotInWb && !empty($wbFilterError)): ?>
              <div class="inline-status-error">WB: <?= h($wbFilterError) ?></div>
            <?php elseif ($filterNotInWb && !empty($wbFilterWarning)): ?>
              <div class="inline-status-error">WB: <?= h($wbFilterWarning) ?></div>
            <?php elseif ($filterNotInWb && is_array($wbFilterMeta)): ?>
              <div class="toolbar-note">
                WB: локально в БД активных карточек: <?= h((string)($wbFilterMeta['count'] ?? 0)) ?>.
                Последняя синхронизация: <?= h((string)($wbFilterMeta['last_success_at'] ?? 'нет')) ?>.
              </div>
            <?php endif; ?>

            <div class="toolbar-row" style="margin-top:12px;">
              <div id="offersFilters" class="filter-host"></div>
            </div>

            <div id="offersFiltersInfo" class="filter-info" style="margin-top:10px;"></div>

            <details id="offersParamFiltersCard" class="inline-toggle-card" style="margin-top:12px;">
              <summary>
                <span class="fold-label">
                  <span class="fold-title" style="font-size:13px;">Фильтры по характеристикам</span>
                  <span class="fold-meta">Мультивыбор по <code>param_name</code>. Удобно для точной чистки выдачи перед массовыми действиями.</span>
                </span>
                <span id="offersParamFiltersCount" class="count-chip">0</span>
              </summary>
              <div id="offersParamFilters" class="param-filters-host"></div>
            </details>

            <div class="selection-actions" style="margin-top:12px;">
              <?php if (!$matchingOfferIdsTruncated): ?>
                <button type="button" id="btnSelectAllAllPages" class="btn-secondary" title="Выбрать все товары из текущего набора (все страницы)">Выбрать все из текущей выборки</button>
              <?php else: ?>
                <button type="button" class="btn-secondary" disabled title="Слишком много товаров для выбора всех сразу">Выбрать все из текущей выборки</button>
	              <?php endif; ?>
	              <button type="button" id="btnClearSelection" class="btn-neutral">Очистить выбор</button>
	              <span class="selectedCountBadge count-chip">выбрано: 0 из <?= h((string)$totalOffers) ?></span>
	            </div>

            <div class="toolbar-note" style="margin-top:10px;">
              Галочки в таблице работают по текущей странице, а кнопка выбора всей выборки берёт все найденные товары сразу, если их не слишком много.
            </div>
          </div>
        </details>
      </div>
      <form method="post" action="run_op.php" id="formAssignOzonCategory" style="display:none;">
        <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
        <input type="hidden" name="op_type" value="set_ozon_category">
        <input type="hidden" name="offer_ids_json" id="assignOfferIdsJson" value="">
        <input type="hidden" name="ozon_category" id="assignOzonCategoryValue" value="">
        <input type="hidden" name="clear_category" id="assignOzonCategoryClear" value="0">
        <input type="hidden" name="auto_dataset" value="0">
        <input type="hidden" name="inplace" value="1">
        <input type="hidden" name="run_inline" value="1">
      </form>
      <form method="post" action="run_op.php" id="formAssignWbCategory" style="display:none;">
        <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">
        <input type="hidden" name="op_type" value="set_wb_category">
        <input type="hidden" name="offer_ids_json" id="assignWbCategoryOfferIdsJson" value="">
        <input type="hidden" name="wb_category" id="assignWbCategoryValue" value="">
        <input type="hidden" name="clear_category" id="assignWbCategoryClear" value="0">
        <input type="hidden" name="auto_dataset" value="0">
        <input type="hidden" name="inplace" value="1">
        <input type="hidden" name="run_inline" value="1">
      </form>

      <?php if (true): // Основная таблица остаётся на прежнем месте после операций и фильтров. ?>
      <div class="compact-table-bar">
        <?php if (!$showAll): ?>
          <div class="pager">
            <span class="count-chip">Страница <?= h($page) ?> / <?= h($totalPages) ?></span>
            <?php if ($page > 1): ?>
              <a href="<?= h('view.php?' . http_build_query(['id' => $row['id'], 'page' => $page - 1, 'limit' => ($showAll ? 'all' : $limit)])) ?>">← предыдущая</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a href="<?= h('view.php?' . http_build_query(['id' => $row['id'], 'page' => $page + 1, 'limit' => ($showAll ? 'all' : $limit)])) ?>">следующая →</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div></div>
        <?php endif; ?>

        <form class="compact-limit-form" method="get" action="view.php">
          <input type="hidden" name="id" value="<?= h($row['id']) ?>">

          <select class="control-select" name="limit" onchange="this.form.submit()" title="Сколько строк показывать в таблице">
            <option value="5" <?= (!$showAll && $limit === 5 ? 'selected' : '') ?>>5</option>
            <option value="10" <?= (!$showAll && $limit === 10 ? 'selected' : '') ?>>10</option>
            <option value="20" <?= (!$showAll && $limit === 20 ? 'selected' : '') ?>>20</option>
            <option value="50" <?= (!$showAll && $limit === 50 ? 'selected' : '') ?>>50</option>
            <option value="100" <?= (!$showAll && $limit === 100 ? 'selected' : '') ?>>100</option>
            <option value="all" <?= ($showAll ? 'selected' : '') ?>>все</option>
          </select>

          <?php if (!$showAll): ?>
            <input type="hidden" name="page" value="<?= h($page) ?>">
          <?php endif; ?>
        </form>
      </div>

      <div class="offers-table-wrap">
        <table id="offersTable" style="width:100%;border-collapse:collapse;table-layout:fixed;">

          <thead>
            <tr>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:32px;text-align:center;">
                <input type="checkbox" id="chkAllPage" title="Выбрать все на странице">
              </th>

              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:62px;">id</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:112px;">Название</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:78px;">Фото</th>

              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:105px;">Категория Ozon</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:105px;">Категория WB</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:110px;">Категория поставщика</th>

              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:120px;">Прочие данные</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:190px;">Описание</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:150px;">param_name</th>
              <th style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:3px;width:65px;">Ссылки</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($offers as $o): ?>
              <?php
              $rowCat = (string)($o['category_path'] ?? '');
              $rowOzon = (string)($o['ozon_category'] ?? '');
              $rowWb = (string)($o['wb_category'] ?? ($o['wb_subject_id'] ?? ''));
              $rowBrand = (string)($o['brand_effective'] ?? '');
              ?>
              <tr data-catpath="<?= h($rowCat) ?>" data-ozoncat="<?= h($rowOzon) ?>" data-wbcat="<?= h($rowWb) ?>" data-brand="<?= h($rowBrand) ?>">

                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;text-align:center;">
                  <?php $oid = (string)($o['id'] ?? '');
                  $disabled = (trim($oid) === ''); ?>
                  <input type="checkbox" class="offerChk" data-offer-id="<?= h($oid) ?>" <?= $disabled ? 'disabled' : '' ?>>
                </td>

                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($o['id']) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h($o['name']) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;text-align:center;">
                  <?php $pic0 = (!empty($o['pictures']) ? (string)$o['pictures'][0] : ''); ?>
                  <?php if ($pic0 !== ''): ?>
                    <a href="<?= h($pic0) ?>" target="_blank" rel="noopener">
                      <img
                        src="<?= h($pic0) ?>"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        style="height:74px;width:auto;max-width:74px;object-fit:contain;background:#fff;display:block;margin:0 auto;">
                    </a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>

                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php
                  $oz = (string)($o['ozon_category'] ?? '');
                  $ozLabel = ($oz !== '' && isset($ozonTaxonomyLabelByValue[$oz])) ? $ozonTaxonomyLabelByValue[$oz] : '';
                  ?>
                  <div style="white-space:pre-wrap;line-height:1.25;">
                    <?php if ($ozLabel !== ''): ?>
                      <?= h($ozLabel) ?>
                    <?php else: ?>
                      <?= h($oz) ?>
                    <?php endif; ?>
                  </div>

                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php
                  $wb = (string)($o['wb_category'] ?? ($o['wb_subject_id'] ?? ''));
                  $wbLabel = ($wb !== '' && isset($wbTaxonomyLabelByValue[$wb])) ? $wbTaxonomyLabelByValue[$wb] : '';
                  ?>
                  <div style="white-space:pre-wrap;line-height:1.25;">
                    <?php if ($wbLabel !== ''): ?>
                      <?= h($wbLabel) ?>
                    <?php else: ?>
                      <?= h($wb) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <?php $cp = (string)($o['category_path'] ?? ''); ?>
                  <?php if ($cp !== ''): ?>
                    <div style="white-space:pre-wrap;line-height:1.25;"><?= h($cp) ?></div>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>

                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h(implode("\n", $o['details'])) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:8px;">
                  <?php $html = sanitize_description_html($o['description_html'] ?: ''); ?>
                  <div class="offer-description-preview">
                    <?php if ($html !== ''): ?>
                      <?= $html ?>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;"><?= h(implode("\n", $o['params_lines'])) ?></div>
                </td>
                <td style="border-bottom:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;font-size:9px;">
                  <div style="white-space:pre-wrap;line-height:1.25;">
                    <?php if ($o['url']): ?>
                      Ссылка: <a href="<?= h($o['url']) ?>" target="_blank" rel="noopener">open</a>
                    <?php else: ?>
                      <span class="muted">URL: —</span>
                    <?php endif; ?>

                    <?php if (!empty($o['pictures'])): ?>
                      <?php foreach ($o['pictures'] as $i => $p): $n = $i + 1; ?>
                        <?= "\n" ?>Pic<?= $n ?>: <a href="<?= h($p) ?>" target="_blank" rel="noopener">open</a>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <?= "\n" ?><span class="muted">Pics: —</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <details class="inline-toggle-card" style="margin-top:12px;">
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:13px;">Импорт заполненных XLSX</span>
            <span class="fold-meta">Вынесен вниз страницы, потому что используется заметно реже экспорта и работы с таблицей.</span>
          </span>
        </summary>
        <div class="inline-toggle-body">
          <div class="marketplace-assign-grid">
            <form method="post" action="import_ozon_template_xlsx.php" enctype="multipart/form-data" class="marketplace-assign-panel">
              <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">

              <div class="toolbar-title">Импорт заполненного Ozon XLSX</div>
              <div class="toolbar-subtitle">Находит товары по колонке <code>Артикул</code> и обновляет текущий датасет. Непустые характеристики пишутся в обычные <code>param</code>, пустые ячейки старые значения не удаляют.</div>

              <div class="toolbar-row">
                <label class="field">
                  <span>Заполненный Ozon XLSX</span>
                  <input type="file" name="xlsxfile" accept=".xlsx" required />
                </label>

                <button type="submit" class="btn-primary">Импортировать Ozon XLSX</button>
              </div>
            </form>

            <form method="post" action="import_wb_template_xlsx.php" enctype="multipart/form-data" class="marketplace-assign-panel">
              <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">

              <div class="toolbar-title">Импорт заполненного WB XLSX</div>
              <div class="toolbar-subtitle">Находит товары по <code>Артикул продавца</code> и обновляет текущий датасет. Непустые характеристики пишутся в <code>wb_param</code>, пустые ячейки старые значения не удаляют.</div>

              <div class="toolbar-row">
                <label class="field">
                  <span>Заполненный WB XLSX</span>
                  <input type="file" name="xlsxfile" accept=".xlsx" required />
                </label>

                <button type="submit" class="btn-primary">Импортировать WB XLSX</button>
              </div>
            </form>
          </div>
        </div>
      </details>

      <details class="inline-toggle-card" style="margin-top:12px;">
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:13px;">Обновление остатков из нового XML</span>
            <span class="fold-meta">Сопоставление по <code>offer id</code>. Источник: <code>stock</code>, а если его нет - <code>count</code>.</span>
          </span>
        </summary>
        <div class="inline-toggle-body">
          <form method="post" action="run_op_stock_upload.php" enctype="multipart/form-data" class="toolbar-row" id="stockUpdateForm" data-queue-aware="1" data-op-type="update_stock_from_feed">
            <input type="hidden" name="dataset_id" value="<?= h($row['id']) ?>">

            <label class="field">
              <span>Новый XML</span>
              <input type="file" name="feed_xml" accept=".xml" required>
            </label>

            <label class="field">
              <span>inplace</span>
              <select class="control-select" name="inplace">
                <option value="1" selected>1</option>
                <option value="0">0</option>
              </select>
            </label>

            <label class="field">
              <span>Если товара нет в новом фиде</span>
              <select class="control-select" name="missing_mode">
                <option value="keep" selected>не менять</option>
                <option value="zero">ставить 0</option>
              </select>
            </label>

            <button type="submit" class="btn-primary">Запустить</button>
          </form>
        </div>
      </details>

      <details class="inline-toggle-card" style="margin-top:12px;">
        <summary>
          <span class="fold-label">
            <span class="fold-title" style="font-size:13px;">История запусков</span>
            <span class="fold-meta">Последние операции по этому датасету.</span>
          </span>
          <span class="count-chip"><?= h(count($ops)) ?></span>
        </summary>
        <div class="inline-toggle-body">
          <?php if (!$ops): ?>
            <p class="muted" style="margin:0;">Операций пока нет.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse; font-size:9px;">
              <thead>
                <tr>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:4px;">ID</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:4px;">Тип</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:4px;">Статус</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:4px;">Создано</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:4px;"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ops as $o): ?>
                  <tr>
                    <td style="border-bottom:1px solid #e5e7eb;padding:4px;"><?= h($o['id']) ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:4px;"><?= h($o['op_type']) ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:4px;"><?= h($o['status']) ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:4px;"><?= ft_local_datetime_html((string)($o['created_at'] ?? ''), ['show_seconds' => true]) ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:4px;">
                      <a href="op.php?id=<?= h($o['id']) ?>">Открыть</a>
                      <?php if ((string)($o['op_type'] ?? '') === 'run_pipeline'): ?>
                        <span class="muted"> · </span>
                        <a href="gpt_log.php?pipeline_op_id=<?= h($o['id']) ?>">GPT</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </details>
    </div>


  </div>
  <div id="ft-page-tail" hidden data-request-id="<?= h($viewRequestId) ?>"></div>

  <script>
    (function() {
      // Offer selection for applying operations to selected items (persists in localStorage per dataset).
      if (window.__ftOfferSelectionInit) return;
      window.__ftOfferSelectionInit = true;

      const datasetId = <?= json_encode((string)$row['id']) ?>;
      const storageKey = 'feedtools_selected_offers_' + datasetId;
      const selectionTotal = <?= json_encode((int)$totalOffers) ?>;

      const badge = document.getElementById('selectedCountBadge');
      const badges = document.querySelectorAll('.selectedCountBadge');
      const hidden = document.getElementById('offerIdsJson');
      const btnClear = document.getElementById('btnClearSelection');
      const chkAllPage = document.getElementById('chkAllPage');
      const btnSelectAllAllPages = document.getElementById('btnSelectAllAllPages');

      // IDs of all offers in the current filtered set (across all pages)
      const allMatchingIds = <?= json_encode($matchingOfferIds, JSON_UNESCAPED_UNICODE) ?>;


      function loadSet() {
        try {
          const raw = localStorage.getItem(storageKey);
          if (!raw) return new Set();
          const arr = JSON.parse(raw);
          if (!Array.isArray(arr)) return new Set();
          return new Set(arr.map(String));
        } catch (e) {
          return new Set();
        }
      }

      function saveSet(set) {
        try {
          localStorage.setItem(storageKey, JSON.stringify(Array.from(set.values())));
        } catch (e) {}
      }

      const set = loadSet();

      function updateBadgeAndHidden() {
        const count = set.size;
        const txt = 'выбрано: ' + count + ' из ' + selectionTotal;
        if (badge) badge.textContent = txt;
        if (badges && badges.length) badges.forEach(el => {
          el.textContent = txt;
        });
        if (hidden) hidden.value = count ? JSON.stringify(Array.from(set.values())) : '';
      }

      function updateRowCheckboxesFromSet() {
        document.querySelectorAll('input.offerChk[data-offer-id]').forEach(chk => {
          const id = String(chk.getAttribute('data-offer-id') || '');
          if (!id) return;
          chk.checked = set.has(id);
        });
      }

      function updateMasterCheckboxState() {
        if (!chkAllPage) return;
        const pageBoxes = Array.from(document.querySelectorAll('input.offerChk[data-offer-id]')).filter(x => !x.disabled);
        const allChecked = pageBoxes.length > 0 && pageBoxes.every(x => x.checked);
        const anyChecked = pageBoxes.some(x => x.checked);
        chkAllPage.indeterminate = anyChecked && !allChecked;
        chkAllPage.checked = allChecked;
      }

      function renderAll() {
        updateRowCheckboxesFromSet();
        updateBadgeAndHidden();
        updateMasterCheckboxState();
      }

      document.addEventListener('change', function(ev) {
        const t = ev.target;
        if (!(t instanceof HTMLInputElement)) return;

        if (t.classList.contains('offerChk')) {
          const id = String(t.getAttribute('data-offer-id') || '');
          if (!id) return;

          if (t.checked) set.add(id);
          else set.delete(id);

          saveSet(set);
          updateBadgeAndHidden();
          updateMasterCheckboxState();
          return;
        }

        if (t.id === 'chkAllPage') {
          const pageBoxes = Array.from(document.querySelectorAll('input.offerChk[data-offer-id]')).filter(x => !x.disabled);
          if (t.checked) {
            pageBoxes.forEach(x => set.add(String(x.getAttribute('data-offer-id') || '')));
          } else {
            pageBoxes.forEach(x => set.delete(String(x.getAttribute('data-offer-id') || '')));
          }
          saveSet(set);
          renderAll();
          return;
        }
      });

      if (btnClear) {
        btnClear.addEventListener('click', function() {
          set.clear();
          saveSet(set);
          renderAll();
        });
      }


      if (btnSelectAllAllPages) {
        btnSelectAllAllPages.addEventListener('click', function() {
          if (!Array.isArray(allMatchingIds) || allMatchingIds.length === 0) return;
          set.clear();
          for (const id of allMatchingIds) {
            const s = String(id || '').trim();
            if (s) set.add(s);
          }
          saveSet(set);
          renderAll();
        });
      }

      // Ensure hidden field is up-to-date right before submitting operations form.
      const opForm = document.querySelector('form[action="run_op.php"]');
      if (opForm) {
        opForm.addEventListener('submit', function() {
          updateBadgeAndHidden();
        });
      }

      renderAll();
    })();
  </script>

  <script>
    (function() {
      const forms = Array.from(document.querySelectorAll('form[data-queue-aware="1"]'));
      if (!forms.length) return;

      function formatWait(sec) {
        sec = Number(sec || 0);
        if (!isFinite(sec) || sec <= 0) return 'меньше минуты';
        sec = Math.round(sec);
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        if (h > 0) return h + ' ч ' + String(m).padStart(2, '0') + ' мин';
        if (m > 0) return m + ' мин ' + String(s).padStart(2, '0') + ' сек';
        return s + ' сек';
      }

      async function fetchQueueSummary(form) {
        const datasetId = String(form.querySelector('[name="dataset_id"]')?.value || '').trim();
        const opField = form.querySelector('[name="op_type"]');
        const opType = String(form.dataset.opType || (opField ? opField.value : '') || '').trim();
        if (!datasetId) return null;

        const params = new URLSearchParams();
        params.set('dataset_id', datasetId);
        if (opType) params.set('op_type', opType);

        const res = await fetch('queue_status.php?' + params.toString(), {
          cache: 'no-store',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) {
          throw new Error('Queue status request failed');
        }
        return await res.json();
      }

      forms.forEach(function(form) {
        form.addEventListener('submit', async function(e) {
          if (form.dataset.queueBypass === '1') return;

          e.preventDefault();

          let summary = null;
          try {
            summary = await fetchQueueSummary(form);
          } catch (err) {
            form.dataset.queueBypass = '1';
            form.submit();
            return;
          }

          if (!summary || !summary.will_wait) {
            form.dataset.queueBypass = '1';
            form.submit();
            return;
          }

          const lines = [
            summary.dataset_blocked
              ? 'Операция не начнётся сразу, потому что по этому датасету уже выполняется другая задача.'
              : 'Операция не начнётся сразу, потому что сейчас заняты worker-слоты.',
            ''
          ];

          const blocker = summary.dataset_blocker || summary.blocker || null;
          if (blocker && blocker.id) {
            let blockerLine = 'Сейчас работает операция #' + blocker.id;
            if (blocker.op_type) blockerLine += ' (' + blocker.op_type + ')';
            if (blocker.dataset_id) blockerLine += ' по датасету #' + blocker.dataset_id;
            lines.push(blockerLine);
          }

          if (Number(summary.ahead_count || 0) > 0) {
            lines.push('Операций впереди: ' + summary.ahead_count);
          }

          if (Number(summary.max_parallel || 0) > 0) {
            lines.push('Параллельных worker-слотов: ' + summary.max_parallel);
          }

          lines.push('Примерное ожидание старта: ' + formatWait(summary.estimated_wait_sec || 0));
          lines.push('');
          lines.push('Запустить и поставить задачу в очередь?');

          if (!window.confirm(lines.join('\n'))) {
            return;
          }

          form.dataset.queueBypass = '1';
          form.submit();
        });
      });
    })();
  </script>

  <script>
    (function() {
      if (window.__ftAssignOzonCategoryInit) return;
      window.__ftAssignOzonCategoryInit = true;

      const btnAssign = document.getElementById('btnAssignOzonCategory');
      const btnClear = document.getElementById('btnClearOzonCategory');
      const hint = document.getElementById('assignOzonCategoryHint');

      const catHidden = document.getElementById('ozonTaxonomySelected'); // выбранная категория (value)
      const selectedIdsHidden = document.getElementById('offerIdsJson'); // JSON выбранных offer id (из selection-скрипта)

      const form = document.getElementById('formAssignOzonCategory');
      const fIds = document.getElementById('assignOfferIdsJson');
      const fCat = document.getElementById('assignOzonCategoryValue');
      const fClear = document.getElementById('assignOzonCategoryClear');

      if (!btnAssign || !btnClear || !form || !fIds || !fCat || !fClear || !catHidden || !selectedIdsHidden) return;

      function setHint(text, isError) {
        if (!hint) return;
        hint.textContent = text || '';
        hint.style.color = isError ? '#b91c1c' : '';
      }

      function ensureSelection() {
        const idsJson = String(selectedIdsHidden.value || '').trim();
        if (!idsJson) {
          setHint('Нужно выбрать товары галочками (сейчас выбрано: 0).', true);
          return '';
        }
        return idsJson;
      }

      btnAssign.addEventListener('click', function() {
        setHint('', false);

        const cat = String(catHidden.value || '').trim();
        if (!cat) {
          setHint('Сначала выбери категорию в списке.', true);
          return;
        }

        const idsJson = ensureSelection();
        if (!idsJson) return;

        fIds.value = idsJson;
        fCat.value = cat;
        fClear.value = '0';

        if (!confirm('Назначить выбранную категорию Ozon всем выбранным товарам?')) return;
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        btnAssign.disabled = true;
        btnClear.disabled = true;

        form.submit();
      });

      btnClear.addEventListener('click', function() {
        setHint('', false);

        const idsJson = ensureSelection();
        if (!idsJson) return;

        fIds.value = idsJson;
        fCat.value = '';
        fClear.value = '1';

        if (!confirm('Очистить назначенную категорию Ozon у всех выбранных товаров?')) return;
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        btnAssign.disabled = true;
        btnClear.disabled = true;

        form.submit();
      });
    })();
  </script>

  <script>
    (function() {
      if (window.__ftAssignWbCategoryInit) return;
      window.__ftAssignWbCategoryInit = true;

      const btnAssign = document.getElementById('btnAssignWbSubject');
      const btnClear = document.getElementById('btnClearWbCategory');
      const hint = document.getElementById('assignWbSubjectHint');

      const catHidden = document.getElementById('wbTaxonomySelected');
      const selectedIdsHidden = document.getElementById('offerIdsJson');

      const form = document.getElementById('formAssignWbCategory');
      const fIds = document.getElementById('assignWbCategoryOfferIdsJson');
      const fCat = document.getElementById('assignWbCategoryValue');
      const fClear = document.getElementById('assignWbCategoryClear');

      if (!btnAssign || !btnClear || !form || !fIds || !fCat || !fClear || !catHidden || !selectedIdsHidden) return;

      function setHint(text, isError) {
        if (!hint) return;
        hint.textContent = text || '';
        hint.style.color = isError ? '#b91c1c' : '';
      }

      function ensureSelection() {
        const idsJson = String(selectedIdsHidden.value || '').trim();
        if (!idsJson) {
          setHint('Нужно выбрать товары галочками (сейчас выбрано: 0).', true);
          return '';
        }
        return idsJson;
      }

      btnAssign.addEventListener('click', function() {
        setHint('', false);

        const cat = String(catHidden.value || '').trim();
        if (!cat) {
          setHint('Сначала выбери категорию WB в списке.', true);
          return;
        }

        const idsJson = ensureSelection();
        if (!idsJson) return;

        fIds.value = idsJson;
        fCat.value = cat;
        fClear.value = '0';

        if (!confirm('Назначить выбранную категорию WB всем выбранным товарам?')) return;
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        btnAssign.disabled = true;
        btnClear.disabled = true;

        form.submit();
      });

      btnClear.addEventListener('click', function() {
        setHint('', false);

        const idsJson = ensureSelection();
        if (!idsJson) return;

        fIds.value = idsJson;
        fCat.value = '';
        fClear.value = '1';

        if (!confirm('Очистить назначенную категорию WB у всех выбранных товаров?')) return;
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        btnAssign.disabled = true;
        btnClear.disabled = true;

        form.submit();
      });
    })();
  </script>


  <script>
    window.ftOffersFiltersConfig = <?= json_encode([
      'datasetId' => (string)$row['id'],
      'limit' => $showAll ? 'all' : (string)$limit,
      'emptyToken' => EMPTY_TOKEN,
      'facets' => $facets,
      'selected' => [
        'catpath' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterCatpath),
        'ozoncat' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterOzoncat),
        'wbcat' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterWbcat),
        'brand' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterBrand),
        'hashtag' => $filterHashtagsUi,
      ],
      'selectedParams' => $filterParamsUi,
      'appliedFiltersState' => [
        'q_name' => $filterNameRaw,
        'f_instock' => $filterInStock,
        'f_not_in_ozon' => $filterNotInOzon,
        'f_not_in_ozon_archive' => $filterNotInOzonArchive,
        'f_not_in_wb' => $filterNotInWb,
        'f_has_picture' => $filterHasPicture,
        'f_not_bulky_ozon' => $filterNotBulkyOzon,
        'f_price_min' => $filterPriceMinRaw,
        'f_price_max' => $filterPriceMaxRaw,
        'f_stock_min' => $filterStockMinRaw,
        'f_stock_max' => $filterStockMaxRaw,
        'selected' => [
          'catpath' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterCatpath),
          'ozoncat' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterOzoncat),
          'wbcat' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterWbcat),
          'brand' => array_map(fn($v) => ($v === '' ? EMPTY_TOKEN : $v), $filterBrand),
          'hashtag' => $filterHashtagsUi,
        ],
        'selectedParams' => $filterParamsUi,
      ],
      'ozonLabels' => $ozonFacetLabels,
      'wbLabels' => $wbFacetLabels,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="assets/view_offers_filters.js?v=20260709_selected_only_session"></script>
  <script>
    (function() {
      if (window.__ftOzonTaxPickerInit) return;
      window.__ftOzonTaxPickerInit = true;

      const host = document.getElementById('ozonTaxonomyDropdownHost');
      const hidden = document.getElementById('ozonTaxonomySelected');
      if (!host) return;

      let options = [];
      let loadSeq = 0;
      let searchTimer = null;
      let selectedValue = '';
      let selectedLabel = '';

      function mkSingleDropdown(title, options) {
        const wrap = document.createElement('div');
        wrap.className = 'dd';

        const btn = document.createElement('div');
        btn.className = 'ddbtn';

        const t = document.createElement('span');
        t.textContent = title;

        const v = document.createElement('span');
        v.className = 'muted';
        v.style.fontSize = '12px';
        v.style.flex = '1 1 auto';
        v.style.minWidth = '0';
        v.style.whiteSpace = 'nowrap';
        v.style.overflow = 'hidden';
        v.style.textOverflow = 'ellipsis';
        v.textContent = 'не выбрано';

        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.textContent = '0';

        btn.appendChild(t);
        btn.appendChild(v);
        btn.appendChild(pill);

        const panel = document.createElement('div');
        panel.className = 'ddpanel';

        const search = document.createElement('input');
        search.className = 'ddsearch';
        search.type = 'text';
        search.placeholder = 'поиск...';
        panel.appendChild(search);

        const list = document.createElement('div');
        panel.appendChild(list);

        const actions = document.createElement('div');
        actions.className = 'ddactions';

        const btnClear = document.createElement('button');
        btnClear.type = 'button';
        btnClear.textContent = 'Снять выбор';
        actions.appendChild(btnClear);

        panel.appendChild(actions);

        function updateButton() {
          pill.textContent = selectedValue ? '1' : '0';
          v.textContent = selectedLabel ? selectedLabel : 'не выбрано';
        }

        function renderList(qRaw = '') {
          const q = String(qRaw || '').trim().toLowerCase();
          list.innerHTML = '';

          const total = options.length;

          const hint = document.createElement('div');
          hint.className = 'muted';
          hint.style.fontSize = '11px';
          hint.style.margin = '0 0 6px';
          hint.textContent = q ?
            `Показаны найденные категории (до 50 результатов)` :
            `Показаны первые ${total} категорий. Для быстрого выбора начни вводить поиск.`;
          list.appendChild(hint);

          for (const opt of options) {
            const text = String(opt.label || '');
            const val = String(opt.value || '');

            const item = document.createElement('label');
            item.className = 'dditem';

            const rb = document.createElement('input');
            rb.type = 'radio';
            rb.name = 'ozonTaxonomySingle';
            rb.value = val;
            rb.checked = (val === selectedValue);

            rb.addEventListener('change', () => {
              selectedValue = val;
              selectedLabel = text;
              if (hidden) hidden.value = selectedValue;
              updateButton();

              // закрыть дропдаун после выбора
              panel.classList.remove('open');
            });

            const span = document.createElement('span');
            span.textContent = text;

            item.appendChild(rb);
            item.appendChild(span);
            list.appendChild(item);
          }

          if (total === 0) {
            const empty = document.createElement('div');
            empty.className = 'muted';
            empty.style.fontSize = '12px';
            empty.textContent = 'Ничего не найдено';
            list.appendChild(empty);
          }
        }

        async function loadOptions(query) {
          const seq = ++loadSeq;
          const q = String(query || '').trim();
          const limit = q ? 50 : 10;
          list.innerHTML = '<div class="muted" style="font-size:12px;">Загружаем категории Ozon...</div>';

          try {
            const response = await fetch('taxonomy/options.php?source=ozon&q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(String(limit)), {
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json().catch(() => ({}));
            if (seq !== loadSeq) return;
            if (!response.ok || !payload.ok) {
              throw new Error(payload.error || 'Не удалось загрузить категории Ozon');
            }
            options = Array.isArray(payload.options) ? payload.options : [];
            renderList(q);
          } catch (error) {
            if (seq !== loadSeq) return;
            list.innerHTML = '<div class="muted" style="font-size:12px;">Не удалось загрузить категории Ozon</div>';
          }
        }

        btn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const isOpen = panel.classList.contains('open');
          if (!isOpen) {
            if (window.ftOpenDropdownPanel) window.ftOpenDropdownPanel(panel, btn);
            else panel.classList.add('open');
            await loadOptions(search.value);
            search.focus();
          } else if (window.ftCloseDropdownPanels) {
            window.ftCloseDropdownPanels();
          } else {
            panel.classList.remove('open');
          }
        });

        panel.addEventListener('click', (e) => e.stopPropagation());
        search.addEventListener('input', () => {
          if (searchTimer) window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => {
            loadOptions(search.value);
          }, 180);
        });

        btnClear.addEventListener('click', () => {
          selectedValue = '';
          selectedLabel = '';
          if (hidden) hidden.value = '';
          updateButton();
          search.value = '';
          loadOptions('');
        });

        wrap.appendChild(btn);
        wrap.appendChild(panel);

        wrap._update = () => {
          updateButton();
          renderList();
        };

        updateButton();
        return wrap;
      }

      host.innerHTML = '';
      host.appendChild(mkSingleDropdown('Категория', options));
    })();
  </script>
  <script>
    (function() {
      if (window.__ftWbTaxPickerInit) return;
      window.__ftWbTaxPickerInit = true;

      const host = document.getElementById('wbTaxonomyDropdownHost');
      const hidden = document.getElementById('wbTaxonomySelected');
      if (!host) return;

      let options = [];
      let loadSeq = 0;
      let searchTimer = null;
      let selectedValue = '';
      let selectedLabel = '';

      function mkSingleDropdown(title, options) {
        const wrap = document.createElement('div');
        wrap.className = 'dd';

        const btn = document.createElement('div');
        btn.className = 'ddbtn';

        const t = document.createElement('span');
        t.textContent = title;

        const v = document.createElement('span');
        v.className = 'muted';
        v.style.fontSize = '12px';
        v.style.flex = '1 1 auto';
        v.style.minWidth = '0';
        v.style.whiteSpace = 'nowrap';
        v.style.overflow = 'hidden';
        v.style.textOverflow = 'ellipsis';
        v.textContent = 'не выбрано';

        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.textContent = '0';

        btn.appendChild(t);
        btn.appendChild(v);
        btn.appendChild(pill);

        const panel = document.createElement('div');
        panel.className = 'ddpanel';

        const search = document.createElement('input');
        search.className = 'ddsearch';
        search.type = 'text';
        search.placeholder = 'поиск...';
        panel.appendChild(search);

        const list = document.createElement('div');
        panel.appendChild(list);

        const actions = document.createElement('div');
        actions.className = 'ddactions';

        const btnClear = document.createElement('button');
        btnClear.type = 'button';
        btnClear.textContent = 'Снять выбор';
        actions.appendChild(btnClear);

        panel.appendChild(actions);

        function updateButton() {
          pill.textContent = selectedValue ? '1' : '0';
          v.textContent = selectedLabel ? selectedLabel : 'не выбрано';
        }

        function renderList(qRaw = '') {
          const q = String(qRaw || '').trim().toLowerCase();
          list.innerHTML = '';

          const total = options.length;

          const hint = document.createElement('div');
          hint.className = 'muted';
          hint.style.fontSize = '11px';
          hint.style.margin = '0 0 6px';
          hint.textContent = q
            ? `Показаны найденные категории (до 50 результатов)`
            : `Показаны первые ${total} категорий. Для быстрого выбора начни вводить поиск.`;
          list.appendChild(hint);

          for (const opt of options) {
            const text = String(opt.label || '');
            const val = String(opt.value || '');

            const item = document.createElement('label');
            item.className = 'dditem';

            const rb = document.createElement('input');
            rb.type = 'radio';
            rb.name = 'wbTaxonomySingle';
            rb.value = val;
            rb.checked = (val === selectedValue);

            rb.addEventListener('change', () => {
              selectedValue = val;
              selectedLabel = text;
              if (hidden) hidden.value = selectedValue;
              updateButton();
              panel.classList.remove('open');
            });

            const span = document.createElement('span');
            span.textContent = text;

            item.appendChild(rb);
            item.appendChild(span);
            list.appendChild(item);
          }

          if (total === 0) {
            const empty = document.createElement('div');
            empty.className = 'muted';
            empty.style.fontSize = '12px';
            empty.textContent = 'Ничего не найдено';
            list.appendChild(empty);
          }
        }

        async function loadOptions(query) {
          const seq = ++loadSeq;
          const q = String(query || '').trim();
          const limit = q ? 50 : 10;
          list.innerHTML = '<div class="muted" style="font-size:12px;">Загружаем категории WB...</div>';

          try {
            const response = await fetch('taxonomy/options.php?source=wildberries&q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(String(limit)), {
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json().catch(() => ({}));
            if (seq !== loadSeq) return;
            if (!response.ok || !payload.ok) {
              throw new Error(payload.error || 'Не удалось загрузить категории WB');
            }
            options = Array.isArray(payload.options) ? payload.options : [];
            renderList(q);
          } catch (error) {
            if (seq !== loadSeq) return;
            list.innerHTML = '<div class="muted" style="font-size:12px;">Не удалось загрузить категории WB</div>';
          }
        }

        btn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const isOpen = panel.classList.contains('open');
          if (!isOpen) {
            if (window.ftOpenDropdownPanel) window.ftOpenDropdownPanel(panel, btn);
            else panel.classList.add('open');
            await loadOptions(search.value);
            search.focus();
          } else if (window.ftCloseDropdownPanels) {
            window.ftCloseDropdownPanels();
          } else {
            panel.classList.remove('open');
          }
        });

        panel.addEventListener('click', (e) => e.stopPropagation());
        search.addEventListener('input', () => {
          if (searchTimer) window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => {
            loadOptions(search.value);
          }, 180);
        });

        btnClear.addEventListener('click', () => {
          selectedValue = '';
          selectedLabel = '';
          if (hidden) hidden.value = '';
          updateButton();
          search.value = '';
          loadOptions('');
        });

        wrap.appendChild(btn);
        wrap.appendChild(panel);

        wrap._update = () => {
          updateButton();
          renderList();
        };

        updateButton();
        return wrap;
      }

      host.innerHTML = '';
      host.appendChild(mkSingleDropdown('Категория', options));
    })();
  </script>

  <script>
    (function() {
      if (window.__ftExportXlsInit) return;
      window.__ftExportXlsInit = true;

      const btn = document.getElementById('btnExportXls');
      const hint = document.getElementById('exportXlsHint');

      const selectedIdsHidden = document.getElementById('offerIdsJson');
      const form = document.getElementById('formExportXls');
      const fIds = document.getElementById('exportOfferIdsJson');

      if (!btn || !form || !fIds || !selectedIdsHidden) return;

      function setHint(text, isError) {
        if (!hint) return;
        hint.textContent = text || '';
        hint.style.color = isError ? '#b91c1c' : '';
      }

      btn.addEventListener('click', function() {
        setHint('', false);

        const idsJson = String(selectedIdsHidden.value || '').trim();

        // Пустой выбор = весь датасет
        fIds.value = idsJson; // может быть пустым
        form.submit();

      });
    })();
  </script>

</body>

</html>
