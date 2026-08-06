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
require_once __DIR__ . '/../card_brief.php';

function op_gpt_generate_description_ru(array $cfg, array $ds, int $opId, array $params, callable $log): array
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

    $minDescLen = (int)trim((string)($params['min_desc_len'] ?? '250'));
    if ($minDescLen < 0) $minDescLen = 0;

    $useKeywords = (string)($params['use_keywords'] ?? '1');
    $useKeywords = ($useKeywords !== '' && $useKeywords !== '0');

    // selected offers (optional)
    $offerIds = [];
    if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
        foreach ($params['offer_ids'] as $v) {
            $s = (string)$v;
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

    $log(
        "gpt_generate_description_ru: model={$model}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') .
            ", overwrite=" . ($overwrite ? '1' : '0') . ", min_desc_len={$minDescLen}, use_keywords=" . ($useKeywords ? '1' : '0') . "\n"
    );

    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'generate_description_ru.txt');
    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    // ---------- helpers ----------
    $trimText = static function (string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    };

    $stripToPlain = static function (string $raw): string {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $raw = strip_tags($raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);
        return trim((string)$raw);
    };

    $normalizeDescriptionHtml = static function (string $html): string {
        $html = str_replace(['<![CDATA[', ']]>'], '', $html);
        $html = trim((string)$html);
        $html = preg_replace('~<\s*br\s*/?\s*>~iu', '<br>', $html);
        $html = preg_replace('~<\s*/?\s*(p|div)\b[^>]*>~iu', '<br>', $html);
        $html = preg_replace_callback('~<\s*/?\s*(ul|li)\b[^>]*>~iu', static function (array $m): string {
            $tag = function_exists('mb_strtolower') ? mb_strtolower((string)($m[1] ?? ''), 'UTF-8') : strtolower((string)($m[1] ?? ''));
            if ($tag === 'ul') {
                return strpos($m[0], '</') === 0 ? '</ul>' : '<ul>';
            }
            return strpos($m[0], '</') === 0 ? '</li>' : '<li>';
        }, $html);
        $html = strip_tags($html, '<br><ul><li>');
        $html = preg_replace('~(?:<br>\s*){3,}~u', '<br><br>', (string)$html);
        $html = preg_replace('~\s*<br>\s*~u', '<br>', (string)$html);
        $html = preg_replace('~<ul>\s*~u', '<ul>', (string)$html);
        $html = preg_replace('~\s*</ul>~u', '</ul>', (string)$html);
        $html = preg_replace('~<li>\s*~u', '<li>', (string)$html);
        $html = preg_replace('~\s*</li>~u', '</li>', (string)$html);
        return trim((string)$html);
    };

    $trimPlainTextNicely = static function (string $text, int $limit, string $ellipsis = '…') use ($trimText): string {
        $text = $trimText($text);
        if ($text === '' || $limit <= 0) return '';
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;

        $reserve = mb_strlen($ellipsis, 'UTF-8');
        $softLimit = max(1, $limit - $reserve);
        $slice = mb_substr($text, 0, $softLimit, 'UTF-8');

        $best = '';
        foreach (['/[.!?](?=\s|$)/u', '/[;:](?=\s|$)/u', '/,(?=\s|$)/u'] as $pattern) {
            if (preg_match_all($pattern, $slice, $matches, PREG_OFFSET_CAPTURE) && !empty($matches[0])) {
                $last = end($matches[0]);
                $candidate = trim((string)mb_substr($slice, 0, (int)$last[1] + 1, 'UTF-8'));
                if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') >= (int)floor($limit * 0.6)) {
                    $best = $candidate;
                    break;
                }
            }
        }

        if ($best === '') {
            $spacePos = mb_strrpos($slice, ' ', 0, 'UTF-8');
            if ($spacePos !== false && $spacePos >= (int)floor($limit * 0.6)) {
                $best = trim((string)mb_substr($slice, 0, $spacePos, 'UTF-8'));
            }
        }

        if ($best === '') {
            $best = trim($slice);
        }

        return rtrim($best, " \t\n\r\0\x0B,;:-") . $ellipsis;
    };

    $clampDescriptionHtmlForWb = static function (string $html, int $plainLimit = 1800) use (
        $normalizeDescriptionHtml,
        $stripToPlain,
        $trimPlainTextNicely
    ): string {
        $html = $normalizeDescriptionHtml($html);
        if ($html === '') return '';

        $plain = $stripToPlain($html);
        if ($plain === '') return '';
        if (mb_strlen($plain, 'UTF-8') <= $plainLimit) {
            return $html;
        }

        $trimmedPlain = $trimPlainTextNicely($plain, $plainLimit);
        $parts = preg_split('/(?<=[.!?])\s+/u', $trimmedPlain) ?: [$trimmedPlain];
        $parts = array_values(array_filter(array_map($trimText, $parts), static fn(string $part): bool => $part !== ''));
        if (!$parts) {
            return $trimmedPlain;
        }

        $lead = array_shift($parts);
        $rebuilt = $lead;
        if ($parts) {
            $rebuilt .= '<br><br>Основные характеристики:<br><ul>';
            foreach ($parts as $part) {
                $item = trim($part, " \t\n\r\0\x0B-•");
                if ($item === '') continue;
                $rebuilt .= '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</li>';
            }
            $rebuilt .= '</ul>';
        }
        return $normalizeDescriptionHtml($rebuilt);
    };

    $latinShare = static function (string $s): float {
        $s = (string)$s;
        // считаем только буквенные символы: латиница vs (латиница+кириллица)
        preg_match_all('/[A-Za-z]/u', $s, $mLat);
        preg_match_all('/[A-Za-zА-Яа-яЁё]/u', $s, $mAll);

        $latin = isset($mLat[0]) ? count($mLat[0]) : 0;
        $all   = isset($mAll[0]) ? count($mAll[0]) : 0;

        if ($all <= 0) return 0.0;
        return $latin / $all;
    };


    $snippet = static function (string $raw, int $maxLen): string {
        $raw = $raw ?? '';
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

    // category cache
    $catCache = [];
    $loadCategory = static function (string $code) use (&$catCache): ?array {
        if ($code === '') return null;
        if (array_key_exists($code, $catCache)) return $catCache[$code];

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

        $payload = [
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'full_path' => (string)($row['full_path'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
            'typical_goods' => (string)($meta['typical_goods'] ?? ''),
            'features' => (string)($meta['features'] ?? ''),
            'keywords' => function_exists('ft_taxonomy_meta_keywords') ? ft_taxonomy_meta_keywords($meta) : '',
        ];

        $catCache[$code] = $payload;
        return $payload;
    };

    // ---------- PASS 1: scan offers + build GPT jobs ----------
    ops_update_progress($opId, 0, max(1, ($totalOffers > 0 ? $totalOffers : 1)), 'scan', 'scanning offers');

    $reader = new XMLReader();
    if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Cannot open XML: {$inputPath}");
    }

    $seenOffers = 0;
    $tick = 0;

    // jobs: offerId => payload(product+category)
    $jobs = [];
    $existingDescPlainByOffer = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

        $offerId = (string)$reader->getAttribute('id');
        $offerDepth = $reader->depth;

        if ($reader->isEmptyElement) {
            $seenOffers++;
            continue;
        }

        $inScope = $applyAll || ($offerId !== '' && isset($offerSet[$offerId]));
        if (!$inScope) {
            // всё равно нужно корректно пропустить offer; просто не будем сохранять job
        }

        $name = '';
        $vendorCode = '';
        $brand = '';
        $vendor = '';
        $modelTxt = '';
        $descInner = '';
        $ozonCategory = '';
        $wbCategory = '';
        $paramsArr = [];

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

            if ($tag === 'description') {
                // В description у тебя HTML в CDATA. readString() вернёт содержимое БЕЗ <![CDATA[ ]]>.
                $descInner = (string)$reader->readString();
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

        $ozonCategory = trim($ozonCategory);
        $wbCategory = trim($wbCategory);
        if ($ozonCategory === '' && $wbCategory === '') continue;

        $cat = ft_load_combined_marketplace_category_context($ozonCategory, $wbCategory, $useKeywords);
        if (!$cat) continue;

        $descPlain = $stripToPlain($descInner);
        $existingDescPlainByOffer[$offerId] = $descPlain;

        $latinRatio = $latinShare($descPlain);
        $rewriteBecauseLatin = ($latinRatio > 0.20);


        // если overwrite=0 — работаем только по пустым/коротким
        if (
            !$overwrite
            && !$rewriteBecauseLatin
            && $minDescLen > 0
            && mb_strlen($descPlain, 'UTF-8') >= $minDescLen
        ) {
            continue;
        }

        $operationMode = ($descPlain === '' || $rewriteBecauseLatin || ($minDescLen > 0 && mb_strlen($descPlain, 'UTF-8') < $minDescLen))
            ? 'generate'
            : 'rewrite';
        $productPayload = [
            'offer_id' => $offerId,
            'vendorCode' => $vendorCode,
            'name' => $name,
            'brand' => $brand,
            'vendor' => $vendor,
            'model' => $modelTxt,
            'description_existing_plain' => $snippet($descPlain, 5000),
            'params' => $paramsArr,
        ];
        $categoryPayload = [
            'code' => (string)$cat['code'],
            'name' => (string)$cat['name'],
            'full_path' => (string)$cat['full_path'],
            'description' => (string)$cat['description'],
            'typical_goods' => (string)$cat['typical_goods'],
            'features' => (string)$cat['features'],
            'keywords_lines' => $useKeywords ? (string)($cat['keywords_lines'] ?? '') : '',
            'marketplace_contexts' => $cat['marketplace_contexts'] ?? [],
        ];

        $jobs[$offerId] = [
            'offer_id' => $offerId,
            'ozon_category' => $ozonCategory,
            'wb_category' => $wbCategory,
            'product' => $productPayload,
            'category' => $categoryPayload,
            'card_brief' => ft_card_brief_build($productPayload, $categoryPayload, [
                'purpose' => 'description',
                'operation_mode' => $operationMode,
                'use_keywords' => $useKeywords,
            ]),
        ];

        if ($maxItems > 0 && count($jobs) >= $maxItems) break;
    }

    $reader->close();

    $log("scan: offers_seen={$seenOffers}, jobs=" . count($jobs) . "\n");

    // no candidates -> byte-copy
    if (count($jobs) === 0) {
        if (!copy($inputPath, $outXmlAbs)) {
            throw new RuntimeException("Cannot copy input XML to result: {$outXmlAbs}");
        }

        $report = [
            'summary' => [
                'offers_seen' => $seenOffers,
                'jobs' => 0,
                'offers_touched' => 0,
                'scope' => $applyAll ? 'all' : 'selected',
                'note' => 'no-op: no candidates by overwrite/min_desc_len/max_items',
            ],
            'params_effective' => [
                'model' => $model,
                'max_items' => (string)$maxItems,
                'inplace' => $inplace ? '1' : '0',
                'overwrite' => $overwrite ? '1' : '0',
                'min_desc_len' => (string)$minDescLen,
                'use_keywords' => $useKeywords ? '1' : '0',
            ],
        ];
        $reportAbs = $outDir . '/report.json';
        file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($inplace) {
            // validate + replace
            $tmpReader = new XMLReader();
            if (!$tmpReader->open($outXmlAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new RuntimeException("Result XML cannot be opened for validation: {$outXmlAbs}");
            }
            while ($tmpReader->read()) {
            }
            $tmpReader->close();

            $dstAbs = (string)($ds['stored_path'] ?? '');
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
            $upd->execute([$bytes, $newSha, (int)$scan['offers_count'], $warningsJson, (int)$ds['id']]);
        }

        return [
            'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
            'report_json' => rel_to_outputs($cfg, $reportAbs),
            'summary_json_inline' => [
                'title' => 'gpt_generate_description_ru',
                'items' => ['Jobs: 0', 'No-op copy'],
                'metrics' => ['jobs' => 0, 'offers_touched' => 0],
            ],
        ];
    }

    // ---------- PASS 2: GPT generate description ----------
    $gptTotal = max(1, count($jobs));
    $gptDone = 0;

    $perOffer = []; // offerId => ['status','description_html','error']
    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;

    foreach ($jobs as $offerId => $payload) {
        $gptDone++;
        ops_update_progress($opId, $gptDone, $gptTotal, 'gpt', "GPT {$gptDone}/{$gptTotal} offer={$offerId}");

        $status = 'ok';
        $err = '';
        $descHtml = '';
        $rawText = '';
        $raw = null;
        $u = ['input' => 0, 'cached_input' => 0, 'output' => 0];

        $requestPayload = [
            'card_brief' => $payload['card_brief'] ?? [],
            'category' => $payload['category'] ?? [],
            'product' => $payload['product'] ?? [],
        ];

        $inputJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) $inputJson = '{"product":{},"category":{}}';
        $userInput = "Return a json object only.\n\njson:\n" . $inputJson;

        try {
            $res = $client->generateText(
                $model,
                $userInput,
                $prompt,
                [
                    'prompt_cache_key' => 'gen_desc:' . substr(hash('sha256', json_encode(
                        $requestPayload['category'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ) ?: ''), 0, 48),
                    'text' => [
                        'format' => ['type' => 'json_object'],
                    ],
                ]
            );

            $rawText = (string)($res['output_text'] ?? '');
            $raw = $res['raw'] ?? null;

            $u = $extractUsage(is_array($raw) ? $raw : null);
            $tokensInTotal += (int)$u['input'];
            $tokensOutTotal += (int)$u['output'];
            $tokensCachedTotal += (int)$u['cached_input'];
            $costUsdTotal += $calcCostUSD((int)$u['input'], (int)$u['cached_input'], (int)$u['output']);

            $obj = $extractJsonObject($rawText);
            $descHtml = trim((string)($obj['description_html'] ?? ''));

            if ($descHtml === '') {
                throw new RuntimeException('GPT returned empty description_html');
            }

            $descHtml = $clampDescriptionHtmlForWb($descHtml, 1800);
            if ($descHtml === '') {
                throw new RuntimeException('GPT returned empty description after normalization');
            }

            // аварийная защита: очень длинный HTML всё равно режем
            if (mb_strlen($descHtml, 'UTF-8') > 20000) {
                $descHtml = mb_substr($descHtml, 0, 20000, 'UTF-8');
                $descHtml = rtrim($descHtml) . '…';
            }
        } catch (Throwable $e) {
            $status = 'error';
            $err = $e->getMessage();
        }

        $perOffer[$offerId] = [
            'status' => $status,
            'error' => $err,
            'description_html' => $descHtml,
        ];

        file_put_contents(
            $outDir . '/gpt/' . preg_replace('~[^a-zA-Z0-9_\-]+~', '_', $offerId) . '.json',
            json_encode([
                'offer_id' => $offerId,
                'status' => $status,
                'error' => $err,
                'payload' => $payload,
                'gpt_output_text' => $rawText,
                'usage' => $u,
                'raw' => $raw,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    $costUsdTotal = round($costUsdTotal, 6);
    $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");

    // подготовим карту для записи в XML: только ok
    $toWriteDesc = [];
    foreach ($perOffer as $oid => $r) {
        if (($r['status'] ?? '') !== 'ok') continue;
        $h = trim((string)($r['description_html'] ?? ''));
        if ($h === '') continue;
        $toWriteDesc[$oid] = $h;
    }

    // ---------- PASS 3: rewrite XML with <description> ----------
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
    $newDescHtml = '';

    $inDescription = false;
    $descriptionDepth = -1;
    $descriptionSeenInOffer = false;
    $skipDescriptionText = false;
    $descWritten = false;

    $seenOffers2 = 0;
    $offersTouched = 0;
    $progressTick2 = 0;

    while ($reader->read()) {
        switch ($reader->nodeType) {
            case XMLReader::ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer') {
                        $inOffer = true;
                        $offerDepth = $reader->depth;
                        $curOfferId = (string)$reader->getAttribute('id');

                        $inDescription = false;
                        $descriptionDepth = -1;
                        $descriptionSeenInOffer = false;
                        $skipDescriptionText = false;
                        $descWritten = false;

                        $targetOffer = ($curOfferId !== '' && isset($toWriteDesc[$curOfferId]));
                        $newDescHtml = $targetOffer ? (string)$toWriteDesc[$curOfferId] : '';

                        $writer->startElement('offer');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newDescHtml !== '') {
                                $writer->startElement('description');
                                $writer->writeCData($newDescHtml);
                                $writer->endElement();
                                $offersTouched++;
                            }
                            $writer->endElement();

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

                    if ($inOffer && $name === 'description') {
                        $descriptionSeenInOffer = true;
                        $inDescription = true;
                        $descriptionDepth = $reader->depth;
                        $skipDescriptionText = $targetOffer; // replace
                        $descWritten = false;

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newDescHtml !== '') {
                                $writer->writeCData($newDescHtml);
                                $descWritten = true;
                                $offersTouched++;
                            }
                            $writer->endElement();
                            $inDescription = false;
                            $descriptionDepth = -1;
                            $skipDescriptionText = false;
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
                    if ($inOffer && $inDescription && $skipDescriptionText) break;
                    $writer->text($reader->value);
                    break;
                }

            case XMLReader::CDATA: {
                    if ($inOffer && $inDescription && $skipDescriptionText) break;
                    $writer->writeCData($reader->value);
                    break;
                }

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    if ($inOffer && $inDescription && $name === 'description' && $reader->depth === $descriptionDepth) {
                        if ($targetOffer && !$descWritten && $newDescHtml !== '') {
                            $writer->writeCData($newDescHtml);
                            $descWritten = true;
                            $offersTouched++;
                        }
                        $writer->endElement();
                        $inDescription = false;
                        $descriptionDepth = -1;
                        $skipDescriptionText = false;
                        break;
                    }

                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        if ($targetOffer && !$descriptionSeenInOffer && $newDescHtml !== '') {
                            $writer->startElement('description');
                            $writer->writeCData($newDescHtml);
                            $writer->endElement();
                            $offersTouched++;
                        }

                        $writer->endElement();

                        $seenOffers2++;
                        $progressTick2++;
                        if ($totalOffers > 0 && ($progressTick2 % 200 === 0)) {
                            ops_update_progress($opId, min($seenOffers2, $totalOffers), $totalOffers, 'write', 'rewriting XML');
                        }

                        $inOffer = false;
                        $targetOffer = false;
                        $curOfferId = '';
                        $offerDepth = -1;
                        $inDescription = false;
                        $descriptionDepth = -1;
                        $descriptionSeenInOffer = false;
                        $skipDescriptionText = false;
                        $descWritten = false;
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

    // ---------- REPORT ----------
    $statusCounts = ['ok' => 0, 'error' => 0];
    foreach ($perOffer as $r) {
        $st = (string)($r['status'] ?? '');
        if (!isset($statusCounts[$st])) $statusCounts[$st] = 0;
        $statusCounts[$st]++;
    }

    $report = [
        'summary' => [
            'offers_seen' => $seenOffers,
            'jobs' => count($jobs),
            'gpt_status_counts' => $statusCounts,
            'offers_to_write' => count($toWriteDesc),
            'offers_touched' => $offersTouched,
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
            'min_desc_len' => (string)$minDescLen,
            'use_keywords' => $useKeywords ? '1' : '0',
        ],
    ];
    $reportAbs = $outDir . '/report.json';
    file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ---------- inplace ----------
    if ($inplace) {
        // validate
        $tmpReader = new XMLReader();
        if (!$tmpReader->open($outXmlAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Result XML cannot be opened for validation: {$outXmlAbs}");
        }
        while ($tmpReader->read()) {
        }
        $tmpReader->close();

        $dstAbs = (string)($ds['stored_path'] ?? '');
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
        $upd->execute([$bytes, $newSha, (int)$scan['offers_count'], $warningsJson, (int)$ds['id']]);
    }

    return [
        'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'summary_json_inline' => [
            'title' => 'gpt_generate_description_ru',
            'items' => [
                'Jobs: ' . count($jobs),
                'Touched offers: ' . $offersTouched,
                'Cost USD: ' . round($costUsdTotal, 6),
            ],
            'metrics' => [
                'jobs' => count($jobs),
                'offers_touched' => $offersTouched,
                'input_tokens' => $tokensInTotal,
                'cached_input_tokens' => $tokensCachedTotal,
                'output_tokens' => $tokensOutTotal,
                'cost_usd' => round($costUsdTotal, 6),
            ],
        ],
    ];
}
