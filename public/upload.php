<?php
require_once __DIR__ . '/../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/xml_scan.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_private_ip(string $ip): bool
{
  if ($ip === '') return true;

  $public = filter_var(
    $ip,
    FILTER_VALIDATE_IP,
    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
  );
  if ($public !== false) return false;

  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $long = ip2long($ip);
    if ($long === false) return true;
    $ranges = [
      ['0.0.0.0', '0.255.255.255'],
      ['10.0.0.0', '10.255.255.255'],
      ['127.0.0.0', '127.255.255.255'],
      ['169.254.0.0', '169.254.255.255'],
      ['172.16.0.0', '172.31.255.255'],
      ['192.168.0.0', '192.168.255.255'],
      ['224.0.0.0', '239.255.255.255'],
      ['240.0.0.0', '255.255.255.255'],
    ];
    foreach ($ranges as [$a, $b]) {
      if ($long >= ip2long($a) && $long <= ip2long($b)) return true;
    }
    return false;
  }

  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    $ip = strtolower($ip);
    if ($ip === '::1') return true;
    if (str_starts_with($ip, 'fe80:')) return true;
    if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) return true;
    return false;
  }

  return true;
}

function validate_public_download_url(string $url): array
{
  $url = trim($url);
  if ($url === '') throw new RuntimeException('Ссылка пустая.');

  $parts = parse_url($url);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    throw new RuntimeException('Некорректная ссылка.');
  }

  $scheme = strtolower((string)$parts['scheme']);
  if (!in_array($scheme, ['http', 'https'], true)) {
    throw new RuntimeException('Только http/https ссылки.');
  }

  if (!empty($parts['user']) || !empty($parts['pass'])) {
    throw new RuntimeException('Ссылки с логином/паролем не поддерживаются.');
  }

  $host = (string)$parts['host'];
  $ips = [];
  if (filter_var($host, FILTER_VALIDATE_IP)) {
    $ips[] = $host;
  } else {
    $resolved4 = gethostbynamel($host);
    if (is_array($resolved4)) $ips = array_merge($ips, $resolved4);

    $resolved6 = @dns_get_record($host, DNS_AAAA);
    if (is_array($resolved6)) {
      foreach ($resolved6 as $row) {
        if (!empty($row['ipv6'])) $ips[] = (string)$row['ipv6'];
      }
    }
  }

  $ips = array_values(array_unique(array_filter(array_map('trim', $ips))));
  if (!$ips) throw new RuntimeException('Не удалось определить IP адрес хоста.');

  foreach ($ips as $ip) {
    if (is_private_ip($ip)) {
      throw new RuntimeException('Запрещённый адрес хоста (локальный/приватный).');
    }
  }

  return $parts;
}

function resolve_redirect_url(string $baseUrl, string $location): string
{
  $location = trim($location);
  if ($location === '') return '';
  if (preg_match('~^https?://~i', $location)) return $location;
  if (str_starts_with($location, '//')) {
    $base = parse_url($baseUrl);
    return strtolower((string)($base['scheme'] ?? 'https')) . ':' . $location;
  }

  $base = parse_url($baseUrl);
  if (!$base || empty($base['scheme']) || empty($base['host'])) return $location;

  $root = strtolower((string)$base['scheme']) . '://' . (string)$base['host'];
  if (!empty($base['port'])) $root .= ':' . (int)$base['port'];

  if (str_starts_with($location, '/')) return $root . $location;

  $path = (string)($base['path'] ?? '/');
  $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
  return $root . $dir . $location;
}

