<?php

function op_output_dir(array $cfg, int $datasetId, int $opId): string {
  $baseOut = rtrim($cfg['paths']['outputs_dir'], '/');
  return $baseOut . "/dataset_" . $datasetId . "/op_" . $opId;
}

function ensure_dir(string $dir): void {
  if (is_dir($dir)) return;

  $inheritFrom = dirname($dir);
  while ($inheritFrom !== dirname($inheritFrom) && !is_dir($inheritFrom)) {
    $inheritFrom = dirname($inheritFrom);
  }
  $inheritOwner = is_dir($inheritFrom) ? @fileowner($inheritFrom) : false;
  $inheritGroup = is_dir($inheritFrom) ? @filegroup($inheritFrom) : false;

  if (!mkdir($dir, 0775, true)) {
    throw new RuntimeException("Cannot create directory: {$dir}");
  }

  // Manual maintenance commands may run as root. Keep their runtime output
  // writable by the same worker user that owns the nearest existing parent.
  $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
  if ($isRoot && $inheritOwner !== false && $inheritGroup !== false) {
    $current = $dir;
    while ($current !== $inheritFrom && str_starts_with($current, rtrim($inheritFrom, '/') . '/')) {
      @chown($current, $inheritOwner);
      @chgrp($current, $inheritGroup);
      @chmod($current, 02775);
      $current = dirname($current);
    }
  }
}

function rel_to_outputs(array $cfg, string $absPath): string {
  $root = realpath($cfg['paths']['outputs_dir']);
  $p = realpath($absPath);
  if (!$root || !$p || strpos($p, $root) !== 0) {
    throw new RuntimeException("Path is not inside outputs_dir: {$absPath}");
  }
  return ltrim(substr($p, strlen($root)), '/');
}

function abs_from_outputs(array $cfg, string $relPath): string {
  $root = realpath($cfg['paths']['outputs_dir']);
  if (!$root) throw new RuntimeException("outputs_dir not found");
  return $root . '/' . ltrim($relPath, '/');
}
