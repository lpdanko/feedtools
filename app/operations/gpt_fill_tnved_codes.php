<?php
declare(strict_types=1);

function op_gpt_fill_tnved_codes(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    throw new RuntimeException('gpt_fill_tnved_codes поддерживается для раздела товаров поставщиков в DB-режиме.');
}
