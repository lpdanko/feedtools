<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/master_mobile_clean_images.php';

function mmci_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mmci_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action !== '') {
    try {
        if ($action === 'status') {
            mmci_json(['ok' => true, 'state' => mmci_state_summary(mmci_read_state($cfg))]);
        }
        if ($action === 'init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $mode = (string)($_POST['source_mode'] ?? 'feed');
            $url = $mode === 'sitemap'
                ? (string)($_POST['sitemap_url'] ?? MMCI_DEFAULT_SITEMAP_URL)
                : (string)($_POST['feed_url'] ?? MMCI_DEFAULT_FEED_URL);
            $limit = max(0, (int)($_POST['limit'] ?? 0));
            $verifyTls = !empty($_POST['verify_tls']);
            mmci_json(['ok' => true, 'state' => mmci_init_job($cfg, $mode, $url, $limit, $verifyTls)]);
        }
        if ($action === 'step') {
            $limit = max(1, min(50, (int)($_GET['limit'] ?? $_POST['limit'] ?? 5)));
            mmci_json(['ok' => true, 'state' => mmci_step($cfg, $limit)]);
        }
        if ($action === 'pause' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            mmci_json(['ok' => true, 'state' => mmci_set_status($cfg, 'paused')]);
        }
        if ($action === 'resume' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            mmci_json(['ok' => true, 'state' => mmci_set_status($cfg, 'running')]);
        }
        if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            mmci_json(['ok' => true, 'state' => mmci_retry_errors($cfg)]);
        }
        if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            mmci_json(['ok' => true, 'state' => mmci_reset($cfg)]);
        }
        if ($action === 'download_csv') {
            mmci_download_csv($cfg, false);
        }
        if ($action === 'download_found_csv') {
            mmci_download_csv($cfg, true);
        }
        if ($action === 'download_replacements_csv') {
            mmci_download_replacements_csv($cfg);
        }
        if ($action === 'download_replacements_json') {
            mmci_download_replacements_json($cfg);
        }
        mmci_json(['ok' => false, 'error' => 'Неизвестное действие.'], 400);
    } catch (Throwable $e) {
        mmci_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$state = mmci_state_summary(mmci_read_state($cfg));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Master Mobile — чистые первые фото</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      --ink: #172033;
      --muted: #667085;
      --line: #d9e2ee;
      --soft: #f6f8fb;
      --dark: #111827;
      --good: #047857;
      --warn: #b45309;
      --bad: #b42318;
      --blue: #1d4ed8;
    }
    * { box-sizing: border-box; }
    body {
      margin: 28px auto;
      max-width: 1180px;
      padding: 0 16px 48px;
      color: var(--ink);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: #fff;
    }
    h1 { margin: 0 0 8px; font-size: clamp(28px, 4vw, 42px); letter-spacing: 0; }
    h2 { margin: 0 0 12px; font-size: 20px; letter-spacing: 0; }
    h3 { margin: 0 0 8px; font-size: 16px; letter-spacing: 0; }
    a { color: var(--blue); }
    .muted { color: var(--muted); }
    .lead { max-width: 840px; margin: 0 0 22px; line-height: 1.5; color: var(--muted); }
    .panel {
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
      margin: 16px 0;
      background: #fff;
    }
    .split {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
      gap: 16px;
      align-items: start;
    }
    .source-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    label.option {
      display: block;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 12px;
      cursor: pointer;
      background: var(--soft);
    }
    label.option strong { display: block; margin-bottom: 4px; }
    label.option input { margin-right: 8px; }
    .field { margin-top: 12px; }
    .field label { display: block; font-weight: 800; margin-bottom: 6px; }
    input[type=url], input[type=number] {
      width: 100%;
      min-height: 42px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 8px 10px;
      font: inherit;
    }
    input[type=checkbox] { transform: translateY(1px); }
    .controls {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 14px;
    }
    button, .button-link {
      min-height: 42px;
      border: 1px solid var(--dark);
      border-radius: 8px;
      padding: 0 14px;
      background: var(--dark);
      color: #fff;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    button.secondary, .button-link.secondary {
      background: #fff;
      color: var(--dark);
      border-color: #cbd5e1;
    }
    button.warn {
      background: #fff7ed;
      color: #9a3412;
      border-color: #fed7aa;
    }
    button.danger {
      background: #fef3f2;
      color: var(--bad);
      border-color: #fecaca;
    }
    button:disabled {
      opacity: .58;
      cursor: not-allowed;
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
    }
    .stat {
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 12px;
      background: var(--soft);
    }
    .stat-value {
      font-size: 28px;
      font-weight: 900;
      line-height: 1.1;
    }
    .stat-label {
      color: var(--muted);
      font-size: 13px;
      margin-top: 4px;
    }
    .progress {
      height: 18px;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      overflow: hidden;
      background: #f1f5f9;
      margin: 12px 0 8px;
    }
    .progress > div {
      height: 100%;
      width: 0;
      background: #111827;
      transition: width .25s ease;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      background: #eef2ff;
      color: #3730a3;
    }
    .badge.good { background: #ecfdf3; color: var(--good); }
    .badge.warn { background: #fffbeb; color: var(--warn); }
    .badge.bad { background: #fef3f2; color: var(--bad); }
    .status-line {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 8px;
    }
    .log {
      max-height: 230px;
      overflow: auto;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #0f172a;
      color: #dbeafe;
      padding: 10px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 12px;
      line-height: 1.45;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    th, td {
      border-bottom: 1px solid #e5e7eb;
      padding: 10px 8px;
      vertical-align: top;
      text-align: left;
      font-size: 13px;
      overflow-wrap: anywhere;
    }
    th { color: #475467; background: #f8fafc; }
    .col-small { width: 92px; }
    .col-status { width: 100px; }
    .error { color: var(--bad); }
    @media (max-width: 880px) {
      .split, .source-grid { grid-template-columns: 1fr; }
      .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
      .stats { grid-template-columns: 1fr; }
      button, .button-link { width: 100%; }
    }
  </style>
</head>
<body>
  <?= ft_top_navigation(['back_href' => 'index.php', 'back_label' => 'Назад', 'active' => 'xml']) ?>

  <h1>Master Mobile: чистые первые фото</h1>
  <p class="lead">Инструмент проходит товары порциями, берет артикул, спрашивает live-search Master Mobile и сохраняет прямую ссылку на первый чистый файл из <code>/upload/iblock/</code>. Прогресс лежит на диске, а найденные ссылки отдельно выгружаются в формат замен для итогового XML-фида.</p>

  <div class="split">
    <section class="panel">
      <h2>Запуск</h2>
      <form id="initForm">
        <div class="source-grid">
          <label class="option">
            <input type="radio" name="source_mode" value="feed" checked>
            <strong>Быстро: из полного XML</strong>
            <span class="muted">URL и артикулы уже есть в фиде; дальше идут только запросы к поиску Master Mobile.</span>
          </label>
          <label class="option">
            <input type="radio" name="source_mode" value="sitemap">
            <strong>Медленно: напрямую sitemap</strong>
            <span class="muted">Берем URL товаров с сайта и открываем карточки, чтобы вытащить артикул.</span>
          </label>
        </div>

        <div class="field" data-source-field="feed">
          <label for="feedUrl">XML-фид Master Mobile</label>
          <input id="feedUrl" type="url" name="feed_url" value="<?= mmci_h(MMCI_DEFAULT_FEED_URL) ?>">
        </div>

        <div class="field" data-source-field="sitemap" hidden>
          <label for="sitemapUrl">Sitemap Master Mobile</label>
          <input id="sitemapUrl" type="url" name="sitemap_url" value="<?= mmci_h(MMCI_DEFAULT_SITEMAP_URL) ?>">
        </div>

        <div class="field">
          <label for="limit">Лимит товаров для теста</label>
          <input id="limit" type="number" name="limit" min="0" step="1" value="0">
          <div class="muted">0 значит без лимита. Для пробного запуска удобно поставить 20-50.</div>
        </div>

        <div class="field">
          <label><input type="checkbox" name="verify_tls" value="1"> Проверять TLS-сертификаты Master Mobile</label>
          <div class="muted">По умолчанию выключено, как в текущем скрипте Master Mobile, чтобы не спотыкаться о цепочку сертификатов на хостинге.</div>
        </div>

        <div class="controls">
          <button type="submit" id="initBtn">Создать задание заново</button>
          <button type="button" class="secondary" id="resumeBtn">Продолжить</button>
          <button type="button" class="warn" id="pauseBtn">Пауза</button>
          <button type="button" class="secondary" id="retryBtn">Повторить ошибки</button>
          <button type="button" class="danger" id="resetBtn">Сбросить</button>
        </div>
      </form>
    </section>

    <aside class="panel">
      <h2>Состояние</h2>
      <div class="status-line">
        <span class="badge" id="stateBadge">—</span>
        <span class="muted" id="heartbeatText">—</span>
      </div>
      <div class="progress"><div id="progressFill"></div></div>
      <div class="muted" id="progressText">—</div>
      <div class="controls">
        <label class="muted" for="batchSize">Порция</label>
        <input id="batchSize" type="number" min="1" max="50" value="8" style="width:90px">
        <a class="button-link secondary" href="?action=download_replacements_csv">CSV замен фото</a>
        <a class="button-link secondary" href="?action=download_found_csv">CSV найденных</a>
        <a class="button-link secondary" href="?action=download_csv">CSV всех</a>
        <a class="button-link secondary" href="?action=download_replacements_json">JSON замен</a>
      </div>
    </aside>
  </div>

  <section class="panel">
    <h2>Прогресс</h2>
    <div class="stats">
      <div class="stat"><div class="stat-value" id="totalStat">0</div><div class="stat-label">товаров всего</div></div>
      <div class="stat"><div class="stat-value" id="doneStat">0</div><div class="stat-label">обработано</div></div>
      <div class="stat"><div class="stat-value" id="foundStat">0</div><div class="stat-label">чистых ссылок найдено</div></div>
      <div class="stat"><div class="stat-value" id="pendingStat">0</div><div class="stat-label">в очереди</div></div>
      <div class="stat"><div class="stat-value" id="notFoundStat">0</div><div class="stat-label">не найдено</div></div>
      <div class="stat"><div class="stat-value" id="errorStat">0</div><div class="stat-label">ошибки</div></div>
    </div>
  </section>

  <section class="panel">
    <h2>Журнал</h2>
    <div class="log" id="logBox">—</div>
  </section>

  <section class="panel">
    <h2>Последние обработанные</h2>
    <table>
      <thead>
        <tr>
          <th class="col-small">ID</th>
          <th class="col-small">Артикул</th>
          <th>Товар</th>
          <th>Чистая ссылка</th>
          <th class="col-status">Статус</th>
        </tr>
      </thead>
      <tbody id="recentBody">
        <tr><td colspan="5" class="muted">Пока нет данных.</td></tr>
      </tbody>
    </table>
  </section>

  <script>
    const initForm = document.getElementById('initForm');
    const initBtn = document.getElementById('initBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const retryBtn = document.getElementById('retryBtn');
    const resetBtn = document.getElementById('resetBtn');
    const batchSize = document.getElementById('batchSize');
    let running = false;
    let stepping = false;

    function fmtInt(value) {
      return new Intl.NumberFormat('ru-RU').format(Number(value || 0));
    }

    function statusLabel(status) {
      const map = {
        empty: 'нет задания',
        ready: 'готово к запуску',
        running: 'идет работа',
        paused: 'пауза',
        done: 'завершено'
      };
      return map[status] || status || '—';
    }

    function terminalStatusLabel(status) {
      const map = {
        found: 'найдено',
        not_found: 'нет ссылки',
        no_vendor: 'нет артикула',
        error: 'ошибка',
        pending: 'очередь',
        processing: 'в работе'
      };
      return map[status] || status || '—';
    }

    function setBusy(isBusy) {
      initBtn.disabled = isBusy;
      retryBtn.disabled = isBusy;
      resetBtn.disabled = isBusy;
    }

    function render(state) {
      state = state || {};
      const counts = state.counts || {};
      const status = state.status || 'empty';
      const badge = document.getElementById('stateBadge');
      badge.textContent = statusLabel(status);
      badge.className = 'badge' + (status === 'done' ? ' good' : (status === 'paused' ? ' warn' : (status === 'empty' ? ' bad' : '')));

      const percent = Number(state.percent || 0);
      document.getElementById('progressFill').style.width = Math.max(0, Math.min(100, percent)) + '%';
      document.getElementById('progressText').textContent = `${percent.toFixed(1)}% · ${fmtInt(state.done)} из ${fmtInt(state.total)}`;
      document.getElementById('heartbeatText').textContent = state.heartbeat_at ? `последний шаг: ${state.heartbeat_at}` : 'шагов еще не было';

      document.getElementById('totalStat').textContent = fmtInt(state.total);
      document.getElementById('doneStat').textContent = fmtInt(state.done);
      document.getElementById('foundStat').textContent = fmtInt(counts.found);
      document.getElementById('pendingStat').textContent = fmtInt((counts.pending || 0) + (counts.processing || 0));
      document.getElementById('notFoundStat').textContent = fmtInt((counts.not_found || 0) + (counts.no_vendor || 0));
      document.getElementById('errorStat').textContent = fmtInt(counts.error);

      const log = (state.log || []).slice().reverse().map(row => {
        return `[${row.ts || ''}] ${row.message || ''}`;
      }).join('\n');
      document.getElementById('logBox').textContent = log || '—';

      const recent = state.recent || [];
      const body = document.getElementById('recentBody');
      body.innerHTML = '';
      if (!recent.length) {
        body.innerHTML = '<tr><td colspan="5" class="muted">Пока нет данных.</td></tr>';
      } else {
        for (const item of recent) {
          const tr = document.createElement('tr');
          const image = item.clean_image_url ? `<a href="${escapeAttr(item.clean_image_url)}" target="_blank" rel="noopener">открыть</a>` : `<span class="muted">${escapeHtml(item.error || '—')}</span>`;
          tr.innerHTML = `
            <td>${escapeHtml(item.site_id || '')}</td>
            <td>${escapeHtml(item.vendor_code || '')}</td>
            <td><a href="${escapeAttr(item.url || '#')}" target="_blank" rel="noopener">${escapeHtml(item.title || item.url || '')}</a></td>
            <td>${image}</td>
            <td>${escapeHtml(terminalStatusLabel(item.status || ''))}</td>
          `;
          body.appendChild(tr);
        }
      }

      if (status === 'done' || status === 'paused' || status === 'empty') {
        running = false;
      }
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
      }[ch]));
    }

    function escapeAttr(value) {
      return escapeHtml(value).replace(/`/g, '&#096;');
    }

    async function api(action, options = {}) {
      const method = options.method || 'GET';
      const params = options.params || {};
      const body = options.body || null;
      let url = `?action=${encodeURIComponent(action)}`;
      for (const [key, value] of Object.entries(params)) {
        url += `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
      }
      const response = await fetch(url, {
        method,
        body,
        headers: body instanceof FormData ? {} : {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}
      });
      const text = await response.text();
      let payload;
      try {
        payload = JSON.parse(text);
      } catch (err) {
        const preview = text.replace(/\s+/g, ' ').trim().slice(0, 260);
        throw new Error(`Сервер вернул не JSON (${response.status}). ${preview || 'Пустой ответ.'}`);
      }
      if (!payload.ok) {
        throw new Error(payload.error || 'Ошибка запроса');
      }
      render(payload.state);
      return payload.state;
    }

    async function refresh() {
      try {
        await api('status');
      } catch (err) {
        document.getElementById('logBox').textContent = err.message;
      }
    }

    async function loop() {
      if (!running || stepping) return;
      stepping = true;
      try {
        const state = await api('step', {params: {limit: Math.max(1, Math.min(50, Number(batchSize.value || 8)))}});
        if (state.status === 'done' || state.status === 'paused' || state.status === 'empty') {
          running = false;
        }
      } catch (err) {
        document.getElementById('logBox').textContent = `${err.message}\n\nПарсер не остановлен на сервере. Следующая попытка продолжится автоматически.`;
        await new Promise(resolve => setTimeout(resolve, 2500));
      } finally {
        stepping = false;
        if (running) {
          setTimeout(loop, 350);
        }
      }
    }

    document.querySelectorAll('input[name="source_mode"]').forEach(input => {
      input.addEventListener('change', () => {
        const mode = document.querySelector('input[name="source_mode"]:checked').value;
        document.querySelector('[data-source-field="feed"]').hidden = mode !== 'feed';
        document.querySelector('[data-source-field="sitemap"]').hidden = mode !== 'sitemap';
      });
    });

    initForm.addEventListener('submit', async event => {
      event.preventDefault();
      if (!confirm('Создать новое задание и заменить текущий прогресс?')) return;
      setBusy(true);
      try {
        const form = new FormData(initForm);
        form.append('action', 'init');
        await api('init', {method: 'POST', body: form});
      } catch (err) {
        alert(err.message);
      } finally {
        setBusy(false);
      }
    });

    resumeBtn.addEventListener('click', async () => {
      try {
        const body = new URLSearchParams({action: 'resume'});
        await api('resume', {method: 'POST', body});
        running = true;
        loop();
      } catch (err) {
        alert(err.message);
      }
    });

    pauseBtn.addEventListener('click', async () => {
      running = false;
      try {
        const body = new URLSearchParams({action: 'pause'});
        await api('pause', {method: 'POST', body});
      } catch (err) {
        alert(err.message);
      }
    });

    retryBtn.addEventListener('click', async () => {
      if (!confirm('Вернуть ошибки и ненайденные товары в очередь?')) return;
      try {
        const body = new URLSearchParams({action: 'retry_errors'});
        await api('retry_errors', {method: 'POST', body});
      } catch (err) {
        alert(err.message);
      }
    });

    resetBtn.addEventListener('click', async () => {
      if (!confirm('Сбросить состояние и CSV?')) return;
      running = false;
      try {
        const body = new URLSearchParams({action: 'reset'});
        await api('reset', {method: 'POST', body});
      } catch (err) {
        alert(err.message);
      }
    });

    render(<?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    setInterval(refresh, 5000);
  </script>
</body>
</html>
