<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/stocks_tool.php';

header('Content-Type: application/json; charset=utf-8');

$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'bad run_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$run = stocks_tool_run_get($runId, $cfg);
if (!is_array($run)) {
    http_response_code(404);
    echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$progressTotal = max(0, (int)($run['progress_total'] ?? 0));
$progressCurrent = max(0, (int)($run['progress_current'] ?? 0));
$percent = null;
if ($progressTotal > 0) {
    $percent = round(min(100, ($progressCurrent / $progressTotal) * 100), 1);
}

echo json_encode([
    'id' => (int)($run['id'] ?? 0),
    'status' => (string)($run['status'] ?? ''),
    'started_at' => (string)($run['started_at'] ?? ''),
    'finished_at' => (string)($run['finished_at'] ?? ''),
    'error_text' => (string)($run['error_text'] ?? ''),
    'log_text' => (string)($run['log_text'] ?? ''),
    'summary' => is_array($run['summary'] ?? null) ? $run['summary'] : [],
    'totals' => is_array($run['totals'] ?? null) ? $run['totals'] : [],
    'progress_current' => $progressCurrent,
    'progress_total' => $progressTotal,
    'percent' => $percent,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
