<?php
declare(strict_types=1);

require_once __DIR__ . '/../ozon_analytics.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';

function op_ozon_sync_analytics(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $connectionId = (int)($params['connection_id'] ?? 0);
  [$dateFrom, $dateTo, $daysCount] = ozon_analytics_parse_date_range($params);

  $metricsParam = trim((string)($params['metrics'] ?? ''));
  $metrics = $metricsParam !== ''
    ? preg_split('~[\s,;]+~', $metricsParam, -1, PREG_SPLIT_NO_EMPTY)
    : ozon_analytics_default_metrics();
  $metrics = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $metrics ?: [])));
  if (!$metrics) {
    $metrics = ozon_analytics_default_metrics();
  }

  $limit = (int)($params['limit'] ?? 1000);
  $progress = static function (int $done, int $total, string $stage, string $message = '') use ($opId): void {
    ops_update_progress($opId, $done, $total, $stage, $message);
  };

  ops_update_progress($opId, 0, max(1, $daysCount), 'init', 'Ozon analytics: preparing');
  $result = ozon_analytics_sync_connection(
    $cfg,
    $connectionId,
    $dateFrom,
    $dateTo,
    $daysCount,
    $metrics,
    $opId,
    $progress,
    $log,
    ['limit' => $limit]
  );

  $outDir = op_output_dir($cfg, (int)($ds['id'] ?? 0), $opId);
  ensure_dir($outDir);
  $report = [
    'ok' => true,
    'operation' => 'ozon_sync_analytics',
    'connection_id' => (int)$result['connection_id'],
    'date_from' => $result['date_from'],
    'date_to' => $result['date_to'],
    'days_count' => (int)$result['days_count'],
    'api_rows' => (int)$result['api_rows'],
    'rows_upserted' => (int)$result['rows_upserted'],
    'pages_count' => (int)$result['pages_count'],
    'available_metrics' => $result['available_metrics'],
    'failed_metrics' => $result['failed_metrics'],
    'run_id' => (int)$result['run_id'],
  ];
  $reportPath = $outDir . '/report.json';
  file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  ops_update_progress(
    $opId,
    max(1, (int)$result['rows_upserted']),
    max(1, (int)$result['rows_upserted']),
    'done',
    'Ozon analytics: done'
  );

  return [
    'report_json' => rel_to_outputs($cfg, $reportPath),
    'summary_json_inline' => [
      'metrics' => [
        'connection_id' => (int)$result['connection_id'],
        'date_from' => $result['date_from'],
        'date_to' => $result['date_to'],
        'days_count' => (int)$result['days_count'],
        'api_rows' => (int)$result['api_rows'],
        'rows_upserted' => (int)$result['rows_upserted'],
        'available_metrics_count' => count((array)$result['available_metrics']),
        'failed_metrics_count' => count((array)$result['failed_metrics']),
      ],
    ],
    'ozon_analytics' => $report,
  ];
}
