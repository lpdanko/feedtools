<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/navigation.php';
require_once __DIR__ . '/../../app/wildberries/WildberriesClient.php';
require_once __DIR__ . '/../../app/wildberries/WildberriesDictionaries.php';
require_once __DIR__ . '/../../app/taxonomy/GlobalAttributeExclusions.php';

function ozon_cfg(): array {
  $cfg = require __DIR__ . '/../../app/config.php';
  $oz = $cfg['ozon'] ?? [];
  if (!is_array($oz)) $oz = [];
  $oz += ['client_id'=>'', 'api_key'=>'', 'base_url'=>'https://api-seller.ozon.ru', 'timeout_sec'=>30];

  if (trim((string)$oz['client_id']) === '' || trim((string)$oz['api_key']) === '') {
    throw new RuntimeException('Ozon API не настроен: задайте OZON_CLIENT_ID и OZON_API_KEY (ENV или app/config.local.php)');
  }
  return $oz;
}

function ozon_post_json(array $oz, string $path, array $payload): array {
  $url = rtrim((string)$oz['base_url'], '/') . $path;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Client-Id: ' . (string)$oz['client_id'],
      'Api-Key: '   . (string)$oz['api_key'],
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => (int)($oz['timeout_sec'] ?? 30),
  ]);

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
// curl_close() deprecated since PHP 8.5 and has no effect since PHP 8.0
unset($ch);

  if ($raw === false) {
    throw new RuntimeException('Ozon request failed: ' . ($curlErr ?: 'curl error'));
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new RuntimeException('Ozon вернул некорректный JSON (HTTP ' . $http . ')');
  }
  if ($http >= 400) {
    $msg = $data['message'] ?? ($data['error']['message'] ?? 'HTTP error');
    throw new RuntimeException('Ozon HTTP ' . $http . ': ' . $msg);
  }
  return $data;
}

function ozon_norm_attr_name(string $s): string {
  $s = trim($s);

  // NBSP / zero-width space → обычный пробел
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);

  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace('ё', 'е', $s);

  // вычищаем пунктуацию/символы, чтобы совпадало стабильнее между категориями
  $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
  $s = preg_replace('~\s+~u', ' ', $s);

  return trim($s);
}
function ozon_attribute_values(array $oz, int $descriptionCategoryId, int $typeId, int $attributeId, string $language = 'RU', int $limitTotal = 500): array {
  static $cache = [];
  $key = $descriptionCategoryId . ':' . $typeId . ':' . $attributeId . ':' . $language . ':' . $limitTotal;
  if (isset($cache[$key])) return $cache[$key];

  $values = [];
  $seen = [];

  $lastValueId = 0;
  $safety = 0;

  while ($safety++ < 100 && count($values) < $limitTotal) {
    $resp = ozon_post_json($oz, '/v1/description-category/attribute/values', [
      'description_category_id' => $descriptionCategoryId,
      'type_id' => $typeId,
      'attribute_id' => $attributeId,
      'language' => $language,
      'last_value_id' => $lastValueId,
      'limit' => min(1000, max(1, $limitTotal - count($values))),
    ]);

    $result = $resp['result'] ?? [];
    if (!is_array($result)) $result = [];

    // в разных версиях API список может лежать либо в result, либо в result.values
    $items = $result;
    if (isset($result['values']) && is_array($result['values'])) {
      $items = $result['values'];
    }

    if (!is_array($items) || !$items) break;

    $maxId = $lastValueId;

    foreach ($items as $it) {
      if (!is_array($it)) continue;

      $v = trim((string)($it['value'] ?? ''));
      if ($v === '') continue;

      $vk = ozon_norm_attr_name($v);
      if ($vk !== '' && !isset($seen[$vk])) {
        $seen[$vk] = 1;
        $values[] = $v;
        if (count($values) >= $limitTotal) break;
      }

      $vid = isset($it['id']) ? (int)$it['id'] : 0;
      if ($vid > $maxId) $maxId = $vid;
    }

    $hasNext = (bool)($result['has_next'] ?? false);
    if (!$hasNext || count($values) >= $limitTotal) break;

    $next = isset($result['last_value_id']) ? (int)$result['last_value_id'] : $maxId;
    if ($next <= $lastValueId) break;

    $lastValueId = $next;
  }

  $cache[$key] = $values;
  return $values;
}

