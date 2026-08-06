<?php
declare(strict_types=1);

@set_time_limit(0);
@ini_set('max_execution_time', '0');

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/wb_promotions.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Missing vendor/autoload.php. Run: composer install');
}
require_once $autoload;

function wb_promo_import_redirect(int $connectionId, array $query): void
{
    $query = ['connection_id' => (string)$connectionId] + $query;
    header('Location: ozon_price_tool.php?' . http_build_query($query), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$connectionId = (int)($_POST['connection_id'] ?? 0);
if ($connectionId <= 0) {
    http_response_code(400);
    exit('connection_id is required');
}

try {
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Импорт XLSX автоакции доступен только для подключения WB.');
    }

    if (empty($_FILES['xlsxfile']) || (int)($_FILES['xlsxfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Выбери XLS/XLSX-файл автоакции WB.');
    }

    $origName = (string)($_FILES['xlsxfile']['name'] ?? '');
    $tmpPath = (string)($_FILES['xlsxfile']['tmp_name'] ?? '');
    $bytes = (int)($_FILES['xlsxfile']['size'] ?? 0);
    if ($bytes <= 0 || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Загруженный XLS/XLSX-файл пустой или недоступен.');
    }
    if (!preg_match('~\.(xlsx|xls)$~iu', $origName)) {
        throw new RuntimeException('Нужен файл .xls или .xlsx из кабинета WB.');
    }

    $promotionTitle = trim((string)($_POST['promotion_title'] ?? ''));
    $promotionId = max(0, (int)($_POST['promotion_id'] ?? 0));
    $promotionStart = trim((string)($_POST['promotion_start'] ?? ''));
    $promotionEnd = trim((string)($_POST['promotion_end'] ?? ''));
    $result = wb_promotions_import_xlsx($tmpPath, $connectionId, $origName, $promotionTitle, $promotionId, $cfg, $promotionStart, $promotionEnd);
    wb_promo_import_redirect($connectionId, [
        'wb_promo_imported' => '1',
        'promotion_id' => (string)($result['promotion_id'] ?? 0),
        'products' => (string)($result['products_stored'] ?? 0),
        'candidates' => (string)($result['candidate_count'] ?? 0),
        'participating' => (string)($result['participating_count'] ?? 0),
    ]);
} catch (Throwable $e) {
    wb_promo_import_redirect($connectionId, [
        'wb_promo_error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
    ]);
}
