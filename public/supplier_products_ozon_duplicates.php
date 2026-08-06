<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/ozon_duplicate_products.php';
require_once __DIR__ . '/../app/ozon_price_tool.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ozon_dups_current_url(int $datasetId, int $connectionId = 0): string
{
    $query = ['id' => $datasetId];
    if ($connectionId > 0) {
        $query['connection_id'] = $connectionId;
    }
    return 'supplier_products_ozon_duplicates.php?' . http_build_query($query);
}

function ozon_dups_offer_json(array $offers): string
{
    $offers = array_values(array_filter(array_unique(array_map('strval', $offers)), static fn($v): bool => trim($v) !== ''));
    return json_encode($offers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function ozon_dups_status_label(string $status): string
{
    return match ($status) {
        'ready' => 'готово к разбору',
        'passed_product_not_found' => 'товар-модератор не найден в базе',
        'failed_product_not_found' => 'дубль не найден в базе',
        default => str_starts_with($status, 'passed_status_') ? ('статус модератора: ' . substr($status, 14)) : ($status !== '' ? $status : 'не проверено'),
    };
}

function ozon_dups_render_product_card(array $row, string $side): string
{
    $offer = trim((string)($row[$side . '_offer_id'] ?? ''));
    $name = trim((string)($row[$side . '_name'] ?? ''));
    $photo = trim((string)($row[$side . '_photo_url'] ?? ''));
    $productId = (int)($row[$side . '_product_id'] ?? 0);
    $html = '<div class="product-mini">';
    if ($photo !== '') {
        $html .= '<a class="product-mini-photo" href="' . h($photo) . '" target="_blank" rel="noopener"><img src="' . h($photo) . '" alt=""></a>';
    } else {
        $html .= '<div class="product-mini-photo product-mini-photo--empty">нет фото</div>';
    }
    $html .= '<div class="product-mini-text">';
    $html .= '<div class="product-mini-offer">' . h($offer !== '' ? $offer : '—') . '</div>';
    $html .= '<div class="product-mini-name">' . h($name !== '' ? $name : 'Название не найдено') . '</div>';
    if ($productId > 0) {
        $html .= '<div class="product-mini-meta">ID товара: ' . h((string)$productId) . '</div>';
    } else {
        $html .= '<div class="product-mini-meta is-warning">нет локального товара</div>';
    }
    $html .= '</div></div>';
    return $html;
}

function ozon_dups_render_op_button(
    int $datasetId,
    int $connectionId,
    string $returnUrl,
    string $opType,
    array $offers,
    string $label,
    string $class = ''
): string {
    $fieldsJson = $opType === 'supplier_push_ozon_content' ? json_encode(['name', 'description'], JSON_UNESCAPED_UNICODE) : '';
    $disabled = ozon_dups_offer_json($offers) === '[]';
    $html = '<form method="post" action="run_op.php" class="inline-op-form">';
    $html .= '<input type="hidden" name="dataset_id" value="' . h((string)$datasetId) . '">';
    $html .= '<input type="hidden" name="op_type" value="' . h($opType) . '">';
    $html .= '<input type="hidden" name="offer_ids_json" value="' . h(ozon_dups_offer_json($offers)) . '">';
    $html .= '<input type="hidden" name="return_url" value="' . h($returnUrl) . '">';
    if ($opType === 'supplier_push_ozon_content') {
        $html .= '<input type="hidden" name="connection_id" value="' . h((string)$connectionId) . '">';
        $html .= '<input type="hidden" name="fields_json" value="' . h((string)$fieldsJson) . '">';
    }
    if ($opType === 'gpt_generate_description_ru') {
        $html .= '<input type="hidden" name="rewrite_existing" value="1">';
        $html .= '<input type="hidden" name="use_keywords" value="1">';
        $html .= '<input type="hidden" name="inplace" value="1">';
    }
    $html .= '<button type="submit" class="mini-btn ' . h($class) . '"' . ($disabled ? ' disabled' : '') . '>' . h($label) . '</button>';
    $html .= '</form>';
    return $html;
}

$datasetId = (int)($_GET['id'] ?? 0);
if ($datasetId <= 0) {
    http_response_code(400);
    exit('Bad dataset id');
}

supplier_products_tables_ensure($cfg);
$dataset = supplier_products_dataset_row($datasetId, $cfg);
if (!is_array($dataset) || !supplier_products_is_dataset_row($dataset)) {
    http_response_code(404);
    exit('Supplier products dataset not found');
}

$ctx = supplier_products_context_for_dataset($dataset, $cfg);
if (!is_array($ctx)) {
    http_response_code(404);
    exit('Supplier context not found');
}

$supplier = $ctx['supplier'];
$supplierId = (int)$ctx['supplier_id'];
$datasetConnectionId = (int)($dataset['ozon_connection_id'] ?? 0);
$requestedConnectionId = (int)($_GET['connection_id'] ?? 0);
$connections = ozon_price_connection_list($cfg, 'ozon');
$connectionId = $requestedConnectionId > 0 ? $requestedConnectionId : $datasetConnectionId;
if ($connectionId <= 0 && $connections) {
    $connectionId = (int)($connections[0]['id'] ?? 0);
}
$connection = $connectionId > 0 ? ozon_price_connection_get($connectionId, $cfg) : null;
$returnUrl = ozon_dups_current_url($datasetId, $connectionId);

$pairs = $connectionId > 0 ? ozon_duplicate_pairs_for_dataset($datasetId, $connectionId, $cfg) : [];
$stats = [
    'pairs' => count($pairs),
    'ready' => 0,
    'missing' => 0,
    'updated_at' => '',
];
foreach ($pairs as $pair) {
    if ((string)($pair['status'] ?? '') === 'ready') {
        $stats['ready']++;
    } else {
        $stats['missing']++;
    }
    $updated = trim((string)($pair['updated_at'] ?? ''));
    if ($updated !== '' && ($stats['updated_at'] === '' || $updated > $stats['updated_at'])) {
        $stats['updated_at'] = $updated;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Дубли Ozon</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root { --bg:#f5f8fc; --panel:#fff; --ink:#111827; --muted:#64748b; --line:#d8e4f2; --soft:#f8fbff; --blue:#2563eb; --green:#047857; --red:#b91c1c; --amber:#b45309; --shadow:0 18px 45px rgba(15,23,42,.07); }
    * { box-sizing:border-box; }
    body { margin:0; background:linear-gradient(180deg,#fff 0%,var(--bg) 320px); color:var(--ink); font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    a { color:#1d4ed8; text-decoration:none; }
    a:hover { text-decoration:underline; }
    .shell { max-width:1480px; margin:0 auto; padding:22px 18px 46px; }
    .hero, .panel { border:1px solid var(--line); border-radius:18px; background:rgba(255,255,255,.94); box-shadow:var(--shadow); }
    .hero { padding:22px; margin-bottom:16px; }
    .hero-row { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; }
    h1 { margin:0; font-size:34px; line-height:1.05; letter-spacing:0; }
    .lead { max-width:900px; margin:9px 0 0; color:var(--muted); font-size:15px; }
    .stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-top:18px; }
    .stat { border:1px solid #e2eaf5; border-radius:13px; background:var(--soft); padding:12px; min-width:0; }
    .stat span { display:block; color:var(--muted); font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .stat strong { display:block; margin-top:4px; font-size:20px; line-height:1; overflow-wrap:anywhere; }
    .panel { padding:18px; margin-bottom:16px; }
    .controls { display:grid; grid-template-columns:minmax(240px,420px) auto 1fr; gap:10px; align-items:end; }
    label { display:grid; gap:6px; color:var(--muted); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
    select { min-height:42px; border:1px solid #cbd8ea; border-radius:12px; background:#fff; color:var(--ink); padding:0 12px; font:inherit; font-weight:700; }
    .btn, button { min-height:42px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #bfdbfe; border-radius:12px; background:#eff6ff; color:#1d4ed8; padding:0 14px; font:inherit; font-weight:900; cursor:pointer; text-decoration:none; }
    .btn:hover, button:hover { text-decoration:none; transform:translateY(-1px); }
    .btn.primary, button.primary { background:linear-gradient(135deg,#2563eb,#0f8b8d); color:#fff; border-color:transparent; }
    .btn.danger, button.danger { background:#fff1f2; border-color:#fecaca; color:var(--red); }
    .btn.neutral, button.neutral { background:#fff; color:#172033; border-color:var(--line); }
    .hint { color:var(--muted); align-self:center; }
    .bulk { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .bulk select { min-height:38px; }
    .table-wrap { overflow:auto; border:1px solid var(--line); border-radius:14px; background:#fff; }
    table { width:100%; border-collapse:separate; border-spacing:0; min-width:1120px; }
    th, td { border-bottom:1px solid #e5edf7; border-right:1px solid #e5edf7; padding:12px; vertical-align:top; text-align:left; }
    th { position:sticky; top:0; z-index:1; background:#edf4fb; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    th:last-child, td:last-child { border-right:0; }
    tr:last-child td { border-bottom:0; }
    .check-cell { width:42px; text-align:center; }
    input[type=checkbox] { width:18px; height:18px; accent-color:#2563eb; }
    .product-mini { display:grid; grid-template-columns:86px minmax(0,1fr); gap:12px; min-width:0; }
    .product-mini-photo { width:86px; height:112px; border:1px solid #dbe7f4; border-radius:12px; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:12px; font-weight:800; text-align:center; }
    .product-mini-photo img { width:100%; height:100%; object-fit:contain; display:block; }
    .product-mini-offer { font-weight:950; color:#0f172a; overflow-wrap:anywhere; }
    .product-mini-name { margin-top:5px; max-width:420px; color:#243449; font-weight:700; overflow-wrap:anywhere; }
    .product-mini-meta { margin-top:7px; color:#64748b; font-size:12px; font-weight:800; }
    .product-mini-meta.is-warning { color:var(--amber); }
    .status-pill { display:inline-flex; align-items:center; min-height:28px; padding:0 9px; border-radius:999px; font-size:12px; font-weight:900; background:#ecfdf5; color:var(--green); border:1px solid #bbf7d0; }
    .status-pill.is-warning { background:#fffbeb; color:var(--amber); border-color:#fde68a; }
    .error-text { margin-top:10px; max-width:360px; color:#64748b; font-size:12px; white-space:pre-wrap; overflow-wrap:anywhere; }
    .row-actions { display:grid; gap:10px; min-width:240px; }
    .action-group { display:flex; gap:6px; flex-wrap:wrap; }
    .action-title { color:#64748b; font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.05em; }
    .inline-op-form { display:inline; margin:0; }
    .mini-btn { min-height:32px; border-radius:10px; padding:0 10px; font-size:12px; background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .mini-btn.green { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
    .mini-btn.amber { background:#fffbeb; color:#92400e; border-color:#fde68a; }
    .mini-btn:disabled, .mini-btn[disabled] { opacity:.48; cursor:not-allowed; transform:none; }
    .empty { padding:28px; text-align:center; color:var(--muted); }
    .empty h2 { margin:0 0 8px; color:#172033; }
    @media (max-width:760px) {
      .shell { padding:14px 10px 34px; }
      h1 { font-size:28px; }
      .stats, .controls { grid-template-columns:1fr; }
      .hero, .panel { border-radius:14px; padding:14px; }
      .bulk { align-items:stretch; }
      .bulk > * { width:100%; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <?= ft_top_navigation([
      'back_href' => 'supplier_products_view_v2.php?id=' . urlencode((string)$datasetId),
      'back_label' => 'К товарам',
      'active' => 'suppliers',
      'links' => [
        ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
        ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
        ['key' => 'connections', 'label' => 'Подключения', 'href' => 'marketplace_connections.php'],
      ],
    ]) ?>

    <section class="hero">
      <div class="hero-row">
        <div>
          <h1>Дубли товаров Ozon</h1>
          <p class="lead">Отдельный экран для ошибки “дубль товара”: операция находит товар, который уже прошёл модерацию, сопоставляет его с текущим дублем и даёт быстрые действия по названиям, описаниям и отправке в Ozon.</p>
        </div>
        <a class="btn neutral" href="supplier_products_view_v2.php?id=<?= h((string)$datasetId) ?>">Открыть таблицу товаров</a>
      </div>
      <div class="stats">
        <div class="stat"><span>Поставщик</span><strong><?= h((string)($supplier['name'] ?? '—')) ?></strong></div>
        <div class="stat"><span>Подключение</span><strong><?= h(ozon_price_connection_title_short($connection)) ?></strong></div>
        <div class="stat"><span>Пар найдено</span><strong><?= h((string)$stats['pairs']) ?></strong></div>
        <div class="stat"><span>Обновлено</span><strong><?= h($stats['updated_at'] !== '' ? $stats['updated_at'] : 'ещё нет') ?></strong></div>
      </div>
    </section>

    <section class="panel">
      <div class="controls">
        <form method="get">
          <input type="hidden" name="id" value="<?= h((string)$datasetId) ?>">
          <label>
            Ozon-подключение
            <select name="connection_id" onchange="this.form.submit()">
              <?php foreach ($connections as $c): $cid = (int)($c['id'] ?? 0); ?>
                <option value="<?= h((string)$cid) ?>" <?= $cid === $connectionId ? 'selected' : '' ?>>
                  <?= h(ozon_price_connection_title_short($c)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>

        <form method="post" action="run_op.php">
          <input type="hidden" name="dataset_id" value="<?= h((string)$datasetId) ?>">
          <input type="hidden" name="op_type" value="ozon_duplicate_pairs_scan">
          <input type="hidden" name="connection_id" value="<?= h((string)$connectionId) ?>">
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
          <button type="submit" class="primary" <?= $connectionId <= 0 ? 'disabled' : '' ?>>Собрать дубли Ozon</button>
        </form>

        <div class="hint">
          <?php if ($connectionId <= 0): ?>
            Для датасета не выбрано подключение Ozon.
          <?php else: ?>
            Обновление работает по кешу Ozon-статусов. Если статусы устарели, сначала обнови статус Ozon в общей таблице.
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="hero-row" style="margin-bottom:12px;">
        <div>
          <h2 style="margin:0;font-size:22px;">Пары товаров</h2>
          <div class="hint">Галочками можно собрать несколько пар и обработать прошедшие модерацию товары, дубли или обе стороны сразу.</div>
        </div>
        <div class="bulk">
          <select id="bulkTarget">
            <option value="failed">Дубли с ошибкой</option>
            <option value="passed">Прошедшие модерацию</option>
            <option value="both">Обе стороны пары</option>
          </select>
          <button type="button" class="neutral" data-bulk-op="gpt_rewrite_title_marketplace">Рерайт названий</button>
          <button type="button" class="neutral" data-bulk-op="gpt_generate_description_ru">Рерайт описаний</button>
          <button type="button" class="primary" data-bulk-op="supplier_push_ozon_content">Отправить названия + описания</button>
        </div>
      </div>

      <form id="bulkRunForm" method="post" action="run_op.php" style="display:none;">
        <input type="hidden" name="dataset_id" value="<?= h((string)$datasetId) ?>">
        <input type="hidden" name="op_type" value="">
        <input type="hidden" name="offer_ids_json" value="[]">
        <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <input type="hidden" name="connection_id" value="<?= h((string)$connectionId) ?>">
        <input type="hidden" name="fields_json" value="<?= h(json_encode(['name', 'description'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>">
        <input type="hidden" name="rewrite_existing" value="1">
        <input type="hidden" name="use_keywords" value="1">
        <input type="hidden" name="inplace" value="1">
      </form>

      <?php if (!$pairs): ?>
        <div class="empty">
          <h2>Таблица дублей пока пустая</h2>
          <div>Запусти сбор дублей Ozon. Если ошибок нет, здесь ничего не появится.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="check-cell"><input type="checkbox" id="selectAllPairs" aria-label="Выбрать все пары"></th>
                <th>Прошедший модерацию товар</th>
                <th>Дубль товара с ошибкой</th>
                <th>Статус и ошибка Ozon</th>
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pairs as $pair):
                $failedOffer = trim((string)($pair['failed_offer_id'] ?? ''));
                $passedOffer = trim((string)($pair['passed_offer_id'] ?? ''));
                $status = (string)($pair['status'] ?? '');
                $ready = $status === 'ready';
              ?>
                <tr>
                  <td class="check-cell">
                    <input type="checkbox" class="pair-check" data-failed-offer="<?= h($failedOffer) ?>" data-passed-offer="<?= h($passedOffer) ?>" aria-label="Выбрать пару">
                  </td>
                  <td><?= ozon_dups_render_product_card($pair, 'passed') ?></td>
                  <td><?= ozon_dups_render_product_card($pair, 'failed') ?></td>
                  <td>
                    <span class="status-pill <?= $ready ? '' : 'is-warning' ?>"><?= h(ozon_dups_status_label($status)) ?></span>
                    <?php if ((int)($pair['passed_company_id'] ?? 0) > 0): ?>
                      <div class="product-mini-meta">Company ID: <?= h((string)(int)$pair['passed_company_id']) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string)($pair['raw_error_text'] ?? '')) !== ''): ?>
                      <div class="error-text"><?= h((string)$pair['raw_error_text']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="row-actions">
                      <div>
                        <div class="action-title">Название</div>
                        <div class="action-group">
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_rewrite_title_marketplace', [$passedOffer], 'модератор') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_rewrite_title_marketplace', [$failedOffer], 'дубль') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_rewrite_title_marketplace', [$passedOffer, $failedOffer], 'оба') ?>
                        </div>
                      </div>
                      <div>
                        <div class="action-title">Описание</div>
                        <div class="action-group">
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_generate_description_ru', [$passedOffer], 'модератор', 'amber') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_generate_description_ru', [$failedOffer], 'дубль', 'amber') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'gpt_generate_description_ru', [$passedOffer, $failedOffer], 'оба', 'amber') ?>
                        </div>
                      </div>
                      <div>
                        <div class="action-title">Ozon API</div>
                        <div class="action-group">
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'supplier_push_ozon_content', [$passedOffer], 'отправить модератор', 'green') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'supplier_push_ozon_content', [$failedOffer], 'отправить дубль', 'green') ?>
                          <?= ozon_dups_render_op_button($datasetId, $connectionId, $returnUrl, 'supplier_push_ozon_content', [$passedOffer, $failedOffer], 'отправить оба', 'green') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <script>
    (function() {
      var all = document.getElementById('selectAllPairs');
      var checks = Array.prototype.slice.call(document.querySelectorAll('.pair-check'));
      var bulkForm = document.getElementById('bulkRunForm');
      var targetSelect = document.getElementById('bulkTarget');
      if (all) {
        all.addEventListener('change', function() {
          checks.forEach(function(cb) { cb.checked = all.checked; });
        });
      }
      function selectedOffers(target) {
        var out = [];
        var seen = {};
        checks.forEach(function(cb) {
          if (!cb.checked) return;
          var failed = cb.getAttribute('data-failed-offer') || '';
          var passed = cb.getAttribute('data-passed-offer') || '';
          var values = target === 'both' ? [passed, failed] : [target === 'passed' ? passed : failed];
          values.forEach(function(v) {
            v = String(v || '').trim();
            if (v && !seen[v]) {
              seen[v] = true;
              out.push(v);
            }
          });
        });
        return out;
      }
      document.querySelectorAll('[data-bulk-op]').forEach(function(button) {
        button.addEventListener('click', function() {
          if (!bulkForm) return;
          var opType = button.getAttribute('data-bulk-op') || '';
          var offers = selectedOffers(targetSelect ? targetSelect.value : 'failed');
          if (!offers.length) {
            alert('Выбери хотя бы одну пару товаров.');
            return;
          }
          bulkForm.querySelector('[name="op_type"]').value = opType;
          bulkForm.querySelector('[name="offer_ids_json"]').value = JSON.stringify(offers);
          bulkForm.submit();
        });
      });
    })();
  </script>
</body>
</html>
