<?php

require_once __DIR__ . '/../app/wildberries/WildberriesClient.php';

$cfg = require __DIR__ . '/../app/config.php';
$wb = new WildberriesClient($cfg['wildberries'] ?? []);

try {
    $ping = $wb->ping('content', 'content');
    $parents = $wb->getParentCategories();

    $items = $parents['data'] ?? [];
    $count = is_array($items) ? count($items) : 0;

    echo "WB content ping: " . json_encode($ping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "WB parent categories: {$count}" . PHP_EOL;

    if ($count > 0) {
        $sample = array_slice($items, 0, 5);
        foreach ($sample as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            $name = (string)($row['name'] ?? '');
            echo "- {$id}: {$name}" . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "WB smoke failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
