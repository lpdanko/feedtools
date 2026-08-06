<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/llm/OpenAIRequestLog.php';
require_once __DIR__ . '/llm/OpenAIPricing.php';

function employee_analytics_parse_period(array $input): array
{
    $preset = trim((string)($input['preset'] ?? '7d'));
    $today = new DateTimeImmutable('today');

    $make = static function (DateTimeImmutable $from, DateTimeImmutable $to, string $preset): array {
        return [
            'preset' => $preset,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            'from_ts' => $from->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
            'to_ts_exclusive' => $to->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        ];
    };

    if ($preset === 'today') {
        return $make($today, $today, $preset);
    }
    if ($preset === '30d') {
        return $make($today->modify('-29 days'), $today, $preset);
    }
    if ($preset === '90d') {
        return $make($today->modify('-89 days'), $today, $preset);
    }
    if ($preset === 'custom') {
        $fromRaw = trim((string)($input['from'] ?? ''));
        $toRaw = trim((string)($input['to'] ?? ''));
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromRaw) ?: $today->modify('-6 days');
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toRaw) ?: $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return $make($from, $to, $preset);
    }

    return $make($today->modify('-6 days'), $today, '7d');
}

function employee_analytics_fetch(array $cfg, array $period): array
{
    ops_table_ensure();
    OpenAIRequestLog::ensureTable();

    $primaryRows = ea_fetch_operations_in_period(
        (string)$period['from_ts'],
        (string)$period['to_ts_exclusive']
    );

    $rowsById = [];
    foreach ($primaryRows as $row) {
        $rowsById[(int)$row['id']] = $row;
    }

    ea_hydrate_parent_operations($rowsById);

    $primaryIds = [];
    $rootIds = [];
    foreach ($primaryRows as $row) {
        $opId = (int)($row['id'] ?? 0);
        if ($opId <= 0) continue;
        $primaryIds[$opId] = true;
        $rootId = ea_root_op_id_from_map($rowsById[$opId], $rowsById);
        if ($rootId > 0) {
            $rootIds[$rootId] = true;
        }
    }

    $relevantRows = [];
    foreach ($rowsById as $id => $row) {
        $rootId = ea_root_op_id_from_map($row, $rowsById);
        if ($rootId > 0 && isset($rootIds[$rootId])) {
            $row['_root_op_id'] = $rootId;
            $row['_effective_actor'] = ea_effective_actor_from_map($row, $rowsById);
            $relevantRows[$id] = $row;
        }
    }

    $gptByOp = ea_fetch_gpt_usage_by_op($cfg, array_keys($relevantRows));
    $rootOps = ea_build_root_operations($relevantRows, $rootIds, $gptByOp);

    $employeeSummary = [];
    $launchBreakdown = [];
    $stepBreakdown = [];
    $recentActivity = array_values($rootOps);
    $systemSummary = [
        'launches' => 0,
        'processed_items' => 0,
        'gpt_requests' => 0,
        'cost_usd' => 0.0,
    ];

    foreach ($rootOps as $rootId => $root) {
        $actor = ea_human_actor_or_null((string)($root['actor_user'] ?? ''));

        if ($actor === null) {
            $systemSummary['launches']++;
            $systemSummary['processed_items'] += (int)($root['processed_items'] ?? 0);
            $systemSummary['gpt_requests'] += (int)($root['gpt_requests'] ?? 0);
            $systemSummary['cost_usd'] += (float)($root['cost_usd'] ?? 0.0);
            continue;
        }

        if (!isset($employeeSummary[$actor])) {
            $employeeSummary[$actor] = [
                'actor_user' => $actor,
                'launches' => 0,
                'pipelines' => 0,
                'done' => 0,
                'error' => 0,
                'cancelled' => 0,
                'datasets' => [],
                'selected_items' => 0,
                'processed_items' => 0,
                'gpt_requests' => 0,
                'api_requests' => 0,
                'local_hits' => 0,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'billable_input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => 0.0,
                'cache_savings_usd' => 0.0,
                'first_activity_at' => null,
                'last_activity_at' => null,
            ];
        }

        $employeeSummary[$actor]['launches']++;
        if ((string)($root['op_type'] ?? '') === 'run_pipeline') {
            $employeeSummary[$actor]['pipelines']++;
        }
        $status = (string)($root['status'] ?? '');
        if (isset($employeeSummary[$actor][$status])) {
            $employeeSummary[$actor][$status]++;
        }
        $datasetId = (int)($root['dataset_id'] ?? 0);
        if ($datasetId > 0) {
            $employeeSummary[$actor]['datasets'][$datasetId] = true;
        }
        $employeeSummary[$actor]['selected_items'] += (int)($root['selected_items'] ?? 0);
        $employeeSummary[$actor]['processed_items'] += (int)($root['processed_items'] ?? 0);
        foreach (['gpt_requests', 'api_requests', 'local_hits', 'input_tokens', 'cached_input_tokens', 'billable_input_tokens', 'output_tokens'] as $key) {
            $employeeSummary[$actor][$key] += (int)($root[$key] ?? 0);
        }
        foreach (['cost_usd', 'cache_savings_usd'] as $key) {
            $employeeSummary[$actor][$key] += (float)($root[$key] ?? 0.0);
        }
        $employeeSummary[$actor]['first_activity_at'] = ea_min_datetime($employeeSummary[$actor]['first_activity_at'], (string)($root['created_at'] ?? ''));
        $employeeSummary[$actor]['last_activity_at'] = ea_max_datetime(
            $employeeSummary[$actor]['last_activity_at'],
            (string)($root['finished_at'] ?: ($root['started_at'] ?: ($root['created_at'] ?? '')))
        );

        $launchKey = $actor . '|' . (string)($root['op_type'] ?? '');
        if (!isset($launchBreakdown[$launchKey])) {
            $launchBreakdown[$launchKey] = [
                'actor_user' => $actor,
                'op_type' => (string)($root['op_type'] ?? ''),
                'launches' => 0,
                'selected_items' => 0,
                'processed_items' => 0,
                'gpt_requests' => 0,
                'cost_usd' => 0.0,
            ];
        }
        $launchBreakdown[$launchKey]['launches']++;
        $launchBreakdown[$launchKey]['selected_items'] += (int)($root['selected_items'] ?? 0);
        $launchBreakdown[$launchKey]['processed_items'] += (int)($root['processed_items'] ?? 0);
        $launchBreakdown[$launchKey]['gpt_requests'] += (int)($root['gpt_requests'] ?? 0);
        $launchBreakdown[$launchKey]['cost_usd'] += (float)($root['cost_usd'] ?? 0.0);
    }

    foreach ($relevantRows as $row) {
        $rootId = (int)($row['_root_op_id'] ?? 0);
        if ($rootId <= 0 || !isset($rootIds[$rootId])) continue;
        $actor = ea_human_actor_or_null((string)($row['_effective_actor'] ?? ''));
        if ($actor === null) continue;
        $opType = (string)($row['op_type'] ?? '');
        $key = $actor . '|' . $opType;
        if (!isset($stepBreakdown[$key])) {
            $stepBreakdown[$key] = [
                'actor_user' => $actor,
                'op_type' => $opType,
                'runs' => 0,
                'processed_items' => 0,
                'gpt_requests' => 0,
                'cost_usd' => 0.0,
            ];
        }
        $stepBreakdown[$key]['runs']++;
        $stepBreakdown[$key]['processed_items'] += ea_operation_processed_items($row);
        $stepBreakdown[$key]['gpt_requests'] += (int)($gptByOp[(int)$row['id']]['requests'] ?? 0);
        $stepBreakdown[$key]['cost_usd'] += (float)($gptByOp[(int)$row['id']]['cost_usd'] ?? 0.0);
    }

    foreach ($employeeSummary as &$row) {
        $row['datasets_count'] = count($row['datasets']);
        unset($row['datasets']);
        $row['cost_usd'] = round((float)$row['cost_usd'], 6);
        $row['cache_savings_usd'] = round((float)$row['cache_savings_usd'], 6);
    }
    unset($row);

    usort($recentActivity, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    foreach ($recentActivity as &$row) {
        $row['actor_user_display'] = ea_display_actor((string)($row['actor_user'] ?? ''));
        $row['actor_is_system'] = ea_human_actor_or_null((string)($row['actor_user'] ?? '')) === null;
    }
    unset($row);

    $overview = [
        'employees_count' => count($employeeSummary),
        'launches' => 0,
        'pipelines' => 0,
        'selected_items' => 0,
        'processed_items' => 0,
        'gpt_requests' => 0,
        'api_requests' => 0,
        'local_hits' => 0,
        'input_tokens' => 0,
        'cached_input_tokens' => 0,
        'billable_input_tokens' => 0,
        'output_tokens' => 0,
        'cost_usd' => 0.0,
        'cache_savings_usd' => 0.0,
    ];
    foreach ($employeeSummary as $row) {
        foreach (['launches', 'pipelines', 'selected_items', 'processed_items', 'gpt_requests', 'api_requests', 'local_hits', 'input_tokens', 'cached_input_tokens', 'billable_input_tokens', 'output_tokens'] as $key) {
            $overview[$key] += (int)($row[$key] ?? 0);
        }
        foreach (['cost_usd', 'cache_savings_usd'] as $key) {
            $overview[$key] += (float)($row[$key] ?? 0.0);
        }
    }
    $overview['cost_usd'] = round((float)$overview['cost_usd'], 6);
    $overview['cache_savings_usd'] = round((float)$overview['cache_savings_usd'], 6);
    $systemSummary['cost_usd'] = round((float)$systemSummary['cost_usd'], 6);

    $employeeSummary = array_values($employeeSummary);
    $launchBreakdown = array_values($launchBreakdown);
    $stepBreakdown = array_values($stepBreakdown);

    usort($employeeSummary, static function (array $a, array $b): int {
        $costCmp = ((float)($b['cost_usd'] ?? 0.0)) <=> ((float)($a['cost_usd'] ?? 0.0));
        if ($costCmp !== 0) return $costCmp;
        return strcmp((string)($a['actor_user'] ?? ''), (string)($b['actor_user'] ?? ''));
    });
    usort($launchBreakdown, static function (array $a, array $b): int {
        $costCmp = ((float)($b['cost_usd'] ?? 0.0)) <=> ((float)($a['cost_usd'] ?? 0.0));
        if ($costCmp !== 0) return $costCmp;
        return strcmp((string)($a['actor_user'] ?? ''), (string)($b['actor_user'] ?? ''));
    });
    usort($stepBreakdown, static function (array $a, array $b): int {
        $costCmp = ((float)($b['cost_usd'] ?? 0.0)) <=> ((float)($a['cost_usd'] ?? 0.0));
        if ($costCmp !== 0) return $costCmp;
        return strcmp((string)($a['actor_user'] ?? ''), (string)($b['actor_user'] ?? ''));
    });

    return [
        'period' => $period,
        'overview' => $overview,
        'employees' => $employeeSummary,
        'launch_breakdown' => $launchBreakdown,
        'step_breakdown' => $stepBreakdown,
        'recent_activity' => array_slice($recentActivity, 0, 40),
        'system_summary' => $systemSummary,
    ];
}

function ea_fetch_operations_in_period(string $fromTs, string $toTsExclusive): array
{
    $stmt = db()->prepare("
      SELECT
        o.*,
        d.original_filename
      FROM feedtools_operations o
      LEFT JOIN feedtools_datasets d ON d.id = o.dataset_id
      WHERE o.created_at >= ? AND o.created_at < ?
      ORDER BY o.id ASC
    ");
    $stmt->execute([$fromTs, $toTsExclusive]);
    return $stmt->fetchAll() ?: [];
}

function ea_fetch_operations_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
    if (!$ids) return [];

    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        $sql = "
          SELECT
            o.*,
            d.original_filename
          FROM feedtools_operations o
          LEFT JOIN feedtools_datasets d ON d.id = o.dataset_id
          WHERE o.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($chunk);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $out[(int)$row['id']] = $row;
        }
    }

    return $out;
}

