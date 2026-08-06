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
 * gpt_fix_title_ru
 *
 * Проверяет <name> у offer. Если в названии нет подряд 5 кириллических букв,
 * вызывает GPT по промпту app/llm/prompts/title_fix_ru.txt и переписывает <name>.
 *
 * Выход:
 * - result_xml: обновлённый XML (в outputs)
 * - report_json: сводка/список изменений
 * - gpt_results_jsonl / gpt_raw_jsonl: результаты/сырьё для дебага
 * - changes_csv: список изменённых названий
 *
 * ВАЖНО: по умолчанию делает INPLACE обновление текущего датасета и текущего XML (stored_path).
 */
function op_gpt_fix_title_ru(array $cfg, array $datasetRow, int $opId, array $params, callable $log): array
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

    // --- selected offers filter (optional) ---
    // params['offer_ids'] = array of offer@id strings.
    // If not provided / empty -> process all offers.
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

    $log("gpt_fix_title_ru: model={$model}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') . "\n");

    // --- load prompt ---
    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'title_fix_ru.txt');

    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    // --- helpers ---
    $hasCyrillicRun5 = static function (string $s): bool {
        // true если есть минимум 5 кириллических букв подряд
        return (bool)preg_match('/\p{Cyrillic}{5}/u', $s);
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
                // Описание часто большое. В prompt оно не нужно.
                continue;
            }

            // name мы передаём отдельно как original_name
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

    // first pass: count items requiring GPT (only selected offers if provided)
    $needFix = 0;
    $scopeOffersTotal = 0;

    $outCancelFlagAbs = $outDir . '/cancel.flag';
    $cancelled = false;

    $checkCancel = static function () use ($outCancelFlagAbs): bool {
        clearstatcache(true, $outCancelFlagAbs);
        return is_file($outCancelFlagAbs);
    };

    for ($i = 0; $i < $offersTotal; $i++) {
        if ($checkCancel()) {
            $cancelled = true;
            $log("gpt_fix_title_ru: cancelled by user (during scan)\n");
            break;
        }

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

        if (!$hasCyrillicRun5($name)) $needFix++;
    }

    if ($selectedSet === null) {
        $scopeOffersTotal = $offersTotal; // no selection -> whole feed
    }

    $totalToProcess = $needFix;
    if ($maxItems > 0) $totalToProcess = min($totalToProcess, $maxItems);

    ops_update_progress($opId, 0, $totalToProcess, 'scan', "offers_total={$offersTotal}, need_fix={$needFix}");

    // artifacts writers
    $resultsJsonlAbs = $outDir . '/gpt_results.jsonl';
    $rawJsonlAbs = $outDir . '/gpt_raw.jsonl';
    $csvAbs = $outDir . '/changes.csv';

    file_put_contents($resultsJsonlAbs, '');
    file_put_contents($rawJsonlAbs, '');

    $csvFp = fopen($csvAbs, 'wb');
    if ($csvFp) {
        // BOM for Excel
        fwrite($csvFp, "\xEF\xBB\xBF");
        fputcsv($csvFp, ['offer_id', 'vendorCode', 'original_name', 'new_name', 'type_ru', 'status', 'error'], ';');
    }

    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;

    // --- main loop ---
    $done = 0;
    $fixed = 0;
    $failed = 0;
    $skippedLimit = 0;
    $alreadyOk = $scopeOffersTotal - $needFix;
    if ($alreadyOk < 0) $alreadyOk = 0;

    $samples = [];

    for ($i = 0; $i < $offersTotal; $i++) {
        if ($checkCancel()) {
            $cancelled = true;
            $log("gpt_fix_title_ru: cancelled by user\n");
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

        if ($hasCyrillicRun5($originalName)) {
            continue; // уже ок
        }

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
            'fields' => $offerData['fields'],
            'params' => $offerData['params'],
        ];

        $inputObj = [
            'original_name' => $originalName,
            'product' => $product,
        ];

        $inputJson = json_encode($inputObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) $inputJson = '{"original_name":""}';

        ops_update_progress($opId, $done, $totalToProcess, 'openai', "offer={$offerId}");

        $status = 'ok';
        $errText = '';
        $newName = '';
        $typeRu = '';
        $raw = null;
        $u = ['input' => 0, 'cached_input' => 0, 'output' => 0];

        try {
            if ($checkCancel()) {
                $cancelled = true;
                $log("gpt_fix_title_ru: cancelled by user (before OpenAI call)\n");
                break;
            }

            // ВАЖНО: слово "json" должно быть в input, иначе json_object даёт 400
            $userInput = "Return a json object only.\n\n" . $inputJson;

            $res = $client->generateText(
                $model,
                $userInput,
                $prompt,
                [
                    'prompt_cache_key' => 'fix_title:' . substr(hash('sha256', $prompt), 0, 48),
                    'text' => [
                        'format' => [
                            'type' => 'json_object',
                        ],
                    ],
                ]
            );

            $rawText = trim((string)($res['output_text'] ?? ''));
            $raw = $res['raw'] ?? null;

            $u = $extractUsage(is_array($raw) ? $raw : null);

            $tokensInTotal += (int)$u['input'];
            $tokensOutTotal += (int)$u['output'];
            $tokensCachedTotal += (int)$u['cached_input'];
            $costUsdTotal += $calcCostUSD((int)$u['input'], (int)$u['cached_input'], (int)$u['output']);

            $decoded = json_decode($rawText, true);

            // fallback: если вдруг прилетела обвязка вокруг JSON
            if (!is_array($decoded)) {
                $i2 = strpos($rawText, '{');
                $j2 = strrpos($rawText, '}');
                if ($i2 !== false && $j2 !== false && $j2 > $i2) {
                    $decoded = json_decode(substr($rawText, $i2, $j2 - $i2 + 1), true);
                }
            }

            if (!is_array($decoded)) {
                throw new RuntimeException('GPT output is not valid JSON. Preview: ' . mb_substr($rawText, 0, 200, 'UTF-8'));
            }

            $typeRu = trim((string)($decoded['type_ru'] ?? ''));
            $newName = trim((string)($decoded['new_name'] ?? ''));

            if ($newName === '') {
                throw new RuntimeException('GPT output: new_name is empty');
            }
            if (!$hasCyrillicRun5($newName)) {
                throw new RuntimeException('GPT output: new_name still has no 5 кириллических подряд');
            }
            if (mb_strlen($newName, 'UTF-8') > 200) {
                throw new RuntimeException('GPT output: new_name is too long');
            }

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
                ];
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
            'status' => $status,
            'error' => $errText,
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
            fputcsv($csvFp, [$offerId, $vendorCode, $originalName, $newName, $typeRu, $status, $errText], ';');
        }

        $done++;
    }

    $costUsdTotal = round($costUsdTotal, 6);
    $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");

    if ($csvFp) fclose($csvFp);

    ops_update_progress($opId, $done, $totalToProcess, 'write', 'saving result.xml');

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
            'offers_need_fix' => $needFix,
            'processed' => $done,
            'fixed' => $fixed,
            'failed' => $failed,
            'skipped_due_to_max_items' => $skippedLimit,
            'cancelled' => $cancelled,
            'usage' => [
                'input_tokens' => $tokensInTotal,
                'cached_input_tokens' => $tokensCachedTotal,
                'output_tokens' => $tokensOutTotal,
                'cost_usd' => round($costUsdTotal, 6),
            ],
        ],
        'samples' => $samples,
        'prompt_file' => 'app/llm/prompts/title_fix_ru.txt',
        'params_effective' => [
            'model' => $model,
            'max_items' => $maxItems,
            'inplace' => $inplace ? '1' : '0',
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
        while ($tmpReader->read()) {
        }
        $tmpReader->close();

        $dstAbs = (string)($datasetRow['stored_path'] ?? '');
        if ($dstAbs === '' || !is_file($dstAbs)) {
            throw new RuntimeException("Dataset stored_path not found: {$dstAbs}");
        }

        $newSha = hash_file('sha256', $resultAbs);

        $stmt = db()->prepare("SELECT id FROM feedtools_datasets WHERE sha256 = ? AND id <> ?");
        $stmt->execute([$newSha, (int)$datasetRow['id']]);
        $dupId = $stmt->fetchColumn();
        if ($dupId) {
            throw new RuntimeException("In-place update blocked: result duplicates dataset #{$dupId} (sha256 match).");
        }

        $backupAbs = $outDir . '/backup_before_inplace.xml';
        @copy($dstAbs, $backupAbs);

        $dstDir = dirname($dstAbs);
        $tmpAbs = $dstDir . '/.tmp_inplace_' . (int)$opId . '_' . basename($dstAbs);

        if (!copy($resultAbs, $tmpAbs)) {
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
        $upd->execute([$bytes, $newSha, (int)$scan['offers_count'], $warningsJson, (int)$datasetRow['id']]);

        $ins = db()->prepare("
          INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
          VALUES (?, ?, ?, ?, 0)
        ");
        $ins->execute([(int)$opId, 'inplace_update', (int)$datasetRow['id'], $newSha]);

        $inplaceApplied = true;
    }

    $summaryInline = [
        'title' => 'gpt_fix_title_ru',
        'items' => [
            "Offers total: {$offersTotal}",
            "Need fix (no 5 Cyrillic подряд): {$needFix}",
            "Processed: {$done}",
            "Fixed: {$fixed}",
            "Failed: {$failed}",
            "Inplace: " . ($inplaceApplied ? '1' : '0'),
            "Tokens in: {$tokensInTotal}",
            "Tokens cached in: {$tokensCachedTotal}",
            "Tokens out: {$tokensOutTotal}",
            "Cost USD: $" . round($costUsdTotal, 6),
        ],
        'metrics' => [
            'offers_total' => $offersTotal,
            'need_fix' => $needFix,
            'processed' => $done,
            'fixed' => $fixed,
            'failed' => $failed,
            'inplace' => $inplace ? '1' : '0',
            'inplace_applied' => $inplaceApplied ? '1' : '0',
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

    ops_update_progress($opId, $totalToProcess, $totalToProcess, 'done', 'completed');

    return [
        'result_xml' => rel_to_outputs($cfg, $resultAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'gpt_results_jsonl' => rel_to_outputs($cfg, $resultsJsonlAbs),
        'gpt_raw_jsonl' => rel_to_outputs($cfg, $rawJsonlAbs),
        'changes_csv' => rel_to_outputs($cfg, $csvAbs),
        'summary_json_inline' => $summaryInline,
    ];
}
