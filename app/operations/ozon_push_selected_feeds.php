<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_products.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../ozon_actions.php';
require_once __DIR__ . '/../ozon_fbo_tool.php';

function ozon_push_log_primary_reason(array $validation, array $calcWarnings): string
{
    foreach ($calcWarnings as $warning) {
        $warning = trim((string)$warning);
        if ($warning === '') {
            continue;
        }
        if (str_contains($warning, 'Товара нет на Ozon')) {
            return $warning;
        }
    }
    foreach ($calcWarnings as $warning) {
        $warning = trim((string)$warning);
        if ($warning !== '') {
            return $warning;
        }
    }
    $reason = trim((string)($validation['reason'] ?? ''));
    return $reason !== '' ? $reason : 'Не удалось собрать валидные цены для выгрузки.';
}

function ozon_push_log_action_title(string $title): string
{
    $title = trim($title);
    if ($title === 'Эластичный бустинг. Без ограничения срока действия') {
        return 'Эл. бустинг.';
    }
    return $title;
}

function ozon_push_log_action_summary(array $desiredState, ?array $applyResult = null): string
{
    $parts = [];
    $upsertSource = is_array($applyResult) && !empty($applyResult['actions_upsert'])
        ? (array)$applyResult['actions_upsert']
        : (array)($desiredState['actions_upsert'] ?? []);
    $upserts = [];
    foreach ($upsertSource as $action) {
        $title = ozon_push_log_action_title((string)($action['title'] ?? 'Акция Ozon'));
        $changeType = (string)($action['change_type'] ?? 'add');
        $price = (float)($action['action_price'] ?? 0);
        $status = 'sent';
        $response = (array)($action['response'] ?? []);
        $result = (array)($response['result'] ?? []);
        if (!empty($result['rejected'])) {
            $status = 'rejected';
        } elseif (!empty($result['product_ids'])) {
            $status = 'ok';
        }
        $upserts[] = $title . ' [' . $changeType . ' ' . $status . '] @ ' . number_format($price, 2, '.', '');
    }
    if ($upserts) {
        $parts[] = 'акции=' . implode('; ', $upserts);
    }

    $removeSource = is_array($applyResult) && !empty($applyResult['actions_remove'])
        ? (array)$applyResult['actions_remove']
        : (array)($desiredState['actions_remove'] ?? []);
    $removes = [];
    foreach ($removeSource as $action) {
        $title = ozon_push_log_action_title((string)($action['title'] ?? 'Акция Ozon'));
        $status = 'sent';
        $response = (array)($action['response'] ?? []);
        $result = (array)($response['result'] ?? []);
        if (!empty($result['rejected'])) {
            $status = 'rejected';
        } elseif (!empty($result['product_ids'])) {
            $status = 'ok';
        }
        $removes[] = $title . ' [remove ' . $status . ']';
    }
    if ($removes) {
        $parts[] = 'удалить=' . implode('; ', $removes);
    }

    return $parts ? implode(' | ', $parts) : 'акции=нет изменений';
}

function ozon_push_apply_result_default(array $desiredState): array
{
    return [
        'offer_id' => trim((string)($desiredState['offer_id'] ?? '')),
        'price_import' => null,
        'price_import_skipped' => false,
        'price_import_skip_reason' => '',
        'actions_upsert' => [],
        'actions_remove' => [],
        'errors' => [],
        'status' => 'ok',
    ];
}

function ozon_push_batch_marker(int $batchSize): array
{
    return [
        'batched' => true,
        'batch_size' => $batchSize,
        'accepted' => true,
    ];
}

function ozon_push_price_import_bool($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (float)$value !== 0.0;
    }
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true', 'yes', 'ok', 'success', 'updated'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'error', 'failed', 'fail', 'rejected'], true)) {
            return false;
        }
    }
    return null;
}

function ozon_push_price_import_error_messages($value): array
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }
    if (is_string($value) || is_numeric($value)) {
        $message = trim((string)$value);
        return $message !== '' ? [$message] : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $direct = trim((string)($value['message'] ?? $value['error_message'] ?? $value['description'] ?? ''));
    if ($direct !== '') {
        $code = trim((string)($value['code'] ?? $value['error_code'] ?? ''));
        $field = trim((string)($value['field'] ?? $value['attribute'] ?? ''));
        $prefixParts = array_values(array_filter([$code, $field], static fn(string $part): bool => $part !== ''));
        return [($prefixParts ? implode(': ', $prefixParts) . ': ' : '') . $direct];
    }

    $messages = [];
    $children = array_is_list($value)
        ? $value
        : array_intersect_key($value, array_flip(['errors', 'error', 'messages', 'message', 'details', 'reasons']));
    foreach ($children as $child) {
        foreach (ozon_push_price_import_error_messages($child) as $message) {
            if ($message !== '') {
                $messages[] = $message;
            }
        }
    }
    return array_values(array_unique($messages));
}

function ozon_push_price_import_row_like(array $row): bool
{
    foreach (['offer_id', 'offerId', 'product_id', 'productId', 'updated', 'success', 'status', 'errors', 'error'] as $key) {
        if (array_key_exists($key, $row)) {
            return true;
        }
    }
    return false;
}

