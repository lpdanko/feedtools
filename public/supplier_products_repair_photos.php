<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/supplier_products_marketplace_import.php';

header('Content-Type: application/json; charset=utf-8');

function sp_photo_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sp_photo_payload(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    return is_array($payload) ? $payload : $_POST;
}

function sp_photo_lc(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function sp_photo_norm_header(string $s): string
{
    $s = str_replace(["*", "\xC2\xA0", "\xE2\x80\x8B"], [' ', ' ', ' '], $s);
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return sp_photo_lc(trim((string)$s));
}

function sp_photo_split_pictures(string $s): array
{
    $s = trim((string)$s);
    if ($s === '') {
        return [];
    }
    $s = str_replace(["\r\n", "\r", "\n", "\t"], ';', $s);
    $parts = preg_split('/\s*[;\s]\s*/u', $s) ?: [];
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || isset($seen[$part])) {
            continue;
        }
        $seen[$part] = true;
        $out[] = $part;
    }
    return $out;
}

function sp_photo_cell_string($sheet, int $col, int $row): string
{
    $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
    $value = $sheet->getCell($coord)->getFormattedValue();
    return trim(preg_replace('/\s+/u', ' ', (string)$value));
}

function sp_photo_import_upload_storage_dir(array $cfg): string
{
    $uploadsDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? (dirname(__DIR__) . '/storage/uploads')), '/\\');
    return $uploadsDir . '/supplier_import_sources';
}

function sp_photo_stored_source_path(string $token, array $cfg): string
{
    $token = basename(trim($token));
    if ($token === '' || !preg_match('~^[A-Za-z0-9_.-]+$~', $token)) {
        throw new RuntimeException('Сохранённый источник импорта не найден.');
    }
    $dir = sp_photo_import_upload_storage_dir($cfg);
    $path = $dir . '/' . $token;
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if (!$realDir || !$realPath || strpos($realPath, $realDir) !== 0 || !is_file($realPath)) {
        throw new RuntimeException('Сохранённый источник импорта не найден.');
    }
    return $realPath;
}

function sp_photo_job_dir(array $cfg): string
{
    $uploadsDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? (dirname(__DIR__) . '/storage/uploads')), '/\\');
    $dir = $uploadsDir . '/supplier_photo_repair_jobs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку для задания исправления фото.');
    }
    return $dir;
}

function sp_photo_job_path(string $token, array $cfg): string
{
    $token = basename(trim($token));
    if ($token === '' || !preg_match('~^[A-Za-z0-9]+$~', $token)) {
        throw new RuntimeException('Задание исправления фото не найдено.');
    }
    return sp_photo_job_dir($cfg) . '/' . $token . '.json';
}

function sp_photo_write_job(string $token, array $job, array $cfg): void
{
    $path = sp_photo_job_path($token, $cfg);
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить прогресс исправления фото.');
    }
    @chmod($path, 0664);
}

function sp_photo_read_job(string $token, array $cfg): array
{
    $path = sp_photo_job_path($token, $cfg);
    if (!is_file($path)) {
        throw new RuntimeException('Задание исправления фото не найдено.');
    }
    $job = json_decode((string)file_get_contents($path), true);
    if (!is_array($job)) {
        throw new RuntimeException('Не удалось прочитать задание исправления фото.');
    }
    return $job;
}

