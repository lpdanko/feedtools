<?php
declare(strict_types=1);

/**
 * Supplier product imports are executed through supplier_products_db_ops.php.
 * This stub keeps the operation registry compatible with the generic runner.
 */
function op_supplier_import_catalog(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('supplier_import_catalog работает только для DB-товаров поставщика.');
}
