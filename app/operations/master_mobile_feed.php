<?php
declare(strict_types=1);

require_once __DIR__ . '/../master_mobile_admin.php';

function mm_feed_progress(int $opId, int $done, string $stage, string $message): void
{
    ops_update_progress($opId, max(0, min(1000, $done)), 1000, $stage, $message);
}

function mm_feed_command_label(array $cmd): string
{
    return implode(' ', array_map(static function ($part): string {
        $part = (string)$part;
        return preg_match('~^[A-Za-z0-9_./:=@+-]+$~', $part) ? $part : escapeshellarg($part);
    }, $cmd));
}

function mm_feed_run_command(
    array $cmd,
    string $cwd,
    array $env,
    int $opId,
    callable $log,
    callable $onLine
): array {
    $log('$ ' . mm_feed_command_label($cmd) . "\n");

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить процесс.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $buffers = [1 => '', 2 => ''];
    $exitCode = null;
    $pendingException = null;

    try {
        while (true) {
            foreach ([1, 2] as $fd) {
                while (($chunk = fread($pipes[$fd], 8192)) !== false && $chunk !== '') {
                    if ($fd === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                    $buffers[$fd] .= $chunk;
                    while (($pos = strpos($buffers[$fd], "\n")) !== false) {
                        $line = substr($buffers[$fd], 0, $pos);
                        $buffers[$fd] = substr($buffers[$fd], $pos + 1);
                        $line = rtrim($line, "\r");
                        if ($line !== '') {
                            $log($line . "\n");
                            $onLine($line, $fd);
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = is_int($status['exitcode'] ?? null) ? (int)$status['exitcode'] : null;
                foreach ([1, 2] as $fd) {
                    while (($chunk = fread($pipes[$fd], 8192)) !== false && $chunk !== '') {
                        if ($fd === 1) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                        $buffers[$fd] .= $chunk;
                    }
                }
                break;
            }

            if (function_exists('ops_is_cancel_requested') && ops_is_cancel_requested($opId)) {
                @proc_terminate($process);
                $pendingException = new RuntimeException('Операция отменена пользователем.');
                break;
            }

            usleep(200000);
        }
    } finally {
        foreach ([1, 2] as $fd) {
            if (isset($buffers[$fd]) && trim($buffers[$fd]) !== '') {
                $line = trim($buffers[$fd]);
                $log($line . "\n");
                $onLine($line, $fd);
            }
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
            }
        }
    }

    $closedCode = proc_close($process);
    if ($closedCode >= 0) {
        $exitCode = $closedCode;
    }
    if ($exitCode === null) {
        $exitCode = 0;
    }

    if ($pendingException instanceof Throwable) {
        throw $pendingException;
    }

    if ($exitCode !== 0) {
        $tail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        if (strlen($tail) > 1600) {
            $tail = substr($tail, -1600);
        }
        throw new RuntimeException('Команда завершилась с ошибкой ' . $exitCode . ($tail !== '' ? ': ' . $tail : ''));
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $exitCode,
    ];
}

function mm_feed_parse_build_json(string $stdout): array
{
    $text = trim($stdout);
    if ($text === '') {
        return [];
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end < $start) {
        return [];
    }
    $json = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function mm_feed_remote_feed_available(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 12,
            'ignore_errors' => true,
            'header' => "User-Agent: FeedTools/MasterMobileSourceCheck\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $headers = @get_headers($url, false, $context);
    if (!is_array($headers) || !$headers) {
        return false;
    }
    foreach ($headers as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$header, $m)) {
            $code = (int)$m[1];
            return $code >= 200 && $code < 400;
        }
    }
    return false;
}

function mm_feed_source_feed(array $settings, array $params, callable $log): array
{
    $sourceUrl = trim((string)($params['source_url'] ?? $settings['source_url'] ?? ''));
    if ($sourceUrl !== '' && mm_feed_remote_feed_available($sourceUrl)) {
        return ['kind' => 'url', 'value' => $sourceUrl];
    }

    $fallback = trim((string)($params['source_fallback_feed_path'] ?? $settings['source_fallback_feed_path'] ?? $settings['feed_path'] ?? ''));
    if ($fallback !== '' && is_file(master_mobile_abs_path($fallback))) {
        $log("Source XML unavailable; using local fallback feed: {$fallback}\n");
        return ['kind' => 'file', 'value' => $fallback];
    }

    if ($sourceUrl !== '') {
        return ['kind' => 'url', 'value' => $sourceUrl];
    }
    throw new RuntimeException('Источник Master Mobile недоступен и локальный fallback-фид не найден.');
}

function mm_feed_process_env(): array
{
    $env = $_ENV;
    foreach ([
        'PATH',
        'HOME',
        'LANG',
        'LC_ALL',
        'MASTER_MOBILE_PYTHON',
        'MASTER_MOBILE_LOGIN',
        'MASTER_MOBILE_PASSWORD',
        'MASTER_MOBILE_FTP_HOST',
        'MASTER_MOBILE_FTP_USER',
        'MASTER_MOBILE_FTP_PASS',
        'MASTER_MOBILE_FTP_DIR',
        'MASTER_MOBILE_FTP_FILE',
        'MASTER_MOBILE_FTP_TLS',
    ] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            $env[$key] = $value;
        }
    }
    if (empty($env['PATH'])) {
        $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    }
    return $env;
}

function mm_feed_run_parser(array $settings, array $params, int $opId, callable $log): array
{
    $root = master_mobile_root_dir();
    $python = getenv('MASTER_MOBILE_PYTHON') ?: 'python3';
    $maxWorkers = max(1, min(12, (int)(getenv('MASTER_MOBILE_MAX_WORKERS') ?: 6)));
    $workers = max(1, min($maxWorkers, (int)($params['workers'] ?? $settings['workers'] ?? 4)));
    $limit = max(0, (int)($params['limit'] ?? 0));
    $snapshot = (string)($params['snapshot_path'] ?? $settings['snapshot_path']);
    $storeId = trim((string)($params['store_id'] ?? $settings['store_id']));
    $priceMode = master_mobile_normalize_price_mode((string)($params['price_mode'] ?? 'pricelist'));
    $sourceFeed = mm_feed_source_feed($settings, $params, $log);

    $cmd = [
        $python,
        $root . '/bin/master_mobile_parser.py',
        '--source', 'feed-products',
        '--store-id', $storeId !== '' ? $storeId : '2',
        '--workers', (string)$workers,
        '--delay', '0',
        '--no-cache',
        '--ignore-robots',
        '--insecure',
        '-o', master_mobile_abs_path($snapshot),
    ];
    $cmd[] = '--article-feed';
    $cmd[] = $sourceFeed['kind'] === 'file'
        ? master_mobile_abs_path((string)$sourceFeed['value'])
        : (string)$sourceFeed['value'];
    if ($limit > 0) {
        $cmd[] = '--limit';
        $cmd[] = (string)$limit;
    }
    if ($priceMode === 'parsed') {
        $cmd[] = '--fetch-product-prices';
    } else {
        $cmd[] = '--no-login';
    }

    $seenTotal = 0;
    $seenDone = 0;
    mm_feed_progress($opId, 20, 'parse_stock', 'Запускаем парсинг остатков');
    $result = mm_feed_run_command(
        $cmd,
        $root,
        mm_feed_process_env(),
        $opId,
        $log,
        static function (string $line) use (&$seenTotal, &$seenDone, $opId): void {
            if (preg_match('~(?:Product URLs selected from corrected feed|Current products selected from categories):\s*(\d+)~u', $line, $m)) {
                $seenTotal = max(0, (int)$m[1]);
                mm_feed_progress($opId, 70, 'parse_stock', 'Найдено товаров для парсинга: ' . $seenTotal);
                return;
            }
            if (preg_match('~\[(\d+)/(\d+)\]\s+Buy block:~u', $line, $m)) {
                $seenDone = max($seenDone, (int)$m[1]);
                $seenTotal = max($seenTotal, (int)$m[2]);
                $done = $seenTotal > 0 ? (70 + (int)round(700 * min($seenDone, $seenTotal) / $seenTotal)) : 120;
                mm_feed_progress($opId, $done, 'parse_stock', 'Парсим остатки: ' . $seenDone . ' из ' . $seenTotal);
                return;
            }
            if (preg_match('~Products parsed:\s*(\d+)~u', $line, $m)) {
                mm_feed_progress($opId, 790, 'parse_stock', 'Остатки получены: ' . (int)$m[1]);
            }
        }
    );
    mm_feed_progress($opId, 800, 'parse_stock', 'Парсинг остатков завершен');

    return [
        'snapshot_path' => $snapshot,
        'snapshot_exists' => is_file(master_mobile_abs_path($snapshot)),
        'products_seen' => $seenTotal,
        'products_processed' => $seenDone,
        'exit_code' => (int)$result['exit_code'],
    ];
}

function mm_feed_run_builder(array $settings, array $params, int $opId, callable $log, int $startProgress = 820): array
{
    $root = master_mobile_root_dir();
    $priceMode = master_mobile_normalize_price_mode((string)($params['price_mode'] ?? 'pricelist'));
    $upload = master_mobile_bool($params['upload_feed'] ?? null, true);
    $snapshot = (string)($params['snapshot_path'] ?? $settings['snapshot_path']);
    $feedPath = (string)($params['feed_path'] ?? $settings['feed_path']);
    $imageReplacements = (string)($params['image_replacements_path'] ?? $settings['image_replacements_path']);
    $purchasePrices = (string)($params['purchase_prices_path'] ?? $settings['purchase_prices_path']);
    $minCoverage = (string)($params['min_coverage'] ?? $settings['min_coverage'] ?? '0.95');
    $sourceFeed = mm_feed_source_feed($settings, $params, $log);

    if (!is_file(master_mobile_abs_path($snapshot))) {
        throw new RuntimeException('Сначала нужно получить snapshot остатков: ' . $snapshot);
    }
    if ($priceMode === 'pricelist' && !is_file(master_mobile_abs_path($purchasePrices))) {
        throw new RuntimeException('Прайс для закупочных цен не найден: ' . $purchasePrices);
    }

    $cmd = [
        PHP_BINARY,
        $root . '/bin/master_mobile_build_feed.php',
        '--snapshot=' . $snapshot,
        '--output=' . $feedPath,
        '--image-replacements=' . $imageReplacements,
        '--min-coverage=' . $minCoverage,
    ];
    if ($sourceFeed['kind'] === 'file') {
        $cmd[] = '--base-feed=' . (string)$sourceFeed['value'];
    } else {
        $cmd[] = '--base-url=' . (string)$sourceFeed['value'];
    }
    if ($priceMode === 'pricelist') {
        $cmd[] = '--purchase-prices=' . $purchasePrices;
    } else {
        $cmd[] = '--no-purchase-prices';
    }
    if (!$upload) {
        $cmd[] = '--no-upload';
    }

    mm_feed_progress($opId, $startProgress, 'build_feed', 'Собираем итоговый XML');
    $result = mm_feed_run_command(
        $cmd,
        $root,
        mm_feed_process_env(),
        $opId,
        $log,
        static function (string $line) use ($opId, $startProgress): void {
            if (str_starts_with(trim($line), '{')) {
                mm_feed_progress($opId, max($startProgress, 940), 'build_feed', 'Получена статистика сборки');
            }
        }
    );

    $stats = mm_feed_parse_build_json((string)$result['stdout']);
    mm_feed_progress($opId, 970, 'build_feed', !empty($stats['uploaded']) ? 'Фид собран и загружен на FTP' : 'Фид собран');

    return [
        'feed_path' => $feedPath,
        'feed_exists' => is_file(master_mobile_abs_path($feedPath)),
        'price_mode' => $priceMode,
        'purchase_prices_path' => $priceMode === 'pricelist' ? $purchasePrices : '',
        'uploaded' => !empty($stats['uploaded']),
        'stats' => $stats,
        'exit_code' => (int)$result['exit_code'],
    ];
}

function op_master_mobile_feed(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $settings = master_mobile_default_settings();
    $task = master_mobile_normalize_task((string)($params['task_key'] ?? $params['task'] ?? 'parse_and_build'));
    $priceMode = master_mobile_normalize_price_mode((string)($params['price_mode'] ?? 'pricelist'));
    $params['task_key'] = $task;
    $params['price_mode'] = $priceMode;

    $log("Master Mobile task: " . master_mobile_task_label($task) . "\n");
    $log("Price mode: " . master_mobile_price_mode_label($priceMode) . "\n");
    $log("Store: " . $settings['store_name'] . " (ID " . $settings['store_id'] . ")\n");

    $parseResult = null;
    $buildResult = null;
    $runParser = in_array($task, ['parse_stock', 'parse_and_build'], true);
    $runBuilder = in_array($task, ['build_feed', 'parse_stock', 'parse_and_build'], true);

    if ($runParser) {
        $parseResult = mm_feed_run_parser($settings, $params, $opId, $log);
    }

    if ($runBuilder) {
        $buildResult = mm_feed_run_builder($settings, $params, $opId, $log, $runParser ? 820 : 120);
    }

    mm_feed_progress($opId, 1000, 'done', 'Готово');

    $summary = [
        'supplier_key' => master_mobile_supplier_key(),
        'supplier_name' => $settings['supplier_name'],
        'task_key' => $task,
        'task_label' => master_mobile_task_label($task),
        'price_mode' => $priceMode,
        'price_mode_label' => master_mobile_price_mode_label($priceMode),
        'public_feed_url' => $settings['public_feed_url'],
        'snapshot_path' => (string)($params['snapshot_path'] ?? $settings['snapshot_path']),
        'feed_path' => (string)($params['feed_path'] ?? $settings['feed_path']),
        'parse' => $parseResult,
        'build' => $buildResult,
        'finished_at' => date('c'),
    ];

    $outDir = op_output_dir($cfg, (int)$ds['id'], $opId);
    ensure_dir($outDir);
    $summaryPath = $outDir . '/master_mobile_summary.json';
    file_put_contents($summaryPath, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    return [
        'summary_json_inline' => $summary,
        'master_mobile_summary' => rel_to_outputs($cfg, $summaryPath),
        'public_feed_url' => $settings['public_feed_url'],
    ];
}
