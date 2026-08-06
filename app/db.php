<?php

function db_connection_exception_is_retryable(PDOException $e): bool {
  $state = (string)($e->errorInfo[0] ?? $e->getCode());
  $driverCode = (int)($e->errorInfo[1] ?? $e->getCode());
  $msg = strtolower($e->getMessage());

  return in_array($driverCode, [2002, 2003, 2006, 2013], true)
    || in_array($state, ['08004', '08S01', 'HY000'], true)
    || str_contains($msg, 'connection refused')
    || str_contains($msg, 'server has gone away')
    || str_contains($msg, 'lost connection')
    || str_contains($msg, 'no such file or directory');
}

function db(): PDO {
  if (isset($GLOBALS['__db']) && $GLOBALS['__db'] instanceof PDO) {
    return $GLOBALS['__db'];
  }

  $cfg = require __DIR__ . '/config.php';
  $db  = $cfg['db'];

  $charset = (string)($db['charset'] ?? 'utf8mb4');
  $timeoutSec = (int)($db['timeout_sec'] ?? 5);
  $socket = trim((string)($db['unix_socket'] ?? ''));

  if ($socket !== '') {
    $dsn = sprintf(
      'mysql:unix_socket=%s;dbname=%s;charset=%s',
      $socket,
      (string)$db['name'],
      $charset
    );
  } else {
    $dsn = sprintf(
      'mysql:host=%s;port=%d;dbname=%s;charset=%s',
      (string)($db['host'] ?? '127.0.0.1'),
      (int)($db['port'] ?? 3306),
      (string)$db['name'],
      $charset
    );
  }

  $attempts = max(1, (int)($db['connect_retries'] ?? 6));
  $lastError = null;
  for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
      $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeoutSec,
      ]);

      $GLOBALS['__db'] = $pdo;
      return $pdo;
    } catch (PDOException $e) {
      $lastError = $e;
      if ($attempt >= $attempts || !db_connection_exception_is_retryable($e)) {
        throw $e;
      }
      usleep(min(3000000, 250000 * $attempt * $attempt));
    }
  }

  throw $lastError ?: new RuntimeException('Database connection failed');
}
