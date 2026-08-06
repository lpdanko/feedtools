<?php
declare(strict_types=1);

function op_supplier_push_marketplace_content(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('Эта операция доступна только для DB-товаров поставщика.');
}
