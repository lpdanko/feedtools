<?php
declare(strict_types=1);

const MMCI_BASE_URL = 'https://master-mobile.ru/';
const MMCI_DEFAULT_FEED_URL = 'https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml';
const MMCI_DEFAULT_SITEMAP_URL = 'https://master-mobile.ru/sitemap-iblock-17.php';
const MMCI_SEARCH_URL = 'https://master-mobile.ru/bitrix/services/main/ajax.php?mode=class&c=plastilin%3Aopen.search.title&action=search';
const MMCI_USER_AGENT = 'FeedTools MasterMobileCleanImages/1.0';

function mmci_storage_dir(array $cfg): string
{
    $base = rtrim((string)($cfg['paths']['storage_dir'] ?? (dirname(__DIR__) . '/storage')), '/\\');
    $dir = $base . '/master_mobile/clean_images';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку состояния: ' . $dir);
    }
    return $dir;
}

function mmci_state_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/state.json';
}

function mmci_lock_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/state.lock';
}

function mmci_cookie_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/master_mobile.cookies';
}

function mmci_results_csv_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/results.csv';
}

function mmci_found_csv_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/found.csv';
}

function mmci_replacements_csv_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/feed_picture_replacements.csv';
}

function mmci_replacements_json_path(array $cfg): string
{
    return mmci_storage_dir($cfg) . '/feed_picture_replacements.json';
}

function mmci_now(): string
{
    return gmdate('c');
}

function mmci_default_state(): array
{
    return [
        'version' => 2,
        'status' => 'empty',
        'created_at' => null,
        'updated_at' => null,
        'heartbeat_at' => null,
        'source' => [
            'mode' => '',
            'url' => '',
            'limit' => 0,
            'verify_tls' => false,
        ],
        'products' => [],
        'log' => [],
    ];
}

function mmci_read_state(array $cfg): array
{
    $path = mmci_state_path($cfg);
    if (!is_file($path)) {
        return mmci_default_state();
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) {
        return mmci_default_state();
    }
    $state += mmci_default_state();
    if (!is_array($state['source'] ?? null)) {
        $state['source'] = mmci_default_state()['source'];
    }
    if (!is_array($state['products'] ?? null)) {
        $state['products'] = [];
    }
    if (!is_array($state['log'] ?? null)) {
        $state['log'] = [];
    }
    return $state;
}

function mmci_write_state(array $cfg, array $state, bool $writeExports = true): void
{
    $state['updated_at'] = mmci_now();
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать состояние.');
    }
    $path = mmci_state_path($cfg);
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать состояние.');
    }
    @chmod($tmp, 0664);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось обновить файл состояния.');
    }
    if ($writeExports) {
        mmci_write_exports($cfg, $state);
    }
}

