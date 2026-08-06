<?php
declare(strict_types=1);

function op_supplier_brand_dictionary_only_for_supplier_products(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    require_once __DIR__ . '/../op_registry.php';
    require_once __DIR__ . '/../supplier_products_db_ops.php';

    $op = ops_get($opId);
    if (!is_array($op)) {
        throw new RuntimeException('Операция не найдена.');
    }

    $opType = (string)($op['op_type'] ?? '');
    $registry = op_registry();
    $opMeta = $registry[$opType] ?? null;
    if (!is_array($opMeta)) {
        throw new RuntimeException('Операция не зарегистрирована.');
    }

    return supplier_products_db_run_registered_handler($cfg, $ds, $opId, $params, $log, $opType, $opMeta);
}
