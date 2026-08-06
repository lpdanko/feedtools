<?php
declare(strict_types=1);

function op_gpt_generate_product_photo_variant(array $cfg, array $datasetRow, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('gpt_generate_product_photo_variant доступна для DB-товаров поставщика на странице supplier_products_view.php.');
}