function sp_photo_cleanup_old_jobs(array $cfg): void
{
    $dir = sp_photo_job_dir($cfg);
    $cutoff = time() - 86400;
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_file($path) && (int)@filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

function sp_photo_add_source_map(array &$map, string $offerId, array $pictures, string $supplierCode): void
{
    $offerId = trim($offerId);
    $pictures = supplier_products_normalize_picture_urls($pictures);
    if ($offerId === '' || !$pictures) {
        return;
    }
    $map[$offerId] = $pictures;
    $coded = suppliers_apply_supplier_code($offerId, $supplierCode);
    if ($coded !== '') {
        $map[$coded] = $pictures;
    }
}

function sp_photo_source_map_from_feed(int $supplierId, string $path, string $supplierCode, array $cfg): array
{
    $source = supplier_products_source_records_from_feed_path($supplierId, $path, $cfg);
    $map = [];
    foreach ((array)($source['records'] ?? []) as $record) {
        $pictures = supplier_products_normalize_picture_urls((array)($record['parsed']['pictures'] ?? []));
        sp_photo_add_source_map($map, (string)($record['offer_id'] ?? ''), $pictures, $supplierCode);
        sp_photo_add_source_map($map, (string)($record['raw_offer_id'] ?? ''), $pictures, $supplierCode);
    }
    return [
        'map' => $map,
        'source_offers' => (int)($source['offers_count'] ?? 0),
    ];
}

function sp_photo_source_map_from_xlsx(string $path, string $supplierCode): array
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Не найден vendor/autoload.php для чтения XLSX.');
    }
    require_once $autoload;

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $layouts = [
        ['sheet' => $spreadsheet->getSheetByName('Товары') ?: $spreadsheet->getActiveSheet(), 'header_row' => 1, 'article' => ['артикул продавца'], 'pictures' => ['фото']],
        ['sheet' => $spreadsheet->getSheetByName('Шаблон') ?: $spreadsheet->getActiveSheet(), 'header_row' => 2, 'article' => ['артикул', 'партномер'], 'pictures' => ['ссылка на главное фото', 'ссылки на дополнительные фото']],
        ['sheet' => $spreadsheet->getActiveSheet(), 'header_row' => 1, 'article' => ['артикул продавца', 'артикул', 'партномер'], 'pictures' => ['фото', 'ссылка на главное фото', 'ссылки на дополнительные фото']],
    ];

    foreach ($layouts as $layout) {
        $sheet = $layout['sheet'];
        $headerRow = (int)$layout['header_row'];
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $highestRow = (int)$sheet->getHighestRow();
        $articleCol = null;
        $pictureCols = [];
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $header = sp_photo_norm_header(sp_photo_cell_string($sheet, $col, $headerRow));
            if ($header === '') {
                continue;
            }
            foreach ((array)$layout['article'] as $articleHeader) {
                if ($header === sp_photo_norm_header($articleHeader)) {
                    $articleCol = $col;
                }
            }
            foreach ((array)$layout['pictures'] as $pictureHeader) {
                if ($header === sp_photo_norm_header($pictureHeader)) {
                    $pictureCols[] = $col;
                }
            }
        }
        $pictureCols = array_values(array_unique($pictureCols));
        if (!$articleCol || !$pictureCols) {
            continue;
        }

        $map = [];
        $sourceOffers = 0;
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $offerId = sp_photo_cell_string($sheet, (int)$articleCol, $row);
            if ($offerId === '') {
                continue;
            }
            $sourceOffers++;
            $pictures = [];
            foreach ($pictureCols as $col) {
                foreach (sp_photo_split_pictures(sp_photo_cell_string($sheet, (int)$col, $row)) as $picture) {
                    $pictures[] = $picture;
                }
            }
            sp_photo_add_source_map($map, $offerId, array_values(array_unique($pictures)), $supplierCode);
        }

        return [
            'map' => $map,
            'source_offers' => $sourceOffers,
        ];
    }

    throw new RuntimeException('В XLSX не найдены колонки артикула и фото.');
}

function sp_photo_url_works(string $url, array &$cache): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (isset($cache[$url])) {
        return (bool)$cache[$url];
    }
    if (str_starts_with($url, '/supplier_product_image.php?')) {
        $cache[$url] = true;
        return true;
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $cache[$url] = false;
        return false;
    }

    $check = static function (bool $head) use ($url): bool {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true, 'header' => "Range: bytes=0-1023\r\nUser-Agent: FeedTools/1.0\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $fh = @fopen($url, 'rb', false, $ctx);
            if (!is_resource($fh)) {
                return false;
            }
            @fclose($fh);
            return true;
        }
        $ch = curl_init($url);
        if (!$ch) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'FeedTools/1.0',
            CURLOPT_NOBODY => $head,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (!$head) {
            curl_setopt($ch, CURLOPT_RANGE, '0-1023');
        }
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        return $err === 0 && $code >= 200 && $code < 400;
    };

    $ok = $check(true) || $check(false);
    $cache[$url] = $ok;
    return $ok;
}

