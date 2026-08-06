<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/navigation.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$msg = '';
$err = '';

// --- handle actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'add') {
      $attr = trim((string)($_POST['attr_name'] ?? ''));
      $old  = trim((string)($_POST['old_value'] ?? ''));
      $new  = trim((string)($_POST['new_value'] ?? ''));
      $act  = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

      if ($attr === '' || $old === '' || $new === '') {
        throw new RuntimeException('Заполни: характеристика, старое значение, новое значение.');
      }

      $sql = "INSERT INTO feedtools_param_value_map(attr_name, old_value, new_value, is_active)
              VALUES(?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE new_value=VALUES(new_value), is_active=VALUES(is_active)";
      $st = db()->prepare($sql);
      $st->execute([$attr, $old, $new, $act]);
      $msg = 'Сохранено.';
    } elseif ($action === 'update') {
      $id   = (int)($_POST['id'] ?? 0);
      $attr = trim((string)($_POST['attr_name'] ?? ''));
      $old  = trim((string)($_POST['old_value'] ?? ''));
      $new  = trim((string)($_POST['new_value'] ?? ''));
      $act  = (int)($_POST['is_active'] ?? 1) ? 1 : 0;
      if ($id <= 0) throw new RuntimeException('Некорректный id.');
      if ($attr === '' || $old === '' || $new === '') throw new RuntimeException('Поля не должны быть пустыми.');

      $st = db()->prepare("UPDATE feedtools_param_value_map SET attr_name=?, old_value=?, new_value=?, is_active=? WHERE id=?");
      $st->execute([$attr, $old, $new, $act, $id]);
      $msg = 'Обновлено.';
    } elseif ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Некорректный id.');
      $st = db()->prepare("UPDATE feedtools_param_value_map SET is_active = IF(is_active=1,0,1) WHERE id=?");
      $st->execute([$id]);
      $msg = 'Готово.';
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Некорректный id.');
      $st = db()->prepare("DELETE FROM feedtools_param_value_map WHERE id=?");
      $st->execute([$id]);
      $msg = 'Удалено.';
    } elseif ($action === 'import') {
      $raw = (string)($_POST['import_text'] ?? '');
      $raw = str_replace("\r", "\n", $raw);
      $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn($x) => $x !== ''));
      if (!$lines) throw new RuntimeException('Пустой импорт.');

      $n_ok = 0;
      $n_bad = 0;

      $sql = "INSERT INTO feedtools_param_value_map(attr_name, old_value, new_value, is_active)
              VALUES(?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE new_value=VALUES(new_value), is_active=VALUES(is_active)";
      $st = db()->prepare($sql);

      foreach ($lines as $ln) {
        $parts = array_map('trim', explode(';', $ln));
        if (count($parts) < 3) {
          $n_bad++;
          continue;
        }
        $attr = $parts[0] ?? '';
        $old  = $parts[1] ?? '';
        $new  = $parts[2] ?? '';
        $act  = (isset($parts[3]) && $parts[3] !== '') ? ((int)$parts[3] ? 1 : 0) : 1;
        if ($attr === '' || $old === '' || $new === '') {
          $n_bad++;
          continue;
        }
        $st->execute([$attr, $old, $new, $act]);
        $n_ok++;
      }

      $msg = "Импорт: сохранено {$n_ok}, пропущено {$n_bad}.";
    } else {
      throw new RuntimeException('Неизвестное действие.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$q_attr = trim((string)($_GET['attr'] ?? ''));
$q_text = trim((string)($_GET['q'] ?? ''));
$q_active = (string)($_GET['active'] ?? '');

$where = [];
$args = [];

if ($q_attr !== '') {
  $where[] = 'attr_name = ?';
  $args[] = $q_attr;
}
if ($q_text !== '') {
  $where[] = '(attr_name LIKE ? OR old_value LIKE ? OR new_value LIKE ?)';
  $args[] = '%' . $q_text . '%';
  $args[] = '%' . $q_text . '%';
  $args[] = '%' . $q_text . '%';
}
if ($q_active === '1' || $q_active === '0') {
  $where[] = 'is_active = ?';
  $args[] = (int)$q_active;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$attrs = [];
try {
  $attrs = db()->query("SELECT DISTINCT attr_name FROM feedtools_param_value_map ORDER BY attr_name ASC")->fetchAll();
} catch (Throwable $e) {
}

$rows = [];
$stats = ['total' => 0, 'active' => 0];
try {
  $st = db()->prepare("SELECT id, attr_name, old_value, new_value, is_active, created_at, updated_at
                        FROM feedtools_param_value_map
                        $where_sql
                        ORDER BY attr_name ASC, old_value ASC
                        LIMIT 1000");
  $st->execute($args);
  $rows = $st->fetchAll();

  $stats['total'] = (int)db()->query("SELECT COUNT(*) c FROM feedtools_param_value_map")->fetch()['c'];
  $stats['active'] = (int)db()->query("SELECT COUNT(*) c FROM feedtools_param_value_map WHERE is_active=1")->fetch()['c'];
} catch (Throwable $e) {
  if (!$err) $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <title>FeedTools — Замена значений характеристик</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; max-width: 1200px; margin: 30px auto; padding: 0 16px; }
    .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    input[type=text], select, textarea { padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb; font-size: 14px; }
    textarea { width: 100%; min-height: 120px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
    button { padding: 9px 12px; border-radius: 10px; border: 1px solid #111827; background: #111827; color: #fff; cursor: pointer; }
    .btn2 { border: 1px solid #e5e7eb; background: #fff; color: #111827; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; font-size: 13px; vertical-align: top; }
    .muted { color: #6b7280; }
    .err { color: #b91c1c; }
    .ok { color: #047857; }
    a { color: #111827; }
  </style>
</head>

<body>

  <?= ft_top_navigation([
    'back_href' => 'index.php',
    'back_label' => 'Назад',
    'links' => [
      ['key' => 'home', 'label' => 'Главная', 'href' => 'index.php'],
      ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => 'suppliers.php'],
      ['key' => 'ozon-tax', 'label' => 'Категории Ozon', 'href' => 'taxonomy/index.php?source=ozon'],
      ['key' => 'wb-tax', 'label' => 'Категории WB', 'href' => 'taxonomy/index.php?source=wildberries'],
    ],
  ]) ?>

  <h1>Замена значений характеристик</h1>
  <p class="muted">Таблица соответствий для операции <code>normalize_param_values</code>. Правила применяются к характеристикам Ozon <code>param</code> и Wildberries <code>wb_param</code>. Формат: <b>Характеристика</b> + <b>старое</b> → <b>новое</b>.</p>

  <?php if ($err): ?>
    <div class="card"><div class="err">Ошибка: <?= h($err) ?></div></div>
  <?php elseif ($msg): ?>
    <div class="card"><div class="ok"><?= h($msg) ?></div></div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0;">Добавить / обновить правило</h2>
    <form method="post" class="row">
      <input type="hidden" name="action" value="add">
      <input type="text" name="attr_name" placeholder="Характеристика (например: Материал)" style="min-width:260px;" required>
      <input type="text" name="old_value" placeholder="Старое значение" style="min-width:220px;" required>
      <input type="text" name="new_value" placeholder="Новое значение" style="min-width:220px;" required>
      <select name="is_active">
        <option value="1" selected>active</option>
        <option value="0">inactive</option>
      </select>
      <button type="submit">Сохранить</button>
    </form>
    <p class="muted" style="margin:10px 0 0; font-size:12px;">Если правило с такой парой (Характеристика + старое значение) уже есть — оно будет обновлено.</p>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Импорт списком</h2>
    <p class="muted" style="font-size:12px; margin-top:0;">Каждая строка: <code>Характеристика;старое;новое;active(0/1)</code>. Четвёртое поле опционально (по умолчанию 1).</p>
    <form method="post">
      <input type="hidden" name="action" value="import">
      <textarea name="import_text" placeholder="Материал;поливинилхлорид;пластик;1"></textarea>
      <div class="row" style="margin-top:10px;"><button type="submit">Импортировать</button></div>
    </form>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;">
      <div>
        <h2 style="margin:0;">Правила</h2>
        <div class="muted" style="font-size:12px; margin-top:6px;">Всего: <b><?= h($stats['total']) ?></b>, активных: <b><?= h($stats['active']) ?></b>. Показано максимум 1000 строк.</div>
      </div>
      <form method="get" class="row">
        <select name="attr">
          <option value="">Все характеристики</option>
          <?php foreach ($attrs as $a): $v = (string)($a['attr_name'] ?? ''); $sel = ($v !== '' && $v === $q_attr) ? 'selected' : ''; ?>
            <option value="<?= h($v) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="active">
          <option value="" <?= $q_active === '' ? 'selected' : '' ?>>all</option>
          <option value="1" <?= $q_active === '1' ? 'selected' : '' ?>>active</option>
          <option value="0" <?= $q_active === '0' ? 'selected' : '' ?>>inactive</option>
        </select>
        <input type="text" name="q" value="<?= h($q_text) ?>" placeholder="Поиск (текст)" style="min-width:220px;">
        <button type="submit" class="btn2">Фильтр</button>
      </form>
    </div>

    <?php if (!$rows): ?>
      <p class="muted">Пока нет правил (или фильтр ничего не нашёл).</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th style="width:190px;">Характеристика</th>
            <th>Старое</th>
            <th>Новое</th>
            <th style="width:90px;">Active</th>
            <th style="width:210px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['id']) ?></td>
              <td>
                <form method="post" class="row" style="gap:6px;">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                  <input type="text" name="attr_name" value="<?= h($r['attr_name']) ?>" style="width:180px;">
              </td>
              <td><input type="text" name="old_value" value="<?= h($r['old_value']) ?>" style="width:100%;"></td>
              <td><input type="text" name="new_value" value="<?= h($r['new_value']) ?>" style="width:100%;"></td>
              <td>
                <select name="is_active">
                  <option value="1" <?= ((int)$r['is_active'] === 1) ? 'selected' : '' ?>>1</option>
                  <option value="0" <?= ((int)$r['is_active'] === 0) ? 'selected' : '' ?>>0</option>
                </select>
              </td>
              <td>
                <button type="submit">Сохранить</button>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('Переключить active?');">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                  <button type="submit" class="btn2">Вкл/выкл</button>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('Удалить правило?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                  <button type="submit" class="btn2">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</body>
</html>
