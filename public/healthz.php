<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();

$status = 'ok';
$httpCode = 200;
$checks = [
  'config' => 'ok',
];

if (((string)($_GET['db'] ?? '0')) === '1') {
  require_once __DIR__ . '/../app/db.php';
  try {
    db()->query('SELECT 1');
    $checks['db'] = 'ok';
  } catch (Throwable $e) {
    $status = 'error';
    $httpCode = 503;
    $checks['db'] = 'error';
  }
}

header('Content-Type: application/json; charset=utf-8');
http_response_code($httpCode);
echo json_encode([
  'status' => $status,
  'app' => 'feedtools',
  'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
