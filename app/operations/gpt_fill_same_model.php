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

function op_gpt_fill_same_model(array $cfg, array $ds, int $opId, array $params, callable $log): array
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

    $overwrite = (string)($params['overwrite'] ?? '1');
    $overwrite = ($overwrite !== '' && $overwrite !== '0');

    $minGroup = (int)trim((string)($params['min_group_size'] ?? '1'));
    if ($minGroup < 1) $minGroup = 1;

    $simThr = (float)str_replace(',', '.', (string)($params['similarity_threshold'] ?? '0.90'));
    if ($simThr < 0.70) $simThr = 0.70;
    if ($simThr > 0.99) $simThr = 0.99;
    $minConfidence = (float)str_replace(',', '.', trim((string)($params['min_confidence'] ?? '0.76')));
    if ($minConfidence <= 0 || $minConfidence > 1) {
        $minConfidence = 0.76;
    }

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

    $log("gpt_fill_same_model: model={$model}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') .
        ", overwrite=" . ($overwrite ? '1' : '0') . ", min_group_size={$minGroup}, similarity_threshold={$simThr}, min_confidence={$minConfidence}\n");

    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'fill_same_model_ru.txt');
    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    // ---------- helpers ----------

    $trimText = static function (string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    };

    $descToSnippet = static function (string $raw, int $maxLen = 2000): string {
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


    $extractUsage = static function (?array $raw): array {
        $in = 0;
        $out = 0;
        $cached = 0;

        if (!$raw) return ['input' => 0, 'output' => 0, 'cached_input' => 0];

        // Responses API обычно кладёт usage в корень
        $u = $raw['usage'] ?? null;
        if (is_array($u)) {
            $in = (int)($u['input_tokens'] ?? 0);
            $out = (int)($u['output_tokens'] ?? 0);

            // cached input может лежать в details
            $details = $u['input_tokens_details'] ?? null;
            if (is_array($details)) {
                $cached = (int)($details['cached_tokens'] ?? 0);
                // иногда поле называется cached_input_tokens
                if ($cached === 0) {
                    $cached = (int)($details['cached_input_tokens'] ?? 0);
                }
            }
        }

        return ['input' => $in, 'output' => $out, 'cached_input' => $cached];
    };

    $calcCostUSD = static fn(int $inputTokens, int $cachedInputTokens, int $outputTokens): float
        => openai_cost_usd($cfg, $model, $inputTokens, $cachedInputTokens, $outputTokens);


    // normalized key for grouping
    $normKey = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('~[^\p{L}\p{N}]+~u', '_', $s);
        $s = preg_replace('~_+~u', '_', $s);
        $s = trim((string)$s, '_');
        // safety
        if (mb_strlen($s, 'UTF-8') > 80) {
            $s = mb_substr($s, 0, 80, 'UTF-8');
            $s = trim((string)$s, '_');
        }
        return $s;
    };

    $specificTokens = static function (string $s) use ($normKey): array {
        $out = [];
        if (preg_match_all('~[\p{L}]*\d[\p{L}\p{N}._#\/+\-*]*|[\p{L}\p{N}]+(?:[._#\/+\-*][\p{L}\p{N}]+)+~u', $s, $m)) {
            foreach ((array)($m[0] ?? []) as $token) {
                $key = $normKey((string)$token);
                if ($key !== '' && mb_strlen($key, 'UTF-8') >= 2) {
                    $out[$key] = true;
                }
            }
        }
        return array_keys($out);
    };
    $isGenericCore = static function (string $core, string $key, array $payload) use ($normKey, $specificTokens): bool {
        $coreNorm = $normKey($core);
        $key = $normKey($key);
        if ($coreNorm === '' || $key === '' || mb_strlen($key, 'UTF-8') < 3) {
            return true;
        }
        $product = (array)($payload['product'] ?? []);
        $brandKeys = [];
        foreach (['brand', 'vendor'] as $field) {
            $brandKey = $normKey((string)($product[$field] ?? ''));
            if ($brandKey !== '') {
                $brandKeys[$brandKey] = true;
            }
        }
        if (isset($brandKeys[$key]) || isset($brandKeys[$coreNorm])) {
            return true;
        }
        $generic = [
            'товар' => true,
            'изделие' => true,
            'аксессуар' => true,
            'аксессуары' => true,
            'комплект' => true,
            'набор' => true,
            'упаковка' => true,
            'запчасть' => true,
            'запчасти' => true,
            'деталь' => true,
            'детали' => true,
            'аккумулятор' => true,
            'батарея' => true,
            'зарядное_устройство' => true,
            'квадрокоптер' => true,
            'дрон' => true,
            'камера' => true,
            'объектив' => true,
            'пропеллер' => true,
            'саморезы' => true,
            'винты' => true,
            'кабель' => true,
            'провод' => true,
            'чехол' => true,
            'пульт' => true,
            'мотор' => true,
            'двигатель' => true,
            'плата' => true,
            'модуль' => true,
        ];
        if (isset($generic[$key]) || isset($generic[$coreNorm])) {
            return true;
        }
        $parts = array_values(array_filter(explode('_', $key), static fn($p): bool => $p !== ''));
        $hasSpecific = (bool)$specificTokens($core);
        if (!$hasSpecific) {
            $model = trim((string)($product['model'] ?? ''));
            $hasSpecific = $model !== '' && str_contains($normKey($model), $key);
        }
        if (!$hasSpecific && count($parts) <= 1) {
            return true;
        }
        if (!$hasSpecific && count($parts) <= 2 && count($brandKeys) > 0) {
            foreach (array_keys($brandKeys) as $brandKey) {
                if ($brandKey !== '' && str_starts_with($key, $brandKey . '_')) {
                    return true;
                }
            }
        }
        return false;
    };

    // similarity ratio using levenshtein (fast enough) + quick checks
    $simRatio = static function (string $a, string $b) use ($normKey): float {
        $a = $normKey($a);
        $b = $normKey($b);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;

        $la = mb_strlen($a, 'UTF-8');
        $lb = mb_strlen($b, 'UTF-8');
        $max = max($la, $lb);
        if ($max === 0) return 0.0;

        // levenshtein works on bytes; for UTF-8 we still get usable heuristic if keys are ASCII-ish.
        // Our keys are mostly [a-z0-9_] after norm.
        $d = levenshtein($a, $b);
        if ($d < 0) return 0.0;
        $r = 1.0 - ($d / $max);
        if ($r < 0) $r = 0.0;
        if ($r > 1) $r = 1.0;
        return $r;
    };

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

    // jobs: offerId => payload(product+category + source_text)
    $jobs = [];
    $existingSameModelByOffer = []; // offer_id => existing same_model (trim)


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
            // skip subtree fast
        }

        $name = '';
        $vendorCode = '';
        $brand = '';
        $vendor = '';
        $modelTxt = '';
        $descRaw = '';
        $ozonCategory = '';
        $wbCategory = '';
        $sameModelExisting = '';

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

            if ($tag === 'same_model') {
                $sameModelExisting = $trimText($reader->readString());
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
        $existingSameModelByOffer[$offerId] = trim($sameModelExisting);



        $ozonCategory = trim($ozonCategory);
        $wbCategory = trim($wbCategory);
        if ($ozonCategory === '' && $wbCategory === '') continue;

        $cat = ft_load_combined_marketplace_category_context($ozonCategory, $wbCategory, false);
        if (!$cat) continue;

        // source line: <name> -> <vendorCode> -> <model>
        $sourceLine = $name !== '' ? $name : ($vendorCode !== '' ? $vendorCode : $modelTxt);
        $categoryKeyParts = [];
        foreach ([$ozonCategory, $wbCategory] as $categoryPart) {
            $categoryPartKey = $normKey((string)$categoryPart);
            if ($categoryPartKey !== '') {
                $categoryKeyParts[$categoryPartKey] = true;
            }
        }

        $jobs[$offerId] = [
            'offer_id' => $offerId,
            'ozon_category' => $ozonCategory,
            'wb_category' => $wbCategory,
            'product' => [
                'offer_id' => $offerId,
                'vendorCode' => $vendorCode,
                'name' => $name,
                'brand' => $brand,
                'vendor' => $vendor,
                'model' => $modelTxt,
                'same_model_existing' => trim($sameModelExisting),
                'source_line' => $sourceLine,
                'description' => $descToSnippet($descRaw, 2500),
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
            'category_key' => implode('_', array_keys($categoryKeyParts)),
        ];

        if ($maxItems > 0 && count($jobs) >= $maxItems) {
            break;
        }
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
                'groups' => 0,
                'offers_touched' => 0,
                'scope' => $applyAll ? 'all' : 'selected',
                'note' => 'no-op: result is exact copy of input',
            ],
            'params_effective' => [
                'model' => $model,
                'max_items' => (string)$maxItems,
                'inplace' => $inplace ? '1' : '0',
                'overwrite' => $overwrite ? '1' : '0',
                'min_group_size' => (string)$minGroup,
                'similarity_threshold' => (string)$simThr,
                'min_confidence' => (string)$minConfidence,
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
                'title' => 'gpt_fill_same_model',
                'items' => ['Jobs: 0', 'No-op copy'],
                'metrics' => ['jobs' => 0, 'groups' => 0, 'offers_touched' => 0],
            ],
        ];
    }

    // ---------- PASS 2: GPT extract core+key ----------
    $gptTotal = max(1, count($jobs));
    $gptDone = 0;

    // offerId => ['status','core','key','error']
    $perOffer = [];

    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;


    foreach ($jobs as $offerId => $payload) {
        $gptDone++;
        ops_update_progress($opId, $gptDone, $gptTotal, 'gpt', "GPT {$gptDone}/{$gptTotal} offer={$offerId}");

        $status = 'ok';
        $err = '';
        $core = '';
        $key = '';
        $confidence = 0.0;
        $typeKey = '';
        $brandKey = $normKey((string)(($payload['product']['brand'] ?? '') ?: ($payload['product']['vendor'] ?? '')));
        $categoryKey = (string)($payload['category_key'] ?? '');
        $reason = '';
        $source = 'gpt';
        $existingCore = trim((string)($payload['product']['same_model_existing'] ?? ''));
        if (!$overwrite && $existingCore !== '') {
            // existing считается уже определённым ядром
            $core = $existingCore;
            $key = $normKey($existingCore);
            if ($key === '') {
                $status = 'error';
                $err = 'Existing same_model cannot be normalized to key';
            }
            $perOffer[$offerId] = [
                'status' => $status,
                'error' => $err,
                'core' => $core,
                'key' => $key,
                'confidence' => 1.0,
                'type_key' => '',
                'brand_key' => $normKey((string)(($payload['product']['brand'] ?? '') ?: ($payload['product']['vendor'] ?? ''))),
                'category_key' => (string)($payload['category_key'] ?? ''),
                'reason' => 'existing same_model',
                'source' => 'existing',
            ];
            // Можно сохранить короткий лог-файл, но GPT не вызываем
            file_put_contents(
                $outDir . '/gpt/' . preg_replace('~[^a-zA-Z0-9_\-]+~', '_', $offerId) . '.json',
                json_encode([
                    'offer_id' => $offerId,
                    'status' => $status,
                    'error' => $err,
                    'payload' => $payload,
                    'core' => $core,
                    'key' => $key,
                    'confidence' => 1.0,
                    'type_key' => '',
                    'brand_key' => $normKey((string)(($payload['product']['brand'] ?? '') ?: ($payload['product']['vendor'] ?? ''))),
                    'category_key' => (string)($payload['category_key'] ?? ''),
                    'reason' => 'existing same_model',
                    'source' => 'existing',
                    'usage' => ['input' => 0, 'cached_input' => 0, 'output' => 0],
                    'raw' => null,
                    'gpt_output_text' => null,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            continue;
        }

        $requestPayload = [
            'category' => $payload['category'] ?? [],
            'product' => $payload['product'] ?? [],
        ];

        $inputJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    'prompt_cache_key' => 'same_model:' . substr(hash('sha256', json_encode(
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

            $core = trim((string)($obj['core'] ?? ''));
            $key  = trim((string)($obj['key'] ?? ''));
            $confidenceRaw = $obj['confidence'] ?? null;
            if (is_numeric($confidenceRaw)) {
                $confidence = max(0.0, min(1.0, (float)$confidenceRaw));
            }
            $typeKey = $normKey((string)($obj['type_key'] ?? ($obj['product_type'] ?? '')));
            $brandKeyFromGpt = $normKey((string)($obj['brand_key'] ?? ''));
            if ($brandKeyFromGpt !== '') {
                $brandKey = $brandKeyFromGpt;
            }
            $reason = trim((string)($obj['reason'] ?? ''));

            // safety: if key missing, derive from core
            if ($key === '' && $core !== '') $key = $core;

            $key = $normKey($key);

            // if core empty but key exists -> make core human-readable from key
            if ($core === '' && $key !== '') {
                $core = str_replace('_', ' ', $key);
            }

            // limit core length
            if (mb_strlen($core, 'UTF-8') > 120) {
                $core = mb_substr($core, 0, 120, 'UTF-8');
                $core = trim($core);
            }

            if ($key === '' || $core === '') {
                $status = 'empty';
                $err = 'GPT returned empty core/key';
            } elseif ($confidence < $minConfidence) {
                $status = 'low_confidence';
                $err = 'confidence ' . round($confidence, 3) . ' ниже порога ' . $minConfidence;
            } elseif ($isGenericCore($core, $key, $payload)) {
                $status = 'generic';
                $err = 'слишком общий core/key для безопасной группировки';
            }
        } catch (Throwable $e) {
            $status = 'error';
            $err = $e->getMessage();
        }

        $perOffer[$offerId] = [
            'status' => $status,
            'error' => $err,
            'core' => $core,
            'key' => $key,
            'confidence' => $confidence,
            'type_key' => $typeKey,
            'brand_key' => $brandKey,
            'category_key' => $categoryKey,
            'reason' => $reason,
            'source' => 'gpt',
        ];


        file_put_contents(
            $outDir . '/gpt/' . preg_replace('~[^a-zA-Z0-9_\-]+~', '_', $offerId) . '.json',
            json_encode([
                'offer_id' => $offerId,
                'status' => $status,
                'error' => $err,
                'payload' => $payload,
                'gpt_output_text' => $rawText,
                'core' => $core,
                'key' => $key,
                'confidence' => $confidence,
                'type_key' => $typeKey,
                'brand_key' => $brandKey,
                'category_key' => $categoryKey,
                'reason' => $reason,
                'usage' => $u,

                'raw' => $raw,
                'source' => 'gpt',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    $costUsdTotal = round($costUsdTotal, 6);
    $log("TOTAL usage: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("TOTAL cost ({$model}): \${$costUsdTotal}\n");

    // ---------- PASS 2.5: group by key + fuzzy merge ----------
    // initial groups by exact key
    $groups = []; // key => offerIds[]
    foreach ($perOffer as $offerId => $r) {
        if (($r['status'] ?? '') !== 'ok') continue;
        $k = (string)($r['key'] ?? '');
        if ($k === '') continue;
        $groupKey = $k;
        $brandKey = (string)($r['brand_key'] ?? '');
        $typeKey = (string)($r['type_key'] ?? '');
        $categoryKey = (string)($r['category_key'] ?? '');
        if ($brandKey !== '') {
            $groupKey .= '|b:' . $brandKey;
        }
        if ($typeKey !== '') {
            $groupKey .= '|t:' . $typeKey;
        } elseif ($categoryKey !== '') {
            $groupKey .= '|c:' . $categoryKey;
        }
        if (!isset($groups[$groupKey])) $groups[$groupKey] = [];
        $groups[$groupKey][] = $offerId;
    }

    // fuzzy merge similar keys to stabilize grouping (category-aware already via GPT)
    $keys = array_keys($groups);

    // bucket by prefix to reduce comparisons
    $buckets = [];
    foreach ($keys as $k) {
        $p = substr($k, 0, 6);
        if (!isset($buckets[$p])) $buckets[$p] = [];
        $buckets[$p][] = $k;
    }

    // union-find
    $parent = [];
    foreach ($keys as $k) $parent[$k] = $k;

    $find = function ($x) use (&$parent, &$find) {
        if ($parent[$x] === $x) return $x;
        $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };
    $union = function ($a, $b) use (&$parent, $find) {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra === $rb) return;
        // attach shorter to longer as heuristic
        if (strlen($ra) < strlen($rb)) {
            $parent[$ra] = $rb;
        } else {
            $parent[$rb] = $ra;
        }
    };

    foreach ($buckets as $pref => $list) {
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $specificA = $specificTokens($a);
                $specificB = $specificTokens($b);
                if ($specificA && $specificB) {
                    sort($specificA, SORT_STRING);
                    sort($specificB, SORT_STRING);
                    if ($specificA !== $specificB) {
                        continue;
                    }
                }
                $r = $simRatio($a, $b);
                if ($r >= $simThr) {
                    $union($a, $b);
                }
            }
        }
    }

    // merged groups
    $merged = []; // root => ['keys'=>[], 'offers'=>[]]
    foreach ($groups as $k => $offerList) {
        $root = $find($k);
        if (!isset($merged[$root])) $merged[$root] = ['keys' => [], 'offers' => []];
        $merged[$root]['keys'][] = $k;
        foreach ($offerList as $oid) $merged[$root]['offers'][] = $oid;
    }

    // choose representative core phrase per merged group
    // strategy: take most frequent core among offers in that merged group; tie -> shortest
    $groupCore = []; // root => core string
    foreach ($merged as $root => $g) {
        $freq = [];
        foreach ($g['offers'] as $oid) {
            $c = (string)($perOffer[$oid]['core'] ?? '');
            if ($c === '') continue;
            if (!isset($freq[$c])) $freq[$c] = 0;
            $w = ((string)($perOffer[$oid]['source'] ?? '') === 'existing') ? 3 : 1;
            $freq[$c] += $w;
        }
        if (!$freq) {
            $groupCore[$root] = '';
            continue;
        }
        arsort($freq);
        $bestCount = reset($freq);
        $best = [];
        foreach ($freq as $c => $cnt) {
            if ($cnt !== $bestCount) break;
            $best[] = $c;
        }
        usort($best, function ($a, $b) {
            return mb_strlen($a, 'UTF-8') <=> mb_strlen($b, 'UTF-8');
        });
        $groupCore[$root] = $best[0];
    }

    // final map offerId => same_model (core) for ALL offers (including singletons)
    $finalSameModel = []; // offerId => string(core)
    $groupsCount = 0;

    foreach ($merged as $root => $g) {
        $offers = array_values(array_unique($g['offers'] ?? []));
        $core = (string)($groupCore[$root] ?? '');
        if ($core === '') continue;

        if (count($offers) >= $minGroup) {
            $groupsCount++;
        }

        foreach ($offers as $oid) {
            $finalSameModel[$oid] = $core;
        }
    }

    // decide what to actually write into XML (respect overwrite + existing <same_model>)
    $toWriteSameModel = []; // offerId => core to write into XML
    foreach ($finalSameModel as $oid => $core) {
        $existing = trim((string)($existingSameModelByOffer[$oid] ?? ''));
        if ($overwrite || $existing === '') {
            $toWriteSameModel[$oid] = $core;
        }
    }

    $log(
        "grouping: groups_exact=" . count($groups) .
            ", merged_groups=" . count($merged) .
            ", groups_written={$groupsCount}" .
            ", offers_core_total=" . count($finalSameModel) .
            ", offers_to_write=" . count($toWriteSameModel) . "\n"
    );

    $log("grouping: groups_exact=" . count($groups) . ", merged_groups=" . count($merged) . ", groups_written={$groupsCount}, offers_to_write=" . count($finalSameModel) . "\n");

    // ---------- PASS 3: rewrite XML with <same_model> ----------
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
    $newSameModel = '';

    $inSameModel = false;
    $sameModelDepth = -1;
    $sameModelSeenInOffer = false;
    $skipSameModelText = false;
    $sameModelTextWritten = false;

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

                        $inSameModel = false;
                        $sameModelDepth = -1;
                        $sameModelSeenInOffer = false;
                        $skipSameModelText = false;
                        $sameModelTextWritten = false;

                        $targetOffer = ($curOfferId !== '' && isset($toWriteSameModel[$curOfferId]));
                        $newSameModel = $targetOffer ? (string)$toWriteSameModel[$curOfferId] : '';

                        $writer->startElement('offer');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newSameModel !== '') {
                                $writer->startElement('same_model');
                                $writer->text($newSameModel);
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

                    if ($inOffer && $name === 'same_model') {
                        $sameModelSeenInOffer = true;
                        $inSameModel = true;
                        $sameModelDepth = $reader->depth;
                        $skipSameModelText = $targetOffer; // replace text
                        $sameModelTextWritten = false;

                        if ($reader->isEmptyElement) {
                            if ($targetOffer && $newSameModel !== '') {
                                $writer->text($newSameModel);
                                $sameModelTextWritten = true;
                                $offersTouched++;
                            }
                            $writer->endElement();
                            $inSameModel = false;
                            $sameModelDepth = -1;
                            $skipSameModelText = false;
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
                    if ($inOffer && $inSameModel && $skipSameModelText) {
                        break;
                    }
                    $writer->text($reader->value);
                    break;
                }

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    if ($inOffer && $inSameModel && $name === 'same_model' && $reader->depth === $sameModelDepth) {
                        if ($targetOffer && !$sameModelTextWritten && $newSameModel !== '') {
                            $writer->text($newSameModel);
                            $sameModelTextWritten = true;
                            $offersTouched++;
                        }
                        $writer->endElement();
                        $inSameModel = false;
                        $sameModelDepth = -1;
                        $skipSameModelText = false;
                        break;
                    }

                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        if ($targetOffer && !$sameModelSeenInOffer && $newSameModel !== '') {
                            $writer->startElement('same_model');
                            $writer->text($newSameModel);
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
                        $inSameModel = false;
                        $sameModelDepth = -1;
                        $sameModelSeenInOffer = false;
                        $skipSameModelText = false;
                        $sameModelTextWritten = false;
                        break;
                    }

                    $writer->endElement();
                    break;
                }

            case XMLReader::CDATA:
                if ($inOffer && $inSameModel && $skipSameModelText) break;
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
            'groups_written' => $groupsCount,
            'offers_to_write' => count($finalSameModel),
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
            'min_group_size' => (string)$minGroup,
            'similarity_threshold' => (string)$simThr,
            'min_confidence' => (string)$minConfidence,
        ],
    ];
    $reportAbs = $outDir . '/report.json';
    file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ---------- inplace ----------
    if ($inplace) {
        // validate by read-through
        $tmpReader = new XMLReader();
        if (!$tmpReader->open($outXmlAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Result XML cannot be opened for validation: {$outXmlAbs}");
        }
        while ($tmpReader->read()) {
        }
        $tmpReader->close();

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
        $upd->execute([$bytes, $newSha, (int)$scan['offers_count'], $warningsJson, (int)$ds['id']]);

        $ins = db()->prepare("
          INSERT INTO feedtools_derivations (op_id, output_key, dataset_id, sha256, is_duplicate)
          VALUES (?, ?, ?, ?, 0)
        ");
        $ins->execute([(int)$opId, 'inplace_update', (int)$ds['id'], $newSha]);
    }

    return [
        'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'summary_json_inline' => [
            'title' => 'gpt_fill_same_model',
            'items' => [
                'Jobs: ' . count($jobs),
                'Groups written: ' . $groupsCount,
                'Offers touched: ' . $offersTouched,
                'Tokens in: ' . $tokensInTotal,
                'Tokens cached in: ' . $tokensCachedTotal,
                'Tokens out: ' . $tokensOutTotal,
                'Cost USD: $' . round($costUsdTotal, 4),

            ],
            'metrics' => [
                'jobs' => count($jobs),
                'groups_written' => $groupsCount,
                'offers_touched' => $offersTouched,
                'offers_to_write' => count($finalSameModel),
                'inplace' => $inplace ? 1 : 0,
                'tokens_in' => $tokensInTotal,
                'tokens_cached_in' => $tokensCachedTotal,
                'tokens_out' => $tokensOutTotal,
                'cost_usd' => round($costUsdTotal, 6),

            ],
        ],
    ];
}
