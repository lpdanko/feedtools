<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();

require_once __DIR__ . '/../app/suppliers.php';
require_once __DIR__ . '/../app/supplier_products.php';
require_once __DIR__ . '/../app/navigation.php';

$actor = ft_current_user();
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$flash = '';
$error = '';
$suppliers = [];
$archivedSuppliers = [];
$supplierEditorId = (int)($_GET['supplier_edit_id'] ?? $_POST['supplier_edit_id'] ?? 0);
$isNewSupplierMode = (isset($_GET['new_supplier']) && $_GET['new_supplier'] === '1')
    || (isset($_POST['new_supplier']) && $_POST['new_supplier'] === '1');
$showArchive = (string)($_GET['show_archive'] ?? $_POST['show_archive'] ?? '') === '1';
$supplierEditor = suppliers_default();

try {
    suppliers_table_ensure($cfg);

    if ($requestMethod === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_supplier') {
            $savedId = suppliers_save($_POST, $actor, $cfg);
            header('Location: suppliers.php?supplier_edit_id=' . urlencode((string)$savedId) . '&supplier_saved=1', true, 303);
            exit;
        }
        if ($action === 'delete_supplier') {
            suppliers_delete((int)($_POST['supplier_id'] ?? 0), $cfg);
            header('Location: suppliers.php?supplier_deleted=1', true, 303);
            exit;
        }
        if ($action === 'archive_supplier') {
            suppliers_set_archived((int)($_POST['supplier_id'] ?? 0), true, $actor, $cfg);
            header('Location: suppliers.php?supplier_archived=1', true, 303);
            exit;
        }
        if ($action === 'restore_supplier') {
            suppliers_set_archived((int)($_POST['supplier_id'] ?? 0), false, $actor, $cfg);
            header('Location: suppliers.php?show_archive=1&supplier_restored=1', true, 303);
            exit;
        }
    }

    if ($supplierEditorId > 0) {
        $supplierEditor = suppliers_get($supplierEditorId, $cfg) ?? $supplierEditor;
    }

    if (isset($_GET['supplier_saved']) && $_GET['supplier_saved'] === '1') {
        $flash = 'Поставщик сохранён.';
    } elseif (isset($_GET['supplier_deleted']) && $_GET['supplier_deleted'] === '1') {
        $flash = 'Поставщик удалён.';
    } elseif (isset($_GET['supplier_archived']) && $_GET['supplier_archived'] === '1') {
        $flash = 'Поставщик перенесён в архив.';
    } elseif (isset($_GET['supplier_restored']) && $_GET['supplier_restored'] === '1') {
        $flash = 'Поставщик возвращён из архива.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if ($requestMethod === 'POST' && (string)($_POST['action'] ?? '') === 'save_supplier') {
        $supplierEditor = array_replace(suppliers_default(), [
            'id' => (int)($_POST['id'] ?? 0),
            'name' => trim((string)($_POST['name'] ?? '')),
            'supplier_code' => suppliers_normalize_code((string)($_POST['supplier_code'] ?? '')),
            'feed_url' => trim((string)($_POST['feed_url'] ?? '')),
            'is_active' => 1,
            'sort_order' => max(1, (int)($_POST['sort_order'] ?? 100)),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);
        $isNewSupplierMode = (int)($supplierEditor['id'] ?? 0) <= 0;
    }
}

