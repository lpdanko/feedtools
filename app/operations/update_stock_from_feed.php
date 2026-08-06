<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../xml_scan.php';

function op_update_stock_from_feed(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $datasetId = (int)$ds['id'];
  $inputPath = (string)($ds['stored_path'] ?? '');
  if ($inputPath === '' || !is_file($inputPath)) {
    throw new RuntimeException("Input XML not found: {$inputPath}");
  }

  $feedPath = trim((string)($params['feed_path'] ?? ''));
  if ($feedPath === '' || !is_file($feedPath)) {
    throw new RuntimeException("feed_path not found: {$feedPath}");
  }

  // ВАЖНО (новая логика по требованию):
  // 1) Сначала считаем, что у ВСЕХ offer остаток = 0.
  // 2) Потом, если offer найден в новом фиде и там есть остаток (stock/count), обновляем на это значение.
  // То есть: если offer отсутствует в новом фиде (по offer_id) — в результате будет 0.
  // Параметр missing_mode больше не влияет на поведение (оставлен только для совместимости в report).
  $missingMode = 'zero_all_then_update';
  $missingZeroed = 0; // сколько offer НЕ найдено в новом фиде (т.е. осталось 0)

  $inplace = (string)($params['inplace'] ?? '1');
  $inplace = ($inplace !== '' && $inplace !== '0');

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $outXmlAbs = $outDir . '/result.xml';

  $totalOffers = (int)($ds['offers_count'] ?? 0);
  ops_update_progress($opId, 0, ($totalOffers > 0 ? $totalOffers : 1), 'scan', 'reading stock feed');

  // 1) читаем новый фид и собираем map: offer_id => остаток
  // В обоих фидах остатки могут быть в <stock> или <count>.
  // Приоритет чтения из нового фида: stock > count (если stock есть и не пустой).
  $log("update_stock_from_feed: reading feed_path={$feedPath}\n");

  $qtyMap = [];  // id => string qty
  $seenFeedOffers = 0;
  $hasQty = 0;

  $r = new XMLReader();
  if (!$r->open($feedPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException("Cannot open stock feed XML: {$feedPath}");
  }

  $inOffer = false;
  $offerDepth = -1;
  $curId = '';
  $curQty = null;          // string|null
  $curQtySource = '';      // 'stock'|'count'|''

  while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'offer') {
      $inOffer = true;
      $offerDepth = $r->depth;
      $curId = trim((string)$r->getAttribute('id'));
      $curQty = null;
      $curQtySource = '';
      $seenFeedOffers++;
      continue;
    }

    if ($inOffer && $r->nodeType === XMLReader::ELEMENT && ($r->name === 'stock' || $r->name === 'count')) {
      $tag = $r->name; // stock|count
      $val = $r->isEmptyElement ? '' : trim(html_entity_decode($r->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($curId !== '' && $val !== '') {
        // приоритет: stock побеждает count
        if ($curQty === null) {
          $curQty = $val;
          $curQtySource = $tag;
        } elseif ($tag === 'stock' && $curQtySource !== 'stock') {
          $curQty = $val;
          $curQtySource = 'stock';
        } elseif ($tag === $curQtySource) {
          // если тег тот же — последнее значение побеждает
          $curQty = $val;
        }
      }
      continue;
    }

    if ($inOffer && $r->nodeType === XMLReader::END_ELEMENT && $r->name === 'offer' && $r->depth === $offerDepth) {
      if ($curId !== '' && $curQty !== null && $curQty !== '') {
        $qtyMap[$curId] = (string)$curQty;
        $hasQty++;
      }
      $inOffer = false;
      $offerDepth = -1;
      $curId = '';
      $curQty = null;
      $curQtySource = '';
      continue;
    }
  }

  $r->close();

  $log("feed offers seen: {$seenFeedOffers}\n");
  $log("feed offers with qty: {$hasQty}\n");
  $log("qtyMap size: " . count($qtyMap) . "\n");

  ops_update_progress($opId, 0, ($totalOffers > 0 ? $totalOffers : 1), 'write', 'updating dataset xml');

  // 2) переписываем текущий dataset XML, обновляя/добавляя <stock>
  $reader = new XMLReader();
  if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException("Cannot open dataset XML: {$inputPath}");
  }

  $writer = new XMLWriter();
  if (!$writer->openURI($outXmlAbs)) {
    $reader->close();
    throw new RuntimeException("Cannot write output: {$outXmlAbs}");
  }

  $writer->startDocument('1.0', 'UTF-8');
  $writer->setIndent(false);

  $inOffer = false;
  $offerDepth = -1;
  $curOfferId = '';
  $stockSeen = false;
  $countSeen = false;
  $newQtyVal = null;
  $skipEndName = null;
  $skipEndDepth = null;

  $seenOffers = 0;
  $updatedExistingStock = 0;
  $updatedExistingCount = 0;
  $insertedNewStock = 0;
  $insertedNewCount = 0;
  $matched = 0;

  $progressTick = 0;

  while ($reader->read()) {
    switch ($reader->nodeType) {
      case XMLReader::ELEMENT: {
        $name = $reader->name;

        if ($name === 'offer') {
          $inOffer = true;
          $offerDepth = $reader->depth;
          $curOfferId = trim((string)$reader->getAttribute('id'));
          $stockSeen = false;
          $countSeen = false;

          // базово: всем offer ставим 0, затем при наличии в новом фиде — подменяем
          if ($curOfferId !== '' && isset($qtyMap[$curOfferId])) {
            $newQtyVal = (string)$qtyMap[$curOfferId];
            $matched++;
          } elseif ($curOfferId !== '') {
            $newQtyVal = '0';
            $missingZeroed++;
          } else {
            // offer без id — не трогаем остатки
            $newQtyVal = null;
          }

          $writer->startElement($name);
          if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
              $writer->writeAttribute($reader->name, $reader->value);
            }
            $reader->moveToElement();
          }

          if ($reader->isEmptyElement) {
            // пустой offer: вставим stock (0 или значение из нового фида)
            if ($newQtyVal !== null && $newQtyVal !== '') {
              $writer->writeElement('stock', $newQtyVal);
              $insertedNewStock++;
            }
            $writer->endElement();
            $inOffer = false;
            $offerDepth = -1;
            $curOfferId = '';
            $stockSeen = false;
            $countSeen = false;
            $newQtyVal = null;
          }

          break;
        }

        // помечаем наличие тегов в исходном offer (чтобы не создавать дубликаты при режиме keep)
        if ($inOffer && $name === 'stock') { $stockSeen = true; }
        if ($inOffer && $name === 'count') { $countSeen = true; }

// заменить stock/count внутри offer (обновляем только те теги, которые уже есть в исходном XML)
        if ($inOffer && ($name === 'stock' || $name === 'count') && $newQtyVal !== null && $newQtyVal !== '') {
          if ($name === 'stock') {
            $stockSeen = true;
            $updatedExistingStock++;
          } else {
            $countSeen = true;
            $updatedExistingCount++;
          }

          if (!$reader->isEmptyElement) {
            $reader->readString(); // consume old value; reader moves to END_ELEMENT
          }
          $writer->writeElement($name, $newQtyVal);
          // For safety: if XMLReader emits END_ELEMENT for this node, skip writer->endElement() for it.
          $skipEndName = $name;
          $skipEndDepth = $reader->depth;
          break;
        }

        // обычное копирование узла
        $writer->startElement($name);
        if ($reader->hasAttributes) {
          while ($reader->moveToNextAttribute()) {
            $writer->writeAttribute($reader->name, $reader->value);
          }
          $reader->moveToElement();
        }
        if ($reader->isEmptyElement) {
          $writer->endElement();
        }
        break;
      }

      case XMLReader::END_ELEMENT: {
        $name = $reader->name;

        if ($skipEndName !== null && $name === $skipEndName && $reader->depth === $skipEndDepth) {
          // we already wrote a full <name>..</name> via writeElement()
          $skipEndName = null;
          $skipEndDepth = null;
          break;
        }

        if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
          // если stock/count не было — добавим перед закрытием offer
          // Требование: "заполнять то, что уже было в первом фиде".
          // Поэтому:
          // - если в исходном offer был stock — он уже будет обновлён/сохранён;
          // - если был count — он будет обновлён/сохранён;
          // - если не было ни stock ни count — добавим stock.
          if (!$stockSeen && !$countSeen && $newQtyVal !== null && $newQtyVal !== '') {
            $writer->writeElement('stock', $newQtyVal);
            $insertedNewStock++;
          }

          $writer->endElement(); // </offer>

          $seenOffers++;
          $progressTick++;

          if ($totalOffers > 0 && ($progressTick % 200 === 0)) {
            ops_update_progress($opId, $seenOffers, $totalOffers, 'write', 'processing offers');
          }

          $inOffer = false;
          $offerDepth = -1;
          $curOfferId = '';
          $stockSeen = false;
          $countSeen = false;
          $newQtyVal = null;

          break;
        }

        $writer->endElement();
        break;
      }

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

      default:
        break;
    }
  }

  $reader->close();
  $writer->endDocument();
  $writer->flush();

  ops_update_progress(
    $opId,
    ($totalOffers > 0 ? min($seenOffers, $totalOffers) : $seenOffers),
    ($totalOffers > 0 ? $totalOffers : max(1, $seenOffers)),
    'finalize',
    'saving report'
  );

  // report.json
  $report = [
    'summary' => [
      'dataset_offers_total' => (int)($ds['offers_count'] ?? 0),
      'dataset_offers_seen' => $seenOffers,
      'feed_offers_seen' => $seenFeedOffers,
      'feed_offers_with_qty' => $hasQty,
      'matched_offers_in_dataset' => $matched,
      'updated_existing_stock' => $updatedExistingStock,
      'updated_existing_count' => $updatedExistingCount,
      'inserted_new_stock' => $insertedNewStock,
      'inserted_new_count' => $insertedNewCount,
      'missing_mode' => $missingMode,
      'missing_zeroed_offers' => $missingZeroed,
      'inplace' => $inplace ? 1 : 0,
    ],
    // helpful for debugging runners that filter params
    'params_raw' => $params,
    'params_effective' => [
      'feed_path' => $feedPath,
      'inplace' => $inplace ? '1' : '0',
      'missing_mode' => $missingMode,
    ],
  ];
  file_put_contents($outDir . '/report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  // --- inplace: заменить XML у текущего датасета ---
  if ($inplace) {
    $dstAbs = (string)($ds['stored_path'] ?? '');
    if ($dstAbs === '' || !is_file($dstAbs)) {
      throw new RuntimeException("Dataset stored_path not found: {$dstAbs}");
    }

    $newSha = hash_file('sha256', $outXmlAbs);
    $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ? AND id <> ?");
    $stmt->execute([$newSha, (int)$ds['id']]);
    $dupId = $stmt->fetchColumn();
    if ($dupId) {
      throw new RuntimeException("In-place update blocked: result duplicates dataset #{$dupId} (sha256 match).");
    }

    // backup
    @copy($dstAbs, $outDir . '/backup_before_inplace.xml');

    // atomic replace
    $dstDir = dirname($dstAbs);
    $tmpAbs = $dstDir . '/.tmp_inplace_' . (int)$opId . '_' . basename($dstAbs);

    if (!copy($outXmlAbs, $tmpAbs)) {
      throw new RuntimeException("Cannot write temp file for inplace update: {$tmpAbs}");
    }
    if (!rename($tmpAbs, $dstAbs)) {
      @unlink($tmpAbs);
      throw new RuntimeException("Cannot replace dataset XML (rename failed): {$dstAbs}");
    }

    // rescan + update dataset row
    $scan = scan_xml($dstAbs, (int)($cfg['limits']['sample_offers'] ?? 5));
    $stmt = db()->prepare("
      UPDATE feedtools_datasets
      SET sha256 = ?, bytes = ?, offers_count = ?, warnings_json = ?
      WHERE id = ?
    ");
    $stmt->execute([
      $newSha,
      (int)filesize($dstAbs),
      (int)$scan['offers_count'],
      json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE),
      (int)$ds['id'],
    ]);

    $log("INPLACE done: dataset xml replaced\n");
  }

  return [
    'result_xml' => $outXmlAbs,
    'report_json' => $outDir . '/report.json',
  ];
}
