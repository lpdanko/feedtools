<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/navigation.php';

$supplierId = (int)($_POST['supplier_id'] ?? $_GET['supplier_id'] ?? 0);
if ($supplierId <= 0) {
    http_response_code(400);
    exit('Bad supplier_id');
}

try {
    supplier_products_tables_ensure($cfg);
    $datasetId = supplier_products_dataset_id($supplierId, $cfg);
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
    $hasProducts = supplier_products_count($supplierId, $cfg) > 0;

    if ($action === 'refresh' || !$hasProducts) {
        supplier_products_refresh_from_source($supplierId, $cfg);
        $datasetId = supplier_products_dataset_id($supplierId, $cfg);
        header('Location: supplier_products_view.php?id=' . urlencode((string)$datasetId) . '&supplier_products_refreshed=1', true, 303);
        exit;
    }

    supplier_products_update_dataset_row_from_db($supplierId, $cfg);
    header('Location: supplier_products_view.php?id=' . urlencode((string)$datasetId), true, 303);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Товары поставщика</title>
      <?= ft_navigation_assets() ?>
      <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:32px auto;padding:0 16px;color:#17233a}
        .card{border:1px solid #fecaca;background:#fff1f2;border-radius:14px;padding:18px}
        a{color:#0f172a}
      </style>
    </head>
    <body>
      <?= ft_top_navigation(['back_href' => 'suppliers.php', 'back_label' => 'Назад', 'active' => 'suppliers']) ?>
      <div class="card">
        <h1>Не удалось открыть товары поставщика</h1>
        <p><?= htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    </body>
    </html>
    <?php
}
