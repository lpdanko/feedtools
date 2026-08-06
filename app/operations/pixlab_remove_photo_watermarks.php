<?php
declare(strict_types=1);

function op_pixlab_remove_photo_watermarks(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('Операция удаления водяных знаков поддерживается только для DB-товаров поставщика.');
}
