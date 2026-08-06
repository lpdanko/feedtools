<?php
declare(strict_types=1);

require_once __DIR__ . '/../taxonomy/OzonTaxonomy.php';
require_once __DIR__ . '/../ops.php';

function op_taxonomy_import_ozon(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $filePath = trim((string)($params['file_path'] ?? ''));
  if ($filePath === '') {
    // fallback: использовать stored_path датасета
    $filePath = (string)($ds['stored_path'] ?? '');
  }
  if ($filePath === '' || !is_file($filePath)) {
    throw new RuntimeException("File not found: {$filePath}");
  }

  $replace = !empty($params['replace']) && (string)$params['replace'] !== '0';

  $summary = OzonTaxonomy::importFromFileWithProgress($filePath, $opId, $log, $replace);

  // положим summary в БД через стандарт outputs['summary_json_inline']
  return [
    'summary_json_inline' => $summary,
  ];
}
