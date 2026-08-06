<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/ozon_price_tool.php';

function ozon_duplicate_pairs_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    supplier_products_tables_ensure($cfg);
    ozon_products_tables_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_duplicate_pairs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dataset_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            connection_id BIGINT UNSIGNED NOT NULL,
            failed_offer_id VARCHAR(191) NOT NULL DEFAULT '',
            failed_product_id BIGINT UNSIGNED NULL,
            failed_name TEXT NULL,
            failed_photo_url TEXT NULL,
            passed_offer_id VARCHAR(191) NOT NULL DEFAULT '',
            passed_product_id BIGINT UNSIGNED NULL,
            passed_name TEXT NULL,
            passed_photo_url TEXT NULL,
            passed_company_id BIGINT UNSIGNED NULL,
            passed_ozon_status VARCHAR(64) NOT NULL DEFAULT '',
            raw_error_text TEXT NULL,
            status VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dataset_connection_pair (dataset_id, connection_id, failed_offer_id, passed_offer_id),
            KEY idx_dataset_connection (dataset_id, connection_id, updated_at),
            KEY idx_failed_product (failed_product_id),
            KEY idx_passed_product (passed_product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function ozon_duplicate_pairs_text_norm(string $value): string
{
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = preg_replace('~\s+~u', ' ', $value);
    return trim((string)$value);
}

function ozon_duplicate_pairs_is_duplicate_error(array $error): bool
{
    $texts = $error['texts'] ?? [];
    if (!is_array($texts)) {
        $texts = [];
    }
    $haystack = implode(' ', [
        (string)($error['code'] ?? ''),
        (string)($texts['attribute_name'] ?? ''),
        (string)($texts['description'] ?? ''),
        (string)($texts['message'] ?? ''),
        json_encode($texts['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ]);
    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
    return str_contains($haystack, 'spu_already_exists')
        || str_contains($haystack, 'duplicates')
        || str_contains($haystack, 'duplicate')
        || str_contains($haystack, 'дубл')
        || str_contains($haystack, 'уже существует');
}

function ozon_duplicate_pairs_add_candidate(array &$out, string $offerId, $companyId = null): void
{
    $offerId = ozon_duplicate_pairs_text_norm($offerId);
    $offerId = trim($offerId, " \t\n\r\0\x0B\"'");
    if ($offerId === '' || preg_match('~^(?:0|null|undefined|-)$~i', $offerId)) {
        return;
    }
    $company = is_numeric($companyId) ? (int)$companyId : null;
    $key = $offerId . "\0" . (string)($company ?? '');
    $out[$key] = [
        'offer_id' => $offerId,
        'company_id' => $company,
    ];
}

function ozon_duplicate_pairs_extract_from_message(string $message): array
{
    $message = trim($message);
    if ($message === '') {
        return [];
    }

    $decoded = json_decode($message, true);
    if (!is_array($decoded)) {
        $unescaped = stripcslashes($message);
        if ($unescaped !== $message) {
            $decoded = json_decode($unescaped, true);
        }
    }
    if (!is_array($decoded)) {
        return [];
    }

    $dups = $decoded['DUPLICATES'] ?? $decoded['duplicates'] ?? [];
    if (!is_array($dups)) {
        return [];
    }

    $out = [];
    foreach ($dups as $item) {
        if (!is_array($item)) {
            continue;
        }
        $offerId = (string)($item['OFFERID'] ?? $item['offer_id'] ?? $item['offerId'] ?? '');
        $companyId = $item['COMPANYID'] ?? $item['company_id'] ?? $item['companyId'] ?? null;
        ozon_duplicate_pairs_add_candidate($out, $offerId, $companyId);
    }
    return array_values($out);
}

function ozon_duplicate_pairs_extract_from_params(array $params): array
{
    $out = [];
    foreach ($params as $param) {
        if (!is_array($param)) {
            continue;
        }
        $name = function_exists('mb_strtolower')
            ? mb_strtolower((string)($param['name'] ?? ''), 'UTF-8')
            : strtolower((string)($param['name'] ?? ''));
        if (!str_contains($name, 'duplicate') && !str_contains($name, 'дубл')) {
            continue;
        }
        $value = (string)($param['value'] ?? '');
        foreach (preg_split('~\s*,\s*~u', $value) ?: [] as $part) {
            $part = ozon_duplicate_pairs_text_norm($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('~^(.+?)\s+-\s+(\d+)$~u', $part, $m)) {
                ozon_duplicate_pairs_add_candidate($out, (string)$m[1], (int)$m[2]);
            } else {
                ozon_duplicate_pairs_add_candidate($out, $part, null);
            }
        }
    }
    return array_values($out);
}

function ozon_duplicate_pairs_extract_from_description(string $description): array
{
    $out = [];
    $description = ozon_duplicate_pairs_text_norm($description);
    if ($description === '') {
        return [];
    }

    if (preg_match_all('~([A-Za-zА-Яа-яЁё0-9][A-Za-zА-Яа-яЁё0-9_. /+\-()]{2,180}?)\s+-\s+(\d{3,})~u', $description, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $offerId = trim((string)$m[1]);
            $offerId = preg_replace('~^(?:товар|артикул|offer(?:id)?|дублируется|такой же)\s*[:№#-]*\s*~iu', '', $offerId);
            ozon_duplicate_pairs_add_candidate($out, (string)$offerId, (int)$m[2]);
        }
    }

    return array_values($out);
}

function ozon_duplicate_pairs_extract_from_raw($rawJson): array
{
    if (is_array($rawJson)) {
        $raw = $rawJson;
    } else {
        $rawText = trim((string)$rawJson);
        if ($rawText === '') {
            return [];
        }
        $raw = json_decode($rawText, true);
        if (!is_array($raw)) {
            return [];
        }
    }

    $errors = $raw['errors'] ?? [];
    if (!is_array($errors)) {
        return [];
    }

    $out = [];
    foreach ($errors as $error) {
        if (!is_array($error) || !ozon_duplicate_pairs_is_duplicate_error($error)) {
            continue;
        }
        $texts = $error['texts'] ?? [];
        if (!is_array($texts)) {
            $texts = [];
        }

        $beforeStructured = count($out);
        foreach (ozon_duplicate_pairs_extract_from_message((string)($texts['message'] ?? '')) as $candidate) {
            ozon_duplicate_pairs_add_candidate($out, (string)$candidate['offer_id'], $candidate['company_id'] ?? null);
        }
        $params = $texts['params'] ?? [];
        if (is_array($params)) {
            foreach (ozon_duplicate_pairs_extract_from_params($params) as $candidate) {
                ozon_duplicate_pairs_add_candidate($out, (string)$candidate['offer_id'], $candidate['company_id'] ?? null);
            }
        }
        if (count($out) === $beforeStructured) {
            foreach (ozon_duplicate_pairs_extract_from_description((string)($texts['description'] ?? '')) as $candidate) {
                ozon_duplicate_pairs_add_candidate($out, (string)$candidate['offer_id'], $candidate['company_id'] ?? null);
            }
        }
    }

    return array_values($out);
}

function ozon_duplicate_pairs_error_text_from_raw($rawJson): string
{
    $raw = is_array($rawJson) ? $rawJson : json_decode(trim((string)$rawJson), true);
    if (!is_array($raw)) {
        return '';
    }
    $parts = [];
    foreach (($raw['errors'] ?? []) as $error) {
        if (!is_array($error) || !ozon_duplicate_pairs_is_duplicate_error($error)) {
            continue;
        }
        $texts = $error['texts'] ?? [];
        if (!is_array($texts)) {
            $texts = [];
        }
        foreach (['description', 'message'] as $key) {
            $text = ozon_duplicate_pairs_text_norm((string)($texts[$key] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }
    return mb_substr(implode("\n", array_values(array_unique($parts))), 0, 4000, 'UTF-8');
}

function ozon_duplicate_pairs_offer_candidates(string $offerId, string $supplierCode): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [];
    }
    $supplierCode = suppliers_normalize_code($supplierCode);
    $out = [$offerId => true];
    if ($supplierCode !== '') {
        $suffix = '__' . $supplierCode;
        if (str_ends_with($offerId, $suffix)) {
            $base = substr($offerId, 0, -strlen($suffix));
            if ($base !== '') {
                $out[$base] = true;
            }
        } else {
            $out[$offerId . $suffix] = true;
        }
    }
    return array_keys($out);
}

function ozon_duplicate_pairs_find_supplier_product(PDO $pdo, int $supplierId, string $offerId, string $supplierCode): ?array
{
    $candidates = ozon_duplicate_pairs_offer_candidates($offerId, $supplierCode);
    if (!$candidates) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $args = array_merge([$supplierId], $candidates, $candidates);
    $st = $pdo->prepare("
        SELECT *
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND (offer_id IN ({$placeholders}) OR vendor_code IN ({$placeholders}))
        ORDER BY FIELD(offer_id, {$placeholders}) DESC, id ASC
        LIMIT 1
    ");
    $st->execute(array_merge([$supplierId], $candidates, $candidates, $candidates));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ozon_duplicate_pairs_raw_primary_image(array $raw): string
{
    foreach (['primary_image', 'images', 'images360', 'color_image'] as $key) {
        $value = $raw[$key] ?? null;
        if (is_array($value)) {
            foreach ($value as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    return $url;
                }
            }
        } else {
            $url = trim((string)$value);
            if ($url !== '') {
                return $url;
            }
        }
    }
    return '';
}

function ozon_duplicate_pairs_product_photo(?array $product = null, ?array $ozonRow = null): string
{
    if (is_array($product)) {
        $pictures = supplier_products_decode_json_array($product['pictures_json'] ?? null);
        foreach ($pictures as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                return $url;
            }
        }
    }
    if (is_array($ozonRow)) {
        $raw = json_decode((string)($ozonRow['raw_json'] ?? ''), true);
        if (is_array($raw)) {
            return ozon_duplicate_pairs_raw_primary_image($raw);
        }
    }
    return '';
}

function ozon_duplicate_pairs_product_name(?array $product, ?array $ozonRow, string $fallbackOfferId): string
{
    $name = trim((string)($product['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    if (is_array($ozonRow)) {
        $raw = json_decode((string)($ozonRow['raw_json'] ?? ''), true);
        if (is_array($raw)) {
            foreach (['name', 'offer_name'] as $key) {
                $name = trim((string)($raw[$key] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }
    }
    return $fallbackOfferId;
}

function ozon_duplicate_pairs_fetch_ozon_rows(PDO $pdo, int $connectionId, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map('strval', $offerIds)), static fn($v): bool => trim($v) !== ''));
    if (!$offerIds || $connectionId <= 0) {
        return [];
    }
    $out = [];
    foreach (array_chunk($offerIds, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT *
            FROM feedtools_ozon_products
            WHERE connection_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $st->execute(array_merge([$connectionId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)($row['offer_id'] ?? '')] = $row;
        }
    }
    return $out;
}

function ozon_duplicate_pairs_supplier_offer_candidates(PDO $pdo, int $supplierId, string $supplierCode): array
{
    if ($supplierId <= 0) {
        return [];
    }
    $st = $pdo->prepare("
        SELECT offer_id, vendor_code
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
    ");
    $st->execute([$supplierId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        foreach (['offer_id', 'vendor_code'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach (ozon_duplicate_pairs_offer_candidates($value, $supplierCode) as $candidate) {
                $out[$candidate] = true;
            }
        }
    }
    return array_keys($out);
}

function ozon_duplicate_pairs_fetch_supplier_duplicate_rows(PDO $pdo, int $connectionId, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map('strval', $offerIds)), static fn($v): bool => trim($v) !== ''));
    if ($connectionId <= 0 || !$offerIds) {
        return [];
    }
    $rows = [];
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("
            SELECT *
            FROM feedtools_ozon_products
            WHERE connection_id = ?
              AND offer_id IN ({$placeholders})
              AND (
                COALESCE(raw_json, '') LIKE '%SPU_ALREADY_EXISTS%'
                OR COALESCE(raw_json, '') LIKE '%DUPLICATES%'
                OR COALESCE(raw_json, '') LIKE '%duplicate%'
                OR COALESCE(raw_json, '') LIKE '%дубл%'
                OR COALESCE(status_description, '') LIKE '%дубл%'
                OR COALESCE(status_description, '') LIKE '%уже существует%'
              )
            ORDER BY updated_at DESC, id DESC
        ");
        $st->execute(array_merge([$connectionId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[(string)($row['offer_id'] ?? '')] = $row;
        }
    }
    return array_values($rows);
}

function ozon_duplicate_pairs_status(?array $failedProduct, ?array $passedProduct, ?array $passedOzonRow): string
{
    if (!is_array($passedProduct)) {
        return 'passed_product_not_found';
    }
    if (!is_array($failedProduct)) {
        return 'failed_product_not_found';
    }
    $marketplaceStatus = trim((string)($passedOzonRow['marketplace_status'] ?? ''));
    if ($marketplaceStatus !== '' && $marketplaceStatus !== 'ready') {
        return 'passed_status_' . preg_replace('~[^a-z0-9_-]+~i', '_', $marketplaceStatus);
    }
    return 'ready';
}

function ozon_duplicate_pairs_scan(array $cfg, int $datasetId, int $supplierId, int $connectionId, int $opId = 0, ?callable $log = null): array
{
    ozon_duplicate_pairs_table_ensure($cfg);
    $pdo = db();
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    if ($connectionId <= 0) {
        $ds = supplier_products_dataset_row($datasetId, $cfg);
        $connectionId = (int)($ds['ozon_connection_id'] ?? 0);
    }
    if ($connectionId <= 0) {
        throw new RuntimeException('Для датасета не выбрано подключение Ozon.');
    }

    $log = $log ?: static function (string $message): void {};
    $log("ozon duplicate pairs: dataset={$datasetId}, supplier={$supplierId}, connection={$connectionId}\n");

    $supplierOfferIds = ozon_duplicate_pairs_supplier_offer_candidates($pdo, $supplierId, $supplierCode);
    $ozonRows = ozon_duplicate_pairs_fetch_supplier_duplicate_rows($pdo, $connectionId, $supplierOfferIds);
    $totalRows = count($ozonRows);
    if ($opId > 0 && function_exists('ops_update_progress')) {
        ops_update_progress($opId, 0, max(1, $totalRows), 'scan', "проверяем ошибки Ozon 0/{$totalRows}");
    }

    $pairs = [];
    $passedOfferIds = [];
    $stats = [
        'ozon_rows_seen' => $totalRows,
        'duplicate_error_rows' => 0,
        'pairs_found' => 0,
        'failed_products_found' => 0,
        'passed_products_found' => 0,
        'rows_without_parsed_duplicate' => 0,
        'multiple_duplicates' => 0,
    ];

    $seen = 0;
    foreach ($ozonRows as $row) {
        $seen++;
        $raw = json_decode((string)($row['raw_json'] ?? ''), true);
        $duplicates = ozon_duplicate_pairs_extract_from_raw(is_array($raw) ? $raw : (string)($row['raw_json'] ?? ''));
        if (!$duplicates) {
            $stats['rows_without_parsed_duplicate']++;
            continue;
        }
        $stats['duplicate_error_rows']++;
        if (count($duplicates) > 1) {
            $stats['multiple_duplicates']++;
        }

        $failedOfferId = trim((string)($row['offer_id'] ?? ''));
        $failedProduct = ozon_duplicate_pairs_find_supplier_product($pdo, $supplierId, $failedOfferId, $supplierCode);
        if (is_array($failedProduct)) {
            $stats['failed_products_found']++;
        }
        $rawErrorText = ozon_duplicate_pairs_error_text_from_raw(is_array($raw) ? $raw : (string)($row['raw_json'] ?? ''));

        foreach ($duplicates as $duplicate) {
            $passedOfferId = trim((string)($duplicate['offer_id'] ?? ''));
            if ($passedOfferId === '' || $passedOfferId === $failedOfferId) {
                continue;
            }
            $passedOfferIds[$passedOfferId] = true;
            $pairs[] = [
                'failed_ozon_row' => $row,
                'failed_product' => $failedProduct,
                'failed_offer_id' => $failedOfferId,
                'passed_offer_id' => $passedOfferId,
                'passed_company_id' => $duplicate['company_id'] ?? null,
                'raw_error_text' => $rawErrorText,
            ];
        }

        if ($opId > 0 && function_exists('ops_update_progress') && ($seen % 100 === 0 || $seen === $totalRows)) {
            ops_update_progress($opId, $seen, max(1, $totalRows), 'scan', "проверяем ошибки Ozon {$seen}/{$totalRows}");
        }
    }

    $passedOzonRows = ozon_duplicate_pairs_fetch_ozon_rows($pdo, $connectionId, array_keys($passedOfferIds));
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM feedtools_ozon_duplicate_pairs WHERE dataset_id = ? AND connection_id = ?");
        $del->execute([$datasetId, $connectionId]);

        $ins = $pdo->prepare("
            INSERT INTO feedtools_ozon_duplicate_pairs (
                dataset_id, supplier_id, connection_id,
                failed_offer_id, failed_product_id, failed_name, failed_photo_url,
                passed_offer_id, passed_product_id, passed_name, passed_photo_url,
                passed_company_id, passed_ozon_status, raw_error_text, status,
                created_at, updated_at
            ) VALUES (
                :dataset_id, :supplier_id, :connection_id,
                :failed_offer_id, :failed_product_id, :failed_name, :failed_photo_url,
                :passed_offer_id, :passed_product_id, :passed_name, :passed_photo_url,
                :passed_company_id, :passed_ozon_status, :raw_error_text, :status,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                failed_product_id = VALUES(failed_product_id),
                failed_name = VALUES(failed_name),
                failed_photo_url = VALUES(failed_photo_url),
                passed_product_id = VALUES(passed_product_id),
                passed_name = VALUES(passed_name),
                passed_photo_url = VALUES(passed_photo_url),
                passed_company_id = VALUES(passed_company_id),
                passed_ozon_status = VALUES(passed_ozon_status),
                raw_error_text = VALUES(raw_error_text),
                status = VALUES(status),
                updated_at = NOW()
        ");

        foreach ($pairs as $pair) {
            $passedOfferId = (string)$pair['passed_offer_id'];
            $passedOzonRow = $passedOzonRows[$passedOfferId] ?? null;
            $passedProduct = ozon_duplicate_pairs_find_supplier_product($pdo, $supplierId, $passedOfferId, $supplierCode);
            if (is_array($passedProduct)) {
                $stats['passed_products_found']++;
            }
            $failedProduct = is_array($pair['failed_product'] ?? null) ? $pair['failed_product'] : null;
            $failedOzonRow = is_array($pair['failed_ozon_row'] ?? null) ? $pair['failed_ozon_row'] : null;
            $status = ozon_duplicate_pairs_status($failedProduct, $passedProduct, is_array($passedOzonRow) ? $passedOzonRow : null);

            $ins->execute([
                ':dataset_id' => $datasetId,
                ':supplier_id' => $supplierId,
                ':connection_id' => $connectionId,
                ':failed_offer_id' => (string)$pair['failed_offer_id'],
                ':failed_product_id' => is_array($failedProduct) ? (int)$failedProduct['id'] : null,
                ':failed_name' => ozon_duplicate_pairs_product_name($failedProduct, $failedOzonRow, (string)$pair['failed_offer_id']),
                ':failed_photo_url' => ozon_duplicate_pairs_product_photo($failedProduct, $failedOzonRow),
                ':passed_offer_id' => $passedOfferId,
                ':passed_product_id' => is_array($passedProduct) ? (int)$passedProduct['id'] : null,
                ':passed_name' => ozon_duplicate_pairs_product_name($passedProduct, is_array($passedOzonRow) ? $passedOzonRow : null, $passedOfferId),
                ':passed_photo_url' => ozon_duplicate_pairs_product_photo($passedProduct, is_array($passedOzonRow) ? $passedOzonRow : null),
                ':passed_company_id' => is_numeric($pair['passed_company_id'] ?? null) ? (int)$pair['passed_company_id'] : null,
                ':passed_ozon_status' => (string)($passedOzonRow['marketplace_status'] ?? ''),
                ':raw_error_text' => (string)($pair['raw_error_text'] ?? ''),
                ':status' => $status,
            ]);
            $stats['pairs_found']++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($opId > 0 && function_exists('ops_update_progress')) {
        ops_update_progress($opId, max(1, $totalRows), max(1, $totalRows), 'done', 'таблица дублей Ozon обновлена');
    }
    $log('ozon duplicate pairs done: ' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    return $stats;
}

function ozon_duplicate_pairs_for_dataset(int $datasetId, int $connectionId, array $cfg = []): array
{
    ozon_duplicate_pairs_table_ensure($cfg);
    if ($datasetId <= 0 || $connectionId <= 0) {
        return [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_ozon_duplicate_pairs
        WHERE dataset_id = ?
          AND connection_id = ?
        ORDER BY updated_at DESC, failed_offer_id ASC, passed_offer_id ASC
    ");
    $st->execute([$datasetId, $connectionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function supplier_products_db_op_ozon_duplicate_pairs_scan(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $datasetId = (int)($ds['id'] ?? 0);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('DB-датасет поставщика не найден.');
    }
    $connectionId = (int)($params['connection_id'] ?? 0);
    $stats = ozon_duplicate_pairs_scan($cfg, $datasetId, $supplierId, $connectionId, $opId, $log);
    $report = [
        'title' => 'Дубли товаров Ozon',
        'items' => [
            'Ozon rows seen: ' . (int)$stats['ozon_rows_seen'],
            'Duplicate error rows: ' . (int)$stats['duplicate_error_rows'],
            'Pairs found: ' . (int)$stats['pairs_found'],
            'Failed products found: ' . (int)$stats['failed_products_found'],
            'Passed products found: ' . (int)$stats['passed_products_found'],
            'Rows without parsed duplicate: ' . (int)$stats['rows_without_parsed_duplicate'],
            'Multiple duplicates: ' . (int)$stats['multiple_duplicates'],
        ],
        'metrics' => $stats,
        'duplicate_pairs_url' => 'supplier_products_ozon_duplicates.php?id=' . $datasetId,
    ];
    if (function_exists('supplier_products_db_report_output')) {
        return supplier_products_db_report_output($cfg, $datasetId, $opId, $report);
    }
    return ['summary_json_inline' => $report];
}