function mmci_with_lock(array $cfg, callable $fn)
{
    $lock = fopen(mmci_lock_path($cfg), 'c+');
    if (!$lock) {
        throw new RuntimeException('Не удалось открыть lock-файл.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Не удалось получить lock.');
        }
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function mmci_add_log(array &$state, string $message, string $level = 'info'): void
{
    $state['log'][] = [
        'ts' => mmci_now(),
        'level' => $level,
        'message' => $message,
    ];
    if (count($state['log']) > 250) {
        $state['log'] = array_slice($state['log'], -250);
    }
}

function mmci_status_counts(array $products): array
{
    $counts = [
        'pending' => 0,
        'processing' => 0,
        'found' => 0,
        'not_found' => 0,
        'no_vendor' => 0,
        'error' => 0,
    ];
    foreach ($products as $product) {
        $status = (string)($product['status'] ?? 'pending');
        if (!array_key_exists($status, $counts)) {
            $counts[$status] = 0;
        }
        $counts[$status]++;
    }
    return $counts;
}

function mmci_state_summary(array $state): array
{
    $products = (array)($state['products'] ?? []);
    $counts = mmci_status_counts($products);
    $total = count($products);
    $done = $counts['found'] + $counts['not_found'] + $counts['no_vendor'] + $counts['error'];
    $percent = $total > 0 ? round(($done / $total) * 100, 1) : 0.0;
    $recent = [];
    for ($i = count($products) - 1; $i >= 0 && count($recent) < 25; $i--) {
        $p = $products[$i];
        $status = (string)($p['status'] ?? '');
        if (in_array($status, ['found', 'not_found', 'no_vendor', 'error'], true)) {
            $recent[] = $p;
        }
    }

    return [
        'status' => (string)($state['status'] ?? 'empty'),
        'created_at' => $state['created_at'] ?? null,
        'updated_at' => $state['updated_at'] ?? null,
        'heartbeat_at' => $state['heartbeat_at'] ?? null,
        'source' => $state['source'] ?? [],
        'total' => $total,
        'done' => $done,
        'percent' => $percent,
        'counts' => $counts,
        'log' => array_slice((array)($state['log'] ?? []), -80),
        'recent' => $recent,
    ];
}

function mmci_terminal_status(string $status): bool
{
    return in_array($status, ['found', 'not_found', 'no_vendor', 'error'], true);
}

function mmci_requeue_stale_processing(array &$state, int $staleSeconds = 900): int
{
    $now = time();
    $count = 0;
    foreach ($state['products'] as &$product) {
        if (($product['status'] ?? '') !== 'processing') {
            continue;
        }
        $started = strtotime((string)($product['processing_at'] ?? '')) ?: 0;
        if ($started > 0 && ($now - $started) < $staleSeconds) {
            continue;
        }
        $product['status'] = 'pending';
        $product['processing_token'] = '';
        $product['processing_at'] = '';
        $count++;
    }
    unset($product);
    if ($count > 0) {
        mmci_add_log($state, 'Вернуло зависшие элементы в очередь: ' . $count);
    }
    return $count;
}

function mmci_http_text(string $url, array $options = []): string
{
    $response = mmci_http_request($url, $options + ['return_body' => true]);
    return (string)$response['body'];
}

function mmci_http_download(string $url, string $targetPath, array $options = []): array
{
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку загрузки.');
    }
    if (function_exists('curl_init')) {
        $fh = fopen($targetPath, 'wb');
        if (!$fh) {
            throw new RuntimeException('Не удалось открыть файл загрузки.');
        }
        $ch = curl_init($url);
        if (!$ch) {
            fclose($fh);
            throw new RuntimeException('Не удалось инициализировать curl.');
        }
        $headers = (array)($options['headers'] ?? []);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 120),
            CURLOPT_USERAGENT => (string)($options['user_agent'] ?? MMCI_USER_AGENT),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($options['verify_tls']),
            CURLOPT_SSL_VERIFYHOST => !empty($options['verify_tls']) ? 2 : 0,
        ]);
        if (!empty($options['cookie_file'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, (string)$options['cookie_file']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, (string)$options['cookie_file']);
        }
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        fclose($fh);
        if ($ok === false || $code < 200 || $code >= 400) {
            @unlink($targetPath);
            throw new RuntimeException('HTTP ' . $code . ' при загрузке ' . $url . ($error !== '' ? ': ' . $error : ''));
        }
        return ['status' => $code, 'path' => $targetPath, 'bytes' => filesize($targetPath) ?: 0];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int)($options['timeout'] ?? 120),
            'ignore_errors' => true,
            'header' => "User-Agent: " . (string)($options['user_agent'] ?? MMCI_USER_AGENT) . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => !empty($options['verify_tls']),
            'verify_peer_name' => !empty($options['verify_tls']),
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Не удалось загрузить ' . $url);
    }
    file_put_contents($targetPath, $body, LOCK_EX);
    return ['status' => 200, 'path' => $targetPath, 'bytes' => strlen($body)];
}

function mmci_http_request(string $url, array $options = []): array
{
    $method = strtoupper((string)($options['method'] ?? 'GET'));
    $postFields = $options['post_fields'] ?? null;
    $headers = (array)($options['headers'] ?? []);
    $headers[] = 'Accept: ' . (string)($options['accept'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Не удалось инициализировать curl.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 30),
            CURLOPT_USERAGENT => (string)($options['user_agent'] ?? MMCI_USER_AGENT),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($options['verify_tls']),
            CURLOPT_SSL_VERIFYHOST => !empty($options['verify_tls']) ? 2 : 0,
            CURLOPT_HEADER => false,
        ]);
        if (!empty($options['cookie_file'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, (string)$options['cookie_file']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, (string)$options['cookie_file']);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postFields) ? http_build_query($postFields) : (string)$postFields);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        if ($body === false || $code < 200 || $code >= 400) {
            throw new RuntimeException('HTTP ' . $code . ' при запросе ' . $url . ($error !== '' ? ': ' . $error : ''));
        }
        return ['status' => $code, 'content_type' => $contentType, 'body' => (string)$body];
    }

    $headerText = "User-Agent: " . (string)($options['user_agent'] ?? MMCI_USER_AGENT) . "\r\n";
    foreach ($headers as $header) {
        $headerText .= $header . "\r\n";
    }
    $http = [
        'method' => $method,
        'timeout' => (int)($options['timeout'] ?? 30),
        'ignore_errors' => true,
        'header' => $headerText,
    ];
    if ($method === 'POST') {
        $http['content'] = is_array($postFields) ? http_build_query($postFields) : (string)$postFields;
    }
    $ctx = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => !empty($options['verify_tls']),
            'verify_peer_name' => !empty($options['verify_tls']),
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Не удалось выполнить запрос ' . $url);
    }
    return ['status' => 200, 'content_type' => '', 'body' => $body];
}

