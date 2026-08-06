<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/supplier_content_progress.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/time_display.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scpm_fmt_int($value): string
{
    return number_format((int)round((float)$value), 0, '.', ' ');
}

function scpm_fmt_score($value): string
{
    return number_format((float)$value, 1, '.', ' ');
}

function scpm_delta($value, string $suffix = ''): string
{
    if ($value === null || $value === '') return '—';
    $num = (float)$value;
    if (abs($num) < 0.01) return '0' . $suffix;
    $text = floor($num) == $num ? (string)(int)$num : number_format($num, 1, '.', ' ');
    return ($num > 0 ? '+' : '') . $text . $suffix;
}

function scpm_delta_class($value, bool $inverse = false): string
{
    $num = (float)$value;
    if (abs($num) < 0.01) return 'flat';
    $good = $inverse ? $num < 0 : $num > 0;
    return $good ? 'up' : 'down';
}

function scpm_actor_kind_label(string $kind): string
{
    return [
        'human' => 'пользователь',
        'automation' => 'автоматизация',
        'system' => 'система',
        'unknown' => 'не указан',
    ][$kind] ?? $kind;
}

$error = '';
$report = [
    'period' => supplier_content_progress_parse_period($_GET),
    'overview' => [],
    'rows' => [],
    'contributions' => ['by_actor' => [], 'events' => []],
];

