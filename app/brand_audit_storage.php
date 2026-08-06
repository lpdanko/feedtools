<?php
declare(strict_types=1);

function brand_audit_reports_root(array $cfg): string
{
    $configured = trim((string)($cfg['paths']['reports_dir'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }
    return rtrim((string)($cfg['paths']['storage_dir'] ?? dirname(__DIR__) . '/storage'), '/\\') . '/reports';
}

function brand_audit_dataset_dir(array $cfg, int $datasetId): string
{
    if ($datasetId <= 0) {
        throw new InvalidArgumentException('Invalid brand audit dataset id.');
    }
    return brand_audit_reports_root($cfg) . '/brand_audits/dataset_' . $datasetId;
}

function brand_audit_report_dir(array $cfg, int $datasetId, int $opId): string
{
    if ($opId <= 0) {
        throw new InvalidArgumentException('Invalid brand audit operation id.');
    }
    return brand_audit_dataset_dir($cfg, $datasetId) . '/reports/op_' . $opId;
}

function brand_audit_logo_key(string $brand): string
{
    $normalized = mb_strtolower(trim($brand), 'UTF-8');
    $normalized = trim((string)preg_replace('~\s+~u', ' ', $normalized));
    return substr(hash('sha256', $normalized), 0, 24);
}

function brand_audit_logo_dir(array $cfg, int $datasetId, string $logoKey): string
{
    if (!preg_match('~^[a-f0-9]{24}$~D', $logoKey)) {
        throw new InvalidArgumentException('Invalid brand logo key.');
    }
    return brand_audit_dataset_dir($cfg, $datasetId) . '/logos/' . $logoKey;
}

function brand_audit_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать постоянное хранилище отчёта: ' . $dir);
    }
}

function brand_audit_atomic_write(string $path, string $bytes): void
{
    $dir = dirname($path);
    brand_audit_ensure_dir($dir);
    $tmp = tempnam($dir, '.tmp_');
    if (!is_string($tmp) || $tmp === '') {
        throw new RuntimeException('Не удалось создать временный файл для постоянного отчёта.');
    }
    try {
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать постоянный файл отчёта.');
        }
        @chmod($tmp, 0664);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Не удалось опубликовать постоянный файл отчёта.');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function brand_audit_write_json(string $path, array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Не удалось сериализовать данные постоянного отчёта.');
    }
    brand_audit_atomic_write($path, $json . "\n");
}

function brand_audit_persist_report(
    array $cfg,
    int $datasetId,
    int $opId,
    string $jsonPath,
    string $htmlPath,
    string $csvPath,
    array $summary = []
): array {
    $sources = [
        'json' => $jsonPath,
        'html' => $htmlPath,
        'csv' => $csvPath,
    ];
    foreach ($sources as $format => $source) {
        if (!is_file($source)) {
            throw new RuntimeException('Не найден файл ' . $format . ' для постоянного отчёта аудита брендов.');
        }
    }

    $dir = brand_audit_report_dir($cfg, $datasetId, $opId);
    brand_audit_ensure_dir($dir);
    foreach ($sources as $format => $source) {
        $bytes = file_get_contents($source);
        if (!is_string($bytes)) {
            throw new RuntimeException('Не удалось прочитать файл ' . $format . ' аудита брендов.');
        }
        brand_audit_atomic_write($dir . '/report.' . $format, $bytes);
    }

    $meta = [
        'dataset_id' => $datasetId,
        'op_id' => $opId,
        'created_at' => date('c'),
        'summary' => $summary,
    ];
    brand_audit_write_json($dir . '/meta.json', $meta);
    brand_audit_write_json(brand_audit_dataset_dir($cfg, $datasetId) . '/latest.json', $meta);
    return $meta;
}

function brand_audit_report_path(array $cfg, int $datasetId, int $opId, string $format): ?string
{
    $format = strtolower(trim($format));
    if (!in_array($format, ['html', 'json', 'csv'], true)) {
        return null;
    }
    $path = brand_audit_report_dir($cfg, $datasetId, $opId) . '/report.' . $format;
    return is_file($path) ? $path : null;
}

function brand_audit_latest_info(array $cfg, int $datasetId): ?array
{
    if ($datasetId <= 0) {
        return null;
    }
    $path = brand_audit_dataset_dir($cfg, $datasetId) . '/latest.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded) || (int)($decoded['dataset_id'] ?? 0) !== $datasetId || (int)($decoded['op_id'] ?? 0) <= 0) {
        return null;
    }
    $opId = (int)$decoded['op_id'];
    if (brand_audit_report_path($cfg, $datasetId, $opId, 'html') === null) {
        return null;
    }
    return $decoded;
}