function ozon_push_price_import_collect_rows(array $value, int $depth = 0): array
{
    if ($depth > 4) {
        return [];
    }
    if (ozon_push_price_import_row_like($value)) {
        return [$value];
    }

    $out = [];
    if (array_is_list($value)) {
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach (ozon_push_price_import_collect_rows($item, $depth + 1) as $row) {
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    foreach (['result', 'results', 'items', 'products', 'prices'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            foreach (ozon_push_price_import_collect_rows((array)$value[$key], $depth + 1) as $row) {
                $out[] = $row;
            }
        }
    }
    return $out;
}

function ozon_push_price_import_match(array $response, array $priceRow): array
{
    $offerId = trim((string)($priceRow['offer_id'] ?? ''));
    $productId = (int)($priceRow['product_id'] ?? 0);
    $items = ozon_push_price_import_collect_rows($response);
    $overallErrors = ozon_push_price_import_error_messages($response['errors'] ?? ($response['error'] ?? null));

    if (!$items) {
        return [
            'ok' => !$overallErrors,
            'matched' => false,
            'items_count' => 0,
            'item' => null,
            'errors' => $overallErrors,
        ];
    }

    foreach ($items as $item) {
        $itemOffer = trim((string)($item['offer_id'] ?? ($item['offerId'] ?? '')));
        $itemProduct = (int)($item['product_id'] ?? ($item['productId'] ?? 0));
        $offerMatches = $offerId !== '' && $itemOffer !== '' && $itemOffer === $offerId;
        $productMatches = $productId > 0 && $itemProduct > 0 && $itemProduct === $productId;
        if (!$offerMatches && !$productMatches) {
            continue;
        }

        $errors = ozon_push_price_import_error_messages($item['errors'] ?? ($item['error'] ?? null));
        $updated = ozon_push_price_import_bool($item['updated'] ?? ($item['success'] ?? null));
        $status = strtolower(trim((string)($item['status'] ?? '')));
        if ($updated === false) {
            $errors[] = 'Ozon вернул updated=false для товара.';
        }
        if (in_array($status, ['error', 'failed', 'fail', 'rejected'], true)) {
            $errors[] = 'Ozon вернул статус ' . $status . '.';
        }

        return [
            'ok' => !$errors,
            'matched' => true,
            'items_count' => count($items),
            'item' => $item,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    return [
        'ok' => false,
        'matched' => false,
        'items_count' => count($items),
        'item' => null,
        'errors' => [
            'Ozon принял HTTP-запрос, но не вернул результат по этому offer_id/product_id.',
        ],
    ];
}

function ozon_push_price_import_log_payload(array $response, array $match, int $batchSize): array
{
    $payload = ozon_push_batch_marker($batchSize);
    $payload['matched'] = !empty($match['matched']);
    $payload['response_items'] = (int)($match['items_count'] ?? 0);
    if (is_array($match['item'] ?? null)) {
        $payload['item'] = (array)$match['item'];
    } elseif ((int)($match['items_count'] ?? 0) === 0) {
        $payload['response'] = $response;
    }
    return $payload;
}

function ozon_push_fbo_direct_desired_state(string $offerId, int $productId, float $targetPrice, array $row, array $rule, array $state): array
{
    $currentPrice = isset($row['price']) && (float)$row['price'] > 0 ? ozon_price_round_rub((float)$row['price']) : null;
    $currentMinPrice = isset($row['min_price']) && (float)$row['min_price'] > 0 ? ozon_price_round_rub((float)$row['min_price']) : null;
    $currentOldPrice = isset($row['old_price']) && (float)$row['old_price'] > 0 ? ozon_price_round_rub((float)$row['old_price']) : null;
    $targetPrice = ozon_price_round_rub($targetPrice);
    $oldPrice = ozon_price_payload_old_price($targetPrice, null, $currentOldPrice);

    return [
        'offer_id' => $offerId,
        'product_id' => $productId,
        'mode' => 'fbo_direct',
        'regular_price' => $targetPrice,
        'min_price' => $targetPrice,
        'old_price' => $oldPrice,
        'strike_price' => null,
        'marketing_price' => $targetPrice,
        'base_regular_price' => $currentPrice,
        'base_min_price' => $currentMinPrice,
        'base_marketing_price' => isset($row['marketing_seller_price']) && (float)$row['marketing_seller_price'] > 0
            ? ozon_price_round_rub((float)$row['marketing_seller_price'])
            : null,
        'current_regular_price' => $currentPrice,
        'current_min_price' => $currentMinPrice,
        'current_old_price' => $currentOldPrice,
        'force_rule' => [
            'target' => $offerId,
            'mode' => 'set_fixed',
            'value' => $targetPrice,
            'label' => 'FBO цена ' . $targetPrice . ' ₽',
            'matched_by' => 'fbo_offer_id',
            'matched_value' => $offerId,
            'fbo_rule' => $rule,
            'fbo_state' => $state,
        ],
        'price_adjustment' => [
            'applied' => true,
            'type' => 'fbo',
            'label' => 'FBO цена ' . $targetPrice . ' ₽',
            'source_line' => 'FBO Tool direct: ' . $offerId . ' = ' . $targetPrice,
            'regular_price_before' => $currentPrice,
            'regular_price_after' => $targetPrice,
            'min_price_before' => $currentMinPrice,
            'min_price_after' => $targetPrice,
            'marketing_price_before' => isset($row['marketing_seller_price']) && (float)$row['marketing_seller_price'] > 0
                ? ozon_price_round_rub((float)$row['marketing_seller_price'])
                : null,
            'marketing_price_after' => $targetPrice,
        ],
        'validation' => [
            'regular_price' => $targetPrice,
            'min_price' => $targetPrice,
            'notes' => ['FBO Tool: цена отправлена напрямую из сохранённого правила, без зависимости от XML-фида.'],
            'is_valid' => true,
            'reason' => '',
        ],
        'calc_warnings' => [],
        'price_changed' => $currentPrice === null || abs($targetPrice - $currentPrice) > 0.01,
        'min_price_changed' => $currentMinPrice === null || abs($targetPrice - $currentMinPrice) > 0.01,
        'desired_actions' => [],
        'current_actions' => [],
        'actions_upsert' => [],
        'actions_remove' => [],
        'summary' => [
            'actions_add_count' => 0,
            'actions_update_count' => 0,
            'actions_remove_count' => 0,
        ],
        'reason' => 'FBO Tool direct price push.',
    ];
}

function ozon_push_fbo_restore_old_price(float $regularPrice, ?float $historicalOldPrice = null): float
{
    $regularPrice = ozon_price_round_rub($regularPrice);
    $oldPrice = ozon_price_payload_old_price($regularPrice, $historicalOldPrice);
    if ($regularPrice <= 400.0) {
        return max($oldPrice, ozon_price_round_rub($regularPrice + 25.0));
    }
    if ($regularPrice <= 10000.0) {
        return max($oldPrice, ozon_price_round_rub(ceil($regularPrice / 0.90)));
    }
    return max($oldPrice, ozon_price_round_rub($regularPrice + 600.0));
}

function ozon_push_fbo_regular_states_from_history(int $connectionId, array $offerIds): array
{
    $offerIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        return [];
    }

    $states = [];
    foreach (array_chunk($offerIds, 300) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT id, offer_id, product_id, desired_state_json
            FROM feedtools_ozon_price_push_log
            WHERE connection_id = ?
              AND offer_id IN ({$placeholders})
              AND JSON_UNQUOTE(JSON_EXTRACT(desired_state_json, '$.price_adjustment.type')) = 'fbo'
            ORDER BY id DESC
            LIMIT 10000
        ");
        $st->execute(array_merge([$connectionId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId === '' || isset($states[$offerId])) {
                continue;
            }
            $historical = json_decode((string)($row['desired_state_json'] ?? ''), true);
            if (!is_array($historical)) {
                continue;
            }

            $regularPrice = ozon_price_round_rub((float)($historical['base_regular_price'] ?? 0));
            $minPrice = ozon_price_round_rub((float)($historical['base_min_price'] ?? 0));
            $fboPrice = ozon_price_round_rub((float)($historical['regular_price'] ?? 0));
            $fboMinPrice = ozon_price_round_rub((float)($historical['min_price'] ?? 0));
            if ($regularPrice <= 0) {
                continue;
            }
            if ($minPrice <= 0) {
                $minPrice = $regularPrice;
            }
            $minPrice = min($regularPrice, $minPrice);

            // Repeated FBO pushes can contain the already reduced price as their base.
            // Keep scanning until we find the snapshot taken before the first reduction.
            if (abs($regularPrice - $fboPrice) <= 0.01 && abs($minPrice - $fboMinPrice) <= 0.01) {
                continue;
            }

            $productId = (int)($row['product_id'] ?? ($historical['product_id'] ?? 0));
            $historicalOldPrice = (float)($historical['current_old_price'] ?? ($historical['old_price'] ?? 0));
            $oldPrice = ozon_push_fbo_restore_old_price(
                $regularPrice,
                $historicalOldPrice > 0 ? $historicalOldPrice : null
            );
            $states[$offerId] = [
                'offer_id' => $offerId,
                'product_id' => $productId,
                'mode' => 'fbo_history_restore',
                'regular_price' => $regularPrice,
                'min_price' => $minPrice,
                'old_price' => $oldPrice,
                'strike_price' => null,
                'marketing_price' => null,
                'base_regular_price' => $regularPrice,
                'base_min_price' => $minPrice,
                'base_marketing_price' => null,
                'current_regular_price' => $fboPrice > 0 ? $fboPrice : null,
                'current_min_price' => $fboMinPrice > 0 ? $fboMinPrice : null,
                'current_old_price' => $historicalOldPrice > 0 ? $historicalOldPrice : null,
                'force_rule' => null,
                'price_adjustment' => [
                    'applied' => true,
                    'type' => 'fbo_history_restore',
                    'label' => 'Обычная цена до FBO',
                    'source_line' => 'FBO price history #' . (int)($row['id'] ?? 0),
                    'regular_price_before' => $fboPrice > 0 ? $fboPrice : null,
                    'regular_price_after' => $regularPrice,
                    'min_price_before' => $fboMinPrice > 0 ? $fboMinPrice : null,
                    'min_price_after' => $minPrice,
                ],
                'validation' => [
                    'regular_price' => $regularPrice,
                    'min_price' => $minPrice,
                    'notes' => ['Товар отсутствует в актуальных XML-фидах; возвращена подтверждённая цена до включения FBO Tool.'],
                    'is_valid' => true,
                    'reason' => '',
                ],
                'calc_warnings' => [],
                'price_changed' => true,
                'min_price_changed' => true,
                'desired_actions' => [],
                'current_actions' => [],
                'actions_upsert' => [],
                'actions_remove' => [],
                'summary' => [
                    'actions_add_count' => 0,
                    'actions_update_count' => 0,
                    'actions_remove_count' => 0,
                ],
                'reason' => 'Restored from the last regular price snapshot before FBO reduction.',
                'history_push_log_id' => (int)($row['id'] ?? 0),
            ];
        }
    }

    return $states;
}

function ozon_push_selected_fbo_prices_direct(array $cfg, int $connectionId, array $selectedOfferIds, ?string $actor, int $opId, callable $log): array
{
    $selectedOfferIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $selectedOfferIds
    ))));
    $summary = [
        'feeds_selected' => 0,
        'feeds_processed' => 0,
        'offers_total' => count($selectedOfferIds),
        'offers_processed' => 0,
        'offers_applied' => 0,
        'offers_partial_error' => 0,
        'offers_skipped' => 0,
        'offers_failed' => 0,
        'fbo_direct' => true,
        'fbo_active' => 0,
        'rules_annulled' => 0,
    ];
    $feedReport = [
        'feed_id' => 0,
        'feed_name' => 'FBO Tool direct',
        'offers_total' => count($selectedOfferIds),
        'offers_processed' => 0,
        'offers_applied' => 0,
        'offers_partial_error' => 0,
        'offers_skipped' => 0,
        'offers_failed' => 0,
        'error' => '',
        'direct' => true,
    ];

    if (!$selectedOfferIds || $connectionId <= 0) {
        ops_update_progress($opId, 1, 1, 'done', 'FBO Tool: нет выбранных товаров для отправки.');
        return [
            'summary_json_inline' => $summary + ['feeds' => [$feedReport]],
            'outputs' => ['ozon_push_selected_feeds' => true, 'fbo_direct_push' => true],
        ];
    }

    ops_update_progress($opId, 0, max(1, count($selectedOfferIds)), 'fbo_preflight', 'Обновляем FBO-остатки перед прямой выгрузкой сниженных цен...');
    $log("fbo_direct: refreshing selected FBO stocks before price push\n");
    $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $selectedOfferIds, $cfg, $log);
    $summary['fbo_active'] = (int)($refresh['fbo_active'] ?? 0);
    $summary['rules_annulled'] = (int)($refresh['rules_annulled'] ?? 0);
    $log('fbo_direct: requested=' . (int)($refresh['requested'] ?? 0)
        . ' · active_fbo=' . (int)($refresh['fbo_active'] ?? 0)
        . ' · rules_annulled=' . (int)($refresh['rules_annulled'] ?? 0) . "\n");

    $rows = ozon_fbo_tool_items($connectionId, [
        'only_with_stock' => false,
        'offer_ids' => $selectedOfferIds,
        'limit' => max(1, count($selectedOfferIds)),
    ], $cfg);
    $rowsByOffer = [];
    foreach ($rows as $row) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        if ($offerId !== '') {
            $rowsByOffer[$offerId] = $row;
        }
    }
    $rulesByOffer = ozon_fbo_tool_price_rules_by_offer($connectionId, $selectedOfferIds, $cfg);

    $pendingRows = [];
    foreach ($selectedOfferIds as $offerId) {
        $summary['offers_processed']++;
        $feedReport['offers_processed']++;
        $row = is_array($rowsByOffer[$offerId] ?? null) ? (array)$rowsByOffer[$offerId] : [];
        $rule = is_array($rulesByOffer[$offerId] ?? null) ? (array)$rulesByOffer[$offerId] : null;
        $units = $row ? ozon_fbo_tool_row_units($row) : 0;
        $state = ozon_fbo_tool_rule_state($rule, $units);
        $targetPrice = is_array($rule) ? ozon_price_round_rub((float)($rule['target_price'] ?? 0)) : 0.0;
        $rowPrefix = $summary['offers_processed'] . '. ' . $offerId;

        if (!$row) {
            $summary['offers_skipped']++;
            $feedReport['offers_skipped']++;
            $log("{$rowPrefix}: skipped, FBO-товар не найден в локальной таблице после обновления остатков.\n");
            continue;
        }
        if ($targetPrice <= 0 || empty($state['has_rule'])) {
            $summary['offers_skipped']++;
            $feedReport['offers_skipped']++;
            $log("{$rowPrefix}: skipped, нет сохранённой сниженной FBO-цены.\n");
            continue;
        }
        if (empty($state['is_active'])) {
            $summary['offers_skipped']++;
            $feedReport['offers_skipped']++;
            $reason = trim((string)($state['status_label'] ?? 'правило не активно'));
            $log("{$rowPrefix}: skipped, FBO-правило не активно ({$reason}), остаток={$units}.\n");
            continue;
        }

        $desiredState = ozon_push_fbo_direct_desired_state(
            $offerId,
            (int)($row['product_id'] ?? 0),
            $targetPrice,
            $row,
            $rule,
            $state
        );
        $pendingRows[] = [
            'offer_id' => $offerId,
            'row_prefix' => $rowPrefix,
            'desired_state' => $desiredState,
            'price_row' => [
                'offer_id' => $offerId,
                'product_id' => (int)($row['product_id'] ?? 0),
                'price' => $targetPrice,
                'min_price' => $targetPrice,
                'old_price' => (float)($desiredState['old_price'] ?? 0),
                'current_old_price' => $desiredState['current_old_price'] ?? null,
            ],
        ];
    }

    if (!$pendingRows) {
        ops_update_progress($opId, max(1, $summary['offers_processed']), max(1, $summary['offers_total']), 'done', 'FBO Tool: нет активных сниженных цен для отправки.');
        $feedReport['error'] = 'Нет активных сниженных FBO-цен для отправки после обновления остатков.';
        return [
            'summary_json_inline' => $summary + ['feeds' => [$feedReport]],
            'outputs' => ['ozon_push_selected_feeds' => true, 'fbo_direct_push' => true],
        ];
    }

    ops_update_progress($opId, $summary['offers_processed'], max(1, $summary['offers_total']), 'push_prices', 'Отправляем сниженные FBO-цены напрямую в Ozon...');
    foreach (array_chunk($pendingRows, 100) as $chunk) {
        $priceRows = array_values(array_map(static fn(array $pendingRow): array => (array)$pendingRow['price_row'], $chunk));
        $chunkSize = count($priceRows);
        try {
            $response = ozon_price_import_prices($cfg, $priceRows);
            foreach ($chunk as $pendingRow) {
                $offerId = (string)($pendingRow['offer_id'] ?? '');
                $rowPrefix = (string)($pendingRow['row_prefix'] ?? $offerId);
                $desiredState = (array)($pendingRow['desired_state'] ?? []);
                $targetPrice = (float)($desiredState['regular_price'] ?? 0);
                $priceRow = (array)($pendingRow['price_row'] ?? []);
                $match = ozon_push_price_import_match($response, $priceRow);
                $result = [
                    'status' => !empty($match['ok']) ? 'ok' : 'partial_error',
                    'price_import' => ozon_push_price_import_log_payload($response, $match, $chunkSize),
                    'batch' => ozon_push_batch_marker($chunkSize),
                    'errors' => (array)($match['errors'] ?? []),
                ];
                if (!empty($match['ok'])) {
                    $summary['offers_applied']++;
                    $feedReport['offers_applied']++;
                    ozon_price_push_log_write(0, $actor, $offerId, (int)($desiredState['product_id'] ?? 0), 'ok', $desiredState, $result, $connectionId, $cfg);
                    $log("{$rowPrefix}: ok, FBO direct price={$targetPrice}, min_price={$targetPrice}\n");
                } else {
                    $summary['offers_partial_error']++;
                    $feedReport['offers_partial_error']++;
                    ozon_price_push_log_write(0, $actor, $offerId, (int)($desiredState['product_id'] ?? 0), 'partial_error', $desiredState, $result, $connectionId, $cfg);
                    $errorText = implode('; ', array_map(static fn($v): string => trim((string)$v), (array)($match['errors'] ?? [])));
                    $log("{$rowPrefix}: error, Ozon did not confirm direct FBO price={$targetPrice}: {$errorText}\n");
                }
            }
        } catch (Throwable $e) {
            foreach ($chunk as $pendingRow) {
                $offerId = (string)($pendingRow['offer_id'] ?? '');
                $rowPrefix = (string)($pendingRow['row_prefix'] ?? $offerId);
                $desiredState = (array)($pendingRow['desired_state'] ?? []);
                $result = [
                    'status' => 'partial_error',
                    'errors' => ['Не удалось обновить FBO-цену напрямую: ' . $e->getMessage()],
                ];
                $summary['offers_partial_error']++;
                $feedReport['offers_partial_error']++;
                ozon_price_push_log_write(0, $actor, $offerId, (int)($desiredState['product_id'] ?? 0), 'partial_error', $desiredState, $result, $connectionId, $cfg);
                $log("{$rowPrefix}: error, direct FBO price push failed: {$e->getMessage()}\n");
            }
        }
    }

    ops_update_progress($opId, max(1, $summary['offers_processed']), max(1, $summary['offers_total']), 'done', 'Прямая выгрузка сниженных FBO-цен завершена.');
    $log("fbo_direct_done: applied={$feedReport['offers_applied']}, partial={$feedReport['offers_partial_error']}, skipped={$feedReport['offers_skipped']}\n");

    return [
        'summary_json_inline' => $summary + ['feeds' => [$feedReport]],
        'outputs' => [
            'ozon_push_selected_feeds' => true,
            'fbo_direct_push' => true,
        ],
    ];
}

