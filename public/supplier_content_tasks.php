<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();

$supplierId = max(0, (int)($_GET['supplier_id'] ?? 0));
$target = $supplierId > 0
    ? 'supplier_content_progress_supplier.php?' . http_build_query(['supplier_id' => $supplierId])
    : 'supplier_content_progress.php';

header('Location: ' . $target, true, 302);
exit;
