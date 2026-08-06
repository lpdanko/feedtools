<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/navigation.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function operator_safe_return_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || str_contains($raw, "\n") || str_contains($raw, "\r")) {
        return 'index.php';
    }
    $parts = parse_url($raw);
    if (!is_array($parts)) {
        return 'index.php';
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($currentHost === '' || $host === '' || $host !== $currentHost) {
            return 'index.php';
        }
    }
    $path = (string)($parts['path'] ?? '');
    $query = (string)($parts['query'] ?? '');
    if ($path === '' || str_starts_with($path, '//')) {
        return 'index.php';
    }
    if (!preg_match('~^/[A-Za-z0-9_./-]+$~', $path) && !preg_match('~^[A-Za-z0-9_./-]+$~', $path)) {
        return 'index.php';
    }
    return $path . ($query !== '' ? '?' . $query : '');
}

$returnUrl = operator_safe_return_url((string)($_GET['return_url'] ?? $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php')));
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $actor = ft_normalize_actor((string)($_POST['actor'] ?? ''));
    if (isset($_POST['clear'])) {
        ft_set_operator_user(null);
        header('Location: ' . $returnUrl, true, 303);
        exit;
    }
    if ($actor === null) {
        $error = 'Укажи имя оператора.';
    } else {
        ft_set_operator_user($actor);
        header('Location: ' . $returnUrl, true, 303);
        exit;
    }
}

$current = ft_current_user() ?? '';
$authUser = ft_authenticated_user() ?? '';
$operator = ft_operator_user() ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FeedTools — Оператор</title>
  <?= ft_navigation_assets() ?>
  <style>
    :root { color-scheme: light; --bg:#f4f7fb; --panel:#fff; --ink:#172033; --muted:#64748b; --line:#d9e5f2; --blue:#2563eb; --red:#b42318; --shadow:0 18px 44px rgba(25,54,90,.08); }
    * { box-sizing: border-box; }
    body { margin:0; background:linear-gradient(180deg,#f8fbff 0%,var(--bg) 100%); color:var(--ink); font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    .shell { max-width:760px; margin:0 auto; padding:24px 18px 44px; }
    .panel { border:1px solid var(--line); border-radius:16px; background:var(--panel); box-shadow:var(--shadow); padding:22px; }
    h1 { margin:0; font-size:34px; line-height:1; letter-spacing:0; }
    .lead { margin:10px 0 20px; color:var(--muted); }
    label { display:grid; gap:7px; color:var(--muted); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
    input[type=text] { min-height:44px; border:1px solid #c9d7e8; border-radius:10px; padding:0 12px; color:var(--ink); background:#fff; font:inherit; text-transform:none; letter-spacing:0; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
    button, .btn { min-height:40px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--blue); border-radius:10px; padding:0 14px; background:var(--blue); color:#fff; font:inherit; font-weight:900; text-decoration:none; cursor:pointer; }
    button.secondary, .btn.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
    .facts { display:grid; gap:8px; margin:16px 0; padding:14px; border:1px solid var(--line); border-radius:12px; background:#f8fbff; }
    .fact { display:flex; justify-content:space-between; gap:12px; }
    .fact span { color:var(--muted); }
    .fact b { text-align:right; }
    .error { margin-bottom:14px; padding:12px; border:1px solid #ffd0cc; border-radius:12px; background:#fff4f2; color:var(--red); font-weight:800; }
    @media (max-width:560px) { .fact { display:grid; } }
  </style>
</head>
<body>
  <main class="shell">
    <?= ft_top_navigation([
      'back_href' => $returnUrl,
      'back_label' => 'Назад',
      'active' => '',
      'links' => ft_default_nav_links(''),
    ]) ?>
    <section class="panel">
      <h1>Оператор</h1>
      <p class="lead">Это имя будет записываться в новые операции и использоваться в дашборде вклада. Когда будет включена полноценная авторизация, она станет главным источником пользователя.</p>
      <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
      <div class="facts">
        <div class="fact"><span>Текущий пользователь</span><b><?= h($current !== '' ? $current : 'не указан') ?></b></div>
        <div class="fact"><span>Авторизация</span><b><?= h($authUser !== '' ? $authUser : 'не включена') ?></b></div>
        <div class="fact"><span>Оператор cookie</span><b><?= h($operator !== '' ? $operator : 'не задан') ?></b></div>
      </div>
      <form method="post">
        <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <label>
          Имя оператора
          <input type="text" name="actor" value="<?= h($operator !== '' ? $operator : $current) ?>" placeholder="например: ivan.petrov">
        </label>
        <div class="actions">
          <button type="submit">Сохранить</button>
          <button type="submit" name="clear" value="1" class="secondary">Сбросить</button>
          <a class="btn secondary" href="<?= h($returnUrl) ?>">Отмена</a>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