function ozon_required_attr_data(array $oz, int $descriptionCategoryId, int $typeId, array $excludeNames = []): array {
  $resp = ozon_post_json($oz, '/v1/description-category/attribute', [
    'description_category_id' => $descriptionCategoryId,
    'type_id' => $typeId,
  ]);

  $items = $resp['result'] ?? [];
  if (!is_array($items)) $items = [];

  if (isset($items['attributes']) && is_array($items['attributes'])) {
    $items = $items['attributes'];
  }

  $exclude = [];
  foreach ($excludeNames as $x) {
    $x = trim((string)$x);
    if ($x === '') continue;
    $exclude[ozon_norm_attr_name($x)] = true;
  }

  $lines = [];     // для textarea (только имена)
  $meta  = [];     // для meta_json (имя + описание)
  $seen  = [];

  foreach ($items as $a) {
    if (!is_array($a)) continue;

    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $nk = ozon_norm_attr_name($name);
    if ($nk !== '' && isset($exclude[$nk])) continue;

    if (isset($seen[$nk])) continue;
    $seen[$nk] = 1;

    // description может отсутствовать — тогда пустая строка
    $desc = trim((string)($a['description'] ?? ''));

    $id = isset($a['id']) ? (int)$a['id'] : 0;
    $dictionaryId = (int)($a['dictionary_id'] ?? 0);
    $allowedValues = [];

    // Если Ozon отдал dictionary_id, подтягиваем справочник даже без подсказки в description.
    $descLower = str_replace('ё', 'е', mb_strtolower($desc, 'UTF-8'));
    $shouldFetchValues = $id > 0
      && $dictionaryId > 0
      && !in_array($nk, ['бренд', 'бренд товара', 'тн вэд', 'тн вэд коды еаэс'], true)
      && !str_contains($descLower, 'любой удобный формат')
      && !str_contains($descLower, 'можно указать только целое число')
      && !str_contains($descLower, 'десятичную дробь')
      && (
        str_contains($descLower, 'спис')
        || str_contains($descLower, 'одно значение')
        || in_array($nk, ['цвет', 'цвет товара', 'название цвета', 'основной цвет', 'материал', 'материал изделия', 'основной материал', 'страна производства', 'страна изготовитель', 'страна производитель', 'пол', 'сезон'], true)
      );
    if ($shouldFetchValues) {
      $vals = ozon_attribute_values($oz, $descriptionCategoryId, $typeId, $id, 'RU', 500);

      if ($vals) {
        $allowedValues = array_slice($vals, 0, 500);
      } else {
        $allowedValues = [];
      }
    }

    $lines[] = $name;

    // храним в памяти (meta_json)
    // ключ — нормализованное имя, чтобы не было дублей
    $meta[$nk] = [
      'name' => $name,
      'description' => $desc,
      'id' => $id,
      'attribute_complex_id' => (int)($a['attribute_complex_id'] ?? ($a['complex_id'] ?? 0)),
      'required' => !empty($a['is_required']) || !empty($a['required']),
      'dictionary_id' => $dictionaryId,
      'allowed_values' => $allowedValues,
      'selection_mode' => $allowedValues ? 'choose_one' : ($dictionaryId > 0 ? 'dictionary' : 'free'),
      'value_source' => $allowedValues ? 'ozon_dictionary' : ($dictionaryId > 0 ? 'ozon_dictionary' : 'free_text'),
    ];
  }

  return ['lines' => $lines, 'meta' => $meta];
}

function wb_cfg(): array {
  $cfg = require __DIR__ . '/../../app/config.php';
  $wb = $cfg['wildberries'] ?? [];
  if (!is_array($wb)) $wb = [];
  return $wb;
}

function wb_norm_attr_name(string $s): string {
  $s = trim($s);
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace('ё', 'е', $s);
  $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return trim($s);
}

