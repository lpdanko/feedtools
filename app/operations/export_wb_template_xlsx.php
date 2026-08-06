<?php

declare(strict_types=1);

require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../wildberries/WbTemplateExport.php';

function op_export_wb_template_xlsx(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$ds['id'];
    $templatePath = trim((string)($params['template_path'] ?? ''));
    if ($templatePath === '' || !is_file($templatePath)) {
        throw new RuntimeException('WB template file not found for export operation');
    }

    $offerIds = [];
    if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
        foreach ($params['offer_ids'] as $value) {
            $value = trim((string)$value);
            if ($value !== '') $offerIds[] = $value;
        }
    }

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);

    $log("Preparing WB XLSX export\n");
    $log("Template: {$templatePath}\n");
    $log("Offers selected: " . count($offerIds) . "\n");

    $result = wb_export_template_build(
        $cfg,
        $datasetId,
        $templatePath,
        $offerIds,
        [
            'work_dir' => $outDir,
            'progress' => static function (int $done, int $total, string $stage, string $message) use ($opId): void {
                ops_update_progress($opId, $done, $total, $stage, $message);
            },
            'cancel_check' => static function () use ($opId): bool {
                return ops_is_cancel_requested($opId);
            },
        ]
    );

    $finalAbs = $outDir . '/wb_template.xlsx';
    if (is_file($finalAbs)) {
        @unlink($finalAbs);
    }
    if (!rename((string)$result['result_path'], $finalAbs)) {
        if (!copy((string)$result['result_path'], $finalAbs)) {
            throw new RuntimeException('Failed to finalize WB XLSX export');
        }
        @unlink((string)$result['result_path']);
    }

    $summary = [
        'title' => 'WB XLSX export finished',
        'items' => [
            'Экспорт подготовлен в фоне и не нагружал web-интерфейс.',
            'Экспортировано строк: ' . (int)($result['rows_exported'] ?? 0),
            'Файл: ' . (string)($result['download_name'] ?? basename($finalAbs)),
        ],
        'metrics' => [
            'rows_exported' => (int)($result['rows_exported'] ?? 0),
            'download_name' => (string)($result['download_name'] ?? basename($finalAbs)),
        ],
    ];

    return [
        'wb_template_xlsx' => rel_to_outputs($cfg, $finalAbs),
        'summary_json_inline' => $summary,
    ];
}
