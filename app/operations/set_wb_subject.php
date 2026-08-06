<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';

function op_set_wb_category(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  return op_set_wb_category_impl($cfg, $ds, $opId, $params, $log);
}

function op_set_wb_subject(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  return op_set_wb_category_impl($cfg, $ds, $opId, $params, $log);
}

function op_set_wb_category_impl(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
  $tagName = 'wb_category';
  $legacyTagName = 'wb_subject_id';
  $datasetId = (int)$ds['id'];
  $inputPath = (string)($ds['stored_path'] ?? '');
  if ($inputPath === '' || !is_file($inputPath)) {
    throw new RuntimeException("Input XML not found: {$inputPath}");
  }

  $clearCategory = trim((string)($params['clear_category'] ?? '0'));
  $clearCategory = ($clearCategory !== '' && $clearCategory !== '0');

  $newVal = trim((string)($params['wb_category'] ?? ($params['wb_subject_id'] ?? '')));
  if (!$clearCategory && $newVal === '') {
    throw new RuntimeException("Param wb_category is required");
  }

  $autoDataset = (string)($params['auto_dataset'] ?? '1');
  $autoDataset = ($autoDataset !== '' && $autoDataset !== '0');

  $inplace = (string)($params['inplace'] ?? '1');
  $inplace = ($inplace !== '' && $inplace !== '0');

  $offerIds = [];
  if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
    foreach ($params['offer_ids'] as $v) {
      $s = trim((string)$v);
      if ($s !== '') $offerIds[] = $s;
    }
    $offerIds = array_values(array_unique($offerIds));
  }

  $applyAll = (count($offerIds) === 0);
  $offerSet = $applyAll ? null : array_fill_keys($offerIds, true);

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $outXmlAbs = $outDir . '/result.xml';

  $totalOffers = (int)($ds['offers_count'] ?? 0);
  if ($totalOffers <= 0) $totalOffers = 0;

  $log("set_wb_category: mode=" . ($clearCategory ? 'clear' : 'assign') . " value={$newVal}\n");
  $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));
  $log("mode: " . ($inplace ? "INPLACE\n" : "DERIVE\n"));

  ops_update_progress($opId, 0, ($totalOffers > 0 ? $totalOffers : 1), 'scan', 'reading input');

  $reader = new XMLReader();
  if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException("Cannot open XML: {$inputPath}");
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
  $targetOffer = false;
  $wbSeen = false;
  $skipNode = null;

  $seenOffers = 0;
  $updatedExisting = 0;
  $insertedNew = 0;
  $matchedTargets = 0;
  $progressTick = 0;

  while ($reader->read()) {
    if ($skipNode !== null) {
      if (
        $reader->nodeType === XMLReader::END_ELEMENT &&
        $reader->name === $skipNode['name'] &&
        $reader->depth === $skipNode['depth']
      ) {
        $skipNode = null;
      }
      continue;
    }

    switch ($reader->nodeType) {
      case XMLReader::ELEMENT: {
        $name = $reader->name;

        if ($name === 'offer') {
          $inOffer = true;
          $offerDepth = $reader->depth;
          $curOfferId = (string)$reader->getAttribute('id');
          $wbSeen = false;

          $targetOffer = $applyAll ? true : isset($offerSet[$curOfferId]);
          if ($targetOffer) $matchedTargets++;

          $writer->startElement($name);
          if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
              $writer->writeAttribute($reader->name, $reader->value);
            }
            $reader->moveToElement();
          }

          if ($reader->isEmptyElement) {
            if ($targetOffer && !$clearCategory) {
              $writer->writeElement($tagName, $newVal);
              $insertedNew++;
            }
            $writer->endElement();
            $inOffer = false;
            $targetOffer = false;
          }

          break;
        }

        if ($inOffer && $targetOffer && ($name === $tagName || $name === $legacyTagName)) {
          if (!$wbSeen) {
            $wbSeen = true;
            $updatedExisting++;
            if (!$clearCategory) {
              $writer->writeElement($tagName, $newVal);
            }
          }

          if (!$reader->isEmptyElement) {
            $skipNode = ['name' => $name, 'depth' => $reader->depth];
          }
          break;
        }

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

        if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
          if ($targetOffer && !$wbSeen && !$clearCategory) {
            $writer->writeElement($tagName, $newVal);
            $insertedNew++;
          }

          $writer->endElement();
          $seenOffers++;
          $progressTick++;

          if ($totalOffers > 0 && ($progressTick % 200 === 0)) {
            ops_update_progress($opId, $seenOffers, $totalOffers, 'scan', 'processing offers');
          }

          $inOffer = false;
          $targetOffer = false;
          $offerDepth = -1;
          $wbSeen = false;
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
    'write',
    'saving report'
  );

  $report = [
    'summary' => [
      'offers_total' => (int)($ds['offers_count'] ?? 0),
      'offers_seen' => $seenOffers,
      'scope' => $applyAll ? 'all' : 'selected',
      'selected_requested' => count($offerIds),
      'selected_matched_in_feed' => $applyAll ? null : $matchedTargets,
      'mode' => $clearCategory ? 'clear' : 'assign',
      'wb_category_value' => $newVal,
      'updated_existing_tag' => $updatedExisting,
      'inserted_new_tag' => $insertedNew,
    ],
    'params_effective' => [
      'wb_category' => $newVal,
      'clear_category' => $clearCategory ? '1' : '0',
      'auto_dataset' => $autoDataset ? '1' : '0',
    ],
  ];

  $reportAbs = $outDir . '/report.json';
  file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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

    $backupAbs = $outDir . '/backup_before_inplace.xml';
    @copy($dstAbs, $backupAbs);

    $dstDir = dirname($dstAbs);
    $tmpAbs = $dstDir . '/.tmp_inplace_' . (int)$opId . '_' . basename($dstAbs);

    if (!copy($outXmlAbs, $tmpAbs)) {
      throw new RuntimeException("Cannot write temp file for inplace update: {$tmpAbs}");
    }
    if (!rename($tmpAbs, $dstAbs)) {
      @unlink($tmpAbs);
      throw new RuntimeException("Cannot replace dataset XML (rename failed): {$dstAbs}");
    }

    $scan = scan_xml($dstAbs, 0);
    $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);
    $bytes = (int)filesize($dstAbs);

    $upd = db()->prepare("
      UPDATE feedtools_datasets
      SET bytes = ?, sha256 = ?, offers_count = ?, warnings_json = ?
      WHERE id = ?
    ");
    $upd->execute([
      $bytes,
      $newSha,
      (int)$scan['offers_count'],
      $warningsJson,
      (int)$ds['id'],
    ]);

    $ins = db()->prepare("
      INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
      VALUES (?, ?, ?, ?, 0)
    ");
    $ins->execute([(int)$opId, 'inplace_update', (int)$ds['id'], $newSha]);

    $log("INPLACE: dataset #{$ds['id']} updated. bytes={$bytes} sha256={$newSha}\n");
  }

  $derived = ['auto_dataset' => $autoDataset ? '1' : '0'];

  $createDerivedDataset = static function (array $cfg, int $opId, string $outputKey, string $srcAbs, callable $log): array {
    $sha256 = hash_file('sha256', $srcAbs);

    $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
    $stmt->execute([$sha256]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
      $ins = db()->prepare("
        INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
        VALUES (?, ?, ?, ?, 1)
      ");
      $ins->execute([(int)$opId, (string)$outputKey, (int)$existing, (string)$sha256]);

      $log("derived_dataset: duplicate of dataset #{$existing} (sha256 match)\n");
      return ['dataset_id' => (int)$existing, 'is_duplicate' => true, 'sha256' => $sha256];
    }

    $uploadsDir = (string)$cfg['paths']['uploads_dir'];
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true)) {
      throw new RuntimeException('Cannot create uploads dir: ' . $uploadsDir);
    }

    $originalFilename = "from_op_{$opId}_{$outputKey}.xml";
    $storedFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $originalFilename;
    $storedPath = rtrim($uploadsDir, '/\\') . '/' . $storedFilename;

    if (!copy($srcAbs, $storedPath)) {
      throw new RuntimeException('Copy to uploads failed');
    }

    $scan = scan_xml($storedPath, 0);
    $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);

    $stmt = db()->prepare("
      INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $originalFilename,
      $storedFilename,
      $storedPath,
      (int)filesize($storedPath),
      $sha256,
      (int)$scan['offers_count'],
      $warningsJson,
    ]);

    $newId = (int)db()->lastInsertId();

    $ins = db()->prepare("
      INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
      VALUES (?, ?, ?, ?, 0)
    ");
    $ins->execute([(int)$opId, (string)$outputKey, (int)$newId, (string)$sha256]);

    $log("derived_dataset: created dataset #{$newId}\n");

    return ['dataset_id' => (int)$newId, 'is_duplicate' => false, 'sha256' => $sha256];
  };

  if ($inplace) {
    file_put_contents(
      $outDir . '/derived_dataset.json',
      json_encode(['inplace' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
  }

  if (!$inplace && $autoDataset) {
    try {
      ops_update_progress(
        $opId,
        ($totalOffers > 0 ? min($seenOffers, $totalOffers) : $seenOffers),
        ($totalOffers > 0 ? $totalOffers : max(1, $seenOffers)),
        'dataset',
        'creating derived dataset'
      );
      $derived = $createDerivedDataset($cfg, $opId, 'result_xml', $outXmlAbs, $log);
      file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } catch (Throwable $e) {
      $log("derived_dataset: ERROR: " . $e->getMessage() . "\n");
      $derived = ['error' => $e->getMessage()];
      file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
  } else {
    file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  $summaryInline = [
    'title' => 'set_wb_category',
    'items' => [
      'Scope: ' . ($applyAll ? 'all offers' : ('selected ' . count($offerIds))),
      'Mode: ' . ($clearCategory ? 'clear' : 'assign'),
      'Value: ' . ($clearCategory ? '[cleared]' : $newVal),
      'Updated existing tag: ' . $updatedExisting,
      'Inserted new tag: ' . $insertedNew,
    ],
    'metrics' => [
      'updated_existing_tag' => $updatedExisting,
      'inserted_new_tag' => $insertedNew,
      'selected_requested' => count($offerIds),
      'selected_matched_in_feed' => $applyAll ? null : $matchedTargets,
      'derived_dataset' => $derived,
      'inplace' => $inplace ? 1 : 0,
      'updated_dataset_id' => $inplace ? (int)$ds['id'] : null,
    ],
  ];

  return [
    'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
    'report_json' => rel_to_outputs($cfg, $reportAbs),
    'summary_json_inline' => $summaryInline,
  ];
}