function download_xml_to_tempfile(string $url, int $maxBytes): array
{
  $url = trim($url);
  validate_public_download_url($url);

  $tmp = tempnam(sys_get_temp_dir(), 'ftxml_');
  if (!$tmp) throw new RuntimeException('Не удалось создать временный файл.');

  $fp = fopen($tmp, 'wb');
  if (!$fp) {
    @unlink($tmp);
    throw new RuntimeException('Не удалось открыть временный файл.');
  }

  if (function_exists('curl_init')) {
    $currentUrl = $url;

    for ($redirect = 0; $redirect <= 5; $redirect++) {
      validate_public_download_url($currentUrl);

      ftruncate($fp, 0);
      rewind($fp);

      $bytes = 0;
      $tooLarge = false;
      $location = '';

      $ch = curl_init($currentUrl);
      curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'FeedTools/1.0 (+xml import)',
        CURLOPT_FAILONERROR => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$location, $maxBytes): int {
          $len = strlen($header);
          $pos = strpos($header, ':');
          if ($pos !== false) {
            $name = strtolower(trim(substr($header, 0, $pos)));
            $value = trim(substr($header, $pos + 1));
            if ($name === 'location') $location = $value;
            if ($name === 'content-length' && ctype_digit($value) && (int)$value > $maxBytes) {
              return 0;
            }
          }
          return $len;
        },
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($fp, &$bytes, &$tooLarge, $maxBytes): int {
          $len = strlen($chunk);
          $bytes += $len;
          if ($bytes > $maxBytes) {
            $tooLarge = true;
            return 0;
          }
          $written = fwrite($fp, $chunk);
          return ($written === false) ? 0 : $written;
        },
      ]);

      $ok = curl_exec($ch);
      $err = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);

      if ($tooLarge || ($ok === false && stripos($err, 'Failed writing') !== false)) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('Файл по ссылке слишком большой.');
      }
      if (!$ok) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('Не удалось скачать XML: ' . ($err ?: ('HTTP ' . $code)));
      }

      if ($code >= 300 && $code < 400 && $location !== '') {
        $nextUrl = resolve_redirect_url($currentUrl, $location);
        if ($nextUrl === '') {
          fclose($fp);
          @unlink($tmp);
          throw new RuntimeException('Некорректный redirect при скачивании XML.');
        }
        $currentUrl = $nextUrl;
        continue;
      }

      if ($code >= 400) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('Не удалось скачать XML: HTTP ' . $code);
      }

      fflush($fp);
      fclose($fp);

      if ($bytes <= 0) {
        @unlink($tmp);
        throw new RuntimeException('Скачан пустой файл.');
      }

      return [$tmp, $bytes, $currentUrl];
    }

    fclose($fp);
    @unlink($tmp);
    throw new RuntimeException('Слишком много redirect при скачивании XML.');
  }

  $ctx = stream_context_create([
    'http' => [
      'timeout' => 60,
      'follow_location' => 1,
      'max_redirects' => 5,
      'user_agent' => 'FeedTools/1.0 (+xml import)',
    ],
  ]);
  $in = @fopen($url, 'rb', false, $ctx);
  if (!$in) {
    fclose($fp);
    @unlink($tmp);
    throw new RuntimeException('Не удалось открыть ссылку.');
  }

  $bytes = 0;
  while (!feof($in)) {
    $chunk = fread($in, 1024 * 1024);
    if ($chunk === false) break;
    $bytes += strlen($chunk);
    if ($bytes > $maxBytes) {
      fclose($in);
      fclose($fp);
      @unlink($tmp);
      throw new RuntimeException('Файл по ссылке слишком большой.');
    }
    fwrite($fp, $chunk);
  }

  fclose($in);
  fflush($fp);
  fclose($fp);

  if ($bytes <= 0) {
    @unlink($tmp);
    throw new RuntimeException('Скачан пустой файл.');
  }

  return [$tmp, $bytes, $url];
}

function upload_pending_dir(array $cfg): string
{
  return rtrim((string)$cfg['paths']['uploads_dir'], '/\\') . '/pending';
}

function ensure_pending_dir(array $cfg): string
{
  $dir = upload_pending_dir($cfg);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Не удалось создать папку pending.');
  }
  return $dir;
}

