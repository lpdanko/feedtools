<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../ozon_price_tool.php';
require_once __DIR__ . '/../wb_promotions.php';

function op_wb_download_promotion_xlsx(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Missing vendor/autoload.php. Run: composer install');
    }
    require_once $autoload;

    $connectionId = (int)($params['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('Для скачивания XLS/XLSX автоакций WB нужно передать connection_id.');
    }
    $connection = ozon_price_connection_resolve($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Скачивание XLS/XLSX автоакций доступно только для подключения Wildberries.');
    }

    $daysAhead = max(0, min(365, (int)($params['days_ahead'] ?? 45)));
    $maxPromotions = max(1, min(200, (int)($params['max_promotions'] ?? 20)));
    $onlyAuto = !array_key_exists('only_auto', $params) || in_array(strtolower((string)$params['only_auto']), ['1', 'true', 'yes', 'on'], true);
    $importAfterDownload = !array_key_exists('import_after_download', $params) || in_array(strtolower((string)$params['import_after_download']), ['1', 'true', 'yes', 'on'], true);
    $inboxDir = trim((string)($params['inbox_dir'] ?? ''));
    $maxFilesImport = max(0, (int)($params['max_files_import'] ?? 0));

    $settings = wb_promotions_download_settings_get($connectionId, $cfg);
    $curlTemplate = trim((string)($settings['curl_template'] ?? ''));
    $generateCurlTemplate = trim((string)($settings['generate_curl_template'] ?? ''));
    $detailCurlTemplate = trim((string)($settings['detail_curl_template'] ?? ''));
    if ($curlTemplate === '') {
        throw new RuntimeException('Сначала сохрани cURL-шаблон скачивания XLS/XLSX из кабинета WB.');
    }

    $resolvedInbox = wb_promotions_resolve_import_dir($inboxDir, $connectionId);
    $log("wb_download_promotion_xlsx: connection_id={$connectionId}, days_ahead={$daysAhead}, max_promotions={$maxPromotions}\n");
    $log("inbox_dir={$resolvedInbox}\n");

    ops_update_progress($opId, 0, 1, 'select_promotions', 'Ищем активные автоакции WB для скачивания...');
    $promotions = wb_promotions_downloadable_promotions($connectionId, $cfg, $daysAhead, $maxPromotions, $onlyAuto);
    $log('promotions selected=' . count($promotions) . "\n");
    $needsExplicitPeriodId = preg_match('~\{(?:period_id|periodID|periodId)\}~', $generateCurlTemplate . "\n" . $curlTemplate) === 1;
    if ($needsExplicitPeriodId && $detailCurlTemplate !== '') {
        $log("period_id detail lookup enabled\n");
        $promotions = wb_promotions_enrich_promotions_with_period_ids(
            $connectionId,
            $promotions,
            $detailCurlTemplate,
            $cfg,
            [
                'timeout_sec' => max(10, min(120, (int)($params['detail_timeout_sec'] ?? 45))),
                'max_bytes' => max(10000, min(5000000, (int)($params['detail_max_bytes'] ?? 2000000))),
            ],
            $log
        );
    }
    if ($needsExplicitPeriodId) {
        $beforePeriodFilter = count($promotions);
        $promotions = array_values(array_filter($promotions, static function (array $promotion): bool {
            return wb_promotions_has_explicit_period_id($promotion);
        }));
        $skippedWithoutPeriod = $beforePeriodFilter - count($promotions);
        if ($skippedWithoutPeriod > 0) {
            $log("period_id filter: skipped_without_cabinet_period_id={$skippedWithoutPeriod}\n");
        }
        if ($beforePeriodFilter > 0 && !$promotions) {
            throw new RuntimeException(
                'Не удалось получить periodID ни для одной автоакции WB. '
                . 'Скорее всего, авторизация в сохранённых cURL-командах устарела. '
                . 'Обнови три cURL-команды в Price Tool этого WB-аккаунта.'
            );
        }
    }
    if (!$promotions) {
        ops_update_progress($opId, 1, 1, 'done', 'Нет активных автоакций WB для скачивания.');
        return [
            'summary_json_inline' => [
                'connection_id' => $connectionId,
                'download' => ['promotions_seen' => 0, 'downloaded' => 0, 'failed' => 0],
                'import' => null,
            ],
        ];
    }

    ops_update_progress($opId, 0, count($promotions), 'download', 'Скачиваем XLS/XLSX автоакций WB...');
    $downloadSummary = wb_promotions_download_xlsx_for_promotions(
        $connectionId,
        $promotions,
        $curlTemplate,
        $resolvedInbox,
        $cfg,
        [
            'timeout_sec' => max(10, min(300, (int)($params['timeout_sec'] ?? 120))),
            'max_bytes' => max(100000, min(200000000, (int)($params['max_bytes'] ?? 50000000))),
            'generate_curl_template' => $generateCurlTemplate,
            'generate_wait_ms' => max(0, min(30000, (int)($params['generate_wait_ms'] ?? 2500))),
            'download_attempts' => max(1, min(8, (int)($params['download_attempts'] ?? ($generateCurlTemplate !== '' ? 3 : 1)))),
            'download_retry_delay_ms' => max(250, min(30000, (int)($params['download_retry_delay_ms'] ?? 2500))),
        ],
        $log
    );
    ops_update_progress($opId, count($promotions), count($promotions), 'downloaded', 'Скачивание XLS/XLSX автоакций WB завершено.');
    if ((int)($downloadSummary['downloaded'] ?? 0) <= 0 && (int)($downloadSummary['failed'] ?? 0) > 0) {
        $messages = [];
        foreach ((array)($downloadSummary['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = trim((string)($item['message'] ?? ''));
            if ($message !== '') {
                $messages[$message] = true;
            }
        }
        $reason = $messages ? implode(' / ', array_slice(array_keys($messages), 0, 3)) : 'WB не вернул ни одного XLS/XLSX-файла.';
        throw new RuntimeException('Не удалось скачать ни одного XLS/XLSX автоакций WB. ' . $reason);
    }

    $importSummary = null;
    if ($importAfterDownload) {
        ops_update_progress($opId, 0, 1, 'import', 'Разбираем скачанные XLS/XLSX автоакций WB...');
        $importSummary = wb_promotions_import_xlsx_folder($connectionId, $resolvedInbox, $cfg, [
            'max_files' => $maxFilesImport,
            'min_age_sec' => 0,
            'move_processed' => true,
            'move_failed' => true,
        ]);
        $log(
            "import done: seen={$importSummary['files_seen']}, imported={$importSummary['imported']}, duplicate={$importSummary['skipped_duplicate']}, failed={$importSummary['failed']}, products={$importSummary['products_stored']}\n"
        );
    }

    ops_update_progress($opId, 1, 1, 'done', 'Скачивание и импорт XLS/XLSX автоакций WB завершены.');
    $summary = [
        'connection_id' => $connectionId,
        'download' => $downloadSummary,
        'import' => $importSummary,
    ];

    return [
        'summary_json_inline' => $summary,
        'outputs' => [
            'wb_promotion_xlsx_downloaded' => true,
            'inbox_dir' => $resolvedInbox,
        ],
    ];
}