function wb_characteristics_data(WildberriesClient $wb, int $subjectId, array $excludeNames = []): array {
  $resp = $wb->getSubjectCharacteristics($subjectId);
  $items = $resp['data'] ?? [];
  if (!is_array($items)) $items = [];

  $exclude = [];
  foreach ($excludeNames as $x) {
    $x = trim((string)$x);
    if ($x === '') continue;
    $exclude[wb_norm_attr_name($x)] = true;
  }

  $lines = [];
  $meta = [];
  $seen = [];

  foreach ($items as $a) {
    if (!is_array($a)) continue;

    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') continue;

    $nk = wb_norm_attr_name($name);
    if ($nk !== '' && isset($exclude[$nk])) continue;
    if (isset($seen[$nk])) continue;
    $seen[$nk] = 1;

    $row = [
      'name' => $name,
      'id' => (int)($a['charcID'] ?? 0),
      'required' => !empty($a['required']),
      'unit' => trim((string)($a['unitName'] ?? '')),
      'max_count' => (int)($a['maxCount'] ?? 0),
      'popular' => !empty($a['popular']),
      'charc_type' => (int)($a['charcType'] ?? 0),
      'is_variable' => !empty($a['isVariable']),
      'subject_id' => (int)($a['subjectID'] ?? 0),
      'subject_name' => trim((string)($a['subjectName'] ?? '')),
    ];
    $row = wb_dict_enrich_characteristic_meta($wb, $row);

    $meta[$nk] = $row;
    $lines[] = $name;
  }

  return ['lines' => $lines, 'meta' => $meta];
}




function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function taxonomy_template_image_url(array $template): string {
  $relative = str_replace('\\', '/', trim((string)($template['relative_path'] ?? '')));
  $relative = ltrim($relative, '/');
  if ($relative !== '' && !str_contains($relative, '../') && !str_contains($relative, '..\\')) {
    return '../supplier_product_image.php?f=' . rawurlencode($relative);
  }
  $url = trim((string)($template['url'] ?? ''));
  if ($url !== '' && preg_match('~^https?://~i', $url)) {
    return $url;
  }
  return '';
}

function taxonomy_template_bytes_label(int $bytes): string {
  if ($bytes <= 0) return '';
  if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, '.', ' ') . ' MB';
  if ($bytes >= 1024) return number_format($bytes / 1024, 1, '.', ' ') . ' KB';
  return $bytes . ' B';
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$stmt = db()->prepare("SELECT * FROM feedtools_taxonomy_categories WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

$source = trim((string)($row['source'] ?? ''));
if (!in_array($source, ['ozon', 'wildberries'], true)) {
  http_response_code(400);
  exit('Unsupported taxonomy source');
}

$meta = [];
if (!empty($row['meta_json'])) {
  $meta = json_decode((string)$row['meta_json'], true);
  if (!is_array($meta)) $meta = [];
}
if ($source === 'wildberries') {
  if (empty($meta['raw']) || !is_array($meta['raw'])) {
    $meta['raw'] = [];
  }
  $legacyWbCategory = trim((string)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? '')));
  if ($legacyWbCategory !== '') {
    $meta['raw']['wb_category'] = $legacyWbCategory;
    if (!isset($meta['raw']['wb_subject_id'])) {
      $meta['raw']['wb_subject_id'] = $legacyWbCategory;
    }
  }
}

$meta += $source === 'wildberries'
  ? [
      'description' => '',
      'typical_goods' => '',
      'features' => '',
      'cover_design' => '',
      'video_cover_design' => '',
      'wb_required_attributes' => [],
      'wb_characteristics_meta' => [],
      'keywords' => [],
    ]
  : [
      'description' => '',
      'typical_goods' => '',
      'features' => '',
      'cover_design' => '',
      'video_cover_design' => '',
      'ozon_required_attributes' => [],
      'keywords' => [],
    ];

