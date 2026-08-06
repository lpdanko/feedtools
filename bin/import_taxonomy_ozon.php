<?php
declare(strict_types=1);

// bin/import_taxonomy_ozon.php
require_once __DIR__ . '/../app/taxonomy/OzonTaxonomy.php';

$file = $argv[1] ?? null;
if (!$file) {
    fwrite(STDERR, "Usage: php bin/import_taxonomy_ozon.php /absolute/path/to/categories_file\n");
    exit(2);
}

try {
    $res = OzonTaxonomy::importFromFile($file);
    echo "OK\n";
    echo "Leaves parsed: {$res['leaves_parsed']}\n";
    echo "Nodes total:  {$res['nodes_total']}\n";
    echo "Upserted:     {$res['upserted']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