function ea_hydrate_parent_operations(array &$rowsById): void
{
    for ($depth = 0; $depth < 6; $depth++) {
        $missing = [];
        foreach ($rowsById as $row) {
            $parentId = ops_detect_pipeline_parent_id($row);
            if ($parentId > 0 && !isset($rowsById[$parentId])) {
                $missing[$parentId] = true;
            }
        }
        if (!$missing) {
            return;
        }
        foreach (ea_fetch_operations_by_ids(array_keys($missing)) as $id => $row) {
            $rowsById[$id] = $row;
        }
    }
}

function ea_root_op_id_from_map(array $row, array $rowsById): int
{
    $currentId = (int)($row['id'] ?? 0);
    if ($currentId <= 0) return 0;

    $seen = [];
    $current = $row;
    for ($depth = 0; $depth < 8; $depth++) {
        $currentId = (int)($current['id'] ?? 0);
        if ($currentId <= 0 || isset($seen[$currentId])) {
            break;
        }
        $seen[$currentId] = true;
        $parentId = ops_detect_pipeline_parent_id($current);
        if ($parentId <= 0 || !isset($rowsById[$parentId])) {
            return $currentId;
        }
        $current = $rowsById[$parentId];
    }
    return $currentId;
}

function ea_effective_actor_from_map(array $row, array $rowsById): string
{
    $rootId = ea_root_op_id_from_map($row, $rowsById);
    $root = $rowsById[$rootId] ?? $row;
    foreach ([$root, $row] as $candidate) {
        $value = trim((string)($candidate['created_by'] ?? ''));
        if ($value !== '' && $value !== 'cli') {
            return $value;
        }
    }
    $fallback = trim((string)($root['created_by'] ?? ($row['created_by'] ?? 'cli')));
    return $fallback !== '' ? $fallback : 'cli';
}

