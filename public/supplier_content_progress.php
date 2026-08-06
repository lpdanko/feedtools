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

function scp_fmt_int($value): string
{
    return number_format((int)$value, 0, '.', ' ');
}

function scp_fmt_score($value): string
{
    return number_format((float)$value, 1, '.', ' ');
}

function scp_score_class(float $score): string
{
    if ($score >= 80) return 'good';
    if ($score >= 55) return 'mid';
    return 'bad';
}

function scp_delta($value, string $suffix = ''): string
{
    if ($value === null || $value === '') return '—';
    $num = (float)$value;
    if (abs($num) < 0.01) return '0' . $suffix;
    return ($num > 0 ? '+' : '') . (floor($num) == $num ? (string)(int)$num : number_format($num, 1, '.', ' ')) . $suffix;
}

function scp_delta_class($value, bool $inverse = false): string
{
    $num = (float)$value;
    if (abs($num) < 0.01) return 'flat';
    $good = $inverse ? $num < 0 : $num > 0;
    return $good ? 'up' : 'down';
}

function scp_confidence_label(string $level): string
{
    return ['high' => 'данные свежие', 'medium' => 'средняя уверенность', 'low' => 'низкая уверенность'][$level] ?? 'средняя уверенность';
}

$error = '';
$report = ['period' => supplier_content_progress_parse_period($_GET), 'overview' => [], 'rows' => []];
try {
    $report = supplier_content_progress_fetch_portfolio($cfg, [
        'preset' => $_GET['preset'] ?? '7d',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'refresh' => isset($_GET['refresh']) && (string)$_GET['refresh'] === '1',
        'include_inactive' => isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1',
        'priority' => $_GET['priority'] ?? 'all',
        'focus' => $_GET['focus'] ?? 'all',
        'movement' => $_GET['movement'] ?? 'all',
        'q' => $_GET['q'] ?? '',
    ]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$period = (array)($report['period'] ?? supplier_content_progress_parse_period($_GET));
$overview = (array)($report['overview'] ?? []);
$rows = (array)($report['rows'] ?? []);
$priorityFilter = (string)($_GET['priority'] ?? 'all');
$focusFilter = (string)($_GET['focus'] ?? 'all');
$movementFilter = (string)($_GET['movement'] ?? 'all');
$q = (string)($_GET['q'] ?? '');
$errorDelta = (int)($overview['error_delta'] ?? 0) + (int)($overview['revision_delta'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — Контентный прогресс</title>
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
      --gray: #64748b;
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
      max-width: 1480px;
      margin: 0 auto;
      padding: 24px 18px 44px;
    }
    .page-head {
      display: grid;
      gap: 10px;
      margin-bottom: 16px;
    }
    .title-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
    }
    h1 {
      margin: 0;
      font-size: clamp(28px, 4vw, 44px);
      line-height: 1;
      letter-spacing: 0;
    }
    .lead {
      max-width: 900px;
      margin: 0;
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
      font-weight: 800;
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
      background: rgba(255,255,255,.84);
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
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    select, input[type=date], input[type=search] {
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
      font-weight: 800;
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
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .metric .v {
      font-size: 26px;
      line-height: 1;
      font-weight: 900;
    }
    .table-panel {
      border: 1px solid var(--line);
      border-radius: 16px;
      background: var(--panel);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .table-head {
      padding: 16px 18px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .table-head h2 {
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
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .05em;
      background: #fbfdff;
      white-space: nowrap;
    }
    tr:hover td { background: #fbfdff; }
    .supplier-name {
      font-size: 15px;
      font-weight: 900;
      color: var(--ink);
      text-decoration: none;
    }
    .supplier-sub {
      margin-top: 4px;
      color: var(--muted);
      font-size: 12px;
    }
    .score-cell {
      min-width: 150px;
    }
    .score-line {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      align-items: center;
      margin-bottom: 7px;
      font-weight: 900;
    }
    .bar {
      height: 9px;
      border-radius: 999px;
      overflow: hidden;
      background: #e8eef7;
    }
    .bar span {
      display: block;
      height: 100%;
      width: var(--w);
      background: var(--blue);
      border-radius: inherit;
    }
    .bar.good span { background: var(--green); }
    .bar.mid span { background: #d99a24; }
    .bar.bad span { background: var(--red); }
    .mp {
      min-width: 150px;
      display: grid;
      gap: 6px;
    }
    .mp-title {
      font-weight: 900;
    }
    .mp-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 5px;
      font-size: 12px;
      color: var(--muted);
    }
    .mp-grid b {
      color: var(--ink);
      font-weight: 900;
    }
    .chips {
      display: flex;
      gap: 5px;
      flex-wrap: wrap;
      max-width: 360px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 25px;
      padding: 0 8px;
      border-radius: 999px;
      background: #eef5ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 800;
    }
    .chip.red { background: #fff1f2; color: var(--red); }
    .chip.yellow { background: #fffbeb; color: var(--yellow); }
    .chip.gray { background: #f1f5f9; color: var(--gray); }
    .priority-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 950;
      white-space: nowrap;
      background: #f1f5f9;
      color: var(--gray);
    }
    .priority-pill.red { background: #fff1f2; color: var(--red); }
    .priority-pill.yellow { background: #fffbeb; color: var(--yellow); }
    .priority-pill.blue { background: #eef5ff; color: var(--blue); }
    .priority-pill.green { background: #ecfdf5; color: var(--green); }
    .priority-pill.gray { background: #f1f5f9; color: var(--gray); }
    .priority-cell {
      min-width: 170px;
    }
    .priority-score {
      margin-top: 6px;
      font-size: 12px;
      font-weight: 900;
      color: var(--ink);
    }
    .score-note {
      margin-top: 6px;
      color: var(--muted);
      font-size: 11px;
      line-height: 1.35;
    }
    .confidence {
      display: inline-flex;
      align-items: center;
      min-height: 25px;
      padding: 0 8px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      background: #ecfdf5;
      color: var(--green);
      white-space: nowrap;
    }
    .confidence.medium { background: #fffbeb; color: var(--yellow); }
    .confidence.low { background: #fff1f2; color: var(--red); }
    .delta {
      font-weight: 900;
      white-space: nowrap;
    }
    .delta.up { color: var(--green); }
    .delta.down { color: var(--red); }
    .delta.flat { color: var(--gray); }
    .error {
      padding: 14px 16px;
      border: 1px solid #fecaca;
      border-radius: 14px;
      background: #fff1f2;
      color: var(--red);
      font-weight: 800;
      margin: 16px 0;
    }
    @media (max-width: 1280px) {
      .overview { grid-template-columns: repeat(4, minmax(150px, 1fr)); }
    }
    @media (max-width: 900px) {
      .overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .table-panel { overflow-x: auto; }
      table { min-width: 1260px; }
    }
    @media (max-width: 640px) {
      .shell { padding-left: 12px; padding-right: 12px; }
      .overview { grid-template-columns: 1fr; }
      label, select, input[type=date], input[type=search], button { width: 100%; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <?= ft_top_navigation([
      'back_href' => 'index.php',
      'back_label' => 'Назад',
      'active' => 'content_progress',
      'links' => ft_default_nav_links('content_progress'),
    ]) ?>

    <section class="page-head">
      <div class="title-row">
        <div>
          <h1>Контентный прогресс</h1>
          <p class="lead">Загрузка товаров поставщиков на Ozon и WB, качество карточек, ошибки, готовность и реальная продаваемость. Товары без остатка показываются отдельно и не портят прогресс выгрузки.</p>
        </div>
        <div class="actions">
          <a class="btn secondary" href="supplier_content_progress.php?<?= h(http_build_query(array_merge($_GET, ['refresh' => 1]))) ?>">Пересчитать</a>
          <a class="btn secondary" href="supplier_content_progress_monitoring.php?<?= h(http_build_query([
            'preset' => $period['preset'] ?? '7d',
            'from' => $period['from_date'] ?? '',
            'to' => $period['to_date'] ?? '',
            'include_inactive' => isset($_GET['include_inactive']) ? '1' : '',
          ])) ?>">Динамика</a>
          <a class="btn" href="suppliers.php">Поставщики</a>
        </div>
      </div>
    </section>

    <section class="filters">
      <form method="get">
        <label>
          Период
          <select name="preset">
            <?php foreach (['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', '90d' => '90 дней', 'custom' => 'Свой'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= (string)$period['preset'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
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
        <label>
          Приоритет
          <select name="priority">
            <?php foreach (['all' => 'Все', 'urgent' => 'Срочно', 'high' => 'Высокий', 'medium' => 'Средний', 'support' => 'Поддерживать', 'idle' => 'Без выгрузки'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $priorityFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Фокус
          <select name="focus">
            <?php foreach ([
              'all' => 'Все',
              'upload' => 'Загрузка',
              'errors' => 'Ошибки',
              'core' => 'Базовые данные',
              'sellable' => 'Продаваемость',
              'quality' => 'Качество',
              'support' => 'Поддержка',
              'stock' => 'Нет остатка',
              'data' => 'Импорт',
            ] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $focusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Движение
          <select name="movement">
            <?php foreach (['all' => 'Все', 'improved' => 'Улучшились', 'regressed' => 'Просели', 'changed' => 'Есть изменение', 'no_delta' => 'Нет базы'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $movementFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Поиск
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="поставщик или код">
        </label>
        <label style="display:flex; align-items:center; gap:8px; min-height:40px; padding-bottom:2px; text-transform:none; letter-spacing:0;">
          <input type="checkbox" name="include_inactive" value="1" <?= isset($_GET['include_inactive']) ? 'checked' : '' ?> style="width:auto;">
          Все поставщики
        </label>
        <button type="submit">Показать</button>
        <a class="btn secondary" href="supplier_content_progress.php">Сбросить</a>
      </form>
    </section>

    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="overview">
      <div class="metric"><div class="k">Поставщики</div><div class="v"><?= h(scp_fmt_int($overview['suppliers_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Товаров всего</div><div class="v"><?= h(scp_fmt_int($overview['products_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">К выгрузке</div><div class="v"><?= h(scp_fmt_int($overview['target_products_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Без остатка</div><div class="v"><?= h(scp_fmt_int($overview['out_of_stock_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Загружено</div><div class="v"><?= h(scp_fmt_int($overview['uploaded_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Не загружено</div><div class="v"><?= h(scp_fmt_int($overview['not_uploaded_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Продается</div><div class="v"><?= h(scp_fmt_int($overview['sellable_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Ошибки/доработки</div><div class="v"><?= h(scp_fmt_int($overview['error_total'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Срочно</div><div class="v"><?= h(scp_fmt_int($overview['priority_urgent_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Высокий приоритет</div><div class="v"><?= h(scp_fmt_int($overview['priority_high_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Улучшились</div><div class="v"><?= h(scp_fmt_int($overview['improved_suppliers_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Просели</div><div class="v"><?= h(scp_fmt_int($overview['regressed_suppliers_count'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Δ progress</div><div class="v"><span class="delta <?= h(scp_delta_class($overview['avg_progress_delta'] ?? 0)) ?>"><?= h(scp_delta($overview['avg_progress_delta'] ?? 0, ' п.')) ?></span></div></div>
      <div class="metric"><div class="k">Δ загружено</div><div class="v"><span class="delta <?= h(scp_delta_class($overview['uploaded_delta'] ?? 0)) ?>"><?= h(scp_delta($overview['uploaded_delta'] ?? 0)) ?></span></div></div>
      <div class="metric"><div class="k">Δ продается</div><div class="v"><span class="delta <?= h(scp_delta_class($overview['sellable_delta'] ?? 0)) ?>"><?= h(scp_delta($overview['sellable_delta'] ?? 0)) ?></span></div></div>
      <div class="metric"><div class="k">Δ ошибок</div><div class="v"><span class="delta <?= h(scp_delta_class($errorDelta, true)) ?>"><?= h(scp_delta($errorDelta)) ?></span></div></div>
      <div class="metric"><div class="k">Средний progress</div><div class="v"><?= h(scp_fmt_score($overview['avg_progress'] ?? 0)) ?></div></div>
      <div class="metric"><div class="k">Качество</div><div class="v"><?= h(scp_fmt_score($overview['avg_quality'] ?? 0)) ?></div></div>
    </section>

    <section class="table-panel">
      <div class="table-head">
        <h2>Поставщики</h2>
        <div class="muted">Показано <?= h(scp_fmt_int($overview['suppliers_count'] ?? 0)) ?> из <?= h(scp_fmt_int($overview['suppliers_total_count'] ?? 0)) ?>. Сначала поставщики с самым высоким приоритетом.</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Поставщик</th>
            <th>Приоритет</th>
            <th>Progress</th>
            <th>Ozon</th>
            <th>WB</th>
            <th>Ассортимент</th>
            <th>Не загружено</th>
            <th>Продается</th>
            <th>Период</th>
            <th>Причины</th>
            <th>Данные</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="muted">По текущим фильтрам нет данных.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $entry): ?>
            <?php
              $supplier = (array)($entry['supplier'] ?? []);
              $snapshot = (array)($entry['snapshot'] ?? []);
              $metrics = (array)($entry['metrics'] ?? []);
              $delta = (array)($entry['delta'] ?? []);
              $priority = (array)($entry['priority'] ?? []);
              $supplierId = (int)($supplier['id'] ?? 0);
              $score = (float)($snapshot['content_progress_score'] ?? 0);
              $scoreClass = scp_score_class($score);
              $byMarketplace = (array)($metrics['by_marketplace'] ?? []);
              $confidence = (array)($metrics['data_confidence'] ?? []);
              $confidenceLevel = (string)($confidence['level'] ?? ($snapshot['data_confidence_level'] ?? 'medium'));
              $progressDelta = $delta['available'] ?? false ? (float)($delta['content_progress_score'] ?? 0) : null;
            ?>
            <tr>
              <td>
                <a class="supplier-name" href="supplier_content_progress_supplier.php?supplier_id=<?= h((string)$supplierId) ?>"><?= h($supplier['name'] ?? ('#' . $supplierId)) ?></a>
                <div class="supplier-sub">код: <?= h((string)($supplier['supplier_code'] ?? '')) ?> · снимок #<?= h((string)($snapshot['id'] ?? '')) ?></div>
              </td>
              <td class="priority-cell">
                <span class="priority-pill <?= h((string)($priority['class'] ?? 'gray')) ?>"><?= h((string)($priority['label'] ?? '—')) ?></span>
                <div class="priority-score"><?= h(scp_fmt_score($priority['score'] ?? 0)) ?> / 100</div>
                <div class="supplier-sub">фокус: <?= h((string)($priority['focus'] ?? 'Контент')) ?></div>
              </td>
              <td class="score-cell">
                <div class="score-line"><span><?= h(scp_fmt_score($score)) ?></span><span class="muted">/100</span></div>
                <div class="bar <?= h($scoreClass) ?>" style="--w: <?= h((string)max(0, min(100, $score))) ?>%;"><span></span></div>
                <div class="score-note">загрузка <?= h(scp_fmt_score($snapshot['upload_score'] ?? 0)) ?> · продается <?= h(scp_fmt_score($snapshot['sellable_score'] ?? 0)) ?> · качество <?= h(scp_fmt_score($snapshot['avg_card_quality_score'] ?? 0)) ?></div>
              </td>
              <?php foreach (['ozon' => 'Ozon', 'wb' => 'WB'] as $mp => $label): ?>
                <?php $mpRow = (array)($byMarketplace[$mp] ?? []); ?>
                <td>
                  <div class="mp">
                    <div class="mp-title"><?= h($label) ?> · <?= h(scp_fmt_score($mpRow['completion_score'] ?? 0)) ?></div>
                    <div class="mp-grid">
                      <span>загр. <b><?= h(scp_fmt_int($mpRow['uploaded'] ?? 0)) ?></b></span>
                      <span>готово <b><?= h(scp_fmt_int($mpRow['ready'] ?? 0)) ?></b></span>
                      <span>прод. <b><?= h(scp_fmt_int($mpRow['sellable'] ?? 0)) ?></b></span>
                      <span>ошиб. <b><?= h(scp_fmt_int(((int)($mpRow['error'] ?? 0)) + ((int)($mpRow['revision'] ?? 0)))) ?></b></span>
                    </div>
                  </div>
                </td>
              <?php endforeach; ?>
              <td>
                <b><?= h(scp_fmt_int($metrics['target_products_total'] ?? 0)) ?></b>
                <div class="supplier-sub">всего <?= h(scp_fmt_int($snapshot['products_total'] ?? 0)) ?> · без остатка <?= h(scp_fmt_int($metrics['out_of_stock_total'] ?? 0)) ?></div>
              </td>
              <td><b><?= h(scp_fmt_int($snapshot['not_uploaded_total'] ?? 0)) ?></b><div class="supplier-sub">из товаров с остатком</div></td>
              <td><b><?= h(scp_fmt_int($snapshot['sellable_total'] ?? 0)) ?></b><div class="supplier-sub"><?= h(scp_fmt_score($metrics['sellable_any_percent'] ?? 0)) ?>%</div></td>
              <td>
                <?php if ($progressDelta === null): ?>
                  <span class="muted">нет базы</span>
                <?php else: ?>
                  <span class="delta <?= $progressDelta >= 0 ? 'up' : 'down' ?>"><?= h(scp_delta($progressDelta, ' п.')) ?></span>
                  <div class="supplier-sub">sellable <?= h(scp_delta($delta['sellable_total'] ?? null)) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="chips">
                  <?php foreach (array_slice((array)($priority['reasons'] ?? ($metrics['main_reasons'] ?? [])), 0, 4) as $reason): ?>
                    <span class="chip <?= str_contains((string)$reason, 'ошиб') || str_contains((string)$reason, 'динамика') ? 'red' : 'gray' ?>"><?= h($reason) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <span class="confidence <?= h($confidenceLevel) ?>"><?= h(scp_confidence_label($confidenceLevel)) ?></span>
                <?php if (!empty($confidence['warnings'])): ?>
                  <div class="supplier-sub"><?= h((string)reset($confidence['warnings'])) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>