try {
    $report = supplier_content_progress_fetch_monitoring($cfg, [
        'preset' => $_GET['preset'] ?? '7d',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'refresh' => isset($_GET['refresh']) && (string)$_GET['refresh'] === '1',
        'include_inactive' => isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1',
    ]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$period = (array)($report['period'] ?? supplier_content_progress_parse_period($_GET));
$overview = (array)($report['overview'] ?? []);
$rows = (array)($report['rows'] ?? []);
$contributions = (array)($report['contributions'] ?? []);
$actors = (array)($contributions['by_actor'] ?? []);
$events = (array)($contributions['events'] ?? []);
$errorDelta = (int)($overview['error_delta'] ?? 0) + (int)($overview['revision_delta'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — Динамика контента</title>
  <?= ft_navigation_assets() ?>
  <?= ft_time_display_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f7fb;
      --panel: #ffffff;
      --ink: #172033;
      --muted: #64748b;
      --line: #d9e5f2;
      --soft: #f8fbff;
      --blue: #2563eb;
      --green: #12805c;
      --yellow: #b7791f;
      --red: #b42318;
      --shadow: 0 18px 44px rgba(25, 54, 90, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
      color: var(--ink);
      font: 15px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .shell {
      max-width: 1500px;
      margin: 0 auto;
      padding: 24px 18px 44px;
    }
    .head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }
    h1 {
      margin: 0;
      font-size: clamp(28px, 4vw, 42px);
      line-height: 1;
      letter-spacing: 0;
    }
    .lead {
      max-width: 900px;
      margin: 9px 0 0;
      color: var(--muted);
      font-size: 16px;
    }
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .btn {
      min-height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 13px;
      border-radius: 10px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 900;
    }
    .btn.secondary {
      background: #fff;
      color: var(--ink);
      border-color: var(--line);
    }
    .filters {
      margin: 18px 0;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(255,255,255,.86);
      box-shadow: 0 10px 24px rgba(25, 54, 90, .05);
    }
    .filters form {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: end;
    }
    label {
      display: grid;
      gap: 5px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    select, input[type=date] {
      min-height: 40px;
      min-width: 150px;
      border: 1px solid #c9d7e8;
      border-radius: 10px;
      padding: 0 10px;
      color: var(--ink);
      background: #fff;
      font: inherit;
      text-transform: none;
      letter-spacing: 0;
    }
    button {
      min-height: 40px;
      border: 1px solid var(--blue);
      border-radius: 10px;
      padding: 0 14px;
      background: var(--blue);
      color: #fff;
      font: inherit;
      font-weight: 900;
      cursor: pointer;
    }
    .overview {
      display: grid;
      grid-template-columns: repeat(8, minmax(130px, 1fr));
      gap: 10px;
      margin: 18px 0;
    }
    .metric {
      min-height: 106px;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: var(--panel);
      box-shadow: var(--shadow);
      display: grid;
      align-content: space-between;
      gap: 8px;
    }
    .metric .k {
      color: var(--muted);
      font-size: 11px;
      font-weight: 950;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .metric .v {
      font-size: 26px;
      line-height: 1;
      font-weight: 950;
    }
    .panel {
      border: 1px solid var(--line);
      border-radius: 16px;
      background: var(--panel);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-top: 16px;
    }
    .panel-head {
      padding: 16px 18px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .panel-head h2 {
      margin: 0;
      font-size: 18px;
    }
    .muted { color: var(--muted); }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    th, td {
      padding: 12px 10px;
      border-bottom: 1px solid #e7edf5;
      text-align: left;
      vertical-align: top;
    }
    th {
      color: var(--muted);
      font-size: 11px;
      font-weight: 950;
      text-transform: uppercase;
      letter-spacing: .05em;
      background: #fbfdff;
    }
    tr:last-child td { border-bottom: 0; }
    .supplier-name, .op-link {
      color: var(--ink);
      font-weight: 950;
      text-decoration: none;
    }
    .supplier-name:hover, .op-link:hover { color: var(--blue); }
    .sub {
      margin-top: 3px;
      color: var(--muted);
      font-size: 12px;
    }
    .delta {
      display: inline-flex;
      min-width: 62px;
      justify-content: center;
      border-radius: 999px;
      padding: 4px 8px;
      font-weight: 950;
      font-size: 12px;
      background: #eef2f7;
      color: var(--muted);
    }
    .delta.up { background: #e7f8f0; color: var(--green); }
    .delta.down { background: #fff0ee; color: var(--red); }
    .delta.flat { background: #eef2f7; color: var(--muted); }
    .pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 4px 8px;
      background: #eef4ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }
    .pill.green { background: #e7f8f0; color: var(--green); }
    .pill.gray { background: #eef2f7; color: var(--muted); }
    .pill.red { background: #fff0ee; color: var(--red); }
    .score-line {
      display: flex;
      gap: 5px;
      align-items: baseline;
      font-weight: 950;
      font-size: 20px;
    }
    .bar {
      width: 150px;
      max-width: 100%;
      height: 8px;
      border-radius: 999px;
      background: #e8eef7;
      overflow: hidden;
      margin-top: 6px;
    }
    .bar span {
      display: block;
      height: 100%;
      width: var(--w);
      background: var(--blue);
      border-radius: inherit;
    }
    .bar.good span { background: var(--green); }
    .bar.bad span { background: var(--red); }
    .right { text-align: right; }
    .error {
      padding: 12px 14px;
      border: 1px solid #ffd0cc;
      border-radius: 12px;
      background: #fff4f2;
      color: var(--red);
      font-weight: 800;
      margin: 14px 0;
    }
    @media (max-width: 1180px) {
      .overview { grid-template-columns: repeat(4, minmax(150px, 1fr)); }
      .panel { overflow-x: auto; }
      table { min-width: 980px; }
    }
    @media (max-width: 720px) {
      .overview { grid-template-columns: 1fr; }
      label, select, input[type=date], button { width: 100%; }
      .head { display: grid; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <?= ft_top_navigation([
      'back_href' => 'supplier_content_progress.php',
      'back_label' => 'К прогрессу',
      'active' => 'content_monitoring',
      'links' => ft_default_nav_links('content_monitoring'),
    ]) ?>

    <section class="head">
      <div>
        <h1>Динамика контента</h1>
        <p class="lead">Сравнение состояния контента между периодами: загрузка, готовность, продаваемость, ошибки и вклад пользователей по завершенным контентным операциям.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="supplier_content_progress_monitoring.php?<?= h(http_build_query(array_merge($_GET, ['refresh' => 1]))) ?>">Обновить снимки</a>
        <a class="btn" href="supplier_content_progress.php">Дашборд</a>
      </div>
    </section>

    <section class="filters">
      <form method="get">
        <label>
          Период
          <select name="preset">
            <?php foreach (['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', '90d' => '90 дней', 'custom' => 'Свой'] as $value => $labelText): ?>
              <option value="<?= h($value) ?>" <?= (string)$period['preset'] === $value ? 'selected' : '' ?>><?= h($labelText) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          От
          <input type="date" name="from" value="<?= h($period['from_date'] ?? '') ?>">
        </label>
        <label>
          До
          <input type="date" name="to" value="<?= h($period['to_date'] ?? '') ?>">
        </label>
        <label style="display:flex; align-items:center; gap:8px; min-height:40px; padding-bottom:2px; text-transform:none; letter-spacing:0;">
          <input type="checkbox" name="include_inactive" value="1" <?= isset($_GET['include_inactive']) ? 'checked' : '' ?> style="width:auto;">
          Все поставщики
        </label>
        <button type="submit">Показать</button>
      </form>
    </section>

    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="overview">
      <div class="metric"><div class="k">Поставщики</div><div class="v"><?= h(scpm_fmt_int($overview['suppliers_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Улучшились</div><div class="v"><?= h(scpm_fmt_int($overview['improved_suppliers_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Δ progress</div><div class="v"><span class="delta <?= h(scpm_delta_class($overview['avg_progress_delta'] ?? 0)) ?>"><?= h(scpm_delta($overview['avg_progress_delta'] ?? 0, ' п.')) ?></span></div></div>
      <div class="metric"><div class="k">Δ загружено</div><div class="v"><span class="delta <?= h(scpm_delta_class($overview['uploaded_delta'] ?? 0)) ?>"><?= h(scpm_delta($overview['uploaded_delta'] ?? 0)) ?></span></div></div>
      <div class="metric"><div class="k">Δ продается</div><div class="v"><span class="delta <?= h(scpm_delta_class($overview['sellable_delta'] ?? 0)) ?>"><?= h(scpm_delta($overview['sellable_delta'] ?? 0)) ?></span></div></div>
      <div class="metric"><div class="k">Δ ошибок</div><div class="v"><span class="delta <?= h(scpm_delta_class($errorDelta, true)) ?>"><?= h(scpm_delta($errorDelta)) ?></span></div></div>
      <div class="metric"><div class="k">Δ качество</div><div class="v"><span class="delta <?= h(scpm_delta_class($overview['avg_quality_delta'] ?? 0)) ?>"><?= h(scpm_delta($overview['avg_quality_delta'] ?? 0, ' п.')) ?></span></div></div>
      <div class="metric"><div class="k">Вклад</div><div class="v"><?= h(scpm_fmt_int($overview['content_points'] ?? 0)) ?></div></div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Вклад пользователей</h2>
        <div class="muted">Завершенные контентные операции за выбранный период.</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Пользователь</th>
            <th>Тип</th>
            <th class="right">Вклад</th>
            <th class="right">Операции</th>
            <th class="right">Поставщики</th>
            <th class="right">Обработано</th>
            <th class="right">Улучшения</th>
            <th class="right">Выгрузка</th>
            <th>Главное действие</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$actors): ?>
            <tr><td colspan="9" class="muted">Нет контентных операций за период.</td></tr>
          <?php endif; ?>
          <?php foreach ($actors as $actor): ?>
            <tr>
              <td><b><?= h($actor['actor_label'] ?? '') ?></b></td>
              <td><span class="pill <?= (string)($actor['actor_kind'] ?? '') === 'human' ? 'green' : 'gray' ?>"><?= h(scpm_actor_kind_label((string)($actor['actor_kind'] ?? 'unknown'))) ?></span></td>
              <td class="right"><b><?= h(scpm_fmt_int($actor['content_points'] ?? 0)) ?></b></td>
              <td class="right"><?= h(scpm_fmt_int($actor['ops_done'] ?? 0)) ?> / <?= h(scpm_fmt_int($actor['ops_total'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($actor['suppliers_count'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($actor['products_processed'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($actor['quality_updates'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($actor['marketplace_uploads'] ?? 0)) ?></td>
              <td><?= h($actor['top_operation'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Поставщики за период</h2>
        <div class="muted">Сначала поставщики с самым заметным движением.</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Поставщик</th>
            <th>Progress</th>
            <th class="right">К выгрузке</th>
            <th class="right">Δ загружено</th>
            <th class="right">Δ готово</th>
            <th class="right">Δ продается</th>
            <th class="right">Δ ошибок</th>
            <th class="right">Вклад</th>
            <th>Лидер</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted">Пока нет данных для расчета.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $entry): ?>
            <?php
              $supplier = (array)($entry['supplier'] ?? []);
              $snapshot = (array)($entry['snapshot'] ?? []);
              $metrics = (array)($entry['metrics'] ?? []);
              $delta = (array)($entry['delta'] ?? []);
              $contribution = (array)($entry['contribution'] ?? []);
              $supplierId = (int)($supplier['id'] ?? 0);
              $score = (float)($snapshot['content_progress_score'] ?? 0);
              $rowErrorDelta = (float)($delta['error_total'] ?? 0) + (float)($delta['revision_total'] ?? 0);
              $supplierHref = 'supplier_content_progress_supplier.php?' . http_build_query([
                'supplier_id' => $supplierId,
                'preset' => $period['preset'] ?? '7d',
                'from' => $period['from_date'] ?? '',
                'to' => $period['to_date'] ?? '',
              ]);
            ?>
            <tr>
              <td>
                <a class="supplier-name" href="<?= h($supplierHref) ?>"><?= h($supplier['name'] ?? ('#' . $supplierId)) ?></a>
                <div class="sub">код: <?= h((string)($supplier['supplier_code'] ?? '')) ?> · без остатка <?= h(scpm_fmt_int($metrics['out_of_stock_total'] ?? 0)) ?></div>
              </td>
              <td>
                <div class="score-line"><span><?= h(scpm_fmt_score($score)) ?></span><span class="muted">/100</span></div>
                <div class="bar <?= $score >= 80 ? 'good' : ($score < 55 ? 'bad' : '') ?>" style="--w: <?= h((string)max(0, min(100, $score))) ?>%;"><span></span></div>
                <?php if (!empty($delta['available'])): ?>
                  <div class="sub"><span class="delta <?= h(scpm_delta_class($delta['content_progress_score'] ?? 0)) ?>"><?= h(scpm_delta($delta['content_progress_score'] ?? 0, ' п.')) ?></span></div>
                <?php else: ?>
                  <div class="sub">нет базы сравнения</div>
                <?php endif; ?>
              </td>
              <td class="right"><b><?= h(scpm_fmt_int($metrics['target_products_total'] ?? 0)) ?></b></td>
              <td class="right"><span class="delta <?= h(scpm_delta_class($delta['uploaded_total'] ?? 0)) ?>"><?= h(!empty($delta['available']) ? scpm_delta($delta['uploaded_total'] ?? 0) : '—') ?></span></td>
              <td class="right"><span class="delta <?= h(scpm_delta_class($delta['ready_total'] ?? 0)) ?>"><?= h(!empty($delta['available']) ? scpm_delta($delta['ready_total'] ?? 0) : '—') ?></span></td>
              <td class="right"><span class="delta <?= h(scpm_delta_class($delta['sellable_total'] ?? 0)) ?>"><?= h(!empty($delta['available']) ? scpm_delta($delta['sellable_total'] ?? 0) : '—') ?></span></td>
              <td class="right"><span class="delta <?= h(scpm_delta_class($rowErrorDelta, true)) ?>"><?= h(!empty($delta['available']) ? scpm_delta($rowErrorDelta) : '—') ?></span></td>
              <td class="right"><b><?= h(scpm_fmt_int($contribution['content_points'] ?? 0)) ?></b><div class="sub"><?= h(scpm_fmt_int($contribution['ops_done'] ?? 0)) ?> операций</div></td>
              <td><?= h($contribution['top_actor'] ?? '') ?><div class="sub"><?= h($contribution['top_operation'] ?? '') ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Последние контентные операции</h2>
        <div class="muted">До 80 последних операций в выбранном периоде.</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Операция</th>
            <th>Поставщик</th>
            <th>Пользователь</th>
            <th>Статус</th>
            <th class="right">Вклад</th>
            <th class="right">Обработано</th>
            <th class="right">Улучшения</th>
            <th class="right">Выгрузка</th>
            <th>Время</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$events): ?>
            <tr><td colspan="9" class="muted">Нет операций за период.</td></tr>
          <?php endif; ?>
          <?php foreach ($events as $event): ?>
            <tr>
              <td>
                <a class="op-link" href="op.php?id=<?= h((string)($event['op_id'] ?? 0)) ?>">#<?= h((string)($event['op_id'] ?? 0)) ?> · <?= h($event['op_label'] ?? $event['op_type'] ?? '') ?></a>
                <div class="sub"><?= h($event['op_type'] ?? '') ?></div>
              </td>
              <td><?= h($event['supplier_name'] ?: ('#' . (string)($event['supplier_id'] ?? ''))) ?></td>
              <td><?= h($event['actor_label'] ?? '') ?></td>
              <td><span class="pill <?= (string)($event['status'] ?? '') === 'done' ? 'green' : ((string)($event['status'] ?? '') === 'error' ? 'red' : 'gray') ?>"><?= h($event['status'] ?? '') ?></span></td>
              <td class="right"><b><?= h(scpm_fmt_int($event['content_points'] ?? 0)) ?></b></td>
              <td class="right"><?= h(scpm_fmt_int($event['products_processed'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($event['quality_updates'] ?? 0)) ?></td>
              <td class="right"><?= h(scpm_fmt_int($event['marketplace_uploads'] ?? 0)) ?></td>
              <td><span data-ft-datetime="<?= h($event['created_at'] ?? '') ?>"><?= h($event['created_at'] ?? '') ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>