function lines_to_list(string $s): array {
  $items = array_values(array_filter(array_map('trim', preg_split('~\R~u', $s)), fn($x)=>$x!==''));
  $seen = [];
  $out = [];
  foreach ($items as $it) {
    $k = mb_strtolower($it);
    if (isset($seen[$k])) continue;
    $seen[$k]=1; $out[]=$it;
  }
  return $out;
}
function list_to_lines($a): string {
  return is_array($a) ? implode("\n", $a) : '';
}
function wb_meta_names_to_lines($meta): string {
  if (!is_array($meta)) return '';
  $names = [];
  foreach ($meta as $row) {
    if (!is_array($row)) continue;
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') $names[] = $name;
  }
  if (!$names) return '';
  natcasesort($names);
  return implode("\n", $names);
}
function filtered_fill_attribute_names(string $source, array $meta, array $excludeNames): array {
  $excludeMap = [];
  $norm = $source === 'wildberries' ? 'wb_norm_attr_name' : 'ozon_norm_attr_name';
  foreach ($excludeNames as $name) {
    $key = $norm((string)$name);
    if ($key !== '') $excludeMap[$key] = true;
  }

  $result = [];
  $seen = [];
  $push = static function (string $name) use (&$result, &$seen, $excludeMap, $norm): void {
    $name = trim($name);
    if ($name === '') return;
    $key = $norm($name);
    if ($key === '' || isset($excludeMap[$key]) || isset($seen[$key])) return;
    $seen[$key] = true;
    $result[] = $name;
  };

  if ($source === 'wildberries') {
    $rows = $meta['wb_characteristics_meta'] ?? [];
    if (is_array($rows) && $rows) {
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $push((string)($row['name'] ?? ''));
      }
      return $result;
    }
    foreach (($meta['wb_required_attributes'] ?? []) as $name) {
      $push((string)$name);
    }
    return $result;
  }

  $rows = $meta['ozon_required_attributes_meta'] ?? [];
  if (is_array($rows) && $rows) {
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $push((string)($row['name'] ?? ''));
    }
    return $result;
  }
  foreach (($meta['ozon_required_attributes'] ?? []) as $name) {
    $push((string)$name);
  }
  return $result;
}
$msg=''; $err='';
$globalExcludeNames = taxonomy_get_global_exclude_attribute_names($source);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $meta['description'] = (string)($_POST['description'] ?? '');
    $meta['typical_goods'] = (string)($_POST['typical_goods'] ?? '');
    $meta['features'] = (string)($_POST['features'] ?? '');
    $coverDesignInput = (string)($_POST['cover_design'] ?? '');
    $meta['cover_design'] = $coverDesignInput;
    $videoCoverDesignInput = (string)($_POST['video_cover_design'] ?? '');
    $meta['video_cover_design'] = $videoCoverDesignInput;
    $meta['keywords'] = lines_to_list((string)($_POST['kw'] ?? ''));
    if ($source === 'wildberries') {
      $meta['wb_required_attributes'] = filtered_fill_attribute_names('wildberries', [
        'wb_characteristics_meta' => $meta['wb_characteristics_meta'] ?? [],
        'wb_required_attributes' => lines_to_list((string)($_POST['req'] ?? '')),
      ], $globalExcludeNames);
    } else {
      $meta['ozon_required_attributes'] = filtered_fill_attribute_names('ozon', [
        'ozon_required_attributes_meta' => $meta['ozon_required_attributes_meta'] ?? [],
        'ozon_required_attributes' => lines_to_list((string)($_POST['req'] ?? '')),
      ], $globalExcludeNames);
    }

    if ($source === 'ozon' && !empty($_POST['fill_ozon_attrs'])) {
      $oz = ozon_cfg();
      $excludeNames = $globalExcludeNames;


      // Можно заранее сохранить правильную связку в meta_json:
      // ozon_description_category_id и ozon_type_id
      $descId = (int)($meta['ozon_description_category_id'] ?? 0);
      $typeId = (int)($meta['ozon_type_id'] ?? 0);

      $lines = [];

      // Если связка уже известна — используем её
      if ($descId > 0 && $typeId > 0) {
$tmp = ozon_required_attr_data($oz, $descId, $typeId, $excludeNames);
$lines = $tmp['lines'];
$attrsMeta = $tmp['meta'];



      } else {
        // Иначе пробуем угадать из ozon_leaf_id / ozon_parent_id
        $a = (int)($row['ozon_leaf_id'] ?? 0);
        $b = (int)($row['ozon_parent_id'] ?? 0);

        if ($a <= 0 || $b <= 0) {
          throw new RuntimeException('У категории не заполнены ozon_parent_id/ozon_leaf_id (нужно для запроса атрибутов)');
        }

        $attempts = [
          ['desc' => $a, 'type' => $b],
          ['desc' => $b, 'type' => $a],
        ];

        $errors = [];
        $attrsMeta = [];
        foreach ($attempts as $t) {
          try {
$tmp = ozon_required_attr_data($oz, (int)$t['desc'], (int)$t['type'], $excludeNames);
$tmpLines = $tmp['lines'];
$tmpMeta  = $tmp['meta'];

if (count($tmpLines) > 0) {
  $descId = (int)$t['desc'];
  $typeId = (int)$t['type'];
  $lines = $tmpLines;
  $attrsMeta = $tmpMeta;
  break;
}

if ($lines === []) {
  $descId = (int)$t['desc'];
  $typeId = (int)$t['type'];
  $lines = $tmpLines;
  $attrsMeta = $tmpMeta;
}

          } catch (Throwable $e) {
            $errors[] = $e->getMessage();
          }
        }

        if ($descId <= 0 || $typeId <= 0) {
          throw new RuntimeException('Не удалось определить description_category_id/type_id для Ozon');
        }
        if ($lines === [] && $errors) {
          throw new RuntimeException('Ozon API ошибка: ' . implode(' | ', $errors));
        }
      }

      // Сохраняем найденную связку (чтобы дальше не гадать)
      $meta['ozon_description_category_id'] = $descId;
      $meta['ozon_type_id'] = $typeId;

      // В поле кладём только "name: ..."
      $meta['ozon_required_attributes'] = $lines;
      $meta['ozon_required_attributes_meta'] = $attrsMeta;

      $msg = 'Заполнено из Ozon: ' . count($lines);
    }

    if ($source === 'wildberries' && !empty($_POST['fill_wb_attrs'])) {
      $wb = new WildberriesClient(wb_cfg());
      $excludeNames = $globalExcludeNames;

      $subjectId = (int)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? 0));
      if ($subjectId <= 0) {
        throw new RuntimeException('У категории не заполнен wb_category');
      }

      $tmp = wb_characteristics_data($wb, $subjectId, $excludeNames);
      $meta['wb_required_attributes'] = $tmp['lines'];
      $meta['wb_characteristics_meta'] = $tmp['meta'];
      $msg = 'Заполнено из Wildberries: ' . count($tmp['meta']);
    }


    // будущие поля — JSON merge
    $extra = trim((string)($_POST['extra_json'] ?? ''));
    if ($extra !== '') {
      $decoded = json_decode($extra, true);
      if (!is_array($decoded)) throw new RuntimeException('extra_json должен быть JSON-объектом');
      $meta = array_replace_recursive($meta, $decoded);
      $meta['cover_design'] = $coverDesignInput;
      $meta['video_cover_design'] = $videoCoverDesignInput;
    }

    db()->prepare("UPDATE feedtools_taxonomy_categories SET meta_json=? WHERE id=?")->execute([
      json_encode($meta, JSON_UNESCAPED_UNICODE),
      $id
    ]);

    if ($msg === '') $msg = 'Сохранено';
  } catch (Throwable $e) {
    $err=$e->getMessage();
  }
}

