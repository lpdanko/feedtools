<?php
declare(strict_types=1);

function op_remove_white_bg_watermarks(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('Локальная очистка белого фона поддерживается только для DB-товаров поставщика.');
}
