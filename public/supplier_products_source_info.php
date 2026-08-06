<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/xml_scan.php';
require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/supplier_products_import.php';
require_once __DIR__ . '/../app/supplier_products_marketplace_import.php';

header('Content-Type: application/json; charset=utf-8');

function supplier_source_info_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function supplier_source_info_selected_ids($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map('strval', $value), static fn($v) => trim($v) !== ''));
}

$cleanup = [];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        supplier_source_info_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

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

    $source = trim((string)($payload['source'] ?? ''));
    if ($source === '' || !in_array($source, supplier_marketplace_import_sources(), true)) {
        throw new RuntimeException('Выбери источник импорта.');
    }

    $sourceDataForPreview = null;
    if ($source === 'supplier_feed') {
        $url = trim((string)($supplier['feed_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('У поставщика не заполнена ссылка на источник данных.');
        }
        $download = ozon_price_feed_fetch_remote_xml($url, (int)($cfg['limits']['max_upload_bytes'] ?? 200_000_000));
        $path = (string)$download['path'];
        $cleanup[] = $path;
        $info = supplier_import_source_info_from_file($supplierId, $path, $path, $cfg, true);
        $sourceDataForPreview = is_array($info['source_data'] ?? null) ? $info['source_data'] : null;
        unset($info['source_data']);
        $info['label'] = 'Фид поставщика';
        $info['url'] = (string)($download['final_url'] ?? $url);
    } elseif ($source === 'url') {
        $url = trim((string)($payload['feed_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Укажи ссылку на фид.');
        }
        $download = ozon_price_feed_fetch_remote_xml($url, (int)($cfg['limits']['max_upload_bytes'] ?? 200_000_000));
        $path = (string)$download['path'];
        $cleanup[] = $path;
        $info = supplier_import_source_info_from_file($supplierId, $path, $path, $cfg, true);
        $sourceDataForPreview = is_array($info['source_data'] ?? null) ? $info['source_data'] : null;
        unset($info['source_data']);
        $info['label'] = 'Фид по ссылке';
        $info['url'] = (string)($download['final_url'] ?? $url);
    } elseif ($source === 'upload') {
        $path = supplier_import_stored_source_path((string)($payload['stored_file'] ?? ''), $cfg);
        $name = trim((string)($payload['stored_file_name'] ?? ''));
        $info = supplier_import_source_info_from_file($supplierId, $path, $name, $cfg, true);
        $sourceDataForPreview = is_array($info['source_data'] ?? null) ? $info['source_data'] : null;
        unset($info['source_data']);
        $info['label'] = 'Загруженный файл';
    } else {
        $connectionId = $source === 'ozon_account'
            ? (int)($payload['ozon_connection_id'] ?? 0)
            : (int)($payload['wb_connection_id'] ?? 0);
        $info = supplier_marketplace_import_source_info(
            $datasetId,
            $supplierId,
            $source,
            $connectionId,
            $cfg,
            [
                'mode' => (string)($payload['mode'] ?? 'add_update'),
                'scope' => (string)($payload['scope'] ?? 'all'),
                'selected_offer_ids' => supplier_source_info_selected_ids($payload['selected_offer_ids'] ?? []),
            ]
        );
    }

    if (is_array($sourceDataForPreview)) {
        $info['preview'] = supplier_products_import_preview($supplierId, $sourceDataForPreview, [
            'mode' => (string)($payload['mode'] ?? 'add_update'),
            'scope' => (string)($payload['scope'] ?? 'all'),
            'selected_offer_ids' => supplier_source_info_selected_ids($payload['selected_offer_ids'] ?? []),
        ], $cfg);
    }

    foreach ($cleanup as $pathToClean) {
        if (is_string($pathToClean) && $pathToClean !== '' && is_file($pathToClean)) {
            @unlink($pathToClean);
        }
    }

    supplier_source_info_json(['ok' => true] + $info);
} catch (Throwable $e) {
    foreach (($cleanup ?? []) as $pathToClean) {
        if (is_string($pathToClean) && $pathToClean !== '' && is_file($pathToClean)) {
            @unlink($pathToClean);
        }
    }
    supplier_source_info_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