function ea_human_actor_or_null(string $actor): ?string
{
    $actor = trim($actor);
    if ($actor === '') return null;
    $normalized = strtolower($actor);
    if (in_array($normalized, ['cli', 'system', 'worker', 'cron', 'internal'], true)) {
        return null;
    }
    return $actor;
}

function ea_display_actor(string $actor): string
{
    $human = ea_human_actor_or_null($actor);
    return $human !== null ? $human : 'system';
}

function ea_fetch_gpt_usage_by_op(array $cfg, array $opIds): array
{
    $opIds = array_values(array_unique(array_filter(array_map('intval', $opIds), static fn(int $v): bool => $v > 0)));
    if (!$opIds) return [];

    $out = [];
    foreach (array_chunk($opIds, 300) as $chunk) {
        $sql = "
          SELECT
            op_id,
            model,
            COUNT(*) AS requests,
            SUM(CASE WHEN local_cache_hit = 0 THEN 1 ELSE 0 END) AS api_requests,
            SUM(CASE WHEN local_cache_hit = 1 THEN 1 ELSE 0 END) AS local_hits,
            COALESCE(SUM(input_tokens), 0) AS input_tokens,
            COALESCE(SUM(cached_input_tokens), 0) AS cached_input_tokens,
            COALESCE(SUM(output_tokens), 0) AS output_tokens
          FROM feedtools_openai_requests
          WHERE op_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
          GROUP BY op_id, model
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($chunk);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $opId = (int)($row['op_id'] ?? 0);
            if ($opId <= 0) continue;
            if (!isset($out[$opId])) {
                $out[$opId] = [
                    'requests' => 0,
                    'api_requests' => 0,
                    'local_hits' => 0,
                    'input_tokens' => 0,
                    'cached_input_tokens' => 0,
                    'billable_input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost_usd' => 0.0,
                    'cache_savings_usd' => 0.0,
                ];
            }

            $inputTokens = (int)($row['input_tokens'] ?? 0);
            $cachedTokens = (int)($row['cached_input_tokens'] ?? 0);
            $outputTokens = (int)($row['output_tokens'] ?? 0);
            $cost = openai_cost_breakdown($cfg, (string)($row['model'] ?? ''), $inputTokens, $cachedTokens, $outputTokens);

            $out[$opId]['requests'] += (int)($row['requests'] ?? 0);
            $out[$opId]['api_requests'] += (int)($row['api_requests'] ?? 0);
            $out[$opId]['local_hits'] += (int)($row['local_hits'] ?? 0);
            $out[$opId]['input_tokens'] += $inputTokens;
            $out[$opId]['cached_input_tokens'] += $cachedTokens;
            $out[$opId]['billable_input_tokens'] += (int)($cost['billable_input_tokens'] ?? 0);
            $out[$opId]['output_tokens'] += $outputTokens;
            $out[$opId]['cost_usd'] += (float)($cost['cost_usd'] ?? 0.0);
            $out[$opId]['cache_savings_usd'] += (float)($cost['cache_savings_usd'] ?? 0.0);
        }
    }

    foreach ($out as &$row) {
        $row['cost_usd'] = round((float)$row['cost_usd'], 6);
        $row['cache_savings_usd'] = round((float)$row['cache_savings_usd'], 6);
    }
    unset($row);

    return $out;
}