function sp_photo_repair_pictures(array $currentPictures, array $sourcePictures, array &$urlCache, bool $addPhotos = false, bool $syncSource = false): array
{
    $currentPictures = supplier_products_normalize_picture_urls($currentPictures);
    $sourcePictures = supplier_products_normalize_picture_urls($sourcePictures);
    $stats = [
        'links_checked' => 0,
        'broken_links' => 0,
        'replaced_links' => 0,
        'removed_links' => 0,
        'added_links' => 0,
        'synced_from_source' => 0,
        'no_replacement' => 0,
    ];

    if ($syncSource && $sourcePictures) {
        $targetPictures = [];
        $seen = [];
        foreach ($sourcePictures as $picture) {
            $picture = trim((string)$picture);
            if ($picture === '' || isset($seen[$picture])) {
                continue;
            }
            $seen[$picture] = true;
            $targetPictures[] = $picture;
        }
        if ($addPhotos) {
            foreach ($currentPictures as $picture) {
                $picture = trim((string)$picture);
                if ($picture === '' || isset($seen[$picture])) {
                    continue;
                }
                $seen[$picture] = true;
                $targetPictures[] = $picture;
            }
        }

        $nextPictures = [];
        foreach ($targetPictures as $picture) {
            $stats['links_checked']++;
            if (sp_photo_url_works($picture, $urlCache)) {
                $nextPictures[] = $picture;
            } else {
                $stats['broken_links']++;
                $stats['removed_links']++;
            }
        }
        if (!$nextPictures) {
            $stats['no_replacement']++;
            return [
                'pictures' => $currentPictures,
                'changed' => false,
                'stats' => $stats,
            ];
        }

        $changed = $nextPictures !== $currentPictures;
        if ($changed) {
            $stats['synced_from_source'] = 1;
            if (count($nextPictures) > count($currentPictures)) {
                $stats['added_links'] += count($nextPictures) - count($currentPictures);
            } elseif (count($nextPictures) < count($currentPictures)) {
                $stats['removed_links'] += count($currentPictures) - count($nextPictures);
            } else {
                $stats['replaced_links']++;
            }
        }

        return [
            'pictures' => array_values($nextPictures),
            'changed' => $changed,
            'stats' => $stats,
        ];
    }

    $nextPictures = [];
    $used = [];

    foreach ($currentPictures as $index => $picture) {
        $stats['links_checked']++;
        if (sp_photo_url_works($picture, $urlCache)) {
            $nextPictures[] = $picture;
            $used[$picture] = true;
            continue;
        }
        $stats['broken_links']++;
        $replacement = '';
        $candidates = [];
        if (isset($sourcePictures[$index])) {
            $candidates[] = $sourcePictures[$index];
        }
        foreach ($sourcePictures as $candidate) {
            $candidates[] = $candidate;
        }
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || $candidate === $picture || isset($used[$candidate])) {
                continue;
            }
            if (sp_photo_url_works($candidate, $urlCache)) {
                $replacement = $candidate;
                break;
            }
        }
        if ($replacement === '') {
            $stats['no_replacement']++;
            $stats['removed_links']++;
            continue;
        }
        $used[$replacement] = true;
        $nextPictures[] = $replacement;
        $stats['replaced_links']++;
    }

    if ($addPhotos) {
        foreach ($sourcePictures as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || isset($used[$candidate])) {
                continue;
            }
            if (!sp_photo_url_works($candidate, $urlCache)) {
                continue;
            }
            $nextPictures[] = $candidate;
            $used[$candidate] = true;
            $stats['added_links']++;
        }
    }

    return [
        'pictures' => array_values($nextPictures),
        'changed' => $nextPictures !== $currentPictures,
        'stats' => $stats,
    ];
}

function sp_photo_product_source_pictures(array $product, array $sourceMap, string $supplierCode): array
{
    $offerId = trim((string)($product['offer_id'] ?? ''));
    foreach ([$offerId, suppliers_apply_supplier_code($offerId, $supplierCode)] as $key) {
        if ($key !== '' && isset($sourceMap[$key]) && is_array($sourceMap[$key])) {
            return $sourceMap[$key];
        }
    }
    return [];
}

