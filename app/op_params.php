<?php

function op_params_normalize(array $paramDefs, array $src): array {
  $out = [];

  foreach ($paramDefs as $key => $def) {
    $type = $def['type'] ?? 'string';
    $required = (bool)($def['required'] ?? false);
    $default = $def['default'] ?? null;

    $has = array_key_exists($key, $src);

    if (!$has) {
      if ($required && $default === null) {
        throw new RuntimeException("Missing required param: {$key}");
      }
      if ($default !== null) $out[$key] = $default;
      continue;
    }

    $raw = $src[$key];

    // сейчас нам нужен только string, но оставим задел
    if ($type === 'string') {
      $val = trim((string)$raw);
      if ($required && $val === '' && $default === null) {
        throw new RuntimeException("Param {$key} is required");
      }
      if ($val === '' && $default !== null) $val = (string)$default;
      if (isset($def['max_len']) && mb_strlen($val, 'UTF-8') > (int)$def['max_len']) {
        throw new RuntimeException("Param {$key} too long");
      }
      $out[$key] = $val;
      continue;
    }

    throw new RuntimeException("Unsupported param type: {$type}");
  }

  return $out;
}
