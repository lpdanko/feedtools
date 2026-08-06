<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/taxonomy/WildberriesTaxonomy.php';

$cfg = require __DIR__ . '/../app/config.php';
$replace = in_array('--replace', $argv, true);

$summary = WildberriesTaxonomy::importFromApiWithProgress(
  $cfg['wildberries'] ?? [],
  0,
  static function (string $line): void {
    fwrite(STDOUT, $line);
  },
  $replace
);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