function save_pending_import_file(array $cfg, string $tmpPath, string $origName, int $bytes, string $sourceType, string $sourceValue): string
{
  $dir = ensure_pending_dir($cfg);
  $token = bin2hex(random_bytes(16));
  $xmlPath = $dir . '/' . $token . '.xml';
  $metaPath = $dir . '/' . $token . '.json';

  if (!@rename($tmpPath, $xmlPath)) {
    if (!@copy($tmpPath, $xmlPath)) {
      @unlink($tmpPath);
      throw new RuntimeException('Не удалось сохранить временный XML для подтверждения кода поставщика.');
    }
    @unlink($tmpPath);
  }

  $meta = [
    'original_filename' => $origName,
    'bytes' => $bytes,
    'source_type' => $sourceType,
    'source_value' => $sourceValue,
    'created_at' => date('c'),
  ];

  file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  return $token;
}

function load_pending_import(array $cfg, string $token): array
{
  if (!preg_match('~^[a-f0-9]{32}$~', $token)) {
    throw new RuntimeException('Некорректный токен отложенного импорта.');
  }

  $dir = ensure_pending_dir($cfg);
  $xmlPath = $dir . '/' . $token . '.xml';
  $metaPath = $dir . '/' . $token . '.json';

  if (!is_file($xmlPath) || !is_file($metaPath)) {
    throw new RuntimeException('Временный файл импорта не найден. Повтори загрузку.');
  }

  $meta = json_decode((string)file_get_contents($metaPath), true);
  if (!is_array($meta)) {
    throw new RuntimeException('Не удалось прочитать данные временного импорта.');
  }

  return [
    'token' => $token,
    'xml_path' => $xmlPath,
    'meta_path' => $metaPath,
    'original_filename' => (string)($meta['original_filename'] ?? 'remote.xml'),
    'bytes' => (int)($meta['bytes'] ?? (is_file($xmlPath) ? filesize($xmlPath) : 0)),
    'source_type' => (string)($meta['source_type'] ?? ''),
    'source_value' => (string)($meta['source_value'] ?? ''),
  ];
}

function cleanup_pending_import(array $cfg, string $token): void
{
  if (!preg_match('~^[a-f0-9]{32}$~', $token)) return;
  $dir = upload_pending_dir($cfg);
  @unlink($dir . '/' . $token . '.xml');
  @unlink($dir . '/' . $token . '.json');
}

function probe_supplier_code_in_offer_ids(string $path, int $checkLimit = 20): array
{
  $reader = new XMLReader();
  if (!$reader->open($path, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Cannot open XML file');
  }

  $checked = 0;
  $found = 0;
  $samples = [];

  while ($checked < $checkLimit && $reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

    $offerId = (string)$reader->getAttribute('id');
    $checked++;

    if (strpos($offerId, '__') !== false) {
      $found++;
    } elseif (count($samples) < 8) {
      $samples[] = $offerId;
    }

    if (!$reader->isEmptyElement) {
      $depth = $reader->depth;
      while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) {
          break;
        }
      }
    }
  }

  $reader->close();

  return [
    'checked_count' => $checked,
    'found_count' => $found,
    'has_supplier_code' => $found > 0,
    'sample_offer_ids_without_code' => $samples,
  ];
}

function normalize_supplier_code(string $code): string
{
  $code = trim($code);
  $code = preg_replace('~^_+|_+$~u', '', $code);
  $code = preg_replace('~[^\pL\pN.\-]+~u', '_', $code);
  $code = preg_replace('~_+~u', '_', $code);
  return trim((string)$code, '._-');
}

