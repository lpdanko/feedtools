<?php
declare(strict_types=1);

require_once __DIR__ . '/gpt_fill_offer_params.php';

function op_gpt_fill_offer_params_wb(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $log("gpt_fill_offer_params_wb: legacy alias -> gpt_fill_offer_params\n");
  return op_gpt_fill_offer_params($cfg, $ds, $opId, $params, $log);
}