function mmci_absolute_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    return rtrim(MMCI_BASE_URL, '/') . '/' . ltrim($url, '/');
}

function mmci_site_id_from_url(string $url): string
{
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $path = rtrim($path, '/');
    $last = basename($path);
    return preg_match('~^\d+$~', $last) ? $last : '';
}

function mmci_offer_base_id(string $offerId): string
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return '';
    }
    return trim(preg_replace('~\s*__\s*[^_]+$~', '', $offerId) ?? $offerId);
}

function mmci_offer_id_with_supplier(string $article, string $supplierCode = '24'): string
{
    $article = trim($article);
    if ($article === '' || $supplierCode === '') {
        return $article;
    }
    if (preg_match('~\s*__\s*' . preg_quote($supplierCode, '~') . '\s*$~i', $article)) {
        return $article;
    }
    return $article . '__' . $supplierCode;
}

function mmci_offer_param_value(SimpleXMLElement $offer, string $name): string
{
    foreach ($offer->param ?? [] as $param) {
        if (trim((string)($param['name'] ?? '')) === $name) {
            return trim((string)$param);
        }
    }
    return '';
}

function mmci_is_product_url(string $url): bool
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host !== '' && $host !== 'master-mobile.ru' && $host !== 'www.master-mobile.ru') {
        return false;
    }
    $path = rtrim((string)(parse_url($url, PHP_URL_PATH) ?? ''), '/');
    $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== ''));
    return count($parts) >= 3 && ($parts[0] ?? '') === 'catalog' && preg_match('~^\d+$~', (string)end($parts)) === 1;
}

function mmci_xml_root_name(string $path): string
{
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть XML: ' . $path);
    }
    try {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                return $reader->localName;
            }
        }
    } finally {
        $reader->close();
    }
    return '';
}

function mmci_xml_locs(string $path): array
{
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть XML: ' . $path);
    }
    $locs = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'loc') {
                $value = trim((string)$reader->readString());
                if ($value !== '') {
                    $locs[] = $value;
                }
            }
        }
    } finally {
        $reader->close();
    }
    return $locs;
}

function mmci_collect_from_sitemap(array $cfg, string $sitemapUrl, int $limit, bool $verifyTls): array
{
    $dir = mmci_storage_dir($cfg) . '/tmp';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать временную папку.');
    }

    $queue = [$sitemapUrl];
    $seenSitemaps = [];
    $seenProducts = [];
    $products = [];

    while ($queue) {
        $url = array_shift($queue);
        $url = trim((string)$url);
        if ($url === '' || isset($seenSitemaps[$url])) {
            continue;
        }
        $seenSitemaps[$url] = true;
        $tmp = $dir . '/sitemap_' . sha1($url) . '.xml';
        try {
            mmci_http_download($url, $tmp, ['timeout' => 180, 'verify_tls' => $verifyTls]);
            $root = mmci_xml_root_name($tmp);
            $locs = mmci_xml_locs($tmp);
        } finally {
            @unlink($tmp);
        }

        if ($root === 'sitemapindex') {
            foreach ($locs as $loc) {
                if (str_contains($loc, 'master-mobile.ru')) {
                    $queue[] = $loc;
                }
            }
            continue;
        }

        foreach ($locs as $loc) {
            if (!mmci_is_product_url($loc)) {
                continue;
            }
            $productUrl = rtrim(mmci_absolute_url($loc), '/') . '/';
            $siteId = mmci_site_id_from_url($productUrl);
            $key = $siteId !== '' ? $siteId : $productUrl;
            if (isset($seenProducts[$key])) {
                continue;
            }
            $seenProducts[$key] = true;
            $products[] = mmci_new_product($siteId, $productUrl, '', '');
            if ($limit > 0 && count($products) >= $limit) {
                return $products;
            }
        }
    }

    return $products;
}