function append_supplier_code_to_offer_ids(string $srcPath, string $dstPath, string $supplierCode): void
{
  $suffix = '__' . $supplierCode;

  $reader = new XMLReader();
  if (!$reader->open($srcPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Не удалось открыть XML для добавления кода поставщика.');
  }

  $writer = new XMLWriter();
  if (!$writer->openURI($dstPath)) {
    $reader->close();
    throw new RuntimeException('Не удалось создать временный XML с кодом поставщика.');
  }

  $writer->startDocument('1.0', 'UTF-8');
  $writer->setIndent(false);

  while ($reader->read()) {
    switch ($reader->nodeType) {
      case XMLReader::ELEMENT:
        $elementName = $reader->name;
        $writer->startElement($elementName);
        if ($reader->hasAttributes) {
          while ($reader->moveToNextAttribute()) {
            $value = $reader->value;
            if ($elementName === 'offer' && $reader->name === 'id' && $reader->value !== '' && strpos((string)$reader->value, '__') === false) {
              $value = (string)$reader->value . $suffix;
            }
            $writer->writeAttribute($reader->name, $value);
          }
          $reader->moveToElement();
        }
        if ($reader->isEmptyElement) {
          $writer->endElement();
        }
        break;

      case XMLReader::END_ELEMENT:
        $writer->endElement();
        break;

      case XMLReader::TEXT:
      case XMLReader::SIGNIFICANT_WHITESPACE:
      case XMLReader::WHITESPACE:
        $writer->text($reader->value);
        break;

      case XMLReader::CDATA:
        $writer->writeCData($reader->value);
        break;

      case XMLReader::COMMENT:
        $writer->writeComment($reader->value);
        break;

      case XMLReader::PI:
        $writer->writePI($reader->name, $reader->value);
        break;

      case XMLReader::DOC_TYPE:
        $writer->writeDTD($reader->name, '', '', $reader->value);
        break;
    }
  }

  $reader->close();
  $writer->endDocument();
  $writer->flush();
}

function store_dataset_from_path(array $cfg, string $sourcePath, string $origName, int $bytes): array
{
  $sha256 = hash_file('sha256', $sourcePath);

  $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
  $stmt->execute([$sha256]);
  $existing = $stmt->fetchColumn();
  if ($existing) {
    return ['existing_dataset_id' => (int)$existing];
  }

  $uploadsDir = (string)$cfg['paths']['uploads_dir'];
  if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
    throw new RuntimeException('Не удалось создать папку uploads.');
  }

  $safeBase = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', basename($origName));
  if ($safeBase === '' || $safeBase === '_') $safeBase = 'feed.xml';
  $storedFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase;
  $storedPath = rtrim($uploadsDir, '/\\') . '/' . $storedFilename;

  if (!@rename($sourcePath, $storedPath)) {
    if (!@copy($sourcePath, $storedPath)) {
      throw new RuntimeException('Не удалось сохранить XML в uploads.');
    }
  }

  $actualBytes = (int)(@filesize($storedPath) ?: $bytes);
  $scan = scan_xml($storedPath, (int)$cfg['limits']['sample_offers']);
  $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);

  $stmt = db()->prepare("
    INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $origName,
    $storedFilename,
    $storedPath,
    $actualBytes,
    $sha256,
    (int)$scan['offers_count'],
    $warningsJson,
  ]);

  $datasetId = (int)db()->lastInsertId();

  return [
    'dataset_id' => $datasetId,
    'original_filename' => $origName,
    'stored_filename' => $storedFilename,
    'bytes' => $actualBytes,
    'sha256' => $sha256,
    'offers_count' => (int)$scan['offers_count'],
    'warnings' => $scan['warnings'],
    'samples' => $scan['samples'],
  ];
}

