<?php

declare(strict_types=1);

require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';
require_once __DIR__ . '/../llm/PromptTemplates.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../xml_scan.php';

/**
 * gpt_rewrite_title_marketplace
 *
 * По аналогии с gpt_optimize_title_marketplace:
 * - обрабатывает все offer/name без фильтра по кириллице во входе
 * - использует промпт app/llm/prompts/title_rewrite_marketplace.txt
 *
 * Отличие:
 * - просим GPT не "оптимизировать", а ПЕРЕПИСАТЬ название так, чтобы оно было другим,
 *   но оставалось корректным и качественным.
 * - добавлена проверка "название реально изменилось" (не равно и не слишком похоже).
 *
 * Выход:
 * - result_xml (в outputs)
 * - report_json
 * - gpt_results_jsonl / gpt_raw_jsonl
 * - changes_csv
 *
 * По умолчанию inplace=1: перезаписывает текущий stored_path датасета атомарно.
 */
function op_gpt_rewrite_title_marketplace(array $cfg, array $datasetRow, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$datasetRow['id'];
    $inputPath = (string)($datasetRow['stored_path'] ?? '');

    if ($inputPath === '' || !is_file($inputPath)) {
        throw new RuntimeException("Input XML not found: {$inputPath}");
    }

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);

    $model = LLM::modelForOp($cfg, $params);

    $maxItems = isset($params['max_items']) ? (int)$params['max_items'] : 0; // 0 = no limit
    if ($maxItems < 0) $maxItems = 0;

    // inplace update (default = 1)
    $inplace = (string)($params['inplace'] ?? '1');
    $inplace = ($inplace !== '' && $inplace !== '0');

    // max_output_tokens (optional)
    $maxOut = isset($params['max_output_tokens']) ? (int)$params['max_output_tokens'] : 0;
    if ($maxOut < 0) $maxOut = 0;

    // Для текущей версии важнее точность, чем уникализация. По умолчанию не отбрасываем
    // корректные названия только потому, что они похожи на исходные.
    $similarityMax = isset($params['similarity_max']) ? (float)$params['similarity_max'] : 100.0;
    if ($similarityMax < 0) $similarityMax = 0;
    if ($similarityMax > 100) $similarityMax = 100;

    // retry count (0..3). default: 0, чтобы не подталкивать GPT к рискованной уникализации.
    $retries = isset($params['retries']) ? (int)$params['retries'] : 0;
    if ($retries < 0) $retries = 0;
    if ($retries > 3) $retries = 3;

    // --- selected offers filter (optional) ---
    $selectedSet = null;
    $selectedRequested = 0;

    if (isset($params['offer_ids']) && is_array($params['offer_ids'])) {
        $tmp = [];
        foreach ($params['offer_ids'] as $v) {
            $s = trim((string)$v);
            if ($s === '') continue;
            $tmp[$s] = true;
        }
        if ($tmp) {
            $selectedSet = $tmp;
            $selectedRequested = count($tmp);
        }
    }

    $log(
        "gpt_rewrite_title_marketplace: model={$model}, max_items={$maxItems}, inplace=" .
        ($inplace ? '1' : '0') .
        ", similarity_max={$similarityMax}, retries={$retries}\n"
    );

    // --- load prompt ---
    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'title_rewrite_marketplace.txt');

    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    // --- helpers ---
    $hasCyrillicRun5 = static function (string $s): bool {
        return (bool)preg_match('/\p{Cyrillic}{5}/u', $s);
    };

    $norm = static function (string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = mb_strtolower($s, 'UTF-8');
        return $s;
    };

    $calcSimilarityPercent = static function (string $a, string $b) use ($norm): float {
        $a = $norm($a);
        $b = $norm($b);
        if ($a === '' && $b === '') return 100.0;
        if ($a === '' || $b === '') return 0.0;

        $percent = 0.0;
        similar_text($a, $b, $percent);
        return (float)$percent;
    };

    $extractJsonFromText = static function (string $text): array {
        $text = trim($text);

        $i = strpos($text, '{');
        $j = strrpos($text, '}');

        if ($i === false || $j === false || $j <= $i) {
            throw new RuntimeException('JSON braces not found in GPT output');
        }

        $candidate = substr($text, $i, $j - $i + 1);

        $obj = json_decode($candidate, true);
        if (!is_array($obj)) {
            throw new RuntimeException('GPT output is not valid JSON');
        }

        return $obj;
    };

    $extractUsage = static function (?array $raw): array {
        $in = 0;
        $out = 0;
        $cached = 0;

        if (!$raw) return ['input' => 0, 'output' => 0, 'cached_input' => 0];

        $u = $raw['usage'] ?? null;
        if (is_array($u)) {
            $in = (int)($u['input_tokens'] ?? 0);
            $out = (int)($u['output_tokens'] ?? 0);

            $details = $u['input_tokens_details'] ?? null;
            if (is_array($details)) {
                $cached = (int)($details['cached_tokens'] ?? 0);
                if ($cached === 0) {
                    $cached = (int)($details['cached_input_tokens'] ?? 0);
                }
            }
        }

        return ['input' => $in, 'output' => $out, 'cached_input' => $cached];
    };

    $calcCostUSD = static fn(int $inputTokens, int $cachedInputTokens, int $outputTokens): float
        => openai_cost_usd($cfg, $model, $inputTokens, $cachedInputTokens, $outputTokens);

    $getFirstChildByTag = static function (DOMElement $el, string $tag): ?DOMElement {
        foreach ($el->childNodes as $ch) {
            if ($ch instanceof DOMElement && $ch->tagName === $tag) return $ch;
        }
        return null;
    };

    $extractOfferData = static function (DOMElement $offerEl): array {
        $fields = [];
        $paramsArr = [];
        $description = '';

        foreach ($offerEl->childNodes as $ch) {
            if (!($ch instanceof DOMElement)) continue;

            $tag = (string)$ch->tagName;

            if ($tag === 'param') {
                $pName = trim((string)$ch->getAttribute('name'));
                $pVal = trim((string)$ch->textContent);
                if ($pName !== '' && $pVal !== '') {
                    $paramsArr[] = ['name' => $pName, 'value' => $pVal];
                }
                continue;
            }

            if ($tag === 'description') {
                $description = trim((string)$ch->textContent);
                $description = html_entity_decode($description, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $description = trim((string)preg_replace('/\s+/u', ' ', strip_tags($description)));
                if (mb_strlen($description, 'UTF-8') > 1800) {
                    $description = mb_substr($description, 0, 1800, 'UTF-8') . '…';
                }
                continue;
            }

            // name передаём отдельно как original_name
            if ($tag === 'name') continue;

            $val = trim((string)$ch->textContent);
            if ($val === '') continue;

            // ограничим, чтобы не раздувать prompt
            if (mb_strlen($val, 'UTF-8') > 220) {
                $val = mb_substr($val, 0, 220, 'UTF-8') . '…';
            }

            if (!array_key_exists($tag, $fields)) {
                $fields[$tag] = $val;
            }
        }

        $vendor = $fields['vendor'] ?? ($fields['brand'] ?? '');
        $brand = $fields['brand'] ?? ($fields['vendor'] ?? '');
        $model = $fields['model'] ?? '';

        return [
            'vendor' => $vendor,
            'brand' => $brand,
            'model' => $model,
            'description' => $description,
            'fields' => $fields,
            'params' => $paramsArr,
        ];
    };

    // --- load XML ---
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;

    if (!$doc->load($inputPath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        throw new RuntimeException('Cannot parse XML. First error: ' . ($errs ? trim($errs[0]->message) : 'unknown'));
    }

    $offers = $doc->getElementsByTagName('offer');
    $offersTotal = (int)$offers->length;

    // --- cancel ---
    $cancelFlagAbs = $outDir . '/cancel.flag';
    $cancelled = false;

    $checkCancel = static function () use ($cancelFlagAbs): bool {
        clearstatcache(true, $cancelFlagAbs);
        return is_file($cancelFlagAbs);
    };

    // first pass: count candidates (non-empty name) (only selected offers if provided)
    $needFix = 0;
    $scopeOffersTotal = 0;

    for ($i = 0; $i < $offersTotal; $i++) {
        $offer = $offers->item($i);
        if (!($offer instanceof DOMElement)) continue;

        $offerIdScan = (string)$offer->getAttribute('id');
        if ($selectedSet !== null && !isset($selectedSet[$offerIdScan])) {
            continue;
        }
        $scopeOffersTotal++;

        $nameEl = $getFirstChildByTag($offer, 'name');
        if (!$nameEl) continue;

        $name = trim((string)$nameEl->textContent);
        if ($name === '') continue;

        $needFix++;
    }

    if ($selectedSet === null) {
        $scopeOffersTotal = $offersTotal;
    }

    $totalToProcess = $needFix;
    if ($maxItems > 0) $totalToProcess = min($totalToProcess, $maxItems);

    ops_update_progress($opId, 0, max(1, $totalToProcess), 'scan', "offers_total={$offersTotal}, candidates={$needFix}");

    // artifacts writers
    $resultsJsonlAbs = $outDir . '/gpt_results.jsonl';
    $rawJsonlAbs = $outDir . '/gpt_raw.jsonl';
    $csvAbs = $outDir . '/changes.csv';

    file_put_contents($resultsJsonlAbs, '');
    file_put_contents($rawJsonlAbs, '');

    $csvFp = fopen($csvAbs, 'wb');
    if ($csvFp) {
        fwrite($csvFp, "\xEF\xBB\xBF");
        fputcsv($csvFp, ['offer_id', 'vendorCode', 'original_name', 'new_name', 'type_ru', 'confidence', 'status', 'error', 'attempt', 'similarity_percent'], ';');
    }

    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;

    // --- main loop ---
    $done = 0;
    $fixed = 0;
    $failed = 0;
    $lowConfidence = 0;
    $skippedLimit = 0;
    $alreadyOk = $offersTotal - $needFix;
    if ($alreadyOk < 0) $alreadyOk = 0;

    $samples = [];

    for ($i = 0; $i < $offersTotal; $i++) {
        if ($checkCancel()) {
            $cancelled = true;
            $log("gpt_rewrite_title_marketplace: cancelled by user\n");
            break;
        }

        $offer = $offers->item($i);
        if (!($offer instanceof DOMElement)) continue;

        $offerId = (string)$offer->getAttribute('id');
        if ($selectedSet !== null && !isset($selectedSet[$offerId])) {
            continue;
        }

        $nameEl = $getFirstChildByTag($offer, 'name');
        if (!$nameEl) continue;

        $originalName = trim((string)$nameEl->textContent);
        if ($originalName === '') continue;

        if ($maxItems > 0 && $done >= $maxItems) {
            $skippedLimit++;
            continue;
        }

        $vendorCodeEl = $getFirstChildByTag($offer, 'vendorCode');
        $vendorCode = $vendorCodeEl ? trim((string)$vendorCodeEl->textContent) : '';

        $offerData = $extractOfferData($offer);

        $product = [
            'id' => $offerId,
            'vendorCode' => $vendorCode,
            'vendor' => $offerData['vendor'],
            'brand' => $offerData['brand'],
            'model' => $offerData['model'],
            'description' => $offerData['description'],
            'supplier_category' => [
                'id' => (string)($offerData['fields']['categoryId'] ?? ''),
                'path' => (string)($offerData['fields']['category_path'] ?? ''),
            ],
            'marketplace_categories' => [
                'ozon_category' => (string)($offerData['fields']['ozon_category'] ?? ''),
                'wb_category' => (string)($offerData['fields']['wb_category'] ?? ($offerData['fields']['wb_subject_id'] ?? '')),
            ],
            'fields' => $offerData['fields'],
            'params' => $offerData['params'],
        ];

        $inputObj = [
            'original_name' => $originalName,
            'product' => $product,
        ];

        $inputJson = json_encode($inputObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) $inputJson = '{"original_name":""}';

        ops_update_progress($opId, $done, max(1, $totalToProcess), 'openai', "offer={$offerId}");

        $status = 'ok';
        $errText = '';
        $newName = '';
        $typeRu = '';
        $raw = null;
        $attemptUsed = 0;
        $similarityPercent = 0.0;
        $confidence = null;

        $u = ['input' => 0, 'cached_input' => 0, 'output' => 0];

        try {
            if ($checkCancel()) {
                $cancelled = true;
                $log("gpt_rewrite_title_marketplace: cancelled by user (before OpenAI call)\n");
                break;
            }

            $baseUserInput = "Return a valid json object only.\n\n" . $inputJson;

            $opts = [
                'prompt_cache_key' => 'rewrite_title:' . substr(hash('sha256', $prompt), 0, 48),
                'text' => [
                    'format' => [
                        'type' => 'json_object',
                    ],
                ],
            ];
            if ($maxOut > 0) {
                $opts['max_output_tokens'] = $maxOut;
            }

            $lastError = '';
            $bestName = '';
            $bestType = '';
            $bestSim = 100.0;
            $bestRaw = null;
            $bestUsage = ['input' => 0, 'cached_input' => 0, 'output' => 0];

            $maxAttempts = 1 + $retries;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($checkCancel()) {
                    $cancelled = true;
                    break;
                }

                $extra = '';
                if ($attempt >= 2) {
                    $extra = "\n\nIMPORTANT: Improve the title only if you can keep the product type accurate. Do not chase uniqueness by changing the product domain. If the original title is already the safest accurate version, return a close cleaned version with low confidence.";
                }

                $res = $client->generateText(
                    $model,
                    $baseUserInput . $extra,
                    $prompt,
                    $opts
                );

                $rawText = (string)($res['output_text'] ?? '');
                $rawLocal = $res['raw'] ?? null;
                $usageLocal = $extractUsage(is_array($rawLocal) ? $rawLocal : null);

                // usage accumulate (в сумме по всем попыткам)
                $tokensInTotal += (int)$usageLocal['input'];
                $tokensOutTotal += (int)$usageLocal['output'];
                $tokensCachedTotal += (int)$usageLocal['cached_input'];
                $costUsdTotal += $calcCostUSD((int)$usageLocal['input'], (int)$usageLocal['cached_input'], (int)$usageLocal['output']);

                $decoded = $extractJsonFromText($rawText);

                $typeLocal = trim((string)($decoded['type_ru'] ?? ''));
                $nameLocal = trim((string)($decoded['new_name'] ?? ''));
                $confidenceLocal = null;
                if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
                    $confidenceLocal = max(0.0, min(1.0, (float)$decoded['confidence']));
                }

                if ($nameLocal === '') {
                    $lastError = 'GPT output: new_name is empty';
                    continue;
                }

                if (!$hasCyrillicRun5($nameLocal)) {
                    $lastError = 'GPT output: new_name has no 5 кириллических подряд';
                    continue;
                }

                if (mb_strlen($nameLocal, 'UTF-8') > 200) {
                    $lastError = 'GPT output: new_name is too long';
                    continue;
                }

                if ($confidenceLocal !== null && $confidenceLocal < 0.62) {
                    $status = 'skipped_low_confidence';
                    $lowConfidence++;
                    $newName = '';
                    $typeRu = $typeLocal;
                    $confidence = $confidenceLocal;
                    $raw = $rawLocal;
                    $u = $usageLocal;
                    $lastError = '';
                    break;
                }

                if ($norm($nameLocal) === $norm($originalName)) {
                    $lastError = 'GPT output: new_name equals original_name';
                    continue;
                }

                $sim = $calcSimilarityPercent($originalName, $nameLocal);

                // сохраняем "лучший" вариант на случай, если ни один не пройдет порог
                if ($sim < $bestSim) {
                    $bestSim = $sim;
                    $bestName = $nameLocal;
                    $bestType = $typeLocal;
                    $bestRaw = $rawLocal;
                    $bestUsage = $usageLocal;
                }

                if ($sim > $similarityMax) {
                    $lastError = "GPT output: too similar to original ({$sim}%)";
                    continue;
                }

                // success
                $newName = $nameLocal;
                $typeRu = $typeLocal;
                $confidence = $confidenceLocal;
                $similarityPercent = $sim;
                $attemptUsed = $attempt;
                $raw = $rawLocal;
                $u = $usageLocal;
                $lastError = '';
                break;
            }

            if ($cancelled) {
                throw new RuntimeException('Cancelled');
            }

            if ($status === 'skipped_low_confidence') {
                $errText = 'GPT confidence is too low to update title';
            } elseif ($newName === '') {
                // если не получилось пройти порог — всё равно можно сохранить "лучший" (но тут лучше фейлить, чтобы не портить качество)
                $err = $lastError !== '' ? $lastError : 'No acceptable variant after retries';
                // оставим полезную диагностику
                if ($bestName !== '') {
                    $err .= "; best_similarity={$bestSim}%";
                }
                // для дебага сохраним bestRaw в raw
                $raw = $bestRaw;
                $u = $bestUsage;
                throw new RuntimeException($err);
            }

            if ($status !== 'skipped_low_confidence') {
                // применяем
                $nameEl->nodeValue = '';
                $nameEl->appendChild($doc->createTextNode($newName));

                $fixed++;

                if (count($samples) < 25) {
                    $samples[] = [
                        'offer_id' => $offerId,
                        'vendorCode' => $vendorCode,
                        'before' => $originalName,
                        'after' => $newName,
                        'similarity_percent' => $similarityPercent,
                        'attempt' => $attemptUsed,
                    ];
                }
            }
        } catch (Throwable $e) {
            $status = 'error';
            $errText = $e->getMessage();
            $failed++;
        }

        $row = [
            'offer_id' => $offerId,
            'vendorCode' => $vendorCode,
            'original_name' => $originalName,
            'type_ru' => $typeRu,
            'new_name' => $newName,
            'confidence' => $confidence,
            'status' => $status,
            'error' => $errText,
            'attempt' => $attemptUsed,
            'similarity_percent' => $similarityPercent,
            'usage' => $u,
        ];

        file_put_contents($resultsJsonlAbs, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        if ($raw !== null) {
            file_put_contents(
                $rawJsonlAbs,
                json_encode(['offer_id' => $offerId, 'usage' => $u, 'raw' => $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND
            );
        }

        if ($csvFp) {
            fputcsv($csvFp, [$offerId, $vendorCode, $originalName, $newName, $typeRu, $confidence !== null ? (string)$confidence : '', $status, $errText, (string)$attemptUsed, (string)$similarityPercent], ';');
        }

        $done++;
    }

    $costUsdTotal = round($costUsdTotal, 6);
    $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");

    if ($csvFp) fclose($csvFp);

    ops_update_progress($opId, $done, max(1, $totalToProcess), 'write', 'saving result.xml');

    // --- save result xml ---
    $resultAbs = $outDir . '/result.xml';
    $doc->save($resultAbs);

    // --- report ---
    $report = [
        'summary' => [
            'offers_total' => $offersTotal,
            'offers_scope_total' => $scopeOffersTotal,
            'selected_requested' => $selectedRequested,
            'offers_already_ok' => $alreadyOk,
            'candidates' => $needFix,
            'processed' => $done,
            'fixed' => $fixed,
            'failed' => $failed,
            'skipped_low_confidence' => $lowConfidence,
            'skipped_due_to_max_items' => $skippedLimit,
            'cancelled' => $cancelled,
            'rewrite_rules' => [
                'similarity_max' => $similarityMax,
                'retries' => $retries,
            ],
            'usage' => [
                'input_tokens' => $tokensInTotal,
                'cached_input_tokens' => $tokensCachedTotal,
                'output_tokens' => $tokensOutTotal,
                'cost_usd' => round($costUsdTotal, 6),
            ],
        ],
        'samples' => $samples,
        'prompt_file' => 'app/llm/prompts/title_rewrite_marketplace.txt',
        'params_effective' => [
            'model' => $model,
            'max_output_tokens' => $maxOut,
            'max_items' => $maxItems,
            'inplace' => $inplace ? '1' : '0',
            'similarity_max' => $similarityMax,
            'retries' => $retries,
        ],
    ];

    $reportAbs = $outDir . '/report.json';
    file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ---------- inplace ----------
    $inplaceApplied = false;
    if ($inplace && !$cancelled) {
        // validate by read-through
        $tmpReader = new XMLReader();
        if (!$tmpReader->open($resultAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Result XML cannot be opened for validation: {$resultAbs}");
        }
        while ($tmpReader->read()) {}
        $tmpReader->close();

        $dstAbs = (string)($datasetRow['stored_path'] ?? '');
        if ($dstAbs === '' || !is_file($dstAbs)) {
            throw new RuntimeException("Dataset stored_path not found: {$dstAbs}");
        }

        $newSha = hash_file('sha256', $resultAbs);

        // блокируем ситуацию, когда новый XML полностью совпал с другим датасетом
        $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ? AND id <> ?");
        $stmt->execute([$newSha, (int)$datasetRow['id']]);
        $dupId = $stmt->fetchColumn();
        if ($dupId) {
            throw new RuntimeException("In-place update blocked: result duplicates dataset #{$dupId} (sha256 match).");
        }

        // backup текущего XML
        $backupAbs = $outDir . '/backup_before_inplace.xml';
        @copy($dstAbs, $backupAbs);

        // atomic replace через tmp + rename
        $dstDir = dirname($dstAbs);
        $tmpAbs = $dstDir . '/.tmp_inplace_' . (int)$opId . '_' . basename($dstAbs);

        if (!copy($resultAbs, $tmpAbs)) {
            throw new RuntimeException("Cannot write temp file for inplace update: {$tmpAbs}");
        }
        if (!rename($tmpAbs, $dstAbs)) {
            @unlink($tmpAbs);
            throw new RuntimeException("Cannot replace dataset XML (rename failed): {$dstAbs}");
        }

        // перескан + обновление feedtools_datasets
        $scan = scan_xml($dstAbs, 0);
        $warningsJson = json_encode($scan['warnings'], JSON_UNESCAPED_UNICODE);
        $bytes = (int)filesize($dstAbs);

        $upd = db()->prepare("
          UPDATE feedtools_datasets
          SET bytes = ?, sha256 = ?, offers_count = ?, warnings_json = ?
          WHERE id = ?
        ");
        $upd->execute([$bytes, $newSha, (int)$scan['offers_count'], $warningsJson, (int)$datasetRow['id']]);

        // derivation history
        $ins = db()->prepare("
          INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
          VALUES (?, ?, ?, ?, 0)
        ");
        $ins->execute([(int)$opId, 'inplace_update', (int)$datasetRow['id'], $newSha]);

        $inplaceApplied = true;
    }

    $summaryInline = [
        'title' => 'gpt_rewrite_title_marketplace',
        'items' => [
            "Offers total: {$offersTotal}",
            "Candidates (non-empty name): {$needFix}",
            "Processed: {$done}",
            "Fixed: {$fixed}",
            "Skipped low confidence: {$lowConfidence}",
            "Failed: {$failed}",
            "Inplace: " . ($inplaceApplied ? '1' : '0'),
            "Similarity max: {$similarityMax}",
            "Retries: {$retries}",
            "Tokens in: {$tokensInTotal}",
            "Tokens cached in: {$tokensCachedTotal}",
            "Tokens out: {$tokensOutTotal}",
            "Cost USD: $" . round($costUsdTotal, 6),
        ],
        'metrics' => [
            'offers_total' => $offersTotal,
            'candidates' => $needFix,
            'processed' => $done,
            'fixed' => $fixed,
            'failed' => $failed,
            'skipped_low_confidence' => $lowConfidence,
            'inplace' => $inplace ? '1' : '0',
            'inplace_applied' => $inplaceApplied ? '1' : '0',
            'similarity_max' => $similarityMax,
            'retries' => $retries,
            'tokens_in' => $tokensInTotal,
            'tokens_cached_in' => $tokensCachedTotal,
            'tokens_out' => $tokensOutTotal,
            'cost_usd' => round($costUsdTotal, 6),
        ],
    ];

    if ($cancelled) {
        $summaryInline['items'][] = 'CANCELLED: stopped early';
        $summaryInline['metrics']['cancelled'] = true;
    }

    ops_update_progress($opId, max(1, $totalToProcess), max(1, $totalToProcess), 'done', 'completed');

    return [
        'result_xml' => rel_to_outputs($cfg, $resultAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'gpt_results_jsonl' => rel_to_outputs($cfg, $resultsJsonlAbs),
        'gpt_raw_jsonl' => rel_to_outputs($cfg, $rawJsonlAbs),
        'changes_csv' => rel_to_outputs($cfg, $csvAbs),
        'summary_json_inline' => $summaryInline,
    ];
}
