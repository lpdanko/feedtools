<?php

function ft_env_load(array $paths, bool $override = false): void
{
  static $loaded = [];

  foreach ($paths as $path) {
    $real = (string)$path;
    if ($real === '' || isset($loaded[$real]) || !is_file($real)) {
      continue;
    }

    $lines = file($real, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
      continue;
    }

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#') {
        continue;
      }

      if (str_starts_with($line, 'export ')) {
        $line = trim(substr($line, 7));
      }

      $pos = strpos($line, '=');
      if ($pos === false) {
        continue;
      }

      $name = trim(substr($line, 0, $pos));
      if ($name === '' || !preg_match('/^[A-Z0-9_]+$/i', $name)) {
        continue;
      }

      $value = trim(substr($line, $pos + 1));
      $value = ft_env_unquote($value);

      if (!$override && ft_env_has($name)) {
        continue;
      }

      putenv($name . '=' . $value);
      $_ENV[$name] = $value;
      $_SERVER[$name] = $value;
    }

    $loaded[$real] = true;
  }
}

function ft_env_has(string $name): bool
{
  $value = getenv($name);
  return $value !== false;
}

function ft_env(string $name, ?string $default = null): ?string
{
  $value = getenv($name);
  if ($value === false) {
    return $default;
  }
  return (string)$value;
}

function ft_env_int(string $name, int $default = 0): int
{
  $value = ft_env($name);
  if ($value === null || $value === '') {
    return $default;
  }
  return (int)$value;
}

function ft_env_float(string $name, float $default = 0.0): float
{
  $value = ft_env($name);
  if ($value === null || $value === '') {
    return $default;
  }
  return (float)str_replace(',', '.', trim($value));
}

function ft_env_bool(string $name, bool $default = false): bool
{
  $value = ft_env($name);
  if ($value === null || $value === '') {
    return $default;
  }

  $value = strtolower(trim($value));
  return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ft_env_unquote(string $value): string
{
  if ($value === '') {
    return '';
  }

  $first = $value[0];
  $last = $value[strlen($value) - 1];

  if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
    $value = substr($value, 1, -1);
  }

  return strtr($value, [
    '\n' => "\n",
    '\r' => "\r",
    '\t' => "\t",
    '\"' => '"',
    "\\'" => "'",
    '\\\\' => '\\',
  ]);
}
