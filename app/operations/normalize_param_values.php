<?php
declare(strict_types=1);

/**
 * normalize_param_values
 *
 * Назначение:
 * - заменять значения <param name="...">VALUE</param> и <wb_param name="...">VALUE</wb_param>
 *   на нормализованные значения для маркетплейсов по таблице соответствий в БД.
 *
 * Источник правил:
 *   feedtools_param_value_map(attr_name, old_value, new_value, is_active)
 *
 * Scope:
 * - по выбранным товарам (params['offer_ids']) или по всем товарам, если выбор пуст
 *
 * Режим:
 * - inplace=1 (по умолчанию) заменяет XML внутри текущего dataset
 * - auto_dataset=1 создаёт новый dataset при derive-режиме
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';
require_once __DIR__ . '/../paths.php';

function op_normalize_param_values(array $cfg, array $ds, int $opId, array $params, callable $log): array
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
      $s = trim((string)$v);
      if ($s !== '') $offerIds[] = $s;
    }
    $offerIds = array_values(array_unique($offerIds));
  }

  $applyAll = (count($offerIds) === 0);
  $offerSet = $applyAll ? null : array_fill_keys($offerIds, true);

  // ---- load mapping rules ----
  $rows = db()->query("SELECT attr_name, old_value, new_value FROM feedtools_param_value_map WHERE is_active=1")->fetchAll();
  if (!$rows) {
    throw new RuntimeException('No active mapping rules found in feedtools_param_value_map');
  }

  $norm = static function (string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower($s, 'UTF-8');
  };

  $compatibilityAttrNorms = [
    $norm('Совместимость') => true,
    $norm('compatibility') => true,
  ];

  $limitCompatibilityValue = static function (string $value, int $limit = 100): string {
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    if ($value === '' || mb_strlen($value, 'UTF-8') <= $limit) {
      return $value;
    }

    $cut = mb_substr($value, 0, $limit, 'UTF-8');
    $wordCut = preg_replace('/\s+\S*$/u', '', $cut);
    if (is_string($wordCut) && mb_strlen($wordCut, 'UTF-8') >= 70) {
      $cut = $wordCut;
    }

    $cut = rtrim($cut, " \t\n\r\0\x0B,;./\\|-");
    if ($cut === '') {
      $cut = mb_substr($value, 0, $limit, 'UTF-8');
    }
    return $cut;
  };

  // map[attr_norm][old_norm] = new_value
  $map = [];
  foreach ($rows as $r) {
    $attr = $norm((string)($r['attr_name'] ?? ''));
    $old  = $norm((string)($r['old_value'] ?? ''));
    $new  = trim((string)($r['new_value'] ?? ''));
    if ($attr === '' || $old === '' || $new === '') continue;
    $map[$attr][$old] = $new;
  }

  if (!$map) {
    throw new RuntimeException('Mapping rules are empty after normalization (check attr_name/old_value/new_value)');
  }

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $outXmlAbs = $outDir . '/result.xml';

  $totalOffers = (int)($ds['offers_count'] ?? 0);
  if ($totalOffers <= 0) $totalOffers = 0;

  $log("normalize_param_values: rules=" . count($rows) . "\n");
  $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));
  $log("mode: " . ($inplace ? "INPLACE\n" : "DERIVE\n"));
  if ($maxItems > 0) $log("max_items: {$maxItems}\n");

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

  $inParam = false;
  $paramDepth = -1;
  $paramElementName = '';
  $paramAttrNameNorm = '';
  // param text streaming state
  $paramHadContentWritten = false;      // whether we already processed the first meaningful text chunk
  $paramDidReplace = false;             // whether we replaced value (then we suppress remaining chunks)
  $paramSawMeaningfulText = false;      // whether we saw non-whitespace text inside <param>/<wb_param>
  $paramShouldReplace = false;
  $paramReplaceTo = '';

  // offer-level fields for auto-filling weight/dimensions when missing
  $curPrice = 0.0;
  $inPrice = false;
  $priceDepth = -1;
  $priceHadContent = false;

  $inWeight = false;
  $weightDepth = -1;
  $weightHadContent = false;

  $inDimensions = false;
  $dimensionsDepth = -1;
  $dimensionsHadContent = false;


  // offer-level brand + hashtags normalization
  $curBrand = '';
  $inVendor = false;
  $vendorDepth = -1;
  $vendorHadContent = false;

  $inHashtags = false;
  $hashtagsDepth = -1;
  $hashtagsHadContent = false;

  $normKey = static function (string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    // keep only letters/digits to make matching robust across spaces/_/-
    $s = preg_replace('/[^0-9a-zа-я]+/ui', '', $s);
    return $s ?? '';
  };

  $fixedWeightCount = 0;
  $fixedDimensionsCount = 0;
  $trimmedCompatibilityCount = 0;

  $pickDefaultsByPrice = static function (float $price): array {
    // Returns [weight, dimensions] as strings
    if ($price <= 5000) return ['0.15', '100/100/100'];
    if ($price <= 15000) return ['0.25', '200/150/100'];
    if ($price <= 30000) return ['0.5', '250/200/150'];
    return ['1', '350/200/200'];
  };

  $seenOffers = 0;
  $matchedTargets = 0;
  $processedTargets = 0;

  $replacedCount = 0;
  $replacedByAttr = [];
  $replacedByElement = ['param' => 0, 'wb_param' => 0];
  $logReplLimit = 500;

  $progressTick = 0;

  while ($reader->read()) {
    switch ($reader->nodeType) {

      case XMLReader::ELEMENT: {
        $name = $reader->name;

        if ($name === 'offer') {
          $inOffer = true;
          $offerDepth = $reader->depth;
          $curOfferId = (string)$reader->getAttribute('id');
          $curBrand = '';
          $inVendor = false; $vendorDepth = -1; $vendorHadContent = false;
          $inHashtags = false; $hashtagsDepth = -1; $hashtagsHadContent = false;

          $targetOffer = $applyAll ? true : isset($offerSet[(string)$curOfferId]);
          if ($targetOffer) {
            $matchedTargets++;
            if ($maxItems > 0 && $processedTargets >= $maxItems) {
              $targetOffer = false;
            }
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
            $inOffer = false;
            $targetOffer = false;
          }

          break;
        }

        if ($inOffer && $targetOffer && ($name === 'param' || $name === 'wb_param')) {
          // start marketplace param
          $inParam = true;
          $paramDepth = $reader->depth;
          $paramElementName = $name;
          $paramHadContentWritten = false;
          $paramDidReplace = false;
          $paramSawMeaningfulText = false;
          $paramShouldReplace = false;
          $paramReplaceTo = '';

          $rawAttrName = (string)$reader->getAttribute('name');
          $paramAttrNameNorm = $norm($rawAttrName);

          $writer->startElement($name);
          if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
              $writer->writeAttribute($reader->name, $reader->value);
            }
            $reader->moveToElement();
          }

          if ($reader->isEmptyElement) {
            // nothing to replace
            $writer->endElement();
            $inParam = false;
            $paramDepth = -1;
            $paramElementName = '';
            $paramAttrNameNorm = '';
          }

          break;
        }

        if ($inOffer && $targetOffer && ($name === 'price' || $name === 'weight' || $name === 'dimensions')) {
          // Track offer-level values for defaulting weight/dimensions
          if ($name === 'price') {
            $inPrice = true;
            $priceDepth = $reader->depth;
            $priceHadContent = false;
          } elseif ($name === 'weight') {
            $inWeight = true;
            $weightDepth = $reader->depth;
            $weightHadContent = false;
          } else { // dimensions
            $inDimensions = true;
            $dimensionsDepth = $reader->depth;
            $dimensionsHadContent = false;
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
            if ($name === 'price') { $inPrice = false; $priceDepth = -1; $priceHadContent = false; }
            if ($name === 'weight') { $inWeight = false; $weightDepth = -1; $weightHadContent = false; }
            if ($name === 'dimensions') { $inDimensions = false; $dimensionsDepth = -1; $dimensionsHadContent = false; }
          }
          break;
        }


        if ($inOffer && $targetOffer && ($name === 'vendor' || $name === 'hashtags')) {
          if ($name === 'vendor') {
            $inVendor = true;
            $vendorDepth = $reader->depth;
            $vendorHadContent = false;
          } else {
            $inHashtags = true;
            $hashtagsDepth = $reader->depth;
            $hashtagsHadContent = false;
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
            if ($name === 'vendor') { $inVendor = false; $vendorDepth = -1; $vendorHadContent = false; }
            if ($name === 'hashtags') { $inHashtags = false; $hashtagsDepth = -1; $hashtagsHadContent = false; }
          }
          break;
        }


        // default passthrough element
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

        if ($inParam && $name === $paramElementName && $reader->depth === $paramDepth) {
          $writer->endElement();
          $inParam = false;
          $paramDepth = -1;
          $paramElementName = '';
          $paramAttrNameNorm = '';
          $paramHadContentWritten = false;
          $paramShouldReplace = false;
          $paramReplaceTo = '';
          break;
        }
        if ($inPrice && $name === 'price' && $reader->depth === $priceDepth) {
          $writer->endElement();
          $inPrice = false;
          $priceDepth = -1;
          $priceHadContent = false;
          break;
        }
        if ($inWeight && $name === 'weight' && $reader->depth === $weightDepth) {
          $writer->endElement();
          $inWeight = false;
          $weightDepth = -1;
          $weightHadContent = false;
          break;
        }
        if ($inDimensions && $name === 'dimensions' && $reader->depth === $dimensionsDepth) {
          $writer->endElement();
          $inDimensions = false;
          $dimensionsDepth = -1;
          $dimensionsHadContent = false;
          break;
        }




        if ($inVendor && $name === 'vendor' && $reader->depth === $vendorDepth) {
          $writer->endElement();
          $inVendor = false;
          $vendorDepth = -1;
          $vendorHadContent = false;
          break;
        }
        if ($inHashtags && $name === 'hashtags' && $reader->depth === $hashtagsDepth) {
          $writer->endElement();
          $inHashtags = false;
          $hashtagsDepth = -1;
          $hashtagsHadContent = false;
          break;
        }

        if ($inOffer && $name === 'offer' && $reader->depth === $offerDepth) {
          $writer->endElement();
          $inOffer = false;
          $offerDepth = -1;
          $curOfferId = '';

          $curPrice = 0.0;

          if ($targetOffer) {
            $processedTargets++;
          }
          $targetOffer = false;

          $seenOffers++;
          $progressTick++;
          if ($progressTick >= 200) {
            $progressTick = 0;
            ops_update_progress(
              $opId,
              ($totalOffers > 0 ? min($seenOffers, $totalOffers) : $seenOffers),
              ($totalOffers > 0 ? $totalOffers : max(1, $seenOffers)),
              'scan',
              "offers={$seenOffers}, replaced={$replacedCount}"
            );
          }

          break;
        }

        $writer->endElement();
        break;
      }

      case XMLReader::TEXT:
      case XMLReader::CDATA: {

        if ($targetOffer) {
          if ($inPrice && !$priceHadContent) {
            $raw = trim((string)$reader->value);
            $raw = str_replace(',', '.', $raw);
            $curPrice = (float)$raw;
            if ($curPrice < 0) $curPrice = 0.0;
            // pass through price unchanged
            if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($reader->value);
            else $writer->text($reader->value);
            $priceHadContent = true;
            break;
          }

          if ($inWeight && !$weightHadContent) {
            $raw = trim((string)$reader->value);
            $rawN = str_replace(',', '.', $raw);
            $w = (float)$rawN;

            if (abs($w) < 1e-9) {
              // weight missing/zero -> fill by price
              [$defW, $defD] = $pickDefaultsByPrice($curPrice);
              $writer->text($defW);
              $fixedWeightCount++;
              $log("FILL weight offer={$curOfferId} price={$curPrice}: '{$raw}' -> '{$defW}'\n");
            } else {
              // дополнение: если weight >= 10, считаем что это граммы и переводим в кг
              if ($w >= 10) {
                $w2 = $w / 1000.0;
                $wStr = rtrim(rtrim(sprintf('%.6f', $w2), '0'), '.');
                if ($wStr === '') $wStr = '0';
                $writer->text($wStr);
                $log("CONVERT weight>=10 offer={$curOfferId}: '{$raw}' -> '{$wStr}'\n");
              } else {
                // pass through unchanged
                if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($reader->value);
                else $writer->text($reader->value);
              }
            }

            $weightHadContent = true;
            break;
          }

          if ($inDimensions && !$dimensionsHadContent) {
            $raw = trim((string)$reader->value);
            $rawCmp = preg_replace('/\s+/u', '', $raw);
            $handled = false;

            // 1) empty or fully zero -> fill by price defaults
            if ($rawCmp === '' || $rawCmp === '0/0/0') {
              [$defW, $defD] = $pickDefaultsByPrice($curPrice);
              $writer->text($defD);
              $fixedDimensionsCount++;
              $log("FILL dimensions offer={$curOfferId} price={$curPrice}: '{$raw}' -> '{$defD}'\\n");
              $handled = true;
            }

            // 2) if one or more components are 0 -> replace each 0 with average of the other two
            if (!$handled) {
              // allow formats like "100/0/50" (optionally with spaces)
              $parts = explode('/', $rawCmp);
              if (count($parts) === 3) {
                $vals = [];
                foreach ($parts as $p) {
                  $p = str_replace(',', '.', trim($p));
                  $vals[] = (is_numeric($p) ? (float)$p : null);
                }

                if (!in_array(null, $vals, true)) {
                  $nonZero = [];
                  foreach ($vals as $v) {
                    if (abs((float)$v) > 1e-9) $nonZero[] = (float)$v;
                  }

                  if (count($nonZero) === 2) {
                    $avg = ($nonZero[0] + $nonZero[1]) / 2.0;
                    for ($i = 0; $i < 3; $i++) {
                      if (abs((float)$vals[$i]) <= 1e-9) $vals[$i] = $avg;
                    }

                    // write as integers when possible (dimensions are usually mm)
                    $out = [];
                    foreach ($vals as $v) {
                      $v = (float)$v;
                      $vRound = round($v);
                      if (abs($v - $vRound) < 1e-6) {
                        $out[] = (string)(int)$vRound;
                      } else {
                        $out[] = rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
                      }
                    }

                    $newDim = implode('/', $out);
                    $writer->text($newDim);
                    $fixedDimensionsCount++;
                    $log("FIX dimensions(0-part) offer={$curOfferId}: '{$raw}' -> '{$newDim}'\\n");
                    $handled = true;
                  } elseif (count($nonZero) <= 1) {
                    // too little info -> fall back to defaults by price
                    [$defW, $defD] = $pickDefaultsByPrice($curPrice);
                    $writer->text($defD);
                    $fixedDimensionsCount++;
                    $log("FILL dimensions(insufficient non-zero parts) offer={$curOfferId} price={$curPrice}: '{$raw}' -> '{$defD}'\\n");
                    $handled = true;
                  }
                }
              }
            }

            // 3) passthrough
            if (!$handled) {
              if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($reader->value);
              else $writer->text($reader->value);
            }
            $dimensionsHadContent = true;
            break;
          }
        }


        if ($targetOffer) {
          if ($inVendor && !$vendorHadContent) {
            $val = trim((string)$reader->value);
            if ($val !== '') $curBrand = $val;
            // passthrough vendor unchanged
            if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($reader->value);
            else $writer->text($reader->value);
            $vendorHadContent = true;
            break;
          }

          if ($inHashtags && !$hashtagsHadContent) {
            $raw = (string)$reader->value;
            $brandKey = $normKey($curBrand);

            $parts = preg_split('/\s*,\s*/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
            $out = [];
            foreach ($parts as $p) {
              $p = trim($p);
              if ($p === '') continue;
              $tagText = ltrim($p, "# \t\n\r\0\x0B");
              $tagKey = $normKey($tagText);

              if ($brandKey !== '' && $tagKey !== '' && mb_stripos($tagKey, $brandKey, 0, 'UTF-8') !== false) {
                // drop hashtag containing brand
                continue;
              }
              $out[] = $p;
            }

            $new = implode(', ', $out);
            $writer->text($new);
            $hashtagsHadContent = true;
            break;
          }
        }

        if ($inParam && $targetOffer) {
          // param text replacement:
          // - decide replacement on first *meaningful* text chunk (non-whitespace)
          // - if we replaced, suppress remaining chunks to avoid mixing old/new
          // - if we didn't replace, pass through ALL chunks (to avoid truncation)
          $chunk = (string)$reader->value;

          // If this is only whitespace, pass through but do not "lock" the param.
          if (!$paramSawMeaningfulText && trim($chunk) === '') {
            if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($chunk);
            else $writer->text($chunk);
            break;
          }

          $paramSawMeaningfulText = true;

          if (!$paramHadContentWritten) {
            $orig = $chunk;
            $origNorm = $norm($orig);
            $new = null;
            $trimmedCompatibility = false;

            if ($paramAttrNameNorm !== '' && isset($map[$paramAttrNameNorm][$origNorm])) {
              $new = $map[$paramAttrNameNorm][$origNorm];
            }

            $final = ($new !== null && $new !== '') ? $new : null;
            $candidateForLimit = ($final !== null) ? $final : $orig;
            if ($paramAttrNameNorm !== '' && isset($compatibilityAttrNorms[$paramAttrNameNorm])) {
              $limited = $limitCompatibilityValue($candidateForLimit);
              if ($limited !== $candidateForLimit) {
                $final = $limited;
                $trimmedCompatibility = true;
              }
            }

            // capture brand for hashtag filtering (prefer normalized/replaced value)
            if ($paramAttrNameNorm === 'бренд') {
              $brandCandidate = ($final !== null && $final !== '') ? $final : $orig;
              if (trim($brandCandidate) !== '') $curBrand = trim($brandCandidate);
            }

            if ($final !== null && $final !== '' && $final !== $orig) {
              // write replacement and mark as replaced
              $writer->text($final);
              $paramDidReplace = true;

              $replacedCount++;
              if ($trimmedCompatibility) {
                $trimmedCompatibilityCount++;
              }
              $paramSource = ($paramElementName === 'wb_param') ? 'wb_param' : 'param';
              if (!isset($replacedByElement[$paramSource])) $replacedByElement[$paramSource] = 0;
              $replacedByElement[$paramSource]++;
              if (!isset($replacedByAttr[$paramAttrNameNorm])) $replacedByAttr[$paramAttrNameNorm] = 0;
              $replacedByAttr[$paramAttrNameNorm]++;
              if ($replacedCount <= $logReplLimit) {
                $log("REPLACE offer={$curOfferId} tag={$paramSource} attr={$paramAttrNameNorm}: '{$orig}' -> '{$final}'\n");
                if ($replacedCount === $logReplLimit) {
                  $log("REPLACE log limit reached ({$logReplLimit}). Further replacements will be counted but not logged.\n");
                }
              }
            } else {
              // pass through original chunk
              if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($chunk);
              else $writer->text($chunk);
            }

            $paramHadContentWritten = true;
          } else {
            // subsequent chunks
            if (!$paramDidReplace) {
              if ($reader->nodeType === XMLReader::CDATA) $writer->writeCData($chunk);
              else $writer->text($chunk);
            }
            // else: skip remaining chunks
          }

          break;
        }

        // default passthrough
        if ($reader->nodeType === XMLReader::CDATA) {
          $writer->writeCData($reader->value);
        } else {
          $writer->text($reader->value);
        }
        break;
      }

      case XMLReader::SIGNIFICANT_WHITESPACE:
      case XMLReader::WHITESPACE:
        $writer->text($reader->value);
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

  // report.json
  arsort($replacedByAttr);
  $top = [];
  $i = 0;
  foreach ($replacedByAttr as $k => $v) {
    $top[$k] = $v;
    $i++;
    if ($i >= 30) break;
  }

  $report = [
    'summary' => [
      'offers_total' => (int)($ds['offers_count'] ?? 0),
      'offers_seen' => $seenOffers,
      'scope' => $applyAll ? 'all' : 'selected',
      'selected_requested' => count($offerIds),
      'selected_matched_in_feed' => $applyAll ? null : $matchedTargets,
      'rules_active' => count($rows),
      'replaced_total' => $replacedCount,
      'replaced_param_total' => (int)($replacedByElement['param'] ?? 0),
      'replaced_wb_param_total' => (int)($replacedByElement['wb_param'] ?? 0),
      'fixed_weight_total' => $fixedWeightCount,
      'fixed_dimensions_total' => $fixedDimensionsCount,
      'trimmed_compatibility_total' => $trimmedCompatibilityCount,
      'replaced_by_attr_top30' => $top,
    ],
    'params_effective' => [
      'inplace' => $inplace ? '1' : '0',
      'auto_dataset' => $autoDataset ? '1' : '0',
      'max_items' => (string)$maxItems,
    ],
  ];

  $reportAbs = $outDir . '/report.json';
  file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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

    $upd = db()->prepare("\n      UPDATE feedtools_datasets\n      SET bytes = ?, sha256 = ?, offers_count = ?, warnings_json = ?\n      WHERE id = ?\n    ");
    $upd->execute([
      $bytes,
      $newSha,
      (int)$scan['offers_count'],
      $warningsJson,
      (int)$ds['id'],
    ]);

    $ins = db()->prepare("\n      INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)\n      VALUES (?, ?, ?, ?, 0)\n    ");
    $ins->execute([(int)$opId, 'inplace_update', (int)$ds['id'], $newSha]);

    $log("INPLACE: dataset #{$ds['id']} updated. bytes={$bytes} sha256={$newSha}\n");
  }

  // derived dataset support (same helper as in set_ozon_category)
  $derived = ['auto_dataset' => $autoDataset ? '1' : '0'];

  $createDerivedDataset = static function (array $cfg, int $opId, string $outputKey, string $srcAbs, callable $log): array {
    $sha256 = hash_file('sha256', $srcAbs);

    $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ?");
    $stmt->execute([$sha256]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
      $ins = db()->prepare("\n        INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)\n        VALUES (?, ?, ?, ?, 1)\n      ");
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

    $stmt = db()->prepare("\n      INSERT INTO feedtools_datasets (original_filename, stored_filename, stored_path, bytes, sha256, offers_count, warnings_json)\n      VALUES (?, ?, ?, ?, ?, ?, ?)\n    ");
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

    $ins = db()->prepare("\n      INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)\n      VALUES (?, ?, ?, ?, 0)\n    ");
    $ins->execute([(int)$opId, (string)$outputKey, (int)$newId, (string)$sha256]);

    $log("derived_dataset: created dataset #{$newId}\n");
    return ['dataset_id' => $newId, 'is_duplicate' => false, 'sha256' => $sha256];
  };

  if ($inplace) {
    file_put_contents($outDir . '/derived_dataset.json', json_encode(['inplace' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
      $log('derived_dataset: ERROR: ' . $e->getMessage() . "\n");
      $derived = ['error' => $e->getMessage()];
      file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
  } else {
    file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  $summaryInline = [
    'title' => 'normalize_param_values',
    'items' => [
      'Scope: ' . ($applyAll ? 'all offers' : ('selected ' . count($offerIds))),
      'Rules active: ' . count($rows),
      'Replaced: ' . $replacedCount,
      'Ozon param: ' . (int)($replacedByElement['param'] ?? 0),
      'WB param: ' . (int)($replacedByElement['wb_param'] ?? 0),
      'Compatibility trimmed: ' . $trimmedCompatibilityCount,
    ],
    'metrics' => [
      'rules_active' => count($rows),
      'replaced_total' => $replacedCount,
      'replaced_param_total' => (int)($replacedByElement['param'] ?? 0),
      'replaced_wb_param_total' => (int)($replacedByElement['wb_param'] ?? 0),
      'trimmed_compatibility_total' => $trimmedCompatibilityCount,
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
