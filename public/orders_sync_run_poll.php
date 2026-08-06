<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/orders_sync.php';

header('Content-Type: application/json; charset=utf-8');

$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'bad run_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$run = orders_sync_run_get($runId, $cfg);
if (!is_array($run)) {
    http_response_code(404);
    echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
$kind = trim((string)($summary['kind'] ?? 'ozon_sync'));
$percent = null;

if ($kind === 'moysklad_export') {
    $total = max(0, (int)($totals['scanned'] ?? 0));
    $done = (int)($totals['created'] ?? 0)
        + (int)($totals['updated'] ?? 0)
        + (int)($totals['restored'] ?? 0)
        + (int)($totals['recreated'] ?? 0)
        + (int)($totals['skipped'] ?? 0)
        + (int)($totals['errors'] ?? 0);
    if ($total > 0) {
        $percent = round(min(100, ($done / $total) * 100), 1);
    }
} else {
    $sources = is_array($summary['sources'] ?? null) ? $summary['sources'] : [];
    $total = count($sources);
    $done = 0;
    foreach ($sources as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!empty($item['error']) || (int)($item['fetched'] ?? 0) > 0 || (int)($item['inserted'] ?? 0) > 0 || (int)($item['updated'] ?? 0) > 0) {
            $done++;
        }
    }
    if ($total > 0) {
        $percent = round(min(100, ($done / $total) * 100), 1);
    }
}

echo json_encode([
    'id' => (int)($run['id'] ?? 0),
    'status' => (string)($run['status'] ?? ''),
    'started_at' => (string)($run['started_at'] ?? ''),
    'finished_at' => (string)($run['finished_at'] ?? ''),
    'error_text' => (string)($run['error_text'] ?? ''),
    'log_text' => (string)($run['log_text'] ?? ''),
    'summary' => $summary,
    'percent' => $percent,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