function mmci_collect_from_feed(array $cfg, string $feedUrl, int $limit, bool $verifyTls): array
{
    $dir = mmci_storage_dir($cfg) . '/tmp';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать временную папку.');
    }
    $tmp = $dir . '/feed_' . sha1($feedUrl) . '.xml';
    try {
        mmci_http_download($feedUrl, $tmp, ['timeout' => 300, 'verify_tls' => $verifyTls]);

        $reader = new XMLReader();
        if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Не удалось открыть XML-фид.');
        }

        $seen = [];
        $products = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'offer') {
                    continue;
                }
                $xml = $reader->readOuterXML();
                if ($xml === '') {
                    continue;
                }
                $offer = @simplexml_load_string($xml);
                if (!$offer) {
                    continue;
                }
                $url = trim((string)($offer->url ?? ''));
                $vendorCode = trim((string)($offer->vendorCode ?? ''));
                $name = trim((string)($offer->name ?? ''));
                if ($url === '' || !str_contains($url, 'master-mobile.ru')) {
                    continue;
                }
                $productUrl = rtrim(mmci_absolute_url($url), '/') . '/';
                $siteId = mmci_site_id_from_url($productUrl);
                $offerId = trim((string)($offer['id'] ?? ''));
                $stickerArticle = mmci_offer_param_value($offer, 'Артикул стикер');
                if ($stickerArticle !== '') {
                    $vendorCode = $stickerArticle;
                    $offerId = mmci_offer_id_with_supplier($stickerArticle);
                } elseif ($vendorCode === '' && $offerId !== '') {
                    $vendorCode = mmci_offer_base_id($offerId);
                }
                $firstPicture = trim((string)($offer->picture ?? ''));
                $currentPictureUrl = $firstPicture !== '' ? mmci_absolute_url($firstPicture) : '';
                $key = $siteId !== '' ? $siteId : ($vendorCode !== '' ? $vendorCode : $productUrl);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $products[] = mmci_new_product($siteId, $productUrl, $vendorCode, $name, $offerId, $currentPictureUrl);
                if ($limit > 0 && count($products) >= $limit) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    } finally {
        @unlink($tmp);
    }

    return $products;
}

function mmci_new_product(string $siteId, string $url, string $vendorCode, string $title, string $offerId = '', string $currentPictureUrl = ''): array
{
    return [
        'offer_id' => $offerId,
        'offer_base_id' => mmci_offer_base_id($offerId),
        'site_id' => $siteId,
        'url' => $url,
        'vendor_code' => $vendorCode,
        'title' => $title,
        'current_picture_url' => $currentPictureUrl,
        'status' => 'pending',
        'clean_image_url' => '',
        'source_image_path' => '',
        'error' => '',
        'attempts' => 0,
        'processing_token' => '',
        'processing_at' => '',
        'updated_at' => '',
    ];
}

function mmci_init_job(array $cfg, string $mode, string $url, int $limit, bool $verifyTls): array
{
    $mode = $mode === 'sitemap' ? 'sitemap' : 'feed';
    $url = trim($url);
    if ($url === '') {
        $url = $mode === 'sitemap' ? MMCI_DEFAULT_SITEMAP_URL : MMCI_DEFAULT_FEED_URL;
    }
    if (!preg_match('~^https?://~i', $url)) {
        throw new RuntimeException('Источник должен быть http/https URL.');
    }
    $limit = max(0, $limit);

    $products = $mode === 'sitemap'
        ? mmci_collect_from_sitemap($cfg, $url, $limit, $verifyTls)
        : mmci_collect_from_feed($cfg, $url, $limit, $verifyTls);

    $state = mmci_default_state();
    $state['status'] = $products ? 'ready' : 'empty';
    $state['created_at'] = mmci_now();
    $state['updated_at'] = mmci_now();
    $state['source'] = [
        'mode' => $mode,
        'url' => $url,
        'limit' => $limit,
        'verify_tls' => $verifyTls,
    ];
    $state['products'] = $products;
    mmci_add_log($state, 'Создано задание: ' . count($products) . ' товаров, источник ' . $mode . '.');

    mmci_with_lock($cfg, static function () use ($cfg, $state): void {
        @unlink(mmci_cookie_path($cfg));
        mmci_write_state($cfg, $state);
    });

    return mmci_state_summary($state);
}

function mmci_extract_sessid(string $html): string
{
    foreach ([
        '~"bitrix_sessid"\s*:\s*"([0-9a-f]{16,64})"~i',
        "~'sessid'\\s*:\\s*BX\\.bitrix_sessid\\(\\)~i",
        '~"sessid"\s*:\s*"([0-9a-f]{16,64})"~i',
        "~'sessid'\\s*:\\s*'([0-9a-f]{16,64})'~i",
    ] as $pattern) {
        if (preg_match($pattern, $html, $m) && !empty($m[1])) {
            return (string)$m[1];
        }
    }
    return '';
}

function mmci_clean_text(string $value): string
{
    $value = preg_replace('~<script[\s\S]*?</script>~i', ' ', $value) ?? $value;
    $value = preg_replace('~<style[\s\S]*?</style>~i', ' ', $value) ?? $value;
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('~\s+~u', ' ', $value) ?? $value);
}

