<?php
declare(strict_types=1);

require_once __DIR__ . '/../taxonomy/WildberriesTaxonomy.php';

function op_taxonomy_import_wb_api(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $replace = !empty($params['replace']) && (string)($params['replace'] ?? '') !== '0';

  $summary = WildberriesTaxonomy::importFromApiWithProgress(
    $cfg['wildberries'] ?? [],
    $opId,
    $log,
    $replace
  );

  return [
    'summary_json_inline' => $summary,
  ];
}
