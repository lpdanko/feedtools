<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/time_display.php';
require_once __DIR__ . '/../app/marketplace_brand_dictionary.php';
require_once __DIR__ . '/../app/ozon_price_tool.php';
require_once __DIR__ . '/../app/navigation.php';

marketplace_brand_dictionary_tables_ensure();

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function brand_dictionary_source_label(string $source): string
{
    return $source === 'wildberries' ? 'Wildberries' : 'Ozon';
}

function brand_dictionary_connection_label(array $row): string
{
    $title = trim((string)($row['title'] ?? ''));
    $clientId = trim((string)($row['client_id'] ?? ''));
    if ($title === '') {
        $title = brand_dictionary_source_label((string)($row['marketplace'] ?? 'ozon'));
    }
    return $clientId !== '' ? $title . ' · ' . $clientId : $title;
}

function brand_dictionary_default_connection_id(array $connections): int
{
    foreach ($connections as $row) {
        if ((int)($row['is_active'] ?? 0) === 1) {
            return (int)($row['id'] ?? 0);
        }
    }
    $first = $connections[0] ?? null;
    return is_array($first) ? (int)($first['id'] ?? 0) : 0;
}

function brand_dictionary_int_query(string $q): int
{
    return ctype_digit($q) ? (int)$q : 0;
}

function brand_dictionary_table_rows_estimate(string $tableName): int
{
    static $cache = [];
    $tableName = trim($tableName);
    if ($tableName === '') {
        return 0;
    }
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }
    $st = db()->prepare("
        SELECT COALESCE(TABLE_ROWS, 0)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    $st->execute([$tableName]);
    $cache[$tableName] = max(0, (int)($st->fetchColumn() ?: 0));
    return $cache[$tableName];
}