function mmci_extract_vendor_code(string $html): string
{
    $patterns = [
        '~mtitle-sticker--sku[\s\S]{0,900}?mtitle-sticker__text[^>]*>\s*([^<]+)~iu',
        '~Арт\.\s*:\s*</[^>]+>\s*<[^>]+>\s*([A-Za-zА-Яа-я0-9._/-]+)~iu',
        '~Арт\.\s*:\s*([A-Za-zА-Яа-я0-9._/-]+)~iu',
        '~Артикул[^0-9A-Za-zА-Яа-я]{0,40}([A-Za-zА-Яа-я0-9._/-]{3,})~iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $value = mmci_clean_text((string)$m[1]);
            $value = trim(preg_replace('~^(арт\.?|артикул)\s*:?\s*~iu', '', $value) ?? $value);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function mmci_extract_title(string $html): string
{
    foreach ([
        '~<h1[^>]*class="[^"]*card__name[^"]*"[^>]*>([\s\S]*?)</h1>~iu',
        '~<title[^>]*>([\s\S]*?)</title>~iu',
    ] as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $title = mmci_clean_text((string)$m[1]);
            if ($title !== '') {
                return $title;
            }
        }
    }
    return '';
}

function mmci_ensure_master_session(array $cfg, array &$runtime, bool $verifyTls): string
{
    if (!empty($runtime['sessid'])) {
        return (string)$runtime['sessid'];
    }
    $cookieFile = (string)($runtime['cookie_file'] ?? mmci_cookie_path($cfg));
    $html = mmci_http_text(MMCI_BASE_URL, [
        'cookie_file' => $cookieFile,
        'verify_tls' => $verifyTls,
        'timeout' => 30,
    ]);
    $sessid = mmci_extract_sessid($html);
    if ($sessid === '') {
        throw new RuntimeException('Не удалось получить Bitrix sessid.');
    }
    $runtime['sessid'] = $sessid;
    $runtime['cookie_file'] = $cookieFile;
    return $sessid;
}

function mmci_fetch_product_page(array $cfg, string $url, array &$runtime, bool $verifyTls): array
{
    $cookieFile = (string)($runtime['cookie_file'] ?? mmci_cookie_path($cfg));
    $html = mmci_http_text($url, [
        'cookie_file' => $cookieFile,
        'verify_tls' => $verifyTls,
        'timeout' => 30,
    ]);
    $sessid = mmci_extract_sessid($html);
    if ($sessid !== '') {
        $runtime['sessid'] = $sessid;
    }
    $runtime['cookie_file'] = $cookieFile;
    return [
        'vendor_code' => mmci_extract_vendor_code($html),
        'title' => mmci_extract_title($html),
    ];
}

function mmci_lookup_clean_image(array $cfg, string $vendorCode, string $siteId, array &$runtime, bool $verifyTls): array
{
    $lastError = 'Search API вернул неуспешный ответ.';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            usleep(350000 * $attempt);
            unset($runtime['sessid']);
            if (!empty($runtime['cookie_file'])) {
                @unlink((string)$runtime['cookie_file']);
            }
        }

        try {
            $sessid = mmci_ensure_master_session($cfg, $runtime, $verifyTls);
            $body = mmci_http_text(MMCI_SEARCH_URL, [
                'method' => 'POST',
                'post_fields' => [
                    'q' => $vendorCode,
                    'sessid' => $sessid,
                ],
                'headers' => [
                    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With: XMLHttpRequest',
                    'Origin: ' . rtrim(MMCI_BASE_URL, '/'),
                    'Referer: ' . MMCI_BASE_URL,
                ],
                'accept' => 'application/json, text/javascript, */*; q=0.01',
                'cookie_file' => (string)($runtime['cookie_file'] ?? mmci_cookie_path($cfg)),
                'verify_tls' => $verifyTls,
                'timeout' => 30,
            ]);
        } catch (Throwable $e) {
            $lastError = 'Search API недоступен: ' . $e->getMessage();
            continue;
        }

        $payload = json_decode($body, true);
        if (is_array($payload) && ($payload['status'] ?? '') === 'success') {
            break;
        }

        $status = is_array($payload) ? trim((string)($payload['status'] ?? '')) : '';
        $message = '';
        if (is_array($payload)) {
            foreach (['message', 'error', 'errors'] as $field) {
                if (!empty($payload[$field])) {
                    $message = is_scalar($payload[$field])
                        ? (string)$payload[$field]
                        : json_encode($payload[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                }
            }
        }
        $messagePreview = function_exists('mb_substr')
            ? mb_substr($message, 0, 180, 'UTF-8')
            : substr($message, 0, 180);
        $lastError = 'Search API вернул неуспешный ответ'
            . ($status !== '' ? ' (status=' . $status . ')' : '')
            . ($message !== '' ? ': ' . $messagePreview : '.');
    }
    if (!isset($payload) || !is_array($payload) || ($payload['status'] ?? '') !== 'success') {
        throw new RuntimeException($lastError);
    }

    $items = $payload['data']['products']['items'] ?? [];
    if (!is_array($items) || !$items) {
        return ['found' => false, 'error' => 'В поиске нет товара по артикулу.'];
    }

    $best = null;
    $bestScore = -1;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $source = $item['_source'] ?? [];
        if (!is_array($source)) {
            continue;
        }
        $article = trim((string)($source['article'] ?? ''));
        $id = trim((string)($source['id'] ?? ($item['_id'] ?? '')));
        $score = 0;
        if ($article !== '' && strcasecmp($article, $vendorCode) === 0) {
            $score += 10;
        }
        if ($siteId !== '' && $id === $siteId) {
            $score += 5;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $source;
        }
    }

    if (!is_array($best) || $bestScore <= 0) {
        return ['found' => false, 'error' => 'Точный товар не найден в Search API.'];
    }

    $img = trim((string)($best['img'] ?? ''));
    if ($img === '') {
        return ['found' => false, 'error' => 'Search API нашел товар, но не отдал img.'];
    }
    if (str_contains($img, 'ram.watermark')) {
        return ['found' => false, 'error' => 'Search API отдал только watermarked img.'];
    }

    return [
        'found' => true,
        'image_url' => mmci_absolute_url($img),
        'image_path' => $img,
        'title' => trim((string)($best['name'] ?? '')),
    ];
}

