<?php
declare(strict_types=1);

function ft_text_sanitize_lc(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ft_text_html_decode(string $value): string
{
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ft_text_normalize_space(string $value): string
{
    $value = preg_replace('/[\t\r\f\v]+/u', ' ', $value);
    $value = preg_replace('/[ ]{2,}/u', ' ', (string)$value);
    $value = preg_replace('/\n{3,}/u', "\n\n", (string)$value);
    return trim((string)$value);
}

function ft_text_normalize_inline(string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string)$value);
    return trim((string)$value);
}

function ft_markup_to_plain_text(string $html): string
{
    $text = ft_text_html_decode($html);
    $text = preg_replace('~<\s*br\s*/?\s*>~iu', "\n", $text);
    $text = preg_replace('~</\s*(p|div|li|h[1-6]|tr)\s*>~iu', "\n", (string)$text);
    $text = preg_replace('~</\s*(ul|ol|table)\s*>~iu', "\n", (string)$text);
    $text = strip_tags((string)$text);
    return ft_text_normalize_space((string)$text);
}

function ft_looks_like_image_url(string $url): bool
{
    $url = trim((string)$url);
    if ($url === '') return false;
    $url = preg_replace('~[\)\]\}\>\"\'\,\.;!\?]+$~u', '', $url);
    $parts = parse_url((string)$url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return false;
    $path = ft_text_sanitize_lc((string)($parts['path'] ?? ''));
    return (bool)preg_match('~\.(jpe?g|png|gif|webp|bmp|tiff?|svg|avif)(?:$|\?)~i', $path);
}

function ft_extract_urls(string $text): array
{
    $source = ft_text_html_decode($text);
    $urls = [];

    if (preg_match_all('~\b(?:href|src)\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))~iu', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $url = trim((string)($match[1] ?? $match[2] ?? $match[3] ?? ''));
            if ($url !== '') $urls[] = $url;
        }
    }

    if (preg_match_all('~\b(?:https?|ftp)://[^\s<>"\']+~iu', $source, $matches)) {
        foreach ($matches[0] as $url) {
            $url = trim((string)$url);
            if ($url !== '') $urls[] = $url;
        }
    }

    $unique = [];
    $out = [];
    foreach ($urls as $url) {
        if (isset($unique[$url])) continue;
        $unique[$url] = true;
        $out[] = $url;
    }
    return $out;
}

function ft_text_strip_links_preserve_markup(string $text): string
{
    $text = ft_text_html_decode($text);

    $text = preg_replace('~\[(.*?)\]\(\s*(?:https?|ftp)://[^)]+\)~iu', '$1', $text);
    $text = preg_replace('~<\s*a\b[^>]*>~iu', '', (string)$text);
    $text = preg_replace('~<\s*/\s*a\s*>~iu', '', (string)$text);
    $text = preg_replace('~\b(?:href|src)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~iu', '', (string)$text);

    $patterns = [
        '~\b(?:https?|ftp)://[^\s<>"\']+~iu',
        '~\bwww\.[a-z0-9\-]+\.[a-z]{2,}(?:/[^\s<>"\']*)?~iu',
        '~\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)+\.(?:ru|com|net|org|info|io|me|site|shop|store|su|fi|eu|de|uk|cn|ua|kz|by)(?:/[^\s<>"\']*)?~iu',
        '~\b(?:t\.me|telegram\.me|wa\.me)/[^\s<>"\']+~iu',
    ];
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', (string)$text);
    }

    return (string)$text;
}

function ft_text_strip_phones(string $text): string
{
    $text = preg_replace('~\b(тел\.?|телефон|phone|whatsapp|вайбер|viber|telegram|телеграм|ватсап)\b\s*[:\-]?\s*\+?[0-9\(\)\-\s]{6,}~iu', '', $text);
    $text = preg_replace_callback('~(?:(?:\+\s*)?\d[\d\s\-\(\)]{6,}\d)~u', static function (array $match): string {
        $raw = (string)$match[0];
        $digits = preg_replace('~\D+~u', '', $raw);
        return strlen((string)$digits) >= 7 ? '' : $raw;
    }, (string)$text);
    return (string)$text;
}

function ft_text_strip_contact_cta_lines(string $text): string
{
    $parts = preg_split('/(\r\n|\r|\n)/u', $text);
    if (!is_array($parts)) return $text;

    $bad = '~\b(звон(ите|и|ить|ок)|позвон(ите|и|ить)|свяж(итесь|ись)|связаться|контакт(ы|ируйте)|наберите|напишите|пишите|перейдите|перейти|ссылка|по\s*ссылке|наш\s*магазин|магазином|менеджер|по\s*телефону|тел\.?\b|whatsapp|ватсап|viber|вайбер|telegram|телеграм)\b~iu';
    $out = [];

    foreach ($parts as $line) {
        $line = (string)$line;
        $fragments = preg_split('/(?<=[\.!\?;])\s+/u', $line);
        if (!is_array($fragments)) {
            if (!preg_match($bad, $line)) $out[] = $line;
            continue;
        }

        $kept = [];
        foreach ($fragments as $fragment) {
            $fragment = (string)$fragment;
            if (trim($fragment) === '') continue;
            if (preg_match($bad, $fragment)) continue;
            $kept[] = $fragment;
        }
        $out[] = implode(' ', $kept);
    }

    return implode("\n", $out);
}

function ft_text_strip_disclaimer(string $text): string
{
    return (string)preg_replace(
        '~Производитель\s+оставляет\s+за\s+собой\s+право\s+менять\s+технические\s+характеристики,\s+комплектацию\s+и\s+внешний\s+вид\s+продукции\s+без\s+уведомления\s+дилеров\.\s*Важные\s+для\s+вас\s+детали\s+рекомендуем\s+обсудить\s+с\s+менеджером\s+при\s+согласовании\s+заказа\.?~iu',
        '',
        $text
    );
}

