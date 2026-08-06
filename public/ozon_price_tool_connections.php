<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

$connectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
$editId = (int)($_GET['edit_id'] ?? $_GET['connection_edit_id'] ?? $_POST['edit_id'] ?? $_POST['connection_edit_id'] ?? 0);
$isNew = (isset($_GET['new']) && $_GET['new'] === '1')
    || (isset($_GET['new_connection']) && $_GET['new_connection'] === '1')
    || (isset($_POST['new']) && $_POST['new'] === '1')
    || (isset($_POST['new_connection']) && $_POST['new_connection'] === '1');

$params = [];
if ($connectionId > 0) {
    $params['connection_id'] = (string)$connectionId;
}
if ($editId > 0) {
    $params['connection_edit_id'] = (string)$editId;
}
if ($isNew) {
    $params['new_connection'] = '1';
}

$target = 'marketplace_connections.php';
if ($params) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target, true, 302);
exit;
