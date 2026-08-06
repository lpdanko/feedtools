#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg = require $root . '/app/config.php';
require_once $root . '/app/remote_image_cleanup.php';

$options = getopt('', [
    'apply',
    'min-age-days:',
    'limit:',
    'delete-cache',
    'delete-legacy',
]);

$stats = ft_remote_image_cleanup_run($cfg, [
    'apply' => isset($options['apply']),
    'min_age_days' => isset($options['min-age-days']) ? (int)$options['min-age-days'] : 7,
    'limit' => isset($options['limit']) ? (int)$options['limit'] : 5000,
    'delete_cache' => isset($options['delete-cache']),
    'delete_legacy' => isset($options['delete-legacy']),
]);

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

