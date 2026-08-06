<?php
declare(strict_types=1);

require_once __DIR__ . '/../taxonomy/OzonTaxonomy.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';

function taxonomy_import_ozon_cfg_or_fail(array $cfg): array {
  if (function_exists('ozon_cfg_or_fail')) {
    return ozon_cfg_or_fail($cfg);
  }

  $oz = $cfg['ozon'] ?? [];
  if (!is_array($oz)) $oz = [];
  $oz += ['client_id'=>'', 'api_key'=>'', 'base_url'=>'https://api-seller.ozon.ru', 'timeout_sec'=>60];

  if (trim((string)$oz['client_id']) === '' || trim((string)$oz['api_key']) === '') {
    throw new RuntimeException('Ozon API не настроен: задайте ozon.client_id и ozon.api_key (app/config.local.php или ENV)');
  }
  return $oz;
}

function taxonomy_import_ozon_post_json(array $oz, string $path, $payload): array {
  if (function_exists('ozon_post_json')) {
    return ozon_post_json($oz, $path, $payload);
  }

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
    CURLOPT_TIMEOUT => (int)($oz['timeout_sec'] ?? 60),
  ]);

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

  // curl_close() не нужен (PHP 8+); чтобы не ловить Deprecated на 8.5 — просто освобождаем переменную
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

function ozon_tree_to_lines(array $treeResult): array {
  $lines = [];

  $walk = function(array $node, array $path, int $currentDescId) use (&$walk, &$lines) {
    // skip disabled nodes if present
    if (!empty($node['disabled'])) return;

    // category node
    if (isset($node['description_category_id'], $node['category_name'])) {
      $descId = (int)$node['description_category_id'];
      $name = trim((string)$node['category_name']);
      if ($name === '' || $descId <= 0) return;

      $path2 = array_merge($path, [$name]);

      $children = $node['children'] ?? [];
      if (!is_array($children)) $children = [];

      foreach ($children as $ch) {
        if (!is_array($ch)) continue;

        // type leaf
        if (isset($ch['type_id'], $ch['type_name'])) {
          if (!empty($ch['disabled'])) continue;

          $typeId = (int)$ch['type_id'];
          $typeName = trim((string)$ch['type_name']);
          if ($typeId <= 0 || $typeName === '') continue;

          $fullPath = implode(' > ', array_merge($path2, [$typeName]));
          $lines[] = $fullPath . ' (' . $descId . '_' . $typeId . ')';
          continue;
        }

        // nested category
        if (isset($ch['description_category_id'], $ch['category_name'])) {
          $walk($ch, $path2, $descId);
        }
      }
      return;
    }

    // unexpected node -> ignore
  };

  foreach ($treeResult as $root) {
    if (!is_array($root)) continue;
    $walk($root, [], 0);
  }

  return $lines;
}

function op_taxonomy_import_ozon_api(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $replace = !empty($params['replace']) && (string)$params['replace'] !== '0';

  $oz = taxonomy_import_ozon_cfg_or_fail($cfg);

  ops_update_progress($opId, 0, 100, 'fetch', 'Запрос дерева категорий из Ozon API...');
  $resp = taxonomy_import_ozon_post_json($oz, '/v1/description-category/tree', (object)[]);

  $tree = $resp['result'] ?? [];
  if (!is_array($tree)) $tree = [];

  ops_update_progress($opId, 5, 100, 'build', 'Генерация файла categories_ozon.txt...');
  $lines = ozon_tree_to_lines($tree);

  if (!$lines) {
    throw new RuntimeException('Ozon tree: не удалось извлечь ни одной leaf-категории (type_id/type_name).');
  }

  $outFile = __DIR__ . '/../../storage/taxonomies/ozon/categories_ozon.txt';
  ensure_dir(dirname($outFile));
  file_put_contents($outFile, implode("\n", $lines) . "\n");

  // дальше используем существующий импортёр (прогресс он ведёт по строкам файла)
  $summary = OzonTaxonomy::importFromFileWithProgress($outFile, $opId, $log, $replace);

  return [
    'summary_json_inline' => $summary + [
      'source' => 'ozon_api_tree',
      'generated_file' => $outFile,
      'generated_lines' => count($lines),
    ],
  ];
}
