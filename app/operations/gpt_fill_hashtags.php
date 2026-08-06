<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';
require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';
require_once __DIR__ . '/../llm/PromptTemplates.php';
require_once __DIR__ . '/../taxonomy/MarketplaceCategoryContext.php';

/**
 * gpt_fill_hashtags
 *
 * - scope: selected offers from dataset page (params['offer_ids']) or all if none selected
 * - for each offer: read ozon_category (tag <ozon_category> OR param name="ozon_category")
 * - load category meta from feedtools_taxonomy_categories (source='ozon', is_leaf=1, ozon_parent_id/ozon_leaf_id)
 * - send to GPT: full text product + full text category
 * - receive JSON { hashtags: ["...", ...] }
 * - write into XML as <hashtags>#tag1 #tag2</hashtags> (insert if missing)
 * - do not overwrite existing non-empty <hashtags> unless params['overwrite']=1
 * - default model: gpt-5.2 via op_registry, but can override via params['model']
 * - IMPORTANT: no temperature, no max_output_tokens
 */
function op_gpt_fill_hashtags(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$ds['id'];
    $inputPath = (string)($ds['stored_path'] ?? '');
    if ($inputPath === '' || !is_file($inputPath)) {
        throw new RuntimeException("Input XML not found: {$inputPath}");
    }

    $model = LLM::modelForOp($cfg, $params);

    $maxItems = (int)trim((string)($params['max_items'] ?? '0'));
    if ($maxItems < 0) $maxItems = 0;

    $inplace = (string)($params['inplace'] ?? '1');
    $inplace = ($inplace !== '' && $inplace !== '0');

    $overwrite = (string)($params['overwrite'] ?? '0');
    $overwrite = ($overwrite !== '' && $overwrite !== '0');

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
    ensure_dir($outDir . '/gpt');

    $outXmlAbs = $outDir . '/result.xml';

    $totalOffers = (int)($ds['offers_count'] ?? 0);
    if ($totalOffers < 0) $totalOffers = 0;

    $log("gpt_fill_hashtags: model={$model}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') . ", overwrite=" . ($overwrite ? '1' : '0') . "\n");
    $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));

    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'fill_hashtags_ru.txt');
    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    // ---- helpers ----

    $trimText = static function (string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    };

    $descToSnippet = static function (string $raw, int $maxLen = 3000): string {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $raw = strip_tags($raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        if ($maxLen > 0 && mb_strlen($raw, 'UTF-8') > $maxLen) {
            return mb_substr($raw, 0, $maxLen, 'UTF-8') . '…';
        }
        return $raw;
    };

    $extractJsonObject = static function (string $text): array {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        $i = strpos($text, '{');
        $j = strrpos($text, '}');
        if ($i === false || $j === false || $j <= $i) {
            throw new RuntimeException('JSON braces not found in GPT output');
        }
        $candidate = substr($text, $i, $j - $i + 1);
        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GPT output is not valid JSON');
        }
        return $decoded;
    };

    $extractUsage = static function ($raw): array {
        $in = 0;
        $out = 0;
        $cached = 0;

        if (!is_array($raw)) {
            return ['input' => 0, 'output' => 0, 'cached_input' => 0];
        }

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


    $normalizeHashtag = static function (string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        $s = preg_replace('~^#+~u', '', $s);
        $s = preg_replace('/\s+/u', '_', $s);
        $s = preg_replace('~[^\p{L}\p{N}_]+~u', '', $s);
        $s = mb_strtolower((string)$s, 'UTF-8');
        $s = trim((string)$s, '_');
        if ($s === '') return '';
        // Ozon counts the leading "#" as part of the hashtag length.
        if (mb_strlen($s, 'UTF-8') > 29) {
            $s = mb_substr($s, 0, 29, 'UTF-8');
            $s = trim((string)$s, '_');
        }
        if ($s === '') return '';
        return '#' . $s;
    };

    // запретные хештеги (после normalizeHashtag они всегда в нижнем регистре)
    $bannedHashtags = [
        '#цена'  => true,
        '#купить' => true,
    ];


    // category cache (code => prepared payload)
    $catCache = [];

    $loadCategory = static function (string $code) use (&$catCache): ?array {
        if ($code === '') return null;
        if (isset($catCache[$code])) return $catCache[$code];

        if (!preg_match('~^(\d+)_(\d+)$~', $code, $m)) {
            $catCache[$code] = null;
            return null;
        }
        $descId = (int)$m[1];
        $typeId = (int)$m[2];

        $row = db_exec_with_retry(function () use ($descId, $typeId) {
            $st = db()->prepare("
              SELECT * FROM feedtools_taxonomy_categories
              WHERE source='ozon' AND is_leaf=1 AND ozon_parent_id=? AND ozon_leaf_id=?
              LIMIT 1
            ");
            $st->execute([$descId, $typeId]);
            $r = $st->fetch();
            return $r ?: null;
        }, 2);

        if (!$row) {
            $catCache[$code] = null;
            return null;
        }

        $meta = [];
        if (!empty($row['meta_json'])) {
            $tmp = json_decode((string)$row['meta_json'], true);
            if (is_array($tmp)) $meta = $tmp;
        }
        $meta += [
            'description' => '',
            'typical_goods' => '',
            'features' => '',
        ];

        $payload = [
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'full_path' => (string)($row['full_path'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
            'typical_goods' => (string)($meta['typical_goods'] ?? ''),
            'features' => (string)($meta['features'] ?? ''),
        ];

        $catCache[$code] = $payload;
        return $payload;
    };

    // ---- PASS 1: scan offers in scope, collect candidates ----
    ops_update_progress($opId, 0, max(1, ($totalOffers > 0 ? $totalOffers : 1)), 'scan', 'scanning offers');

    $reader = new XMLReader();
    if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Cannot open XML: {$inputPath}");
    }

    $seenOffers = 0;
    $tick = 0;

    // candidates: offerId => payload for GPT
    $candidates = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

        $offerId = (string)$reader->getAttribute('id');
        $offerDepth = $reader->depth;

        if ($reader->isEmptyElement) {
            $seenOffers++;
            continue;
        }

        $inScope = $applyAll || ($offerId !== '' && isset($offerSet[$offerId]));

        $name = '';
        $vendorCode = '';
        $brand = '';
        $vendor = '';
        $modelTxt = '';
        $descRaw = '';
        $ozonCategory = '';
        $wbCategory = '';
        $hashtagsExisting = '';

        $paramsArr = []; // list of {name,value}

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;

            $tag = $reader->name;

            if ($tag === 'name') {
                $name = $trimText($reader->readString());
                continue;
            }
            if ($tag === 'vendorCode') {
                $vendorCode = $trimText($reader->readString());
                continue;
            }
            if ($tag === 'brand') {
                $brand = $trimText($reader->readString());
                continue;
            }
            if ($tag === 'vendor') {
                $vendor = $trimText($reader->readString());
                continue;
            }
            if ($tag === 'model') {
                $modelTxt = $trimText($reader->readString());
                continue;
            }

            if ($tag === 'ozon_category') {
                $ozonCategory = trim((string)$reader->readString());
                continue;
            }
            if ($tag === 'wb_category' || $tag === 'wb_subject_id') {
                $wbCategory = trim((string)$reader->readString());
                continue;
            }

            if ($tag === 'hashtags') {
                $hashtagsExisting = $trimText($reader->readString());
                continue;
            }

            if ($tag === 'description') {
                $descRaw = (string)$reader->readInnerXml();
                continue;
            }

            if ($tag === 'param') {
                $pname = (string)$reader->getAttribute('name');
                $pval = $trimText($reader->readString());
                $paramsArr[] = ['marketplace' => 'ozon', 'name' => $pname, 'value' => $pval];

                if ($ozonCategory === '' && $pname !== '' && trim($pname) === 'ozon_category') {
                    $ozonCategory = trim($pval);
                }
                if ($wbCategory === '' && $pname !== '' && in_array(trim($pname), ['wb_category', 'wb_subject_id'], true)) {
                    $wbCategory = trim($pval);
                }
                continue;
            }

            if ($tag === 'wb_param') {
                $pname = (string)$reader->getAttribute('name');
                $pval = $trimText($reader->readString());
                $paramsArr[] = ['marketplace' => 'wildberries', 'name' => $pname, 'value' => $pval];
                continue;
            }

            if (!$reader->isEmptyElement) $reader->readString();
        }

        $seenOffers++;
        $tick++;
        if ($totalOffers > 0 && ($tick % 250 === 0)) {
            ops_update_progress($opId, $seenOffers, $totalOffers, 'scan', 'scanning offers');
        }

        if (!$inScope) continue;
        if ($offerId === '') continue;

        if (!$overwrite && trim($hashtagsExisting) !== '') {
            continue;
        }

        $ozonCategory = trim($ozonCategory);
        $wbCategory = trim($wbCategory);
        if ($ozonCategory === '' && $wbCategory === '') continue;

        $cat = ft_load_combined_marketplace_category_context($ozonCategory, $wbCategory, false);
        if (!$cat) continue;

        $candidates[$offerId] = [
            'offer_id' => $offerId,
            'ozon_category' => $ozonCategory,
            'wb_category' => $wbCategory,
            'hashtags_existing' => $hashtagsExisting,
            'product' => [
                'offer_id' => $offerId,
                'vendorCode' => $vendorCode,
                'name' => $name,
                'brand' => $brand,
                'vendor' => $vendor,
                'model' => $modelTxt,
                'description' => $descToSnippet($descRaw, 3500),
                'params' => $paramsArr,
            ],
            'category' => [
                'code' => (string)$cat['code'],
                'name' => (string)$cat['name'],
                'full_path' => (string)$cat['full_path'],
                'description' => (string)$cat['description'],
                'typical_goods' => (string)$cat['typical_goods'],
                'features' => (string)$cat['features'],
                'marketplace_contexts' => $cat['marketplace_contexts'] ?? [],
            ],
        ];

        if ($maxItems > 0 && count($candidates) >= $maxItems) {
            break;
        }
    }

    $reader->close();

    $log("scan: offers_seen={$seenOffers}, candidates=" . count($candidates) . "\n");

    // If there are no candidates, XML must remain unchanged.
    if (count($candidates) === 0) {
        $log("No candidates. Writing result as a byte-for-byte copy of input.\n");

        if (!copy($inputPath, $outXmlAbs)) {
            throw new RuntimeException("Cannot copy input XML to result: {$outXmlAbs}");
        }

        $report = [
            'summary' => [
                'offers_total' => (int)($ds['offers_count'] ?? 0),
                'offers_seen' => $seenOffers,
                'candidates' => 0,
                'gpt_status_counts' => ['ok' => 0, 'error' => 0],
                'offers_touched' => 0,
                'offers_with_hashtags' => 0,
                'scope' => $applyAll ? 'all' : 'selected',
                'selected_requested' => count($offerIds),
                'note' => 'no-op: result is exact copy of input',
            ],
            'params_effective' => [
                'model' => $model,
                'max_items' => (string)$maxItems,
                'inplace' => $inplace ? '1' : '0',
                'overwrite' => $overwrite ? '1' : '0',
            ],
        ];
        $reportAbs = $outDir . '/report.json';
        file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($inplace) {
            // validate
            $tmpReader = new XMLReader();
            if (!$tmpReader->open($outXmlAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new RuntimeException("Result XML cannot be opened for validation: {$outXmlAbs}");
            }
            while ($tmpReader->read()) { /* read-through */
            }
            $tmpReader->close();

            $dstAbs = (string)($ds['stored_path'] ?? '');
            if ($dstAbs === '' || !is_file($dstAbs)) {
                throw new RuntimeException("Dataset stored_path not found: {$dstAbs}");
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

            $newSha = hash_file('sha256', $dstAbs);
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

            $log("INPLACE: dataset #{$ds['id']} updated via byte-copy (no-op).\n");
        }

        return [
            'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
            'report_json' => rel_to_outputs($cfg, $reportAbs),
            'summary_json_inline' => [
                'title' => 'gpt_fill_hashtags',
                'items' => [
                    'Candidates: 0',
                    'No-op: result is exact copy of input',
                ],
                'metrics' => [
                    'candidates' => 0,
                    'offers_touched' => 0,
                    'offers_with_hashtags' => 0,
                    'inplace' => $inplace ? 1 : 0,
                    'updated_dataset_id' => $inplace ? (int)$ds['id'] : null,
                ],
            ],
        ];
    }

    // ---- PASS 2: GPT ----
    $gptTotal = max(1, count($candidates));
    $gptDone = 0;

    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;


    $gptResults = []; // offerId => ['status','hashtags','error']

    foreach ($candidates as $offerId => $cand) {
        $gptDone++;
        ops_update_progress($opId, $gptDone, $gptTotal, 'gpt', "GPT {$gptDone}/{$gptTotal} offer={$offerId}");

        $status = 'ok';
        $err = '';
        $hashtagsFinal = '';

        $payload = [
            'category' => $cand['category'],
            'product' => $cand['product'],
        ];

        $inputJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) $inputJson = '{"product":{},"category":{}}';

        $userInput = "Return a json object only.\n\njson:\n" . $inputJson;

        $rawText = '';
        $raw = null;
        $u = ['input' => 0, 'cached_input' => 0, 'output' => 0];

        try {
            $res = $client->generateText(
                $model,
                $userInput,
                $prompt,
                [
                    'prompt_cache_key' => 'fill_hashtags:' . substr(hash('sha256', json_encode([
                        'category' => $cand['category'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 48),
                    'text' => [
                        'format' => [
                            'type' => 'json_object',
                        ],
                    ],
                ]
            );

            $rawText = (string)($res['output_text'] ?? '');
            $raw = $res['raw'] ?? null;
            $u = $extractUsage($raw);

            $tokensInTotal += (int)$u['input'];
            $tokensOutTotal += (int)$u['output'];
            $tokensCachedTotal += (int)$u['cached_input'];

            $costUsdTotal += $calcCostUSD((int)$u['input'], (int)$u['cached_input'], (int)$u['output']);


            $obj = $extractJsonObject($rawText);

            $rawTags = $obj['hashtags'] ?? [];

            // Приводим к плоскому списку строк
            $items = [];
            if (is_array($rawTags)) {
                $items = $rawTags;
            } elseif (is_string($rawTags)) {
                $items = [$rawTags];
            } else {
                $items = [];
            }

            $uniq = [];
            $out = [];

            foreach ($items as $item) {
                $s = trim((string)$item);
                if ($s === '') continue;

                // Если в строке есть # — извлекаем все #теги
                if (strpos($s, '#') !== false) {
                    if (preg_match_all('~#[\p{L}\p{N}_-]+~u', $s, $m)) {
                        foreach ($m[0] as $ht) {
                            $t = $normalizeHashtag((string)$ht);
                            if ($t === '' || isset($uniq[$t]) || isset($bannedHashtags[$t])) continue;
                            $uniq[$t] = true;
                            $out[] = $t;
                        }
                    }
                    continue;
                }

                // Иначе режем по разделителям (запятая/точка с запятой/перенос/пробел)
                $parts = preg_split('~[\s,;]+~u', $s);
                foreach ($parts as $p) {
                    $t = $normalizeHashtag((string)$p);
                    if ($t === '' || isset($uniq[$t]) || isset($bannedHashtags[$t])) continue;
                    $uniq[$t] = true;
                    $out[] = $t;

                    if (count($out) >= 15) break 2;
                }
            }

            // ВАЖНО: запись через запятую
            $hashtagsFinal = implode(', ', $out);
        } catch (Throwable $e) {
            $status = 'error';
            $err = $e->getMessage();
        }

        $gptResults[$offerId] = [
            'status' => $status,
            'error' => $err,
            'hashtags' => $hashtagsFinal,
        ];

        $dbg = [
            'offer_id' => $offerId,
            'ozon_category' => $cand['ozon_category'],
            'status' => $status,
            'error' => $err,
            'payload' => $payload,
            'gpt_output_text' => $rawText,
            'final_hashtags' => $hashtagsFinal,
            'usage' => $u,

            'raw' => $raw,
        ];
        file_put_contents(
            $outDir . '/gpt/' . preg_replace('~[^a-zA-Z0-9_\-]+~', '_', $offerId) . '.json',
            json_encode($dbg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    $costUsdTotal = round($costUsdTotal, 6);
    $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");


    // ---- PASS 3: rewrite XML with inserted/replaced <hashtags> (streaming) ----
    ops_update_progress($opId, 0, ($totalOffers > 0 ? $totalOffers : 1), 'write', 'rewriting XML');

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
    $newHashtags = '';

    $inHashtags = false;
    $hashtagsDepth = -1;
    $hashtagsSeenInOffer = false;
    $skipHashtagsText = false;
    $hashtagsTextWritten = false;

    $seenOffers2 = 0;
    $offersTouched = 0;
    $offersWithHashtags = 0;
    $progressTick2 = 0;

    while ($reader->read()) {
        switch ($reader->nodeType) {

            case XMLReader::ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer') {
                        $inOffer = true;
                        $offerDepth = $reader->depth;
                        $curOfferId = (string)$reader->getAttribute('id');

                        $inHashtags = false;
                        $hashtagsDepth = -1;
                        $hashtagsSeenInOffer = false;
                        $skipHashtagsText = false;
                        $hashtagsTextWritten = false;

                        $targetOffer = ($curOfferId !== ''
                            && isset($gptResults[$curOfferId])
                            && ($gptResults[$curOfferId]['status'] ?? '') === 'ok'
                            && trim((string)($gptResults[$curOfferId]['hashtags'] ?? '')) !== ''
                        );
                        $newHashtags = $targetOffer ? (string)$gptResults[$curOfferId]['hashtags'] : '';

                        $writer->startElement('offer');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newHashtags !== '') {
                                $writer->startElement('hashtags');
                                $writer->text($newHashtags);
                                $writer->endElement();
                                $offersTouched++;
                                $offersWithHashtags++;
                            }

                            $writer->endElement(); // </offer>

                            $seenOffers2++;
                            $progressTick2++;

                            $inOffer = false;
                            $targetOffer = false;
                            $curOfferId = '';
                            $offerDepth = -1;
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

                    if ($inOffer && $name === 'hashtags') {
                        $hashtagsSeenInOffer = true;
                        $inHashtags = true;
                        $hashtagsDepth = $reader->depth;
                        $skipHashtagsText = $targetOffer;
                        $hashtagsTextWritten = false;

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newHashtags !== '') {
                                $writer->text($newHashtags);
                                $hashtagsTextWritten = true;
                                $offersTouched++;
                                $offersWithHashtags++;
                            }
                            $writer->endElement(); // </hashtags>
                            $inHashtags = false;
                            $hashtagsDepth = -1;
                            $skipHashtagsText = false;
                        }
                        break;
                    }

                    if ($reader->isEmptyElement) {
                        $writer->endElement();
                    }
                    break;
                }

            case XMLReader::TEXT:
            case XMLReader::SIGNIFICANT_WHITESPACE:
            case XMLReader::WHITESPACE: {
                    if ($inOffer && $inHashtags && $skipHashtagsText) {
                        break;
                    }
                    $writer->text($reader->value);
                    break;
                }

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    if ($inOffer && $inHashtags && $name === 'hashtags' && $reader->depth === $hashtagsDepth) {
                        if ($targetOffer && !$hashtagsTextWritten && $newHashtags !== '') {
                            $writer->text($newHashtags);
                            $hashtagsTextWritten = true;
                            $offersTouched++;
                            $offersWithHashtags++;
                        }
                        $writer->endElement(); // </hashtags>
                        $inHashtags = false;
                        $hashtagsDepth = -1;
                        $skipHashtagsText = false;
                        break;
                    }

                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        if ($targetOffer && !$hashtagsSeenInOffer && $newHashtags !== '') {
                            $writer->startElement('hashtags');
                            $writer->text($newHashtags);
                            $writer->endElement();
                            $offersTouched++;
                            $offersWithHashtags++;
                        }

                        $writer->endElement(); // </offer>

                        $seenOffers2++;
                        $progressTick2++;

                        if ($totalOffers > 0 && ($progressTick2 % 200 === 0)) {
                            ops_update_progress($opId, min($seenOffers2, $totalOffers), $totalOffers, 'write', 'rewriting XML');
                        }

                        $inOffer = false;
                        $targetOffer = false;
                        $curOfferId = '';
                        $offerDepth = -1;
                        $inHashtags = false;
                        $hashtagsDepth = -1;
                        $hashtagsSeenInOffer = false;
                        $skipHashtagsText = false;
                        $hashtagsTextWritten = false;
                        break;
                    }

                    $writer->endElement();
                    break;
                }

            case XMLReader::CDATA:
                if ($inOffer && $inHashtags && $skipHashtagsText) break;
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

    // ---- REPORT ----
    $statusCounts = ['ok' => 0, 'error' => 0];
    foreach ($gptResults as $r) {
        $st = (string)($r['status'] ?? '');
        if (!isset($statusCounts[$st])) $statusCounts[$st] = 0;
        $statusCounts[$st]++;
    }

    $report = [
        'summary' => [
            'offers_total' => (int)($ds['offers_count'] ?? 0),
            'offers_seen' => $seenOffers,
            'candidates' => count($candidates),
            'gpt_status_counts' => $statusCounts,
            'offers_touched' => $offersTouched,
            'offers_with_hashtags' => $offersWithHashtags,
            'scope' => $applyAll ? 'all' : 'selected',
            'selected_requested' => count($offerIds),
            'usage' => [
                'input_tokens' => $tokensInTotal,
                'cached_input_tokens' => $tokensCachedTotal,
                'output_tokens' => $tokensOutTotal,
                'cost_usd' => round($costUsdTotal, 6),
            ],

        ],
        'params_effective' => [
            'model' => $model,
            'max_items' => (string)$maxItems,
            'inplace' => $inplace ? '1' : '0',
            'overwrite' => $overwrite ? '1' : '0',
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

    $summaryInline = [
        'title' => 'gpt_fill_hashtags',
        'items' => [
            'Candidates: ' . count($candidates),
            'Offers touched: ' . $offersTouched,
            'Offers with hashtags: ' . $offersWithHashtags,
            'Tokens in: ' . $tokensInTotal,
            'Tokens cached in: ' . $tokensCachedTotal,
            'Tokens out: ' . $tokensOutTotal,
            'Cost USD: $' . round($costUsdTotal, 4),

        ],
        'metrics' => [
            'candidates' => count($candidates),
            'offers_touched' => $offersTouched,
            'offers_with_hashtags' => $offersWithHashtags,
            'inplace' => $inplace ? 1 : 0,
            'updated_dataset_id' => $inplace ? (int)$ds['id'] : null,
            'tokens_in' => $tokensInTotal,
            'tokens_cached_in' => $tokensCachedTotal,
            'tokens_out' => $tokensOutTotal,
            'cost_usd' => round($costUsdTotal, 6),

        ],
    ];

    return [
        'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'summary_json_inline' => $summaryInline,
    ];
}
