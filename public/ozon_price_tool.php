<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/ozon_actions.php';
require_once __DIR__ . '/../app/wb_promotions.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/navigation.php';

ozon_price_connections_table_ensure($cfg);
ozon_price_feeds_table_ensure($cfg);
ozon_actions_tables_ensure();
ozon_price_global_settings_table_ensure($cfg);
ops_table_ensure();

$actor = ft_current_user();
$flash = '';
$error = '';
$feeds = [];
$requestedConnectionId = (int)($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
if ($requestedConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=price_tool', true, 303);
    exit;
}
$currentConnection = ozon_price_connection_resolve($requestedConnectionId, $cfg);
$currentConnectionId = (int)($currentConnection['id'] ?? 0);
if ($currentConnectionId <= 0) {
    header('Location: marketplace_connections.php?need_connection=price_tool', true, 303);
    exit;
}
$currentMarketplace = (string)($currentConnection['marketplace'] ?? 'ozon');
$currentMarketplaceLabel = price_tool_marketplace_label($currentMarketplace);
$isOzonMarketplace = $currentMarketplace === 'ozon';
$isWbMarketplace = $currentMarketplace === 'wb';
$isYandexMarketplace = $currentMarketplace === 'yandex_market';
if ($isWbMarketplace) {
    wb_promotions_tables_ensure($cfg);
}
$currentMarketplaceReady = price_tool_connection_supports($currentConnection, 'price_tool');
$pushOpType = $isWbMarketplace ? 'wb_push_selected_feeds' : ($isYandexMarketplace ? 'yandex_push_selected_feeds' : 'ozon_push_selected_feeds');
$cfg = ozon_price_cfg_with_connection($cfg, $currentConnection);
$globalSettings = ($currentMarketplaceReady && $isOzonMarketplace)
    ? ozon_price_tool_settings_get($currentConnectionId, $cfg)
    : ozon_price_tool_settings_default();
$promoSyncSummary = null;
$wbDecisionSummary = null;
$wbRecentDecisions = [];
$promoSyncLastOp = null;
$wbPromotionRows = [];
$wbSelectedPromotionId = 0;
$wbSelectedPromotion = null;
$wbSelectedPromotionProducts = [];
$wbSelectedPromotionProductsLimit = 300;
$wbPromotionImportInboxDir = '';
$wbPromoFolderImportLastOp = null;
$wbPromotionDownloadSettings = [];
$wbPromoDownloadLastOp = null;
$wbPromoCurlSaveAttempted = false;
$pushFeedsLastOp = null;
$feedOfferCounts = [];
$forceRuleOptions = ['categories' => [], 'brands' => []];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_global_settings') {
        if (!$currentMarketplaceReady) {
            throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' блок общих настроек расчёта пока не поддержан.');
        }
        if (!$isOzonMarketplace) {
            throw new RuntimeException('Для подключения ' . $currentMarketplaceLabel . ' отдельный блок общих настроек расчёта пока не нужен: все параметры задаются прямо в профиле фида.');
        }
        ozon_price_tool_settings_save($_POST, $actor, $currentConnectionId, $cfg);
        header('Location: ozon_price_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&settings_saved=1', true, 303);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_wb_promo_download_settings') {
        $wbPromoCurlSaveAttempted = true;
        if (!$currentMarketplaceReady || !$isWbMarketplace) {
            throw new RuntimeException('Шаблон скачивания XLS/XLSX доступен только для подключения WB.');
        }
        $curlBundle = trim((string)($_POST['wb_promo_curl_bundle'] ?? ''));
        if ($curlBundle !== '') {
            $classified = wb_promotions_classify_curl_bundle($curlBundle);
            $detailCurlTemplate = (string)$classified['detail_curl_template'];
            $generateCurlTemplate = (string)$classified['generate_curl_template'];
            $curlTemplate = (string)$classified['curl_template'];
        } else {
            $detailCurlTemplate = (string)($_POST['wb_promo_detail_curl_template'] ?? '');
            $generateCurlTemplate = (string)($_POST['wb_promo_generate_curl_template'] ?? '');
            $curlTemplate = (string)($_POST['wb_promo_download_curl_template'] ?? '');
        }
        if (trim($detailCurlTemplate) === '' || trim($generateCurlTemplate) === '' || trim($curlTemplate) === '') {
            throw new RuntimeException('Для стабильного скачивания нужны все три cURL-запроса: детали акции, сформировать файл и скачать файл.');
        }
        $samplePromotionId = max(0, (int)($_POST['wb_promo_download_sample_promotion_id'] ?? 0));
        wb_promotions_download_settings_save($currentConnectionId, $curlTemplate, $samplePromotionId, $actor, $cfg, $generateCurlTemplate, $detailCurlTemplate);
        $testStatus = 'error';
        try {
            $test = wb_promotions_download_settings_test($currentConnectionId, $cfg);
            $testStatus = (string)($test['status'] ?? 'ok');
            wb_promotions_download_settings_set_test_result(
                $currentConnectionId,
                $testStatus,
                (string)($test['message'] ?? 'Авторизация cURL работает.'),
                $cfg
            );
        } catch (Throwable $testError) {
            wb_promotions_download_settings_set_test_result($currentConnectionId, 'error', $testError->getMessage(), $cfg);
        }
        header(
            'Location: ozon_price_tool.php?connection_id=' . urlencode((string)$currentConnectionId)
            . '&wb_download_settings_saved=1&wb_curl_test=' . urlencode($testStatus)
            . '#wb-promo-download-settings',
            true,
            303
        );
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_wb_promotion') {
        if (!$currentMarketplaceReady || !$isWbMarketplace) {
            throw new RuntimeException('Удаление акций доступно только для подключения WB.');
        }
        $deletePromotionId = max(0, (int)($_POST['promotion_id'] ?? 0));
        $deleteResult = wb_promotions_delete($currentConnectionId, $deletePromotionId, $cfg);
        $query = [
            'connection_id' => (string)$currentConnectionId,
            'wb_promo_deleted' => '1',
            'promotion_id' => (string)$deletePromotionId,
            'products' => (string)(int)($deleteResult['products_deleted'] ?? 0),
        ];
        header('Location: ozon_price_tool.php?' . http_build_query($query) . '#wb-promotions-list', true, 303);
        exit;
    }

    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    foreach ($feeds as $feed) {
        $feedOfferCounts[(int)($feed['id'] ?? 0)] = ozon_price_feed_offer_count_cached($feed, 3600, false);
    }
    if ($currentMarketplaceReady && $isOzonMarketplace) {
        $forceRuleOptions = ozon_price_force_rule_options_for_feeds($feeds, $cfg, false);
    }
    if (isset($_GET['settings_saved']) && $_GET['settings_saved'] === '1') {
        $flash = $isOzonMarketplace
            ? 'Общие настройки Ozon Price Tool сохранены.'
            : 'Настройки Price Tool сохранены.';
    }
    if ($isWbMarketplace && isset($_GET['wb_promo_imported']) && $_GET['wb_promo_imported'] === '1') {
        $flash = 'XLSX автоакции WB импортирован: товаров '
            . (int)($_GET['products'] ?? 0)
            . ', кандидатов ' . (int)($_GET['candidates'] ?? 0)
            . ', уже участвуют ' . (int)($_GET['participating'] ?? 0)
            . '.';
    }
    if ($isWbMarketplace && isset($_GET['wb_download_settings_saved']) && $_GET['wb_download_settings_saved'] === '1') {
        $flash = (string)($_GET['wb_curl_test'] ?? '') === 'ok'
            ? 'cURL-команды сохранены и проверены: авторизация WB работает.'
            : 'cURL-команды сохранены, но проверка авторизации не пройдена. Причина показана в блоке автоскачивания.';
    }
    if ($isWbMarketplace && isset($_GET['wb_promo_deleted']) && $_GET['wb_promo_deleted'] === '1') {
        $flash = 'Акция WB #' . (int)($_GET['promotion_id'] ?? 0)
            . ' удалена из базы. Удалено товаров акции: ' . (int)($_GET['products'] ?? 0) . '.';
    }
    if ($isWbMarketplace && trim((string)($_GET['wb_promo_error'] ?? '')) !== '') {
        $error = trim((string)$_GET['wb_promo_error']);
    }

    if ($currentMarketplaceReady && $isOzonMarketplace && $currentConnectionId > 0) {
        $oz = ozon_cfg_or_fail($cfg);
        $promoSyncSummary = ozon_actions_sync_summary($currentConnectionId, $cfg);
        $st = db()->prepare("
          SELECT id, status, created_at, finished_at
          FROM feedtools_operations
          WHERE op_type = 'ozon_sync_actions'
            AND (
              connection_id = ?
              OR (
                connection_id IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED) = ?
              )
            )
          ORDER BY id DESC
          LIMIT 1
        ");
        $st->execute([$currentConnectionId, $currentConnectionId]);
        $promoSyncLastOp = $st->fetch() ?: null;
    } elseif ($currentMarketplaceReady && $isWbMarketplace && $currentConnectionId > 0) {
        $wbPromotionImportInboxDir = wb_promotions_default_import_inbox_dir($currentConnectionId);
        $wbPromotionDownloadSettings = wb_promotions_download_settings_get($currentConnectionId, $cfg);
        $promoSyncSummary = wb_promotions_sync_summary($currentConnectionId, $cfg, 60.0);
        $wbDecisionSummary = wb_promotions_decision_summary($currentConnectionId, $cfg);
        $wbRecentDecisions = wb_promotions_recent_decisions($currentConnectionId, $cfg, 25);
        $wbPromotionRows = wb_promotions_list($currentConnectionId, $cfg, 200);
        $wbSelectedPromotionId = max(0, (int)($_GET['wb_promotion_id'] ?? 0));
        if ($wbSelectedPromotionId > 0) {
            $wbSelectedPromotion = wb_promotions_get($currentConnectionId, $wbSelectedPromotionId, $cfg);
            if ($wbSelectedPromotion !== null) {
                $wbSelectedPromotionProducts = wb_promotions_products_for_promotion(
                    $currentConnectionId,
                    $wbSelectedPromotionId,
                    $cfg,
                    $wbSelectedPromotionProductsLimit
                );
            }
        }
        $st = db()->prepare("
          SELECT id, status, created_at, finished_at
          FROM feedtools_operations
          WHERE op_type = 'wb_sync_promotions'
            AND (
              connection_id = ?
              OR (
                connection_id IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED) = ?
              )
            )
          ORDER BY id DESC
          LIMIT 1
        ");
        $st->execute([$currentConnectionId, $currentConnectionId]);
        $promoSyncLastOp = $st->fetch() ?: null;

        $st = db()->prepare("
          SELECT id, status, created_at, finished_at
          FROM feedtools_operations
          WHERE op_type = 'wb_import_promotion_xlsx_folder'
            AND (
              connection_id = ?
              OR (
                connection_id IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED) = ?
              )
            )
          ORDER BY id DESC
          LIMIT 1
        ");
        $st->execute([$currentConnectionId, $currentConnectionId]);
        $wbPromoFolderImportLastOp = $st->fetch() ?: null;

        $st = db()->prepare("
          SELECT id, status, created_at, finished_at
          FROM feedtools_operations
          WHERE op_type = 'wb_download_promotion_xlsx'
            AND (
              connection_id = ?
              OR (
                connection_id IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED) = ?
              )
            )
          ORDER BY id DESC
          LIMIT 1
        ");
        $st->execute([$currentConnectionId, $currentConnectionId]);
        $wbPromoDownloadLastOp = $st->fetch() ?: null;
    }

    if ($currentMarketplaceReady && $currentConnectionId > 0) {
        $st = db()->prepare("
          SELECT id, status, created_at, finished_at
          FROM feedtools_operations
          WHERE op_type = ?
            AND (
              connection_id = ?
              OR (
                connection_id IS NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.connection_id')) AS UNSIGNED) = ?
              )
            )
          ORDER BY id DESC
          LIMIT 1
        ");
        $st->execute([$pushOpType, $currentConnectionId, $currentConnectionId]);
        $pushFeedsLastOp = $st->fetch() ?: null;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $feeds = $currentMarketplaceReady ? ozon_price_feed_list($currentConnectionId, $cfg) : [];
    foreach ($feeds as $feed) {
        $feedOfferCounts[(int)($feed['id'] ?? 0)] = ozon_price_feed_offer_count_cached($feed, 3600, false);
    }
    if ($currentMarketplaceReady && $isOzonMarketplace) {
        $forceRuleOptions = ozon_price_force_rule_options_for_feeds($feeds, $cfg, false);
    }
    $globalSettings = ($currentMarketplaceReady && $isOzonMarketplace)
        ? ozon_price_tool_settings_get($currentConnectionId, $cfg)
        : ozon_price_tool_settings_default();
    if ($currentMarketplaceReady && $isWbMarketplace) {
        $wbPromotionDownloadSettings = wb_promotions_download_settings_get($currentConnectionId, $cfg);
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_input_decimal($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return str_replace('.', ',', number_format((float)$value, 2, '.', ''));
}

function fmt_money_short($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' ₽';
}

function fmt_percent_short($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . '%';
}

function wb_promotion_moscow_timezone(): DateTimeZone
{
    static $tz = null;
    if (!$tz instanceof DateTimeZone) {
        $tz = new DateTimeZone('Europe/Moscow');
    }
    return $tz;
}

function fmt_datetime_short($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $raw;
    }
}

function fmt_promo_calendar_date($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($raw);
        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];
        return $dt->format('j') . ' ' . ($months[(int)$dt->format('n')] ?? $dt->format('m'));
    } catch (Throwable $e) {
        return $raw;
    }
}

