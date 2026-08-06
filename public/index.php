<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ops.php';
require_once __DIR__ . '/../app/suppliers.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function home_fmt_int(int $value): string
{
    return number_format($value, 0, '.', ' ');
}

function home_fmt_duration(int $sec): string
{
    if ($sec <= 0) {
        return '-';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) {
        return sprintf('%dч %02dм', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dм %02dс', $m, $s);
    }
    return sprintf('%dс', $s);
}

function home_table_exists(string $table): bool
{
    static $cache = [];
    $table = trim($table);
    if ($table === '' || !preg_match('~^[a-zA-Z0-9_]+$~', $table)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return (bool)$cache[$table];
    }
    try {
        $st = db()->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $st->execute([$table]);
        $cache[$table] = (int)$st->fetchColumn() > 0;
    } catch (Throwable) {
        $cache[$table] = false;
    }
    return (bool)$cache[$table];
}

function home_count_table(string $table, string $where = '1=1'): int
{
    if (!home_table_exists($table)) {
        return 0;
    }
    if (!preg_match('~^[a-zA-Z0-9_]+$~', $table)) {
        return 0;
    }
    try {
        return (int)db()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function home_latest_xml_datasets(int $limit = 5): array
{
    if (!home_table_exists('feedtools_datasets')) {
        return [];
    }
    try {
        $sql = "
            SELECT id, created_at, original_filename, offers_count
            FROM feedtools_datasets
            WHERE original_filename <> '[system] Global operations'
              AND id NOT IN (
                SELECT dataset_id
                FROM feedtools_supplier_product_meta
                WHERE dataset_id > 0
              )
            ORDER BY id DESC
            LIMIT " . max(1, min(20, $limit));
        return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

$error = '';
$activeOps = [];
$latestXmlDatasets = [];
$stats = [
    'suppliers' => 0,
    'supplier_products' => 0,
    'connections' => 0,
    'price_profiles' => 0,
    'stock_profiles' => 0,
    'stock_tool_items' => 0,
    'order_profiles' => 0,
    'fbo_items' => 0,
    'ozon_analytics_rows' => 0,
    'xml_datasets' => 0,
];

try {
    suppliers_table_ensure($cfg);
    $activeOps = ops_list_active_global(8);
    $latestXmlDatasets = home_latest_xml_datasets(5);
    $stats = [
        'suppliers' => home_count_table('feedtools_suppliers', 'is_active = 1 AND COALESCE(is_archived, 0) = 0'),
        'supplier_products' => home_count_table('feedtools_supplier_products'),
        'connections' => home_count_table('feedtools_marketplace_connections'),
        'price_profiles' => home_count_table('feedtools_ozon_price_feeds'),
        'stock_profiles' => home_count_table('feedtools_marketplace_stock_profiles'),
        'stock_tool_items' => home_count_table('feedtools_stock_tool_items'),
        'order_profiles' => home_count_table('feedtools_marketplace_sync_profiles'),
        'fbo_items' => home_count_table('feedtools_ozon_fbo_items'),
        'ozon_analytics_rows' => home_count_table('feedtools_ozon_product_analytics_daily'),
        'xml_datasets' => home_count_table('feedtools_datasets', "original_filename <> '[system] Global operations'"),
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$primaryCards = [
    [
        'title' => 'Товары поставщиков',
        'description' => 'Импорт, таблица offers, контент, характеристики, фото, комплекты и экспорт на маркетплейсы.',
        'href' => 'suppliers.php',
        'action' => 'Открыть поставщиков',
        'meta' => home_fmt_int($stats['suppliers']) . ' поставщиков · ' . home_fmt_int($stats['supplier_products']) . ' товаров',
        'tone' => 'supplier',
    ],
    [
        'title' => 'Парсинг фидов',
        'description' => 'Запуск парсинга остатков, пересборка XML и автоматизация обновлений по поставщикам.',
        'href' => 'master_mobile_feed.php',
        'action' => 'Открыть управление',
        'meta' => 'Master Mobile',
        'tone' => 'stocks',
    ],
    [
        'title' => 'Контентный прогресс',
        'description' => 'Дашборд загрузки товаров на Ozon/WB, продаваемости, ошибок и качества карточек по поставщикам.',
        'href' => 'supplier_content_progress.php',
        'action' => 'Открыть прогресс',
        'meta' => home_fmt_int($stats['supplier_products']) . ' товаров в оценке',
        'tone' => 'supplier',
    ],
    [
        'title' => 'Подключения маркетплейсов',
        'description' => 'Кабинеты Ozon, Wildberries и Яндекс Маркета, доступ к Price Tool, Stocks Tool и Orders Sync.',
        'href' => 'marketplace_connections.php',
        'action' => 'Открыть подключения',
        'meta' => home_fmt_int($stats['connections']) . ' подключений',
        'tone' => 'market',
    ],
    [
        'title' => 'Price Tool',
        'description' => 'Расчет цен, правила по поставщикам, индекс цены, акции и ручные проверки артикула.',
        'href' => 'marketplace_connections.php?need_connection=price_tool',
        'action' => 'Перейти к профилям',
        'meta' => home_fmt_int($stats['price_profiles']) . ' профилей',
        'tone' => 'price',
    ],
    [
        'title' => 'Stocks Tool',
        'description' => 'Остатки по фидам поставщиков, буферы, резервы, комплекты и выгрузка на маркетплейсы.',
        'href' => 'marketplace_connections.php?need_connection=stocks_tool',
        'action' => 'Перейти к остаткам',
        'meta' => home_fmt_int($stats['stock_profiles']) . ' профилей',
        'tone' => 'stocks',
    ],
    [
        'title' => 'stock pois',
        'description' => 'Ручная распродажа небольшого остатка: склад Ozon, сниженная цена и возврат к обычному расчету.',
        'href' => 'marketplace_connections.php?need_connection=stock_tool',
        'action' => 'Открыть распродажу',
        'meta' => home_fmt_int($stats['stock_tool_items']) . ' товаров',
        'tone' => 'stocks',
    ],
    [
        'title' => 'Orders Sync',
        'description' => 'Синхронизация заказов с МойСклад, автоматизации, склады и статусы заказов.',
        'href' => 'marketplace_connections.php?need_connection=orders_sync',
        'action' => 'Перейти к заказам',
        'meta' => home_fmt_int($stats['order_profiles']) . ' профилей',
        'tone' => 'orders',
    ],
    [
        'title' => 'FBO Tool',
        'description' => 'Остатки FBO Ozon, сниженные цены, FBS-обнуление и контроль распродажи склада.',
        'href' => 'marketplace_connections.php?need_connection=fbo_tool',
        'action' => 'Открыть FBO',
        'meta' => home_fmt_int($stats['fbo_items']) . ' FBO товаров',
        'tone' => 'fbo',
    ],
    [
        'title' => 'Аналитика Ozon',
        'description' => 'Показы, переходы, конверсии, продажи, возвраты, отмены и рекламные метрики по товарам.',
        'href' => 'ozon_analytics.php',
        'action' => 'Открыть аналитику',
        'meta' => home_fmt_int($stats['ozon_analytics_rows']) . ' дневных строк',
        'tone' => 'analytics',
    ],
    [
        'title' => 'Аналитика WB',
        'description' => 'Переходы в карточку, корзина, заказы, выкупы, отмены и конверсии Wildberries по товарам.',
        'href' => 'wb_analytics.php',
        'action' => 'Открыть аналитику WB',
        'meta' => 'дневные отчеты',
        'tone' => 'analytics',
    ],
];

$referenceLinks = [
    ['label' => 'Категории Ozon', 'href' => 'taxonomy/index.php?source=ozon'],
    ['label' => 'Категории WB', 'href' => 'taxonomy/index.php?source=wildberries'],
    ['label' => 'Бренды маркетплейсов', 'href' => 'brand_dictionary.php?source=ozon'],
    ['label' => 'Замены характеристик', 'href' => 'param_value_map.php'],
    ['label' => 'Контентный прогресс', 'href' => 'supplier_content_progress.php'],
    ['label' => 'Динамика контента', 'href' => 'supplier_content_progress_monitoring.php'],
    ['label' => 'Аналитика Ozon', 'href' => 'ozon_analytics.php'],
    ['label' => 'Аналитика WB', 'href' => 'wb_analytics.php'],
    ['label' => 'Журнал GPT', 'href' => 'gpt_log.php'],
    ['label' => 'Очередь операций', 'href' => 'queue_status.php'],
];
if (ft_is_admin_user()) {
    $referenceLinks[] = ['label' => 'Аналитика сотрудников', 'href' => 'staff_analytics.php'];
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>FeedTools</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f7fb;
      --panel: #ffffff;
      --panel-soft: #f8fbff;
      --border: #d9e5f2;
      --text: #17233a;
      --muted: #61738d;
      --blue: #2563eb;
      --navy: #111827;
      --green: #14804a;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
      color: var(--text);
      font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    a { color: inherit; }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 50;
      display: inline-flex;
      padding: 9px 13px;
      border-radius: 999px;
      border: 1px solid #f59e0b;
      background: rgba(255, 251, 235, .97);
      color: #92400e;
      box-shadow: 0 12px 28px rgba(146, 64, 14, .14);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .shell {
      width: min(1500px, calc(100% - 36px));
      margin: 0 auto;
      padding: 26px 0 40px;
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 18px;
      align-items: end;
      margin-bottom: 18px;
      padding: 22px 24px;
      border: 1px solid var(--border);
      border-radius: 24px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    h1, h2, h3, p { margin-top: 0; }
    h1 {
      margin-bottom: 8px;
      font-size: clamp(34px, 4vw, 52px);
      line-height: 1;
      letter-spacing: 0;
    }
    h2 { margin-bottom: 14px; font-size: 24px; }
    h3 { margin-bottom: 8px; font-size: 20px; line-height: 1.18; }
    .muted { color: var(--muted); }
    .hero p { max-width: 820px; margin-bottom: 0; font-size: 18px; }
    .quick-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: flex-end;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      text-decoration: none;
      font-weight: 800;
      white-space: nowrap;
    }
    .btn.primary { border-color: var(--navy); background: var(--navy); color: #fff; }
    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 380px;
      gap: 18px;
      align-items: start;
    }
    .services {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }
    .service-card, .panel {
      min-width: 0;
      border: 1px solid var(--border);
      border-radius: 20px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .service-card {
      display: grid;
      gap: 12px;
      padding: 18px;
      text-decoration: none;
      overflow: hidden;
      transition: transform .14s ease, border-color .14s ease, box-shadow .14s ease;
    }
    .service-card:hover {
      transform: translateY(-2px);
      border-color: #b8c9df;
      box-shadow: 0 24px 54px rgba(27, 57, 90, .12);
    }
    .service-card[data-tone="supplier"] { background: linear-gradient(180deg, #ffffff 0%, #eef6ff 100%); }
    .service-card[data-tone="market"] { background: linear-gradient(180deg, #ffffff 0%, #f0f7f4 100%); }
    .service-card[data-tone="price"] { background: linear-gradient(180deg, #ffffff 0%, #f4f0ff 100%); }
    .service-card[data-tone="stocks"] { background: linear-gradient(180deg, #ffffff 0%, #edfdf3 100%); }
    .service-card[data-tone="orders"] { background: linear-gradient(180deg, #ffffff 0%, #fff7ed 100%); }
    .service-card[data-tone="fbo"] { background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%); }
    .service-card[data-tone="analytics"] { background: linear-gradient(180deg, #ffffff 0%, #eefdfb 100%); }
    .service-meta {
      display: inline-flex;
      width: fit-content;
      max-width: 100%;
      padding: 6px 10px;
      border: 1px solid #bfdbfe;
      border-radius: 999px;
      background: rgba(255,255,255,.72);
      color: #1d4ed8;
      font-size: 13px;
      font-weight: 800;
    }
    .service-card p { margin: 0; color: var(--muted); }
    .service-action { color: var(--navy); font-weight: 900; }
    .side { display: grid; gap: 14px; }
    .panel { padding: 18px; }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .stat {
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: var(--panel-soft);
    }
    .stat strong {
      display: block;
      font-size: 24px;
      line-height: 1.05;
    }
    .stat span { display: block; margin-top: 4px; color: var(--muted); font-size: 13px; font-weight: 700; }
    .link-grid { display: grid; gap: 8px; }
    .link-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      text-decoration: none;
      font-weight: 800;
    }
    .link-row span:last-child { color: var(--muted); }
    .ops-table, .xml-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .ops-table th, .ops-table td, .xml-table th, .xml-table td {
      padding: 10px 8px;
      border-bottom: 1px solid #e5edf6;
      text-align: left;
      vertical-align: top;
      font-size: 14px;
    }
    .ops-table th, .xml-table th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
    .badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 10px;
      border-radius: 999px;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 900;
    }
    .badge.queued { border-color: #e5e7eb; background: #f8fafc; color: #475569; }
    .truncate {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .error {
      margin-bottom: 14px;
      padding: 14px 16px;
      border: 1px solid #fecdd3;
      border-radius: 16px;
      background: #fff1f2;
      color: #b91c1c;
      font-weight: 800;
    }
    .section { display: grid; gap: 14px; }
    @media (max-width: 1180px) {
      .layout { grid-template-columns: 1fr; }
      .services { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .quick-actions { justify-content: flex-start; }
    }
    @media (max-width: 760px) {
      .shell { width: min(100% - 24px, 1500px); padding-top: 14px; }
      .hero { grid-template-columns: 1fr; padding: 18px; }
      .services, .stat-grid { grid-template-columns: 1fr; }
      .btn { width: 100%; }
      .ops-table th:nth-child(3), .ops-table td:nth-child(3) { display: none; }
    }
  </style>
</head>
<body>
  <?php if (ft_is_staging_env($cfg)): ?>
    <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
  <?php endif; ?>

  <main class="shell">
    <section class="hero">
      <div>
        <h1>FeedTools</h1>
        <p class="muted">Рабочая панель сервиса: товары поставщиков, подключения маркетплейсов, цены, остатки, заказы и справочники.</p>
      </div>
      <div class="quick-actions">
        <a class="btn primary" href="suppliers.php">Товары поставщиков</a>
        <a class="btn" href="marketplace_connections.php">Подключения</a>
        <a class="btn" href="xml_feeds.php">XML-фиды</a>
      </div>
    </section>

    <?php if ($error !== ''): ?>
      <div class="error">Ошибка загрузки панели: <?= h($error) ?></div>
    <?php endif; ?>

    <div class="layout">
      <section class="section">
        <div class="services">
          <?php foreach ($primaryCards as $card): ?>
            <a class="service-card" href="<?= h($card['href']) ?>" data-tone="<?= h($card['tone']) ?>">
              <span class="service-meta"><?= h($card['meta']) ?></span>
              <div>
                <h3><?= h($card['title']) ?></h3>
                <p><?= h($card['description']) ?></p>
              </div>
              <span class="service-action"><?= h($card['action']) ?> →</span>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="panel">
          <h2>Активные операции</h2>
          <?php if (!$activeOps): ?>
            <p class="muted" style="margin-bottom:0;">Сейчас нет операций в очереди или в работе.</p>
          <?php else: ?>
            <table class="ops-table">
              <thead>
                <tr>
                  <th style="width:70px;">ID</th>
                  <th style="width:110px;">Статус</th>
                  <th>Операция</th>
                  <th style="width:95px;">Прошло</th>
                  <th style="width:80px;"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($activeOps as $op): ?>
                  <?php
                    $status = (string)($op['status'] ?? '');
                    $badgeClass = $status === 'queued' ? 'badge queued' : 'badge';
                    if (!empty($op['cancel_requested'])) {
                        $status .= ' · stop';
                    }
                    $step = trim((string)($op['active_child_op_type'] ?? ''));
                  ?>
                  <tr>
                    <td>#<?= h($op['id'] ?? '') ?></td>
                    <td><span class="<?= h($badgeClass) ?>"><?= h($status) ?></span></td>
                    <td>
                      <div class="truncate"><?= h($op['op_type'] ?? '') ?></div>
                      <?php if ($step !== ''): ?>
                        <div class="muted truncate" style="font-size:12px;">Сейчас: <?= h($step) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= h(home_fmt_duration((int)($op['elapsed_sec'] ?? 0))) ?></td>
                    <td><a href="op.php?id=<?= h($op['id'] ?? '') ?>">Открыть</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>

      <aside class="side">
        <div class="panel">
          <h2>Состояние</h2>
          <div class="stat-grid">
            <div class="stat"><strong><?= h(home_fmt_int($stats['supplier_products'])) ?></strong><span>товаров поставщиков</span></div>
            <div class="stat"><strong><?= h(home_fmt_int($stats['connections'])) ?></strong><span>подключений</span></div>
            <div class="stat"><strong><?= h(home_fmt_int($stats['price_profiles'])) ?></strong><span>профилей цен</span></div>
            <div class="stat"><strong><?= h(home_fmt_int($stats['stock_profiles'])) ?></strong><span>профилей остатков</span></div>
          </div>
        </div>

        <div class="panel">
          <h2>Справочники</h2>
          <div class="link-grid">
            <?php foreach ($referenceLinks as $link): ?>
              <a class="link-row" href="<?= h($link['href']) ?>">
                <span><?= h($link['label']) ?></span>
                <span>→</span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <h2>XML-фиды</h2>
          <p class="muted">Старая служба загрузки XML вынесена отдельно.</p>
          <p><a class="btn primary" href="xml_feeds.php">Открыть XML-фиды</a></p>
          <?php if ($latestXmlDatasets): ?>
            <table class="xml-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Файл</th>
                  <th>Offers</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($latestXmlDatasets as $ds): ?>
                  <tr>
                    <td><a href="view.php?id=<?= h($ds['id'] ?? '') ?>">#<?= h($ds['id'] ?? '') ?></a></td>
                    <td class="truncate"><?= h($ds['original_filename'] ?? '') ?></td>
                    <td><?= h(home_fmt_int((int)($ds['offers_count'] ?? 0))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </main>
</body>
</html>
