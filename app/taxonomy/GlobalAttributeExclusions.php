<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function taxonomy_global_exclusions_normalize(array $names): array
{
  $seen = [];
  $out = [];
  foreach ($names as $name) {
    $value = trim((string)$name);
    if ($value === '') continue;
    $key = mb_strtolower($value, 'UTF-8');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $value;
  }
  return $out;
}

function taxonomy_global_exclusions_builtin(string $source): array
{
  if ($source !== 'ozon') return [];
  return taxonomy_global_exclusions_normalize([
    '#Хештеги',
    'Аннотация',
    'Бренд',
    'Бренд*',
    'Документ PDF',
    'Название группы',
    'Название модели (для объединения в одну карточку)',
    'Название файла PDF',
    'Название',
    'Название товара',
    'Озон.Видео: название',
    'Озон.Видео: ссылка',
    'Озон.Видео: товары на видео',
    'Озон.Видеообложка: ссылка',
    'Rich-контент JSON',
    'Rich content JSON',
    'Тип',
    'Тип*',
  ]);
}

function taxonomy_global_exclusions_table_ensure(): void
{
  static $ready = false;
  if ($ready) return;

  db()->exec("
    CREATE TABLE IF NOT EXISTS feedtools_settings (
      setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
      setting_value_json LONGTEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $ready = true;
}

function taxonomy_global_exclusions_setting_key(string $source): string
{
  $source = trim($source);
  if (!in_array($source, ['ozon', 'wildberries'], true)) {
    throw new InvalidArgumentException('Unsupported taxonomy source for exclusions');
  }
  return 'taxonomy_exclude_attribute_names:' . $source;
}

function taxonomy_global_exclusions_config_fallback(string $source, ?array $cfg = null): array
{
  if ($source !== 'ozon') return [];
  if ($cfg === null) {
    $cfg = require __DIR__ . '/../config.php';
  }
  $oz = $cfg['ozon'] ?? [];
  if (!is_array($oz)) return [];
  $names = $oz['exclude_attribute_names'] ?? [];
  return taxonomy_global_exclusions_normalize(array_merge(
    is_array($names) ? $names : [],
    taxonomy_global_exclusions_builtin($source)
  ));
}

function taxonomy_get_global_exclude_attribute_names(string $source, ?array $cfg = null): array
{
  taxonomy_global_exclusions_table_ensure();

  $key = taxonomy_global_exclusions_setting_key($source);
  $st = db()->prepare("SELECT setting_value_json FROM feedtools_settings WHERE setting_key=? LIMIT 1");
  $st->execute([$key]);
  $raw = $st->fetchColumn();

  if ($raw !== false && $raw !== null && $raw !== '') {
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
      return taxonomy_global_exclusions_normalize(array_merge($decoded, taxonomy_global_exclusions_builtin($source)));
    }
  }

  return taxonomy_global_exclusions_config_fallback($source, $cfg);
}

function taxonomy_save_global_exclude_attribute_names(string $source, array $names): void
{
  taxonomy_global_exclusions_table_ensure();

  $key = taxonomy_global_exclusions_setting_key($source);
  $payload = json_encode(taxonomy_global_exclusions_normalize($names), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $st = db()->prepare("
    INSERT INTO feedtools_settings (setting_key, setting_value_json)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE setting_value_json = VALUES(setting_value_json)
  ");
  $st->execute([$key, $payload]);
}