$displayFillAttributes = filtered_fill_attribute_names($source, $meta, $globalExcludeNames);
if ($source === 'wildberries') {
  $meta['wb_required_attributes'] = $displayFillAttributes;
} else {
  $meta['ozon_required_attributes'] = $displayFillAttributes;
}
$backgroundTemplate = is_array($meta['additional_photo_background_template'] ?? null)
  ? $meta['additional_photo_background_template']
  : [];
$backgroundTemplateUrl = $backgroundTemplate ? taxonomy_template_image_url($backgroundTemplate) : '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Edit taxonomy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1100px;margin:30px auto;padding:0 16px}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
    textarea{width:95%;min-height:110px;padding:10px;border:1px solid #e5e7eb;border-radius:10px;font-family:inherit}
    button{padding:10px 14px;border-radius:10px;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer}
    .muted{color:#6b7280}
    .ok{color:#166534}
    .err{color:#b91c1c}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
    a{color:#111827}
    .template-preview{display:grid;grid-template-columns:minmax(180px,280px) 1fr;gap:16px;align-items:start;border:1px solid #dbe7fb;background:#f8fbff;border-radius:16px;padding:14px;margin-top:12px}
    .template-preview img{display:block;width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:12px;border:1px solid #cfe0fb;background:#fff}
    .template-preview h3{margin:0 0 8px;font-size:18px}
    .template-preview dl{display:grid;grid-template-columns:150px 1fr;gap:6px 10px;margin:12px 0;color:#374151}
    .template-preview dt{color:#6b7280}
    .template-preview dd{margin:0;font-weight:650}
    .template-preview .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .template-preview .actions a{display:inline-flex;align-items:center;justify-content:center;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px;padding:8px 12px;text-decoration:none;font-weight:650;color:#1d4ed8}
    .template-empty{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:14px;padding:12px;margin-top:12px;color:#64748b}
    @media (max-width:700px){.template-preview{grid-template-columns:1fr}.template-preview dl{grid-template-columns:1fr}}
  </style>
</head>
<body>

<?= ft_top_navigation([
  'back_href' => 'index.php?source=' . rawurlencode((string)$source),
  'back_label' => 'Назад',
  'links' => [
    ['key' => 'home', 'label' => 'Главная', 'href' => '../index.php'],
    ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => '../suppliers.php'],
    ['key' => 'connections', 'label' => 'Подключения', 'href' => '../marketplace_connections.php'],
    ['key' => 'taxonomy', 'label' => 'Список категорий', 'href' => 'index.php?source=' . rawurlencode((string)$source)],
  ],
  'active' => 'taxonomy',
]) ?>
<h1 data-ft-i18n="off"><?=h($row['full_path'])?></h1>
<p class="muted">
  <?php if ($source === 'wildberries'): ?>
    wb_parent_id: <code><?=h($meta['raw']['wb_parent_id'] ?? '')?></code>,
    wb_category: <code><?=h($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? ''))?></code>
  <?php else: ?>
    ozon_parent_id: <code><?=h($row['ozon_parent_id'] ?? '')?></code>,
    ozon_leaf_id: <code><?=h($row['ozon_leaf_id'] ?? '')?></code>
  <?php endif; ?>
</p>

<div class="card">
  <?php if ($msg): ?><p class="ok"><?=h($msg)?></p><?php endif; ?>
  <?php if ($err): ?><p class="err"><?=h($err)?></p><?php endif; ?>

  <form method="post">
    <div class="grid">
      <div>
        <label class="muted">Описание категории</label>
        <textarea name="description"><?=h($meta['description'])?></textarea>
      </div>
      <div>
        <label class="muted">Типичные товары</label>
        <textarea name="typical_goods"><?=h($meta['typical_goods'])?></textarea>
      </div>
    </div>

    <div style="margin-top:12px;">
      <label class="muted">Особенности</label>
      <textarea name="features"><?=h($meta['features'])?></textarea>
    </div>

    <div style="margin-top:12px;">
      <label class="muted">Дизайн обложки</label>
      <textarea name="cover_design" placeholder="Стиль, цвета, композиция и оформление продающей обложки для товаров этой категории."><?=h($meta['cover_design'])?></textarea>
      <p class="muted" style="margin-top:6px;">Этот текст используется операцией генерации обложек как стилевое ТЗ для товаров категории.</p>
    </div>

    <div style="margin-top:12px;">
      <?php if ($backgroundTemplateUrl !== ''): ?>
        <div class="template-preview">
          <a href="<?=h($backgroundTemplateUrl)?>" target="_blank" rel="noopener">
            <img src="<?=h($backgroundTemplateUrl)?>" alt="Сгенерированная обложка категории">
          </a>
          <div>
            <h3>Сгенерированная обложка категории</h3>
            <p class="muted" style="margin:0;">Фон для дополнительных фото: на него операция без GPT пишет характеристики товара.</p>
            <dl>
              <?php if ((int)($backgroundTemplate['width'] ?? 0) > 0 && (int)($backgroundTemplate['height'] ?? 0) > 0): ?>
                <dt>Размер</dt>
                <dd><?=h((int)$backgroundTemplate['width'] . ' × ' . (int)$backgroundTemplate['height'])?></dd>
              <?php endif; ?>
              <?php if (trim((string)($backgroundTemplate['image_model'] ?? '')) !== ''): ?>
                <dt>Модель</dt>
                <dd><?=h($backgroundTemplate['image_model'])?></dd>
              <?php endif; ?>
              <?php if (trim((string)($backgroundTemplate['image_quality'] ?? '')) !== ''): ?>
                <dt>Качество</dt>
                <dd><?=h($backgroundTemplate['image_quality'])?></dd>
              <?php endif; ?>
              <?php if ((int)($backgroundTemplate['prompt_version'] ?? 0) > 0): ?>
                <dt>Версия шаблона</dt>
                <dd><?=h((int)$backgroundTemplate['prompt_version'])?></dd>
              <?php endif; ?>
              <?php if (trim((string)($backgroundTemplate['updated_at'] ?? '')) !== ''): ?>
                <dt>Обновлено</dt>
                <dd><?=h($backgroundTemplate['updated_at'])?></dd>
              <?php endif; ?>
              <?php $bytesLabel = taxonomy_template_bytes_label((int)($backgroundTemplate['bytes'] ?? 0)); ?>
              <?php if ($bytesLabel !== ''): ?>
                <dt>Файл</dt>
                <dd><?=h($bytesLabel)?></dd>
              <?php endif; ?>
            </dl>
            <div class="actions">
              <a href="<?=h($backgroundTemplateUrl)?>" target="_blank" rel="noopener">Открыть изображение</a>
              <?php if (trim((string)($backgroundTemplate['relative_path'] ?? '')) !== ''): ?>
                <a href="<?=h($backgroundTemplateUrl)?>" download>Скачать</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="template-empty">Сгенерированная обложка для дополнительных фото пока не создана. Она появится здесь после операции анализа категории.</div>
      <?php endif; ?>
    </div>

    <div style="margin-top:12px;">
      <label class="muted">Дизайн видео-обложки</label>
      <textarea name="video_cover_design" placeholder="Короткое ТЗ для видео: стартовый кадр, движение камеры, динамика, переходы, свет, финальный кадр."><?=h($meta['video_cover_design'])?></textarea>
      <p class="muted" style="margin-top:6px;">Этот текст используется операцией GPT-видеообложек как ТЗ для Sora.</p>
    </div>

    <div class="grid" style="margin-top:12px;">
      <div>
        <label class="muted"><?= $source === 'wildberries' ? 'Характеристики WB для заполнения (по строкам)' : 'Характеристики для заполнения (по строкам)' ?></label>
        <textarea name="req"><?=h(list_to_lines($source === 'wildberries' ? ($meta['wb_required_attributes'] ?? []) : ($meta['ozon_required_attributes'] ?? [])))?></textarea>
        <?php if ($source === 'wildberries'): ?>
          <p class="muted" style="margin-top:6px;">Список выше показывает рабочий набор для GPT: все характеристики категории, кроме тех, что входят в общий список исключений.</p>
        <?php endif; ?>
      </div>
      <div>
        <label class="muted">Ключевые слова (по строкам)</label>
        <textarea name="kw"><?=h(list_to_lines($meta['keywords']))?></textarea>
      </div>
    </div>
    <?php if ($source === 'wildberries'): ?>
      <div style="margin-top:12px;">
        <label class="muted">Все характеристики WB (из meta_json)</label>
        <textarea readonly><?=h(wb_meta_names_to_lines($meta['wb_characteristics_meta'] ?? []))?></textarea>
      </div>
    <?php endif; ?>
    <p class="muted" style="margin-top:12px;">Общий список исключаемых характеристик теперь задаётся на странице <a href="index.php?source=<?= h($source) ?>">Категории <?= h($source === 'wildberries' ? 'WB' : 'Ozon') ?></a> и применяется сразу ко всем категориям этого источника.</p>
    <?php if ($source === 'wildberries'): ?>
      <button type="submit" name="fill_wb_attrs" value="1" class="btn-secondary" style="margin-left:8px;">
        Заполнить характеристики из API Wildberries
      </button>
    <?php else: ?>
      <button type="submit" name="fill_ozon_attrs" value="1" class="btn-secondary" style="margin-left:8px;">
        Заполнить характеристики из API Ozon
      </button>
    <?php endif; ?>

    <div style="margin-top:12px;">
      <label class="muted">Доп.поля (JSON, будет слито в meta_json)</label>
      <textarea name="extra_json" placeholder='{"future_field":"value","nested":{"k":1}}'></textarea>
      <p class="muted">Это на будущее, чтобы добавлять новые поля без правок БД.</p>
    </div>

    <div style="margin-top:12px;">
      <button type="submit">Сохранить</button>
    </div>
  </form>
</div>

</body>
</html>