$error = '';
$result = null;
$supplierWarning = null;

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tmpFilesToDelete = [];
    $pendingTokenToCleanup = null;

    if (!empty($_POST['pending_token'])) {
      $pending = load_pending_import($cfg, (string)$_POST['pending_token']);
      $pendingTokenToCleanup = $pending['token'];
      $origName = $pending['original_filename'];
      $bytes = $pending['bytes'];

      if (!empty($_POST['continue_without_supplier_code'])) {
        $stored = store_dataset_from_path($cfg, $pending['xml_path'], $origName, $bytes);
      } else {
        $supplierCode = normalize_supplier_code((string)($_POST['supplier_code'] ?? ''));
        if ($supplierCode === '') {
          throw new RuntimeException('Укажи код поставщика.');
        }

        $tmpTransformed = tempnam(sys_get_temp_dir(), 'ftxml_sup_');
        if (!$tmpTransformed) {
          throw new RuntimeException('Не удалось создать временный XML для добавления кода поставщика.');
        }
        $tmpFilesToDelete[] = $tmpTransformed;

        append_supplier_code_to_offer_ids($pending['xml_path'], $tmpTransformed, $supplierCode);
        $stored = store_dataset_from_path($cfg, $tmpTransformed, $origName, (int)(@filesize($tmpTransformed) ?: $bytes));
      }

      if (!empty($stored['existing_dataset_id'])) {
        cleanup_pending_import($cfg, $pendingTokenToCleanup);
        header("Location: view.php?id=" . urlencode((string)$stored['existing_dataset_id']));
        exit;
      }

      $result = $stored;
      cleanup_pending_import($cfg, $pendingTokenToCleanup);
      foreach ($tmpFilesToDelete as $tmpPath) @unlink($tmpPath);
    } else {
      $tmpPath = null;
      $origName = null;
      $bytes = 0;
      $sourceType = '';
      $sourceValue = '';

      if (!empty($_FILES['xmlfile']) && (int)($_FILES['xmlfile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmpPath = (string)$_FILES['xmlfile']['tmp_name'];
        $origName = (string)$_FILES['xmlfile']['name'];
        $bytes = (int)$_FILES['xmlfile']['size'];
        $sourceType = 'upload';
        $sourceValue = $origName;

        if ($bytes <= 0) throw new RuntimeException('Пустой файл.');
        if ($bytes > (int)$cfg['limits']['max_upload_bytes']) throw new RuntimeException('Файл слишком большой.');
      } else {
        $xmlurl = trim((string)($_POST['xmlurl'] ?? ''));
        if ($xmlurl === '') {
          throw new RuntimeException('Нужно загрузить файл или указать ссылку.');
        }

        [$tmpPath, $bytes, $finalUrl] = download_xml_to_tempfile($xmlurl, (int)$cfg['limits']['max_upload_bytes']);
        $origName = basename((string)(parse_url($finalUrl, PHP_URL_PATH) ?: '')) ?: 'remote.xml';
        if (!preg_match('/\.xml$/i', $origName)) $origName .= '.xml';
        $sourceType = 'url';
        $sourceValue = $finalUrl;
      }

      $probe = probe_supplier_code_in_offer_ids($tmpPath, 20);
      $supplierCode = normalize_supplier_code((string)($_POST['supplier_code'] ?? ''));
      $continueWithout = !empty($_POST['continue_without_supplier_code']);

      if (!$probe['has_supplier_code'] && !$continueWithout && $supplierCode === '') {
        $pendingToken = save_pending_import_file($cfg, $tmpPath, $origName, $bytes, $sourceType, $sourceValue);
        $supplierWarning = [
          'pending_token' => $pendingToken,
          'original_filename' => $origName,
          'checked_count' => $probe['checked_count'],
          'sample_offer_ids_without_code' => $probe['sample_offer_ids_without_code'],
        ];
      } else {
        $sourcePathForImport = $tmpPath;
        $tmpTransformed = null;

        if (!$probe['has_supplier_code'] && $supplierCode !== '') {
          $tmpTransformed = tempnam(sys_get_temp_dir(), 'ftxml_sup_');
          if (!$tmpTransformed) {
            throw new RuntimeException('Не удалось создать временный XML для добавления кода поставщика.');
          }
          append_supplier_code_to_offer_ids($tmpPath, $tmpTransformed, $supplierCode);
          $sourcePathForImport = $tmpTransformed;
          $bytes = (int)(@filesize($tmpTransformed) ?: $bytes);
        }

        $stored = store_dataset_from_path($cfg, $sourcePathForImport, $origName, $bytes);

        if (!empty($stored['existing_dataset_id'])) {
          if ($tmpTransformed && is_file($tmpTransformed)) @unlink($tmpTransformed);
          if ($sourceType === 'url' && is_file($tmpPath)) @unlink($tmpPath);
          header("Location: view.php?id=" . urlencode((string)$stored['existing_dataset_id']));
          exit;
        }

        if ($tmpTransformed && is_file($tmpTransformed)) @unlink($tmpTransformed);
        if ($sourceType === 'url' && is_file($tmpPath) && $tmpPath !== $sourcePathForImport) @unlink($tmpPath);

        $result = $stored;
      }
    }
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Upload result</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= ft_navigation_assets() ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px;}
    .muted{color:#6b7280;}
    .err{color:#b91c1c;}
    .warn{color:#9a6700;}
    table{width:100%;border-collapse:collapse;}
    th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;font-size:14px;}
    a{color:#111827;}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px;}
    input[type=text]{padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font:inherit;min-width:320px;max-width:100%;}
    button{padding:10px 14px;border-radius:10px;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer;font:inherit;}
    .btn-secondary{background:#fff;color:#111827;}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
  </style>
</head>
<body>

<?= ft_top_navigation([
  'back_href' => 'xml_feeds.php',
  'back_label' => 'Назад',
  'active' => 'xml',
]) ?>

<?php if ($supplierWarning): ?>
  <div class="card">
    <h2 class="warn">Не найден код поставщика в offer id</h2>
    <p>В первых <b><?=h($supplierWarning['checked_count'])?></b> товарах не найдено вхождение <code>__</code>. Обычно в таком формате в <code>offer id</code> хранится код поставщика.</p>
    <p class="muted">Файл: <b><?=h($supplierWarning['original_filename'])?></b></p>

    <?php if (!empty($supplierWarning['sample_offer_ids_without_code'])): ?>
      <p class="muted">Примеры offer id без кода поставщика:</p>
      <ul>
        <?php foreach ($supplierWarning['sample_offer_ids_without_code'] as $sampleId): ?>
          <li><code><?=h($sampleId)?></code></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="card" style="background:#fafafa;">
      <input type="hidden" name="pending_token" value="<?=h($supplierWarning['pending_token'])?>">
      <div class="row">
        <label>
          <div class="muted" style="margin-bottom:6px;">Код поставщика</div>
          <input type="text" name="supplier_code" placeholder="например DJI или RC_GOODS" required>
        </label>
        <button type="submit">Добавить код и импортировать</button>
      </div>
      <p class="muted" style="margin-top:10px;">Код будет автоматически добавлен в конец каждого <code>offer id</code> в формате <code>__код_поставщика</code>.</p>
    </form>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="pending_token" value="<?=h($supplierWarning['pending_token'])?>">
      <input type="hidden" name="continue_without_supplier_code" value="1">
      <button type="submit" class="btn-secondary">Импортировать без кода поставщика</button>
    </form>
  </div>

<?php elseif ($error): ?>
  <div class="card">
    <h2 class="err">Ошибка</h2>
    <p class="err"><?=h($error)?></p>
    <p class="muted">Проверь настройки БД в <code>.env</code> или <code>app/config.local.php</code> и права записи в <code>storage/uploads</code>.</p>
  </div>

<?php elseif ($result): ?>
  <div class="card">
    <h2>Файл загружен</h2>
    <p><b>ID датасета:</b> <?=h($result['dataset_id'])?></p>
    <p><b>Файл:</b> <?=h($result['original_filename'])?></p>
    <p><b>Offers:</b> <?=h($result['offers_count'])?></p>
    <p class="muted"><b>SHA256:</b> <?=h($result['sha256'])?></p>

    <h3>Предупреждения</h3>
    <ul>
      <li>offers без url: <b><?=h($result['warnings']['offers_missing_url'])?></b></li>
      <li>offers без product_id в url: <b><?=h($result['warnings']['offers_missing_product_id'])?></b></li>
    </ul>

    <h3>Примеры товаров</h3>
    <table>
      <thead>
        <tr><th>id</th><th>name</th><th>vendorCode</th><th>url</th></tr>
      </thead>
      <tbody>
      <?php foreach ($result['samples'] as $s): ?>
        <tr>
          <td><?=h($s['id'])?></td>
          <td><?=h($s['name'])?></td>
          <td><?=h($s['vendorCode'])?></td>
          <td><?=h($s['url'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p style="margin-top:14px;">
      <a href="view.php?id=<?=h($result['dataset_id'])?>">Открыть страницу датасета →</a>
    </p>
  </div>

<?php else: ?>
  <div class="card">
    <h2>Импорт XML</h2>
    <p class="muted">Эта страница используется после отправки формы загрузки. Вернись на главную и выбери XML-файл или ссылку.</p>
  </div>
<?php endif; ?>

</body>
</html>
