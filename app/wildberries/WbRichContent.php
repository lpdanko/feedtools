<?php
declare(strict_types=1);

require_once __DIR__ . '/../text_sanitize.php';

function wb_rich_content_description_limit(): int
{
    return 2000;
}

function wb_rich_content_normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', (string)$text);
    $text = preg_replace('/[ \t]+/u', ' ', (string)$text);
    $text = preg_replace('/\h*\n\h*/u', "\n", (string)$text);
    $text = preg_replace('/\n{3,}/u', "\n\n", (string)$text);

    $lines = preg_split('/\n/u', (string)$text) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            if ($out && end($out) !== '') {
                $out[] = '';
            }
            continue;
        }
        $line = preg_replace('/\s+([\.,;:!?])/u', '$1', $line);
        $out[] = trim((string)$line);
    }
    while ($out && $out[0] === '') {
        array_shift($out);
    }
    while ($out && end($out) === '') {
        array_pop($out);
    }
    return trim(implode("\n", $out));
}

function wb_rich_content_markup_to_text(string $source): string
{
    $source = trim((string)$source);
    if ($source === '') {
        return '';
    }

    if (strpos($source, '<![CDATA[') !== false) {
        $source = (string)preg_replace('~<!\[CDATA\[(.*?)\]\]>~su', '$1', $source);
    }
    $source = str_replace([']]>', '<![CDATA['], '', $source);
    $source = preg_replace('~<\s*script\b[^>]*>.*?<\s*/\s*script\s*>~isu', '', (string)$source);
    $source = preg_replace('~<\s*style\b[^>]*>.*?<\s*/\s*style\s*>~isu', '', (string)$source);

    $text = html_entity_decode((string)$source, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('~<\s*br\s*/?\s*>~iu', "\n", (string)$text);
    $text = preg_replace('~<\s*/\s*(p|div|li|h[1-6]|tr)\s*>~iu', "\n", (string)$text);
    $text = preg_replace('~<\s*/\s*(ul|ol|table)\s*>~iu', "\n", (string)$text);
    $text = strip_tags((string)$text);
    $text = ft_text_strip_links_preserve_markup((string)$text);
    $text = ft_text_strip_phones((string)$text);
    $text = ft_text_strip_contact_cta_lines((string)$text);
    $text = ft_text_strip_disclaimer((string)$text);
    $text = ft_text_strip_orphan_link_labels((string)$text);
    return wb_rich_content_normalize_text((string)$text);
}

function wb_rich_content_trim_description(string $text, int $limit = 0): string
{
    $limit = $limit > 0 ? $limit : wb_rich_content_description_limit();
    $text = wb_rich_content_normalize_text($text);
    if ($text === '' || $limit <= 0) {
        return '';
    }
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    $ellipsis = '…';
    $softLimit = max(1, $limit - mb_strlen($ellipsis, 'UTF-8'));
    $slice = trim((string)mb_substr($text, 0, $softLimit, 'UTF-8'));
    $floor = (int)floor($limit * 0.6);

    $best = '';
    foreach (["\n\n", "\n"] as $break) {
        $pos = mb_strrpos($slice, $break, 0, 'UTF-8');
        if ($pos !== false && $pos >= $floor) {
            $candidate = trim((string)mb_substr($slice, 0, $pos, 'UTF-8'));
            if ($candidate !== '') {
                $best = $candidate;
                break;
            }
        }
    }

    if ($best === '') {
        foreach (['/[.!?](?=\s|$)/u', '/[;:](?=\s|$)/u', '/,(?=\s|$)/u'] as $pattern) {
            if (preg_match_all($pattern, $slice, $matches, PREG_OFFSET_CAPTURE) && !empty($matches[0])) {
                $last = end($matches[0]);
                $candidate = trim((string)mb_substr($slice, 0, (int)$last[1] + 1, 'UTF-8'));
                if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') >= $floor) {
                    $best = $candidate;
                    break;
                }
            }
        }
    }

    if ($best === '') {
        $spacePos = mb_strrpos($slice, ' ', 0, 'UTF-8');
        if ($spacePos !== false && $spacePos >= $floor) {
            $best = trim((string)mb_substr($slice, 0, $spacePos, 'UTF-8'));
        }
    }
    if ($best === '') {
        $best = $slice;
    }

    $best = rtrim($best, " \t\n\r\0\x0B,;:-");
    if ($best === '') {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return wb_rich_content_normalize_text($best . $ellipsis);
}

function wb_rich_content_description(string $source, string $fallback = '', int $limit = 0): string
{
    $text = wb_rich_content_markup_to_text($source);
    if ($text === '') {
        $text = wb_rich_content_markup_to_text($fallback);
    }
    return wb_rich_content_trim_description($text, $limit > 0 ? $limit : wb_rich_content_description_limit());
}

function wb_rich_content_media_urls(array $urls, int $limit = 30): array
{
    $out = [];
    $seen = [];
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || trim((string)($parts['host'] ?? '')) === '') {
            continue;
        }
        $key = mb_strtolower($url, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $url;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
