<?php
declare(strict_types=1);

$dirs = [
  __DIR__ . '/../storage/cache',
  __DIR__ . '/../storage/cache/llm_responses',
  __DIR__ . '/../storage/cache/openai_responses',
  __DIR__ . '/../storage/logs',
  __DIR__ . '/../storage/outputs',
  __DIR__ . '/../storage/reports',
  __DIR__ . '/../storage/reports/brand_audits',
  __DIR__ . '/../storage/uploads',
];

foreach ($dirs as $dir) {
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create directory: {$dir}\n");
    exit(1);
  }

  echo "OK {$dir}\n";
}