function ea_build_root_operations(array $relevantRows, array $rootIds, array $gptByOp): array
{
    $roots = [];
    foreach ($rootIds as $rootId => $_) {
        if (!isset($relevantRows[$rootId])) continue;
        $rootRow = $relevantRows[$rootId];
        $roots[$rootId] = [
            'id' => $rootId,
            'dataset_id' => (int)($rootRow['dataset_id'] ?? 0),
            'original_filename' => (string)($rootRow['original_filename'] ?? ''),
            'op_type' => (string)($rootRow['op_type'] ?? ''),
            'status' => (string)($rootRow['status'] ?? ''),
            'created_at' => (string)($rootRow['created_at'] ?? ''),
            'started_at' => (string)($rootRow['started_at'] ?? ''),
            'finished_at' => (string)($rootRow['finished_at'] ?? ''),
            'created_by' => (string)($rootRow['created_by'] ?? ''),
            'actor_user' => (string)($rootRow['_effective_actor'] ?? '—'),
            'selected_items' => ea_operation_selected_items($rootRow),
            'processed_items' => max(ea_operation_processed_items($rootRow), ea_operation_selected_items($rootRow)),
            'gpt_requests' => 0,
            'api_requests' => 0,
            'local_hits' => 0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'billable_input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0.0,
            'cache_savings_usd' => 0.0,
            'duration_sec' => ea_operation_duration_sec($rootRow),
            'step_count' => 0,
            'step_ops' => [],
        ];
    }

    foreach ($relevantRows as $row) {
        $opId = (int)($row['id'] ?? 0);
        $rootId = (int)($row['_root_op_id'] ?? 0);
        if ($opId <= 0 || $rootId <= 0 || !isset($roots[$rootId])) continue;

        $usage = $gptByOp[$opId] ?? null;
        if ($usage) {
            foreach (['gpt_requests' => 'requests', 'api_requests' => 'api_requests', 'local_hits' => 'local_hits', 'input_tokens' => 'input_tokens', 'cached_input_tokens' => 'cached_input_tokens', 'billable_input_tokens' => 'billable_input_tokens', 'output_tokens' => 'output_tokens'] as $to => $from) {
                $roots[$rootId][$to] += (int)($usage[$from] ?? 0);
            }
            $roots[$rootId]['cost_usd'] += (float)($usage['cost_usd'] ?? 0.0);
            $roots[$rootId]['cache_savings_usd'] += (float)($usage['cache_savings_usd'] ?? 0.0);
        }

        if ($opId !== $rootId) {
            $roots[$rootId]['step_count']++;
            $roots[$rootId]['step_ops'][] = (string)($row['op_type'] ?? '');
        }
    }

    foreach ($roots as &$row) {
        $row['cost_usd'] = round((float)$row['cost_usd'], 6);
        $row['cache_savings_usd'] = round((float)$row['cache_savings_usd'], 6);
        $row['step_ops'] = array_values(array_unique(array_filter($row['step_ops'], static fn(string $v): bool => trim($v) !== '')));
    }
    unset($row);

    return $roots;
}

