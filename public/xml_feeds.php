<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/navigation.php';

$rows = [];
$activeOps = [];
$error = '';
$workerParallel = ops_worker_max_parallel();

try {
  supplier_products_tables_ensure($cfg);
  $stmt = db()->query("
    SELECT id, created_at, original_filename, bytes, offers_count
    FROM feedtools_datasets
    WHERE original_filename <> '[system] Global operations'
      AND id NOT IN (
        SELECT dataset_id
        FROM feedtools_supplier_product_meta
        WHERE dataset_id > 0
      )
    ORDER BY id DESC
    LIMIT 20
  ");
  $rows = $stmt->fetchAll();
  $activeOps = ops_list_active_global(20);
} catch (Throwable $e) {
  $error = $e->getMessage();
}

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmt_bytes($b)
{
  $b = (float)$b;
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $i = 0;
  while ($b >= 1024 && $i < count($units) - 1) {
    $b /= 1024;
    $i++;
  }
  return sprintf('%0.2f %s', $b, $units[$i]);
}
function fmt_duration_short($sec)
{
  $sec = (int)$sec;
  if ($sec <= 0) return '—';
  $h = intdiv($sec, 3600);
  $m = intdiv($sec % 3600, 60);
  $s = $sec % 60;
  if ($h > 0) return sprintf('%dh %02dm', $h, $m);
  if ($m > 0) return sprintf('%dm %02ds', $m, $s);
  return sprintf('%ds', $s);
}
?>
<!doctype html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <title>FeedTools — XML-фиды</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      max-width: 1100px;
      margin: 30px auto;
      padding: 0 16px;
    }

    .card {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 16px;
    }

    .row {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      align-items: center;
    }

    input[type=file],
    input[type=url] {
      padding: 8px;
    }

    input[type=url] {
      border: 1px solid #d1d5db;
      border-radius: 10px;
      min-width: min(100%, 520px);
      width: 100%;
      box-sizing: border-box;
      font: inherit;
    }

    .upload-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 12px;
    }

    .upload-panel {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 14px;
      background: #fafafa;
    }

    .upload-panel h3 {
      margin: 0 0 10px;
    }

    button {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid #111827;
      background: #111827;
      color: #fff;
      cursor: pointer;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      border-bottom: 1px solid #e5e7eb;
      padding: 10px;
      text-align: left;
      font-size: 14px;
    }

    .muted {
      color: #6b7280;
    }

    .err {
      color: #b91c1c;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 12px;
      font-weight: 600;
      background: #eff6ff;
      color: #1d4ed8;
    }

    .status-badge.is-queued {
      background: #f3f4f6;
      color: #374151;
    }

    a {
      color: #111827;
    }

    @media (max-width: 800px) {
      .upload-grid {
        grid-template-columns: 1fr;
      }
    }

    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 1000;
      display: inline-flex;
      align-items: center;
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid #f59e0b;
      background: rgba(255, 251, 235, 0.97);
      color: #92400e;
      box-shadow: 0 12px 28px rgba(146, 64, 14, .14);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
  </style>
</head>