function mmci_process_product(array $cfg, array $product, array &$runtime, bool $verifyTls): array
{
    $siteId = trim((string)($product['site_id'] ?? ''));
    $url = trim((string)($product['url'] ?? ''));
    $vendorCode = trim((string)($product['vendor_code'] ?? ''));
    $title = trim((string)($product['title'] ?? ''));

    if ($vendorCode === '' && $url !== '') {
        $page = mmci_fetch_product_page($cfg, $url, $runtime, $verifyTls);
        $vendorCode = trim((string)$page['vendor_code']);
        if ($title === '') {
            $title = trim((string)$page['title']);
        }
    }

    if ($vendorCode === '') {
        return [
            'status' => 'no_vendor',
            'vendor_code' => '',
            'title' => $title,
            'clean_image_url' => '',
            'source_image_path' => '',
            'error' => 'Не удалось определить артикул.',
        ];
    }

    $lookup = mmci_lookup_clean_image($cfg, $vendorCode, $siteId, $runtime, $verifyTls);
    if (!empty($lookup['found'])) {
        return [
            'status' => 'found',
            'vendor_code' => $vendorCode,
            'title' => $title !== '' ? $title : (string)($lookup['title'] ?? ''),
            'clean_image_url' => (string)$lookup['image_url'],
            'source_image_path' => (string)$lookup['image_path'],
            'error' => '',
        ];
    }

    return [
        'status' => 'not_found',
        'vendor_code' => $vendorCode,
        'title' => $title,
        'clean_image_url' => '',
        'source_image_path' => '',
        'error' => (string)($lookup['error'] ?? 'Чистая картинка не найдена.'),
    ];
}

