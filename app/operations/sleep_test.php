<?php
function op_sleep_test(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $seconds = (int)($params['seconds'] ?? 20);
  $tick = max(1, (int)($params['tick'] ?? 2));

  $total = $seconds;
  $start = microtime(true);

  $log("sleep_test: seconds={$seconds}, tick={$tick}\n");

  for ($done = 0; $done <= $total; $done += $tick) {
    $elapsed = microtime(true) - $start;
    $msg = "elapsed=" . round($elapsed,1) . "s";
    $log("progress {$done}/{$total} {$msg}\n");

    if (function_exists('ops_update_progress')) {
      ops_update_progress($opId, min($done, $total), $total, 'sleep', $msg);
    }

    if ($done < $total) sleep($tick);
  }

  $elapsed = microtime(true) - $start;

  if (function_exists('ops_set_summary')) {
    ops_set_summary($opId, [
      'title' => 'Sleep test завершён',
      'items' => [
        "Длительность: " . round($elapsed,1) . "s",
        "Тики: {$tick}s",
      ],
      'metrics' => [
        'elapsed_sec' => (int)round($elapsed),
        'total' => $total,
      ],
    ]);
  }

  return [
    // артефакты не обязательны
  ];
}
