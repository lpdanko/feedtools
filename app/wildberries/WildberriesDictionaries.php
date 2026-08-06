<?php
declare(strict_types=1);

require_once __DIR__ . '/WildberriesClient.php';

function wb_dict_norm_attr_name(string $s): string
{
  $s = trim((string)$s);
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);
  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  $s = str_replace('ё', 'е', $s);
  $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
  $s = preg_replace('~\s+~u', ' ', (string)$s);
  return trim((string)$s);
}

function wb_dict_push_unique(array &$out, array &$seen, string $value): void
{
  $value = trim((string)$value);
  if ($value === '') return;
  $key = wb_dict_norm_attr_name($value);
  if ($key === '' || isset($seen[$key])) return;
  $seen[$key] = true;
  $out[] = $value;
}

function wb_dict_extract_values(array $resp, string $directory): array
{
  $items = $resp['data'] ?? [];
  if (!is_array($items)) return [];

  $out = [];
  $seen = [];

  if ($directory === 'colors') {
    // WB colors are very granular. Put parent colors first so GPT prefers stable base names.
    foreach ($items as $item) {
      if (is_array($item)) {
        wb_dict_push_unique($out, $seen, (string)($item['parentName'] ?? ''));
      }
    }
    foreach ($items as $item) {
      if (is_array($item)) {
        wb_dict_push_unique($out, $seen, (string)($item['name'] ?? ''));
      }
    }
    return array_slice($out, 0, 300);
  }

  foreach ($items as $item) {
    if (is_array($item)) {
      wb_dict_push_unique($out, $seen, (string)($item['name'] ?? ''));
    } else {
      wb_dict_push_unique($out, $seen, (string)$item);
    }
  }

  return array_slice($out, 0, 300);
}

function wb_dict_values(WildberriesClient $wb, string $directory): array
{
  static $cache = [];

  $directory = trim($directory);
  if ($directory === '') return [];
  if (array_key_exists($directory, $cache)) return $cache[$directory];

  try {
    $cache[$directory] = wb_dict_extract_values($wb->getDirectory($directory), $directory);
  } catch (Throwable $e) {
    $cache[$directory] = [];
  }

  return $cache[$directory];
}

function wb_dict_for_attribute(WildberriesClient $wb, string $attributeName): array
{
  $nk = wb_dict_norm_attr_name($attributeName);
  if ($nk === '') return [];

  if (in_array($nk, ['цвет', 'основной цвет', 'цвет товара'], true)) {
    return ['directory' => 'colors', 'values' => wb_dict_values($wb, 'colors')];
  }

  if (in_array($nk, ['страна производства', 'страна производитель'], true)) {
    return ['directory' => 'countries', 'values' => wb_dict_values($wb, 'countries')];
  }

  if ($nk === 'сезон') {
    return ['directory' => 'seasons', 'values' => wb_dict_values($wb, 'seasons')];
  }

  if (in_array($nk, ['пол', 'назначение по полу'], true)) {
    return ['directory' => 'kinds', 'values' => wb_dict_values($wb, 'kinds')];
  }

  return [];
}

function wb_dict_enrich_characteristic_meta(WildberriesClient $wb, array $row): array
{
  $name = trim((string)($row['name'] ?? ''));
  if ($name === '') return $row;

  $dict = wb_dict_for_attribute($wb, $name);
  $values = $dict['values'] ?? [];
  if (!is_array($values) || !$values) return $row;

  $row['allowed_values'] = array_values($values);
  $row['selection_mode'] = 'choose_one';
  $row['value_source'] = 'wb_directory_' . (string)($dict['directory'] ?? 'unknown');
  return $row;
}