function mmci_step(array $cfg, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    $token = bin2hex(random_bytes(8));
    $selected = mmci_with_lock($cfg, static function () use ($cfg, $limit, $token): array {
        $state = mmci_read_state($cfg);
        mmci_requeue_stale_processing($state);
        if (!in_array((string)($state['status'] ?? ''), ['ready', 'running'], true)) {
            return ['state' => $state, 'batch' => []];
        }
        $state['status'] = 'running';
        $state['heartbeat_at'] = mmci_now();
        $batch = [];
        foreach ($state['products'] as $idx => &$product) {
            if (($product['status'] ?? 'pending') !== 'pending') {
                continue;
            }
            $product['status'] = 'processing';
            $product['processing_token'] = $token;
            $product['processing_at'] = mmci_now();
            $product['attempts'] = (int)($product['attempts'] ?? 0) + 1;
            $batch[] = ['index' => $idx, 'product' => $product];
            if (count($batch) >= $limit) {
                break;
            }
        }
        unset($product);
        if (!$batch) {
            $counts = mmci_status_counts((array)$state['products']);
            if (($counts['pending'] + $counts['processing']) === 0) {
                $state['status'] = 'done';
                mmci_add_log($state, 'Задание завершено.');
            }
        }
        mmci_write_state($cfg, $state, false);
        return ['state' => $state, 'batch' => $batch];
    });

    $batch = (array)($selected['batch'] ?? []);
    if (!$batch) {
        return mmci_state_summary((array)($selected['state'] ?? mmci_read_state($cfg)));
    }

    $stateForOptions = (array)($selected['state'] ?? []);
    $verifyTls = !empty($stateForOptions['source']['verify_tls']);
    $runtime = [
        'cookie_file' => mmci_cookie_path($cfg),
    ];
    $results = [];
    foreach ($batch as $item) {
        $idx = (int)$item['index'];
        $product = (array)$item['product'];
        try {
            $result = mmci_process_product($cfg, $product, $runtime, $verifyTls);
        } catch (Throwable $e) {
            $result = [
                'status' => 'error',
                'vendor_code' => (string)($product['vendor_code'] ?? ''),
                'title' => (string)($product['title'] ?? ''),
                'clean_image_url' => '',
                'source_image_path' => '',
                'error' => $e->getMessage(),
            ];
        }
        $results[] = ['index' => $idx, 'result' => $result];
    }

    return mmci_with_lock($cfg, static function () use ($cfg, $results, $token): array {
        $state = mmci_read_state($cfg);
        foreach ($results as $row) {
            $idx = (int)$row['index'];
            if (!isset($state['products'][$idx])) {
                continue;
            }
            $product = &$state['products'][$idx];
            if (($product['processing_token'] ?? '') !== $token) {
                unset($product);
                continue;
            }
            $result = (array)$row['result'];
            $status = (string)($result['status'] ?? 'error');
            if (!in_array($status, ['found', 'not_found', 'no_vendor', 'error'], true)) {
                $status = 'error';
            }
            foreach (['vendor_code', 'title', 'clean_image_url', 'source_image_path', 'error'] as $field) {
                if (array_key_exists($field, $result)) {
                    $product[$field] = (string)$result[$field];
                }
            }
            $product['status'] = $status;
            $product['processing_token'] = '';
            $product['processing_at'] = '';
            $product['updated_at'] = mmci_now();
            unset($product);
        }

        $state['heartbeat_at'] = mmci_now();
        $counts = mmci_status_counts((array)$state['products']);
        if (($counts['pending'] + $counts['processing']) === 0) {
            $state['status'] = 'done';
            mmci_add_log($state, 'Задание завершено: найдено ' . $counts['found'] . ' чистых ссылок.');
        } else {
            $state['status'] = 'running';
        }
        mmci_write_state($cfg, $state, ($state['status'] === 'done'));
        return mmci_state_summary($state);
    });
}

function mmci_set_status(array $cfg, string $status): array
{
    return mmci_with_lock($cfg, static function () use ($cfg, $status): array {
        $state = mmci_read_state($cfg);
        if ($status === 'paused') {
            $state['status'] = 'paused';
            mmci_add_log($state, 'Задание поставлено на паузу.');
        } elseif ($status === 'running') {
            mmci_requeue_stale_processing($state, 0);
            if (($state['status'] ?? '') !== 'done') {
                $state['status'] = 'running';
                mmci_add_log($state, 'Задание продолжено.');
            }
        }
        mmci_write_state($cfg, $state);
        return mmci_state_summary($state);
    });
}

function mmci_retry_errors(array $cfg): array
{
    return mmci_with_lock($cfg, static function () use ($cfg): array {
        $state = mmci_read_state($cfg);
        $count = 0;
        foreach ($state['products'] as &$product) {
            if (in_array((string)($product['status'] ?? ''), ['error', 'not_found', 'no_vendor'], true)) {
                $product['status'] = 'pending';
                $product['processing_token'] = '';
                $product['processing_at'] = '';
                $count++;
            }
        }
        unset($product);
        if ($count > 0) {
            $state['status'] = 'ready';
            mmci_add_log($state, 'Возвращено в очередь для повтора: ' . $count . '.');
        }
        mmci_write_state($cfg, $state);
        return mmci_state_summary($state);
    });
}

