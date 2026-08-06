<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';
require_once __DIR__ . '/../text_sanitize.php';

/**
 * sanitize_description_links
 *
 * Требования:
 * - Проверять описания (<description>) и искать в них ссылки на изображения.
 * - Каждую ссылку на изображение записывать в отдельный тег <picture>.
 * - Из описания удалить любые ссылки (URL/домены), номера телефонов,
 *   а также призывы позвонить/связаться/перейти по ссылке.
 */
function op_sanitize_description_links(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$ds['id'];
    $inputPath = (string)($ds['stored_path'] ?? '');
    if ($inputPath === '' || !is_file($inputPath)) {
        throw new RuntimeException("Input XML not found: {$inputPath}");
    }

    $autoDataset = (string)($params['auto_dataset'] ?? '0');
    $autoDataset = ($autoDataset !== '' && $autoDataset !== '0');

    $inplace = (string)($params['inplace'] ?? '1');
    $inplace = ($inplace !== '' && $inplace !== '0');

    $maxItems = (int)trim((string)($params['max_items'] ?? '0'));
    if ($maxItems < 0) $maxItems = 0;

    // selected offers (optional): run_op.php кладёт в params['offer_ids']
    $offerIds = [];
    if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
        foreach ($params['offer_ids'] as $v) {
            $s = (string)$v; // НЕ trim: id может быть чувствителен
            if ($s !== '') $offerIds[] = $s;
        }
        $uniq = [];
        foreach ($offerIds as $s) $uniq[$s] = true;
        $offerIds = array_keys($uniq);
    }
    $applyAll = (count($offerIds) === 0);
    $offerSet = $applyAll ? null : array_fill_keys($offerIds, true);

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);

    $outXmlAbs = $outDir . '/result.xml';

    $totalOffers = (int)($ds['offers_count'] ?? 0);
    if ($totalOffers < 0) $totalOffers = 0;

    $log("sanitize_description_links: inplace=" . ($inplace ? '1' : '0') . ", auto_dataset=" . ($autoDataset ? '1' : '0') . ", max_items={$maxItems}\n");
    $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));

    // ---- helpers ----

    $truncateToLimitPlain = static function (string $html, int $limit = 6000): string {
        // считаем длину без HTML-тегов
        $plain = strip_tags($html);
        $plain = ft_text_normalize_space((string)$plain);

        if (mb_strlen($plain, 'UTF-8') <= $limit) {
            return ft_text_normalize_space($html);
        }

        // аккуратная обрезка: режем по границе предложения/слова
        $cut = mb_substr($plain, 0, $limit, 'UTF-8');

        // пробуем отступить к последней "хорошей" границе
        $pos = -1;
        foreach (['. ', '! ', '? ', '; ', ': ', "\n", ' '] as $sep) {
            $p = mb_strrpos($cut, $sep, 0, 'UTF-8');
            if ($p !== false && $p > $pos) $pos = $p;
        }
        if ($pos !== -1 && $pos > (int)($limit * 0.7)) {
            $cut = mb_substr($cut, 0, $pos + 1, 'UTF-8');
        }

        $cut = rtrim($cut);
        // возвращаем уже "безопасный" текст без тегов (в description мы пишем CDATA)
        return ft_text_normalize_space($cut);
    };


    // ---- rewrite XML ----

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
    $curOfferId = '';
    $targetOffer = false;

    $inDescription = false;
    $descriptionDepth = -1;
    $descBuffer = '';
    $skipDescWrite = false;

    $inParam = false;
    $paramDepth = -1;
    $paramName = '';
    $paramBuffer = '';
    $skipParamWrite = false;

    $inPicture = false;
    $pictureDepth = -1;
    $pictureBuffer = '';

    $existingPictures = []; // url => true
    $foundPictures = [];    // url => true (из description)
    $picturesAddedInOffer = 0;

    $descProcessedThisOffer = false;
    $descChangedThisOffer = false;
    $paramProcessedThisOffer = false;
    $paramChangedThisOffer = false;

    $seenOffers = 0;
    $matchedTargets = 0;
    $offersTouched = 0;
    $offersDescProcessed = 0;
    $offersDescChangedCount = 0;
    $offersParamsProcessed = 0;
    $offersParamsChangedCount = 0;
    $picturesAddedTotal = 0;

    $progressTick = 0;

    while ($reader->read()) {
        switch ($reader->nodeType) {
            case XMLReader::ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer') {
                        $inOffer = true;
                        $offerDepth = $reader->depth;
                        $curOfferId = (string)$reader->getAttribute('id');

                        $targetOffer = $applyAll ? true : isset($offerSet[(string)$curOfferId]);
                        if ($targetOffer) $matchedTargets++;

                        // лимит: после достижения просто копируем как есть
                        if ($maxItems > 0 && $offersTouched >= $maxItems) {
                            $targetOffer = false;
                        }

                        // reset per-offer state
                        $inDescription = false;
                        $descriptionDepth = -1;
                        $descBuffer = '';
                        $skipDescWrite = false;

                        $inParam = false;
                        $paramDepth = -1;
                        $paramName = '';
                        $paramBuffer = '';
                        $skipParamWrite = false;

                        $inPicture = false;
                        $pictureDepth = -1;
                        $pictureBuffer = '';

                        $existingPictures = [];
                        $foundPictures = [];
                        $picturesAddedInOffer = 0;

                        $descProcessedThisOffer = false;
                        $descChangedThisOffer = false;
                        $paramProcessedThisOffer = false;
                        $paramChangedThisOffer = false;

                        $writer->startElement('offer');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            $writer->endElement();
                            $inOffer = false;
                            $targetOffer = false;
                            $curOfferId = '';
                            $offerDepth = -1;

                            $seenOffers++;
                            $progressTick++;
                        }
                        break;
                    }

                    if ($inOffer && $name === 'description') {
                        $inDescription = true;
                        $descriptionDepth = $reader->depth;
                        $descBuffer = '';
                        $skipDescWrite = $targetOffer;

                        if ($targetOffer) {
                            $descProcessedThisOffer = true;
                        }

                        $writer->startElement('description');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            $writer->endElement();
                            $inDescription = false;
                            $descriptionDepth = -1;
                            $skipDescWrite = false;
                        }
                        break;
                    }

                    if ($inOffer && $name === 'picture') {
                        $inPicture = true;
                        $pictureDepth = $reader->depth;
                        $pictureBuffer = '';

                        $writer->startElement('picture');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }
                        if ($reader->isEmptyElement) {
                            $writer->endElement();
                            $inPicture = false;
                            $pictureDepth = -1;
                        }
                        break;
                    }

                    if ($inOffer && $name === 'param') {
                        $inParam = true;
                        $paramDepth = $reader->depth;
                        $paramName = (string)$reader->getAttribute('name');
                        $paramBuffer = '';
                        $skipParamWrite = $targetOffer;

                        if ($targetOffer) {
                            $paramProcessedThisOffer = true;
                        }

                        $writer->startElement('param');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            $writer->endElement();
                            $inParam = false;
                            $paramDepth = -1;
                            $paramName = '';
                            $paramBuffer = '';
                            $skipParamWrite = false;
                        }
                        break;
                    }

                    // generic element copy
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

            case XMLReader::TEXT:
            case XMLReader::SIGNIFICANT_WHITESPACE:
            case XMLReader::WHITESPACE: {
                    if ($inOffer && $inDescription && $skipDescWrite) {
                        $descBuffer .= $reader->value;
                        break;
                    }
                    if ($inOffer && $inParam && $skipParamWrite) {
                        $paramBuffer .= $reader->value;
                        break;
                    }
                    if ($inOffer && $inPicture) {
                        $pictureBuffer .= $reader->value;
                    }
                    $writer->text($reader->value);
                    break;
                }

            case XMLReader::CDATA: {
                    if ($inOffer && $inDescription && $skipDescWrite) {
                        $descBuffer .= $reader->value;
                        break;
                    }
                    if ($inOffer && $inParam && $skipParamWrite) {
                        $paramBuffer .= $reader->value;
                        break;
                    }
                    if ($inOffer && $inPicture) {
                        $pictureBuffer .= $reader->value;
                    }
                    $writer->writeCData($reader->value);
                    break;
                }

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    if ($inOffer && $inPicture && $name === 'picture' && $reader->depth === $pictureDepth) {
                        $u = trim((string)$pictureBuffer);
                        if ($u !== '') $existingPictures[$u] = true;
                        $writer->endElement();
                        $inPicture = false;
                        $pictureDepth = -1;
                        $pictureBuffer = '';
                        break;
                    }

                    if ($inOffer && $inDescription && $name === 'description' && $reader->depth === $descriptionDepth) {
                        if ($targetOffer) {
                            $offersDescProcessed++;

                            $raw = (string)$descBuffer;
                            $urls = ft_extract_urls($raw);

                            foreach ($urls as $u) {
                                $u2 = trim($u);
                                if ($u2 === '') continue;
                                if (ft_looks_like_image_url($u2)) {
                                    $foundPictures[$u2] = true;
                                }
                            }

                            $clean = ft_sanitize_markupish_text($raw);

                            // лимит 6000 символов без HTML-тегов (после очистки)
                            $plainClean = strip_tags($clean);
                            $plainClean = ft_text_normalize_space((string)$plainClean);

                            if (mb_strlen($plainClean, 'UTF-8') <= 6000) {
                                $clean2 = $clean; // ✅ сохраняем HTML
                            } else {
                                // fallback: режем по тексту (HTML структуру полностью сохранить без DOM-обрезки сложно)
                                $clean2 = $truncateToLimitPlain($plainClean, 6000);
                                // можно завернуть в <p> чтобы не выглядело “сырым”
                                $clean2 = '<p>' . htmlspecialchars($clean2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                            }


                            $orig = ft_markup_to_plain_text($raw);
                            $descChangedThisOffer = (ft_markup_to_plain_text($clean2) !== $orig);

                            if ($descChangedThisOffer) $offersDescChangedCount++;

                            if ($clean2 !== '') {
                                $writer->writeCData($clean2);
                            }
                        }

                        $writer->endElement();
                        $inDescription = false;
                        $descriptionDepth = -1;
                        $skipDescWrite = false;
                        $descBuffer = '';
                        break;
                    }

                    if ($inOffer && $inParam && $name === 'param' && $reader->depth === $paramDepth) {
                        if ($targetOffer) {
                            $offersParamsProcessed++;
                            $rawParam = (string)$paramBuffer;
                            $paramNameNorm = ft_text_sanitize_lc(trim((string)$paramName));
                            $preserveLinks = ($paramNameNorm === ft_text_sanitize_lc('видео'));
                            $cleanParam = $preserveLinks
                                ? ft_text_normalize_inline($rawParam)
                                : ft_sanitize_plain_text($rawParam);
                            $paramChangedThisOffer = $paramChangedThisOffer || ($cleanParam !== ft_text_normalize_space($rawParam));
                            if ($cleanParam !== ft_text_normalize_space($rawParam)) {
                                $offersParamsChangedCount++;
                            }
                            if ($cleanParam !== '') {
                                $writer->text($cleanParam);
                            }
                        }

                        $writer->endElement();
                        $inParam = false;
                        $paramDepth = -1;
                        $paramName = '';
                        $paramBuffer = '';
                        $skipParamWrite = false;
                        break;
                    }

                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        if ($targetOffer && count($foundPictures) > 0) {
                            foreach ($foundPictures as $u => $_) {
                                if (isset($existingPictures[$u])) continue;
                                $writer->writeElement('picture', $u);
                                $picturesAddedInOffer++;
                                $picturesAddedTotal++;
                            }
                        }

                        if ($targetOffer && ($picturesAddedInOffer > 0 || $descChangedThisOffer || $descProcessedThisOffer || $paramChangedThisOffer || $paramProcessedThisOffer)) {
                            $offersTouched++;
                        }

                        $writer->endElement(); // </offer>

                        $seenOffers++;
                        $progressTick++;
                        if ($totalOffers > 0 && ($progressTick % 200 === 0)) {
                            ops_update_progress($opId, $seenOffers, $totalOffers, 'scan', 'processing offers');
                        }

                        $inOffer = false;
                        $targetOffer = false;
                        $curOfferId = '';
                        $offerDepth = -1;

                        $inDescription = false;
                        $descriptionDepth = -1;
                        $descBuffer = '';
                        $skipDescWrite = false;

                        $inParam = false;
                        $paramDepth = -1;
                        $paramName = '';
                        $paramBuffer = '';
                        $skipParamWrite = false;

                        $inPicture = false;
                        $pictureDepth = -1;
                        $pictureBuffer = '';
                        $existingPictures = [];
                        $foundPictures = [];
                        $picturesAddedInOffer = 0;

                        $descProcessedThisOffer = false;
                        $descChangedThisOffer = false;
                        $paramProcessedThisOffer = false;
                        $paramChangedThisOffer = false;

                        break;
                    }

                    $writer->endElement();
                    break;
                }

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
            'offers_touched' => $offersTouched,
            'offers_desc_processed' => $offersDescProcessed,
            'offers_desc_changed' => $offersDescChangedCount,
            'params_processed' => $offersParamsProcessed,
            'params_changed' => $offersParamsChangedCount,
            'pictures_added_total' => $picturesAddedTotal,
        ],
        'params_effective' => [
            'inplace' => $inplace ? '1' : '0',
            'auto_dataset' => $autoDataset ? '1' : '0',
            'max_items' => (string)$maxItems,
        ],
    ];
    file_put_contents($outDir . '/report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ---- inplace update: replace current dataset xml and update dataset row ----
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
            throw new RuntimeException("In-place update blocked: result duplicates dataset #{$dupId} (sha256 match). ");
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

    // auto create derived dataset (optional)
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
        $dstAbs = $uploadsDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $originalFilename;

        if (!copy($srcAbs, $dstAbs)) {
            throw new RuntimeException('Cannot copy derived dataset xml');
        }

        $scan = scan_xml($dstAbs, 0);
        $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);

        $bytes = (int)filesize($dstAbs);

        $insDs = db()->prepare("
          INSERT INTO feedtools_datasets (created_at, original_filename, stored_path, bytes, sha256, offers_count, warnings_json)
          VALUES (NOW(), ?, ?, ?, ?, ?, ?)
        ");
        $insDs->execute([
            $originalFilename,
            $dstAbs,
            $bytes,
            $sha256,
            (int)$scan['offers_count'],
            $warningsJson,
        ]);

        $newId = (int)db()->lastInsertId();

        $insDer = db()->prepare("
          INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
          VALUES (?, ?, ?, ?, 0)
        ");
        $insDer->execute([(int)$opId, (string)$outputKey, $newId, $sha256]);

        $log("derived_dataset: created dataset #{$newId} sha256={$sha256}\n");
        return ['dataset_id' => $newId, 'is_duplicate' => false, 'sha256' => $sha256];
    };

    if ($autoDataset) {
        $derived['derived_dataset'] = $createDerivedDataset($cfg, $opId, 'result', $outXmlAbs, $log);
    }

    return [
        'result_xml' => $outXmlAbs,
        'output_dir' => $outDir,
        'derived' => $derived,
    ];
}
