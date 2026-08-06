<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/master_mobile_admin.php';
require_once __DIR__ . '/../app/navigation.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mm_status_class(string $status): string
{
    return preg_replace('~[^a-z0-9_-]+~i', '', $status) ?: 'unknown';
}

function mm_op_params(array $op): array
{
    $raw = (string)($op['params_json'] ?? '');
    $decoded = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($decoded) ? $decoded : [];
}

function mm_redirect(array $params = []): void
{
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: master_mobile_feed.php' . $query, true, 303);
    exit;
}

$settings = master_mobile_default_settings();
$flash = '';
$error = '';

try {
    master_mobile_automation_table_ensure();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $actor = function_exists('ft_current_user') ? ft_current_user() : ops_current_actor();
        $actor = is_string($actor) ? trim($actor) : null;

        if ($action === 'upload_pricelist') {
            master_mobile_save_uploaded_pricelist($_FILES['price_file'] ?? []);
            mm_redirect(['price_uploaded' => '1']);
        }

        if ($action === 'run_task') {
            $params = [
                'task_key' => (string)($_POST['task_key'] ?? 'parse_and_build'),
                'price_mode' => (string)($_POST['price_mode'] ?? 'pricelist'),
                'workers' => (string)($_POST['workers'] ?? $settings['workers']),
                'upload_feed' => !empty($_POST['upload_feed']) ? '1' : '0',
                'limit' => (string)($_POST['limit'] ?? '0'),
            ];

            $uploadError = (int)(($_FILES['price_file']['error'] ?? UPLOAD_ERR_NO_FILE));
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $params['purchase_prices_path'] = master_mobile_save_uploaded_pricelist($_FILES['price_file'] ?? []);
                $params['price_mode'] = 'pricelist';
            }

            $opId = master_mobile_enqueue_task($cfg, $params, $actor);
            mm_redirect(['queued_op' => (string)$opId]);
        }

        if ($action === 'save_automation') {
            $automationId = master_mobile_automation_save($_POST, $actor);
            mm_redirect(['automation_saved' => '1', 'automation_id' => (string)$automationId]);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (isset($_GET['price_uploaded'])) {
    $flash = 'Прайс сохранен. Теперь можно пересобрать фид с закупочными ценами из этого файла.';
}
if (isset($_GET['queued_op'])) {
    $flash = 'Задача поставлена в очередь: операция #' . (int)$_GET['queued_op'];
}
if (isset($_GET['automation_saved'])) {
    $flash = 'Автоматизация сохранена.';
}

$settings = master_mobile_default_settings();
$files = [
    'snapshot' => master_mobile_file_info((string)$settings['snapshot_path']),
    'feed' => master_mobile_file_info((string)$settings['feed_path']),
    'images' => master_mobile_file_info((string)$settings['image_replacements_path']),
    'prices' => master_mobile_file_info((string)$settings['purchase_prices_path']),
];
$automation = master_mobile_automation_get();
$activeOps = master_mobile_active_ops(5);
$recentOps = master_mobile_recent_ops(12);
$pollOpId = (int)($_GET['queued_op'] ?? 0);
if ($pollOpId <= 0 && $activeOps) {
    $pollOpId = (int)($activeOps[0]['id'] ?? 0);
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Master Mobile — парсинг и фид</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f7fb;
      --panel: #fff;
      --soft: #f8fafc;
      --border: #d9e4f2;
      --text: #142033;
      --muted: #66758a;
      --blue: #2563eb;
      --green: #137a4d;
      --red: #b42318;
      --amber: #a85d00;
      --shadow: 0 18px 42px rgba(19, 43, 75, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
      color: var(--text);
      font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    a { color: #1d4ed8; }
    .shell {
      width: min(1420px, calc(100% - 36px));
      margin: 0 auto;
      padding: 24px 0 44px;
    }
    .hero, .panel {
      border: 1px solid var(--border);
      border-radius: 18px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 18px;
      align-items: end;
      padding: 22px;
      margin-bottom: 16px;
    }
    h1, h2, h3, p { margin-top: 0; }
    h1 { margin-bottom: 8px; font-size: clamp(32px, 4vw, 48px); line-height: 1; letter-spacing: 0; }
    h2 { margin-bottom: 12px; font-size: 24px; }
    h3 { margin-bottom: 10px; font-size: 19px; line-height: 1.15; }
    .muted { color: var(--muted); }
    .hero p { margin-bottom: 0; max-width: 800px; }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }
    .btn, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 14px;
      border-radius: 12px;
      border: 1px solid #111827;
      background: #111827;
      color: #fff;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
    }
    .btn.secondary, button.secondary { background: #fff; color: var(--text); border-color: var(--border); }
    .grid { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 16px; align-items: start; }
    .stack { display: grid; gap: 16px; }
    .panel { padding: 18px; }
    .file-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .file-box {
      min-width: 0;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: var(--soft);
      padding: 12px;
    }
    .file-box strong { display: block; margin-bottom: 4px; }
    .file-box code, code {
      display: inline-block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      vertical-align: bottom;
      color: #334155;
      background: #eef4fb;
      border: 1px solid #dce8f5;
      border-radius: 8px;
      padding: 2px 6px;
      font-size: 12px;
    }
    .run-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .run-card {
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      padding: 14px;
    }
    label { display: grid; gap: 6px; font-weight: 800; color: #344054; }
    input[type="number"], input[type="file"], select {
      width: 100%;
      min-height: 40px;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 10px;
      background: #fff;
      color: var(--text);
      font: inherit;
      font-weight: 600;
    }
    .form-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 12px 0; }
    .check {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 800;
      color: var(--text);
      margin: 8px 0 12px;
    }
    .check input { width: 18px; height: 18px; }
    .flash, .error {
      margin: 0 0 16px;
      padding: 12px 14px;
      border-radius: 14px;
      font-weight: 800;
    }
    .flash { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
    .error { background: #fff1f2; color: var(--red); border: 1px solid #fecdd3; }
    .status {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 9px;
      border-radius: 999px;
      border: 1px solid #dbe4f0;
      background: #f8fafc;
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
    }
    .status.running { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
    .status.queued { background: #f5f3ff; border-color: #ddd6fe; color: #6d28d9; }
    .status.done { background: #ecfdf3; border-color: #bbf7d0; color: var(--green); }
    .status.error { background: #fff1f2; border-color: #fecaca; color: var(--red); }
    .status.cancelled { background: #fffbeb; border-color: #fde68a; color: var(--amber); }
    .progress {
      margin-top: 10px;
      height: 12px;
      overflow: hidden;
      border-radius: 999px;
      background: #e5e7eb;
    }
    .progress > span {
      display: block;
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #2563eb, #0f8b8d);
      transition: width .25s ease;
    }
    pre {
      max-height: 300px;
      overflow: auto;
      white-space: pre-wrap;
      word-break: break-word;
      margin: 10px 0 0;
      padding: 12px;
      border-radius: 12px;
      background: #0f172a;
      color: #d7e1ee;
      font-size: 12px;
      line-height: 1.45;
    }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 8px; border-bottom: 1px solid #e5edf6; text-align: left; vertical-align: top; }
    th { color: #526177; font-size: 13px; background: #f8fafc; }
    .automation-box {
      display: grid;
      gap: 12px;
      border: 1px solid #cfe0f4;
      border-radius: 14px;
      background: #f8fbff;
      padding: 14px;
    }
    .op-card {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      background: #fff;
    }
    @media (max-width: 1100px) {
      .grid, .hero { grid-template-columns: 1fr; }
      .actions { justify-content: flex-start; }
      .file-grid, .run-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 720px) {
      .shell { width: min(100% - 24px, 1420px); }
      .file-grid, .run-grid, .form-row { grid-template-columns: 1fr; }
      .btn, button { width: 100%; }
    }
  </style>
</head>
<body>
<div class="shell">
  <?= ft_top_navigation([
      'back_href' => 'index.php',
      'back_label' => 'Главная',
      'active' => 'supplier_feeds',
      'links' => ft_default_nav_links('supplier_feeds'),
  ]) ?>

  <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <section class="hero">
    <div>
      <h1>Master Mobile</h1>
      <p class="muted">Управление парсингом остатков, сборкой полного XML-фида, чистыми фото и закупочными ценами. Склады сейчас берутся с <?= h((string)$settings['store_name']) ?>.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="<?= h((string)$settings['public_feed_url']) ?>" target="_blank" rel="noopener">Открыть фид</a>
      <a class="btn secondary" href="queue_status.php">Очередь операций</a>
    </div>
  </section>

  <section class="panel" style="margin-bottom:16px;">
    <h2>Состояние файлов</h2>
    <div class="file-grid">
      <div class="file-box">
        <strong>Snapshot остатков</strong>
        <div class="muted"><?= h(master_mobile_format_dt($files['snapshot']['mtime'])) ?> · <?= h(master_mobile_format_bytes($files['snapshot']['bytes'])) ?></div>
        <code><?= h($files['snapshot']['path']) ?></code>
      </div>
      <div class="file-box">
        <strong>Итоговый XML</strong>
        <div class="muted"><?= h(master_mobile_format_dt($files['feed']['mtime'])) ?> · <?= h(master_mobile_format_bytes($files['feed']['bytes'])) ?></div>
        <code><?= h($files['feed']['path']) ?></code>
      </div>
      <div class="file-box">
        <strong>Чистые фото</strong>
        <div class="muted"><?= h(master_mobile_format_dt($files['images']['mtime'])) ?> · <?= h(master_mobile_format_bytes($files['images']['bytes'])) ?></div>
        <code><?= h($files['images']['path']) ?></code>
      </div>
      <div class="file-box">
        <strong>Прайс закупки</strong>
        <div class="muted"><?= h(master_mobile_format_dt($files['prices']['mtime'])) ?> · <?= h(master_mobile_format_bytes($files['prices']['bytes'])) ?></div>
        <code><?= h($files['prices']['path']) ?></code>
      </div>
    </div>
  </section>

  <div class="grid">
    <main class="stack">
      <section class="panel">
        <h2>Запуск вручную</h2>
        <div class="run-grid">
          <form class="run-card" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="run_task">
            <input type="hidden" name="task_key" value="parse_stock">
            <h3>Остатки и фид</h3>
            <p class="muted">Парсит остатки с сайта Master Mobile, затем пересобирает XML с текущими чистыми фото и ценами.</p>
            <div class="form-row">
              <label>Потоки
                <input type="number" name="workers" min="1" max="32" value="<?= h((string)$settings['workers']) ?>">
              </label>
              <label>Источник цен
                <select name="price_mode">
                  <?php foreach (master_mobile_price_mode_options() as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $key === 'pricelist' ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Лимит для теста
                <input type="number" name="limit" min="0" value="0">
              </label>
            </div>
            <label class="check"><input type="checkbox" name="upload_feed" value="1" checked> загрузить фид на FTP</label>
            <button type="submit">Запустить обновление</button>
          </form>

          <form class="run-card" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="run_task">
            <input type="hidden" name="task_key" value="build_feed">
            <h3>Источник и фид</h3>
            <p class="muted">Скачивает полный XML поставщика, применяет текущие остатки, чистые фото и выбранный источник цен.</p>
            <div class="form-row">
              <label>Источник цен
                <select name="price_mode">
                  <?php foreach (master_mobile_price_mode_options() as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $key === 'pricelist' ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Новый прайс XLSX/CSV
                <input type="file" name="price_file" accept=".xlsx,.csv">
              </label>
              <label>Лимит
                <input type="number" name="limit" min="0" value="0" disabled>
              </label>
            </div>
            <label class="check"><input type="checkbox" name="upload_feed" value="1" checked> загрузить фид на FTP</label>
            <button type="submit">Пересобрать фид</button>
          </form>
        </div>
      </section>

      <section class="panel">
        <h2>Обновить только прайс</h2>
        <form method="post" enctype="multipart/form-data" class="form-row" style="align-items:end;">
          <input type="hidden" name="action" value="upload_pricelist">
          <label>Файл XLSX или CSV
            <input type="file" name="price_file" accept=".xlsx,.csv" required>
          </label>
          <button type="submit" class="secondary">Сохранить прайс</button>
          <div class="muted">После сохранения можно пересобрать фид с режимом “Закупочные цены из прайса”.</div>
        </form>
      </section>

      <section class="panel">
        <h2>Последние операции</h2>
        <?php if (!$recentOps): ?>
          <p class="muted">Операций пока нет.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Задача</th>
                <th>Статус</th>
                <th>Создана</th>
                <th>Детали</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentOps as $op): ?>
                <?php $opParams = mm_op_params($op); ?>
                <tr>
                  <td><a href="op.php?id=<?= h((string)$op['id']) ?>">#<?= h((string)$op['id']) ?></a></td>
                  <td><?= h(master_mobile_task_label((string)($opParams['task_key'] ?? ''))) ?></td>
                  <td><span class="status <?= h(mm_status_class((string)$op['status'])) ?>"><?= h((string)$op['status']) ?></span></td>
                  <td><?= h((string)$op['created_at']) ?></td>
                  <td class="muted"><?= h((string)($op['progress_msg'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>
    </main>

    <aside class="stack">
      <section class="panel">
        <h2>Текущая работа</h2>
        <?php if ($pollOpId > 0): ?>
          <div class="op-card" id="liveOp" data-op-id="<?= h((string)$pollOpId) ?>">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
              <strong>Операция <a id="liveOpLink" href="op.php?id=<?= h((string)$pollOpId) ?>">#<?= h((string)$pollOpId) ?></a></strong>
              <span id="liveStatus" class="status">...</span>
            </div>
            <div class="progress"><span id="liveProgress"></span></div>
            <p id="liveMsg" class="muted" style="margin:10px 0 0;">Загружаем статус...</p>
            <pre id="liveLog"></pre>
          </div>
        <?php else: ?>
          <p class="muted">Сейчас нет активных задач Master Mobile.</p>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2>Автоматизация</h2>
        <form method="post" class="automation-box">
          <input type="hidden" name="action" value="save_automation">
          <label class="check" style="margin:0;"><input type="checkbox" name="enabled" value="1" <?= !empty($automation['enabled']) ? 'checked' : '' ?>> включить автоматическое обновление</label>
          <label>Что запускать
            <select name="task_key">
              <?php foreach (master_mobile_task_options() as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= (string)($automation['task_key'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Периодичность, минут
            <input type="number" name="frequency_minutes" min="15" max="10080" step="15" value="<?= h((string)($automation['frequency_minutes'] ?? 120)) ?>">
          </label>
          <label>Источник цен
            <select name="price_mode">
              <?php foreach (master_mobile_price_mode_options() as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= (string)($automation['price_mode'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Потоки парсинга
            <input type="number" name="workers" min="1" max="32" value="<?= h((string)($automation['workers'] ?? 12)) ?>">
          </label>
          <label class="check"><input type="checkbox" name="upload_feed" value="1" <?= !empty($automation['upload_feed']) ? 'checked' : '' ?>> загружать фид на FTP</label>
          <button type="submit">Сохранить автоматизацию</button>
          <div class="muted">
            Последний запуск:
            <?php if (!empty($automation['last_run_op_id'])): ?>
              <a href="op.php?id=<?= h((string)$automation['last_run_op_id']) ?>">#<?= h((string)$automation['last_run_op_id']) ?></a>,
            <?php endif; ?>
            <?= h((string)($automation['last_run_at'] ?? 'пока не было')) ?>
          </div>
        </form>
      </section>

      <section class="panel">
        <h2>Параметры Master Mobile</h2>
        <p class="muted">Источник: <a href="<?= h((string)$settings['source_url']) ?>" target="_blank" rel="noopener">полный XML поставщика</a></p>
        <p class="muted">Код поставщика: <code><?= h((string)$settings['supplier_code']) ?></code></p>
        <p class="muted">Склад остатков: <code><?= h((string)$settings['store_id']) ?></code> <?= h((string)$settings['store_name']) ?></p>
      </section>
    </aside>
  </div>
</div>

<?php if ($pollOpId > 0): ?>
<script>
(function() {
  var root = document.getElementById('liveOp');
  if (!root) return;
  var opId = root.getAttribute('data-op-id');
  var statusEl = document.getElementById('liveStatus');
  var progressEl = document.getElementById('liveProgress');
  var msgEl = document.getElementById('liveMsg');
  var logEl = document.getElementById('liveLog');
  function cls(status) {
    return 'status ' + String(status || '').replace(/[^a-z0-9_-]+/ig, '');
  }
  function poll() {
    fetch('op_poll.php?id=' + encodeURIComponent(opId), {cache: 'no-store'})
      .then(function(r) { return r.json(); })
      .then(function(data) {
        statusEl.textContent = data.status || '';
        statusEl.className = cls(data.status || '');
        var percent = data.percent == null ? 0 : Math.max(0, Math.min(100, Number(data.percent)));
        progressEl.style.width = percent + '%';
        var parts = [];
        if (data.stage) parts.push(data.stage);
        if (data.msg) parts.push(data.msg);
        if (data.total > 0) parts.push(String(data.done) + ' из ' + String(data.total));
        msgEl.textContent = parts.join(' · ') || 'Ожидаем обновления статуса';
        logEl.textContent = data.log_tail || '';
        if (['done', 'error', 'cancelled'].indexOf(data.status) === -1) {
          window.setTimeout(poll, 2500);
        }
      })
      .catch(function() { window.setTimeout(poll, 5000); });
  }
  poll();
})();
</script>
<?php endif; ?>
</body>
</html>
