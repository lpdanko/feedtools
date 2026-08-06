<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

header('Content-Type: application/json; charset=UTF-8');

try {
  $raw = file_get_contents('php://input');
  if (!is_string($raw)) $raw = '';
  if (strlen($raw) > 16384) {
    $raw = substr($raw, 0, 16384);
  }

  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    $payload = ['raw' => $raw];
  }

  $entry = [
    'ts' => gmdate('c'),
    'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'payload' => $payload,
  ];

  $dir = (string)($cfg['paths']['logs_dir'] ?? (__DIR__ . '/../storage/logs'));
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
  @file_put_contents($dir . '/client_diagnostics.log', $line, FILE_APPEND | LOCK_EX);

  http_response_code(204);
} catch (Throwable $e) {
  http_response_code(204);
}
