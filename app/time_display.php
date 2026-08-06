<?php
declare(strict_types=1);

function ft_time_display_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ft_time_display_html_attrs(array $attrs): string
{
    $parts = [];
    foreach ($attrs as $key => $value) {
        if ($value === null || $value === false) continue;
        if ($value === true) {
            $parts[] = ft_time_display_escape((string)$key);
            continue;
        }
        $parts[] = ft_time_display_escape((string)$key) . '="' . ft_time_display_escape((string)$value) . '"';
    }
    return $parts ? ' ' . implode(' ', $parts) : '';
}

function ft_time_to_utc_iso(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return str_replace(' ', 'T', $value) . 'Z';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . 'T00:00:00Z';
    }

    try {
        $hasExplicitZone = (bool)preg_match('/(?:Z|[+\-]\d{2}:\d{2}|[+\-]\d{4})$/i', $value);
        $tz = new DateTimeZone('UTC');
        $dt = $hasExplicitZone ? new DateTimeImmutable($value) : new DateTimeImmutable($value, $tz);
        return $dt->setTimezone($tz)->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return null;
    }
}

function ft_local_datetime_html(?string $value, array $options = []): string
{
    $value = trim((string)$value);
    $iso = ft_time_to_utc_iso($value);
    $empty = (string)($options['empty'] ?? '—');
    $showSeconds = !empty($options['show_seconds']);
    $fallback = $value !== '' ? $value . ' UTC' : $empty;

    $attrs = is_array($options['attrs'] ?? null) ? $options['attrs'] : [];
    $baseClass = trim('ft-local-datetime ' . (string)($options['class'] ?? '') . ' ' . (string)($attrs['class'] ?? ''));
    $attrs['class'] = trim($baseClass);
    $attrs['data-ft-local-datetime'] = '1';
    if ($showSeconds) {
        $attrs['data-show-seconds'] = '1';
    }

    if ($value !== '') {
        $attrs['data-utc-value'] = $value;
        $attrs['title'] = 'UTC: ' . $value;
    }
    if ($iso !== null) {
        $attrs['datetime'] = $iso;
    }

    return '<time' . ft_time_display_html_attrs($attrs) . '>' . ft_time_display_escape($fallback) . '</time>';
}

function ft_time_display_assets(): string
{
    static $emitted = false;
    if ($emitted) return '';
    $emitted = true;

    return <<<'HTML'
<style>
  .ft-local-datetime { white-space: nowrap; }
</style>
<script>
  (function () {
    if (window.__ftLocalTimeReady) return;
    window.__ftLocalTimeReady = true;

    function toIso(value) {
      if (value == null) return null;
      const text = String(value).trim();
      if (!text) return null;
      if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) return text.replace(' ', 'T') + 'Z';
      if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text + 'T00:00:00Z';
      return text;
    }

    function formatElement(el) {
      if (!el) return;
      const iso = el.getAttribute('datetime') || toIso(el.dataset.utcValue || '');
      if (!iso) return;

      const date = new Date(iso);
      if (Number.isNaN(date.getTime())) return;

      const withSeconds = el.dataset.showSeconds === '1';
      const formatted = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: withSeconds ? '2-digit' : undefined,
      }).format(date);

      el.textContent = formatted;

      const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
      const utcValue = (el.dataset.utcValue || '').trim();
      el.title = (browserTz ? browserTz + ' · ' : '') + formatted + (utcValue ? '\nUTC: ' + utcValue : '');
    }

    window.ftSetLocalDateTime = function (el, value, options) {
      if (!el) return;
      const opts = options || {};
      const text = value == null ? '' : String(value).trim();
      const iso = toIso(text);

      el.dataset.ftLocalDatetime = '1';
      if (opts.showSeconds) el.dataset.showSeconds = '1';
      else delete el.dataset.showSeconds;

      if (text) el.dataset.utcValue = text;
      else delete el.dataset.utcValue;

      if (iso) {
        el.setAttribute('datetime', iso);
        formatElement(el);
      } else {
        el.removeAttribute('datetime');
        el.textContent = opts.empty || '—';
        el.title = '';
      }
    };

    window.ftLocalizeDateTimes = function (root) {
      const scope = root || document;
      scope.querySelectorAll('[data-ft-local-datetime="1"]').forEach(formatElement);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        window.ftLocalizeDateTimes(document);
      });
    } else {
      window.ftLocalizeDateTimes(document);
    }
  })();
</script>
HTML;
}