function fmt_promo_calendar_range($startValue, $endValue): string
{
    $start = fmt_promo_calendar_date($startValue);
    $end = fmt_promo_calendar_date($endValue);
    if ($start === '—' && $end === '—') {
        return 'срок не указан';
    }
    if ($start === $end || $end === '—') {
        return $start;
    }
    if ($start === '—') {
        return 'до ' . $end;
    }
    return $start . ' – ' . $end;
}

function wb_promotion_calendar_day_label(DateTimeImmutable $day): string
{
    $weekdays = [1 => 'ПН', 2 => 'ВТ', 3 => 'СР', 4 => 'ЧТ', 5 => 'ПТ', 6 => 'СБ', 7 => 'ВС'];
    return $weekdays[(int)$day->format('N')] ?? $day->format('D');
}

function wb_promotion_calendar_month_label(DateTimeImmutable $day): string
{
    $months = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
    return $months[(int)$day->format('n')] ?? $day->format('F');
}

function wb_promotion_calendar_build(array $rows): array
{
    $tz = wb_promotion_moscow_timezone();
    $today = new DateTimeImmutable('today', $tz);
    $minStart = null;
    $maxEnd = null;
    $datedRows = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $startRaw = trim((string)($row['start_datetime'] ?? ''));
        $endRaw = trim((string)($row['end_datetime'] ?? ''));
        if ($startRaw === '' && $endRaw === '') {
            continue;
        }
        try {
            $start = $startRaw !== '' ? (new DateTimeImmutable($startRaw, $tz))->setTime(0, 0) : null;
            $end = $endRaw !== '' ? (new DateTimeImmutable($endRaw, $tz))->setTime(0, 0) : null;
        } catch (Throwable $e) {
            continue;
        }
        if ($start === null && $end === null) {
            continue;
        }
        if ($start === null) {
            $start = $end;
        }
        if ($end === null) {
            $end = $start;
        }
        if ($start === null || $end === null) {
            continue;
        }
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $datedRows[] = ['row' => $row, 'start' => $start, 'end' => $end];
        $minStart = $minStart === null || $start < $minStart ? $start : $minStart;
        $maxEnd = $maxEnd === null || $end > $maxEnd ? $end : $maxEnd;
    }

    if (!$datedRows || $minStart === null || $maxEnd === null) {
        return ['days' => [], 'months' => [], 'items' => [], 'lanes' => 0, 'today_offset' => null];
    }

    $floor = $today->modify('-10 days');
    $windowStart = $minStart < $floor ? $floor : ($minStart < $today ? $minStart : $today);
    $windowEnd = $maxEnd > $today->modify('+28 days') ? $maxEnd : $today->modify('+28 days');
    $hardEnd = $today->modify('+75 days');
    if ($windowEnd > $hardEnd) {
        $windowEnd = $hardEnd;
    }
    if ($windowEnd < $windowStart) {
        $windowEnd = $windowStart;
    }

    $days = [];
    $cursor = $windowStart;
    while ($cursor <= $windowEnd) {
        $days[] = [
            'date' => $cursor,
            'day' => $cursor->format('j'),
            'weekday' => wb_promotion_calendar_day_label($cursor),
            'weekend' => (int)$cursor->format('N') >= 6,
            'today' => $cursor->format('Y-m-d') === $today->format('Y-m-d'),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    $monthSpans = [];
    foreach ($days as $index => $dayInfo) {
        /** @var DateTimeImmutable $day */
        $day = $dayInfo['date'];
        $key = $day->format('Y-m');
        if (!isset($monthSpans[$key])) {
            $monthSpans[$key] = [
                'label' => wb_promotion_calendar_month_label($day),
                'offset' => $index,
                'span' => 0,
            ];
        }
        $monthSpans[$key]['span']++;
    }

    $dayCount = max(1, count($days));
    $items = [];
    foreach ($datedRows as $item) {
        /** @var DateTimeImmutable $start */
        $start = $item['start'];
        /** @var DateTimeImmutable $end */
        $end = $item['end'];
        if ($end < $windowStart || $start > $windowEnd) {
            continue;
        }
        $displayStart = $start < $windowStart ? $windowStart : $start;
        $displayEnd = $end > $windowEnd ? $windowEnd : $end;
        $startOffset = (int)$windowStart->diff($displayStart)->format('%a');
        $endOffset = (int)$windowStart->diff($displayEnd)->format('%a');
        $startOffset = max(0, min($dayCount - 1, $startOffset));
        $endOffset = max($startOffset, min($dayCount - 1, $endOffset));
        $span = max(1, $endOffset - $startOffset + 1);
        $row = $item['row'];
        $items[] = [
            'row' => $row,
            'offset' => $startOffset,
            'span' => $span,
            'end_offset' => $endOffset,
            'sort_start' => $startOffset,
            'sort_span' => $span,
            'is_clipped_start' => $start < $windowStart,
            'is_clipped_end' => $end > $windowEnd,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $cmp = $a['sort_start'] <=> $b['sort_start'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return $b['sort_span'] <=> $a['sort_span'];
    });

    $laneEnds = [];
    foreach ($items as $index => $item) {
        $lane = 0;
        while (isset($laneEnds[$lane]) && (int)$laneEnds[$lane] >= (int)$item['offset']) {
            $lane++;
        }
        $laneEnds[$lane] = (int)$item['end_offset'];
        $items[$index]['lane'] = $lane;
    }

    $todayOffset = null;
    if ($today >= $windowStart && $today <= $windowEnd) {
        $todayOffset = (int)$windowStart->diff($today)->format('%a');
    }

    return [
        'days' => $days,
        'months' => array_values($monthSpans),
        'items' => $items,
        'lanes' => max(1, count($laneEnds)),
        'today_offset' => $todayOffset,
    ];
}

function wb_promotion_type_label($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'тип не указан';
    }
    $lower = mb_strtolower($raw, 'UTF-8');
    if (str_contains($lower, 'auto') || str_contains($lower, 'авто')) {
        return 'автоакция';
    }
    if (str_contains($lower, 'regular') || str_contains($lower, 'обыч')) {
        return 'обычная';
    }
    return $raw;
}

function wb_promotion_status(array $row): array
{
    $startRaw = trim((string)($row['start_datetime'] ?? ''));
    $endRaw = trim((string)($row['end_datetime'] ?? ''));
    try {
        $tz = wb_promotion_moscow_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $start = $startRaw !== '' ? new DateTimeImmutable($startRaw, $tz) : null;
        $end = $endRaw !== '' ? new DateTimeImmutable($endRaw, $tz) : null;
        if ($end !== null && $end < $now) {
            return ['label' => 'завершена', 'class' => 'ended'];
        }
        if ($start !== null && $start > $now) {
            return ['label' => 'будет', 'class' => 'upcoming'];
        }
        if ($start !== null || $end !== null) {
            return ['label' => 'идёт', 'class' => 'ok'];
        }
    } catch (Throwable $e) {
    }
    return ['label' => 'без срока', 'class' => 'muted'];
}

function wb_promotion_is_future(array $row): bool
{
    $startRaw = trim((string)($row['start_datetime'] ?? ''));
    if ($startRaw === '') {
        return false;
    }
    try {
        $tz = wb_promotion_moscow_timezone();
        $start = new DateTimeImmutable($startRaw, $tz);
        return $start > new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        return false;
    }
}

function wb_promotion_count_labels(array $row): array
{
    if (wb_promotion_is_future($row)) {
        return [
            'participating' => 'будут участвовать',
            'participating_short' => 'будет участвовать',
            'candidate' => 'подходят',
            'candidate_short' => 'подходит',
        ];
    }
    return [
        'participating' => 'участвуют',
        'participating_short' => 'участвует',
        'candidate' => 'кандидаты',
        'candidate_short' => 'кандидаты',
    ];
}

function wb_promotion_product_status(array $row, ?array $promotion = null): array
{
    $sourceType = (string)($row['source_type'] ?? '');
    $inAction = !empty($row['in_action']) || $sourceType === 'participating';
    $isFuture = is_array($promotion) && wb_promotion_is_future($promotion);
    return $inAction
        ? ['label' => ($isFuture ? 'будет участвовать' : 'участвует'), 'class' => 'ok']
        : ['label' => ($isFuture ? 'подходит' : 'кандидат'), 'class' => 'upcoming'];
}

function wb_promotion_decision_status_view($value): array
{
    $status = trim((string)$value);
    return match ($status) {
        'selected' => ['label' => 'акция выбрана', 'class' => 'ok'],
        'future_selected' => ['label' => 'будущая акция', 'class' => 'upcoming'],
        'future_available' => ['label' => 'будущая акция', 'class' => 'upcoming'],
        'blocked_below_target_margin' => ['label' => 'старый запас', 'class' => 'warn'],
        'blocked_below_min_price' => ['label' => 'ниже границы', 'class' => 'warn'],
        'blocked_discount_rounding_below_min_price' => ['label' => 'округление ниже границы', 'class' => 'warn'],
        'blocked_discount_rounding_below_target_margin' => ['label' => 'округление старый запас', 'class' => 'warn'],
        'none' => ['label' => 'нет акции', 'class' => 'muted'],
        'disabled' => ['label' => 'выключено', 'class' => 'muted'],
        default => ['label' => $status !== '' ? $status : '—', 'class' => 'muted'],
    };
}

function wb_promotion_decision_action_label($value): string
{
    $action = trim((string)$value);
    return match ($action) {
        'upload_to_promotion' => 'добавить через API',
        'price_only' => 'цена/скидка',
        'base_price' => 'базовая цена',
        default => $action !== '' ? $action : '—',
    };
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ozon Price Tool</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f8fc;
      --card: #ffffff;
      --border: #d9e5f2;
      --text: #17233a;
      --muted: #61738d;
      --accent: #0f766e;
      --accent-soft: #ecfdf5;
      --promo-soft: #eef6ff;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
      --danger: #b42318;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f7fbff 0%, #f4f7fb 100%);
      color: var(--text);
      font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 20;
      padding: 8px 12px;
      border-radius: 999px;
      background: #fff5d6;
      color: #8a5a00;
      border: 1px solid #f2d386;
      font-weight: 700;
      font-size: 13px;
    }
    .topbar {
      max-width: 1420px;
      margin: 0 auto;
      padding: 28px 18px 18px;
    }
    .page {
      max-width: 1420px;
      margin: 0 auto;
      padding: 0 18px 36px;
      display: grid;
      gap: 18px;
    }
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 22px;
      box-shadow: var(--shadow);
      min-width: 0;
    }
    h1, h2, h3 { margin: 0 0 10px; line-height: 1.15; }
    .muted { color: var(--muted); }
    .button-link, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 14px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 700;
      cursor: pointer;
    }
    .button-link.secondary, button.secondary {
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    .button-link.danger, button.danger {
      background: #fff1f2;
      color: var(--danger);
      border-color: #fecdd3;
    }
    .connections-list {
      display: grid;
      gap: 14px;
    }
    .connection-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: #fbfdff;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
      border: 1px solid #dbe7f3;
      background: #fff;
    }
    .pill.active { background: #edfdf3; border-color: #b7ebc6; color: #166534; }
    .pill.inactive { background: #fff1f2; border-color: #fecdd3; color: #b42318; }
    .current-connection-card {
      margin-top: 18px;
      display: flex;
      gap: 18px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      border: 1px solid #cfe0f7;
      border-radius: 24px;
      padding: 20px 22px;
      background:
        radial-gradient(circle at top right, rgba(191, 219, 254, 0.35), transparent 34%),
        linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
      box-shadow: 0 16px 32px rgba(27, 57, 90, 0.08);
    }
    .current-connection-kicker {
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .current-connection-title {
      font-size: 32px;
      font-weight: 800;
      line-height: 1.05;
      letter-spacing: -0.03em;
      margin-bottom: 6px;
    }
    .current-connection-meta {
      color: var(--muted);
      font-size: 15px;
    }
    .icon-button {
      width: 44px;
      min-width: 44px;
      padding: 0;
      border-radius: 14px;
    }
    .icon-button svg {
      width: 18px;
      height: 18px;
      display: block;
    }
    .tab-nav {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 18px;
      align-items: end;
      padding-left: 10px;
      border-bottom: 1px solid #cfdceb;
      width: fit-content;
    }
    .tab-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 52px;
      padding: 0 24px;
      border-radius: 18px 18px 0 0;
      border: 1px solid #cfdceb;
      border-bottom: none;
      background: linear-gradient(180deg, #ffffff 0%, #eef4fb 100%);
      color: #334155;
      text-decoration: none;
      font-weight: 700;
      position: relative;
      top: 1px;
      box-shadow: 0 -1px 0 rgba(255,255,255,.9) inset;
    }
    .tab-link.active {
      background: linear-gradient(180deg, #17233a 0%, #0f172a 100%);
      color: #fff;
      border-color: #0f172a;
      box-shadow:
        0 10px 24px rgba(15, 23, 42, 0.14),
        0 -1px 0 rgba(255,255,255,.08) inset;
    }
    .tab-stack {
      display: grid;
      gap: 12px;
      justify-content: start;
      margin-top: 18px;
    }
    .tab-nav.is-subtabs {
      margin-top: 0;
      gap: 8px;
      padding-left: 14px;
      border-bottom-color: #dbe7f3;
    }
    .tab-nav.is-subtabs .tab-link {
      min-height: 44px;
      padding: 0 20px;
      border-radius: 14px 14px 0 0;
      background: linear-gradient(180deg, #f8fbff 0%, #edf3fb 100%);
      color: #52637d;
      border-color: #dbe7f3;
      box-shadow: 0 -1px 0 rgba(255,255,255,.95) inset;
    }
    .tab-nav.is-subtabs .tab-link.active {
      background: linear-gradient(180deg, #e8f1ff 0%, #dce8fb 100%);
      color: #13233d;
      border-color: #bfd1ea;
      box-shadow:
        0 8px 20px rgba(148, 163, 184, 0.16),
        0 -1px 0 rgba(255,255,255,.85) inset;
    }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
    }
    .back-link:hover {
      color: var(--text);
    }
    .flash, .error {
      max-width: 1420px;
      margin: 0 auto 16px;
      padding: 14px 18px;
      border-radius: 16px;
      border: 1px solid var(--border);
    }
    .flash { background: #edfdf3; color: #166534; border-color: #b7ebc6; }
    .error { background: #fff1f2; color: var(--danger); border-color: #fecdd3; }
    .grid {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .field label {
      display: block;
      margin-bottom: 6px;
      font-weight: 700;
      font-size: 14px;
    }
    .field input {
      width: 100%;
      min-width: 0;
      min-height: 48px;
      padding: 0 14px;
      border: 1px solid #ced9e8;
      border-radius: 14px;
      font-size: 16px;
      background: #fff;
      color: var(--text);
    }
    .field textarea {
      width: 100%;
      min-width: 0;
      min-height: 180px;
      padding: 12px 14px;
      border: 1px solid #ced9e8;
      border-radius: 14px;
      font-size: 15px;
      background: #fff;
      color: var(--text);
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    .field small {
      display: block;
      margin-top: 6px;
      color: var(--muted);
    }
    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 16px;
    }
    .stats {
      display: grid;
      gap: 14px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-top: 16px;
    }
    .stat {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 14px 16px;
      background: #fff;
    }
    .stat .label {
      color: var(--muted);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 6px;
    }
    .stat .value {
      font-size: 34px;
      font-weight: 800;
      line-height: 1.05;
    }
    .data-table-wrap {
      margin-top: 18px;
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 18px;
      background: #fff;
    }
    .data-table {
      width: 100%;
      min-width: 980px;
      border-collapse: collapse;
    }
    .data-table th,
    .data-table td {
      padding: 11px 12px;
      border-bottom: 1px solid #e5edf6;
      text-align: left;
      vertical-align: top;
      font-size: 14px;
      line-height: 1.35;
    }
    .data-table th {
      background: #f6f9fd;
      color: #52637d;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .data-table tr:last-child td {
      border-bottom: none;
    }
    .data-table .num {
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }
    .promo-title {
      display: block;
      font-weight: 800;
      color: var(--text);
      max-width: 420px;
    }
    .status-chip {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 9px;
      border-radius: 999px;
      border: 1px solid #dbe7f3;
      background: #fff;
      color: #52637d;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
    }
    .status-chip.ok {
      background: #ecfdf5;
      border-color: #bbf7d0;
      color: #166534;
    }
    .status-chip.upcoming {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
    }
    .status-chip.ended {
      background: #f8fafc;
      border-color: #e2e8f0;
      color: #64748b;
    }
    .status-chip.muted {
      background: #fff7ed;
      border-color: #fed7aa;
      color: #9a3412;
    }
    .status-chip.warn {
      background: #fff7ed;
      border-color: #fed7aa;
      color: #9a3412;
    }
    .wb-promo-calendar {
      margin-top: 18px;
      border: 1px solid #dbe5f2;
      border-radius: 22px;
      background: #fff;
      overflow: hidden;
    }
    .wb-promo-calendar-scroll {
      --wb-promo-day: 88px;
      overflow-x: auto;
      overflow-y: hidden;
      padding-bottom: 4px;
      background:
        linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    }
    .wb-promo-calendar-canvas {
      position: relative;
      min-width: 100%;
      width: calc(var(--wb-promo-day) * var(--wb-promo-days));
    }
    .wb-promo-calendar-months {
      display: grid;
      grid-template-columns: repeat(var(--wb-promo-days), var(--wb-promo-day));
      min-height: 34px;
      align-items: center;
      border-bottom: 1px solid #eef2f7;
      background: #fff;
    }
    .wb-promo-calendar-month {
      text-align: center;
      font-weight: 900;
      color: #0f172a;
      font-size: 15px;
    }
    .wb-promo-calendar-days {
      display: grid;
      grid-template-columns: repeat(var(--wb-promo-days), var(--wb-promo-day));
      border-bottom: 1px solid #eef2f7;
      background: #fff;
    }
    .wb-promo-calendar-day {
      min-height: 56px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 1px;
      border-right: 1px solid #eef2f7;
      color: #111827;
      font-weight: 900;
      font-variant-numeric: tabular-nums;
    }
    .wb-promo-calendar-day:last-child {
      border-right: 0;
    }
    .wb-promo-calendar-day span {
      color: #a1a9b6;
      font-size: 11px;
      font-weight: 800;
    }
    .wb-promo-calendar-day.is-weekend {
      background:
        repeating-linear-gradient(135deg, rgba(148, 163, 184, .09) 0 2px, transparent 2px 6px),
        #fbfdff;
    }
    .wb-promo-calendar-day.is-today {
      background: #f0e4ff;
      color: #7c3aed;
      border-radius: 8px;
      margin: 5px 8px;
      min-height: 46px;
      border-right: 0;
    }
    .wb-promo-calendar-day.is-today span {
      color: #8b5cf6;
    }
    .wb-promo-calendar-body {
      position: relative;
      display: grid;
      grid-template-columns: repeat(var(--wb-promo-days), var(--wb-promo-day));
      grid-template-rows: repeat(var(--wb-promo-lanes), minmax(92px, auto));
      gap: 10px 0;
      padding: 14px 0;
      min-height: var(--wb-promo-body-height, 132px);
      background:
        repeating-linear-gradient(to right, transparent 0 calc(var(--wb-promo-day) - 1px), #edf2f7 calc(var(--wb-promo-day) - 1px) var(--wb-promo-day)),
        #fff;
    }
    .wb-promo-calendar-weekend {
      background: repeating-linear-gradient(135deg, rgba(148, 163, 184, .08) 0 2px, transparent 2px 6px);
      pointer-events: none;
      z-index: 1;
    }
    .wb-promo-calendar-today-line {
      width: 2px;
      background: #8b5cf6;
      box-shadow: 0 0 0 1px rgba(139, 92, 246, .12);
      pointer-events: none;
      justify-self: start;
      z-index: 5;
    }
    .wb-promo-calendar-card {
      min-height: 86px;
      margin: 0 4px;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid #ffc3ad;
      background: rgba(255, 247, 242, .88);
      box-shadow: 0 10px 24px rgba(180, 83, 9, .08);
      color: #17233a;
      text-decoration: none;
      overflow: hidden;
      z-index: 3;
    }
    .wb-promo-calendar-card:hover {
      border-color: #fb923c;
      box-shadow: 0 14px 30px rgba(180, 83, 9, .13);
      transform: translateY(-1px);
    }
    .wb-promo-calendar-card.is-regular {
      border-color: #bfdbfe;
      background: rgba(239, 246, 255, .9);
      box-shadow: 0 10px 24px rgba(29, 78, 216, .08);
    }
    .wb-promo-calendar-card.is-muted {
      opacity: .72;
    }
    .wb-promo-calendar-title {
      display: block;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 900;
      line-height: 1.2;
      margin-bottom: 8px;
    }
    .wb-promo-calendar-title b {
      color: #fb6a3d;
    }
    .wb-promo-calendar-card.is-regular .wb-promo-calendar-title b {
      color: #2563eb;
    }
    .wb-promo-calendar-chips {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 8px;
      min-width: 0;
    }
    .wb-promo-calendar-chip {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 0 8px;
      border-radius: 7px;
      background: #ffffff;
      border: 1px solid #e5edf6;
      color: #27364d;
      font-size: 12px;
      font-weight: 850;
      white-space: nowrap;
    }
    .wb-promo-calendar-chip.is-ok {
      background: #3f9860;
      border-color: #3f9860;
      color: #fff;
    }
    .wb-promo-calendar-meta {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: #5f6e83;
      font-size: 12px;
      font-weight: 760;
    }
    .wb-promo-calendar-empty {
      padding: 18px;
      color: var(--muted);
      border: 1px dashed #cfdceb;
      border-radius: 16px;
      background: #fbfdff;
      margin-top: 14px;
    }
    .section-head {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .feeds-list {
      display: grid;
      gap: 14px;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
    .feed-item {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: #fbfdff;
    }
    .feed-select-grid {
      display: grid;
      gap: 14px;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      margin-top: 16px;
    }
    .feed-select {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: #fbfdff;
    }
    .feed-select input[type="checkbox"] {
      margin-top: 4px;
      width: 18px;
      height: 18px;
      flex: 0 0 auto;
    }
    .force-rule-builder {
      display: grid;
      gap: 8px;
      padding: 10px;
      border: 1px solid #fed7aa;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.78);
      margin-bottom: 10px;
    }
    .force-rule-toolbar {
      display: grid;
      grid-template-columns: minmax(180px, 1fr) 150px auto auto;
      gap: 6px;
      align-items: center;
    }
    .force-rule-toolbar input[type="search"],
    .force-rule-toolbar input[type="text"] {
      min-height: 34px;
      padding: 6px 10px;
      border-radius: 10px;
      font-size: 13px;
    }
    .force-rule-mini-button {
      min-height: 34px;
      padding: 0 10px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 800;
    }
    .force-rule-meta {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      align-items: center;
    }
    .force-rule-pill {
      display: inline-flex;
      align-items: center;
      min-height: 22px;
      padding: 0 8px;
      border-radius: 999px;
      background: #eef5ff;
      border: 1px solid #c9dcff;
      color: #2458b8;
      font-size: 11px;
      font-weight: 800;
    }
    .force-rule-options {
      max-height: 170px;
      overflow: auto;
      display: grid;
      gap: 4px;
      padding: 5px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #fff;
    }
    .force-rule-option {
      display: grid;
      grid-template-columns: 16px minmax(0, 1fr) auto;
      gap: 8px;
      align-items: start;
      padding: 6px 8px;
      border-radius: 9px;
      cursor: pointer;
      line-height: 1.18;
      font-size: 13px;
    }
    .force-rule-option[hidden],
    .force-rule-option.is-filtered-out {
      display: none !important;
    }
    .force-rule-option:hover {
      background: #f3f7fd;
    }
    .force-rule-option.is-selected {
      background: #eaf2ff;
      outline: 1px solid #9ec2ff;
    }
    .force-rule-option.has-rule {
      background: #f0fdf4;
      outline: 1px solid #bbf7d0;
    }
    .force-rule-option input[type="checkbox"] {
      width: 16px;
      height: 16px;
      min-height: 16px;
      margin: 0;
      padding: 0;
      border-radius: 4px;
      flex: 0 0 auto;
    }
    .force-rule-option-main {
      min-width: 0;
      overflow-wrap: anywhere;
      font-weight: 760;
    }
    .force-rule-option-sub {
      display: block;
      margin-top: 2px;
      color: var(--muted);
      font-size: 11px;
      font-weight: 650;
    }
    .force-rule-status {
      align-self: start;
      padding: 1px 6px;
      border-radius: 999px;
      background: #dcfce7;
      color: #166534;
      font-size: 10px;
      font-weight: 900;
      white-space: nowrap;
      visibility: hidden;
    }
    .force-rule-option.has-rule .force-rule-status {
      visibility: visible;
    }
    .force-rule-empty {
      padding: 9px;
      color: var(--muted);
      font-size: 12px;
    }
    @media (max-width: 820px) {
      .force-rule-toolbar {
        grid-template-columns: 1fr;
      }
      .wb-promo-calendar-scroll {
        --wb-promo-day: 72px;
      }
      .wb-promo-calendar-card {
        padding: 10px;
      }
    }
    .global-card { background: linear-gradient(180deg, #f6fffb 0%, #ffffff 100%); }
    .promo-card { background: linear-gradient(180deg, #f4f8ff 0%, #ffffff 100%); }
    .force-card { background: linear-gradient(180deg, #fffaf3 0%, #ffffff 100%); }
    .push-card { background: linear-gradient(180deg, #f7faff 0%, #ffffff 100%); }
    code {
      padding: 1px 6px;
      border-radius: 999px;
      background: #f2f6fb;
      border: 1px solid #dce6f3;
      font-size: 0.95em;
    }
  </style>
</head>
<body>
  <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'marketplace_connections.php?connection_id=' . urlencode((string)$currentConnectionId), 'back_label' => 'Назад', 'active' => 'connections']) ?>
    <h1 style="margin:0 0 8px;">Price Tool</h1>
    <div class="current-connection-card">
      <div>
        <div class="current-connection-kicker">Текущий кабинет</div>
        <?php if ($currentConnectionId > 0 && is_array($currentConnection)): ?>
          <div class="current-connection-title"><?= h((string)($currentConnection['title'] ?? '—')) ?></div>
          <div class="current-connection-meta">
            <?= h($currentMarketplaceLabel) ?>
            <?php if (!empty($currentConnection['client_id'])): ?>
              · client_id <?= h((string)$currentConnection['client_id']) ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="muted">Кабинет пока не выбран. Открой нужное подключение из общего раздела.</div>
        <?php endif; ?>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="button-link secondary" href="suppliers.php">Поставщики</a>
        <a class="button-link secondary" href="marketplace_connections.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Выбрать кабинет</a>
      </div>
    </div>
    <div class="tab-stack">
      <div class="tab-nav">
        <a class="tab-link active" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Price Tool</a>
        <a class="tab-link" href="orders_sync.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Orders Sync</a>
        <a class="tab-link" href="stocks_tool.php<?= $currentConnectionId > 0 ? '?connection_id=' . urlencode((string)$currentConnectionId) : '' ?>">Stocks Tool</a>
        <?php if ($isOzonMarketplace): ?><a class="tab-link" href="stock_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">stock pois</a><?php endif; ?>
        <?php if (price_tool_connection_supports($currentConnection, 'fbo_tool')): ?><a class="tab-link" href="ozon_fbo_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">FBO Tool</a><?php endif; ?>
      </div>
      <div class="tab-nav is-subtabs">
        <a class="tab-link active" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>">Общие настройки и цены</a>
        <a class="tab-link" href="ozon_price_tool_automations.php?connection_id=<?= h((string)$currentConnectionId) ?>">Автоматизация</a>
      </div>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="page">
    <?php if (!$currentMarketplaceReady): ?>
      <div class="card" style="background:linear-gradient(180deg,#fffaf3 0%,#ffffff 100%);">
        <h2><?= h($currentMarketplaceLabel) ?>: подключение заведено, рабочий Price Tool ещё готовится</h2>
        <div class="muted">Для этого маркетплейса уже можно хранить ключи и параметры кабинета, но расчёт фидов, автоматизация и выгрузка цен пока включены только для Ozon. Переключись на Ozon-подключение или открой глобальный раздел подключений, чтобы подготовить кабинет на будущее.</div>
      </div>
    <?php endif; ?>

    <?php if ($currentMarketplaceReady): ?>
    <div class="card">
      <div class="section-head">
        <div>
          <h2>Ценовые профили</h2>
          <div class="muted">
            <?php if ($isWbMarketplace): ?>
              Каждый ценовой профиль привязывается к поставщику и хранит свою формулу расчёта цен WB. Открывай профиль, чтобы настраивать скидки, доходность, расходы, предпросмотр и тест одного артикула перед отправкой.
            <?php else: ?>
              Каждый ценовой профиль привязывается к поставщику и хранит свою формулу расчёта. Открывай профиль, чтобы управлять параметрами, делать предпросмотр и быстрый тест товара.
            <?php endif; ?>
          </div>
        </div>
        <a class="button-link secondary" href="ozon_price_tool_feed.php?new=1&connection_id=<?= h((string)$currentConnectionId) ?>">Добавить профиль</a>
      </div>

      <div class="feeds-list">
        <?php if (!$feeds): ?>
          <div class="feed-item">
            <div class="muted">Пока нет ни одного профиля. Создай поставщика, затем добавь ценовой профиль с формулой расчёта.</div>
          </div>
        <?php endif; ?>
        <?php foreach ($feeds as $feed): ?>
          <div class="feed-item">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
              <div>
                <div style="font-weight:800; margin-bottom:6px; font-size:20px;"><?= h((string)$feed['name']) ?></div>
                <?php if (trim((string)($feed['supplier_name'] ?? '')) !== ''): ?>
                  <div class="muted" style="font-size:13px; margin-bottom:4px;">Поставщик: <?= h((string)$feed['supplier_name']) ?></div>
                <?php endif; ?>
                <div class="muted" style="font-size:13px; margin-bottom:4px;">Тег закупки: <code><?= h((string)$feed['cost_tag']) ?></code></div>
                <?php if ($isWbMarketplace): ?>
                  <div class="muted" style="font-size:13px; margin-bottom:4px;">Код поставщика: <?= trim((string)($feed['supplier_code'] ?? '')) !== '' ? '<code>' . h((string)$feed['supplier_code']) . '</code>' : '—' ?></div>
                  <div class="muted" style="font-size:13px;">
                    Расходы: WB API
                    <?php if (trim((string)($feed['wb_tariff_warehouse_name'] ?? '')) !== ''): ?>
                      · Склад тарифа: <?= h((string)$feed['wb_tariff_warehouse_name']) ?>
                    <?php endif; ?>
                    · Скидка WB: <?= h(number_format((float)($feed['wb_discount_percent'] ?? 0), 2, ',', ' ')) ?>%
                  </div>
                <?php else: ?>
                  <div class="muted" style="font-size:13px;">Схема: <?= h(strtoupper((string)$feed['fulfillment_scheme'])) ?></div>
                <?php endif; ?>
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a
                  class="button-link secondary icon-button"
                  href="ozon_price_tool_feed.php?clone_feed_id=<?= h((string)$feed['id']) ?>&connection_id=<?= h((string)$currentConnectionId) ?>"
                  title="Скопировать настройки профиля"
                  aria-label="Скопировать настройки профиля"
                >
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M9 9.75A2.75 2.75 0 0 1 11.75 7h6.5A2.75 2.75 0 0 1 21 9.75v8.5A2.75 2.75 0 0 1 18.25 21h-6.5A2.75 2.75 0 0 1 9 18.25z" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M6.25 17A2.25 2.25 0 0 1 4 14.75v-9A2.75 2.75 0 0 1 6.75 3h6.5A2.25 2.25 0 0 1 15.5 5.25" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                  </svg>
                </a>
                <a class="button-link secondary" href="ozon_price_tool_feed.php?feed_id=<?= h((string)$feed['id']) ?>&connection_id=<?= h((string)$currentConnectionId) ?>">Открыть настройки</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($isWbMarketplace): ?>
    <div class="card global-card">
      <h2>WB Price Tool</h2>
      <div class="muted">WB-ветка считает цены прямо из XML-фида, тянет комиссию, логистику и тариф возврата из API Wildberries, учитывает WB Клуб, диапазоны доходности и ручные потери по невыкупам/возвратам.</div>
      <div class="stats">
        <div class="stat">
          <div class="label">Профилей</div>
          <div class="value"><?= h((string)count($feeds)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Товаров в профилях</div>
          <div class="value"><?= h((string)array_sum(array_map(static fn($v) => (int)($v ?? 0), $feedOfferCounts))) ?></div>
        </div>
        <div class="stat">
          <div class="label">Тест и отправка</div>
          <div class="value" style="font-size:22px;">Готов</div>
        </div>
        <div class="stat">
          <div class="label">Массовая выгрузка</div>
          <div class="value" style="font-size:22px;">Готова</div>
        </div>
      </div>
    </div>

    <div class="card push-card">
      <div class="section-head">
        <div>
          <h2>Акции WB</h2>
          <div class="muted">Синхронизация сохраняет календарь акций, товары-участники и кандидатов с текущей/плановой ценой и скидкой. Эти данные используются для контроля слишком высоких скидок и могут включаться в расчёт цены в профиле фида.</div>
        </div>
        <?php if ($promoSyncLastOp): ?>
          <a class="button-link secondary" href="op.php?id=<?= h((string)$promoSyncLastOp['id']) ?>">Последняя синхронизация WB</a>
        <?php endif; ?>
      </div>
      <div class="stats">
        <div class="stat">
          <div class="label">Акций в БД</div>
          <div class="value"><?= h((string)($promoSyncSummary['promotions_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Обычных / авто</div>
          <div class="value" style="font-size:22px;"><?= h((string)($promoSyncSummary['regular_promotions_count'] ?? 0)) ?> / <?= h((string)($promoSyncSummary['auto_promotions_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Товаров в акциях</div>
          <div class="value"><?= h((string)($promoSyncSummary['products_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Участвуют / кандидаты</div>
          <div class="value" style="font-size:22px;"><?= h((string)($promoSyncSummary['participating_count'] ?? 0)) ?> / <?= h((string)($promoSyncSummary['candidate_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Скидка от 60%</div>
          <div class="value"><?= h((string)($promoSyncSummary['risky_discount_count'] ?? 0)) ?></div>
          <?php if (($promoSyncSummary['max_plan_discount'] ?? null) !== null): ?>
            <div class="muted" style="margin-top:6px;">Макс.: <?= h(number_format((float)$promoSyncSummary['max_plan_discount'], 2, ',', ' ')) ?>%</div>
          <?php endif; ?>
        </div>
        <div class="stat">
          <div class="label">Последнее обновление</div>
          <div class="value" style="font-size:22px;"><?= h((string)($promoSyncSummary['last_synced_at'] ?? '—')) ?></div>
          <?php if ($promoSyncLastOp): ?>
            <div class="muted" style="margin-top:6px;">Операция #<?= h((string)$promoSyncLastOp['id']) ?> · <?= h((string)$promoSyncLastOp['status']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (is_array($wbDecisionSummary)): ?>
        <div class="stats" style="margin-top:18px;">
          <div class="stat">
            <div class="label">Последних решений</div>
            <div class="value"><?= h((string)($wbDecisionSummary['total'] ?? 0)) ?></div>
          </div>
          <div class="stat">
            <div class="label">Выбрана акция</div>
            <div class="value"><?= h((string)($wbDecisionSummary['selected'] ?? 0)) ?></div>
          </div>
          <div class="stat">
            <div class="label">Добавить через API</div>
            <div class="value"><?= h((string)($wbDecisionSummary['upload_to_promotion'] ?? 0)) ?></div>
          </div>
          <div class="stat">
            <div class="label">Только цена/скидка</div>
            <div class="value"><?= h((string)($wbDecisionSummary['price_only'] ?? 0)) ?></div>
          </div>
          <div class="stat">
            <div class="label">Старый запас / ниже границы</div>
            <div class="value" style="font-size:22px;"><?= h((string)($wbDecisionSummary['blocked_below_target_margin'] ?? 0)) ?> / <?= h((string)($wbDecisionSummary['blocked_below_min_price'] ?? 0)) ?></div>
          </div>
          <div class="stat">
            <div class="label">Последний пересчёт</div>
            <div class="value" style="font-size:22px;"><?= h((string)($wbDecisionSummary['last_decided_at'] ?? '—')) ?></div>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($wbRecentDecisions)): ?>
        <details class="card" style="margin-top:18px; box-shadow:none; background:#fbfdff;">
          <summary style="cursor:pointer; font-weight:800;">Последние решения Price Tool по акциям</summary>
          <div class="muted" style="margin-top:8px;">Это последние сохранённые решения массового или ручного расчёта: какую акцию выбрали, какая цена плановая и какую скидку продавца нужно отправить.</div>
          <div class="data-table-wrap" style="margin-top:12px;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Артикул</th>
                  <th>Решение</th>
                  <th>Акция</th>
                  <th>Min / граница</th>
                  <th>План / итог</th>
                  <th>Скидка</th>
                  <th>Обновлено</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wbRecentDecisions as $decisionRow): ?>
                  <?php $decisionStatusView = wb_promotion_decision_status_view($decisionRow['decision_status'] ?? ''); ?>
                  <tr>
                    <td>
                      <code><?= h((string)($decisionRow['offer_id'] ?? '')) ?></code>
                      <div class="muted">nmID <?= h((string)($decisionRow['nm_id'] ?? '')) ?></div>
                    </td>
                    <td>
                      <span class="status-chip <?= h((string)$decisionStatusView['class']) ?>"><?= h((string)$decisionStatusView['label']) ?></span>
                      <div class="muted" style="margin-top:6px;"><?= h(wb_promotion_decision_action_label($decisionRow['decision_action'] ?? '')) ?></div>
                    </td>
                    <td>
                      <?= h((string)($decisionRow['promotion_name'] ?? '—')) ?>
                      <?php if (!empty($decisionRow['promotion_id'])): ?>
                        <div class="muted">ID <?= h((string)$decisionRow['promotion_id']) ?> · <?= h((string)($decisionRow['source_type'] ?? '')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="num">
                      <?= h(fmt_money_short($decisionRow['min_effective_sale_price'] ?? null)) ?><br>
                      <span class="muted"><?= h(fmt_money_short($decisionRow['target_effective_sale_price'] ?? null)) ?></span>
                    </td>
                    <td class="num">
                      <?= h(fmt_money_short($decisionRow['plan_effective_sale_price'] ?? null)) ?><br>
                      <span class="muted"><?= h(fmt_money_short($decisionRow['desired_effective_sale_price'] ?? null)) ?></span>
                    </td>
                    <td class="num"><?= h(fmt_percent_short($decisionRow['desired_discount'] ?? null)) ?></td>
                    <td class="num"><?= h(fmt_datetime_short($decisionRow['decided_at'] ?? ($decisionRow['updated_at'] ?? null))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
      <div id="wb-promotions-list" style="margin-top:22px;">
        <div class="section-head" style="margin-bottom:8px;">
          <div>
            <h3>Добавленные акции</h3>
            <div class="muted">Здесь видно, какие акции уже сохранены в базе, сколько товаров в них участвует и сколько пока только подходит по условиям акции.</div>
          </div>
        </div>
        <?php if (empty($wbPromotionRows)): ?>
          <div class="muted" style="margin-top:10px;">Акций пока нет. Импортируй XLSX автоакции или запусти синхронизацию WB.</div>
        <?php else: ?>
          <?php $wbPromotionCalendar = wb_promotion_calendar_build($wbPromotionRows); ?>
          <?php if (!empty($wbPromotionCalendar['days'])): ?>
            <?php
              $calendarDaysCount = count((array)$wbPromotionCalendar['days']);
              $calendarLanesCount = max(1, (int)($wbPromotionCalendar['lanes'] ?? 1));
            ?>
            <div class="wb-promo-calendar" aria-label="Календарь акций WB">
              <div
                class="wb-promo-calendar-scroll"
                style="--wb-promo-days: <?= h((string)$calendarDaysCount) ?>; --wb-promo-lanes: <?= h((string)$calendarLanesCount) ?>; --wb-promo-body-height: <?= h((string)(28 + $calendarLanesCount * 104)) ?>px;"
              >
                <div class="wb-promo-calendar-canvas">
                  <div class="wb-promo-calendar-months">
                    <?php foreach ((array)$wbPromotionCalendar['months'] as $monthInfo): ?>
                      <div
                        class="wb-promo-calendar-month"
                        style="grid-column: <?= h((string)((int)($monthInfo['offset'] ?? 0) + 1)) ?> / span <?= h((string)max(1, (int)($monthInfo['span'] ?? 1))) ?>;"
                      ><?= h((string)($monthInfo['label'] ?? '')) ?></div>
                    <?php endforeach; ?>
                  </div>
                  <div class="wb-promo-calendar-days">
                    <?php foreach ((array)$wbPromotionCalendar['days'] as $dayInfo): ?>
                      <div class="wb-promo-calendar-day<?= !empty($dayInfo['weekend']) ? ' is-weekend' : '' ?><?= !empty($dayInfo['today']) ? ' is-today' : '' ?>">
                        <?= h((string)($dayInfo['day'] ?? '')) ?>
                        <span><?= h((string)($dayInfo['weekday'] ?? '')) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="wb-promo-calendar-body">
                    <?php foreach ((array)$wbPromotionCalendar['days'] as $dayIndex => $dayInfo): ?>
                      <?php if (!empty($dayInfo['weekend'])): ?>
                        <div
                          class="wb-promo-calendar-weekend"
                          style="grid-column: <?= h((string)((int)$dayIndex + 1)) ?> / span 1; grid-row: 1 / <?= h((string)($calendarLanesCount + 1)) ?>;"
                        ></div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (($wbPromotionCalendar['today_offset'] ?? null) !== null): ?>
                      <div
                        class="wb-promo-calendar-today-line"
                        style="grid-column: <?= h((string)((int)$wbPromotionCalendar['today_offset'] + 1)) ?> / span 1; grid-row: 1 / <?= h((string)($calendarLanesCount + 1)) ?>;"
                      ></div>
                    <?php endif; ?>
                    <?php foreach ((array)$wbPromotionCalendar['items'] as $calendarItem): ?>
                      <?php
                        $promotionRow = (array)($calendarItem['row'] ?? []);
                        $promotionStatus = wb_promotion_status($promotionRow);
                        $promotionId = (int)($promotionRow['promotion_id'] ?? 0);
                        $productsCount = (int)($promotionRow['products_count'] ?? 0);
                        $participatingCount = (int)($promotionRow['participating_count'] ?? 0);
                        $candidateCount = (int)($promotionRow['candidate_count'] ?? 0);
                        $countLabels = wb_promotion_count_labels($promotionRow);
                        $typeLabel = wb_promotion_type_label($promotionRow['type'] ?? '');
                        $isAutoPromotion = $typeLabel === 'автоакция';
                        $mainCount = $participatingCount > 0 ? $participatingCount : $candidateCount;
                        $mainCountLabel = $participatingCount > 0 ? $countLabels['participating_short'] : $countLabels['candidate_short'];
                        $calendarClass = $isAutoPromotion ? 'is-auto' : 'is-regular';
                        if (($promotionStatus['class'] ?? '') === 'ended') {
                            $calendarClass .= ' is-muted';
                        }
                        $dateRange = fmt_promo_calendar_range($promotionRow['start_datetime'] ?? null, $promotionRow['end_datetime'] ?? null);
                        $href = 'ozon_price_tool.php?connection_id=' . urlencode((string)$currentConnectionId) . '&wb_promotion_id=' . urlencode((string)$promotionId) . '#wb-promotions-list';
                      ?>
                      <a
                        class="wb-promo-calendar-card <?= h($calendarClass) ?>"
                        href="<?= h($href) ?>"
                        style="grid-column: <?= h((string)((int)($calendarItem['offset'] ?? 0) + 1)) ?> / span <?= h((string)max(1, (int)($calendarItem['span'] ?? 1))) ?>; grid-row: <?= h((string)((int)($calendarItem['lane'] ?? 0) + 1)) ?>;"
                        title="<?= h((string)($promotionRow['name'] ?? ('WB action #' . $promotionId))) ?>"
                      >
                        <span class="wb-promo-calendar-title">
                          <b><?= h($isAutoPromotion ? 'Автоакция.' : 'Акция.') ?></b>
                          <?= h((string)($promotionRow['name'] ?? ('WB action #' . $promotionId))) ?>
                        </span>
                        <span class="wb-promo-calendar-chips">
                          <span class="wb-promo-calendar-chip is-ok"><?= h((string)$promotionStatus['label']) ?></span>
                          <?php if ($promotionId > 0): ?>
                            <span class="wb-promo-calendar-chip">ID <?= h((string)$promotionId) ?></span>
                          <?php endif; ?>
                          <?php if (($promotionRow['max_plan_discount'] ?? null) !== null && is_numeric($promotionRow['max_plan_discount'])): ?>
                            <span class="wb-promo-calendar-chip">скидка <?= h(fmt_percent_short($promotionRow['max_plan_discount'])) ?></span>
                          <?php endif; ?>
                          <?php if ($candidateCount > 0): ?>
                            <span class="wb-promo-calendar-chip">+<?= h((string)$candidateCount) ?></span>
                          <?php endif; ?>
                        </span>
                        <span class="wb-promo-calendar-meta">
                          <?= h($dateRange) ?> · <?= h($mainCountLabel) ?> <?= h((string)$mainCount) ?> из <?= h((string)$productsCount) ?> товаров
                        </span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="wb-promo-calendar-empty">Для календаря пока не хватает дат начала и окончания акций.</div>
          <?php endif; ?>

          <div class="data-table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Акция</th>
                  <th>Тип / статус</th>
                  <th>Срок</th>
                  <th>Товары</th>
                  <th>Плановая цена</th>
                  <th>Макс. скидка</th>
                  <th>Обновлено</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wbPromotionRows as $promotionRow): ?>
                  <?php
                    $promotionStatus = wb_promotion_status($promotionRow);
                    $promotionId = (int)($promotionRow['promotion_id'] ?? 0);
                    $productsCount = (int)($promotionRow['products_count'] ?? 0);
                    $participatingCount = (int)($promotionRow['participating_count'] ?? 0);
                    $candidateCount = (int)($promotionRow['candidate_count'] ?? 0);
                    $countLabels = wb_promotion_count_labels($promotionRow);
                    $minPlanPrice = $promotionRow['min_plan_price'] ?? null;
                    $maxPlanPrice = $promotionRow['max_plan_price'] ?? null;
                  ?>
                  <tr>
                    <td>
                      <span class="promo-title"><?= h((string)($promotionRow['name'] ?? ('WB action #' . $promotionId))) ?></span>
                      <div class="muted">ID <?= h((string)$promotionId) ?></div>
                    </td>
                    <td>
                      <span class="status-chip <?= h((string)$promotionStatus['class']) ?>"><?= h((string)$promotionStatus['label']) ?></span>
                      <div class="muted" style="margin-top:6px;"><?= h(wb_promotion_type_label($promotionRow['type'] ?? '')) ?></div>
                    </td>
                    <td class="num">
                      <?= h(fmt_datetime_short($promotionRow['start_datetime'] ?? null)) ?><br>
                      <?= h(fmt_datetime_short($promotionRow['end_datetime'] ?? null)) ?>
                    </td>
                    <td class="num">
                      Всего: <?= h((string)$productsCount) ?><br>
                      <span class="muted"><?= h($countLabels['participating']) ?>: <?= h((string)$participatingCount) ?> · <?= h($countLabels['candidate']) ?>: <?= h((string)$candidateCount) ?></span>
                    </td>
                    <td class="num">
                      <?php if ($minPlanPrice !== null && $maxPlanPrice !== null && (float)$minPlanPrice !== (float)$maxPlanPrice): ?>
                        <?= h(fmt_money_short($minPlanPrice)) ?> – <?= h(fmt_money_short($maxPlanPrice)) ?>
                      <?php else: ?>
                        <?= h(fmt_money_short($maxPlanPrice ?? $minPlanPrice)) ?>
                      <?php endif; ?>
                    </td>
                    <td class="num"><?= h(fmt_percent_short($promotionRow['max_plan_discount'] ?? null)) ?></td>
                    <td class="num">
                      <?= h(fmt_datetime_short($promotionRow['last_product_synced_at'] ?? ($promotionRow['synced_at'] ?? null))) ?>
                    </td>
                    <td>
                      <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a
                          class="button-link secondary"
                          href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>&wb_promotion_id=<?= h((string)$promotionId) ?>#wb-promotions-list"
                        >Товары</a>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Удалить эту акцию WB из локальной базы вместе с товарами, историей и решениями price tool?');">
                          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                          <input type="hidden" name="action" value="delete_wb_promotion">
                          <input type="hidden" name="promotion_id" value="<?= h((string)$promotionId) ?>">
                          <button type="submit" class="danger">Удалить</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($wbSelectedPromotion !== null): ?>
          <?php
            $selectedProductsCount = (int)($wbSelectedPromotion['products_count'] ?? count($wbSelectedPromotionProducts));
            $selectedPromotionStatus = wb_promotion_status($wbSelectedPromotion);
          ?>
          <div class="card" style="margin-top:18px; box-shadow:none; background:#fbfdff;">
            <div class="section-head">
              <div>
                <h3>Товары в акции: <?= h((string)($wbSelectedPromotion['name'] ?? ('WB action #' . $wbSelectedPromotionId))) ?></h3>
                <div class="muted">
                  <span class="status-chip <?= h((string)$selectedPromotionStatus['class']) ?>"><?= h((string)$selectedPromotionStatus['label']) ?></span>
                  ID <?= h((string)$wbSelectedPromotionId) ?> · показано <?= h((string)count($wbSelectedPromotionProducts)) ?> из <?= h((string)$selectedProductsCount) ?>
                  <?php if ($selectedProductsCount > $wbSelectedPromotionProductsLimit): ?>
                    · первые <?= h((string)$wbSelectedPromotionProductsLimit) ?> строк
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="button-link secondary" href="ozon_price_tool.php?connection_id=<?= h((string)$currentConnectionId) ?>#wb-promotions-list">Скрыть товары</a>
                <form method="post" style="margin:0;" onsubmit="return confirm('Удалить эту акцию WB из локальной базы вместе с товарами, историей и решениями price tool?');">
                  <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
                  <input type="hidden" name="action" value="delete_wb_promotion">
                  <input type="hidden" name="promotion_id" value="<?= h((string)$wbSelectedPromotionId) ?>">
                  <button type="submit" class="danger">Удалить акцию</button>
                </form>
              </div>
            </div>
            <?php if (empty($wbSelectedPromotionProducts)): ?>
              <div class="muted">По этой акции пока нет сохранённых товаров.</div>
            <?php else: ?>
              <div class="data-table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Статус</th>
                      <th>nmID</th>
                      <th>Артикул</th>
                      <th>Текущая цена</th>
                      <th>Плановая цена</th>
                      <th>Скидка</th>
                      <th>Плановая скидка</th>
                      <th>Изменение скидки</th>
                      <th>Обновлено</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($wbSelectedPromotionProducts as $productRow): ?>
                      <?php $productStatus = wb_promotion_product_status($productRow, $wbSelectedPromotion); ?>
                      <tr>
                        <td><span class="status-chip <?= h((string)$productStatus['class']) ?>"><?= h((string)$productStatus['label']) ?></span></td>
                        <td class="num"><?= h((string)($productRow['nm_id'] ?? '')) ?></td>
                        <td><?= h((string)($productRow['vendor_code'] ?? '—')) ?></td>
                        <td class="num"><?= h(fmt_money_short($productRow['price'] ?? null)) ?></td>
                        <td class="num"><?= h(fmt_money_short($productRow['plan_price'] ?? null)) ?></td>
                        <td class="num"><?= h(fmt_percent_short($productRow['discount'] ?? null)) ?></td>
                        <td class="num"><?= h(fmt_percent_short($productRow['plan_discount'] ?? null)) ?></td>
                        <td class="num"><?= h(fmt_percent_short($productRow['plan_discount_delta'] ?? null)) ?></td>
                        <td class="num"><?= h(fmt_datetime_short($productRow['synced_at'] ?? ($productRow['updated_at'] ?? null))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        <?php elseif ($wbSelectedPromotionId > 0): ?>
          <div class="error" style="margin:18px 0 0;">Акция WB #<?= h((string)$wbSelectedPromotionId) ?> не найдена в сохранённых данных этого кабинета.</div>
        <?php endif; ?>
      </div>
      <form method="post" action="wb_promotion_import_xlsx.php" enctype="multipart/form-data" style="margin-top:18px;">
        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
        <div class="grid">
          <div class="field">
            <label for="wb_promo_xlsx">XLS/XLSX автоакции WB</label>
            <input id="wb_promo_xlsx" type="file" name="xlsxfile" accept=".xls,.xlsx" required>
            <small>Файл “Все товары подходящие для акции…” из кабинета WB. Для автоакций это источник плановой цены и скидки.</small>
          </div>
          <div class="field">
            <label for="wb_promo_title">Название акции</label>
            <input id="wb_promo_title" type="text" name="promotion_title" placeholder="Можно оставить пустым">
            <small>Если оставить пустым, сервис попробует взять название из имени файла и сопоставить его с календарём WB.</small>
          </div>
          <div class="field">
            <label for="wb_promo_id">ID акции WB</label>
            <input id="wb_promo_id" type="number" name="promotion_id" min="0" placeholder="Необязательно">
            <small>Нужен только если название из файла не совпадает с календарём WB.</small>
          </div>
          <div class="field">
            <label for="wb_promo_start">Начало акции</label>
            <input id="wb_promo_start" type="date" name="promotion_start">
            <small>Сервис сохранит эту дату как 00:00. Можно оставить пустым, если акция уже есть в календаре WB и сопоставится по названию или ID.</small>
          </div>
          <div class="field">
            <label for="wb_promo_end">Окончание акции</label>
            <input id="wb_promo_end" type="date" name="promotion_end">
            <small>Сервис сохранит эту дату как 23:59. Для ручной XLSX-акции без сопоставления с календарём дата окончания обязательна.</small>
          </div>
        </div>
        <div class="actions">
          <button type="submit" class="secondary">Импортировать XLS/XLSX автоакции</button>
        </div>
      </form>
      <div class="card" id="wb-promo-download-settings" style="margin-top:18px; box-shadow:none; background:#fbfdff;">
        <div class="section-head">
          <div>
            <h3>Автоскачивание XLS/XLSX из кабинета WB</h3>
            <div class="muted">Система сама распознаёт запросы и подставляет ID каждой акции.</div>
          </div>
          <?php if ($wbPromoDownloadLastOp): ?>
            <a class="button-link secondary" href="op.php?id=<?= h((string)$wbPromoDownloadLastOp['id']) ?>">Последнее скачивание</a>
          <?php endif; ?>
        </div>
        <form method="post" style="margin-top:14px;">
          <input type="hidden" name="action" value="save_wb_promo_download_settings">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="wb_promo_download_sample_promotion_id" value="0">
          <div class="field">
            <label for="wb_promo_detail_curl_template">1. Детали акции <span class="muted">· обязательно</span></label>
            <textarea id="wb_promo_detail_curl_template" name="wb_promo_detail_curl_template" style="min-height:150px;" placeholder="Copy as cURL для запроса promotions/detail?promoID=..." required><?= $wbPromoCurlSaveAttempted ? h((string)($_POST['wb_promo_detail_curl_template'] ?? '')) : '' ?></textarea>
            <small>Нужен для получения внутреннего <code>periodID</code>. Числовой <code>promoID</code> заменится автоматически.</small>
          </div>
          <div class="field" style="margin-top:12px;">
            <label for="wb_promo_generate_curl_template">2. Сформировать файл <span class="muted">· обязательно</span></label>
            <textarea id="wb_promo_generate_curl_template" name="wb_promo_generate_curl_template" style="min-height:150px;" placeholder="Copy as cURL для запроса после нажатия «Сформировать файл»" required><?= $wbPromoCurlSaveAttempted ? h((string)($_POST['wb_promo_generate_curl_template'] ?? '')) : '' ?></textarea>
            <small>Создаёт свежий отчёт по выбранной акции. Числовой <code>periodID</code> заменится автоматически.</small>
          </div>
          <div class="field" style="margin-top:12px;">
            <label for="wb_promo_download_curl_template">3. Скачать файл <span class="muted">· обязательно</span></label>
            <textarea id="wb_promo_download_curl_template" name="wb_promo_download_curl_template" style="min-height:150px;" placeholder="Copy as cURL для запроса после нажатия «Скачать файл»" required><?= $wbPromoCurlSaveAttempted ? h((string)($_POST['wb_promo_download_curl_template'] ?? '')) : '' ?></textarea>
            <small>Возвращает готовый XLS/XLSX. Числовой <code>periodID</code> заменится автоматически.</small>
          </div>
          <div class="actions">
            <button type="submit" class="secondary">Проверить три запроса и сохранить</button>
            <?php if (!$wbPromoCurlSaveAttempted && trim((string)($wbPromotionDownloadSettings['last_test_status'] ?? '')) !== ''): ?>
              <span class="muted" style="color:<?= (string)($wbPromotionDownloadSettings['last_test_status'] ?? '') === 'ok' ? '#166534' : '#b91c1c' ?>;">
                <?= h((string)($wbPromotionDownloadSettings['last_test_message'] ?? '')) ?>
              </span>
            <?php endif; ?>
          </div>
        </form>
        <div class="actions">
          <form method="post" action="run_op.php">
            <input type="hidden" name="dataset_id" value="0">
            <input type="hidden" name="op_type" value="wb_download_promotion_xlsx">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <input type="hidden" name="inbox_dir" value="<?= h($wbPromotionImportInboxDir !== '' ? $wbPromotionImportInboxDir : wb_promotions_default_import_inbox_dir($currentConnectionId)) ?>">
            <input type="hidden" name="days_ahead" value="45">
            <input type="hidden" name="max_promotions" value="20">
            <input type="hidden" name="only_auto" value="1">
            <input type="hidden" name="import_after_download" value="1">
            <button type="submit" class="secondary">Скачать и импортировать активные автоакции</button>
          </form>
          <?php if ($wbPromoDownloadLastOp): ?>
            <span class="muted">Операция #<?= h((string)$wbPromoDownloadLastOp['id']) ?> · <?= h((string)$wbPromoDownloadLastOp['status']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card" style="margin-top:18px; box-shadow:none; background:#fbfdff;">
        <div class="section-head">
          <div>
            <h3>Автоимпорт XLSX из папки</h3>
            <div class="muted">Файлы автоакций WB, скачанные в эту папку, можно разобрать одной фоновой операцией. Поддерживаются .xls и .xlsx; успешные файлы будут перенесены в <code>_processed</code>, ошибочные — в <code>_failed</code>.</div>
          </div>
          <?php if ($wbPromoFolderImportLastOp): ?>
            <a class="button-link secondary" href="op.php?id=<?= h((string)$wbPromoFolderImportLastOp['id']) ?>">Последний автоимпорт</a>
          <?php endif; ?>
        </div>
        <div class="stat" style="margin-top:12px;">
          <div class="label">Папка для XLSX</div>
          <div class="value" style="font-size:16px; word-break:break-all;"><?= h($wbPromotionImportInboxDir !== '' ? $wbPromotionImportInboxDir : wb_promotions_default_import_inbox_dir($currentConnectionId)) ?></div>
        </div>
        <div class="actions">
          <form method="post" action="run_op.php">
            <input type="hidden" name="dataset_id" value="0">
            <input type="hidden" name="op_type" value="wb_import_promotion_xlsx_folder">
            <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
            <input type="hidden" name="inbox_dir" value="<?= h($wbPromotionImportInboxDir !== '' ? $wbPromotionImportInboxDir : wb_promotions_default_import_inbox_dir($currentConnectionId)) ?>">
            <input type="hidden" name="max_files" value="0">
            <input type="hidden" name="min_age_sec" value="10">
            <input type="hidden" name="move_processed" value="1">
            <input type="hidden" name="move_failed" value="1">
            <button type="submit" class="secondary">Разобрать новые XLSX из папки</button>
          </form>
          <?php if ($wbPromoFolderImportLastOp): ?>
            <span class="muted">Операция #<?= h((string)$wbPromoFolderImportLastOp['id']) ?> · <?= h((string)$wbPromoFolderImportLastOp['status']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="actions">
        <form method="post" action="run_op.php">
          <input type="hidden" name="dataset_id" value="0">
          <input type="hidden" name="op_type" value="wb_sync_promotions">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="days_back" value="7">
          <input type="hidden" name="days_ahead" value="45">
          <input type="hidden" name="all_promo" value="0">
          <input type="hidden" name="sync_products" value="1">
          <input type="hidden" name="sync_candidates" value="1">
          <input type="hidden" name="danger_plan_discount_percent" value="60">
          <button type="submit">Обновить акции WB в БД</button>
        </form>
        <a class="button-link secondary" href="ozon_price_tool_automations.php?connection_id=<?= h((string)$currentConnectionId) ?>">Автоматизация акций WB</a>
      </div>
    </div>

    <div class="card push-card">
      <div class="section-head">
        <div>
          <h2>Массовое обновление цен WB</h2>
          <div class="muted">Выбери ценовые профили, и сервис через очередь заново скачает фиды, пересчитает все товары и отправит актуальные цену, скидку продавца и скидку WB Клуба.</div>
        </div>
        <?php if ($pushFeedsLastOp): ?>
          <a class="button-link secondary" href="op.php?id=<?= h((string)$pushFeedsLastOp['id']) ?>">Последняя выгрузка WB</a>
        <?php endif; ?>
      </div>
      <?php if (!$feeds): ?>
        <div class="muted">Сначала создай хотя бы один ценовой профиль, и здесь появится массовый запуск.</div>
      <?php else: ?>
        <form method="post" action="run_op.php" id="pushFeedsForm">
          <input type="hidden" name="dataset_id" value="0">
          <input type="hidden" name="op_type" value="wb_push_selected_feeds">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="feed_ids_json" id="pushFeedsJson" value="[]">
          <input type="hidden" name="force_refresh" value="1">
          <input type="hidden" name="push_all" value="1">
          <div class="feed-select-grid">
            <?php foreach ($feeds as $feed): ?>
              <label class="feed-select">
                <input type="checkbox" value="<?= h((string)$feed['id']) ?>" data-feed-push-checkbox>
                <span>
                  <span style="display:block; font-weight:800; font-size:18px; margin-bottom:4px;"><?= h((string)$feed['name']) ?></span>
                  <?php if (trim((string)($feed['supplier_name'] ?? '')) !== ''): ?>
                    <span class="muted" style="display:block; font-size:13px;">Поставщик: <?= h((string)$feed['supplier_name']) ?></span>
                  <?php endif; ?>
                  <span class="muted" style="display:block; font-size:13px;">Тег закупки: <code><?= h((string)$feed['cost_tag']) ?></code></span>
                  <span class="muted" style="display:block; font-size:13px;">Товаров в фиде: <?= h(($feedOfferCounts[(int)($feed['id'] ?? 0)] ?? null) === null ? '—' : (string)$feedOfferCounts[(int)($feed['id'] ?? 0)]) ?></span>
                  <?php if (!empty($feed['supplier_code'])): ?>
                    <span class="muted" style="display:block; font-size:13px;">Код поставщика: <code><?= h((string)$feed['supplier_code']) ?></code></span>
                  <?php endif; ?>
                  <span class="muted" style="display:block; font-size:13px;">Скидка WB: <?= h(number_format((float)($feed['wb_discount_percent'] ?? 0), 2, ',', ' ')) ?>% · WB Клуб: <?= h(number_format((float)($feed['wb_club_discount_percent'] ?? 0), 2, ',', ' ')) ?>%</span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button type="button" class="secondary" id="pushFeedsSelectAll">Выбрать все</button>
            <button type="button" class="secondary" id="pushFeedsReset">Снять выбор</button>
            <button type="submit">Обновить цены WB по выбранным фидам</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isYandexMarketplace): ?>
    <div class="card global-card">
      <h2>Яндекс Price Tool</h2>
      <div class="muted">Яндекс-ветка считает цены из XML-фида, подтягивает текущие цены, карточки, рекомендации и тарифы площадки через Partner API, учитывает ручные расходы и minimumForBestseller.</div>
      <div class="stats">
        <div class="stat">
          <div class="label">Профилей</div>
          <div class="value"><?= h((string)count($feeds)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Товаров в профилях</div>
          <div class="value"><?= h((string)array_sum(array_map(static fn($v) => (int)($v ?? 0), $feedOfferCounts))) ?></div>
        </div>
        <div class="stat">
          <div class="label">Данные API</div>
          <div class="value" style="font-size:22px;">Цены + тарифы</div>
        </div>
        <div class="stat">
          <div class="label">Массовая выгрузка</div>
          <div class="value" style="font-size:22px;">Готова</div>
        </div>
      </div>
    </div>

    <div class="card push-card">
      <div class="section-head">
        <div>
          <h2>Массовое обновление цен Яндекс Маркета</h2>
          <div class="muted">Выбери ценовые профили, и сервис через очередь пересчитает фиды, получит тарифы и рекомендации Яндекса, отправит цену, зачёркнутую цену и minimumForBestseller.</div>
        </div>
        <?php if ($pushFeedsLastOp): ?>
          <a class="button-link secondary" href="op.php?id=<?= h((string)$pushFeedsLastOp['id']) ?>">Последняя выгрузка Яндекс</a>
        <?php endif; ?>
      </div>
      <?php if (!$feeds): ?>
        <div class="muted">Сначала создай хотя бы один ценовой профиль, и здесь появится массовый запуск.</div>
      <?php else: ?>
        <form method="post" action="run_op.php" id="pushFeedsForm">
          <input type="hidden" name="dataset_id" value="0">
          <input type="hidden" name="op_type" value="yandex_push_selected_feeds">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="feed_ids_json" id="pushFeedsJson" value="[]">
          <div class="feed-select-grid">
            <?php foreach ($feeds as $feed): ?>
              <label class="feed-select">
                <input type="checkbox" value="<?= h((string)$feed['id']) ?>" data-feed-push-checkbox>
                <span>
                  <span style="display:block; font-weight:800; font-size:18px; margin-bottom:4px;"><?= h((string)$feed['name']) ?></span>
                  <?php if (trim((string)($feed['supplier_name'] ?? '')) !== ''): ?>
                    <span class="muted" style="display:block; font-size:13px;">Поставщик: <?= h((string)$feed['supplier_name']) ?></span>
                  <?php endif; ?>
                  <span class="muted" style="display:block; font-size:13px;">Тег закупки: <code><?= h((string)$feed['cost_tag']) ?></code></span>
                  <span class="muted" style="display:block; font-size:13px;">Схема: <?= h(strtoupper((string)$feed['fulfillment_scheme'])) ?> · Товаров: <?= h(($feedOfferCounts[(int)($feed['id'] ?? 0)] ?? null) === null ? '—' : (string)$feedOfferCounts[(int)($feed['id'] ?? 0)]) ?></span>
                  <?php if (!empty($feed['supplier_code'])): ?>
                    <span class="muted" style="display:block; font-size:13px;">Код поставщика: <code><?= h((string)$feed['supplier_code']) ?></code></span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button type="button" class="secondary" id="pushFeedsSelectAll">Выбрать все</button>
            <button type="button" class="secondary" id="pushFeedsReset">Снять выбор</button>
            <button type="submit">Обновить цены Яндекс по выбранным фидам</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isOzonMarketplace): ?>
    <div class="card global-card">
      <h2>Общие настройки</h2>
      <div class="muted">Эти параметры действуют сразу на весь Ozon Price Tool. Сейчас сюда вынесен общий логистический микс, который используется при расчёте магистрали для всех фидов.</div>
      <form method="post" style="margin-top:14px;">
        <input type="hidden" name="action" value="save_global_settings">
        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
        <div class="grid">
          <div class="field">
            <label for="logistics_moscow_share_percent">Москва, %</label>
            <input id="logistics_moscow_share_percent" name="logistics_moscow_share_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($globalSettings['logistics_moscow_share_percent'] ?? '25.00')) ?>">
            <small>Доля заказов, где магистраль считается по <code>min</code>.</small>
          </div>
          <div class="field">
            <label for="logistics_spb_share_percent">Санкт-Петербург, %</label>
            <input id="logistics_spb_share_percent" name="logistics_spb_share_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($globalSettings['logistics_spb_share_percent'] ?? '25.00')) ?>">
            <small>Доля заказов для Санкт-Петербурга.</small>
          </div>
          <div class="field">
            <label for="logistics_regions_share_percent">Регионы, %</label>
            <input id="logistics_regions_share_percent" name="logistics_regions_share_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($globalSettings['logistics_regions_share_percent'] ?? '50.00')) ?>">
            <small>Остальной региональный поток.</small>
          </div>
          <div class="field">
            <label for="logistics_spb_multiplier_percent">Санкт-Петербург от min, %</label>
            <input id="logistics_spb_multiplier_percent" name="logistics_spb_multiplier_percent" type="text" inputmode="decimal" value="<?= h(fmt_input_decimal($globalSettings['logistics_spb_multiplier_percent'] ?? '130.00')) ?>">
            <small><code>130</code> означает <code>1,3 × min</code>.</small>
          </div>
        </div>
        <div class="actions">
          <button type="submit">Сохранить общие настройки</button>
        </div>
      </form>
    </div>

    <div class="card force-card">
      <h2>Форсирование цен</h2>
      <div class="muted">Это общий ручной слой поверх расчёта. Правила применяются в самом конце ко всем итоговым ценам товара: обычной цене, `min price` и ценам для акций. Приоритет: артикул, затем категория поставщика, затем бренд из прайса.</div>
      <form method="post" style="margin-top:14px;">
        <input type="hidden" name="action" value="save_global_settings">
        <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
        <div class="grid">
          <div class="field">
            <label for="price_force_enabled">Активация</label>
            <div style="display:flex; gap:16px; align-items:center; min-height:48px; padding:0 2px;">
              <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="radio" name="price_force_enabled" value="0" <?= empty($globalSettings['price_force_enabled']) ? 'checked' : '' ?>>
                Выключено
              </label>
              <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="radio" name="price_force_enabled" value="1" <?= !empty($globalSettings['price_force_enabled']) ? 'checked' : '' ?>>
                Включено
              </label>
            </div>
            <small>Если выключено, сервис игнорирует список правил и работает только по основной формуле.</small>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="price_force_rules_text">Список правил: `offer_id = выражение`</label>
            <textarea id="price_force_rules_text" name="price_force_rules_text" placeholder="00632__22 = +100&#10;107862__16 = -5%&#10;BK-1800-25-3S__4 = 7090"><?= h((string)($globalSettings['price_force_rules_text'] ?? '')) ?></textarea>
            <small>
              Поддерживаются три режима:
              <code>offer_id = 100</code> — зафиксировать цену,
              <code>offer_id = +100</code> / <code>-50</code> — изменить на рубли,
              <code>offer_id = +20%</code> / <code>-10%</code> — изменить на процент.
            </small>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="price_force_category_rules_text">Категории поставщика: `categoryId` или полный путь = выражение</label>
            <div class="force-rule-builder" data-force-builder data-force-textarea="price_force_category_rules_text">
              <div class="force-rule-toolbar">
                <input type="search" data-force-search placeholder="найти категорию">
                <input type="text" data-force-expression placeholder="+7%, -100, 9900">
                <button type="button" class="secondary force-rule-mini-button" data-force-select-visible>выбрать</button>
                <button type="button" class="force-rule-mini-button" data-force-add>добавить</button>
              </div>
              <div class="force-rule-meta">
                <span class="force-rule-pill" data-force-selected-count>выбрано: 0</span>
                <span class="force-rule-pill" data-force-existing-count>в правилах: 0</span>
                <button type="button" class="secondary force-rule-mini-button" data-force-clear>очистить</button>
              </div>
              <div class="force-rule-options">
                <?php if (empty($forceRuleOptions['categories'])): ?>
                  <div class="force-rule-empty">Список категорий пока пуст. Обнови товары поставщиков или открой фид, чтобы сервис прочитал категории из XML.</div>
                <?php endif; ?>
                <?php foreach ((array)($forceRuleOptions['categories'] ?? []) as $categoryOption): ?>
                  <?php
                    if (!is_array($categoryOption)) { continue; }
                    $target = trim((string)($categoryOption['value'] ?? ''));
                    if ($target === '') { continue; }
                    $categoryId = trim((string)($categoryOption['id'] ?? ''));
                    $altTargets = ($categoryId !== '' && $categoryId !== $target) ? [$categoryId] : [];
                    $searchText = trim(implode(' ', array_filter([
                      (string)($categoryOption['label'] ?? $target),
                      $target,
                      $categoryId,
                      (string)($categoryOption['source_label'] ?? ''),
                    ])));
                  ?>
                  <label
                    class="force-rule-option"
                    data-force-option
                    data-force-target="<?= h($target) ?>"
                    data-force-alt-targets="<?= h(json_encode($altTargets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    data-force-search-text="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>"
                  >
                    <input type="checkbox" data-force-check>
                    <span class="force-rule-option-main">
                      <?= h((string)($categoryOption['label'] ?? $target)) ?>
                      <span class="force-rule-option-sub">
                        <?php if ($categoryId !== ''): ?>ID <?= h($categoryId) ?> · <?php endif; ?>
                        <?= h((string)(int)($categoryOption['count'] ?? 0)) ?> товаров<?php if (trim((string)($categoryOption['source_label'] ?? '')) !== ''): ?> · <?= h((string)$categoryOption['source_label']) ?><?php endif; ?>
                      </span>
                    </span>
                    <span class="force-rule-status">есть правило</span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <textarea id="price_force_category_rules_text" name="price_force_category_rules_text" placeholder="48233792 = +7%&#10;Электроника -> Квадрокоптеры, дроны, роботы -> Аккумулятор для дрона, квадрокоптера = -100"><?= h((string)($globalSettings['price_force_category_rules_text'] ?? '')) ?></textarea>
            <small>Категория берётся из XML-фида поставщика. Можно указывать короткий ID из `categoryId` или полный путь категории, если нужно точнее отделить похожие категории разных поставщиков.</small>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="price_force_brand_rules_text">Бренды из прайса: `бренд = выражение`</label>
            <div class="force-rule-builder" data-force-builder data-force-textarea="price_force_brand_rules_text">
              <div class="force-rule-toolbar">
                <input type="search" data-force-search placeholder="найти бренд">
                <input type="text" data-force-expression placeholder="+5%, -50, 9900">
                <button type="button" class="secondary force-rule-mini-button" data-force-select-visible>выбрать</button>
                <button type="button" class="force-rule-mini-button" data-force-add>добавить</button>
              </div>
              <div class="force-rule-meta">
                <span class="force-rule-pill" data-force-selected-count>выбрано: 0</span>
                <span class="force-rule-pill" data-force-existing-count>в правилах: 0</span>
                <button type="button" class="secondary force-rule-mini-button" data-force-clear>очистить</button>
              </div>
              <div class="force-rule-options">
                <?php if (empty($forceRuleOptions['brands'])): ?>
                  <div class="force-rule-empty">Список брендов пока пуст. Бренды берутся из тегов <code>brand</code> и <code>vendor</code> в прайсах поставщиков.</div>
                <?php endif; ?>
                <?php foreach ((array)($forceRuleOptions['brands'] ?? []) as $brandOption): ?>
                  <?php
                    if (!is_array($brandOption)) { continue; }
                    $target = trim((string)($brandOption['value'] ?? ''));
                    if ($target === '') { continue; }
                    $searchText = trim(implode(' ', array_filter([
                      (string)($brandOption['label'] ?? $target),
                      $target,
                      (string)($brandOption['source_label'] ?? ''),
                    ])));
                  ?>
                  <label
                    class="force-rule-option"
                    data-force-option
                    data-force-target="<?= h($target) ?>"
                    data-force-alt-targets="[]"
                    data-force-search-text="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>"
                  >
                    <input type="checkbox" data-force-check>
                    <span class="force-rule-option-main">
                      <?= h((string)($brandOption['label'] ?? $target)) ?>
                      <span class="force-rule-option-sub">
                        <?= h((string)(int)($brandOption['count'] ?? 0)) ?> товаров<?php if (trim((string)($brandOption['source_label'] ?? '')) !== ''): ?> · <?= h((string)$brandOption['source_label']) ?><?php endif; ?>
                      </span>
                    </span>
                    <span class="force-rule-status">есть правило</span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <textarea id="price_force_brand_rules_text" name="price_force_brand_rules_text" placeholder="Matek = +5%&#10;BetaFPV = -50&#10;DJI = 9900"><?= h((string)($globalSettings['price_force_brand_rules_text'] ?? '')) ?></textarea>
            <small>Бренд берётся из тега `brand`, а если его нет — из `vendor`. Сравнение не чувствительно к регистру.</small>
          </div>
        </div>
        <div class="actions">
          <button type="submit">Сохранить правила форсирования</button>
        </div>
      </form>
    </div>

    <div class="card promo-card">
      <h2>Синхронизация акций Ozon</h2>
      <div class="muted">Этот блок общий для всего инструмента. Он подтягивает из Ozon весь список акций и товары, которые уже участвуют или могут участвовать в них. Дальше все фиды используют эти данные из нашей базы.</div>
      <div class="stats">
        <div class="stat">
          <div class="label">Акций в БД</div>
          <div class="value"><?= h((string)($promoSyncSummary['actions_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Товаров в акциях</div>
          <div class="value"><?= h((string)($promoSyncSummary['products_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Уже участвуют</div>
          <div class="value"><?= h((string)($promoSyncSummary['participating_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Могут участвовать</div>
          <div class="value"><?= h((string)($promoSyncSummary['candidate_count'] ?? 0)) ?></div>
        </div>
        <div class="stat">
          <div class="label">Последнее обновление</div>
          <div class="value" style="font-size:22px;"><?= h((string)($promoSyncSummary['last_synced_at'] ?? '—')) ?></div>
          <?php if ($promoSyncLastOp): ?>
            <div class="muted" style="margin-top:6px;">Операция #<?= h((string)$promoSyncLastOp['id']) ?> · <?= h((string)$promoSyncLastOp['status']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="actions">
        <form method="post" action="run_op.php">
          <input type="hidden" name="dataset_id" value="0">
          <input type="hidden" name="op_type" value="ozon_sync_actions">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <button type="submit">Обновить акции Ozon в БД</button>
        </form>
        <?php if ($promoSyncLastOp): ?>
          <a class="button-link secondary" href="op.php?id=<?= h((string)$promoSyncLastOp['id']) ?>">Открыть последнюю операцию</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card push-card">
      <div class="section-head">
        <div>
          <h2>Массовое обновление цен по профилям</h2>
          <div class="muted">Здесь можно выбрать сразу несколько ценовых профилей и запустить обновление всех цен, `min price` и акций в Ozon. Операция пойдёт через очередь и откроет отдельную страницу статуса.</div>
        </div>
        <?php if ($pushFeedsLastOp): ?>
          <a class="button-link secondary" href="op.php?id=<?= h((string)$pushFeedsLastOp['id']) ?>">Последняя массовая выгрузка</a>
        <?php endif; ?>
      </div>
      <?php if (!$feeds): ?>
        <div class="muted">Сначала создай хотя бы один ценовой профиль, и здесь появится массовый запуск.</div>
      <?php else: ?>
        <form method="post" action="run_op.php" id="pushFeedsForm">
          <input type="hidden" name="dataset_id" value="0">
          <input type="hidden" name="op_type" value="ozon_push_selected_feeds">
          <input type="hidden" name="connection_id" value="<?= h((string)$currentConnectionId) ?>">
          <input type="hidden" name="feed_ids_json" id="pushFeedsJson" value="[]">
          <div class="feed-select-grid">
            <?php foreach ($feeds as $feed): ?>
              <label class="feed-select">
                <input type="checkbox" value="<?= h((string)$feed['id']) ?>" data-feed-push-checkbox>
                <span>
                  <span style="display:block; font-weight:800; font-size:18px; margin-bottom:4px;"><?= h((string)$feed['name']) ?></span>
                  <?php if (trim((string)($feed['supplier_name'] ?? '')) !== ''): ?>
                    <span class="muted" style="display:block; font-size:13px;">Поставщик: <?= h((string)$feed['supplier_name']) ?></span>
                  <?php endif; ?>
                  <span class="muted" style="display:block; font-size:13px;">Тег закупки: <code><?= h((string)$feed['cost_tag']) ?></code></span>
                  <span class="muted" style="display:block; font-size:13px;">Схема: <?= h(strtoupper((string)$feed['fulfillment_scheme'])) ?></span>
                  <span class="muted" style="display:block; font-size:13px;">Товаров в фиде: <?= h(($feedOfferCounts[(int)($feed['id'] ?? 0)] ?? null) === null ? '—' : (string)$feedOfferCounts[(int)($feed['id'] ?? 0)]) ?></span>
                  <?php if (!empty($feed['supplier_code'])): ?>
                    <span class="muted" style="display:block; font-size:13px;">Код поставщика: <code><?= h((string)$feed['supplier_code']) ?></code></span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button type="button" class="secondary" id="pushFeedsSelectAll">Выбрать все</button>
            <button type="button" class="secondary" id="pushFeedsReset">Снять выбор</button>
            <button type="submit">Обновить цены по выбранным фидам</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div>
  <script>
    (() => {
      const normalize = (value) => String(value || '')
        .toLocaleLowerCase('ru-RU')
        .replaceAll('ё', 'е')
        .replace(/\s+/g, ' ')
        .trim();
      const parseRuleTarget = (line) => {
        const match = String(line || '').trim().match(/^(.+?)\s*=/u);
        return match ? match[1].trim() : '';
      };
      const expressionLooksValid = (value) => {
        const text = String(value || '').trim().replace(',', '.');
        if (!/^[+-]?\d+(?:\.\d+)?\s*%?$/u.test(text)) {
          return false;
        }
        return !text.endsWith('%') || text.startsWith('+') || text.startsWith('-');
      };
      const readAltTargets = (option) => {
        try {
          const decoded = JSON.parse(option.getAttribute('data-force-alt-targets') || '[]');
          return Array.isArray(decoded) ? decoded.map((value) => String(value || '').trim()).filter(Boolean) : [];
        } catch (e) {
          return [];
        }
      };

      document.querySelectorAll('[data-force-builder]').forEach((builder) => {
        const textarea = document.getElementById(builder.getAttribute('data-force-textarea') || '');
        const options = Array.from(builder.querySelectorAll('[data-force-option]'));
        const search = builder.querySelector('[data-force-search]');
        const expression = builder.querySelector('[data-force-expression]');
        const addButton = builder.querySelector('[data-force-add]');
        const selectVisibleButton = builder.querySelector('[data-force-select-visible]');
        const clearButton = builder.querySelector('[data-force-clear]');
        const selectedCount = builder.querySelector('[data-force-selected-count]');
        const existingCount = builder.querySelector('[data-force-existing-count]');
        if (!textarea) return;

        const checkboxFor = (option) => option.querySelector('[data-force-check]');
        const checkedOptions = () => options.filter((option) => {
          const box = checkboxFor(option);
          return box && box.checked;
        });
        const ruleTargetSet = () => {
          const set = new Set();
          String(textarea.value || '').split(/\r?\n/u).forEach((line) => {
            const target = parseRuleTarget(line);
            if (target !== '') {
              set.add(normalize(target));
            }
          });
          return set;
        };
        const optionTargets = (option) => {
          const targets = [option.getAttribute('data-force-target') || '', ...readAltTargets(option)];
          return targets.map((value) => String(value || '').trim()).filter(Boolean);
        };
        const refresh = () => {
          const rules = ruleTargetSet();
          let hasRuleCount = 0;
          options.forEach((option) => {
            const box = checkboxFor(option);
            const isChecked = Boolean(box && box.checked);
            const hasRule = optionTargets(option).some((target) => rules.has(normalize(target)));
            option.classList.toggle('is-selected', isChecked);
            option.classList.toggle('has-rule', hasRule);
            if (hasRule) hasRuleCount++;
          });
          if (selectedCount) {
            selectedCount.textContent = `выбрано: ${checkedOptions().length}`;
          }
          if (existingCount) {
            existingCount.textContent = `в правилах: ${hasRuleCount}`;
          }
        };
        const applyFilter = () => {
          const query = normalize(search ? search.value : '');
          options.forEach((option) => {
            const text = normalize(option.getAttribute('data-force-search-text') || option.textContent || '');
            const filteredOut = query !== '' && !text.includes(query);
            option.hidden = filteredOut;
            option.classList.toggle('is-filtered-out', filteredOut);
          });
        };
        const upsertRules = (selected, ruleExpression) => {
          const rows = String(textarea.value || '').split(/\r?\n/u);
          const pending = selected.map((option) => ({
            target: String(option.getAttribute('data-force-target') || '').trim(),
            matchTargets: optionTargets(option).map(normalize),
          })).filter((row) => row.target !== '');
          const used = new Set();
          const nextRows = rows.map((line) => {
            const lineTarget = normalize(parseRuleTarget(line));
            if (lineTarget === '') {
              return line;
            }
            const idx = pending.findIndex((row, index) => !used.has(index) && row.matchTargets.includes(lineTarget));
            if (idx < 0) {
              return line;
            }
            used.add(idx);
            return `${pending[idx].target} = ${ruleExpression}`;
          });
          pending.forEach((row, index) => {
            if (!used.has(index)) {
              nextRows.push(`${row.target} = ${ruleExpression}`);
            }
          });
          textarea.value = nextRows.join('\n').replace(/^\n+/u, '').trimEnd();
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        };

        options.forEach((option) => {
          const box = checkboxFor(option);
          if (box) {
            box.addEventListener('change', refresh);
          }
        });
        if (search) {
          search.addEventListener('input', applyFilter);
        }
        if (textarea) {
          textarea.addEventListener('input', refresh);
        }
        if (selectVisibleButton) {
          selectVisibleButton.addEventListener('click', () => {
            options.filter((option) => !option.hidden).forEach((option) => {
              const box = checkboxFor(option);
              if (box) box.checked = true;
            });
            refresh();
          });
        }
        if (clearButton) {
          clearButton.addEventListener('click', () => {
            options.forEach((option) => {
              const box = checkboxFor(option);
              if (box) box.checked = false;
            });
            refresh();
          });
        }
        if (addButton) {
          addButton.addEventListener('click', () => {
            const selected = checkedOptions();
            const ruleExpression = String(expression ? expression.value : '').trim();
            if (!selected.length) {
              alert('Выбери хотя бы одну строку из списка.');
              return;
            }
            if (!expressionLooksValid(ruleExpression)) {
              alert('Укажи выражение в формате +7%, -100 или 9900.');
              return;
            }
            upsertRules(selected, ruleExpression);
            selected.forEach((option) => {
              const box = checkboxFor(option);
              if (box) box.checked = false;
            });
            refresh();
          });
        }
        refresh();
      });
    })();

    (() => {
      const form = document.getElementById('pushFeedsForm');
      if (!form) return;
      const hidden = document.getElementById('pushFeedsJson');
      const boxes = Array.from(form.querySelectorAll('[data-feed-push-checkbox]'));
      const sync = () => {
        hidden.value = JSON.stringify(boxes.filter((box) => box.checked).map((box) => box.value));
      };
      boxes.forEach((box) => box.addEventListener('change', sync));
      const selectAll = document.getElementById('pushFeedsSelectAll');
      if (selectAll) {
        selectAll.addEventListener('click', () => {
          boxes.forEach((box) => { box.checked = true; });
          sync();
        });
      }
      const reset = document.getElementById('pushFeedsReset');
      if (reset) {
        reset.addEventListener('click', () => {
          boxes.forEach((box) => { box.checked = false; });
          sync();
        });
      }
      form.addEventListener('submit', (event) => {
        sync();
        if (hidden.value === '[]') {
          event.preventDefault();
          alert('Выбери хотя бы один фид для обновления цен.');
        }
      });
      sync();
    })();
  </script>
</body>
</html>
