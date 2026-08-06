<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
ft_require_admin_user();

require_once __DIR__ . '/../app/employee_analytics.php';
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/navigation.php';

$period = employee_analytics_parse_period($_GET);
$report = employee_analytics_fetch($cfg, $period);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_int(int $value): string
{
    return number_format($value, 0, '.', ' ');
}

function fmt_money(float $value): string
{
    return '$' . number_format($value, 4, '.', ' ');
}

function fmt_duration_admin(int $sec): string
{
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
  <title>FeedTools — Аналитика сотрудников</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      max-width: 1320px;
      margin: 28px auto;
      padding: 0 16px 40px;
      color: #111827;
      background: #f8fafc;
    }
    .topbar, .filters, .card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 16px 40px rgba(15, 23, 42, 0.04);
    }
    .topbar {
      padding: 18px 20px;
      margin-bottom: 16px;
    }
    .filters {
      padding: 18px 20px;
      margin-bottom: 18px;
    }
    .filters form {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: end;
    }
    label {
      display: grid;
      gap: 6px;
      font-size: 13px;
      color: #475569;
    }
    select, input[type=date] {
      min-width: 160px;
      padding: 10px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      font: inherit;
      background: #fff;
      color: #111827;
    }
    button {
      padding: 11px 16px;
      border-radius: 10px;
      border: 1px solid #0f766e;
      background: #0f766e;
      color: #fff;
      font: inherit;
      cursor: pointer;
    }
    .muted {
      color: #64748b;
    }
    .overview {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .metric {
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 16px;
    }
    .metric .label {
      font-size: 12px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 8px;
    }
    .metric .value {
      font-size: 24px;
      font-weight: 700;
      color: #0f172a;
    }
    .layout {
      display: grid;
      gap: 18px;
    }
    .card {
      padding: 18px 20px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    th, td {
      padding: 10px 8px;
      border-bottom: 1px solid #e5e7eb;
      text-align: left;
      vertical-align: top;
    }
    th {
      color: #475569;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      background: #ecfeff;
      color: #0f766e;
      font-size: 12px;
      font-weight: 700;
    }
    .op-list {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      max-width: 360px;
    }
    .op-chip {
      display: inline-flex;
      align-items: center;
      padding: 4px 8px;
      border-radius: 999px;
      background: #f1f5f9;
      color: #334155;
      font-size: 12px;
    }
    a {
      color: #0f172a;
    }
    @media (max-width: 900px) {
      body {
        padding: 0 12px 32px;
      }
      .filters form {
        align-items: stretch;
      }
      label, select, input[type=date], button {
        width: 100%;
      }
      table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
      }
    }
  </style>
</head>
<body>
  <?= ft_top_navigation([
    'back_href' => 'index.php',
    'back_label' => 'Назад',
    'links' => [
      ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
      ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
      ['key' => 'connections', 'label' => 'Подключения', 'href' => 'marketplace_connections.php'],
      ['key' => 'gpt', 'label' => 'Журнал GPT', 'href' => 'gpt_log.php'],
    ],
  ]) ?>
  <div class="topbar">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
      <div>
        <h1 style="margin:0 0 8px;">Аналитика сотрудников</h1>
        <div class="muted">Панель видна только пользователю <b>admin</b>. Здесь собираются запуски, объём обработки и GPT-расходы по сотрудникам. Системные процессы вроде <code>cli</code> не считаются сотрудниками.</div>
      </div>
    </div>
  </div>

  <div class="filters">
    <form method="get">
      <label>
        Период
        <select name="preset" id="preset">
          <?php foreach (['today' => 'Сегодня', '7d' => 'Последние 7 дней', '30d' => 'Последние 30 дней', '90d' => 'Последние 90 дней', 'custom' => 'Свой период'] as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $period['preset'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        От
        <input type="date" name="from" value="<?= h($period['from_date']) ?>">
      </label>
      <label>
        До
        <input type="date" name="to" value="<?= h($period['to_date']) ?>">
      </label>
      <button type="submit">Показать</button>
      <div class="muted" style="align-self:center;">Сейчас считаем по времени запуска операций, а GPT-стоимость подтягиваем по связанным шагам и запросам.</div>
    </form>
    <?php if (!empty($report['system_summary']['launches'])): ?>
      <div class="muted" style="margin-top:10px;">
        Отдельно замечено системных запусков: <?= h(fmt_int((int)$report['system_summary']['launches'])) ?>,
        обработано товаров: <?= h(fmt_int((int)$report['system_summary']['processed_items'])) ?>,
        GPT-запросов: <?= h(fmt_int((int)$report['system_summary']['gpt_requests'])) ?>,
        стоимость: <?= h(fmt_money((float)$report['system_summary']['cost_usd'])) ?>.
      </div>
    <?php endif; ?>
  </div>

  <div class="overview">
    <div class="metric"><div class="label">Сотрудники</div><div class="value"><?= h(fmt_int((int)$report['overview']['employees_count'])) ?></div></div>
    <div class="metric"><div class="label">Запуски</div><div class="value"><?= h(fmt_int((int)$report['overview']['launches'])) ?></div></div>
    <div class="metric"><div class="label">Конвейеры</div><div class="value"><?= h(fmt_int((int)$report['overview']['pipelines'])) ?></div></div>
    <div class="metric"><div class="label">Товаров выбрано</div><div class="value"><?= h(fmt_int((int)$report['overview']['selected_items'])) ?></div></div>
    <div class="metric"><div class="label">Товаров обработано</div><div class="value"><?= h(fmt_int((int)$report['overview']['processed_items'])) ?></div></div>
    <div class="metric"><div class="label">GPT запросов</div><div class="value"><?= h(fmt_int((int)$report['overview']['gpt_requests'])) ?></div></div>
    <div class="metric"><div class="label">Billable input</div><div class="value"><?= h(fmt_int((int)$report['overview']['billable_input_tokens'])) ?></div></div>
    <div class="metric"><div class="label">Стоимость</div><div class="value"><?= h(fmt_money((float)$report['overview']['cost_usd'])) ?></div></div>
  </div>

  <div class="layout">
    <div class="card">
      <h2 style="margin-top:0;">По сотрудникам</h2>
      <?php if (!$report['employees']): ?>
        <p class="muted">За выбранный период запусков пока нет.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Сотрудник</th>
              <th>Запуски</th>
              <th>Конвейеры</th>
              <th>Датасеты</th>
              <th>Выбрано</th>
              <th>Обработано</th>
              <th>GPT</th>
              <th>Стоимость</th>
              <th>Cache savings</th>
              <th>Последняя активность</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['employees'] as $row): ?>
              <tr>
                <td><span class="pill"><?= h($row['actor_user']) ?></span></td>
                <td><?= h(fmt_int((int)$row['launches'])) ?></td>
                <td><?= h(fmt_int((int)$row['pipelines'])) ?></td>
                <td><?= h(fmt_int((int)$row['datasets_count'])) ?></td>
                <td><?= h(fmt_int((int)$row['selected_items'])) ?></td>
                <td><?= h(fmt_int((int)$row['processed_items'])) ?></td>
                <td>
                  <?= h(fmt_int((int)$row['gpt_requests'])) ?><br>
                  <span class="muted">in <?= h(fmt_int((int)$row['input_tokens'])) ?> / cached <?= h(fmt_int((int)$row['cached_input_tokens'])) ?></span>
                </td>
                <td><?= h(fmt_money((float)$row['cost_usd'])) ?></td>
                <td><?= h(fmt_money((float)$row['cache_savings_usd'])) ?></td>
                <td><?= ft_local_datetime_html((string)($row['last_activity_at'] ?? ''), ['show_seconds' => true]) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Какие верхнеуровневые операции запускали</h2>
      <p class="muted">Здесь считаются именно пользовательские запуски: конвейер, экспорт, ручная операция. Это удобно, чтобы понимать поведение сотрудников без двойного счёта внутренних шагов.</p>
      <?php if (!$report['launch_breakdown']): ?>
        <p class="muted">Пока нет данных.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Сотрудник</th>
              <th>Операция</th>
              <th>Запусков</th>
              <th>Выбрано</th>
              <th>Обработано</th>
              <th>GPT запросов</th>
              <th>Стоимость</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['launch_breakdown'] as $row): ?>
              <tr>
                <td><?= h($row['actor_user_display'] ?? $row['actor_user']) ?></td>
                <td><?= h($row['op_type']) ?></td>
                <td><?= h(fmt_int((int)$row['launches'])) ?></td>
                <td><?= h(fmt_int((int)$row['selected_items'])) ?></td>
                <td><?= h(fmt_int((int)$row['processed_items'])) ?></td>
                <td><?= h(fmt_int((int)$row['gpt_requests'])) ?></td>
                <td><?= h(fmt_money((float)$row['cost_usd'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Что реально выполнялось внутри</h2>
      <p class="muted">Эта таблица уже смотрит на конкретные шаги, включая операции внутри конвейеров. Здесь лучше видно, какие действия действительно расходуют токены и время.</p>
      <?php if (!$report['step_breakdown']): ?>
        <p class="muted">Пока нет данных.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Сотрудник</th>
              <th>Шаг</th>
              <th>Запусков</th>
              <th>Обработано</th>
              <th>GPT запросов</th>
              <th>Стоимость</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($report['step_breakdown'], 0, 80) as $row): ?>
              <tr>
                <td><?= h($row['actor_user']) ?></td>
                <td><?= h($row['op_type']) ?></td>
                <td><?= h(fmt_int((int)$row['runs'])) ?></td>
                <td><?= h(fmt_int((int)$row['processed_items'])) ?></td>
                <td><?= h(fmt_int((int)$row['gpt_requests'])) ?></td>
                <td><?= h(fmt_money((float)$row['cost_usd'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Последняя активность</h2>
      <?php if (!$report['recent_activity']): ?>
        <p class="muted">Пока нет запусков за этот период.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Когда</th>
              <th>Сотрудник</th>
              <th>Операция</th>
              <th>Датасет</th>
              <th>Товары</th>
              <th>Статус</th>
              <th>GPT</th>
              <th>Стоимость</th>
              <th>Длительность</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['recent_activity'] as $row): ?>
              <tr>
                <td><?= ft_local_datetime_html((string)($row['created_at'] ?? ''), ['show_seconds' => true]) ?></td>
                <td><?= h($row['actor_user']) ?></td>
                <td>
                  <a href="op.php?id=<?= h((string)$row['id']) ?>">#<?= h((string)$row['id']) ?></a>
                  · <?= h($row['op_type']) ?>
                  <?php if (!empty($row['step_ops'])): ?>
                    <div class="op-list" style="margin-top:6px;">
                      <?php foreach ($row['step_ops'] as $op): ?>
                        <span class="op-chip"><?= h($op) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="view.php?id=<?= h((string)$row['dataset_id']) ?>">#<?= h((string)$row['dataset_id']) ?></a><br>
                  <span class="muted"><?= h($row['original_filename'] ?: '—') ?></span>
                </td>
                <td><?= h(fmt_int((int)$row['processed_items'])) ?></td>
                <td><?= h($row['status']) ?></td>
                <td><?= h(fmt_int((int)$row['gpt_requests'])) ?></td>
                <td><?= h(fmt_money((float)$row['cost_usd'])) ?></td>
                <td><?= h(fmt_duration_admin((int)$row['duration_sec'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <script>
    const preset = document.getElementById('preset');
    const fromInput = document.querySelector('input[name="from"]');
    const toInput = document.querySelector('input[name="to"]');
    function syncDateState() {
      const custom = preset && preset.value === 'custom';
      if (fromInput) fromInput.disabled = !custom;
      if (toInput) toInput.disabled = !custom;
    }
    if (preset) {
      preset.addEventListener('change', syncDateState);
      syncDateState();
    }
  </script>
</body>
</html>