$payload = sp_photo_payload();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sp_photo_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $action = trim((string)($payload['action'] ?? ''));
    if ($action === 'start') {
        sp_photo_cleanup_old_jobs($cfg);
        $datasetId = (int)($payload['id'] ?? 0);
        $supplierId = (int)($payload['supplier_id'] ?? 0);
        if ($supplierId <= 0 && $datasetId > 0) {
            $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
        }
        if ($supplierId <= 0) {
            throw new RuntimeException('Поставщик не найден.');
        }
        $supplier = suppliers_get($supplierId, $cfg);
        if (!is_array($supplier)) {
            throw new RuntimeException('Поставщик не найден.');
        }
        $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));

        $scope = trim((string)($payload['scope'] ?? 'all'));
        if (!in_array($scope, ['all', 'selected'], true)) {
            $scope = 'all';
        }
        $selectedIds = supplier_products_selected_offer_id_set((array)($payload['selected_offer_ids'] ?? []), $supplierCode);
        if ($scope === 'selected' && !$selectedIds) {
            throw new RuntimeException('Для исправления только выбранных товаров сначала выбери товары в таблице.');
        }
        $addPhotos = !empty($payload['add_photos']);
        $syncSource = array_key_exists('sync_source', $payload) ? !empty($payload['sync_source']) : true;

        $sourceMode = trim((string)($payload['source'] ?? ''));
        if ($sourceMode === '' || !in_array($sourceMode, supplier_marketplace_import_sources(), true)) {
            throw new RuntimeException('Выбери источник импорта.');
        }
        $cleanupPaths = [];
        $sourcePath = '';

        if ($sourceMode === 'supplier_feed') {
            $sourceUrl = trim((string)($supplier['feed_url'] ?? ''));
            if ($sourceUrl === '') {
                throw new RuntimeException('У поставщика не заполнена ссылка на источник данных.');
            }
            $download = ozon_price_feed_fetch_remote_xml($sourceUrl, (int)($cfg['limits']['max_upload_bytes'] ?? 200_000_000));
            $sourcePath = (string)$download['path'];
            $cleanupPaths[] = $sourcePath;
        } elseif ($sourceMode === 'url') {
            $sourceUrl = trim((string)($payload['feed_url'] ?? ''));
            if ($sourceUrl === '') {
                throw new RuntimeException('Укажи ссылку на фид.');
            }
            $download = ozon_price_feed_fetch_remote_xml($sourceUrl, (int)($cfg['limits']['max_upload_bytes'] ?? 200_000_000));
            $sourcePath = (string)$download['path'];
            $cleanupPaths[] = $sourcePath;
        } elseif ($sourceMode === 'upload') {
            $sourcePath = sp_photo_stored_source_path((string)($payload['stored_file'] ?? ''), $cfg);
        } elseif (supplier_marketplace_import_is_marketplace_source($sourceMode)) {
            $connectionId = $sourceMode === 'ozon_account'
                ? (int)($payload['ozon_connection_id'] ?? 0)
                : (int)($payload['wb_connection_id'] ?? 0);
            $source = supplier_marketplace_import_source_data(
                $datasetId,
                $supplierId,
                $sourceMode,
                $connectionId,
                $cfg,
                [
                    'scope' => $scope,
                    'selected_offer_ids' => (array)($payload['selected_offer_ids'] ?? []),
                ]
            );
            $map = [];
            foreach ((array)($source['records'] ?? []) as $record) {
                $pictures = supplier_products_normalize_picture_urls((array)($record['parsed']['pictures'] ?? []));
                sp_photo_add_source_map($map, (string)($record['offer_id'] ?? ''), $pictures, $supplierCode);
                sp_photo_add_source_map($map, (string)($record['raw_offer_id'] ?? ''), $pictures, $supplierCode);
            }
            $sourceInfo = [
                'map' => $map,
                'source_offers' => (int)($source['offers_count'] ?? count((array)($source['records'] ?? []))),
            ];
        }

        if (!isset($sourceInfo)) {
            $extName = trim((string)($payload['stored_file_name'] ?? ''));
            if ($extName === '') {
                $extName = $sourcePath;
            }
            $ext = sp_photo_lc(pathinfo($extName, PATHINFO_EXTENSION));
            if ($ext === 'xlsx') {
                $sourceInfo = sp_photo_source_map_from_xlsx($sourcePath, $supplierCode);
            } else {
                $sourceInfo = sp_photo_source_map_from_feed($supplierId, $sourcePath, $supplierCode, $cfg);
            }
        }
        foreach ($cleanupPaths as $cleanupPath) {
            if (is_string($cleanupPath) && $cleanupPath !== '' && is_file($cleanupPath)) {
                @unlink($cleanupPath);
            }
        }

        $sourceMap = (array)($sourceInfo['map'] ?? []);
        $productsStmt = db()->prepare("
            SELECT id, offer_id, pictures_json
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $productsStmt->execute([$supplierId]);
        $products = [];
        while ($product = $productsStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($scope === 'selected' && !supplier_products_existing_product_is_selected($product, $selectedIds, $supplierCode)) {
                continue;
            }
            $products[] = [
                'id' => (int)($product['id'] ?? 0),
                'offer_id' => (string)($product['offer_id'] ?? ''),
            ];
        }

        $token = bin2hex(random_bytes(16));
        $job = [
            'token' => $token,
            'supplier_id' => $supplierId,
            'supplier_code' => $supplierCode,
            'add_photos' => $addPhotos,
            'sync_source' => $syncSource,
            'source_map' => $sourceMap,
            'source_offers' => (int)($sourceInfo['source_offers'] ?? 0),
            'products' => $products,
            'offset' => 0,
            'url_cache' => [],
            'stats' => [
                'products_processed' => 0,
                'products_with_source' => 0,
                'products_updated' => 0,
                'links_checked' => 0,
                'broken_links' => 0,
                'replaced_links' => 0,
                'removed_links' => 0,
                'added_links' => 0,
                'synced_from_source' => 0,
                'no_replacement' => 0,
                'missing_source' => 0,
            ],
            'created_at' => date('c'),
        ];
        sp_photo_write_job($token, $job, $cfg);
        sp_photo_json([
            'ok' => true,
            'token' => $token,
            'total' => count($products),
            'source_offers' => (int)($sourceInfo['source_offers'] ?? 0),
            'stats' => $job['stats'],
        ]);
    }

    if ($action === 'batch') {
        $token = trim((string)($payload['token'] ?? ''));
        $job = sp_photo_read_job($token, $cfg);
        $supplierId = (int)($job['supplier_id'] ?? 0);
        $supplierCode = (string)($job['supplier_code'] ?? '');
        $products = (array)($job['products'] ?? []);
        $total = count($products);
        $offset = max(0, (int)($job['offset'] ?? 0));
        $limit = max(1, min(40, (int)($payload['limit'] ?? 20)));
        $chunk = array_slice($products, $offset, $limit);
        $sourceMap = (array)($job['source_map'] ?? []);
        $stats = (array)($job['stats'] ?? []);
        $addPhotos = !empty($job['add_photos']);
        $syncSource = array_key_exists('sync_source', $job) ? !empty($job['sync_source']) : true;
        $urlCache = is_array($job['url_cache'] ?? null) ? (array)$job['url_cache'] : [];
        $changedThisBatch = false;

        if ($chunk) {
            $ids = array_values(array_filter(array_map(static fn($p) => (int)($p['id'] ?? 0), $chunk)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $st = db()->prepare("
                    SELECT id, supplier_id, offer_id, pictures_json
                    FROM feedtools_supplier_products
                    WHERE supplier_id = ?
                      AND id IN ({$placeholders})
                ");
                $st->execute(array_merge([$supplierId], $ids));
                $rowsById = [];
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $rowsById[(int)($row['id'] ?? 0)] = $row;
                }

                $updatePictures = db()->prepare("UPDATE feedtools_supplier_products SET pictures_json = ? WHERE id = ?");
                foreach ($chunk as $productInfo) {
                    $productId = (int)($productInfo['id'] ?? 0);
                    $product = (array)($rowsById[$productId] ?? []);
                    if (!$product) {
                        $stats['products_processed'] = (int)($stats['products_processed'] ?? 0) + 1;
                        continue;
                    }
                    $sourcePictures = sp_photo_product_source_pictures($product, $sourceMap, $supplierCode);
                    if (!$sourcePictures) {
                        $stats['missing_source'] = (int)($stats['missing_source'] ?? 0) + 1;
                    } else {
                        $stats['products_with_source'] = (int)($stats['products_with_source'] ?? 0) + 1;
                    }
                    $currentPictures = supplier_products_normalize_picture_urls(supplier_products_decode_json_array($product['pictures_json'] ?? null));
                    $repair = sp_photo_repair_pictures($currentPictures, $sourcePictures, $urlCache, $addPhotos, $syncSource);
                    foreach ((array)($repair['stats'] ?? []) as $key => $value) {
                        $stats[$key] = (int)($stats[$key] ?? 0) + (int)$value;
                    }
                    if (!empty($repair['changed'])) {
                        $pictures = (array)($repair['pictures'] ?? []);
                        $updatePictures->execute([
                            json_encode($pictures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $productId,
                        ]);
                        supplier_products_sync_product_summary_from_db($productId, $cfg);
                        $stats['products_updated'] = (int)($stats['products_updated'] ?? 0) + 1;
                        $changedThisBatch = true;
                    }
                    $stats['products_processed'] = (int)($stats['products_processed'] ?? 0) + 1;
                }
            }
        }

        $offset = min($total, $offset + count($chunk));
        $done = $offset >= $total;
        if ($done && ((int)($stats['products_updated'] ?? 0) > 0)) {
            supplier_products_update_dataset_row_from_db($supplierId, $cfg);
        }

        $job['offset'] = $offset;
        $job['stats'] = $stats;
        $job['url_cache'] = $urlCache;
        if ($done) {
            $job['done_at'] = date('c');
        }
        sp_photo_write_job($token, $job, $cfg);

        sp_photo_json([
            'ok' => true,
            'token' => $token,
            'total' => $total,
            'offset' => $offset,
            'done' => $done,
            'changed' => $changedThisBatch,
            'stats' => $stats,
        ]);
    }

    sp_photo_json(['ok' => false, 'error' => 'Неизвестное действие.'], 400);
} catch (Throwable $e) {
    sp_photo_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