function ft_text_strip_orphan_link_labels(string $text): string
{
    $patterns = [
        '~\b(?:файл|файлы|ссылка|ссылки|link|links|url|download|скачать|документ|документы|document|documents)\s*:\s*(?=(?:[A-ZА-ЯЁ][^:]{0,60}:)|$)~iu',
        '~\b(?:файл|файлы|ссылка|ссылки|link|links|url|download|скачать|документ|документы|document|documents)\s*:\s*(?=\p{L}[\p{L}\s\-]{0,40}:)~iu',
        '~\b(?:файл|файлы|ссылка|ссылки|link|links|url|download|скачать|документ|документы|document|documents)\s*:\s*$~iu',
    ];
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', (string)$text);
    }
    return (string)$text;
}

function ft_sanitize_markupish_text(string $raw): string
{
    $text = (string)$raw;
    if ($text === '') return '';

    $text = ft_text_strip_links_preserve_markup($text);
    $text = ft_text_strip_phones($text);
    $text = ft_text_strip_contact_cta_lines($text);
    $text = ft_text_strip_disclaimer($text);
    $text = ft_text_strip_orphan_link_labels($text);
    $text = preg_replace('~[\(\)\[\]<>]{2,}~u', ' ', (string)$text);
    $text = preg_replace('/\s+([\.,;:!?])/u', '$1', (string)$text);
    return trim((string)$text);
}

function ft_sanitize_plain_text(string $raw): string
{
    $sanitized = ft_sanitize_markupish_text($raw);
    if ($sanitized === '') return '';
    $plain = ft_markup_to_plain_text($sanitized);
    $plain = preg_replace(
        '~(?:^|\s)(?:файл|файлы|ссылка|ссылки|link|links|url|download|скачать|документ|документы|document|documents)\s*:\s*(?=\p{L}[\p{L}\s\-]{0,40}:)~iu',
        ' ',
        (string)$plain
    );
    $plain = preg_replace(
        '~(?:^|\s)(?:файл|файлы|ссылка|ссылки|link|links|url|download|скачать|документ|документы|document|documents)\s*:\s*$~iu',
        ' ',
        (string)$plain
    );
    $plain = preg_replace('/\s+([\.,;:!?])/u', '$1', (string)$plain);
    return ft_text_normalize_space((string)$plain);
}

function ft_text_contains_link(string $text): bool
{
    $decoded = ft_text_html_decode($text);
    return (bool)preg_match(
        '~(?:\b(?:https?|ftp)://[^\s<>"\']+|\bwww\.[a-z0-9\-]+\.[a-z]{2,}(?:/[^\s<>"\']*)?|\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)+\.(?:ru|com|net|org|info|io|me|site|shop|store|su|fi|eu|de|uk|cn|ua|kz|by)(?:/[^\s<>"\']*)?)~iu',
        $decoded
    );
}

function ft_sanitize_ozon_rich_json(string $json): string
{
    $json = trim((string)$json);
    if ($json === '') return '';

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return '';

    $sanitizeNode = static function ($value, ?string $key = null) use (&$sanitizeNode) {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            $keyNorm = $key !== null ? ft_text_sanitize_lc($key) : '';

            if ($keyNorm === 'content' && $isList) {
                $items = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $clean = ft_sanitize_plain_text($item);
                        if ($clean !== '') $items[] = $clean;
                        continue;
                    }
                    $child = $sanitizeNode($item, null);
                    if ($child === null) continue;
                    if (is_array($child) && $child === []) continue;
                    if ($child === '') continue;
                    $items[] = $child;
                }
                return $items;
            }

            $out = [];
            foreach ($value as $childKey => $childValue) {
                $child = $sanitizeNode($childValue, is_string($childKey) ? $childKey : null);
                if ($child === null) continue;
                if (is_array($child) && $child === []) {
                    $childKeyNorm = is_string($childKey) ? ft_text_sanitize_lc($childKey) : '';
                    if (in_array($childKeyNorm, ['text', 'title', 'subtitle', 'description', 'caption'], true)) {
                        continue;
                    }
                }
                if ($child === '' && is_string($childKey)) {
                    $childKeyNorm = ft_text_sanitize_lc($childKey);
                    if (in_array($childKeyNorm, ['alt', 'label', 'caption', 'description', 'subtitle', 'title'], true)) {
                        continue;
                    }
                }
                $out[$childKey] = $child;
            }

            if (isset($out['content']) && is_array($out['content']) && $out['content'] === []) {
                unset($out['content']);
            }

            if (($keyNorm === 'text' || $keyNorm === 'title' || $keyNorm === 'subtitle' || $keyNorm === 'description' || $keyNorm === 'caption')
                && !isset($out['content'])
            ) {
                return [];
            }

            return $out;
        }

        if (is_string($value)) {
            $keyNorm = $key !== null ? ft_text_sanitize_lc($key) : '';
            if (in_array($keyNorm, ['src', 'srcmobile', 'widgetname', 'type', 'theme', 'size', 'align', 'color', 'gapsize'], true)) {
                return $value;
            }
            if (in_array($keyNorm, ['alt', 'label', 'caption', 'description', 'subtitle', 'title'], true)) {
                return ft_sanitize_plain_text($value);
            }
        }

        return $value;
    };

    $clean = $sanitizeNode($decoded, null);
    if (!is_array($clean) || $clean === []) return '';

    $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '';
}
