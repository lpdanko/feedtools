<?php
declare(strict_types=1);

require_once __DIR__ . '/../wb_analytics.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';

function op_wb_sync_analytics(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $connectionId = (int)($params['connection_id'] ?? 0);
  [$dateFrom, $dateTo, $daysCount] = wb_analytics_parse_date_range($params);

  $progress = static function (int $done, int $total, string $stage, string $message = '') use ($opId): void {
    ops_update_progress($opId, $done, $total, $stage, $message);
  };

  ops_update_progress($opId, 0, max(1, $daysCount * 1000), 'init', 'WB analytics: preparing');
  $result = wb_analytics_sync_connection(
    $cfg,
    $connectionId,
    $dateFrom,
    $dateTo,
    $daysCount,
    $opId,
    $progress,
    $log,
    ['timeout_sec' => (int)($params['timeout_sec'] ?? 1200)]
  );

  $outDir = op_output_dir($cfg, (int)($ds['id'] ?? 0), $opId);
  ensure_dir($outDir);
  $report = [
    'ok' => true,
    'operation' => 'wb_sync_analytics',
    'connection_id' => (int)$result['connection_id'],
    'date_from' => $result['date_from'],
    'date_to' => $result['date_to'],
    'days_count' => (int)$result['days_count'],
    'download_id' => $result['download_id'],
    'api_rows' => (int)$result['api_rows'],
    'rows_upserted' => (int)$result['rows_upserted'],
    'search_api_rows' => (int)($result['search_api_rows'] ?? 0),
    'search_rows_upserted' => (int)($result['search_rows_upserted'] ?? 0),
    'run_id' => (int)$result['run_id'],
  ];
  $reportPath = $outDir . '/report.json';
  file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  ops_update_progress(
    $opId,
    max(1, (int)$result['rows_upserted']),
    max(1, (int)$result['rows_upserted']),
    'done',
    'WB analytics: done'
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
        'search_api_rows' => (int)($result['search_api_rows'] ?? 0),
        'search_rows_upserted' => (int)($result['search_rows_upserted'] ?? 0),
      ],
    ],
    'wb_analytics' => $report,
  ];
}