function brand_dictionary_scope_status(string $source): array
{
    if ($source === 'wildberries') {
        $total = (int)db()->query("
            SELECT COUNT(DISTINCT external_id)
            FROM feedtools_taxonomy_categories
            WHERE source = 'wildberries'
              AND external_id LIKE 'wb:subject:%'
        ")->fetchColumn();
        $ok = (int)db()->query("
            SELECT COUNT(DISTINCT subject_id)
            FROM feedtools_marketplace_brand_scope_fetches
            WHERE marketplace = 'wb'
              AND status = 'ok'
              AND subject_id > 0
        ")->fetchColumn();
        $errors = (int)db()->query("
            SELECT COUNT(*)
            FROM feedtools_marketplace_brand_scope_fetches
            WHERE marketplace = 'wb'
              AND status = 'error'
        ")->fetchColumn();
        return ['total' => $total, 'ok' => $ok, 'errors' => $errors];
    }

    $total = (int)db()->query("
        SELECT COUNT(DISTINCT ozon_parent_id)
        FROM feedtools_taxonomy_categories
        WHERE source = 'ozon'
          AND is_leaf = 1
          AND ozon_parent_id > 0
    ")->fetchColumn();
    $ok = (int)db()->query("
        SELECT COUNT(DISTINCT description_category_id)
        FROM feedtools_marketplace_brand_scope_fetches
        WHERE marketplace = 'ozon'
          AND status = 'ok'
          AND description_category_id > 0
          AND type_id = 0
    ")->fetchColumn();
    $errors = (int)db()->query("
        SELECT COUNT(*)
        FROM feedtools_marketplace_brand_scope_fetches
        WHERE marketplace = 'ozon'
          AND status = 'error'
    ")->fetchColumn();
    return ['total' => $total, 'ok' => $ok, 'errors' => $errors];
}

function brand_dictionary_counts(string $source): array
{
    if ($source === 'wildberries') {
        return [
            'brands' => brand_dictionary_table_rows_estimate('feedtools_wb_brands'),
            'links' => brand_dictionary_table_rows_estimate('feedtools_wb_brand_categories'),
            'exact_links' => brand_dictionary_table_rows_estimate('feedtools_wb_brand_categories'),
            'parent_links' => 0,
        ];
    }
    return [
        'brands' => brand_dictionary_table_rows_estimate('feedtools_ozon_brands'),
        'links' => brand_dictionary_table_rows_estimate('feedtools_ozon_brand_categories'),
        'exact_links' => 0,
        'parent_links' => brand_dictionary_table_rows_estimate('feedtools_ozon_brand_categories'),
    ];
}

function brand_dictionary_total_rows_estimate(string $source): int
{
    return $source === 'wildberries'
        ? brand_dictionary_table_rows_estimate('feedtools_wb_brands')
        : brand_dictionary_table_rows_estimate('feedtools_ozon_brands');
}

function brand_dictionary_where_sql(string $source, string $q, array &$params): string
{
    $where = '1=1';
    if ($q === '') {
        return $where;
    }

    $norm = marketplace_brand_dictionary_norm($q);
    $normLike = $norm . '%';
    $brandId = brand_dictionary_int_query($q);
    if ($norm === '' && $brandId <= 0) {
        return '1=0';
    }
    if ($brandId > 0) {
        $where .= ' AND b.brand_id = ?';
        $params[] = $brandId;
    } else {
        $where .= ' AND b.brand_name_norm LIKE ?';
        $params[] = $normLike;
    }
    return $where;
}

function brand_dictionary_rows(string $source, string $q, int $limit, int $offset): array
{
    $params = [];
    $where = brand_dictionary_where_sql($source, $q, $params);
    $limit = max(20, min(200, $limit));
    $offset = max(0, $offset);
    $isIdQuery = brand_dictionary_int_query($q) > 0;

    if ($source === 'wildberries') {
        $indexHint = $isIdQuery ? '' : ' FORCE INDEX (idx_wb_brand_name_norm)';
        $sql = "
            SELECT
                b.brand_id,
                b.brand_name,
                b.brand_name_norm,
                b.fetched_at
            FROM feedtools_wb_brands b{$indexHint}
            WHERE {$where}
            ORDER BY b.brand_name_norm ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
    } else {
        $indexHint = $isIdQuery ? '' : ' FORCE INDEX (idx_ozon_brand_name_norm)';
        $sql = "
            SELECT
                b.brand_id,
                b.brand_name,
                b.brand_name_norm,
                b.fetched_at
            FROM feedtools_ozon_brands b{$indexHint}
            WHERE {$where}
            ORDER BY b.brand_name_norm ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
    }

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];
    if (!$rows) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['brand_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $counts = brand_dictionary_category_counts($source, array_keys($ids));
    foreach ($rows as &$row) {
        $brandId = (int)($row['brand_id'] ?? 0);
        $stat = $counts[$brandId] ?? ['categories_count' => 0, 'links_fetched_at' => null];
        $row['categories_count'] = (int)($stat['categories_count'] ?? 0);
        $row['links_fetched_at'] = $stat['links_fetched_at'] ?? null;
    }
    unset($row);
    return $rows;
}

function brand_dictionary_category_counts(string $source, array $brandIds): array
{
    $brandIds = array_values(array_unique(array_filter(array_map('intval', $brandIds), static fn(int $id): bool => $id > 0)));
    if (!$brandIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($brandIds), '?'));
    if ($source === 'wildberries') {
        $sql = "
            SELECT
                brand_id,
                COUNT(*) AS categories_count,
                MAX(fetched_at) AS links_fetched_at
            FROM feedtools_wb_brand_categories
            WHERE brand_id IN ({$placeholders})
              AND subject_id > 0
            GROUP BY brand_id
        ";
    } else {
        $sql = "
            SELECT
                brand_id,
                COUNT(*) AS categories_count,
                MAX(fetched_at) AS links_fetched_at
            FROM feedtools_ozon_brand_categories
            WHERE brand_id IN ({$placeholders})
              AND description_category_id > 0
              AND type_id = 0
            GROUP BY brand_id
        ";
    }

    $st = db()->prepare($sql);
    $st->execute($brandIds);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $brandId = (int)($row['brand_id'] ?? 0);
        if ($brandId > 0) {
            $out[$brandId] = $row;
        }
    }
    return $out;
}

function brand_dictionary_ozon_second_level_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parts = array_values(array_filter(array_map('trim', explode('>', $value)), static fn(string $part): bool => $part !== ''));
    if (count($parts) >= 2) {
        return $parts[0] . ' > ' . $parts[1];
    }
    return $value;
}

function brand_dictionary_samples(string $source, array $rows, int $limitPerBrand = 6): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['brand_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        return [];
    }

    if ($source === 'wildberries') {
        $sql = "
            SELECT
                c.brand_id,
                c.subject_id,
                c.parent_id,
                c.category_value,
                c.category_value AS category_name
            FROM feedtools_wb_brand_categories c
            WHERE c.brand_id = ?
              AND c.subject_id > 0
            ORDER BY c.brand_id ASC, c.subject_id ASC
            LIMIT " . (int)$limitPerBrand . "
        ";
    } else {
        $sql = "
            SELECT
                c.brand_id,
                c.description_category_id,
                c.type_id,
                c.category_value,
                c.category_value AS category_name
            FROM feedtools_ozon_brand_categories c
            WHERE c.brand_id = ?
              AND c.type_id = 0
            ORDER BY c.brand_id ASC, c.description_category_id ASC
            LIMIT " . (int)$limitPerBrand . "
        ";
    }

    $st = db()->prepare($sql);
    $out = [];
    $ozonParentIds = [];
    $wbSubjectIds = [];
    foreach ($ids as $brandId) {
        $st->execute([$brandId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rowBrandId = (int)($row['brand_id'] ?? 0);
            if ($rowBrandId <= 0) {
                continue;
            }
            if (!isset($out[$rowBrandId])) {
                $out[$rowBrandId] = [];
            }
            if ($source === 'ozon') {
                $parentId = (int)($row['description_category_id'] ?? 0);
                if ($parentId > 0) {
                    $ozonParentIds[$parentId] = true;
                }
            } elseif ($source === 'wildberries') {
                $subjectId = (int)($row['subject_id'] ?? 0);
                if ($subjectId > 0) {
                    $wbSubjectIds[$subjectId] = true;
                }
            }
            $out[$rowBrandId][] = $row;
        }
        $st->closeCursor();
    }
    if ($source === 'ozon' && $ozonParentIds) {
        $idsToLoad = array_keys($ozonParentIds);
        $placeholders = implode(',', array_fill(0, count($idsToLoad), '?'));
        $names = [];
        $nameStmt = db()->prepare("
            SELECT ozon_parent_id, MIN(full_path) AS full_path
            FROM feedtools_taxonomy_categories
            WHERE source = 'ozon'
              AND is_leaf = 1
              AND ozon_parent_id IN ({$placeholders})
            GROUP BY ozon_parent_id
        ");
        $nameStmt->execute($idsToLoad);
        while ($row = $nameStmt->fetch(PDO::FETCH_ASSOC)) {
            $parentId = (int)($row['ozon_parent_id'] ?? 0);
            if ($parentId > 0) {
                $names[$parentId] = brand_dictionary_ozon_second_level_label((string)($row['full_path'] ?? ''));
            }
        }
        foreach ($out as &$brandSamples) {
            foreach ($brandSamples as &$sample) {
                $parentId = (int)($sample['description_category_id'] ?? 0);
                $sample['category_name'] = $names[$parentId] ?? (string)($sample['category_value'] ?? '');
            }
            unset($sample);
        }
        unset($brandSamples);
    }
    if ($source === 'wildberries' && $wbSubjectIds) {
        $subjectIds = array_keys($wbSubjectIds);
        $externalIds = array_map(static fn(int $id): string => 'wb:subject:' . $id, $subjectIds);
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $names = [];
        $nameStmt = db()->prepare("
            SELECT external_id, full_path
            FROM feedtools_taxonomy_categories
            WHERE source = 'wildberries'
              AND external_id IN ({$placeholders})
        ");
        $nameStmt->execute($externalIds);
        while ($row = $nameStmt->fetch(PDO::FETCH_ASSOC)) {
            $externalId = (string)($row['external_id'] ?? '');
            if (preg_match('~^wb:subject:(\d+)$~', $externalId, $m)) {
                $names[(int)$m[1]] = (string)($row['full_path'] ?? '');
            }
        }
        foreach ($out as &$brandSamples) {
            foreach ($brandSamples as &$sample) {
                $subjectId = (int)($sample['subject_id'] ?? 0);
                $sample['category_name'] = $names[$subjectId] ?? (string)($sample['category_value'] ?? '');
            }
            unset($sample);
        }
        unset($brandSamples);
    }
    return $out;
}

function brand_dictionary_page_url(string $source, string $q, int $page): string
{
    return 'brand_dictionary.php?' . http_build_query([
        'source' => $source,
        'q' => $q,
        'page' => max(1, $page),
    ]);
}

$q = trim((string)($_GET['q'] ?? ''));
$source = trim((string)($_GET['source'] ?? 'ozon'));
if (!in_array($source, ['ozon', 'wildberries'], true)) {
    $source = 'ozon';
}
$sourceLabel = brand_dictionary_source_label($source);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$counts = brand_dictionary_counts($source);
$scopeStatus = brand_dictionary_scope_status($source);
$totalRows = brand_dictionary_total_rows_estimate($source);
$rows = brand_dictionary_rows($source, $q, $perPage + 1, $offset);
$hasNextPage = count($rows) > $perPage;
if ($hasNextPage) {
    $rows = array_slice($rows, 0, $perPage);
}
$samples = brand_dictionary_samples($source, $rows);
$pages = max(1, (int)ceil($totalRows / $perPage));
$ozonConnections = ozon_price_connection_list($cfg, 'ozon');
$wbConnections = ozon_price_connection_list($cfg, 'wb');
$defaultOzonConnectionId = brand_dictionary_default_connection_id($ozonConnections);
$defaultWbConnectionId = brand_dictionary_default_connection_id($wbConnections);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Бренды <?= h($sourceLabel) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_time_display_assets() ?>
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1300px;margin:30px auto;padding:0 16px;color:#111827}
    .card{border:1px solid #dbeafe;border-radius:12px;padding:16px;margin-bottom:16px;background:#fff}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    input[type=text],select{padding:10px;border:1px solid #d1d5db;border-radius:10px;font:inherit;background:#fff}
    input[type=text]{min-width:min(100%,380px)}
    button{padding:10px 14px;border-radius:10px;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer;font-weight:700}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;font-size:13px;vertical-align:top}
    th{color:#374151;background:#f8fafc}
    a{color:#111827}
    .muted{color:#6b7280}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border:1px solid #dbeafe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700}
    .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px}
    .stat{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f8fafc}
    .stat b{display:block;font-size:22px;margin-bottom:2px}
    .sample-list{display:flex;flex-direction:column;gap:4px}
    .sample-item{line-height:1.25}
    .pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:14px}
    .pager a,.pager span{padding:8px 12px;border:1px solid #d1d5db;border-radius:10px;text-decoration:none}
    .pager span{color:#6b7280;background:#f9fafb}
    .env-badge{position:fixed;top:14px;right:16px;z-index:1000;display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;border:1px solid #f59e0b;background:rgba(255,251,235,.97);color:#92400e;box-shadow:0 12px 28px rgba(146,64,14,.14);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    .brand-sync-card{background:#f8fbff}
    .brand-sync-title{font-size:18px;font-weight:800;margin:0 0 10px}
    .brand-sync-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .brand-sync-form{display:grid;gap:10px;padding:12px;border:1px solid #dbeafe;border-radius:12px;background:#fff}
    .brand-sync-form-head{display:flex;gap:10px;align-items:center;justify-content:space-between}
    .brand-sync-form-title{font-weight:800}
    .brand-sync-connection{width:min(100%,320px);min-width:0;padding:8px 10px}
    .brand-sync-row{display:grid;grid-template-columns:minmax(0,1fr) 130px;gap:8px;align-items:start}
    .supplier-brand-category-picker{position:relative;min-width:0}
    .supplier-bulk-category-button{width:100%;min-height:42px;padding:10px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#111827;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .supplier-brand-category-form .btn-secondary,.brand-sync-row .btn-secondary{min-height:42px;border:0;background:linear-gradient(135deg,#2563eb,#0f766e);color:#fff}
    .supplier-brand-category-form .btn-secondary:disabled,.brand-sync-row .btn-secondary:disabled{opacity:.45;cursor:not-allowed}
    .supplier-bulk-category-panel{display:none;z-index:4000;padding:10px;border:1px solid #bfdbfe;border-radius:14px;background:#fff;box-shadow:0 24px 60px rgba(15,23,42,.18);overflow:auto}
    .supplier-bulk-category-panel.open{display:block}
    .supplier-brand-category-search{width:100%;min-width:0;box-sizing:border-box;margin-bottom:8px}
    .supplier-brand-category-results{display:grid;gap:4px}
    .supplier-brand-category-actions{position:sticky;top:-10px;z-index:2;display:flex;gap:8px;padding:6px 0 10px;margin-bottom:4px;border-bottom:1px solid #e5e7eb;background:#fff}
    .supplier-brand-category-action{padding:8px 10px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:800}
    .supplier-brand-category-action.secondary{background:#fff;color:#64748b}
    .supplier-brand-category-option{width:100%;display:grid;grid-template-columns:20px minmax(0,1fr);gap:8px;align-items:start;padding:8px 10px;border:0;border-radius:10px;background:transparent;color:#111827;text-align:left;cursor:pointer;font:inherit}
    .supplier-brand-category-option:hover{background:#eef6ff}
    .supplier-brand-category-option.is-selected{background:#ecfdf5;box-shadow:inset 0 0 0 1px #86efac}
    .supplier-brand-category-option input{width:18px;height:18px;margin:1px 0 0}
    .supplier-brand-category-option-title{display:block;font-weight:800;line-height:1.25}
    .supplier-brand-category-option-meta{display:block;margin-top:2px;color:#6b7280;font-size:12px;line-height:1.25}
    @media (max-width:900px){.stat-grid{grid-template-columns:1fr 1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
    @media (max-width:900px){.brand-sync-grid{grid-template-columns:1fr}.brand-sync-row{grid-template-columns:1fr}.brand-sync-form-head{align-items:stretch;flex-direction:column}}
  </style>
</head>
<body>
<?php if (ft_is_staging_env($cfg)): ?>
<div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
<?php endif; ?>

<?= ft_top_navigation([
  'back_href' => 'index.php',
  'back_label' => 'Назад',
  'links' => [
    ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
    ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
    ['key' => 'connections', 'label' => 'Подключения', 'href' => 'marketplace_connections.php'],
    ['key' => 'taxonomy', 'label' => 'Категории ' . $sourceLabel, 'href' => 'taxonomy/index.php?source=' . urlencode($source)],
  ],
]) ?>

<h1>Бренды <?= h($sourceLabel) ?></h1>

<div class="card">
  <form method="get" class="row">
    <select name="source" onchange="this.form.submit()">
      <option value="ozon" <?= $source === 'ozon' ? 'selected' : '' ?>>Ozon</option>
      <option value="wildberries" <?= $source === 'wildberries' ? 'selected' : '' ?>>Wildberries</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Поиск по началу названия бренда или id">
    <button type="submit">Найти</button>
    <?php if ($q !== ''): ?>
      <a class="muted" href="brand_dictionary.php?source=<?= h($source) ?>">Сбросить</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="stat-grid">
    <div class="stat">
      <b>≈ <?= h($counts['brands']) ?></b>
      <span class="muted">брендов в справочнике</span>
    </div>
    <div class="stat">
      <b>≈ <?= h($counts['links']) ?></b>
      <span class="muted">привязок бренд-категория</span>
      <?php if ($source === 'ozon'): ?>
        <div class="muted" style="font-size:12px;margin-top:4px;">категории второго уровня: ≈ <?= h($counts['parent_links']) ?></div>
      <?php endif; ?>
    </div>
    <div class="stat">
      <b><?= h($scopeStatus['ok']) ?> из <?= h($scopeStatus['total']) ?></b>
      <span class="muted"><?= $source === 'ozon' ? 'родительских категорий с брендами' : 'категорий с загруженными брендами' ?></span>
    </div>
    <div class="stat">
      <b><?= h($scopeStatus['errors']) ?></b>
      <span class="muted">ошибок загрузки категорий</span>
    </div>
  </div>
</div>

<div class="card brand-sync-card" data-brand-category-sync>
  <h2 class="brand-sync-title">Бренды выбранных категорий</h2>
  <div class="brand-sync-grid">
    <form method="post" action="run_op.php" class="brand-sync-form" data-brand-category-sync-form data-brand-category-source="ozon">
      <input type="hidden" name="dataset_id" value="0">
      <input type="hidden" name="op_type" value="supplier_sync_ozon_brands_category">
      <input type="hidden" name="category_value" value="" data-brand-category-value>
      <input type="hidden" name="category_values_json" value="[]" data-brand-category-values-json>
      <input type="hidden" name="force_refresh" value="1">
      <div class="brand-sync-form-head">
        <div class="brand-sync-form-title">Ozon</div>
        <select name="ozon_connection_id" class="brand-sync-connection">
          <?php if (!$ozonConnections): ?>
            <option value="0">Нет подключения Ozon</option>
          <?php else: ?>
            <?php foreach ($ozonConnections as $connection): ?>
              <?php $connectionId = (int)($connection['id'] ?? 0); ?>
              <option value="<?= h($connectionId) ?>" <?= $connectionId === $defaultOzonConnectionId ? 'selected' : '' ?>>
                <?= h(brand_dictionary_connection_label($connection)) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="brand-sync-row">
        <div class="supplier-brand-category-picker" data-brand-category-source="ozon">
          <button type="button" class="supplier-bulk-category-button" data-brand-category-toggle>Выбрать категории Ozon</button>
          <div class="supplier-bulk-category-panel">
            <input class="supplier-brand-category-search" type="text" placeholder="поиск категории...">
            <div class="supplier-brand-category-results"></div>
          </div>
        </div>
        <button type="submit" class="btn-secondary" data-brand-category-submit disabled>Обновить</button>
      </div>
    </form>

    <form method="post" action="run_op.php" class="brand-sync-form" data-brand-category-sync-form data-brand-category-source="wildberries">
      <input type="hidden" name="dataset_id" value="0">
      <input type="hidden" name="op_type" value="supplier_sync_wb_brands_category">
      <input type="hidden" name="category_value" value="" data-brand-category-value>
      <input type="hidden" name="category_values_json" value="[]" data-brand-category-values-json>
      <input type="hidden" name="force_refresh" value="1">
      <div class="brand-sync-form-head">
        <div class="brand-sync-form-title">Wildberries</div>
        <select name="wb_connection_id" class="brand-sync-connection">
          <?php if (!$wbConnections): ?>
            <option value="0">Нет подключения WB</option>
          <?php else: ?>
            <?php foreach ($wbConnections as $connection): ?>
              <?php $connectionId = (int)($connection['id'] ?? 0); ?>
              <option value="<?= h($connectionId) ?>" <?= $connectionId === $defaultWbConnectionId ? 'selected' : '' ?>>
                <?= h(brand_dictionary_connection_label($connection)) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="brand-sync-row">
        <div class="supplier-brand-category-picker" data-brand-category-source="wildberries">
          <button type="button" class="supplier-bulk-category-button" data-brand-category-toggle>Выбрать категории WB</button>
          <div class="supplier-bulk-category-panel">
            <input class="supplier-brand-category-search" type="text" placeholder="поиск категории...">
            <div class="supplier-brand-category-results"></div>
          </div>
        </div>
        <button type="submit" class="btn-secondary" data-brand-category-submit disabled>Обновить</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">Бренды не найдены.</p>
  <?php else: ?>
    <div class="row" style="justify-content:space-between;margin-bottom:12px;">
      <div class="muted">
        <?php if ($q === ''): ?>
          Брендов в справочнике: <b>≈ <?= h($totalRows) ?></b>
          <span class="pill">страница <?= h($page) ?> из ≈ <?= h($pages) ?></span>
        <?php else: ?>
          Показано результатов: <b><?= h(count($rows)) ?></b>
          <span class="pill">страница <?= h($page) ?></span>
        <?php endif; ?>
      </div>
      <div class="muted">Сортировка: по названию бренда</div>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:120px;">Маркетплейс</th>
          <th>Бренд</th>
          <th style="width:120px;">ID бренда</th>
          <th style="width:170px;">Категорий</th>
          <th>Примеры категорий</th>
          <th style="width:170px;">Обновлено</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $brandId = (int)($row['brand_id'] ?? 0);
            $items = $samples[$brandId] ?? [];
            $categoriesCount = (int)($row['categories_count'] ?? 0);
          ?>
          <tr>
            <td><?= h($sourceLabel) ?></td>
            <td>
              <b><?= h($row['brand_name'] ?? '') ?></b>
              <div class="muted" style="font-size:12px;margin-top:3px;"><?= h($row['brand_name_norm'] ?? '') ?></div>
            </td>
            <td><?= h($brandId) ?></td>
            <td>
              <span class="pill"><?= h($categoriesCount) ?></span>
            </td>
            <td>
              <?php if (!$items): ?>
                <span class="muted">нет привязанных категорий</span>
              <?php else: ?>
                <div class="sample-list">
                  <?php foreach ($items as $item): ?>
                    <div class="sample-item"><?= h($item['category_name'] ?? $item['category_value'] ?? '') ?></div>
                  <?php endforeach; ?>
                  <?php if ($categoriesCount > count($items)): ?>
                    <div class="muted">ещё <?= h($categoriesCount - count($items)) ?></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($row['links_fetched_at'])): ?>
                <?= ft_local_datetime_html((string)$row['links_fetched_at'], ['show_seconds' => true]) ?>
              <?php elseif (!empty($row['fetched_at'])): ?>
                <?= ft_local_datetime_html((string)$row['fetched_at'], ['show_seconds' => true]) ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pager">
      <?php if ($page > 1): ?>
        <a href="<?= h(brand_dictionary_page_url($source, $q, $page - 1)) ?>">Назад</a>
      <?php else: ?>
        <span>Назад</span>
      <?php endif; ?>
      <?php if ($hasNextPage): ?>
        <a href="<?= h(brand_dictionary_page_url($source, $q, $page + 1)) ?>">Вперёд</a>
      <?php else: ?>
        <span>Вперёд</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<script>
(function() {
  function categorySourceLabel(source) {
    return source === 'wildberries' ? 'WB' : 'Ozon';
  }

  function closeBrandCategoryPanels(exceptPanel) {
    document.querySelectorAll('.supplier-bulk-category-panel.open').forEach(function(panel) {
      if (exceptPanel && panel === exceptPanel) return;
      panel.classList.remove('open');
      panel.style.position = '';
      panel.style.left = '';
      panel.style.top = '';
      panel.style.width = '';
      panel.style.maxHeight = '';
    });
  }

  function positionBrandCategoryPanel(root) {
    const panel = root ? root.querySelector('.supplier-bulk-category-panel') : null;
    const button = root ? root.querySelector('[data-brand-category-toggle]') : null;
    if (!panel || !button || !panel.classList.contains('open')) return;
    const rect = button.getBoundingClientRect();
    const gap = 6;
    const width = Math.min(Math.max(rect.width, 420), Math.max(320, window.innerWidth - 24));
    let left = rect.left;
    if (left + width > window.innerWidth - 12) left = window.innerWidth - width - 12;
    if (left < 12) left = 12;
    let maxHeight = Math.max(220, window.innerHeight - rect.bottom - 24);
    let top = rect.bottom + gap;
    if (maxHeight < 260 && rect.top > window.innerHeight - rect.bottom) {
      maxHeight = Math.max(220, rect.top - 24);
      top = Math.max(12, rect.top - maxHeight - gap);
    }
    panel.style.position = 'fixed';
    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
    panel.style.width = width + 'px';
    panel.style.maxHeight = maxHeight + 'px';
  }

  function repositionOpenBrandCategoryPanels() {
    document.querySelectorAll('.supplier-bulk-category-panel.open').forEach(function(panel) {
      const root = panel.closest('.supplier-brand-category-picker');
      positionBrandCategoryPanel(root);
    });
  }

  function setBrandCategoryStatus(root, message, isError) {
    const results = root ? root.querySelector('.supplier-brand-category-results') : null;
    if (!results) return;
    results.innerHTML = '';
    const box = document.createElement('div');
    box.className = 'muted';
    box.style.fontSize = '13px';
    box.style.color = isError ? '#b91c1c' : '';
    box.textContent = message || '';
    results.appendChild(box);
  }

  function getBrandCategorySelection(root) {
    if (!root) return new Map();
    if (!root._brandCategorySelection) {
      root._brandCategorySelection = new Map();
      const form = root.closest('[data-brand-category-sync-form]');
      const jsonInput = form ? form.querySelector('[data-brand-category-values-json]') : null;
      const raw = jsonInput ? String(jsonInput.value || '') : '';
      try {
        const decoded = JSON.parse(raw || '[]');
        if (Array.isArray(decoded)) {
          decoded.forEach(function(item) {
            if (!item) return;
            const value = String((typeof item === 'object' ? (item.value || item.category_value) : item) || '').trim();
            if (!value) return;
            root._brandCategorySelection.set(value, {
              value: value,
              label: String(typeof item === 'object' ? (item.label || value) : value),
              kind: String(typeof item === 'object' ? (item.kind || '') : ''),
              category_value: String(typeof item === 'object' ? (item.category_value || '') : ''),
              children_count: Math.max(0, parseInt(String(typeof item === 'object' ? (item.children_count || '0') : '0'), 10) || 0)
            });
          });
        }
      } catch (e) {}
    }
    return root._brandCategorySelection;
  }

  function renderBrandCategoryOptions(root, options) {
    const results = root ? root.querySelector('.supplier-brand-category-results') : null;
    if (!results) return;
    results.innerHTML = '';
    if (!options.length) {
      const empty = document.createElement('div');
      empty.className = 'muted';
      empty.style.fontSize = '13px';
      empty.textContent = 'Ничего не найдено';
      results.appendChild(empty);
      return;
    }
    const selected = getBrandCategorySelection(root);
    const actions = document.createElement('div');
    actions.className = 'supplier-brand-category-actions';
    const selectAll = document.createElement('button');
    selectAll.type = 'button';
    selectAll.className = 'supplier-brand-category-action';
    selectAll.dataset.brandCategorySelectVisible = '1';
    selectAll.textContent = 'Выбрать все категории';
    const clearAll = document.createElement('button');
    clearAll.type = 'button';
    clearAll.className = 'supplier-brand-category-action secondary';
    clearAll.dataset.brandCategoryClear = '1';
    clearAll.textContent = 'Снять выбор';
    actions.appendChild(selectAll);
    actions.appendChild(clearAll);
    results.appendChild(actions);
    options.forEach(function(opt) {
      const value = String(opt.value || '');
      const label = String(opt.label || value);
      if (!value) return;
      const kind = String(opt.kind || (value.indexOf('node:') === 0 ? 'parent' : 'leaf'));
      const categoryValue = String(opt.category_value || '');
      const childrenCount = Math.max(0, parseInt(String(opt.children_count || '0'), 10) || 0);

      const row = document.createElement('label');
      row.className = 'supplier-brand-category-option';
      if (selected.has(value)) row.classList.add('is-selected');
      row.dataset.brandCategoryValue = value;
      row.dataset.brandCategoryLabel = label;
      row.dataset.brandCategoryKind = kind;
      row.dataset.brandCategoryValueRaw = categoryValue;
      row.dataset.brandCategoryChildren = String(childrenCount);

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.checked = selected.has(value);
      checkbox.setAttribute('aria-label', label);

      const text = document.createElement('span');
      const title = document.createElement('span');
      title.className = 'supplier-brand-category-option-title';
      title.textContent = label;
      const meta = document.createElement('span');
      meta.className = 'supplier-brand-category-option-meta';
      meta.textContent = kind === 'parent'
        ? 'ветка категорий, дочерних: ' + childrenCount
        : 'категория';
      text.appendChild(title);
      text.appendChild(meta);

      row.appendChild(checkbox);
      row.appendChild(text);
      results.appendChild(row);
    });
  }

  async function loadBrandCategoryOptions(root, query) {
    const source = root ? String(root.dataset.brandCategorySource || '') : '';
    if (!source) return;
    const seq = String(Date.now()) + ':' + String(Math.random());
    root.dataset.loadSeq = seq;
    setBrandCategoryStatus(root, 'Загружаем категории ' + categorySourceLabel(source) + '...', false);
    const q = String(query || '').trim();
    const limit = q ? 160 : 900;
    try {
      let url = 'taxonomy/options.php?source=' + encodeURIComponent(source) +
        '&q=' + encodeURIComponent(q) +
        '&limit=' + encodeURIComponent(String(limit)) +
        '&include_parents=1&max_depth=2&include_leaves=0';
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json().catch(function() { return null; });
      if (root.dataset.loadSeq !== seq) return;
      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.error) ? data.error : 'Не удалось загрузить категории.');
      }
      renderBrandCategoryOptions(root, Array.isArray(data.options) ? data.options : []);
      positionBrandCategoryPanel(root);
    } catch (e) {
      if (root.dataset.loadSeq !== seq) return;
      setBrandCategoryStatus(root, e.message || 'Не удалось загрузить категории.', true);
    }
  }

  function openBrandCategoryPicker(root) {
    if (!root) return;
    const panel = root.querySelector('.supplier-bulk-category-panel');
    const button = root.querySelector('[data-brand-category-toggle]');
    const search = root.querySelector('.supplier-brand-category-search');
    if (!panel || !button || !search) return;
    if (panel.classList.contains('open')) {
      closeBrandCategoryPanels();
      return;
    }
    closeBrandCategoryPanels(panel);
    panel.classList.add('open');
    positionBrandCategoryPanel(root);
    loadBrandCategoryOptions(root, search.value);
    search.focus();
  }

  function brandCategoryShortLabel(label, value) {
    let text = String(label || '').trim();
    const codeMatch = text.match(/\s*\(([^()]*)\)\s*$/);
    if (codeMatch) {
      text = text.replace(/\s*\([^()]*\)\s*$/, '').trim();
    }
    const parts = text.split('>').map(function(part) { return part.trim(); }).filter(Boolean);
    let short = parts.length ? parts[parts.length - 1] : (text || String(value || '').trim());
    if (codeMatch && codeMatch[1]) short += ' (' + codeMatch[1] + ')';
    return short.length > 68 ? short.slice(0, 65) + '...' : short;
  }

  function updateBrandCategoryForm(root) {
    const form = root ? root.closest('[data-brand-category-sync-form]') : null;
    if (!root || !form) return;
    const items = Array.from(getBrandCategorySelection(root).values());
    const input = form.querySelector('[data-brand-category-value]');
    const jsonInput = form.querySelector('[data-brand-category-values-json]');
    const submit = form.querySelector('[data-brand-category-submit]');
    const toggle = root.querySelector('[data-brand-category-toggle]');
    const first = items[0] || null;
    if (input) input.value = first ? String(first.category_value || first.value || '') : '';
    if (jsonInput) jsonInput.value = JSON.stringify(items);
    if (submit) submit.disabled = items.length === 0;
    if (toggle) {
      const source = String(root.dataset.brandCategorySource || '');
      if (items.length === 0) {
        toggle.textContent = source === 'ozon' ? 'Выбрать категории Ozon' : 'Выбрать категории WB';
        toggle.title = '';
      } else if (items.length === 1) {
        toggle.textContent = brandCategoryShortLabel(items[0].label, items[0].value);
        toggle.title = items[0].label || items[0].value || '';
      } else {
        toggle.textContent = 'Выбрано категорий: ' + items.length;
        toggle.title = items.slice(0, 30).map(function(item) { return item.label || item.value; }).join('\n') + (items.length > 30 ? '\n...' : '');
      }
    }
  }

  function toggleBrandCategoryOption(option) {
    const root = option ? option.closest('.supplier-brand-category-picker') : null;
    if (!root) return;
    const selected = getBrandCategorySelection(root);
    const value = String(option.dataset.brandCategoryValue || '').trim();
    if (!value) return;
    if (selected.has(value)) {
      selected.delete(value);
      option.classList.remove('is-selected');
    } else {
      selected.set(value, {
        value: value,
        label: String(option.dataset.brandCategoryLabel || option.textContent || value).trim(),
        kind: String(option.dataset.brandCategoryKind || ''),
        category_value: String(option.dataset.brandCategoryValueRaw || ''),
        children_count: Math.max(0, parseInt(String(option.dataset.brandCategoryChildren || '0'), 10) || 0)
      });
      option.classList.add('is-selected');
    }
    const checkbox = option.querySelector('input[type="checkbox"]');
    if (checkbox) checkbox.checked = selected.has(value);
    updateBrandCategoryForm(root);
  }

  function selectVisibleBrandCategoryOptions(root) {
    if (!root) return;
    const selected = getBrandCategorySelection(root);
    root.querySelectorAll('.supplier-brand-category-option[data-brand-category-value]').forEach(function(option) {
      const value = String(option.dataset.brandCategoryValue || '').trim();
      if (!value) return;
      selected.set(value, {
        value: value,
        label: String(option.dataset.brandCategoryLabel || option.textContent || value).trim(),
        kind: String(option.dataset.brandCategoryKind || ''),
        category_value: String(option.dataset.brandCategoryValueRaw || ''),
        children_count: Math.max(0, parseInt(String(option.dataset.brandCategoryChildren || '0'), 10) || 0)
      });
      option.classList.add('is-selected');
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = true;
    });
    updateBrandCategoryForm(root);
  }

  function clearBrandCategorySelection(root) {
    if (!root) return;
    getBrandCategorySelection(root).clear();
    root.querySelectorAll('.supplier-brand-category-option').forEach(function(option) {
      option.classList.remove('is-selected');
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = false;
    });
    updateBrandCategoryForm(root);
  }

  document.querySelectorAll('[data-brand-category-sync-form]').forEach(function(form) {
    const root = form.querySelector('.supplier-brand-category-picker');
    if (root) updateBrandCategoryForm(root);
    form.addEventListener('submit', function(e) {
      const picker = form.querySelector('.supplier-brand-category-picker');
      if (picker) updateBrandCategoryForm(picker);
      const jsonInput = form.querySelector('[data-brand-category-values-json]');
      let selected = [];
      try {
        selected = JSON.parse(String(jsonInput ? jsonInput.value : '[]') || '[]');
      } catch (err) {
        selected = [];
      }
      if (!Array.isArray(selected) || selected.length === 0) {
        e.preventDefault();
        e.stopImmediatePropagation();
        const source = String(form.dataset.brandCategorySource || '');
        alert('Выбери одну или несколько категорий ' + categorySourceLabel(source) + '.');
      }
    }, true);
  });

  document.addEventListener('input', function(e) {
    const search = e.target && e.target.matches ? (e.target.matches('.supplier-brand-category-search') ? e.target : null) : null;
    if (!search) return;
    const root = search.closest('.supplier-brand-category-picker');
    if (!root) return;
    if (root._searchTimer) window.clearTimeout(root._searchTimer);
    root._searchTimer = window.setTimeout(function() {
      loadBrandCategoryOptions(root, search.value);
    }, 180);
  });

  document.addEventListener('click', function(e) {
    const toggle = e.target && e.target.closest ? e.target.closest('[data-brand-category-toggle]') : null;
    if (toggle) {
      e.preventDefault();
      openBrandCategoryPicker(toggle.closest('.supplier-brand-category-picker'));
      return;
    }
    const option = e.target && e.target.closest ? e.target.closest('.supplier-brand-category-option') : null;
    if (option) {
      e.preventDefault();
      toggleBrandCategoryOption(option);
      return;
    }
    const selectVisible = e.target && e.target.closest ? e.target.closest('[data-brand-category-select-visible]') : null;
    if (selectVisible) {
      e.preventDefault();
      selectVisibleBrandCategoryOptions(selectVisible.closest('.supplier-brand-category-picker'));
      return;
    }
    const clearSelection = e.target && e.target.closest ? e.target.closest('[data-brand-category-clear]') : null;
    if (clearSelection) {
      e.preventDefault();
      clearBrandCategorySelection(clearSelection.closest('.supplier-brand-category-picker'));
      return;
    }
    if (e.target && e.target.closest && e.target.closest('.supplier-brand-category-picker')) {
      return;
    }
    closeBrandCategoryPanels();
  });

  window.addEventListener('resize', repositionOpenBrandCategoryPanels);
  window.addEventListener('scroll', repositionOpenBrandCategoryPanels, true);
})();
</script>
</body>
</html>
