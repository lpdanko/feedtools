<?php
declare(strict_types=1);

/**
 * Lightweight UI localization for the legacy FeedTools pages.
 *
 * Product content and supplier/marketplace data stay untouched: the browser
 * translator only replaces known UI phrases and deliberately avoids plain
 * table cells and data-bearing elements.
 */

function ft_i18n_cookie_name(): string
{
    return 'feedtools_lang';
}

function ft_i18n_locale(): string
{
    static $locale = null;
    if (is_string($locale)) {
        return $locale;
    }

    $requested = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($requested, ['ru', 'en'], true)) {
        $locale = $requested;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(ft_i18n_cookie_name(), $locale, [
                'expires' => time() + 60 * 60 * 24 * 365,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[ft_i18n_cookie_name()] = $locale;
        return $locale;
    }

    $saved = strtolower(trim((string)($_COOKIE[ft_i18n_cookie_name()] ?? 'ru')));
    $locale = in_array($saved, ['ru', 'en'], true) ? $saved : 'ru';
    return $locale;
}

function ft_i18n_language_url(string $locale): string
{
    $locale = $locale === 'en' ? 'en' : 'ru';
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? 'index.php');
    $parts = parse_url($requestUri);
    $path = (string)($parts['path'] ?? 'index.php');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $query['lang'] = $locale;
    return $path . '?' . http_build_query($query);
}

function ft_i18n_public_base_url(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $publicRoot = str_replace('\\', '/', dirname(__DIR__) . '/public');

    if ($scriptName !== '' && $scriptFile !== '' && str_starts_with($scriptFile, $publicRoot . '/')) {
        $relative = substr($scriptFile, strlen($publicRoot) + 1);
        if ($relative !== '' && str_ends_with($scriptName, $relative)) {
            $base = substr($scriptName, 0, -strlen($relative));
            return rtrim($base, '/');
        }
    }

    return '';
}

function ft_i18n_bootstrap(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;
    ft_i18n_locale();

    if (PHP_SAPI !== 'cli') {
        ob_start('ft_i18n_inject_ui');
    }
}

function ft_i18n_inject_ui(string $output): string
{
    if ($output === '' || stripos($output, '<html') === false || stripos($output, '</body>') === false) {
        return $output;
    }

    foreach (headers_list() as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') !== 0) {
            continue;
        }
        if (stripos($headerLine, 'text/html') === false && stripos($headerLine, 'application/xhtml+xml') === false) {
            return $output;
        }
    }

    $locale = ft_i18n_locale();
    $output = preg_replace(
        "~<html([^>]*?)\\blang=(['\"])[^'\"]*\\2([^>]*)>~i",
        '<html$1lang="' . $locale . '"$3 data-ft-lang="' . $locale . '">',
        $output,
        1
    ) ?? $output;

    if (stripos($output, 'data-ft-lang=') === false) {
        $output = preg_replace('~<html([^>]*)>~i', '<html$1 lang="' . $locale . '" data-ft-lang="' . $locale . '">', $output, 1) ?? $output;
    }

    $base = ft_i18n_public_base_url();
    $catalogUrl = $base . '/assets/i18n-catalog.js?v=20260805c';
    $assetUrl = $base . '/assets/i18n.js?v=20260805c';
    $ruUrl = ft_i18n_language_url('ru');
    $enUrl = ft_i18n_language_url('en');
    $head = ($locale === 'en'
            ? '<link rel="preload" href="' . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . '" as="script">'
            : '')
        . '<style>'
        . '.ft-language-switch{position:fixed;right:16px;bottom:16px;z-index:2147483000;display:inline-flex;gap:3px;padding:4px;border:1px solid #cbd5e1;border-radius:13px;background:rgba(255,255,255,.96);box-shadow:0 10px 30px rgba(15,23,42,.16);font:800 12px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
        . '.ft-language-switch a{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:32px;padding:0 9px;border-radius:9px;color:#475569;text-decoration:none}'
        . '.ft-language-switch a.is-active{background:#111827;color:#fff}'
        . '.ft-language-switch a:focus-visible{outline:3px solid #93c5fd;outline-offset:1px}'
        . '@media(max-width:760px){.ft-language-switch{right:10px;bottom:10px}}'
        . '</style>'
        . '<script>document.documentElement.dataset.ftLang=' . json_encode($locale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    $output = preg_replace('~</head>~i', $head . '</head>', $output, 1) ?? $output;

    $switcher = '<div class="ft-language-switch" role="group" aria-label="Language / Язык" data-ft-i18n="off">'
        . '<a href="' . htmlspecialchars($ruUrl, ENT_QUOTES, 'UTF-8') . '" lang="ru" hreflang="ru" class="' . ($locale === 'ru' ? 'is-active' : '') . '" aria-current="' . ($locale === 'ru' ? 'true' : 'false') . '">RU</a>'
        . '<a href="' . htmlspecialchars($enUrl, ENT_QUOTES, 'UTF-8') . '" lang="en" hreflang="en" class="' . ($locale === 'en' ? 'is-active' : '') . '" aria-current="' . ($locale === 'en' ? 'true' : 'false') . '">EN</a>'
        . '</div>';
    if ($locale === 'en') {
        $switcher .= '<script src="' . htmlspecialchars($catalogUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>'
            . '<script src="' . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }
    return preg_replace('~</body>~i', $switcher . '</body>', $output, 1) ?? $output;
}
