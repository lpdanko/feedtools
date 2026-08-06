<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/navigation.php';
require_once __DIR__ . '/../../app/taxonomy/GlobalAttributeExclusions.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function lines_to_list(string $s): array {
  return taxonomy_global_exclusions_normalize(preg_split('~\R~u', $s) ?: []);
}
function list_to_lines(array $a): string {
  return implode("\n", taxonomy_global_exclusions_normalize($a));
}

$q = trim((string)($_GET['q'] ?? ''));
$leafOnly = isset($_GET['leaf']) ? (int)$_GET['leaf'] : 1;
$source = trim((string)($_GET['source'] ?? 'ozon'));
if (!in_array($source, ['ozon', 'wildberries'], true)) $source = 'ozon';
$sourceLabel = $source === 'wildberries' ? 'Wildberries' : 'Ozon';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postSource = trim((string)($_POST['source'] ?? $source));
  if (!in_array($postSource, ['ozon', 'wildberries'], true)) {
    $postSource = 'ozon';
  }
  $source = $postSource;
  $sourceLabel = $source === 'wildberries' ? 'Wildberries' : 'Ozon';
  try {
    $names = lines_to_list((string)($_POST['exclude_attrs'] ?? ''));
    taxonomy_save_global_exclude_attribute_names($source, $names);
    $msg = 'Общий список исключений сохранён';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$globalExcludeNames = taxonomy_get_global_exclude_attribute_names($source);

$where = "source=?";
$params = [$source];

if ($leafOnly === 1) $where .= " AND is_leaf=1";

if ($q !== '') {
  if ($source === 'ozon' && ctype_digit($q)) {
    $where .= " AND (ozon_leaf_id = ? OR ozon_parent_id = ?)";
    $params[] = (int)$q;
    $params[] = (int)$q;
  } elseif ($source === 'wildberries' && ctype_digit($q)) {
    $where .= " AND (external_id = ? OR external_id = ?)";
    $params[] = 'wb:parent:' . $q;
    $params[] = 'wb:subject:' . $q;
  } else {
    $where .= " AND (name LIKE ? OR full_path LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
  }
}

$sql = "SELECT id, level, name, full_path, is_leaf, ozon_parent_id, ozon_leaf_id, meta_json
        FROM feedtools_taxonomy_categories
        WHERE {$where}
        ORDER BY full_path ASC, name ASC, id ASC
        LIMIT 200";

$countSql = "SELECT COUNT(*)
             FROM feedtools_taxonomy_categories
             WHERE {$where}";

$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function meta_empty(string $source, $metaJson): bool {
  if (!$metaJson) return true;
  $m = json_decode((string)$metaJson, true);
  if (!is_array($m)) return true;
  $desc = trim((string)($m['description'] ?? ''));
  $typ  = trim((string)($m['typical_goods'] ?? ''));
  $feat = trim((string)($m['features'] ?? ''));
  $req  = ($source === 'wildberries') ? ($m['wb_required_attributes'] ?? []) : ($m['ozon_required_attributes'] ?? []);
  $kw   = $m['keywords'] ?? [];
  return $desc==='' && $typ==='' && $feat==='' && empty($req) && empty($kw);
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title><?= h($sourceLabel) ?> taxonomy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1300px;margin:30px auto;padding:0 16px}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
    input[type=text]{padding:10px;border:1px solid #e5e7eb;border-radius:10px;min-width:340px}
    textarea{width:100%;min-height:150px;padding:10px;border:1px solid #e5e7eb;border-radius:10px;font-family:inherit}
    button{padding:10px 14px;border-radius:10px;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;font-size:13px;vertical-align:top}
    .muted{color:#6b7280}
    .ok{color:#166534}
    .err{color:#b91c1c}
    .pill{display:inline-block;padding:2px 8px;border:1px solid #e5e7eb;border-radius:999px;font-size:12px}
    a{color:#111827}
    .env-badge{position:fixed;top:14px;right:16px;z-index:1000;display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;border:1px solid #f59e0b;background:rgba(255,251,235,.97);color:#92400e;box-shadow:0 12px 28px rgba(146,64,14,.14);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
  </style>
</head>
<body>
<?php if (ft_is_staging_env($cfg)): ?>
<div class="env-badge"><?= h(ft_env_badge_label($cfg)) ?> version</div>
<?php endif; ?>
<?= ft_top_navigation([
  'back_href' => '../index.php',
  'back_label' => 'Назад',
  'links' => [
    ['key' => 'home', 'label' => 'Главная', 'href' => '../index.php'],
    ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => '../suppliers.php'],
    ['key' => 'connections', 'label' => 'Подключения', 'href' => '../marketplace_connections.php'],
    ['key' => 'xml', 'label' => 'XML-фиды', 'href' => '../xml_feeds.php'],
  ],
]) ?>
<h1>Категории <?= h($sourceLabel) ?></h1>

<div class="card">
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="source" value="<?= h($source) ?>">
    <input type="text" name="q" value="<?=h($q)?>" placeholder="<?= $source === 'wildberries' ? 'Поиск: путь/имя или wb_category' : 'Поиск: путь/имя или число (leaf_id/parent_id)' ?>">
    <label class="muted"><input type="checkbox" name="leaf" value="1" <?= $leafOnly===1?'checked':'' ?>> только листовые</label>
    <button type="submit">Найти</button>
    <span class="muted"><a href="import.php?source=<?= h($source) ?>">Импорт</a></span>
    <span class="muted"><a href="../brand_dictionary.php?source=<?= h($source) ?>">Бренды <?= h($sourceLabel) ?></a></span>
    <?php if ($source === 'ozon'): ?>
      <span class="muted"><a href="index.php?source=wildberries">Wildberries</a></span>
    <?php else: ?>
      <span class="muted"><a href="index.php?source=ozon">Ozon</a></span>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="source" value="<?= h($source) ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
      <div>
        <h2 style="margin:0;font-size:20px;">Общий список исключаемых характеристик</h2>
        <p class="muted" style="margin:6px 0 0 0;">Этот список применяется сразу ко всем категориям <?= h($sourceLabel) ?> и не зависит от отдельной карточки категории.</p>
      </div>
      <button type="submit">Сохранить список</button>
    </div>
    <textarea name="exclude_attrs" placeholder="По одной характеристике в строке"><?= h(list_to_lines($globalExcludeNames)) ?></textarea>
    <p class="muted" style="margin:8px 0 0 0;">
      Сейчас в списке: <b><?= h(count($globalExcludeNames)) ?></b>.
      <?php if ($source === 'ozon'): ?>
        Список был первоначально взят из глобального Ozon-конфига и теперь редактируется здесь.
      <?php else: ?>
        Список будет использоваться во всех WB-категориях и операциях заполнения.
      <?php endif; ?>
    </p>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">Ничего не найдено.</p>
  <?php else: ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
      <div class="muted">
        Категорий в списке: <b><?= h($totalRows) ?></b>
        <?php if ($totalRows > count($rows)): ?>
          <span class="pill">показаны первые <?= h(count($rows)) ?></span>
        <?php endif; ?>
      </div>
      <div class="muted">Сортировка: по названию / пути</div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Путь / название</th><th>Level</th><th><?= $source === 'wildberries' ? 'wb_parent' : 'ozon_parent' ?></th><th><?= $source === 'wildberries' ? 'wb_category' : 'ozon_leaf' ?></th><th>Meta</th><th>ID</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $meta = json_decode((string)($r['meta_json'] ?? ''), true);
            if (!is_array($meta)) $meta = [];
            $wbParentId = $meta['raw']['wb_parent_id'] ?? '';
            $wbSubjectId = $meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? '');
          ?>
          <tr>
            <td><?=h($r['full_path'])?></td>
            <td><?=h($r['level'])?></td>
            <td><?=h($source === 'wildberries' ? $wbParentId : ($r['ozon_parent_id'] ?? ''))?></td>
            <td><?=h($source === 'wildberries' ? $wbSubjectId : ($r['ozon_leaf_id'] ?? ''))?></td>
            <td><?= meta_empty($source, $r['meta_json']) ? '<span class="muted">пусто</span>' : '<span class="pill">заполнено</span>' ?></td>
            <td><?=h($r['id'])?></td>
            <td><a href="edit.php?id=<?=h($r['id'])?>">Редактировать</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