function ozon_push_apply_desired_states_batched(array $cfg, array $feed, array $pendingRows, ?string $actor, ?int $connectionId): array
{
    $results = [];
    $desiredByOffer = [];
    $priceRows = [];
    $actionUpsertGroups = [];
    $actionRemoveGroups = [];

    foreach ($pendingRows as $pendingRow) {
        if (!is_array($pendingRow)) {
            continue;
        }
        $desiredState = is_array($pendingRow['desired_state'] ?? null) ? (array)$pendingRow['desired_state'] : [];
        $offerId = trim((string)($desiredState['offer_id'] ?? ''));
        if ($offerId === '') {
            continue;
        }
        $desiredByOffer[$offerId] = $desiredState;
        $results[$offerId] = ozon_push_apply_result_default($desiredState);

        $priceChanged = !empty($desiredState['price_changed']) || !empty($desiredState['min_price_changed']);
        if ($priceChanged) {
            $priceRows[] = [
                'offer_id' => $offerId,
                'product_id' => (int)($desiredState['product_id'] ?? 0),
                'price' => (float)($desiredState['regular_price'] ?? 0),
                'min_price' => (float)($desiredState['min_price'] ?? 0),
                'old_price' => (float)($desiredState['old_price'] ?? 0),
                'current_old_price' => $desiredState['current_old_price'] ?? null,
            ];
        } else {
            $results[$offerId]['price_import_skipped'] = true;
            $results[$offerId]['price_import_skip_reason'] = 'Текущие price и min_price уже совпадают с расчётными.';
        }

        foreach ((array)($desiredState['actions_upsert'] ?? []) as $actionRow) {
            if (!is_array($actionRow)) {
                continue;
            }
            $actionId = (int)($actionRow['action_id'] ?? 0);
            $productId = (int)($actionRow['product_id'] ?? 0);
            $actionPrice = round((float)($actionRow['action_price'] ?? 0), 2);
            if ($actionId <= 0 || $productId <= 0 || $actionPrice <= 0) {
                continue;
            }
            $actionUpsertGroups[$actionId] ??= [
                'products' => [],
                'members' => [],
            ];
            $actionUpsertGroups[$actionId]['products'][$productId] = [
                'product_id' => $productId,
                'action_price' => $actionPrice,
            ];
            $actionUpsertGroups[$actionId]['members'][] = [
                'offer_id' => $offerId,
                'action' => $actionRow,
            ];
        }

        foreach ((array)($desiredState['actions_remove'] ?? []) as $actionRow) {
            if (!is_array($actionRow)) {
                continue;
            }
            $actionId = (int)($actionRow['action_id'] ?? 0);
            $productId = (int)($actionRow['product_id'] ?? 0);
            if ($actionId <= 0 || $productId <= 0) {
                continue;
            }
            $actionRemoveGroups[$actionId] ??= [
                'product_ids' => [],
                'members' => [],
            ];
            $actionRemoveGroups[$actionId]['product_ids'][$productId] = $productId;
            $actionRemoveGroups[$actionId]['members'][] = [
                'offer_id' => $offerId,
                'action' => $actionRow,
            ];
        }
    }

    foreach (array_chunk($priceRows, 100) as $chunk) {
        $chunkSize = count($chunk);
        try {
            $response = ozon_price_import_prices($cfg, $chunk);
            foreach ($chunk as $priceRow) {
                $offerId = (string)($priceRow['offer_id'] ?? '');
                if ($offerId !== '' && isset($results[$offerId])) {
                    $match = ozon_push_price_import_match($response, $priceRow);
                    $results[$offerId]['price_import'] = ozon_push_price_import_log_payload($response, $match, $chunkSize);
                    foreach ((array)($match['errors'] ?? []) as $message) {
                        $message = trim((string)$message);
                        if ($message !== '') {
                            $results[$offerId]['errors'][] = 'Не удалось обновить обычную цену / min price: ' . $message;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            foreach ($chunk as $priceRow) {
                $offerId = (string)($priceRow['offer_id'] ?? '');
                if ($offerId !== '' && isset($results[$offerId])) {
                    $results[$offerId]['errors'][] = 'Не удалось обновить обычную цену / min price: ' . $e->getMessage();
                }
            }
        }
    }

    foreach ($actionUpsertGroups as $actionId => $group) {
        $products = array_values((array)($group['products'] ?? []));
        $batchSize = count($products);
        try {
            ozon_actions_activate_products($cfg, (int)$actionId, $products);
            foreach ((array)($group['members'] ?? []) as $member) {
                $offerId = (string)($member['offer_id'] ?? '');
                $actionRow = is_array($member['action'] ?? null) ? (array)$member['action'] : [];
                if ($offerId === '' || !isset($results[$offerId])) {
                    continue;
                }
                $results[$offerId]['actions_upsert'][] = [
                    'action_id' => (int)$actionId,
                    'title' => (string)($actionRow['title'] ?? ''),
                    'change_type' => (string)($actionRow['change_type'] ?? 'add'),
                    'action_price' => round((float)($actionRow['action_price'] ?? 0), 2),
                    'product_id' => (int)($actionRow['product_id'] ?? 0),
                    'response' => ozon_push_batch_marker($batchSize),
                ];
            }
        } catch (Throwable $e) {
            foreach ((array)($group['members'] ?? []) as $member) {
                $offerId = (string)($member['offer_id'] ?? '');
                $actionRow = is_array($member['action'] ?? null) ? (array)$member['action'] : [];
                if ($offerId !== '' && isset($results[$offerId])) {
                    $results[$offerId]['errors'][] = 'Не удалось добавить/обновить акцию "' . (string)($actionRow['title'] ?? 'Ozon') . '": ' . $e->getMessage();
                }
            }
        }
    }

    foreach ($actionRemoveGroups as $actionId => $group) {
        $productIds = array_values((array)($group['product_ids'] ?? []));
        $batchSize = count($productIds);
        try {
            ozon_actions_deactivate_products($cfg, (int)$actionId, $productIds);
            foreach ((array)($group['members'] ?? []) as $member) {
                $offerId = (string)($member['offer_id'] ?? '');
                $actionRow = is_array($member['action'] ?? null) ? (array)$member['action'] : [];
                if ($offerId === '' || !isset($results[$offerId])) {
                    continue;
                }
                $results[$offerId]['actions_remove'][] = [
                    'action_id' => (int)$actionId,
                    'title' => (string)($actionRow['title'] ?? ''),
                    'product_id' => (int)($actionRow['product_id'] ?? 0),
                    'response' => ozon_push_batch_marker($batchSize),
                ];
            }
        } catch (Throwable $e) {
            foreach ((array)($group['members'] ?? []) as $member) {
                $offerId = (string)($member['offer_id'] ?? '');
                $actionRow = is_array($member['action'] ?? null) ? (array)$member['action'] : [];
                if ($offerId !== '' && isset($results[$offerId])) {
                    $results[$offerId]['errors'][] = 'Не удалось удалить товар из акции "' . (string)($actionRow['title'] ?? 'Ozon') . '": ' . $e->getMessage();
                }
            }
        }
    }

    foreach ($desiredByOffer as $offerId => $desiredState) {
        $priceChanged = !empty($desiredState['price_changed']) || !empty($desiredState['min_price_changed']);
        $hasActionChanges = !empty($desiredState['actions_upsert']) || !empty($desiredState['actions_remove']);
        if (!empty($results[$offerId]['errors'])) {
            $results[$offerId]['status'] = 'partial_error';
        } elseif (!$priceChanged && !$hasActionChanges) {
            $results[$offerId]['status'] = 'skipped';
        }
        ozon_price_push_log_write(
            (int)($feed['id'] ?? 0),
            $actor,
            $offerId,
            (int)($desiredState['product_id'] ?? 0),
            (string)($results[$offerId]['status'] ?? 'ok'),
            $desiredState,
            $results[$offerId],
            $connectionId
        );
    }

    return $results;
}

function op_ozon_push_selected_feeds(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    ozon_price_feeds_table_ensure();
    ozon_actions_tables_ensure();
    ozon_price_push_log_table_ensure();

    $requestedConnectionId = (int)($params['connection_id'] ?? 0);
    $allowCrossConnectionFeeds = $requestedConnectionId > 0 && !empty($params['allow_cross_connection_feeds']);
    $selectedOfferIdsRaw = $params['offer_ids'] ?? [];
    if (!is_array($selectedOfferIdsRaw)) {
        $decodedOfferIds = json_decode((string)($params['offer_ids_json'] ?? '[]'), true);
        $selectedOfferIdsRaw = is_array($decodedOfferIds) ? $decodedOfferIds : [];
    }
    $selectedOfferIds = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $selectedOfferIdsRaw
    ))));
    $selectedOfferSet = array_fill_keys($selectedOfferIds, true);
    $restoreRegularPrices = trim((string)($params['source'] ?? '')) === 'ozon_fbo_restore_regular_prices';
    if ($restoreRegularPrices && !$selectedOfferIds) {
        throw new RuntimeException('FBO Tool: не выбраны товары для возврата обычной цены.');
    }

    if (trim((string)($params['source'] ?? '')) === 'ozon_fbo_tool' && $selectedOfferIds) {
        if ($requestedConnectionId <= 0) {
            throw new RuntimeException('FBO Tool: не указано подключение Ozon для прямой выгрузки цен.');
        }
        $connection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
        $cfg = ozon_price_cfg_with_connection($cfg, $connection);
        $clientId = (string)(ozon_cfg_or_fail($cfg)['client_id'] ?? '');
        $log("ozon_push_selected_feeds: FBO direct connection_id={$requestedConnectionId}, client_id={$clientId}, selected_offer_ids=" . count($selectedOfferIds) . "\n");
        return ozon_push_selected_fbo_prices_direct($cfg, $requestedConnectionId, $selectedOfferIds, null, $opId, $log);
    }

    $feedIds = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
    if (!is_array($feedIds)) {
        throw new RuntimeException('Не удалось прочитать список выбранных фидов.');
    }
    $feedIds = array_values(array_unique(array_filter(array_map(static fn($v): int => (int)$v, $feedIds), static fn(int $v): bool => $v > 0)));
    if (!$feedIds) {
        throw new RuntimeException('Выбери хотя бы один фид для обновления цен.');
    }

    $feeds = [];
    foreach ($feedIds as $feedId) {
        $feed = ozon_price_feed_get($feedId, $requestedConnectionId > 0 ? $requestedConnectionId : null, $cfg);
        if (!is_array($feed) && $allowCrossConnectionFeeds) {
            $feed = ozon_price_feed_get($feedId, null, $cfg);
        }
        if (is_array($feed)) {
            if (ozon_price_feed_supplier_is_archived($feed)) {
                $log('skip archived supplier feed #' . $feedId . ': ' . (string)($feed['supplier_name'] ?? $feed['name'] ?? '') . "\n");
                continue;
            }
            $feeds[] = $feed;
        }
    }
    if (!$feeds) {
        throw new RuntimeException('Не удалось найти выбранные профили фидов.');
    }

    if ($allowCrossConnectionFeeds) {
        $connectionId = $requestedConnectionId;
    } else {
        $connectionIds = array_values(array_unique(array_filter(array_map(static fn(array $f): int => (int)($f['connection_id'] ?? 0), $feeds))));
        if (!$connectionIds) {
            $connectionIds = [$requestedConnectionId > 0 ? $requestedConnectionId : ozon_price_connection_default_id($cfg)];
        }
        if (count($connectionIds) !== 1) {
            throw new RuntimeException('Для одной массовой операции можно выбрать только фиды одного подключения.');
        }
        $connectionId = (int)$connectionIds[0];
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    $cfg = ozon_price_cfg_with_connection($cfg, $connection);
    $oz = ozon_cfg_or_fail($cfg);
    $clientId = (string)$oz['client_id'];

    $totalOffers = 0;
    $doneOffers = 0;
    $feedsDone = 0;
    $feedsTotal = count($feeds);
    $summaryFeeds = [];
    $summary = [
        'feeds_selected' => $feedsTotal,
        'feeds_processed' => 0,
        'offers_total' => 0,
        'offers_processed' => 0,
        'offers_applied' => 0,
        'offers_partial_error' => 0,
        'offers_skipped' => 0,
        'offers_failed' => 0,
        'fbo_rules_disabled' => 0,
        'fbo_prices_recalculated' => 0,
        'fbo_prices_restored_from_history' => 0,
        'fbo_prices_unresolved' => 0,
    ];
    $restoredRegularPriceOffers = [];

    ops_update_progress($opId, 0, max(1, $feedsTotal), 'prepare', 'Готовим массовую выгрузку цен по выбранным фидам...');
    $log("ozon_push_selected_feeds: connection_id={$connectionId}, client_id={$clientId}, feeds=" . implode(',', array_map(static fn(array $f): string => (string)$f['id'], $feeds)) . "\n");
    if ($selectedOfferIds) {
        $log('selected_offer_ids=' . count($selectedOfferIds) . "\n");
    }
    foreach ($feeds as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        $feedName = trim((string)($feed['name'] ?? ('feed #' . $feedId)));
        $feedReport = [
            'feed_id' => $feedId,
            'feed_name' => $feedName,
            'offers_total' => 0,
            'offers_processed' => 0,
            'offers_applied' => 0,
            'offers_partial_error' => 0,
            'offers_skipped' => 0,
            'offers_failed' => 0,
            'error' => '',
        ];

        try {
            ops_update_progress($opId, $doneOffers, max(1, $totalOffers ?: $feedsTotal), 'fetch_feed', 'Скачиваем XML для фида: ' . $feedName);
            $log("start: {$feedName}\n");

            $download = ozon_price_feed_fetch_remote_xml((string)$feed['feed_url']);
            try {
                $feedOffers = ozon_price_parse_feed((string)$download['path'], (string)$feed['cost_tag']);
            } finally {
                @unlink((string)$download['path']);
            }

            $supplierCode = (string)($feed['supplier_code'] ?? '');
            $feedMap = ozon_price_feed_offers_by_id($feedOffers, $supplierCode, (int)($feed['supplier_id'] ?? 0));
            if ($selectedOfferSet) {
                $selectedForFeed = array_values(array_unique(array_filter(array_map(
                    static fn(string $offerId): string => ozon_price_apply_supplier_code($offerId, $supplierCode),
                    $selectedOfferIds
                ))));
                $selection = ozon_price_feed_offers_for_requested_ids($feedMap, $selectedForFeed);
                $preparedOffers = (array)($selection['offers'] ?? []);
                $missingSelectedIds = (array)($selection['missing_ids'] ?? []);
                if ($missingSelectedIds) {
                    $sample = implode(', ', array_slice($missingSelectedIds, 0, 8));
                    $suffix = count($missingSelectedIds) > 8 ? '…' : '';
                    $log('selected_missing_in_feed=' . count($missingSelectedIds) . ': ' . $sample . $suffix . "\n");
                }
            } else {
                $preparedOffers = array_values($feedMap);
            }

            $feedReport['offers_total'] = count($preparedOffers);
            $summary['offers_total'] += $feedReport['offers_total'];
            $totalOffers += $feedReport['offers_total'];
            $log("offers_total={$feedReport['offers_total']}\n");

            if (!$preparedOffers) {
                $feedReport['error'] = 'В фиде не найдено товаров с корректным offer_id.';
                $summaryFeeds[] = $feedReport;
                $summary['feeds_processed']++;
                $feedsDone++;
                $log("skip: no valid offers\n");
                continue;
            }

            $offerIds = array_map(static fn(array $offer): string => (string)$offer['offer_id'], $preparedOffers);
            $fboGuard = $restoreRegularPrices
                ? ['active_rules' => 0]
                : ozon_price_refresh_fbo_force_rules_for_offers($connectionId, $offerIds, $cfg, $log);
            if ((int)($fboGuard['active_rules'] ?? 0) > 0) {
                $log('fbo_price_guard: active_rules=' . (int)($fboGuard['active_rules'] ?? 0)
                    . ' · refresh_needed=' . (int)($fboGuard['refresh_needed'] ?? 0)
                    . ' · refreshed=' . (int)($fboGuard['refreshed'] ?? 0)
                    . ' · active_fbo=' . (int)($fboGuard['fbo_active'] ?? 0)
                    . ' · rules_annulled=' . (int)($fboGuard['rules_annulled'] ?? 0) . "\n");
                $fboGuardError = trim((string)($fboGuard['error'] ?? ''));
                if ($fboGuardError !== '') {
                    $log('fbo_price_guard_error: ' . $fboGuardError . "\n");
                }
            }
            $ozonItems = ozon_price_fetch_price_items($cfg, $offerIds);
            $calcRows = [];
            $productIdsByOffer = [];
            foreach ($preparedOffers as $offerIndex => $offer) {
                $offerId = (string)$offer['offer_id'];
                $calc = ozon_price_calculate_offer($feed, $offer, $ozonItems[$offerId] ?? null);
                $calcRows[] = [
                    'offer_index' => $offerIndex,
                    'offer' => $offer,
                    'offer_id' => $offerId,
                    'calc' => $calc,
                ];
                $productId = (int)($calc['product_id'] ?? 0);
                if ($productId > 0) {
                    $productIdsByOffer[$offerId] = $productId;
                }
            }
            $promoRowsByOffer = ozon_actions_rows_for_offers_or_products($connectionId, $offerIds, $productIdsByOffer, $cfg);

            $pendingApplyRows = [];
            foreach ($calcRows as $calcRow) {
                $offerId = (string)$calcRow['offer_id'];
                $rowNo = (int)$calcRow['offer_index'] + 1;
                $rowPrefix = "{$rowNo}. {$offerId}";
                $feedReport['offers_processed']++;
                $summary['offers_processed']++;
                $log("{$rowPrefix}: processing\n");

                $calc = (array)$calcRow['calc'];
                $promoRows = ozon_price_calc_is_archived($calc) ? [] : (array)($promoRowsByOffer[$offerId] ?? []);
                $strategy = ozon_price_build_promotion_strategy($calc, $promoRows, $feed);
                $desiredState = ozon_price_build_desired_state(
                    $calc,
                    $strategy,
                    $promoRows,
                    $connectionId,
                    $cfg,
                    $restoreRegularPrices
                );

                $regularPrice = (float)($desiredState['regular_price'] ?? 0);
                $minPrice = (float)($desiredState['min_price'] ?? 0);
                $validation = (array)($desiredState['validation'] ?? []);
                $validationNotes = array_values(array_filter((array)($validation['notes'] ?? [])));
                $calcWarnings = array_values(array_filter((array)($desiredState['calc_warnings'] ?? [])));
                if ($regularPrice <= 0 || $minPrice <= 0) {
                    $feedReport['offers_skipped']++;
                    $summary['offers_skipped']++;
                    $reason = ozon_push_log_primary_reason($validation, $calcWarnings);
                    $log("{$rowPrefix}: skipped, {$reason}\n");
                    foreach ($calcWarnings as $warning) {
                        $warning = trim((string)$warning);
                        if ($warning === '' || $warning === $reason) {
                            continue;
                        }
                        $log("{$rowPrefix}: warning: {$warning}\n");
                    }
                    $doneOffers++;
                    if ($doneOffers === 1 || $doneOffers % 5 === 0 || $doneOffers === $totalOffers) {
                        ops_update_progress(
                            $opId,
                            $doneOffers,
                            max(1, $totalOffers),
                            'push_prices',
                            "Фид {$feedName}: {$feedReport['offers_processed']} / {$feedReport['offers_total']} товаров, всего {$doneOffers} / {$totalOffers}"
                        );
                    }
                } else {
                    $pendingApplyRows[] = [
                        'offer_id' => $offerId,
                        'row_prefix' => $rowPrefix,
                        'desired_state' => $desiredState,
                        'regular_price' => $regularPrice,
                        'min_price' => $minPrice,
                        'validation_notes' => $validationNotes,
                    ];
                }
            }

            if ($pendingApplyRows) {
                ops_update_progress($opId, $doneOffers, max(1, $totalOffers), 'push_prices', 'Отправляем Ozon пакетами: ' . $feedName);
                $applyByOffer = ozon_push_apply_desired_states_batched($cfg, $feed, $pendingApplyRows, null, $connectionId);
                foreach ($pendingApplyRows as $pendingRow) {
                    $offerId = (string)($pendingRow['offer_id'] ?? '');
                    $rowPrefix = (string)($pendingRow['row_prefix'] ?? $offerId);
                    $desiredState = is_array($pendingRow['desired_state'] ?? null) ? (array)$pendingRow['desired_state'] : [];
                    $apply = is_array($applyByOffer[$offerId] ?? null) ? $applyByOffer[$offerId] : [
                        'status' => 'partial_error',
                        'errors' => ['Маркетплейс не вернул результат применения.'],
                    ];
                    $status = (string)($apply['status'] ?? 'ok');
                    if ($restoreRegularPrices && in_array($status, ['ok', 'skipped'], true)) {
                        try {
                            ozon_fbo_tool_disable_price_rule($connectionId, $offerId, null, $cfg);
                            $restoredRegularPriceOffers[$offerId] = true;
                            $summary['fbo_prices_recalculated']++;
                            $log("{$rowPrefix}: FBO price control disabled after regular price confirmation.\n");
                        } catch (Throwable $disableError) {
                            $status = 'partial_error';
                            $apply['status'] = 'partial_error';
                            $apply['errors'][] = 'Обычная цена отправлена, но не удалось отключить FBO-правило: ' . $disableError->getMessage();
                        }
                    }
                    if ($status === 'ok') {
                        $feedReport['offers_applied']++;
                        $summary['offers_applied']++;
                    } elseif ($status === 'skipped') {
                        $feedReport['offers_skipped']++;
                        $summary['offers_skipped']++;
                    } else {
                        $feedReport['offers_partial_error']++;
                        $summary['offers_partial_error']++;
                    }

                    $regularPrice = (float)($pendingRow['regular_price'] ?? 0);
                    $minPrice = (float)($pendingRow['min_price'] ?? 0);
                    $actionSummary = ozon_push_log_action_summary($desiredState, $apply);
                    $priceInfo = !empty($apply['price_import_skipped'])
                        ? 'price/min_price=без изменений'
                        : "price={$regularPrice}, min_price={$minPrice}";
                    $fboInfo = '';
                    $priceAdjustment = is_array($desiredState['price_adjustment'] ?? null) ? (array)$desiredState['price_adjustment'] : [];
                    if ((string)($priceAdjustment['type'] ?? '') === 'fbo') {
                        $fboInfo = ', FBO: price '
                            . number_format((float)($priceAdjustment['regular_price_before'] ?? 0), 2, '.', '')
                            . '→'
                            . number_format((float)($priceAdjustment['regular_price_after'] ?? $regularPrice), 2, '.', '')
                            . ', min_price '
                            . number_format((float)($priceAdjustment['min_price_before'] ?? 0), 2, '.', '')
                            . '→'
                            . number_format((float)($priceAdjustment['min_price_after'] ?? $minPrice), 2, '.', '');
                    }
                    if ($status === 'skipped') {
                        $skipReason = trim((string)($apply['price_import_skip_reason'] ?? 'Текущие значения уже совпадают с расчётными.'));
                        $log("{$rowPrefix}: skipped, {$skipReason}{$fboInfo}, {$actionSummary}\n");
                    } else {
                        $log("{$rowPrefix}: {$status}, {$priceInfo}{$fboInfo}, {$actionSummary}\n");
                    }
                    foreach ((array)($apply['errors'] ?? []) as $errorLine) {
                        $errorLine = trim((string)$errorLine);
                        if ($errorLine !== '') {
                            $log("{$rowPrefix}: error: {$errorLine}\n");
                        }
                    }
                    foreach ((array)($pendingRow['validation_notes'] ?? []) as $note) {
                        $note = trim((string)$note);
                        if ($note !== '') {
                            $log("{$rowPrefix}: fallback: {$note}\n");
                        }
                    }

                    $doneOffers++;
                    if ($doneOffers === 1 || $doneOffers % 5 === 0 || $doneOffers === $totalOffers) {
                        ops_update_progress(
                            $opId,
                            $doneOffers,
                            max(1, $totalOffers),
                            'push_prices',
                            "Фид {$feedName}: {$feedReport['offers_processed']} / {$feedReport['offers_total']} товаров, всего {$doneOffers} / {$totalOffers}"
                        );
                    }
                }
            }

            $summary['feeds_processed']++;
            $feedsDone++;
            $summaryFeeds[] = $feedReport;
            $log("done: applied={$feedReport['offers_applied']}, partial={$feedReport['offers_partial_error']}, skipped={$feedReport['offers_skipped']}, failed={$feedReport['offers_failed']}\n");
        } catch (Throwable $e) {
            $feedReport['error'] = $e->getMessage();
            $summary['feeds_processed']++;
            $feedsDone++;
            $summaryFeeds[] = $feedReport;
            $log("error: {$e->getMessage()}\n");
        }
    }

    if ($restoreRegularPrices) {
        $historyOfferIds = array_values(array_diff($selectedOfferIds, array_keys($restoredRegularPriceOffers)));
        $historyStates = ozon_push_fbo_regular_states_from_history($connectionId, $historyOfferIds);
        if ($historyStates) {
            $historyReport = [
                'feed_id' => 0,
                'feed_name' => 'FBO price history fallback',
                'offers_total' => count($historyStates),
                'offers_processed' => 0,
                'offers_applied' => 0,
                'offers_partial_error' => 0,
                'offers_skipped' => 0,
                'offers_failed' => 0,
                'error' => '',
                'history_fallback' => true,
            ];
            $historyPendingRows = [];
            foreach ($historyStates as $offerId => $desiredState) {
                $historyPendingRows[] = [
                    'offer_id' => $offerId,
                    'row_prefix' => 'history. ' . $offerId,
                    'desired_state' => $desiredState,
                    'regular_price' => (float)($desiredState['regular_price'] ?? 0),
                    'min_price' => (float)($desiredState['min_price'] ?? 0),
                    'validation_notes' => (array)($desiredState['validation']['notes'] ?? []),
                ];
            }

            $historyCount = count($historyPendingRows);
            $summary['offers_total'] += $historyCount;
            $totalOffers += $historyCount;
            $log('fbo_regular_price_history_fallback: candidates=' . $historyCount . "\n");
            ops_update_progress(
                $opId,
                $doneOffers,
                max(1, $totalOffers),
                'restore_history',
                'Возвращаем обычные цены для товаров, которых больше нет в XML-фидах...'
            );
            $applyByOffer = ozon_push_apply_desired_states_batched(
                $cfg,
                ['id' => 0],
                $historyPendingRows,
                null,
                $connectionId
            );
            foreach ($historyPendingRows as $pendingRow) {
                $offerId = (string)($pendingRow['offer_id'] ?? '');
                $desiredState = (array)($pendingRow['desired_state'] ?? []);
                $historyReport['offers_processed']++;
                $summary['offers_processed']++;
                $apply = is_array($applyByOffer[$offerId] ?? null) ? (array)$applyByOffer[$offerId] : [
                    'status' => 'partial_error',
                    'errors' => ['Маркетплейс не вернул результат восстановления обычной цены из истории.'],
                ];
                $status = (string)($apply['status'] ?? 'partial_error');
                if (in_array($status, ['ok', 'skipped'], true)) {
                    try {
                        ozon_fbo_tool_disable_price_rule($connectionId, $offerId, null, $cfg);
                        $restoredRegularPriceOffers[$offerId] = true;
                        $summary['fbo_prices_restored_from_history']++;
                        if ($status === 'ok') {
                            $historyReport['offers_applied']++;
                            $summary['offers_applied']++;
                        } else {
                            $historyReport['offers_skipped']++;
                            $summary['offers_skipped']++;
                        }
                        $log(
                            'history. ' . $offerId
                            . ': ' . $status
                            . ', price=' . (float)($desiredState['regular_price'] ?? 0)
                            . ', min_price=' . (float)($desiredState['min_price'] ?? 0)
                            . ', FBO price control disabled after Ozon confirmation.' . "\n"
                        );
                    } catch (Throwable $disableError) {
                        $status = 'partial_error';
                        $apply['errors'][] = 'Цена восстановлена, но не удалось отключить FBO-правило: ' . $disableError->getMessage();
                    }
                }
                if ($status === 'partial_error') {
                    $historyReport['offers_partial_error']++;
                    $summary['offers_partial_error']++;
                    $errors = array_values(array_filter(array_map(
                        static fn($value): string => trim((string)$value),
                        (array)($apply['errors'] ?? [])
                    )));
                    $log('history. ' . $offerId . ': partial_error, ' . implode('; ', $errors) . "\n");
                }
                $doneOffers++;
            }
            $summaryFeeds[] = $historyReport;
        }
    }

    $summary['fbo_rules_disabled'] = count($restoredRegularPriceOffers);
    if ($restoreRegularPrices) {
        $summary['fbo_prices_unresolved'] = max(0, count($selectedOfferIds) - $summary['fbo_rules_disabled']);
        $log('fbo_regular_price_restore_done: disabled=' . $summary['fbo_rules_disabled']
            . ', recalculated=' . $summary['fbo_prices_recalculated']
            . ', history=' . $summary['fbo_prices_restored_from_history']
            . ', unresolved=' . $summary['fbo_prices_unresolved']
            . ', requested=' . count($selectedOfferIds) . "\n");
        if ($summary['fbo_rules_disabled'] === 0) {
            $feedErrors = array_values(array_filter(array_map(
                static fn(array $feedReport): string => trim((string)($feedReport['error'] ?? '')),
                $summaryFeeds
            )));
            $reason = $feedErrors ? (' Причина: ' . implode(' · ', array_slice(array_unique($feedErrors), 0, 2))) : '';
            throw new RuntimeException(
                'Не удалось подтвердить ни одной обычной цены. FBO-управление оставлено включённым для всех выбранных товаров.' . $reason
            );
        }
    }
    $progressMessage = $restoreRegularPrices && $summary['fbo_rules_disabled'] < count($selectedOfferIds)
        ? 'Обычные цены возвращены частично; неподтверждённые товары остались под управлением FBO Tool.'
        : 'Массовая выгрузка цен по выбранным фидам завершена.';
    ops_update_progress($opId, max(1, $doneOffers), max(1, $totalOffers ?: $doneOffers), 'done', $progressMessage);

    return [
        'summary_json_inline' => [
            'feeds_selected' => $summary['feeds_selected'],
            'feeds_processed' => $summary['feeds_processed'],
            'offers_total' => $summary['offers_total'],
            'offers_processed' => $summary['offers_processed'],
            'offers_applied' => $summary['offers_applied'],
            'offers_partial_error' => $summary['offers_partial_error'],
            'offers_skipped' => $summary['offers_skipped'],
            'offers_failed' => $summary['offers_failed'],
            'fbo_rules_disabled' => $summary['fbo_rules_disabled'],
            'fbo_prices_recalculated' => $summary['fbo_prices_recalculated'],
            'fbo_prices_restored_from_history' => $summary['fbo_prices_restored_from_history'],
            'fbo_prices_unresolved' => $summary['fbo_prices_unresolved'],
            'feeds' => $summaryFeeds,
        ],
        'outputs' => [
            'ozon_push_selected_feeds' => true,
        ],
    ];
}
