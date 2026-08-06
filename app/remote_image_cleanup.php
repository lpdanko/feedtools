<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_products.php';

function ft_remote_image_cleanup_add_reference(array &$references, string $value, array $cfg): void
{
    $value = trim($value);
    if ($value === '') {
        return;
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        $stack = [$decoded];
        while ($stack) {
            $item = array_pop($stack);
            foreach ((array)$item as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                } elseif (is_string($child)) {
                    ft_remote_image_cleanup_add_reference($references, $child, $cfg);
                }
            }
        }
        return;
    }

    $values = [$value];
    if (preg_match_all('~https?://[^\s"\'<>]+~iu', $value, $matches)) {
        $values = array_merge($values, (array)($matches[0] ?? []));
    }
    foreach ($values as $candidate) {
        $relative = supplier_products_remote_image_relative_from_url((string)$candidate, $cfg);
        if ($relative !== '') {
            $references[$relative] = true;
        }
    }
}

function ft_remote_image_cleanup_references(array $cfg): array
{
    $references = [];
    $baseUrl = trim((string)($cfg['remote_images']['base_url'] ?? ''));
    $host = strtolower(trim((string)(parse_url($baseUrl, PHP_URL_HOST) ?? '')));
    if ($host === '') {
        return $references;
    }
    $needle = '%' . $host . '%';

    $pictures = db()->prepare("SELECT pictures_json FROM feedtools_supplier_products WHERE pictures_json LIKE ?");
    $pictures->execute([$needle]);
    while ($row = $pictures->fetch(PDO::FETCH_ASSOC)) {
        ft_remote_image_cleanup_add_reference($references, (string)($row['pictures_json'] ?? ''), $cfg);
    }

    $fields = db()->prepare("SELECT field_value FROM feedtools_supplier_product_fields WHERE field_value LIKE ?");
    $fields->execute([$needle]);
    while ($row = $fields->fetch(PDO::FETCH_ASSOC)) {
        ft_remote_image_cleanup_add_reference($references, (string)($row['field_value'] ?? ''), $cfg);
    }

    return $references;
}

function ft_remote_image_cleanup_ftp_connect(array $cfg)
{
    $ftpCfg = (array)($cfg['remote_images']['ftp'] ?? []);
    $host = trim((string)($ftpCfg['host'] ?? ''));
    $user = trim((string)($ftpCfg['user'] ?? ''));
    $pass = (string)($ftpCfg['pass'] ?? '');
    if ($host === '' || $user === '' || $pass === '') {
        throw new RuntimeException('FTP для публичных изображений не настроен.');
    }

    $port = max(1, (int)($ftpCfg['port'] ?? 21));
    $timeout = max(10, (int)($ftpCfg['timeout_sec'] ?? 30));
    $ftp = !empty($ftpCfg['ssl']) && function_exists('ftp_ssl_connect')
        ? @ftp_ssl_connect($host, $port, $timeout)
        : @ftp_connect($host, $port, $timeout);
    if (!$ftp || !@ftp_login($ftp, $user, $pass)) {
        if ($ftp) {
            @ftp_close($ftp);
        }
        throw new RuntimeException('Не удалось подключиться к FTP публичных изображений.');
    }
    @ftp_pasv($ftp, !array_key_exists('passive', $ftpCfg) || !empty($ftpCfg['passive']));
    return $ftp;
}

function ft_remote_image_cleanup_list($ftp, string $path): array
{
    $rows = @ftp_rawlist($ftp, $path);
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $line) {
        $parts = preg_split('~\s+~', trim((string)$line), 9);
        if (!is_array($parts) || count($parts) < 9) {
            continue;
        }
        $name = (string)$parts[8];
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        $out[] = [
            'name' => $name,
            'is_dir' => str_starts_with((string)$parts[0], 'd'),
            'size' => max(0, (int)$parts[4]),
        ];
    }
    return $out;
}

function ft_remote_image_cleanup_group(string $relative): string
{
    $parts = explode('/', trim($relative, '/'));
    if (str_starts_with((string)($parts[0] ?? ''), 'supplier_')) {
        return implode('/', array_slice($parts, 0, min(3, max(1, count($parts) - 1))));
    }
    return (string)($parts[0] ?? 'other');
}

