#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

$cfg = require __DIR__ . '/../app/config.php';

function mm_build_arg_value(array $args, string $name, ?string $default = null): ?string
{
    $prefix = $name . '=';
    foreach ($args as $arg) {
        if ($arg === $name) {
            return '1';
        }
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function mm_build_has_arg(array $args, string $name): bool
{
    return mm_build_arg_value($args, $name) !== null;
}

function mm_build_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/master_mobile_build_feed.php [options]\n\n";
    echo "Options:\n";
    echo "  --snapshot=PATH       Parsed price/stock snapshot. Default: storage/master_mobile/price_stock_snapshot.yml\n";
    echo "  --output=PATH         Built full feed. Default: storage/master_mobile/master_mobile_info.xml\n";
    echo "  --base-url=URL        Source full feed URL. Default: https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml\n";
    echo "  --base-feed=PATH      Local full feed instead of downloading base-url.\n";
    echo "  --supplier-code=CODE  Supplier code suffix. Default: 24\n";
    echo "  --zero-missing        Set stock=0 for existing feed offers absent from snapshot.\n";
    echo "  --min-coverage=N      Abort if matched existing offers ratio is below N, e.g. 0.95.\n";
    echo "  --image-replacements=PATH  CSV from clean image parser to replace the first <picture>.\n";
    echo "  --purchase-prices=PATH     XLSX/CSV pricelist; writes purchase prices to price_original.\n";
    echo "  --no-purchase-prices       Do not auto-apply storage/master_mobile/pricelists/master_mobile_prices_current.*.\n";
    echo "  --no-upload           Build local output only.\n";
    echo "  --verify-tls          Verify TLS when downloading the current public feed.\n";
    echo "  --python=BIN          Python executable. Default: python3\n";
}

function mm_build_remote_feed_available(string $url): bool
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
            'header' => "User-Agent: FeedTools/MasterMobileBuild\r\n",
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

$args = array_slice($argv, 1);
if (mm_build_has_arg($args, '--help') || mm_build_has_arg($args, '-h')) {
    mm_build_usage();
    exit(0);
}

$root = dirname(__DIR__);
$python = mm_build_arg_value($args, '--python', getenv('MASTER_MOBILE_PYTHON') ?: 'python3') ?: 'python3';
$snapshot = mm_build_arg_value($args, '--snapshot', 'storage/master_mobile/price_stock_snapshot.yml') ?: 'storage/master_mobile/price_stock_snapshot.yml';
$output = mm_build_arg_value($args, '--output', 'storage/master_mobile/master_mobile_info.xml') ?: 'storage/master_mobile/master_mobile_info.xml';
$baseUrl = mm_build_arg_value($args, '--base-url', 'https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml') ?: 'https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml';
$baseFeed = mm_build_arg_value($args, '--base-feed');
$supplierCode = mm_build_arg_value($args, '--supplier-code', '24') ?: '24';
$minCoverage = mm_build_arg_value($args, '--min-coverage', '0') ?: '0';
$imageReplacements = mm_build_arg_value($args, '--image-replacements');
$purchasePrices = mm_build_arg_value($args, '--purchase-prices');
$noPurchasePrices = mm_build_has_arg($args, '--no-purchase-prices');
$upload = !mm_build_has_arg($args, '--no-upload');
$verifyTls = mm_build_has_arg($args, '--verify-tls');

if (($baseFeed === null || trim($baseFeed) === '') && !mm_build_remote_feed_available($baseUrl)) {
    $fallback = $root . '/' . ltrim($output, '/');
    if (is_file($fallback)) {
        $baseFeed = $fallback;
        fwrite(STDERR, "Source URL is unavailable; using local base feed: {$baseFeed}\n");
    }
}

if ($purchasePrices === null && !$noPurchasePrices) {
    foreach ([
        'storage/master_mobile/pricelists/master_mobile_prices_current.xlsx',
        'storage/master_mobile/pricelists/master_mobile_prices_current.csv',
    ] as $candidate) {
        if (is_file($root . '/' . $candidate)) {
            $purchasePrices = $candidate;
            break;
        }
    }
}

$cmd = [
    $python,
    $root . '/bin/master_mobile_feed_builder.py',
    '--snapshot', $root . '/' . ltrim($snapshot, '/'),
    '--output', $root . '/' . ltrim($output, '/'),
    '--supplier-code', $supplierCode,
    '--min-coverage', $minCoverage,
];
if ($baseFeed !== null && trim($baseFeed) !== '') {
    $cmd[] = '--base-feed';
    $cmd[] = str_starts_with($baseFeed, '/') ? $baseFeed : ($root . '/' . ltrim($baseFeed, '/'));
} else {
    $cmd[] = '--base-url';
    $cmd[] = $baseUrl;
}
if (mm_build_has_arg($args, '--zero-missing')) {
    $cmd[] = '--zero-missing';
}
if ($imageReplacements !== null && trim($imageReplacements) !== '') {
    $cmd[] = '--image-replacements';
    $cmd[] = str_starts_with($imageReplacements, '/') ? $imageReplacements : ($root . '/' . ltrim($imageReplacements, '/'));
}
if (!$noPurchasePrices && $purchasePrices !== null && trim($purchasePrices) !== '') {
    $cmd[] = '--purchase-prices';
    $cmd[] = str_starts_with($purchasePrices, '/') ? $purchasePrices : ($root . '/' . ltrim($purchasePrices, '/'));
}
if (!$verifyTls) {
    $cmd[] = '--insecure';
}
if ($upload) {
    $cmd[] = '--upload';
}

$env = $_ENV;
$ftp = (array)($cfg['remote_images']['ftp'] ?? []);
$env['MASTER_MOBILE_FTP_HOST'] = getenv('MASTER_MOBILE_FTP_HOST') ?: (string)($ftp['host'] ?? '');
$env['MASTER_MOBILE_FTP_USER'] = getenv('MASTER_MOBILE_FTP_USER') ?: (string)($ftp['user'] ?? '');
$env['MASTER_MOBILE_FTP_PASS'] = getenv('MASTER_MOBILE_FTP_PASS') ?: (string)($ftp['pass'] ?? '');
$env['MASTER_MOBILE_FTP_DIR'] = getenv('MASTER_MOBILE_FTP_DIR') ?: '/public_html/xml';
$env['MASTER_MOBILE_FTP_FILE'] = getenv('MASTER_MOBILE_FTP_FILE') ?: 'master_mobile_info.xml';
$env['MASTER_MOBILE_FTP_TLS'] = getenv('MASTER_MOBILE_FTP_TLS') ?: '0';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($cmd, $descriptors, $pipes, $root, $env, ['bypass_shell' => true]);
if (!is_resource($process)) {
    fwrite(STDERR, "Cannot start feed builder.\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);

if ($stdout !== '') {
    echo $stdout;
}
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}
exit($code);
