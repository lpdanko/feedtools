<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
ft_bootstrap_public();
require_once __DIR__ . '/../app/ops.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$opId = isset($_GET['op_id']) ? (int)$_GET['op_id'] : 0;
if ($opId <= 0) {
  http_response_code(400);
  exit('Bad op_id');
}

$op = ops_get($opId);
if (!$op) {
  http_response_code(404);
  exit('Operation not found');
}

$outputs = [];
if (!empty($op['outputs_json'])) $outputs = json_decode($op['outputs_json'], true) ?: [];

$reportPath = null;
if (isset($outputs['report_json'])) {
  // report_json у нас хранится как относительный путь к storage/outputs
  require_once __DIR__ . '/../app/paths.php';
  $cfg = require __DIR__ . '/../app/config.php';
  $reportPath = realpath(abs_from_outputs($cfg, (string)$outputs['report_json']));
}

$report = null;
$reportError = null;

if ($reportPath && is_file($reportPath)) {
  $raw = file_get_contents($reportPath);
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $report = $decoded;
  } else {
    $reportError = 'report.json не является валидным JSON';
  }
} else {
  $reportError = 'report.json не найден (в outputs_json нет report_json или файл отсутствует)';
}
?>
<!doctype html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <title>Report: op #<?= h($opId) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      max-width: 1100px;
      margin: 20px auto;
      padding: 0 12px;
    }

    .card {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 14px;
      margin-bottom: 14px;
    }

    .muted {
      color: #6b7280;
    }

    pre {
      white-space: pre-wrap;
      word-break: break-word;
      background: #0b1020;
      color: #d1d5db;
      padding: 12px;
      border-radius: 12px;
      font-size: 12px;
    }

    a {
      color: #111827;
    }

    .pill {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 999px;
      font-size: 12px;
      line-height: 18px;
      font-weight: 600;
      border: 1px solid transparent;
    }

    .sev-error {
      background: #fee2e2;
      border-color: #fecaca;
      color: #991b1b;
    }

    .sev-warn {
      background: #fef3c7;
      border-color: #fde68a;
      color: #92400e;
    }

    .sev-ok {
      background: #dcfce7;
      border-color: #bbf7d0;
      color: #166534;
    }
  </style>
</head>