function ft_remote_image_cleanup_run(array $cfg, array $options = []): array
{
    $apply = !empty($options['apply']);
    $minAgeDays = max(1, (int)($options['min_age_days'] ?? 7));
    $limit = max(1, min(200000, (int)($options['limit'] ?? 5000)));
    $deleteCache = !empty($options['delete_cache']);
    $deleteLegacy = !empty($options['delete_legacy']);
    $threshold = time() - ($minAgeDays * 86400);

    $remote = (array)($cfg['remote_images'] ?? []);
    $ftpCfg = (array)($remote['ftp'] ?? []);
    $rootDir = rtrim(str_replace('\\', '/', trim((string)($ftpCfg['root_dir'] ?? ''))), '/');
    $prefix = supplier_products_remote_image_path_prefix($cfg);
    if ($rootDir === '' || $prefix === '') {
        throw new RuntimeException('Корневая папка публичных изображений не настроена.');
    }

    $references = ft_remote_image_cleanup_references($cfg);
    $seenRemote = [];
    $ftp = ft_remote_image_cleanup_ftp_connect($cfg);
    $scanRoot = $rootDir . '/' . $prefix;
    $stats = [
        'apply' => $apply,
        'referenced_files' => count($references),
        'scanned_files' => 0,
        'candidate_files' => 0,
        'candidate_bytes' => 0,
        'deleted_files' => 0,
        'freed_bytes' => 0,
        'failed_files' => 0,
        'skipped_recent' => 0,
        'removed_dirs' => 0,
        'missing_referenced_files' => 0,
        'missing_referenced_examples' => [],
        'groups' => [],
    ];

    $recordCandidate = static function (string $relative, int $size) use (&$stats): void {
        $stats['candidate_files']++;
        $stats['candidate_bytes'] += $size;
        $group = ft_remote_image_cleanup_group($relative);
        $stats['groups'][$group]['files'] = (int)($stats['groups'][$group]['files'] ?? 0) + 1;
        $stats['groups'][$group]['bytes'] = (int)($stats['groups'][$group]['bytes'] ?? 0) + $size;
    };

    $deleteFile = static function (string $remotePath, string $relative, int $size) use (
        &$stats,
        $recordCandidate,
        $ftp,
        $apply,
        $limit
    ): bool {
        $recordCandidate($relative, $size);
        if (!$apply || $stats['deleted_files'] >= $limit) {
            return false;
        }
        if (@ftp_delete($ftp, $remotePath)) {
            $stats['deleted_files']++;
            $stats['freed_bytes'] += $size;
            return true;
        }
        $stats['failed_files']++;
        return false;
    };

    $walk = function (string $remotePath, string $relativeDir) use (
        &$walk,
        &$stats,
        &$seenRemote,
        $references,
        $ftp,
        $apply,
        $threshold,
        $deleteFile
    ): void {
        foreach (ft_remote_image_cleanup_list($ftp, $remotePath) as $entry) {
            $name = (string)$entry['name'];
            $childRemote = $remotePath . '/' . $name;
            $childRelative = ltrim($relativeDir . '/' . $name, '/');
            if (!empty($entry['is_dir'])) {
                $walk($childRemote, $childRelative);
                if ($apply && @ftp_rmdir($ftp, $childRemote)) {
                    $stats['removed_dirs']++;
                }
                continue;
            }

            $stats['scanned_files']++;
            $seenRemote[$childRelative] = true;
            if (isset($references[$childRelative])) {
                continue;
            }
            $isOldEnough = false;
            if (preg_match('~^supplier_\d+/(\d{6})/~', $childRelative, $monthMatch)) {
                $fileMonth = (string)$monthMatch[1];
                $thresholdMonth = date('Ym', $threshold);
                if ($fileMonth < $thresholdMonth) {
                    $isOldEnough = true;
                } elseif ($fileMonth > $thresholdMonth) {
                    $stats['skipped_recent']++;
                    continue;
                }
            }
            if (!$isOldEnough) {
                $mtime = @ftp_mdtm($ftp, $childRemote);
                if (!is_int($mtime) || $mtime < 0 || $mtime >= $threshold) {
                    $stats['skipped_recent']++;
                    continue;
                }
            }
            $deleteFile($childRemote, $childRelative, (int)$entry['size']);
        }
    };

    try {
        foreach (ft_remote_image_cleanup_list($ftp, $scanRoot) as $entry) {
            $name = (string)$entry['name'];
            $remotePath = $scanRoot . '/' . $name;
            if (!empty($entry['is_dir'])) {
                if (str_starts_with($name, 'supplier_')) {
                    $walk($remotePath, $name);
                } elseif ($deleteCache && $name === 'ozon_photo_cache') {
                    $walk($remotePath, $name);
                }
                continue;
            }
            if ($deleteLegacy && preg_match('~^img_[a-f0-9]{64}\.(?:jpe?g|png|webp)$~i', $name)) {
                $stats['scanned_files']++;
                $deleteFile($remotePath, $name, (int)$entry['size']);
            }
        }
    } finally {
        @ftp_close($ftp);
    }

    $missingReferenced = [];
    foreach (array_keys($references) as $relative) {
        if (str_starts_with($relative, 'supplier_') && !isset($seenRemote[$relative])) {
            $missingReferenced[] = $relative;
        }
    }
    $stats['missing_referenced_files'] = count($missingReferenced);
    $stats['missing_referenced_examples'] = array_slice($missingReferenced, 0, 20);

    uasort($stats['groups'], static fn(array $a, array $b): int => ((int)$b['bytes']) <=> ((int)$a['bytes']));
    return $stats;
}
