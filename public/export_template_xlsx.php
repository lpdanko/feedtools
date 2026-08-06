<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/text_sanitize.php';
require_once __DIR__ . '/../app/supplier_products.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
  http_response_code(500);
  exit("Missing vendor/autoload.php. Run: composer install");
}
require_once $autoload;
require_once __DIR__ . '/../app/SpreadsheetSqliteCache.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Settings;

@set_time_limit(0);
ignore_user_abort(true);

/** col/row -> A1 */
function cellAddr(int $col, int $row): string
{
  return Coordinate::stringFromColumnIndex($col) . $row;
}

function ft_lc(string $s): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function norm_header(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace('*', '', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return ft_lc(trim($s));
}

function strip_html_to_text(string $html): string
{
  $html = (string)$html;
  if ($html === '') return '';

  // Если по ошибке в текст попали маркеры CDATA, убираем их
  // (часто появляется при readInnerXml на <description><![CDATA[...]]></description>)
  if (strpos($html, '<![CDATA[') !== false) {
    $html = preg_replace('~<!\[CDATA\[(.*?)\]\]>~s', '$1', $html);
  }
  // На всякий случай убираем хвостовые маркеры
  $html = str_replace([']]>', '<![CDATA['], '', $html);

  // выбрасываем script
  $html = preg_replace('~<\s*script\b[^>]*>.*?<\s*/\s*script\s*>~is', '', $html);

  // нормализуем разрешённые теги в переносы/маркеры
  $s = $html;

  // <br> -> \n
  $s = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $s);

  // </p> -> двойной перенос (абзац)
  $s = preg_replace('~<\s*/\s*p\s*>~i', "\n\n", $s);
  // <p> просто убираем
  $s = preg_replace('~<\s*p\s*>~i', '', $s);

  // списки
  $s = preg_replace('~<\s*/\s*li\s*>~i', "\n", $s);
  $s = preg_replace('~<\s*li\s*>~i', "• ", $s);
  $s = preg_replace('~<\s*/\s*ul\s*>~i', "\n", $s);
  $s = preg_replace('~<\s*ul\s*>~i', "\n", $s);

  // <b> оставляем как текст, теги уберём strip_tags ниже

  // убрать остальные теги, декодировать сущности
  $txt = strip_tags($s);
  $txt = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');

  // привести строки в порядок:
  // - убрать пробелы в начале/конце строк
  // - схлопнуть лишние пустые строки
  $lines = preg_split("/\R/u", $txt);
  $lines = array_map(fn($l) => rtrim($l), $lines);

  $out = [];
  $emptyRun = 0;
  foreach ($lines as $l) {
    $l = preg_replace('/[ \t]+/u', ' ', $l);
    $l = trim($l);

    if ($l === '') {
      $emptyRun++;
      if ($emptyRun <= 2) $out[] = '';
      continue;
    }
    $emptyRun = 0;
    $out[] = $l;
  }

  // убрать пустые строки в начале/конце
  while ($out && $out[0] === '') array_shift($out);
  while ($out && end($out) === '') array_pop($out);

  return implode("\n", $out);
}

function ozon_annotation_html(string $html): string
{
  $html = ft_sanitize_markupish_text((string)$html);
  if ($html === '') return '';

  if (strpos($html, '<![CDATA[') !== false) {
    $html = preg_replace('~<!\[CDATA\[(.*?)\]\]>~s', '$1', $html);
  }
  $html = str_replace([']]>', '<![CDATA['], '', $html);

  $html = preg_replace('~<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);

  // Ozon allows a very small subset in annotation. Normalize it and strip everything else.
  $s = $html;
  $s = preg_replace('~<\s*br\b[^>]*>~i', '<br>', (string)$s);
  $s = preg_replace('~<\s*/\s*p\s*>~i', '<br><br>', (string)$s);
  $s = preg_replace('~<\s*p\b[^>]*>~i', '', (string)$s);
  $s = preg_replace('~<\s*ul\b[^>]*>~i', '<ul>', (string)$s);
  $s = preg_replace('~<\s*/\s*ul\s*>~i', '</ul>', (string)$s);
  $s = preg_replace('~<\s*li\b[^>]*>~i', '<li>', (string)$s);
  $s = preg_replace('~<\s*/\s*li\s*>~i', '</li>', (string)$s);
  $s = strip_tags((string)$s, '<br><ul><li>');
  $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

  $s = preg_replace('/[ \t]+/u', ' ', (string)$s);
  $s = preg_replace('/\s*(<br>)\s*/i', '<br>', (string)$s);
  $s = preg_replace('/\s*(<ul>)\s*/i', '<ul>', (string)$s);
  $s = preg_replace('/\s*(<\/ul>)\s*/i', '</ul>', (string)$s);
  $s = preg_replace('/\s*(<li>)\s*/i', '<li>', (string)$s);
  $s = preg_replace('/\s*(<\/li>)\s*/i', '</li>', (string)$s);
  $s = preg_replace('~(?:<br>){3,}~i', '<br><br>', (string)$s);
  $s = preg_replace('~<ul>(?:<br>)+~i', '<ul>', (string)$s);
  $s = preg_replace('~(?:<br>)+</ul>~i', '</ul>', (string)$s);

  return trim((string)$s);
}


function safe_realpath_under(?string $baseDir, string $path): string
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

/** dimensions in feed: "L/W/H" (unit may be missing). Return [L,W,H] raw strings */
function parse_dimensions(string $dim): array
{
  $dim = trim((string)$dim);
  if ($dim === '') return ['', '', ''];
  $parts = array_map('trim', explode('/', $dim));
  $L = $parts[0] ?? '';
  $W = $parts[1] ?? '';
  $H = $parts[2] ?? '';
  return [$L, $W, $H];
}

/** Detect dimension unit from raw string. Returns 'mm', 'cm', or '' */
function detect_dim_unit(string $raw): string
{
  $lc = ft_lc(trim((string)$raw));
  if ($lc === '') return '';
  if (strpos($lc, 'мм') !== false || preg_match('/\bmm\b/u', $lc)) return 'mm';
  if (strpos($lc, 'см') !== false || preg_match('/\bcm\b/u', $lc)) return 'cm';
  return '';
}

function mm_to_cm_str(string $mm): string
{
  $mm = trim((string)$mm);
  if ($mm === '' || !is_numeric($mm)) return '';
  $cm = ((float)$mm) / 10.0;
  $s = number_format($cm, 1, '.', '');
  return preg_replace('/\.0$/', '', $s);
}

/** cm -> mm (x10). Keep empty if non-numeric. */
function dim_auto_to_mm(string $v, bool $asCm): string
{
  $v = trim((string)$v);
  if ($v === '') return '';
  $v = str_replace(',', '.', $v);
  if (!is_numeric($v)) return '';
  $n = (float)$v;
  $mm = $asCm ? ($n * 10.0) : $n;
  return (string)((int)round($mm));
}

function marketplace_price_to_rub_string($value): string
{
  $num = supplier_products_parse_float((string)$value);
  if ($num === null || $num <= 0) {
    return '';
  }
  return (string)max(1, (int)ceil($num - 0.00001));
}

function to_grams(string $w): string
{
  $w = trim((string)$w);
  if ($w === '') return '';
  $w = str_replace(',', '.', $w);
  if (!is_numeric($w)) return '';
  $g = (int)round(((float)$w) * 1000.0);
  return (string)$g;
}

function norm_spaces_to_one(string $s): string
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/[\r\n\t]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function hashtags_to_spaces(string $s): string
{
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
    $key = mb_strtolower($part, 'UTF-8');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $part;
  }
  return implode(' ', $out);
}

