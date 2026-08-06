<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/navigation.php';
require_once __DIR__ . '/../../app/ops.php';
require_once __DIR__ . '/../../app/paths.php';
require_once __DIR__ . '/../../app/op_registry.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function taxonomy_project_root(): string {
  return dirname(__DIR__, 2);
}

function taxonomy_storage_path(string $relative): string {
  return rtrim(taxonomy_project_root(), '/\\') . '/' . ltrim($relative, '/\\');
}

function taxonomy_source_meta(string $source): array {
  if ($source === 'wildberries') {
    return [
      'label' => 'Wildberries',
      'op_type_api' => 'taxonomy_import_wb_api',
      'default_path' => taxonomy_storage_path('storage/taxonomies/wildberries/categories_wb.txt'),
      'supports_file' => false,
    ];
  }

  return [
    'label' => 'Ozon',
    'op_type_api' => 'taxonomy_import_ozon_api',
    'op_type_file' => 'taxonomy_import_ozon',
    'default_path' => taxonomy_storage_path('storage/taxonomies/ozon/categories_ozon.txt'),
    'supports_file' => true,
  ];
}

function ensure_anchor_file(string $path, string $source): string {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('Cannot create taxonomy dir: ' . $dir);
    }
  }
  $dir = realpath($dir) ?: $dir;
  $path = rtrim($dir, '/\\') . '/' . basename($path);
  if (!is_file($path)) {
    $label = $source === 'wildberries' ? 'wildberries' : 'ozon';
    if (file_put_contents($path, "taxonomy-import-anchor:{$label}\n") === false) {
      throw new RuntimeException('Cannot create taxonomy anchor file: ' . $path);
    }
  }
  return $path;
}