function brand_audit_recover_latest_report(array $cfg, int $datasetId): ?array
{
    $existing = brand_audit_latest_info($cfg, $datasetId);
    if ($existing !== null || $datasetId <= 0 || !function_exists('db') || !function_exists('abs_from_outputs')) {
        return $existing;
    }

    $stmt = db()->prepare("\n        SELECT id, outputs_json, summary_json\n        FROM feedtools_operations\n        WHERE dataset_id = ?\n          AND op_type = 'analyze_brand_category_map'\n          AND status = 'done'\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $stmt->execute([$datasetId]);
    $op = $stmt->fetch();
    if (!$op) {
        return null;
    }
    $outputs = json_decode((string)($op['outputs_json'] ?? ''), true);
    if (!is_array($outputs)) {
        return null;
    }
    $map = [
        'report_json' => 'json',
        'report_html' => 'html',
        'brand_category_audit_csv' => 'csv',
    ];
    $paths = [];
    foreach ($map as $key => $format) {
        $rel = trim((string)($outputs[$key] ?? ''));
        if ($rel === '') {
            return null;
        }
        try {
            $candidate = abs_from_outputs($cfg, $rel);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_file($candidate)) {
            return null;
        }
        $paths[$format] = $candidate;
    }
    $summary = json_decode((string)($op['summary_json'] ?? ''), true);
    brand_audit_persist_report(
        $cfg,
        $datasetId,
        (int)$op['id'],
        $paths['json'],
        $paths['html'],
        $paths['csv'],
        is_array($summary) ? $summary : []
    );
    return brand_audit_latest_info($cfg, $datasetId);
}

function brand_audit_logo_state(array $cfg, int $datasetId, string $logoKey): ?array
{
    try {
        $path = brand_audit_logo_dir($cfg, $datasetId, $logoKey) . '/state.json';
    } catch (Throwable $e) {
        return null;
    }
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function brand_audit_write_logo_state(array $cfg, int $datasetId, string $brand, int $opId, array $extra = []): array
{
    $logoKey = brand_audit_logo_key($brand);
    $previous = brand_audit_logo_state($cfg, $datasetId, $logoKey) ?? [];
    $state = array_merge($previous, [
        'dataset_id' => $datasetId,
        'logo_key' => $logoKey,
        'brand' => $brand,
        'op_id' => $opId,
        'updated_at' => date('c'),
    ], $extra);
    brand_audit_write_json(brand_audit_logo_dir($cfg, $datasetId, $logoKey) . '/state.json', $state);
    return $state;
}

function brand_audit_persist_logo_files(
    array $cfg,
    int $datasetId,
    string $brand,
    int $opId,
    array $files,
    array $meta
): array {
    $logoKey = brand_audit_logo_key($brand);
    $dir = brand_audit_logo_dir($cfg, $datasetId, $logoKey);
    $names = [
        'master' => 'master.png',
        'ozon' => 'ozon_500x500.png',
        'wb' => 'wb_120x50.jpg',
    ];
    foreach ($names as $key => $name) {
        $bytes = (string)($files[$key] ?? '');
        if ($bytes === '') {
            throw new RuntimeException('Не хватает файла ' . $key . ' для постоянного хранения логотипа.');
        }
        brand_audit_atomic_write($dir . '/' . $name, $bytes);
    }
    brand_audit_write_json($dir . '/meta.json', $meta);
    return brand_audit_write_logo_state($cfg, $datasetId, $brand, $opId, [
        'status' => 'done',
        'files' => $names,
        'generated_at' => date('c'),
    ]);
}

function brand_audit_logo_file_path(array $cfg, int $datasetId, string $logoKey, string $fileKey): ?string
{
    $names = [
        'master' => 'master.png',
        'ozon' => 'ozon_500x500.png',
        'wb' => 'wb_120x50.jpg',
        'meta' => 'meta.json',
    ];
    if (!isset($names[$fileKey])) {
        return null;
    }
    try {
        $path = brand_audit_logo_dir($cfg, $datasetId, $logoKey) . '/' . $names[$fileKey];
    } catch (Throwable $e) {
        return null;
    }
    return is_file($path) ? $path : null;
}