function sheet_copy_row_template(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $srcRow, int $dstRow, int $maxCol): void
{
  if ($dstRow === $srcRow) return;
  $sheet->getRowDimension($dstRow)->setRowHeight($sheet->getRowDimension($srcRow)->getRowHeight());
  for ($c = 1; $c <= $maxCol; $c++) {
    $src = cellAddr($c, $srcRow);
    $dst = cellAddr($c, $dstRow);
    $sheet->duplicateStyle($sheet->getStyle($src), $dst);
    $dv = $sheet->getCell($src)->getDataValidation();
    if ($dv && $dv->getType() !== \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_NONE) {
      $sheet->setDataValidation($dst, clone $dv);
    }
  }
}

function sheet_clear_row_values(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $maxCol): void
{
  for ($c = 1; $c <= $maxCol; $c++) {
    $sheet->getCell(cellAddr($c, $row))->setValue(null);
  }
}

function ozon_extract_video_links(array $params): array
{
  $out = [];
  $seen = [];

  foreach ($params as $name => $values) {
    if (norm_header((string)$name) !== norm_header('видео')) continue;
    foreach ((array)$values as $raw) {
      $parts = preg_split('/[\s,;\r\n]+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
      foreach ($parts as $part) {
        $url = trim((string)$part);
        if ($url === '') continue;
        $key = ft_lc($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $url;
      }
    }
  }

  return $out;
}

function ozon_video_cover_field_names(): array
{
  return [
    'Озон.Видеообложка: ссылка',
    'Ozon.VideoCover: link',
    'Видеообложка',
    'Видео-обложка',
  ];
}

function ozon_extract_video_cover_links(array $params): array
{
  $wanted = array_fill_keys(array_map('norm_header', ozon_video_cover_field_names()), true);
  $out = [];
  $seen = [];
  foreach ($params as $name => $values) {
    if (!isset($wanted[norm_header((string)$name)])) continue;
    foreach ((array)$values as $raw) {
      $parts = preg_split('/[\s,;\r\n]+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
      foreach ($parts as $part) {
        $url = trim((string)$part);
        if ($url === '') continue;
        $key = ft_lc($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $url;
      }
    }
  }
  return $out;
}

function ozon_raw_url_param_names(): array
{
  static $out = null;
  if ($out !== null) return $out;
  $out = array_fill_keys(array_map('norm_header', [
    'видео',
    'Озон.Видео: название',
    'Озон.Видео: ссылка',
    'Озон.Видео: товары на видео',
    'Озон.Видеообложка: ссылка',
  ]), true);
  return $out;
}

function ozon_find_video_sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
  foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($row = 1; $row <= min(4, (int)$sheet->getHighestRow()); $row++) {
      $hasName = false;
      $hasLink = false;
      for ($col = 1; $col <= $highestColIndex; $col++) {
        $raw = trim((string)($sheet->getCell(cellAddr($col, $row))->getValue() ?? ''));
        $hn = norm_header($raw);
        if ($hn === norm_header('Озон.Видео: название')) $hasName = true;
        if ($hn === norm_header('Озон.Видео: ссылка')) $hasLink = true;
      }
      if ($hasName && $hasLink) {
        return $sheet;
      }
    }
  }

  return null;
}

function ozon_find_video_cover_sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $mainSheet = null): ?array
{
  $matches = [];
  foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
    if ($mainSheet !== null && $sheet === $mainSheet) {
      continue;
    }
    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($row = 1; $row <= min(5, (int)$sheet->getHighestRow()); $row++) {
      $hasCoverLink = false;
      $hasArticle = false;
      for ($col = 1; $col <= $highestColIndex; $col++) {
        $hn = norm_header(trim((string)($sheet->getCell(cellAddr($col, $row))->getValue() ?? '')));
        if ($hn === norm_header('Озон.Видеообложка: ссылка') || $hn === norm_header('Видеообложка')) $hasCoverLink = true;
        if ($hn === norm_header('Артикул') || $hn === norm_header('Партномер')) $hasArticle = true;
      }
      if ($hasCoverLink) {
        $title = norm_header($sheet->getTitle());
        $score = (str_contains($title, norm_header('видеообложка')) || str_contains($title, 'videocover')) ? 2 : 1;
        if ($hasArticle) $score++;
        $matches[] = ['sheet' => $sheet, 'header_row' => $row, 'data_start_row' => $row + 2, 'score' => $score];
        break;
      }
    }
  }
  if (!$matches) return null;
  usort($matches, static fn(array $a, array $b): int => ((int)$b['score']) <=> ((int)$a['score']));
  return $matches[0];
}

function ozon_limit_text(string $text, int $maxLen): string
{
  $text = trim((string)$text);
  if ($text === '' || $maxLen <= 0) return '';

  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($text, 'UTF-8') <= $maxLen) return $text;
    $cut = mb_substr($text, 0, $maxLen, 'UTF-8');
    return rtrim($cut, " \t\n\r\0\x0B.,;:-");
  }

  if (strlen($text) <= $maxLen) return $text;
  return rtrim(substr($text, 0, $maxLen), " \t\n\r\0\x0B.,;:-");
}

function ozon_rich_text_chunks(string $descriptionHtml, int $maxChunks = 200): array
{
  $plain = strip_html_to_text($descriptionHtml);
  if ($plain === '') return [];

  $plain = preg_replace('/\R{3,}/u', "\n\n", (string)$plain);
  $blocks = preg_split('/\n\s*\n/u', (string)$plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $out = [];

  foreach ($blocks as $block) {
    $block = trim((string)$block);
    if ($block === '') continue;

    $lines = preg_split('/\R/u', $block, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($lines as $line) {
      $line = norm_spaces_to_one((string)$line);
      if ($line === '') continue;

      if ((function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line)) > 320) {
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line];
        foreach ($sentences as $sentence) {
          $sentence = ozon_limit_text(norm_spaces_to_one((string)$sentence), 320);
          if ($sentence !== '') $out[] = $sentence;
          if (count($out) >= $maxChunks) break 2;
        }
      } else {
        $out[] = $line;
      }

      if (count($out) >= $maxChunks) break 2;
    }
  }

  $uniq = [];
  $seen = [];
  foreach ($out as $item) {
    $key = ft_lc((string)$item);
    if ($item === '' || isset($seen[$key])) continue;
    $seen[$key] = true;
    $uniq[] = $item;
  }

  return array_slice($uniq, 0, $maxChunks);
}