function ea_decode_json_string(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ea_operation_selected_items(array $row): int
{
    $summary = ea_decode_json_string((string)($row['summary_json'] ?? ''));
    $params = ea_decode_json_string((string)($row['params_json'] ?? ''));

    $value = ea_int_from_candidates([
        $summary['pipeline']['selected_offers'] ?? null,
        $summary['metrics']['selected_matched_in_feed'] ?? null,
        $summary['metrics']['selected_requested'] ?? null,
        isset($params['offer_ids']) && is_array($params['offer_ids']) ? count($params['offer_ids']) : null,
    ]);

    return max(0, $value);
}

function ea_operation_processed_items(array $row): int
{
    $summary = ea_decode_json_string((string)($row['summary_json'] ?? ''));
    $params = ea_decode_json_string((string)($row['params_json'] ?? ''));
    $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];

    $value = ea_int_from_candidates([
        $metrics['offers_touched'] ?? null,
        $metrics['processed'] ?? null,
        $metrics['selected_matched_in_feed'] ?? null,
        $metrics['offers_desc_processed'] ?? null,
        $metrics['offers_with_hashtags'] ?? null,
        $metrics['offers_with_inserted_colors'] ?? null,
        $metrics['offers_to_write'] ?? null,
        $metrics['fixed'] ?? null,
        $metrics['rows_exported'] ?? null,
        $metrics['wb_products_processed'] ?? null,
        $metrics['candidates'] ?? null,
        $summary['pipeline']['selected_offers'] ?? null,
        isset($params['offer_ids']) && is_array($params['offer_ids']) ? count($params['offer_ids']) : null,
    ]);

    return max(0, $value);
}

function ea_operation_duration_sec(array $row): int
{
    $started = trim((string)($row['started_at'] ?? ''));
    $finished = trim((string)($row['finished_at'] ?? ''));
    if ($started !== '' && $finished !== '') {
        $startedTs = strtotime($started) ?: 0;
        $finishedTs = strtotime($finished) ?: 0;
        if ($startedTs > 0 && $finishedTs >= $startedTs) {
            return max(0, $finishedTs - $startedTs);
        }
    }
    return ops_elapsed_sec($row);
}

function ea_int_from_candidates(array $values): int
{
    foreach ($values as $value) {
        if ($value === null || $value === '') continue;
        return max(0, (int)$value);
    }
    return 0;
}

function ea_min_datetime(?string $current, string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $current;
    if ($current === null || trim($current) === '') return $candidate;
    return strcmp($candidate, $current) < 0 ? $candidate : $current;
}

function ea_max_datetime(?string $current, string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $current;
    if ($current === null || trim($current) === '') return $candidate;
    return strcmp($candidate, $current) > 0 ? $candidate : $current;
}