<body>
  <?php if (ft_is_staging_env($cfg)): ?>
    <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
  <?php endif; ?>

  <?= ft_top_navigation(['back_href' => 'index.php', 'back_label' => 'Назад', 'active' => 'xml']) ?>

  <h1>XML-фиды</h1>

  <p class="muted">Отдельная служба старого XML-процесса: загрузка XML-фида, базовая проверка, статистика и экспорт шаблонов.</p>

  <div class="card">
    <h2>Активные операции</h2>
    <p class="muted">Сейчас worker может вести до <?= h((string)$workerParallel) ?> операций параллельно, но не более одной на датасет. Здесь видно, что уже выполняется и что ждёт запуска у всех пользователей.</p>

    <?php if (!$activeOps): ?>
      <p class="muted">Сейчас нет queued/running операций.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Статус</th>
            <th>Тип</th>
            <th>Датасет</th>
            <th>Файл</th>
            <th>Пользователь</th>
            <th>Прошло</th>
            <th>ETA</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
	            <?php foreach ($activeOps as $op): ?>
	            <?php
	              $status = (string)($op['status'] ?? '');
	              $badgeClass = $status === 'running' ? 'status-badge' : 'status-badge is-queued';
	              if (!empty($op['cancel_requested'])) {
	                $status .= ' · cancel';
	              }
	              $etaLabel = ((string)($op['status'] ?? '') === 'queued')
	                ? fmt_duration_short((int)($op['queue_wait_sec'] ?? 0))
	                : fmt_duration_short((int)($op['eta_sec'] ?? 0));
	              $currentStepLabel = trim((string)($op['active_child_op_type'] ?? ''));
	              $currentStepStage = trim((string)($op['active_child_stage'] ?? ''));
	              $currentStepMsg = trim((string)($op['active_child_msg'] ?? ''));
	            ?>
	            <tr>
	              <td><?= h($op['id']) ?></td>
	              <td><span class="<?= h($badgeClass) ?>"><?= h($status) ?></span></td>
	              <td>
	                <div><?= h($op['op_type']) ?></div>
	                <?php if ($currentStepLabel !== ''): ?>
	                  <div class="muted" style="font-size:12px; margin-top:4px;">
	                    Сейчас: <?= h($currentStepLabel) ?>
	                    <?php if ($currentStepStage !== ''): ?>
	                      · <?= h($currentStepStage) ?>
	                    <?php endif; ?>
	                    <?php if ($currentStepMsg !== ''): ?>
	                      <br><?= h($currentStepMsg) ?>
	                    <?php endif; ?>
	                  </div>
	                <?php endif; ?>
	              </td>
	              <td>#<?= h($op['dataset_id']) ?></td>
	              <td><?= h($op['original_filename'] ?: '—') ?></td>
	              <td><?= h($op['created_by'] ?: '—') ?></td>
	              <td><?= h(fmt_duration_short((int)($op['elapsed_sec'] ?? 0))) ?></td>
              <td><?= h($etaLabel) ?></td>
              <td><a href="op.php?id=<?= h($op['id']) ?>">Открыть</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Загрузка XML</h2>
    <p class="muted">Можно загрузить XML-файл с компьютера или импортировать XML по прямой http/https-ссылке.</p>

    <div class="upload-grid">
      <div class="upload-panel">
        <h3>Файл с компьютера</h3>
        <form class="row" action="upload.php" method="post" enctype="multipart/form-data">
          <input type="file" name="xmlfile" accept=".xml,application/xml,text/xml" required>
          <button type="submit">Загрузить файл</button>
        </form>
      </div>

      <div class="upload-panel">
        <h3>XML по ссылке</h3>
        <form action="upload.php" method="post">
          <div class="row" style="align-items:stretch;">
            <input type="url" name="xmlurl" placeholder="https://example.com/feed.xml" required>
            <button type="submit">Импортировать по ссылке</button>
          </div>
          <p class="muted" style="margin:8px 0 0;font-size:12px;">Поддерживаются прямые http/https-ссылки. Локальные и приватные адреса заблокированы.</p>
        </form>
      </div>
    </div>

    <p class="muted" style="margin-top:10px;">После импорта откроется страница с анализом: offers count, примеры, предупреждения.</p>
  </div>

  <div class="card" style="margin-top:16px;">
    <h3>Импорт XLSX шаблона категории Озон → новый датасет</h3>
    <form method="post" action="import_template_xlsx.php" enctype="multipart/form-data">
      <input type="file" name="xlsxfile" accept=".xlsx" required>
      <button type="submit">Загрузить XLSX</button>
      <div class="muted" style="margin-top:6px;font-size:12px;">
        Лист: «Шаблон». Заголовки: строка 2. Данные: с строки 5. Пустой «Артикул*» = конец.
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:16px;">
    <h3>Импорт XLSX шаблона категории WB → новый датасет</h3>
    <form method="post" action="import_wb_template_dataset.php" enctype="multipart/form-data">
      <input type="file" name="xlsxfile" accept=".xlsx" required>
      <button type="submit">Загрузить XLSX</button>
      <div class="muted" style="margin-top:6px;font-size:12px;">
        Лист: «Товары». Заголовки: строка с «Артикул продавца». Данные: через 2 строки после неё. Пустые строки пропускаются.
      </div>
    </form>
  </div>


  <div class="card">
    <h2>Последние загрузки</h2>
    <?php if ($error): ?>
      <p class="err">Ошибка БД: <?= h($error) ?></p>
      <p class="muted">Проверь настройки БД в <code>.env</code> или <code>app/config.local.php</code>.</p>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <p class="muted">Пока нет загрузок.</p>
    <?php else: ?>
      <form method="post" action="delete_bulk.php" onsubmit="return confirm('Удалить выбранные датасеты?');">
        <div class="row" style="justify-content:space-between; margin: 10px 0 14px;">
          <label class="muted" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="check_all" />
            Выбрать все
          </label>
          <button type="submit">Удалить выбранные</button>
        </div>

        <table>
          <thead>
            <tr>
              <th style="width:40px;"></th>
              <th>ID</th>
              <th>Дата загрузки</th>
              <th>Файл</th>
              <th>Размер</th>
              <th>Offers</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><input type="checkbox" class="ds_cb" name="ids[]" value="<?= h($r['id']) ?>" /></td>
                <td><?= h($r['id']) ?></td>
                <td><?= ft_local_datetime_html((string)($r['created_at'] ?? ''), ['show_seconds' => true]) ?></td>
                <td><?= h($r['original_filename']) ?></td>
                <td><?= h(fmt_bytes($r['bytes'])) ?></td>
                <td><?= h($r['offers_count']) ?></td>
                <td><a href="view.php?id=<?= h($r['id']) ?>">Открыть</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </form>

      <script>
        (function() {
          const all = document.getElementById('check_all');
          const cbs = Array.from(document.querySelectorAll('.ds_cb'));
          if (!all || !cbs.length) return;

          all.addEventListener('change', () => {
            for (const cb of cbs) cb.checked = all.checked;
          });

          for (const cb of cbs) {
            cb.addEventListener('change', () => {
              const checked = cbs.filter(x => x.checked).length;
              all.indeterminate = checked > 0 && checked < cbs.length;
              all.checked = checked === cbs.length;
            });
          }
        })();
      </script>
    <?php endif; ?>
  </div>

  <p class="muted">Дальше сюда добавим “операции”: fix ids, apply idmap, same_model, генерация и т.д.</p>

</body>

</html>