function ozon_rich_formatted_text_node(array $content, string $size = 'size2', string $align = 'left', string $color = 'color1'): ?array
{
  $items = [];
  foreach ($content as $item) {
    $item = ozon_limit_text(norm_spaces_to_one((string)$item), 320);
    if ($item !== '') $items[] = $item;
  }
  if (!$items) return null;

  return [
    'content' => array_values($items),
    'size' => $size,
    'align' => $align,
    'color' => $color,
  ];
}

function ozon_rich_plain_text_node(array $content, string $theme = 'default'): ?array
{
  $items = [];
  foreach ($content as $item) {
    $item = ozon_limit_text(norm_spaces_to_one((string)$item), 320);
    if ($item !== '') $items[] = $item;
  }
  if (!$items) return null;

  return [
    'content' => array_values($items),
    'theme' => $theme,
  ];
}

function ozon_rich_param_exclusions(): array
{
  static $out = null;
  if ($out !== null) return $out;

  $names = [
    'видео',
    'Озон.Видео: название',
    'Озон.Видео: ссылка',
    'Озон.Видео: товары на видео',
    'Озон.Видеообложка: ссылка',
    'rich-контент json',
    'rich content json',
    '#хештеги',
    'хештеги',
    'barcode',
    'штрихкод',
    'артикул',
    'артикул ozon',
    'sku',
    'same_model',
    'название модели',
    'объединить в похожие товары',
    'файл',
    'файлы',
    'file',
    'files',
    'документ',
    'документы',
    'document',
    'documents',
    'ссылка',
    'ссылки',
    'link',
    'links',
  ];
  $out = array_fill_keys(array_map('norm_header', $names), true);
  return $out;
}

function ozon_rich_feature_lines(array $params, int $maxItems = 8): array
{
  $exclude = ozon_rich_param_exclusions();
  $out = [];
  $seen = [];

  foreach ($params as $name => $values) {
    $name = ft_sanitize_plain_text((string)$name);
    $nameNorm = norm_header($name);
    if ($name === '' || isset($exclude[$nameNorm])) continue;

    $vals = [];
    foreach ((array)$values as $value) {
      $value = ozon_limit_text(norm_spaces_to_one(ft_sanitize_plain_text((string)$value)), 120);
      if ($value !== '') $vals[] = $value;
    }
    if (!$vals) continue;

    $vals = array_values(array_unique($vals));
    $joined = implode(', ', array_slice($vals, 0, 3));
    $joined = ozon_limit_text($joined, 150);
    if ($joined === '') continue;

    $line = ozon_limit_text($name . ': ' . $joined, 220);
    $key = ft_lc($line);
    if ($line === '' || isset($seen[$key])) continue;

    $seen[$key] = true;
    $out[] = '• ' . $line;
    if (count($out) >= $maxItems) break;
  }

  return $out;
}

function ozon_rich_image_node(string $url, string $alt = ''): ?array
{
  $url = trim((string)$url);
  if ($url === '') return null;

  $out = [
    'src' => $url,
    'srcMobile' => $url,
  ];
  $alt = ozon_limit_text(norm_spaces_to_one(ft_sanitize_plain_text($alt)), 120);
  if ($alt !== '') {
    $out['alt'] = $alt;
  }
  return $out;
}