function mmci_reset(array $cfg): array
{
    return mmci_with_lock($cfg, static function () use ($cfg): array {
        foreach ([
            mmci_state_path($cfg),
            mmci_results_csv_path($cfg),
            mmci_found_csv_path($cfg),
            mmci_replacements_csv_path($cfg),
            mmci_replacements_json_path($cfg),
            mmci_cookie_path($cfg),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $tmpDir = mmci_storage_dir($cfg) . '/tmp';
        foreach (glob($tmpDir . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return mmci_state_summary(mmci_default_state());
    });
}

function mmci_csv_escape($fh, array $row): void
{
    fputcsv($fh, $row, ',', '"', '\\', "\n");
}

function mmci_write_exports(array $cfg, array $state): void
{
    $columns = [
        'site_id',
        'vendor_code',
        'product_url',
        'clean_image_url',
        'status',
        'title',
        'error',
        'updated_at',
        'offer_id',
        'offer_base_id',
        'current_picture_url',
        'source_image_path',
    ];
    $replacementColumns = [
        'offer_id',
        'offer_base_id',
        'vendor_code',
        'site_id',
        'product_url',
        'current_picture_url',
        'replacement_picture_url',
        'source_image_path',
        'title',
        'updated_at',
    ];
    $all = fopen(mmci_results_csv_path($cfg), 'wb');
    $found = fopen(mmci_found_csv_path($cfg), 'wb');
    $replacements = fopen(mmci_replacements_csv_path($cfg), 'wb');
    if (!$all || !$found || !$replacements) {
        if ($all) fclose($all);
        if ($found) fclose($found);
        if ($replacements) fclose($replacements);
        throw new RuntimeException('Не удалось записать CSV.');
    }
    mmci_csv_escape($all, $columns);
    mmci_csv_escape($found, $columns);
    mmci_csv_escape($replacements, $replacementColumns);
    $replacementItems = [];
    foreach ((array)($state['products'] ?? []) as $product) {
        $row = [
            (string)($product['site_id'] ?? ''),
            (string)($product['vendor_code'] ?? ''),
            (string)($product['url'] ?? ''),
            (string)($product['clean_image_url'] ?? ''),
            (string)($product['status'] ?? ''),
            (string)($product['title'] ?? ''),
            (string)($product['error'] ?? ''),
            (string)($product['updated_at'] ?? ''),
            (string)($product['offer_id'] ?? ''),
            (string)($product['offer_base_id'] ?? mmci_offer_base_id((string)($product['offer_id'] ?? ''))),
            (string)($product['current_picture_url'] ?? ''),
            (string)($product['source_image_path'] ?? ''),
        ];
        mmci_csv_escape($all, $row);
        if (($product['status'] ?? '') === 'found') {
            mmci_csv_escape($found, $row);
            $replacementItem = [
                'offer_id' => (string)($product['offer_id'] ?? ''),
                'offer_base_id' => (string)($product['offer_base_id'] ?? mmci_offer_base_id((string)($product['offer_id'] ?? ''))),
                'vendor_code' => (string)($product['vendor_code'] ?? ''),
                'site_id' => (string)($product['site_id'] ?? ''),
                'product_url' => (string)($product['url'] ?? ''),
                'current_picture_url' => (string)($product['current_picture_url'] ?? ''),
                'replacement_picture_url' => (string)($product['clean_image_url'] ?? ''),
                'source_image_path' => (string)($product['source_image_path'] ?? ''),
                'title' => (string)($product['title'] ?? ''),
                'updated_at' => (string)($product['updated_at'] ?? ''),
            ];
            mmci_csv_escape($replacements, array_values($replacementItem));
            $replacementItems[] = $replacementItem;
        }
    }
    fclose($all);
    fclose($found);
    fclose($replacements);

    $json = json_encode([
        'generated_at' => mmci_now(),
        'source' => $state['source'] ?? [],
        'count' => count($replacementItems),
        'items' => $replacementItems,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(mmci_replacements_json_path($cfg), $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать JSON замен.');
    }

    @chmod(mmci_results_csv_path($cfg), 0664);
    @chmod(mmci_found_csv_path($cfg), 0664);
    @chmod(mmci_replacements_csv_path($cfg), 0664);
    @chmod(mmci_replacements_json_path($cfg), 0664);
}

function mmci_download_csv(array $cfg, bool $foundOnly): void
{
    $path = $foundOnly ? mmci_found_csv_path($cfg) : mmci_results_csv_path($cfg);
    if (!is_file($path)) {
        $state = mmci_read_state($cfg);
        mmci_write_exports($cfg, $state);
    }
    $name = $foundOnly ? 'master_mobile_clean_images_found.csv' : 'master_mobile_clean_images_all.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path);
    exit;
}

function mmci_download_replacements_csv(array $cfg): void
{
    if (!is_file(mmci_replacements_csv_path($cfg))) {
        mmci_write_exports($cfg, mmci_read_state($cfg));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_mobile_feed_picture_replacements.csv"');
    readfile(mmci_replacements_csv_path($cfg));
    exit;
}

function mmci_download_replacements_json(array $cfg): void
{
    if (!is_file(mmci_replacements_json_path($cfg))) {
        mmci_write_exports($cfg, mmci_read_state($cfg));
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_mobile_feed_picture_replacements.json"');
    readfile(mmci_replacements_json_path($cfg));
    exit;
}