<body>

  <p>
    <a href="op.php?id=<?= h($opId) ?>">← к операции</a>
    &nbsp;|&nbsp;
    <a href="view.php?id=<?= h($op['dataset_id']) ?>">к датасету</a>
  </p>

  <div class="card">
    <h2>Отчёт проверки (op #<?= h($opId) ?>)</h2>
    <p class="muted">Тип операции: <?= h($op['op_type']) ?> • Статус: <?= h($op['status']) ?></p>

    <?php if ($reportError): ?>
      <p style="color:#b91c1c;"><b>Нет отчёта:</b> <?= h($reportError) ?></p>
    <?php else: ?>
      <?php
      $hasStructured =
        isset($report['summary']) ||
        isset($report['blocking']) ||
        isset($report['issues']) ||
        isset($report['recommendations']) ||
        isset($report['profiling']);
      ?>

      <?php if (!$hasStructured): ?>
        <p class="muted">Отчёт пока без структуры. Показываю JSON целиком.</p>
        <pre><?= h(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      <?php else: ?>

        <!-- SUMMARY -->
        <div class="card" style="margin-top:12px;">
          <h3>Сводка</h3>
          <?php $s = $report['summary'] ?? []; ?>
          <p>
            Offers: <b><?= h($s['offers_total'] ?? '—') ?></b> •
            Errors: <b><?= h($s['errors_total'] ?? '—') ?></b> •
            Warnings: <b><?= h($s['warnings_total'] ?? '—') ?></b>
          </p>
          <?php if (isset($s['notes'])): ?>
            <p class="muted"><?= h($s['notes']) ?></p>
          <?php endif; ?>
        </div>

            <!-- CHECKS -->
    <div class="card">
      <h3>Выполненные проверки</h3>
      <?php $checks = $report['checks'] ?? []; ?>
      <?php if (!$checks): ?>
        <p class="muted">Нет данных.</p>
      <?php else: ?>
        <ul style="margin:0;padding-left:18px;">
          <?php foreach ($checks as $c): ?>
            <?php
              $st = strtoupper((string)($c['status'] ?? ''));
              $cls = ($st === 'FAIL') ? 'sev-error' : (($st === 'WARN') ? 'sev-warn' : 'sev-ok');
            ?>
            <li style="margin:8px 0;">
              <span class="pill <?=$cls?>"><?=h($st ?: 'PASS')?></span>
              <b style="margin-left:8px;"><?=h($c['title'] ?? ($c['code'] ?? ''))?></b>
              <?php if (!empty($c['details'])): ?>
                <div class="muted" style="margin-left:0;margin-top:4px;"><?=h($c['details'])?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>


        <!-- BLOCKING -->
        <div class="card">
          <h3>Критические ошибки</h3>
          <?php $b = $report['blocking'] ?? []; ?>
          <?php if (!$b): ?>
            <p><span class="pill sev-ok">OK</span> <span class="muted">Критических ошибок нет.</span></p>
          <?php else: ?>

            <ul>
              <?php foreach ($b as $item): ?>
                <li>
                  <?php
                  $sev = strtoupper((string)($item['severity'] ?? ''));
                  $cls = ($sev === 'ERROR') ? 'sev-error' : (($sev === 'WARN') ? 'sev-warn' : 'sev-ok');
                  ?>
                  <span class="pill <?= $cls ?>"><?= h($sev ?: 'INFO') ?></span>
                  <b style="margin-left:8px;"><?= h($item['title'] ?? ($item['code'] ?? '')) ?></b>

                  <?php if (!empty($item['count'])): ?> (<?= h($item['count']) ?>)<?php endif; ?>
                    <?php if (!empty($item['advice'])): ?>
                      <div class="muted"><?= h($item['advice']) ?></div>
                    <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <!-- ISSUES -->
        <div class="card">
          <h3>Проблемы и замечания</h3>
          <?php $issues = $report['issues'] ?? []; ?>
          <?php if (!$issues): ?>
            <p class="muted">Нет данных.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Severity</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Code</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Title</th>
                  <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Count</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($issues as $it): ?>
                  <tr>
                    <td style="border-bottom:1px solid #e5e7eb;padding:8px;">
                      <?php
                      $sev = strtoupper((string)($it['severity'] ?? ''));
                      $cls = ($sev === 'ERROR') ? 'sev-error' : (($sev === 'WARN') ? 'sev-warn' : 'sev-ok');
                      ?>
                      <span class="pill <?= $cls ?>"><?= h($sev ?: 'INFO') ?></span>
                    </td>

                    <td style="border-bottom:1px solid #e5e7eb;padding:8px;"><?= h($it['code'] ?? '—') ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:8px;"><?= h($it['title'] ?? '—') ?></td>
                    <td style="border-bottom:1px solid #e5e7eb;padding:8px;"><?= h($it['count'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="muted" style="margin-top:8px;">Подробности (примеры offers) будут в report.csv / sample_offers.csv.</p>
          <?php endif; ?>
        </div>

        <!-- RECOMMENDATIONS -->
        <div class="card">
          <h3>Рекомендации</h3>
          <?php $rec = $report['recommendations'] ?? []; ?>
          <?php if (!$rec): ?>
            <p class="muted">Нет.</p>
          <?php else: ?>
            <ol>
              <?php foreach ($rec as $r): ?>
                <li>
                  <b><?= h($r['title'] ?? 'Рекомендация') ?></b>
                  <?php if (!empty($r['text'])): ?>
                    <div><?= h($r['text']) ?></div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>

        <!-- PROFILING -->
        <div class="card">
          <h3>Профилирование фида</h3>
          <?php $p = $report['profiling'] ?? []; ?>
          <?php if (!$p): ?>
            <p class="muted">Нет данных.</p>
          <?php else: ?>
            <pre><?= h(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    <?php endif; ?>

  </div>

</body>

</html>