function ozon_build_rich_content_json(string $title, string $descriptionHtml, array $pictures, array $params = []): string
{
  $title = ozon_limit_text(norm_spaces_to_one(ft_sanitize_plain_text($title)), 160);
  $descriptionHtml = ft_sanitize_markupish_text($descriptionHtml);
  $pictures = array_values(array_filter(array_map(fn($x) => trim((string)$x), $pictures), fn($x) => $x !== ''));
  $pictures = array_slice($pictures, 0, 8);
  $chunks = ozon_rich_text_chunks($descriptionHtml, 200);
  $features = ozon_rich_feature_lines($params, 8);

  if (!$pictures && !$chunks && !$features) {
    return '';
  }

  $content = [];
  $leadText = $chunks[0] ?? '';
  $restText = array_slice($chunks, $leadText !== '' ? 1 : 0);
  if (!$leadText && $features) {
    $leadText = 'Ключевые характеристики и важные детали товара собраны ниже.';
  }

  if (count($pictures) >= 2) {
    $blocks = [];
    $gallery = array_slice($pictures, 0, min(4, count($pictures)));
    foreach ($gallery as $index => $picture) {
      $imgNode = ozon_rich_image_node($picture, $title);
      if (!$imgNode) continue;
      $block = [
        'img' => $imgNode,
        'reverse' => ($index % 2) === 1,
      ];

      if ($index === 0 && $title !== '') {
        $block['title'] = $title;
      }

      if ($index === 0 && $leadText !== '') {
        $block['text'] = [
          'content' => [$leadText],
          'theme' => 'default',
        ];
      } elseif ($index === 1 && !empty($restText)) {
        $block['text'] = [
          'content' => array_slice($restText, 0, 2),
          'theme' => 'default',
        ];
      }

      $blocks[] = $block;
    }

    if (count($blocks) >= 2) {
      $content[] = [
        'widgetName' => 'raShowcase',
        'type' => 'chess',
        'blocks' => $blocks,
      ];
    }
  } elseif ($pictures) {
    $firstImgNode = ozon_rich_image_node($pictures[0], $title);
    if ($firstImgNode) {
      $block = ['img' => $firstImgNode];
      if ($title !== '') {
        $block['title'] = $title;
      }
      if ($leadText !== '') {
        $block['text'] = [
          'content' => [$leadText],
          'theme' => 'default',
        ];
      }
      $content[] = [
        'widgetName' => 'raShowcase',
        'type' => 'billboard',
        'blocks' => [$block],
      ];
    }
  }

  if ($restText || (!$pictures && $chunks)) {
    $textContent = !$pictures ? $chunks : $restText;
    if ($textContent) {
      $textGroups = array_chunk($textContent, 6);
      foreach ($textGroups as $groupIndex => $groupLines) {
        $textBlock = [
          'widgetName' => 'raTextBlock',
          'gapSize' => 'm',
        ];
        $bodyTextNode = ozon_rich_plain_text_node($groupLines, 'default');
        if ($bodyTextNode) {
          $textBlock['text'] = $bodyTextNode;
        }
        if ($title !== '' && $groupIndex === 0) {
          $textTitleNode = ozon_rich_plain_text_node([$title], 'title');
          if ($textTitleNode) {
            $textBlock['title'] = $textTitleNode;
          }
        }
        if (isset($textBlock['text']) || isset($textBlock['title'])) {
          $content[] = $textBlock;
        }
      }
    }
  }

  if ($features) {
    $featuresBlock = [
      'widgetName' => 'raTextBlock',
      'gapSize' => 'm',
      'title' => [
        'content' => ['Характеристики'],
        'theme' => 'title',
      ],
      'text' => [
        'content' => $features,
        'theme' => 'default',
      ],
    ];
    $content[] = $featuresBlock;
  }

  if (!$content) {
    return '';
  }

  $json = json_encode([
    'content' => $content,
    'version' => 0.2,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (!is_string($json) || $json === '') {
    return '';
  }

  return ft_sanitize_ozon_rich_json(ozon_limit_text($json, 30000));
}

/**
 * Caller must be positioned on <offer>. This consumes until </offer>.
 */
function parse_offer_for_template(XMLReader $r): array
{
  $offerId = trim((string)$r->getAttribute('id'));

  $tags = [];       // tag => first string
  $pictures = [];   // list
  $params = [];     // pname => list values

  $depth = $r->depth;

  while ($r->read()) {
    if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'offer' && $r->depth === $depth) {
      break;
    }
    if ($r->nodeType !== XMLReader::ELEMENT) continue;

    $tag = $r->name;

    if ($tag === 'picture') {
      $v = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($v !== '') $pictures[] = $v;
      continue;
    }

    if ($tag === 'param') {
      $pname = trim((string)$r->getAttribute('name'));
      $pval  = trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($pname !== '') {
        if (!isset($params[$pname])) $params[$pname] = [];
        if ($pval !== '') $params[$pname][] = $pval;
      }
      continue;
    }

    if ($tag === 'description') {
      // readInnerXml может вернуть CDATA-маркеры как текст. Нормализуем.
      $html = (string)$r->readInnerXml();
      $html = html_entity_decode($html, ENT_QUOTES | ENT_XML1, 'UTF-8');
      $tags['description'] = ozon_annotation_html($html);
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
  ];
}

function supplier_products_template_offer_chunk_from_db(array $products, string $paramKind = 'param', array $cfg = []): array
{
  $productIds = array_map('intval', array_keys($products));
  if (!$productIds) return [];

  $fieldsByProduct = [];
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $fieldsStmt = db()->prepare("
    SELECT product_id, field_kind, field_name, field_value
    FROM feedtools_supplier_product_fields
    WHERE product_id IN ({$placeholders})
    ORDER BY product_id ASC, sort_order ASC, id ASC
  ");
  $fieldsStmt->execute($productIds);
  while ($field = $fieldsStmt->fetch(PDO::FETCH_ASSOC)) {
    $productId = (int)($field['product_id'] ?? 0);
    if ($productId > 0) $fieldsByProduct[$productId][] = $field;
  }
  $fieldsStmt->closeCursor();

  $out = [];
  foreach ($products as $productId => $product) {
    $tags = [
      'name' => (string)($product['name'] ?? ''),
      'vendorCode' => (string)($product['vendor_code'] ?? ''),
      'categoryId' => (string)($product['category_id'] ?? ''),
      'ozon_category' => (string)($product['ozon_category'] ?? ''),
      'wb_category' => (string)($product['wb_category'] ?? ''),
      'brand' => (string)($product['brand'] ?? ''),
      'description' => ozon_annotation_html((string)($product['description_html'] ?? '')),
      'price_original' => (string)($product['price_original'] ?? ''),
      'stock' => (string)($product['stock_qty'] ?? ''),
    ];
    $params = [];
	    foreach ((array)($fieldsByProduct[$productId] ?? []) as $field) {
	      $kind = (string)($field['field_kind'] ?? '');
	      $name = trim((string)($field['field_name'] ?? ''));
	      $value = trim((string)($field['field_value'] ?? ''));
	      if ($name === '' || $value === '') continue;
      if ($kind === 'tag' || $kind === 'attr') {
        if (!isset($tags[$name])) $tags[$name] = $value;
      } elseif ($kind === 'standard') {
        if ($name === 'purchase_price') $tags['price_original'] = $value;
        if ($name === 'brand') $tags['brand'] = $value;
        if ($name === 'tnved_code') {
          if (!isset($params['ТН ВЭД коды ЕАЭС'])) $params['ТН ВЭД коды ЕАЭС'] = [];
          if (!isset($params['Код ТН ВЭД'])) $params['Код ТН ВЭД'] = [];
          array_unshift($params['ТН ВЭД коды ЕАЭС'], $value);
          array_unshift($params['Код ТН ВЭД'], $value);
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
	      } elseif ($kind === $paramKind || ($paramKind === 'param' && $kind === 'param')) {
	        $params[$name][] = $value;
	      }
	    }
	    $tags['stock'] = (string)supplier_products_apply_stock_modifier(
	      (int)($tags['stock'] ?? 0),
	      (int)($product['marketplace_enabled'] ?? 1),
	      (int)($product['stock_modifier'] ?? 0)
	    );
		    $priceNum = supplier_products_parse_float((string)($tags['price_original'] ?? ''));
		    if ($priceNum !== null && $priceNum > 0) {
		      $tags['price_original'] = (string)max(1, (int)round($priceNum));
		    }
	    $out[] = [
      'product_id' => $productId,
      'offer_id' => (string)($product['offer_id'] ?? ''),
      'tags' => $tags,
      'pictures' => supplier_products_public_picture_urls(
        supplier_products_decode_json_array($product['pictures_json'] ?? null),
        $cfg
      ),
      'params' => $params,
    ];
  }
  return $out;
}

function supplier_products_template_offers_from_db(
  int $supplierId,
  ?array $selected = null,
  string $paramKind = 'param',
  array $cfg = [],
  int $chunkSize = 250
): Generator {
  $productsStmt = db()->prepare("
    SELECT *
    FROM feedtools_supplier_products
    WHERE supplier_id = ?
    ORDER BY sort_order ASC, id ASC
  ");
  $productsStmt->execute([$supplierId]);
  $chunkSize = max(25, min(1000, $chunkSize));
  $products = [];

  try {
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

      if (count($products) >= $chunkSize) {
        foreach (supplier_products_template_offer_chunk_from_db($products, $paramKind, $cfg) as $offer) {
          yield $offer;
        }
        $products = [];
      }
    }

    if ($products) {
      foreach (supplier_products_template_offer_chunk_from_db($products, $paramKind, $cfg) as $offer) {
        yield $offer;
      }
    }
  } finally {
    $productsStmt->closeCursor();
  }
}

// ---------------- INPUT ----------------

$cliTemplatePath = PHP_SAPI === 'cli' ? trim((string)getenv('FEEDTOOLS_EXPORT_TEMPLATE_FILE')) : '';
if (PHP_SAPI === 'cli') {
  $cliDatasetId = (int)getenv('FEEDTOOLS_EXPORT_DATASET_ID');
  if ($cliDatasetId > 0) $_POST['dataset_id'] = $cliDatasetId;
  $cliOfferIdsJson = trim((string)getenv('FEEDTOOLS_EXPORT_OFFER_IDS_JSON'));
  if ($cliOfferIdsJson !== '') $_POST['offer_ids_json'] = $cliOfferIdsJson;
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
$selected = $offerIds ? array_fill_keys($offerIds, true) : null; // null => весь датасет

$templateOriginalName = '';
if ($cliTemplatePath !== '') {
  if (!is_file($cliTemplatePath) || !is_readable($cliTemplatePath)) {
    http_response_code(400);
    exit('CLI template file is not readable');
  }
  $templateOriginalName = basename($cliTemplatePath);
} else {
  if (empty($_FILES['template_file']) || ($_FILES['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Template file is required (.xlsx)');
  }
  $templateOriginalName = (string)($_FILES['template_file']['name'] ?? '');
}
$ext = strtolower(pathinfo($templateOriginalName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
  http_response_code(400);
  exit('Template must be .xlsx');
}

// dataset row
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
  $supplierDbOffers = supplier_products_template_offers_from_db($supplierId, $selected, 'param', $cfg);
} else {
  try {
    $xmlPath = safe_realpath_under($cfg['paths']['uploads_dir'] ?? null, (string)$ds['stored_path']);
  } catch (Throwable $e) {
    http_response_code(404);
    exit('Dataset XML not found');
  }
}

// tmp paths
$outDir = $cfg['paths']['outputs_dir'];
if (!is_dir($outDir)) @mkdir($outDir, 0777, true);

$tmpTpl = $outDir . '/tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
$tmpOut = $outDir . '/out_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
$cellCachePath = $outDir . '/xlsx_cache_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sqlite';
$spreadsheet = null;
$cellCache = null;

$templateSaved = $cliTemplatePath !== ''
  ? @copy($cliTemplatePath, $tmpTpl)
  : move_uploaded_file((string)$_FILES['template_file']['tmp_name'], $tmpTpl);
if (!$templateSaved) {
  http_response_code(500);
  exit('Failed to save uploaded template');
}

register_shutdown_function(static function () use (&$spreadsheet, &$cellCache, $tmpTpl, $tmpOut, $cellCachePath): void {
  Settings::setCache(null);
  if ($cellCache instanceof FeedtoolsSpreadsheetSqliteCache) {
    $cellCache->close();
    $cellCache = null;
  }
  $spreadsheet = null;
  @unlink($tmpTpl);
  @unlink($tmpOut);
  @unlink($cellCachePath);
  @unlink($cellCachePath . '-journal');
  @unlink($cellCachePath . '-wal');
  @unlink($cellCachePath . '-shm');
});

try {
  $cellCache = new FeedtoolsSpreadsheetSqliteCache($cellCachePath);
  Settings::setCache($cellCache);
} catch (Throwable $e) {
  http_response_code(500);
  exit('Cannot initialize disk cache for large Excel export: ' . $e->getMessage());
}

// ---------------- RULES ----------------

// не заполнять
$SKIP = array_fill_keys(array_map('norm_header', [

  'Цена до скидки, руб.',
  'Баллы за отзывы',
  'SKU',
  'Ссылки на фото 360',
  'Артикул фото',
  'Минимальное количество оптом',
  'Ошибка',
  'Предупреждение',
]), true);

// константы
$CONST = [
  norm_header('НДС, %*') => 'Не облагается',
  norm_header('НДС, %') => 'Не облагается',
  norm_header('Рассрочка') => 'Нет',
  norm_header('Баллы за отзывы') => 'Нет',
  norm_header('Количество заводских упаковок') => '1',
  norm_header('Наличие серийного номера')     => 'Нет',
  norm_header('Гарантийный срок')             => '1 год',
  norm_header('Срок службы, лет')             => '10',
  norm_header('Страна-изготовитель')        => 'Россия',
  norm_header('Класс опасности товара')     => 'Не опасен',
  norm_header('Срок годности в днях')       => '3650',
  norm_header('Гарантия на товар, мес.')       => '24',


];

// фикс-маппинг по заголовку
$H = [
  norm_header('Артикул') => 'offer_id',
  norm_header('Партномер') => 'offer_id',
  norm_header('Штрихкод (Серийный номер / EAN)') => 'tag:barcode',
  norm_header('Штрихкод') => 'tag:barcode',
  norm_header('Тип*') => 'ozon_type',
  norm_header('Название товара') => 'tag:name',
  norm_header('Цена, руб.*') => 'tag:price',
  norm_header('Цена, руб.')  => 'tag:price',

  norm_header('Вес в упаковке, г*') => 'grams:weight',
  norm_header('Вес в упаковке, г')  => 'grams:weight',
  norm_header('Вес товара, г')      => 'grams:weight',

  // ВАЖНО: размеры в датасете в СМ, в шаблоне нужны ММ => *10 делаем в обработчике dim:*
  norm_header('Длина упаковки, мм*') => 'dim:L',
  norm_header('Длина упаковки, мм')  => 'dim:L',
  norm_header('Ширина упаковки, мм*') => 'dim:W',
  norm_header('Ширина упаковки, мм')  => 'dim:W',
  norm_header('Высота упаковки, мм*') => 'dim:H',
  norm_header('Высота упаковки, мм')  => 'dim:H',

  // aggregated dimensions
  norm_header('Размеры, мм') => 'dims:mm',
  norm_header('Размер упаковки (Длина х Ширина х Высота), см') => 'dims:cm',

  // adult flag
  norm_header('Признак 18+*') => 'adult18',
  norm_header('Признак 18+')  => 'adult18',

  norm_header('Ссылка на главное фото*') => 'pic:main',
  norm_header('Ссылка на главное фото')  => 'pic:main',
  norm_header('Ссылки на дополнительные фото') => 'pic:extra',

  norm_header('Бренд*') => 'tag:vendor',
  norm_header('Бренд')  => 'tag:vendor',

  norm_header('Название модели (для объединения в одну карточку)*') => 'tag:same_model',
  norm_header('Название модели (для объединения в одну карточку)')  => 'tag:same_model',
  norm_header('Объединить в похожие товары')  => 'tag:same_model',

  norm_header('Название группы') => 'tag:same_model',

  norm_header('Аннотация') => 'tag:description',
  norm_header('Rich-контент JSON') => 'rich:content',
  norm_header('Rich content JSON') => 'rich:content',

  norm_header('Количество товара в УЕИ') => 'param:количество_в_единице_товара',
  norm_header('Количество в упаковке, шт') => 'param:количество_в_единице_товара',
  norm_header('Единиц в одном товаре') => 'param:количество_в_единице_товара',


  norm_header('#Хештеги') => 'tag:hashtags',

  norm_header('Цвет товара')    => 'param:цвет',
  norm_header('Название цвета') => 'param:цвет',
];

// ozon_category pair -> leaf full_path cache
$ozonTypeCache = [];

/**
 * Return leaf category full_path by ozon_category pair "parent_leaf".
 * If not found -> ''.
 */
$getOzonLeafType = function (string $pair) use (&$ozonTypeCache): string {
  $pair = trim($pair);
  if ($pair === '') return '';
  if (isset($ozonTypeCache[$pair])) return $ozonTypeCache[$pair];

  $parts = explode('_', $pair, 2);
  if (count($parts) !== 2) {
    $ozonTypeCache[$pair] = '';
    return '';
  }
  $parent = trim($parts[0]);
  $leaf   = trim($parts[1]);
  if ($parent === '' || $leaf === '') {
    $ozonTypeCache[$pair] = '';
    return '';
  }

  // ПОДСТРОЙ под свою таблицу taxonomy (ниже — наиболее вероятные поля)
  $stmt = db()->prepare("
    SELECT name
FROM feedtools_taxonomy_categories
WHERE source='ozon'
  AND is_leaf=1
  AND ozon_parent_id = ?
  AND ozon_leaf_id   = ?
LIMIT 1

  ");
  $stmt->execute([$parent, $leaf]);
  $name = (string)($stmt->fetchColumn() ?: '');

  $ozonTypeCache[$pair] = $name;
  return $name;
};


// ---------------- LOAD TEMPLATE ----------------

try {
  $spreadsheet = IOFactory::load($tmpTpl);
} catch (Throwable $e) {
  @unlink($tmpTpl);
  http_response_code(400);
  exit('Cannot read template xlsx');
}

$sheet = $spreadsheet->getSheetByName('Шаблон');
if (!$sheet) $sheet = $spreadsheet->getActiveSheet();
$videoSheet = ozon_find_video_sheet($spreadsheet);
$videoCoverSheetInfo = ozon_find_video_cover_sheet($spreadsheet, $sheet);

// Заголовки: строка 2. Данные: с 5 строки.
$HEADER_ROW = 2;
$DATA_START_ROW = 5;

// Считываем заголовки
$highestCol = $sheet->getHighestColumn();
$highestColIndex = Coordinate::columnIndexFromString($highestCol);

$cols = []; // colIndex => ['raw'=>..., 'norm'=>...]
for ($c = 1; $c <= $highestColIndex; $c++) {
  $raw = (string)($sheet->getCell(cellAddr($c, $HEADER_ROW))->getValue() ?? '');
  $raw = trim($raw);
  if ($raw === '') continue;
  $cols[$c] = ['raw' => $raw, 'norm' => norm_header($raw)];
}

// Удаляем строки с примерами начиная с 5-й (если метод есть)
$maxRow = (int)$sheet->getHighestRow();
if ($maxRow >= $DATA_START_ROW && method_exists($sheet, 'removeRow')) {
  $sheet->removeRow($DATA_START_ROW, $maxRow - $DATA_START_ROW + 1);
}

// ---------------- EXPORT OFFERS ----------------

$offersForExport = null;
if ($supplierDbOffers instanceof Traversable) {
  $offersForExport = $supplierDbOffers;
} else {
  $reader = new XMLReader();
  if (!$reader->open($xmlPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    @unlink($tmpTpl);
    http_response_code(500);
    exit('Cannot open dataset XML');
  }

  $offersForExport = (static function () use ($reader, $selected): Generator {
    try {
      while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

        $offerId = trim((string)$reader->getAttribute('id'));
        if ($offerId === '') {
          $d = $reader->depth;
          while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $d) break;
          }
          continue;
        }

        if ($selected !== null && empty($selected[$offerId])) {
          $d = $reader->depth;
          while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $d) break;
          }
          continue;
        }

        yield parse_offer_for_template($reader);
      }
    } finally {
      $reader->close();
    }
  })();
}

$rowNum = $DATA_START_ROW - 1;
$videoRows = [];
$videoCoverRows = [];

foreach ($offersForExport as $offer) {
  $offerId = trim((string)($offer['offer_id'] ?? ''));
  if ($offerId === '') continue;

  $tags = $offer['tags'];
  $pictures = $offer['pictures'];
  $params = $offer['params'];
  $productId = (int)($offer['product_id'] ?? 0);
  $categoryValues = [
    $productId => [
      'ozon' => (string)($tags['ozon_category'] ?? ''),
    ],
  ];
  $videoLinks = ozon_extract_video_links($params);
  $videoCoverLinks = ozon_extract_video_cover_links($params);

  if ($videoLinks) {
    $videoTitle = trim((string)($tags['name'] ?? ''));
    foreach ($videoLinks as $videoUrl) {
      $videoRows[] = [
        'offer_id' => $offerId,
        'title' => $videoTitle,
        'url' => $videoUrl,
      ];
    }
  }
  if ($videoCoverLinks) {
    foreach ($videoCoverLinks as $videoCoverUrl) {
      $videoCoverRows[] = [
        'offer_id' => $offerId,
        'url' => $videoCoverUrl,
      ];
    }
  }

  $picMain = $pictures[0] ?? '';
  $picExtra = '';
  if (count($pictures) > 1) {
    $rest = array_slice($pictures, 1);
    $rest = array_values(array_filter($rest, fn($x) => trim((string)$x) !== ''));
    $picExtra = $rest ? implode(' ', $rest) : '';
  }

  $dimRaw = (string)($tags['dimensions'] ?? '');
  [$Ld, $Wd, $Hd] = parse_dimensions($dimRaw);

  // Determine units:
  // 1) explicit markers in the string (mm/cm)
  // 2) otherwise infer: values >= 50 are treated as millimeters (project feed convention).
  $unit = detect_dim_unit($dimRaw);
  $asCm = false;
  if ($unit === 'cm') {
    $asCm = true;
  } elseif ($unit === 'mm') {
    $asCm = false;
  } else {
    $arr = [];
    foreach ([$Ld, $Wd, $Hd] as $v) {
      $v = str_replace(',', '.', trim((string)$v));
      if ($v !== '' && is_numeric($v)) $arr[] = (float)$v;
    }
    $max = $arr ? max($arr) : 0.0;
    // 100/100/100 should be interpreted as 100 mm, not 100 cm.
    $asCm = ($max > 0.0 && $max < 50.0);
  }

  $Lmm = dim_auto_to_mm($Ld, $asCm);
  $Wmm = dim_auto_to_mm($Wd, $asCm);
  $Hmm = dim_auto_to_mm($Hd, $asCm);

  $Lcm = mm_to_cm_str($Lmm);
  $Wcm = mm_to_cm_str($Wmm);
  $Hcm = mm_to_cm_str($Hmm);


  $weightG = to_grams((string)($tags['weight'] ?? ''));

  // params normalized map for header match
  $pmap = [];
  foreach ($params as $pn => $vals) {
    $nk = norm_header((string)$pn);
    $vals2 = [];
    $keepRaw = isset(ozon_raw_url_param_names()[$nk])
      || in_array($nk, [
        norm_header('Rich-контент JSON'),
        norm_header('Rich content JSON'),
      ], true);
    foreach ((array)$vals as $v) {
      $v = $keepRaw
        ? norm_spaces_to_one((string)$v)
        : norm_spaces_to_one(ft_sanitize_plain_text((string)$v));
      if ($v !== '') $vals2[] = $v;
    }
    if (!$vals2) continue;
    if (function_exists('supplier_products_is_tnved_characteristic_name') && supplier_products_is_tnved_characteristic_name((string)$pn)) {
      $vals2 = array_values(array_filter(array_map(
        static function (string $value) use ($productId, $categoryValues, $cfg): string {
          if ($productId <= 0 || !function_exists('supplier_products_tnved_value_for_product')) {
            return $value;
          }
          return supplier_products_tnved_value_for_product($productId, $value, $categoryValues, $cfg);
        },
        $vals2
      ), static fn(string $value): bool => trim($value) !== ''));
      if (!$vals2) continue;
    }
    if (!isset($pmap[$nk])) $pmap[$nk] = [];
    $pmap[$nk] = array_merge($pmap[$nk], $vals2);
    if (function_exists('supplier_products_is_tnved_characteristic_name') && supplier_products_is_tnved_characteristic_name((string)$pn)) {
      $tnvedKey = norm_header('ТН ВЭД коды ЕАЭС');
      if (!isset($pmap[$tnvedKey])) $pmap[$tnvedKey] = [];
      $pmap[$tnvedKey] = array_merge($pmap[$tnvedKey], $vals2);
    }
  }

  $rowNum++;

  foreach ($cols as $colIndex => $col) {
    $hn = $col['norm'];

    // не заполнять
    if (isset($SKIP[$hn])) {
      continue;
    }

    // константы
    if (isset($CONST[$hn])) {
      $sheet->setCellValueExplicit(cellAddr($colIndex, $rowNum), $CONST[$hn], DataType::TYPE_STRING);
      continue;
    }

    // фикс-маппинг
    if (isset($H[$hn])) {
      $rule = $H[$hn];
      $value = '';

      if ($rule === 'offer_id') {
        $value = $offerId;
      } elseif ($rule === 'ozon_type') {
        $pair = (string)($tags['ozon_category'] ?? '');
        $value = $pair !== '' ? $getOzonLeafType($pair) : '';
      } elseif (strpos($rule, 'tag:') === 0) {
        $t = substr($rule, 4);
        $value = (string)($tags[$t] ?? '');
        if ($t === 'hashtags') {
          $value = hashtags_to_spaces($value);
        } elseif ($t === 'description') {
          $value = ozon_annotation_html($value);
        } elseif ($t === 'price') {
          $value = marketplace_price_to_rub_string($value);
        } elseif (!in_array($t, ['picture', 'url'], true)) {
          $value = ft_sanitize_plain_text($value);
        }
      } elseif ($rule === 'grams:weight') {
        $value = $weightG;
      } elseif ($rule === 'dim:L') {
        $value = $Lmm;
      } elseif ($rule === 'dim:W') {
        $value = $Wmm;
      } elseif ($rule === 'dim:H') {
        $value = $Hmm;
      } elseif ($rule === 'dims:mm') {
        $value = ($Lmm !== '' && $Wmm !== '' && $Hmm !== '') ? ($Lmm . ' x ' . $Wmm . ' x ' . $Hmm) : '';
      } elseif ($rule === 'dims:cm') {
        $value = ($Lcm !== '' && $Wcm !== '' && $Hcm !== '') ? ($Lcm . ' x ' . $Wcm . ' x ' . $Hcm) : '';
      } elseif ($rule === 'adult18') {
        $a = mb_strtolower(trim((string)($tags['adult'] ?? '')));
        $isAdult = in_array($a, ['true', '1', 'yes', 'да'], true);

        // fallback: age == 18
        if (!$isAdult) {
          $ageRaw = trim((string)($tags['age'] ?? ''));
          if ($ageRaw !== '') {
            $ageNum = preg_replace('/\D+/', '', $ageRaw);
            if ($ageNum === '18') $isAdult = true;
          }
        }
        $value = $isAdult ? 'Да' : '';
      } elseif ($rule === 'pic:main') {
        $value = $picMain;
      } elseif ($rule === 'pic:extra') {
        $value = $picExtra;
      } elseif ($rule === 'rich:content') {
        $richVals = $pmap[norm_header('Rich-контент JSON')] ?? $pmap[norm_header('Rich content JSON')] ?? [];
        if ($richVals) {
          $value = ft_sanitize_ozon_rich_json(trim((string)$richVals[0]));
        }
        if ($value === '') {
          $value = ozon_build_rich_content_json(
            (string)($tags['name'] ?? ''),
            (string)($tags['description'] ?? ''),
            $pictures,
            $params
          );
        }
      } elseif (strpos($rule, 'param:') === 0) {
        $p = norm_header(substr($rule, 6));
        $vals = $pmap[$p] ?? [];
        if ($vals) {
          $uniq = [];
          $seen = [];
          foreach ($vals as $v) {
            if (isset($seen[$v])) continue;
            $seen[$v] = true;
            $uniq[] = $v;
          }
          $value = implode(';', $uniq);
        }
      }

      if ($value !== '') {
        if ($rule === 'tag:price' && preg_match('/^\d+$/', (string)$value)) {
          $sheet->setCellValue(cellAddr($colIndex, $rowNum), (int)$value);
        } else {
          $sheet->setCellValueExplicit(cellAddr($colIndex, $rowNum), (string)$value, DataType::TYPE_STRING);
        }
        // Для "Аннотация" (tag:description) включаем перенос строк
        if ($rule === 'tag:description') {
          $addr = cellAddr($colIndex, $rowNum);
          $sheet->getStyle($addr)->getAlignment()->setWrapText(true);
          $sheet->getStyle($addr)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
          // опционально: авто-высота при открытии в Excel
          $sheet->getRowDimension($rowNum)->setRowHeight(-1);
        }
      }
      continue; // <-- важно: закрываем fixed-map ветку
    }

    // дефолт для "Комплектация"
    if ($hn === norm_header('Комплектация') && empty($pmap[$hn] ?? [])) {
      $sheet->setCellValueExplicit(
        cellAddr($colIndex, $rowNum),
        'Товар в фирменной упаковке',
        DataType::TYPE_STRING
      );
      continue;
    }

    


    // иначе: характеристика (param name == заголовок)
    if (isset($pmap[$hn])) {
      $vals = $pmap[$hn];
      $uniq = [];
      $seen = [];
      foreach ($vals as $v) {
        if (isset($seen[$v])) continue;
        $seen[$v] = true;
        $uniq[] = $v;
      }
      $value = implode(';', $uniq);
      if ($value !== '') {
        $sheet->setCellValueExplicit(cellAddr($colIndex, $rowNum), $value, DataType::TYPE_STRING);
      }
    }
  }
}

if ($videoSheet) {
  $videoHeaderRow = 2;
  $videoDataStartRow = 4;
  $videoHighestColIndex = Coordinate::columnIndexFromString($videoSheet->getHighestColumn());
  $videoCols = [];

  for ($c = 1; $c <= $videoHighestColIndex; $c++) {
    $raw = trim((string)($videoSheet->getCell(cellAddr($c, $videoHeaderRow))->getValue() ?? ''));
    if ($raw === '') continue;
    $videoCols[$c] = ['raw' => $raw, 'norm' => norm_header($raw)];
  }

  $videoMaxRow = (int)$videoSheet->getHighestRow();
  if ($videoMaxRow >= $videoDataStartRow && method_exists($videoSheet, 'removeRow')) {
    $videoSheet->removeRow($videoDataStartRow, $videoMaxRow - $videoDataStartRow + 1);
  }

  $videoRowNum = $videoDataStartRow - 1;
  foreach ($videoRows as $videoRow) {
    $videoRowNum++;
    sheet_copy_row_template($videoSheet, $videoDataStartRow, $videoRowNum, $videoHighestColIndex);
    sheet_clear_row_values($videoSheet, $videoRowNum, $videoHighestColIndex);

    foreach ($videoCols as $colIndex => $col) {
      $hn = $col['norm'];
      $value = '';

      if ($hn === norm_header('Артикул')) {
        $value = (string)$videoRow['offer_id'];
      } elseif ($hn === norm_header('Озон.Видео: название')) {
        $value = (string)$videoRow['title'];
      } elseif ($hn === norm_header('Озон.Видео: ссылка')) {
        $value = (string)$videoRow['url'];
      } elseif ($hn === norm_header('Озон.Видео: товары на видео')) {
        $value = (string)$videoRow['offer_id'];
      }

      if ($value !== '') {
        $videoSheet->setCellValueExplicit(cellAddr($colIndex, $videoRowNum), $value, DataType::TYPE_STRING);
      }
    }
  }
}

if (is_array($videoCoverSheetInfo) && isset($videoCoverSheetInfo['sheet']) && $videoCoverSheetInfo['sheet'] instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet) {
  $videoCoverSheet = $videoCoverSheetInfo['sheet'];
  $videoCoverHeaderRow = (int)($videoCoverSheetInfo['header_row'] ?? 2);
  $videoCoverDataStartRow = (int)($videoCoverSheetInfo['data_start_row'] ?? ($videoCoverHeaderRow + 2));
  $videoCoverHighestColIndex = Coordinate::columnIndexFromString($videoCoverSheet->getHighestColumn());
  $videoCoverCols = [];

  for ($c = 1; $c <= $videoCoverHighestColIndex; $c++) {
    $raw = trim((string)($videoCoverSheet->getCell(cellAddr($c, $videoCoverHeaderRow))->getValue() ?? ''));
    if ($raw === '') continue;
    $videoCoverCols[$c] = ['raw' => $raw, 'norm' => norm_header($raw)];
  }

  $videoCoverMaxRow = (int)$videoCoverSheet->getHighestRow();
  if ($videoCoverMaxRow >= $videoCoverDataStartRow && method_exists($videoCoverSheet, 'removeRow')) {
    $videoCoverSheet->removeRow($videoCoverDataStartRow, $videoCoverMaxRow - $videoCoverDataStartRow + 1);
  }

  $videoCoverRowNum = $videoCoverDataStartRow - 1;
  foreach ($videoCoverRows as $videoCoverRow) {
    $videoCoverRowNum++;
    sheet_copy_row_template($videoCoverSheet, $videoCoverDataStartRow, $videoCoverRowNum, $videoCoverHighestColIndex);
    sheet_clear_row_values($videoCoverSheet, $videoCoverRowNum, $videoCoverHighestColIndex);

    foreach ($videoCoverCols as $colIndex => $col) {
      $hn = $col['norm'];
      $value = '';
      if ($hn === norm_header('Артикул') || $hn === norm_header('Партномер')) {
        $value = (string)$videoCoverRow['offer_id'];
      } elseif ($hn === norm_header('Озон.Видеообложка: ссылка') || $hn === norm_header('Видеообложка')) {
        $value = (string)$videoCoverRow['url'];
      }
      if ($value !== '') {
        $videoCoverSheet->setCellValueExplicit(cellAddr($colIndex, $videoCoverRowNum), $value, DataType::TYPE_STRING);
      }
    }
  }
}

// ---------------- OUTPUT ----------------

try {
  $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
  $writer->setPreCalculateFormulas(false);
  $writer->setUseDiskCaching(true, $outDir);
  $writer->save($tmpOut);
} catch (Throwable $e) {
  error_log('export_template_xlsx write failed: ' . $e->getMessage());
  http_response_code(500);
  exit('Failed to write result xlsx');
}

unset($writer);
Settings::setCache(null);
if ($cellCache instanceof FeedtoolsSpreadsheetSqliteCache) {
  $cellCache->close();
  $cellCache = null;
}
$spreadsheet = null;
@unlink($tmpTpl);

$baseName = preg_replace('/\.[a-z0-9]+$/i', '', (string)$ds['original_filename']);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $baseName);
if ($baseName === '' || $baseName === '_') $baseName = 'dataset_' . $datasetId;
$downloadName = $baseName . '_template.xlsx';

$cliOutputPath = PHP_SAPI === 'cli' ? trim((string)getenv('FEEDTOOLS_EXPORT_OUTPUT_FILE')) : '';
if ($cliOutputPath !== '') {
  $cliOutputDir = dirname($cliOutputPath);
  if (!is_dir($cliOutputDir) && !@mkdir($cliOutputDir, 0775, true) && !is_dir($cliOutputDir)) {
    http_response_code(500);
    exit('Cannot create CLI output directory');
  }
  if (!@rename($tmpOut, $cliOutputPath)) {
    if (!@copy($tmpOut, $cliOutputPath)) {
      http_response_code(500);
      exit('Cannot save CLI output file');
    }
    @unlink($tmpOut);
  }
  fwrite(STDOUT, $cliOutputPath . PHP_EOL);
  exit(0);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpOut));

readfile($tmpOut);
@unlink($tmpOut);
