<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/llm/OpenAIRequestLog.php';

$cfg = require __DIR__ . '/../app/config.php';

$errors = [];
$warnings = [];
$oks = [];

$requiredExtensions = [
  'pdo_mysql',
  'curl',
  'gd',
  'xml',
  'xmlreader',
  'simplexml',
  'dom',
  'mbstring',
  'zip',
  'fileinfo',
  'sqlite3',
];

foreach ($requiredExtensions as $ext) {
  if (!extension_loaded($ext)) {
    $errors[] = "Missing PHP extension: {$ext}";
  } else {
    $oks[] = "PHP extension loaded: {$ext}";
  }
}

if (!empty($cfg['remote_images']['enabled'])) {
  if (!extension_loaded('ftp')) {
    $errors[] = 'FTP extension is required when REMOTE_IMAGES_ENABLED=1';
  } else {
    $oks[] = 'FTP extension loaded';
  }
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
  $errors[] = 'Composer dependencies are missing: vendor/autoload.php not found';
} else {
  $oks[] = 'Composer dependencies are installed';
}

$paths = [
  'uploads_dir' => (string)($cfg['paths']['uploads_dir'] ?? ''),
  'outputs_dir' => (string)($cfg['paths']['outputs_dir'] ?? ''),
  'reports_dir' => (string)($cfg['paths']['reports_dir'] ?? ''),
  'logs_dir' => (string)($cfg['paths']['logs_dir'] ?? ''),
];

foreach ($paths as $key => $path) {
  if ($path === '') {
    $errors[] = "Path config is empty: {$key}";
    continue;
  }

  if (!is_dir($path)) {
    $warnings[] = "Directory does not exist yet: {$path}";
    continue;
  }

  if (!is_writable($path)) {
    $errors[] = "Directory is not writable: {$path}";
  } else {
    $oks[] = "Writable directory: {$path}";
  }
}

$db = $cfg['db'] ?? [];
if (($db['host'] ?? '') === '' && ($db['unix_socket'] ?? '') === '') {
  $errors[] = 'DB host/socket is not configured';
}
if (($db['name'] ?? '') === '') {
  $errors[] = 'DB name is not configured';
}
if (($db['user'] ?? '') === '') {
  $errors[] = 'DB user is not configured';
}
if (($db['pass'] ?? '') === '') {
  $warnings[] = 'DB password is empty';
}

$llm = $cfg['llm'] ?? ($cfg['openai'] ?? []);
if (($llm['api_key'] ?? '') === '') {
  $warnings[] = 'LLM_API_KEY is empty';
} else {
  $oks[] = 'LLM API key is configured';
}

if (!empty($llm['response_cache_enabled'])) {
  $cacheDir = (string)($llm['response_cache_dir'] ?? '');
  if ($cacheDir === '') {
    $errors[] = 'LLM response cache is enabled but response_cache_dir is empty';
  } elseif (!is_dir($cacheDir)) {
    if (@mkdir($cacheDir, 0775, true) || is_dir($cacheDir)) {
      $oks[] = "LLM response cache directory created: {$cacheDir}";
    } else {
      $errors[] = "Cannot create LLM response cache directory: {$cacheDir}";
    }
  } elseif (!is_writable($cacheDir)) {
    $errors[] = "LLM response cache directory is not writable: {$cacheDir}";
  } else {
    $oks[] = "LLM response cache directory is writable: {$cacheDir}";
  }
}

if (!empty($cfg['auth']['enabled'])) {
  $hasPlain = ((string)($cfg['auth']['pass'] ?? '') !== '');
  $hasHash = ((string)($cfg['auth']['pass_hash'] ?? '') !== '');
  $hasUser = ((string)($cfg['auth']['user'] ?? '') !== '');

  if ($hasUser && ($hasPlain || $hasHash)) {
    $oks[] = 'App-level basic auth is configured';
  } else {
    $errors[] = 'APP_BASIC_AUTH_ENABLED=1 but auth credentials are incomplete';
  }
} else {
  $warnings[] = 'App-level basic auth is disabled';
}

if (($cfg['worker']['worker_script'] ?? '') === '' || !is_file((string)$cfg['worker']['worker_script'])) {
  $errors[] = 'Worker script is missing';
} else {
  $oks[] = 'Worker script found';
}

if (!empty($cfg['worker']['auto_spawn'])) {
  $warnings[] = 'WORKER_AUTO_SPAWN=1. On production, a dedicated systemd worker is usually more stable.';
}

$workerParallel = max(1, (int)($cfg['worker']['max_parallel'] ?? 1));
$oks[] = "Worker max parallel: {$workerParallel}";
$workerCfg = is_array($cfg['worker'] ?? null) ? $cfg['worker'] : [];
$laneParts = [
  'price_tool=' . max(0, (int)($workerCfg['price_tool_max_parallel'] ?? 1)),
  'marketplace_data=' . max(0, (int)($workerCfg['marketplace_data_max_parallel'] ?? 1)),
  'supplier_feed=' . max(0, (int)($workerCfg['supplier_feed_max_parallel'] ?? 1)),
];
if ($laneParts) {
  $oks[] = 'Worker background lanes: ' . implode(', ', $laneParts);
}

try {
  db()->query('SELECT 1');
  $oks[] = 'Database connection works';

  if (!empty($llm['request_log_enabled'])) {
    OpenAIRequestLog::ensureTable();
    $oks[] = 'LLM request log table is ready';
  }
} catch (Throwable $e) {
  $errors[] = 'Database connection failed: ' . $e->getMessage();
}

if ($errors) {
  echo "FAIL\n";
} elseif ($warnings) {
  echo "WARN\n";
} else {
  echo "OK\n";
}

foreach ($errors as $line) {
  echo "[ERROR] {$line}\n";
}
foreach ($warnings as $line) {
  echo "[WARN] {$line}\n";
}
foreach ($oks as $line) {
  echo "[OK] {$line}\n";
}

exit($errors ? 1 : 0);