if ($error === '' || $requestMethod === 'POST') {
    try {
        $suppliers = suppliers_list(true, $cfg);
        if ($showArchive) {
            $archivedSuppliers = suppliers_archive_list($cfg);
        }
    } catch (Throwable $e) {
        if ($error === '') {
            $error = $e->getMessage();
        }
        $suppliers = [];
    }
}
if (!$suppliers && !$supplierEditorId) {
    $isNewSupplierMode = true;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function supplier_visible_notes($value): string
{
    $notes = trim((string)$value);
    return $notes === 'Создано автоматически из старого профиля фида Price Tool.' ? '' : $notes;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — Поставщики</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f8fc;
      --card: #ffffff;
      --border: #d9e5f2;
      --text: #17233a;
      --muted: #61738d;
      --shadow: 0 18px 40px rgba(27, 57, 90, 0.08);
      --danger: #b42318;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f7fbff 0%, #f4f7fb 100%);
      color: var(--text);
      font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .env-badge {
      position: fixed;
      top: 14px;
      right: 16px;
      z-index: 20;
      padding: 8px 12px;
      border-radius: 999px;
      background: #fff5d6;
      color: #8a5a00;
      border: 1px solid #f2d386;
      font-weight: 700;
      font-size: 13px;
    }
    .topbar, .page {
      max-width: 1280px;
      margin: 0 auto;
      padding-left: 18px;
      padding-right: 18px;
    }
    .topbar {
      padding-top: 28px;
      padding-bottom: 18px;
    }
    .page {
      padding-bottom: 42px;
      display: grid;
      gap: 18px;
    }
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 20px;
      box-shadow: var(--shadow);
      min-width: 0;
    }
    h1, h2, h3 { margin: 0 0 10px; line-height: 1.15; }
    .muted { color: var(--muted); }
    .button-link, button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 14px;
      border-radius: 12px;
      border: 1px solid #0f172a;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-weight: 700;
      cursor: pointer;
      font: inherit;
    }
    .button-link.secondary, button.secondary {
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    button.danger {
      background: #fff;
      border-color: #f2b8b5;
      color: var(--danger);
    }
    .toolbar {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: flex-start;
      flex-wrap: wrap;
    }
    .flash, .error {
      max-width: 1280px;
      margin: 0 auto 14px;
      padding: 12px 16px;
      border-radius: 14px;
      border: 1px solid #b7ebc6;
      background: #f0fdf4;
      color: #166534;
    }
    .error {
      border-color: #fecaca;
      background: #fff1f2;
      color: #b42318;
    }
    .layout {
      display: grid;
      gap: 18px;
      align-items: start;
    }
    .supplier-list {
      display: grid;
      gap: 12px;
    }
    .supplier-row {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px;
      display: grid;
      gap: 10px;
      background: #fff;
    }
      .supplier-row.is-inactive,
      .supplier-row.is-archived {
      background: #f8fafc;
      opacity: .72;
    }
    .supplier-title {
      font-size: 19px;
      font-weight: 800;
    }
    .chip-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      border-radius: 999px;
      padding: 0 10px;
      background: #eef6ff;
      color: #1d4ed8;
      font-size: 13px;
      font-weight: 700;
    }
    .chip.warn {
      background: #fff7ed;
      color: #9a3412;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }
    label {
      display: grid;
      gap: 6px;
      font-weight: 700;
    }
    input, textarea {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 12px;
      color: var(--text);
      font: inherit;
      background: #fff;
    }
    textarea {
      min-height: 110px;
      resize: vertical;
    }
    .checkbox-chip {
      display: inline-flex;
      gap: 8px;
      align-items: center;
      width: fit-content;
      border: 1px solid var(--border);
      border-radius: 999px;
      padding: 8px 12px;
      background: #fff;
      font-weight: 700;
    }
    .checkbox-chip input {
      width: auto;
    }
    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 16px;
    }
    code {
      background: #f1f5f9;
      padding: 2px 5px;
      border-radius: 6px;
    }
    a { color: #0f172a; }
    @media (max-width: 900px) {
      .layout, .form-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <?php if (ft_is_staging_env($cfg)): ?>
    <div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
  <?php endif; ?>

  <div class="topbar">
    <?= ft_top_navigation(['back_href' => 'index.php', 'back_label' => 'Назад', 'active' => 'suppliers']) ?>
    <div class="toolbar">
      <div>
        <h1>Поставщики</h1>
        <div class="muted">Общий справочник источников данных. Price Tool использует поставщика как источник XML для расчёта цен, а Stocks Tool — как источник остатков.</div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="button-link secondary" href="marketplace_connections.php">Подключения</a>
        <a class="button-link secondary" href="xml_feeds.php">XML-фиды</a>
        <a class="button-link secondary" href="suppliers.php?show_archive=1">Архив</a>
      </div>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="page">
    <div class="layout">
      <div class="card">
        <div class="supplier-list">
          <?php if (!$suppliers): ?>
            <div class="muted">Поставщиков пока нет. Создай первого поставщика ниже.</div>
          <?php endif; ?>

          <?php foreach ($suppliers as $supplier): ?>
            <?php
              $supplierId = (int)($supplier['id'] ?? 0);
              $counts = suppliers_reference_counts($supplierId, $cfg);
              $productsCount = supplier_products_count($supplierId, $cfg);
            ?>
            <div class="supplier-row">
              <div class="toolbar">
                <div>
                  <div class="supplier-title"><?= h((string)($supplier['name'] ?? '')) ?></div>
                  <div class="muted">Код поставщика: <code><?= h((string)($supplier['supplier_code'] ?? '')) ?></code></div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                  <a class="button-link secondary" href="supplier_products.php?supplier_id=<?= h((string)$supplierId) ?>">Товары</a>
                  <a class="button-link secondary" href="suppliers.php?supplier_edit_id=<?= h((string)$supplierId) ?>">Редактировать</a>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Перенести поставщика в архив? Данные сохранятся, но он пропадёт из рабочих списков.');">
                    <input type="hidden" name="action" value="archive_supplier">
                    <input type="hidden" name="supplier_id" value="<?= h((string)$supplierId) ?>">
                    <button type="submit" class="secondary">В архив</button>
                  </form>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Удалить этого поставщика?');">
                    <input type="hidden" name="action" value="delete_supplier">
                    <input type="hidden" name="supplier_id" value="<?= h((string)$supplierId) ?>">
                    <button type="submit" class="danger">Удалить</button>
                  </form>
                </div>
              </div>

              <div class="chip-row">
                <span class="chip"><?= h((string)$productsCount) ?> товаров</span>
                <span class="chip"><?= h((string)($counts['price_profiles'] ?? 0)) ?> Price Tool</span>
                <span class="chip"><?= h((string)($counts['stock_profiles'] ?? 0)) ?> Stocks Tool</span>
              </div>

              <div class="muted" style="word-break:break-word;">
                XML: <a href="<?= h((string)($supplier['feed_url'] ?? '')) ?>" target="_blank" rel="noopener"><?= h((string)($supplier['feed_url'] ?? '')) ?></a>
              </div>
              <?php $visibleNotes = supplier_visible_notes($supplier['notes'] ?? ''); ?>
              <?php if ($visibleNotes !== ''): ?>
                <div class="muted"><?= nl2br(h($visibleNotes)) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($showArchive): ?>
        <div class="card">
          <div class="toolbar">
            <div>
              <h2>Архив поставщиков</h2>
              <div class="muted">Архивные поставщики не показываются в рабочих списках, но их данные остаются на месте.</div>
            </div>
            <a class="button-link secondary" href="suppliers.php">Скрыть архив</a>
          </div>
          <div class="supplier-list" style="margin-top:14px;">
            <?php if (!$archivedSuppliers): ?>
              <div class="muted">В архиве пока пусто.</div>
            <?php endif; ?>
            <?php foreach ($archivedSuppliers as $supplier): ?>
              <?php
                $supplierId = (int)($supplier['id'] ?? 0);
                $counts = suppliers_reference_counts($supplierId, $cfg);
                $productsCount = supplier_products_count($supplierId, $cfg);
              ?>
              <div class="supplier-row is-archived">
                <div class="toolbar">
                  <div>
                    <div class="supplier-title"><?= h((string)($supplier['name'] ?? '')) ?></div>
                    <div class="muted">Код поставщика: <code><?= h((string)($supplier['supplier_code'] ?? '')) ?></code></div>
                    <?php if (trim((string)($supplier['archived_at'] ?? '')) !== ''): ?>
                      <div class="muted">В архиве с <?= h((string)$supplier['archived_at']) ?></div>
                    <?php endif; ?>
                  </div>
                  <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a class="button-link secondary" href="supplier_products.php?supplier_id=<?= h((string)$supplierId) ?>">Товары</a>
                    <a class="button-link secondary" href="suppliers.php?show_archive=1&supplier_edit_id=<?= h((string)$supplierId) ?>">Редактировать</a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Вернуть поставщика из архива?');">
                      <input type="hidden" name="action" value="restore_supplier">
                      <input type="hidden" name="supplier_id" value="<?= h((string)$supplierId) ?>">
                      <button type="submit">Вернуть</button>
                    </form>
                  </div>
                </div>

                <div class="chip-row">
                  <span class="chip warn">архив</span>
                  <span class="chip"><?= h((string)$productsCount) ?> товаров</span>
                  <span class="chip"><?= h((string)($counts['price_profiles'] ?? 0)) ?> Price Tool</span>
                  <span class="chip"><?= h((string)($counts['stock_profiles'] ?? 0)) ?> Stocks Tool</span>
                </div>

                <div class="muted" style="word-break:break-word;">
                  XML: <a href="<?= h((string)($supplier['feed_url'] ?? '')) ?>" target="_blank" rel="noopener"><?= h((string)($supplier['feed_url'] ?? '')) ?></a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($isNewSupplierMode || $supplierEditorId > 0): ?>
        <div class="card">
          <h2><?= (int)($supplierEditor['id'] ?? 0) > 0 ? 'Редактировать поставщика' : 'Новый поставщик' ?></h2>
          <form method="post">
            <input type="hidden" name="action" value="save_supplier">
            <input type="hidden" name="id" value="<?= h((string)($supplierEditor['id'] ?? 0)) ?>">
            <?php if ((int)($supplierEditor['id'] ?? 0) <= 0): ?>
              <input type="hidden" name="new_supplier" value="1">
            <?php endif; ?>

            <div class="form-grid">
              <label>
                <span>Название</span>
                <input type="text" name="name" required value="<?= h((string)($supplierEditor['name'] ?? '')) ?>" placeholder="Например: DJI Global">
              </label>
              <label>
                <span>Код поставщика</span>
                <input type="text" name="supplier_code" required value="<?= h((string)($supplierEditor['supplier_code'] ?? '')) ?>" placeholder="Например: DJI">
              </label>
              <label style="grid-column:1 / -1;">
                <span>Ссылка на источник данных</span>
                <input type="url" name="feed_url" required value="<?= h((string)($supplierEditor['feed_url'] ?? '')) ?>" placeholder="https://example.com/feed.xml">
              </label>
              <label>
                <span>Порядок</span>
                <input type="number" min="1" max="9999" name="sort_order" value="<?= h((string)($supplierEditor['sort_order'] ?? 100)) ?>">
              </label>
              <label style="grid-column:1 / -1;">
                <span>Заметки</span>
                <textarea name="notes"><?= h(supplier_visible_notes($supplierEditor['notes'] ?? '')) ?></textarea>
              </label>
            </div>

            <div class="actions">
              <button type="submit">Сохранить поставщика</button>
              <a class="button-link secondary" href="suppliers.php">Отмена</a>
            </div>
          </form>
        </div>
      <?php else: ?>
        <div class="card">
          <h2>Карточка поставщика</h2>
          <div class="muted">Выбери поставщика из списка или создай нового. После сохранения он появится в Price Tool и Stocks Tool.</div>
          <div class="actions">
            <a class="button-link" href="suppliers.php?new_supplier=1">Новый поставщик</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
