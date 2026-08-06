<?php
declare(strict_types=1);

/**
 * Rebuilds the static RU -> EN UI catalog from source literals.
 *
 * This is a development helper. The application never calls a translation
 * service at runtime and never sends supplier or product data anywhere.
 */

$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/public/*.php') ?: [],
    glob($root . '/public/taxonomy/*.php') ?: [],
    glob($root . '/app/*.php') ?: []
);

$phrases = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        continue;
    }

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $raw = $token[1];
        $quote = $raw[0] ?? '';
        $value = substr($raw, 1, -1);
        if ($quote === '"') {
            $value = stripcslashes($value);
        } else {
            $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }
        ft_i18n_add_phrase($phrases, $value);
    }

    preg_match_all('~>([^<>]*[А-Яа-яЁё][^<>]*)<~u', $source, $matches);
    foreach ($matches[1] ?? [] as $value) {
        $value = preg_replace('~<\?.*?\?>~s', '', (string)$value) ?? '';
        ft_i18n_add_phrase($phrases, $value);
    }

    preg_match_all('~(?:placeholder|title|aria-label|data-confirm)\s*=\s*(["\'])(.*?)\1~su', $source, $matches);
    foreach ($matches[2] ?? [] as $value) {
        ft_i18n_add_phrase($phrases, (string)$value);
    }
}

ksort($phrases, SORT_NATURAL | SORT_FLAG_CASE);
$sourcePhrases = array_keys($phrases);
$catalog = [];
$batches = array_chunk($sourcePhrases, 24);
$separator = "\n__FT_I18N_SEPARATOR__\n";

foreach ($batches as $batchIndex => $batch) {
    $translated = ft_i18n_translate_batch($batch, $separator);
    if (count($translated) !== count($batch)) {
        fwrite(STDERR, "\nRetrying batch " . ($batchIndex + 1) . " one phrase at a time.\n");
        $translated = [];
        foreach ($batch as $phrase) {
            $single = ft_i18n_translate_batch([$phrase], $separator);
            $translated[] = $single[0] ?? $phrase;
            usleep(120000);
        }
    }
    foreach ($batch as $index => $russian) {
        $english = trim((string)$translated[$index]);
        if ($english !== '' && $english !== $russian) {
            $catalog[$russian] = $english;
        }
    }
    fwrite(STDERR, sprintf("\rTranslated %d/%d", min(($batchIndex + 1) * 24, count($sourcePhrases)), count($sourcePhrases)));
    usleep(120000);
}

fwrite(STDERR, "\n");
echo "window.FeedToolsI18nCatalog = ";
echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
echo ";\n";

function ft_i18n_add_phrase(array &$phrases, string $value): void
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim((string)preg_replace('~\s+~u', ' ', $value));
    if ($value === '' || !preg_match('~[А-Яа-яЁё]~u', $value)) {
        return;
    }
    $length = mb_strlen($value, 'UTF-8');
    if ($length < 2 || $length > 420) {
        return;
    }
    if (preg_match('~(?:<\?|\?>|\$[a-z_]|=>|function\s*\(|preg_|SELECT\s|INSERT\s|UPDATE\s|DELETE\s|CREATE\s|\\[nrt]|\{\{|\}\})~iu', $value)) {
        return;
    }
    if (substr_count($value, '{') + substr_count($value, '}') > 2) {
        return;
    }
    $phrases[$value] = true;
}

function ft_i18n_translate_batch(array $phrases, string $separator): array
{
    $query = http_build_query([
        'client' => 'gtx',
        'sl' => 'ru',
        'tl' => 'en',
        'dt' => 't',
        'q' => implode($separator, $phrases),
    ]);
    $url = 'https://translate.googleapis.com/translate_a/single?' . $query;
    $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'FeedTools i18n catalog builder']]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || $response === '') {
        return [];
    }
    try {
        $json = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    $text = '';
    foreach (($json[0] ?? []) as $segment) {
        $text .= (string)($segment[0] ?? '');
    }
    return array_map('trim', explode(trim($separator), $text));
}
