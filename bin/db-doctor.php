<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

$requiredTables = [
  'feedtools_datasets',
  'feedtools_operations',
  'feedtools_derivations',
  'feedtools_taxonomy_categories',
  'feedtools_param_value_map',
  'feedtools_ozon_products',
  'feedtools_ozon_sync_state',
  'feedtools_openai_requests',
];

try {
  $rows = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
} catch (Throwable $e) {
  fwrite(STDERR, "DB connect/query failed: " . $e->getMessage() . PHP_EOL);
  exit(1);
}

$existing = [];
foreach ($rows as $row) {
  if (isset($row[0])) {
    $existing[(string)$row[0]] = true;
  }
}

$missing = [];
foreach ($requiredTables as $table) {
  if (!isset($existing[$table])) {
    $missing[] = $table;
  }
}

if ($missing) {
  echo "MISSING TABLES\n";
  foreach ($missing as $table) {
    echo "- {$table}\n";
  }
  exit(1);
}

echo "OK\n";
foreach ($requiredTables as $table) {
  echo "- {$table}\n";
}
