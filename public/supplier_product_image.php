<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
$baseDir = rtrim((string)($cfg['paths']['uploads_dir'] ?? (dirname(__DIR__) . '/storage/uploads')), '/\\') . '/supplier_product_images';
$file = str_replace('\\', '/', trim((string)($_GET['f'] ?? '')));

if ($file === '' || str_starts_with($file, '/') || str_contains($file, '../') || str_contains($file, '..\\')) {
    http_response_code(404);
    exit;
}

$path = $baseDir . '/' . $file;
$realBase = realpath($baseDir);
$realPath = is_file($path) ? realpath($path) : false;

if (!$realBase || !$realPath || !str_starts_with($realPath, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$mime = (string)(mime_content_type($realPath) ?: 'application/octet-stream');
$allowedVideo = in_array($mime, ['video/mp4', 'video/x-m4v'], true);
if (!str_starts_with($mime, 'image/') && !$allowedVideo) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
$size = (int)filesize($realPath);
if ($allowedVideo) {
    header('Accept-Ranges: bytes');
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if (preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        $start = $m[1] !== '' ? (int)$m[1] : 0;
        $end = $m[2] !== '' ? (int)$m[2] : ($size - 1);
        if ($m[1] === '' && $m[2] !== '') {
            $suffix = max(0, (int)$m[2]);
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        }
        $start = max(0, min($start, max(0, $size - 1)));
        $end = max($start, min($end, max(0, $size - 1)));
        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);
        header('Cache-Control: public, max-age=31536000, immutable');
        $fh = fopen($realPath, 'rb');
        if ($fh !== false) {
            fseek($fh, $start);
            $left = $length;
            while ($left > 0 && !feof($fh)) {
                $chunk = fread($fh, min(8192, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $left -= strlen($chunk);
            }
            fclose($fh);
        }
        exit;
    }
}
header('Content-Length: ' . (string)$size);
header('Cache-Control: public, max-age=31536000, immutable');
readfile($realPath);
