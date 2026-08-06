<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../app/marketplace_brand_status.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 24;
    $ozonCategory = trim((string)($_GET['ozon_category'] ?? ''));
    $wbCategory = trim((string)($_GET['wb_category'] ?? ''));
    $marketplace = trim((string)($_GET['marketplace'] ?? ''));
    echo json_encode([
        'ok' => true,
        'items' => marketplace_brand_suggestions($query, $limit, $ozonCategory, $wbCategory, $marketplace),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
