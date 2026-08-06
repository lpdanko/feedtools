<?php
declare(strict_types=1);

function ft_nav_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ft_navigation_assets(): string
{
    return <<<'HTML'
  <style>
    .ft-nav-shell {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin: 0 0 16px;
    }
    .ft-back-link,
    .ft-nav-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      padding: 0 12px;
      border: 1px solid #d8e3f0;
      border-radius: 12px;
      background: #fff;
      color: #17233a;
      text-decoration: none;
      font-weight: 800;
      line-height: 1;
      box-shadow: 0 8px 18px rgba(27, 57, 90, .05);
      cursor: pointer;
    }
    .ft-back-link {
      border-color: #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
    }
    .ft-nav-links {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .ft-nav-right {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
      align-items: center;
    }
    .ft-operator-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      padding: 0 11px;
      border: 1px solid #d8e3f0;
      border-radius: 12px;
      background: #f8fbff;
      color: #64748b;
      text-decoration: none;
      font-weight: 800;
      line-height: 1;
      box-shadow: 0 8px 18px rgba(27, 57, 90, .04);
    }
    .ft-nav-link.is-active {
      border-color: #111827;
      background: #111827;
      color: #fff;
    }
    .ft-nav-link:hover,
    .ft-back-link:hover,
    .ft-operator-link:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 24px rgba(27, 57, 90, .09);
    }
    @media (max-width: 760px) {
      .ft-nav-shell {
        align-items: stretch;
      }
      .ft-back-link,
      .ft-operator-link,
      .ft-nav-link,
      .ft-nav-right,
      .ft-nav-links {
        width: 100%;
      }
      .ft-nav-links {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
  <script>
    (function() {
      if (window.__feedtoolsBackNavReady) return;
      window.__feedtoolsBackNavReady = true;
      document.addEventListener('click', function(event) {
        var link = event.target && event.target.closest ? event.target.closest('[data-ft-nav-history-back="1"]') : null;
        if (!link) return;
        var fallback = link.getAttribute('href') || link.getAttribute('data-fallback') || 'index.php';
        var ref = document.referrer || '';
        var sameOriginReferrer = false;
        try {
          sameOriginReferrer = ref !== '' && new URL(ref).origin === window.location.origin;
        } catch (e) {
          sameOriginReferrer = false;
        }
        if (sameOriginReferrer && window.history.length > 1) {
          event.preventDefault();
          window.history.back();
          return;
        }
        link.setAttribute('href', fallback);
      }, true);
    })();
  </script>
HTML;
}

function ft_default_nav_links(string $active = ''): array
{
    return [
        ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
        ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
        ['key' => 'content_progress', 'label' => 'Контент', 'href' => 'supplier_content_progress.php'],
        ['key' => 'content_monitoring', 'label' => 'Динамика', 'href' => 'supplier_content_progress_monitoring.php'],
        ['key' => 'marketplace_analytics', 'label' => 'Аналитика', 'href' => 'ozon_analytics.php'],
        ['key' => 'connections', 'label' => 'Подключения', 'href' => 'marketplace_connections.php'],
        ['key' => 'xml', 'label' => 'XML-фиды', 'href' => 'xml_feeds.php'],
        ['key' => 'supplier_feeds', 'label' => 'Парсинг фидов', 'href' => 'master_mobile_feed.php'],
    ];
}

function ft_top_navigation(array $options = []): string
{
    $backHref = (string)($options['back_href'] ?? 'index.php');
    $backLabel = trim((string)($options['back_label'] ?? 'Назад'));
    if ($backLabel === '') {
        $backLabel = 'Назад';
    }
    $active = (string)($options['active'] ?? '');
    $links = $options['links'] ?? ft_default_nav_links($active);
    if (!is_array($links)) {
        $links = ft_default_nav_links($active);
    }
    $useHistoryBack = !empty($options['history_back']);
    $backAttrs = ' data-fallback="' . ft_nav_h($backHref) . '"';
    if ($useHistoryBack) {
        $backAttrs .= ' data-ft-nav-history-back="1"';
    }

    $html = '<nav class="ft-nav-shell" aria-label="Навигация страницы">';
    $html .= '<a class="ft-back-link" href="' . ft_nav_h($backHref) . '"' . $backAttrs . '>← ' . ft_nav_h($backLabel) . '</a>';
    $html .= '<div class="ft-nav-right">';
    $html .= '<div class="ft-nav-links">';
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $label = trim((string)($link['label'] ?? ''));
        $href = trim((string)($link['href'] ?? ''));
        if ($label === '' || $href === '') {
            continue;
        }
        $key = (string)($link['key'] ?? '');
        $class = 'ft-nav-link' . ($key !== '' && $key === $active ? ' is-active' : '');
        $html .= '<a class="' . ft_nav_h($class) . '" href="' . ft_nav_h($href) . '">' . ft_nav_h($label) . '</a>';
    }
    $html .= '</div>';
    if (function_exists('ft_current_user')) {
        $actor = ft_current_user();
        $actorLabel = is_string($actor) && trim($actor) !== '' ? trim($actor) : 'указать';
        $returnUrl = (string)($_SERVER['REQUEST_URI'] ?? 'index.php');
        $html .= '<a class="ft-operator-link" href="operator.php?return_url=' . ft_nav_h(urlencode($returnUrl)) . '">Оператор: ' . ft_nav_h($actorLabel) . '</a>';
    }
    $html .= '</div></nav>';

    return $html;
}