function ensure_dataset_for_file(string $absPath): int {
  $inputPath = trim($absPath);
  $real = realpath($inputPath);
  $absPath = $real !== false ? (string)$real : $inputPath;
  if ($absPath === '' || !is_file($absPath)) {
    throw new RuntimeException("File not found: {$inputPath}");
  }
  $sha = hash_file('sha256', $absPath);
  $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
  $stmt->execute([$sha]);
  $id = (int)($stmt->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  $bytes = (int)filesize($absPath);
  $base = basename($absPath);

  $ins = db()->prepare("
    INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
    VALUES (?, ?, ?, ?, ?, 0, NULL)
  ");
  $ins->execute([$base, $base, $absPath, $bytes, $sha]);

  return (int)db()->lastInsertId();
}

$source = trim((string)($_GET['source'] ?? $_POST['source'] ?? 'ozon'));
if (!in_array($source, ['ozon', 'wildberries'], true)) $source = 'ozon';
$sourceMeta = taxonomy_source_meta($source);
$defaultPath = $sourceMeta['default_path'];

$err = '';
$msg = '';
$opId = isset($_GET['op_id']) ? (int)$_GET['op_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $path = trim((string)($_POST['path'] ?? ''));
    if ($path === '') $path = $defaultPath;

    $replace = !empty($_POST['replace']) ? '1' : '0';
    $fromApi = !empty($_POST['from_api']);

    if ($fromApi) {
      if ($source === 'wildberries') {
        $anchorPath = ensure_anchor_file($defaultPath, $source);
        try {
          $datasetId = ensure_dataset_for_file($anchorPath);
        } catch (Throwable $e) {
          // Для WB сам файл не нужен для импорта из API, поэтому всегда можем
          // использовать любой существующий dataset как технический контейнер.
          $datasetId = (int)(db()->query("SELECT id FROM feedtools_datasets ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
          if ($datasetId <= 0) {
            throw $e;
          }
        }
      } else {
        try {
          $anchorPath = ensure_anchor_file($defaultPath, $source);
          $datasetId = ensure_dataset_for_file($anchorPath);
        } catch (Throwable $e) {
          $datasetId = (int)(db()->query("SELECT id FROM feedtools_datasets ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
          if ($datasetId <= 0) {
            throw $e;
          }
        }
      }
      $opType = $sourceMeta['op_type_api'];
    } else {
      if (empty($sourceMeta['supports_file'])) {
        throw new RuntimeException('Для Wildberries сейчас поддержан только импорт из API.');
      }
      $datasetId = ensure_dataset_for_file($path);
      $opType = $sourceMeta['op_type_file'];
    }

    $registry = op_registry();
    if (!isset($registry[$opType])) throw new RuntimeException("op_type not registered: {$opType}");

    $params = $fromApi
      ? ['replace' => $replace]
      : ['file_path' => (string)realpath($path), 'replace' => $replace];

    $opId = ops_create($datasetId, $opType, $params);
    ops_append_log_tail($opId, "Queued taxonomy import.\n", 200000);
    ops_update_progress($opId, 0, 1, 'queued', 'Queued');

    // spawn worker (как run_op.php)
    if (!empty($cfg['worker']['auto_spawn'])) {
      $php = $cfg['worker']['php_bin'] ?? PHP_BINARY;
      $script = $cfg['worker']['worker_script'] ?? (__DIR__ . '/../../bin/worker.php');

      $outDir = op_output_dir($cfg, $datasetId, $opId);
      ensure_dir($outDir);
      $spawnLogAbs = $outDir . '/spawn.log';
      @file_put_contents($spawnLogAbs, "spawn init\n", FILE_APPEND);

      $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($script)
           . ' --op_id=' . (int)$opId
           . ' > ' . escapeshellarg($spawnLogAbs) . ' 2>&1 &';
      @exec($cmd);

      ops_append_log_tail($opId, "spawn: {$cmd}\n", 200000);
    }

    // остаёмся на этой странице и показываем прогресс
    header("Location: import.php?source=" . urlencode($source) . "&op_id=" . urlencode((string)$opId));
    exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
<title>Taxonomy import</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1200px;margin:30px auto;padding:0 16px}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
    input[type=text]{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px}
    button{padding:10px 14px;border-radius:10px;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer}
    .muted{color:#6b7280}
    .err{color:#b91c1c}
    .ok{color:#166534}
    .bar{height:12px;background:#e5e7eb;border-radius:999px;overflow:hidden}
    .bar > div{height:12px;background:#111827;width:0%}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
    a{color:#111827}
  </style>
</head>
<body>

<?= ft_top_navigation([
  'back_href' => 'index.php?source=' . rawurlencode((string)$source),
  'back_label' => 'Назад',
  'links' => [
    ['key' => 'home', 'label' => 'Главная', 'href' => '../index.php'],
    ['key' => 'suppliers', 'label' => 'Поставщики', 'href' => '../suppliers.php'],
    ['key' => 'connections', 'label' => 'Подключения', 'href' => '../marketplace_connections.php'],
    ['key' => 'taxonomy', 'label' => 'Список категорий', 'href' => 'index.php?source=' . rawurlencode((string)$source)],
  ],
  'active' => 'taxonomy',
]) ?>
<h1>Импорт категорий <?= h($sourceMeta['label']) ?></h1>

<div class="card">
  <?php if ($source === 'ozon'): ?>
    <p class="muted">
      Формат файла: <code>Категория &gt; Подкатегория &gt; ... (A_B)</code><br>
      Прогресс отображается по количеству строк.
    </p>
  <?php else: ?>
    <p class="muted">
      Источник: Wildberries Content API. Будут загружены parent categories и subjects.<br>
      Импорт идёт напрямую из API, файл не нужен.
    </p>
  <?php endif; ?>

  <?php if ($err): ?><p class="err"><?=h($err)?></p><?php endif; ?>

  <form method="post">
    <input type="hidden" name="source" value="<?= h($source) ?>">
    <?php if (!empty($sourceMeta['supports_file'])): ?>
      <label class="muted">Путь к файлу на сервере</label>
      <input type="text" name="path" value="<?=h($defaultPath)?>">
      <div style="margin-top:10px;">
        <label class="muted">
          <input type="checkbox" name="from_api" value="1"> Импортировать из <?= h($sourceMeta['label']) ?> API, игнорировать файл
        </label>
      </div>
    <?php else: ?>
      <div style="margin-top:10px;">
        <label class="muted">
          <input type="checkbox" name="from_api" value="1" checked> Импортировать из <?= h($sourceMeta['label']) ?> API
        </label>
      </div>
    <?php endif; ?>

    <div style="margin-top:10px;">
      <label class="muted">
        <input type="checkbox" name="replace" value="1"> Очистить старые категории перед импортом
      </label>
    </div>
    <div style="margin-top:12px;">
      <button type="submit">Запустить импорт</button>
      <span class="muted" style="margin-left:10px;"><a href="index.php?source=<?= h($source) ?>">Список категорий</a></span>
      <?php if ($source === 'ozon'): ?>
        <span class="muted" style="margin-left:10px;"><a href="import.php?source=wildberries">Переключиться на Wildberries</a></span>
      <?php else: ?>
        <span class="muted" style="margin-left:10px;"><a href="import.php?source=ozon">Переключиться на Ozon</a></span>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($opId > 0): ?>
  <div class="card" id="progressCard">
    <p class="muted">
      Operation: <a href="../op.php?id=<?=h($opId)?>">#<?=h($opId)?></a>
    </p>

    <div class="bar"><div id="barFill"></div></div>
    <p style="margin-top:10px;">
      <b id="status">...</b>
      <span class="muted" id="nums"></span>
    </p>
    <p class="muted" id="stageMsg"></p>

    <pre id="logTail" style="white-space:pre-wrap;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px;max-height:260px;overflow:auto;"></pre>
  </div>

  <script>
    const opId = <?= (int)$opId ?>;

    function fmtSec(s){
      if (s === null || s === undefined) return '';
      s = Math.max(0, s|0);
      const m = Math.floor(s/60), r = s%60;
      return m ? `${m}m ${r}s` : `${r}s`;
    }

    async function poll(){
      const r = await fetch(`../op_poll.php?id=${opId}`, {cache:'no-store'});
      const j = await r.json();

      const status = document.getElementById('status');
      const nums = document.getElementById('nums');
      const stageMsg = document.getElementById('stageMsg');
      const bar = document.getElementById('barFill');
      const logTail = document.getElementById('logTail');

      status.textContent = j.status || '...';

      const done = j.done || 0;
      const total = j.total || 0;
      const pct = (total > 0) ? ((done/total)*100).toFixed(1) : '0.0';
      bar.style.width = `${Math.min(100, Math.max(0, pct))}%`;

      nums.textContent = total > 0
        ? ` ${done}/${total} (${pct}%) • elapsed ${fmtSec(j.elapsed_sec)} • eta ${fmtSec(j.eta_sec)}`
        : ` ${done} • elapsed ${fmtSec(j.elapsed_sec)}`;

      stageMsg.textContent = (j.stage ? `[${j.stage}] ` : '') + (j.msg || '');
      logTail.textContent = (j.log_tail || '').slice(-4000);

      if (j.status === 'done' || j.status === 'error') return;
      setTimeout(poll, 800);
    }
    poll();
  </script>
<?php endif; ?>

</body>
</html>
