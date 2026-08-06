<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(400);
  exit("CLI only\n");
}

$password = $argv[1] ?? '';
if ($password === '') {
  fwrite(STDERR, "Usage: php bin/make-password-hash.php 'your-password'\n");
  exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
