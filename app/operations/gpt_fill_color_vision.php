<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';
require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';
require_once __DIR__ . '/../llm/PromptTemplates.php';

function op_gpt_fill_color_vision(array $cfg, array $ds, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$ds['id'];
    $inputPath = (string)($ds['stored_path'] ?? '');
    if ($inputPath === '' || !is_file($inputPath)) {
        throw new RuntimeException("Input XML not found: {$inputPath}");
    }

    $requestedModel = LLM::modelForOp($cfg, $params);
    $model = LLM::modelForVisionOp($cfg, $params);

    $maxItems = (int)trim((string)($params['max_items'] ?? '0'));
    if ($maxItems < 0) $maxItems = 0;

    $inplace = (string)($params['inplace'] ?? '1');
    $inplace = ($inplace !== '' && $inplace !== '0');

    $autoDataset = (string)($params['auto_dataset'] ?? '0');
    $autoDataset = ($autoDataset !== '' && $autoDataset !== '0');

    // selected offers (optional): run_op.php кладёт в params['offer_ids']
    $offerIds = [];
    if (!empty($params['offer_ids']) && is_array($params['offer_ids'])) {
        foreach ($params['offer_ids'] as $v) {
            $s = (string)$v;          // НЕ trim
            if ($s !== '') $offerIds[] = $s;
        }
        // unique без переупорядочивания и без trim
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

    $log("gpt_fill_color_vision: model={$model}, requested_model={$requestedModel}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') . ", auto_dataset=" . ($autoDataset ? '1' : '0') . "\n");
    $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));

    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'color_detect_vision_ru.txt');
    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");

    $norm = static function (string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        return mb_strtolower($s, 'UTF-8');
    };

    $descToSnippet = static function (string $raw, int $maxLen = 600): string {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $raw = strip_tags($raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        if (mb_strlen($raw, 'UTF-8') > $maxLen) {
            return mb_substr($raw, 0, $maxLen, 'UTF-8') . '…';
        }
        return $raw;
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

    $normalizeColor = static function (string $s): string {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return '';
        $first = mb_substr($s, 0, 1, 'UTF-8');
        $rest  = mb_substr($s, 1, null, 'UTF-8');
        return mb_strtoupper($first, 'UTF-8') . $rest;
    };

    // NEW: detect colors from name/description (avoid GPT call if found)
    $detectColorsFromText = static function (string $name, string $desc) use ($normalizeColor): array {
        $text = trim($name . ' ' . $desc);
        if ($text === '') return [];

        $t = mb_strtolower($text, 'UTF-8');
        $t = str_replace('ё', 'е', $t);

        $patterns = [
            'Черный'      => '/\bчерн(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Белый'       => '/\bбел(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Серый'       => '/\bсер(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Красный'     => '/\bкрасн(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Синий'       => '/\bсин(ий|яя|ее|ие|его|ему|им|их|юю)?\b/u',
            'Голубой'     => '/\bголуб(ой|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Зеленый'     => '/\bзелен(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Желтый'      => '/\bжелт(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Оранжевый'   => '/\bоранжев(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Фиолетовый'  => '/\bфиолетов(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Розовый'     => '/\bрозов(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Коричневый'  => '/\bкоричнев(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Бежевый'     => '/\bбежев(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Золотой'     => '/\bзолот(ой|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
            'Серебристый' => '/\bсеребрист(ый|ая|ое|ые|ого|ому|ым|ых|ую)?\b/u',
        ];

        $found = [];
        foreach ($patterns as $color => $re) {
            if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['color' => $normalizeColor($color), 'pos' => (int)$m[0][1]];
            }
        }
        if (!$found) return [];

        usort($found, fn($a, $b) => $a['pos'] <=> $b['pos']);

        $out = [];
        $seen = [];
        foreach ($found as $x) {
            $c = (string)$x['color'];
            if ($c === '' || isset($seen[$c])) continue;
            $seen[$c] = true;
            $out[] = $c;
            if (count($out) >= 3) break;
        }
        return $out;
    };

    // -------- PASS 1: find candidates (missing filled Цвет) --------
    ops_update_progress($opId, 0, max(1, ($totalOffers > 0 ? $totalOffers : 1)), 'scan', 'finding offers without color or with non-standard color');

    $reader = new XMLReader();
    if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Cannot open XML: {$inputPath}");
    }

    $candidates = []; // offerId => data
    $seenOffers = 0;
    
    $skipElement = static function (XMLReader $r): void {
        // Move reader to the matching END_ELEMENT of the current ELEMENT.
        // After this function returns, caller should NOT process the subtree nodes.
        if ($r->isEmptyElement) return;
        $startDepth = $r->depth;
        $startName  = $r->name;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $startDepth && $r->name === $startName) {
                break; // now positioned on END_ELEMENT
            }
        }
    };

$progressTick = 0;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

        $offerDepth = $reader->depth;
        $offerId = (string)$reader->getAttribute('id');

        $name = '';
        $vendorCode = '';
        $descRaw = '';
        $firstPic = '';

        $hasFilledColor = false;

        $colorVals = [];

        if ($reader->isEmptyElement) {
            $seenOffers++;
            continue;
        }

        // read inside offer
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) continue;

            $tag = $reader->name;

            if ($tag === 'name') {
                $name = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                continue;
            }
            if ($tag === 'vendorCode') {
                $vendorCode = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                continue;
            }
            if ($tag === 'description') {
                // description может содержать HTML-энтити
                $descRaw = (string)$reader->readInnerXml();
                continue;
            }
            if ($tag === 'picture') {
                if ($firstPic === '') {
                    $firstPic = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                } else {
                    // consume
                    $reader->readString();
                }
                continue;
            }
            if ($tag === 'param') {
                $pname = trim((string)$reader->getAttribute('name'));
                $pval  = trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($pname !== '' && $norm($pname) === 'цвет') {
                    if ($pval !== '') {
                        $hasFilledColor = true;
                        $colorVals[] = $pval;
                    }
                }
                continue;
            }

            // other tags: consume cheaply
            if (!$reader->isEmptyElement) {
                $reader->readString();
            }
        }

        $seenOffers++;
        $progressTick++;
        if ($totalOffers > 0 && ($progressTick % 250 === 0)) {
            ops_update_progress($opId, $seenOffers, $totalOffers, 'scan', 'scanning offers');
        }

        if (!$applyAll && $offerId !== '' && !isset($offerSet[$offerId])) {
            continue;
        }

                if ($offerId === '') continue;

        // decide whether we should (re)detect color:
        // - no color at all -> detect
        // - placeholder values ("цвет не указан", "не указан", etc.) -> detect
        // - non-standard color values (not in whitelist) -> detect and overwrite
        $needRedetect = !$hasFilledColor;

        if (!$needRedetect) {
            $standard = [
                'черный' => true, 'белый' => true, 'серый' => true, 'красный' => true,
                'синий' => true, 'голубой' => true, 'зеленый' => true, 'желтый' => true,
                'оранжевый' => true, 'фиолетовый' => true, 'розовый' => true, 'коричневый' => true,
                'бежевый' => true, 'золотой' => true, 'серебристый' => true,
                // доп. частые "стандарты"
                'прозрачный' => true, 'разноцветный' => true, 'мультиколор' => true, 'многоцветный' => true,
            ];

            foreach ($colorVals as $cv) {
                $v = $norm((string)$cv);
                $v = str_replace('ё', 'е', $v);
                $v = preg_replace('/[\.;:]+$/u', '', $v);
                $v = trim((string)$v);

                if ($v === '' || $v === 'цвет не указан' || $v === 'не указан' || $v === 'не указано' || $v === 'нет') {
                    $needRedetect = true;
                    break;
                }

                // multiple values: "Черный, Белый"
                $parts = preg_split('/\s*[,\/\|]+\s*/u', $v, -1, PREG_SPLIT_NO_EMPTY);
                if (!$parts) $parts = [$v];

                foreach ($parts as $p) {
                    $p = trim((string)$p);
                    if ($p === '') continue;
                    if (!isset($standard[$p])) {
                        $needRedetect = true;
                        break 2;
                    }
                }
            }
        }

        if (!$needRedetect) continue;

        $candidates[$offerId] = [
            'offer_id' => $offerId,
            'vendorCode' => $vendorCode,
            'name' => $name,
            'desc' => $descToSnippet($descRaw, 650),
            'picture' => $firstPic,
        ];

        if ($maxItems > 0 && count($candidates) >= $maxItems) {
            // early stop: enough items
            break;
        }
    }

    $reader->close();

    $log("scan: offers_seen={$seenOffers}, candidates_need_redetect=" . count($candidates) . "\n");

    // -------- PREPASS: try detect color from text (name/desc) to avoid GPT --------
    $colorMap = []; // offerId => ['colors'=>[...], 'status'=>..., 'error'=>...]
    $candidatesForGpt = [];
    $filledFromText = 0;

    foreach ($candidates as $offerId => $item) {
        $colorsText = $detectColorsFromText((string)($item['name'] ?? ''), (string)($item['desc'] ?? ''));
        if (count($colorsText) > 0) {
            $colorMap[$offerId] = ['colors' => $colorsText, 'status' => 'from_text'];
            $filledFromText++;
        } else {
            $candidatesForGpt[$offerId] = $item;
        }
    }

    $log("prepass text: filled={$filledFromText}, need_gpt=" . count($candidatesForGpt) . "\n");

    // -------- PASS 2: GPT calls --------
    $gptTotal = max(1, count($candidatesForGpt));
    $gptDone = 0;

    ensure_dir($outDir . '/gpt');

    if (count($candidatesForGpt) === 0) {
        $log("GPT skipped: all colors were detected from text (or no candidates).\n");
    }

    $tokensInTotal = 0;
    $tokensOutTotal = 0;
    $tokensCachedTotal = 0;
    $costUsdTotal = 0.0;

    foreach ($candidatesForGpt as $offerId => $item) {
        $gptDone++;
        ops_update_progress($opId, $gptDone, $gptTotal, 'gpt', "detecting color ({$gptDone}/{$gptTotal})");

        $pic = (string)($item['picture'] ?? '');
        if ($pic === '') {
            $colorMap[$offerId] = ['colors' => [], 'status' => 'no_picture'];
            continue;
        }

        $productJson = [
            'offer_id' => (string)$item['offer_id'],
            'vendorCode' => (string)$item['vendorCode'],
            'name' => (string)$item['name'],
            'desc' => (string)$item['desc'],
        ];

        $input = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => "JSON:\n" . json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ['type' => 'input_image', 'image_url' => $pic],
                ],
            ],
        ];

        try {
            $res = $client->generateText($model, $input, $prompt, [
                'prompt_cache_key' => 'fill_color:' . substr(hash('sha256', $prompt), 0, 48),
                'temperature' => 0.2,
                'max_output_tokens' => 220,
                'text' => ['format' => ['type' => 'json_object']],
            ]);

            $usage = $extractUsage($res['raw'] ?? null);
            $tokensInTotal += (int)$usage['input'];
            $tokensOutTotal += (int)$usage['output'];
            $tokensCachedTotal += (int)$usage['cached_input'];
            $costUsdTotal += $calcCostUSD((int)$usage['input'], (int)$usage['cached_input'], (int)$usage['output']);

            $outText = (string)($res['output_text'] ?? '');
            $obj = $extractJsonFromText($outText);

            $colors = [];
            if (isset($obj['colors']) && is_array($obj['colors'])) {
                foreach ($obj['colors'] as $c) {
                    $cc = $normalizeColor((string)$c);
                    if ($cc !== '') $colors[] = $cc;
                }
            }

            // unique + max 3
            $uniq = [];
            foreach ($colors as $c) $uniq[$c] = true;
            $colors = array_keys($uniq);
            if (count($colors) > 3) $colors = array_slice($colors, 0, 3);

            $colorMap[$offerId] = ['colors' => $colors, 'status' => (count($colors) ? 'ok' : 'undetermined')];

            file_put_contents(
                $outDir . '/gpt/' . preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '_', $offerId) . '.json',
                json_encode(
                    [
                        'offer_id' => $offerId,
                        'picture' => $pic,
                        'product' => $productJson,

                        'gpt_output_text' => $outText,
                        'parsed' => $obj,
                        'colors_final' => $colors,
                        'gpt_usage' => $usage,
                        'gpt_cost_usd' => $calcCostUSD((int)$usage['input'], (int)$usage['cached_input'], (int)$usage['output']),
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (Throwable $e) {
            $colorMap[$offerId] = ['colors' => [], 'status' => 'error', 'error' => $e->getMessage()];
            $log("GPT ERROR offer={$offerId}: " . $e->getMessage() . "\n");
        }
    }

    $costUsdTotal = round($costUsdTotal, 6);
    $log("GPT usage total: input={$tokensInTotal}, cached_input={$tokensCachedTotal}, output={$tokensOutTotal}\n");
    $log("GPT cost total (USD): {$costUsdTotal}\n");

    // -------- PASS 3: rewrite XML (result.xml) --------
    ops_update_progress($opId, 0, max(1, ($totalOffers > 0 ? $totalOffers : 1)), 'rewrite', 'writing result.xml');

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

    $seenOffers2 = 0;
    $removedOldColorParams = 0;
    $insertedColorParams = 0;
    $offersInsertedNonEmpty = 0;

    $progressTick = 0;

    while ($reader->read()) {
        switch ($reader->nodeType) {

            case XMLReader::ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer') {
                        $inOffer = true;
                        $offerDepth = $reader->depth;
                        $curOfferId = (string)$reader->getAttribute('id');
                        $targetOffer = ($curOfferId !== '' && array_key_exists($curOfferId, $colorMap));

                        $writer->startElement($name);
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            // empty offer: if targeted, still can add color params (rare)
                            if ($targetOffer) {
                                $colors = (array)($colorMap[$curOfferId]['colors'] ?? []);
                                if (count($colors) > 0) {
                                    foreach ($colors as $c) {
                                        $writer->startElement('param');
                                        $writer->writeAttribute('name', 'Цвет');
                                        $writer->text((string)$c);
                                        $writer->endElement();
                                        $insertedColorParams++;
                                    }
}
                            }
                            $writer->endElement();
                            $inOffer = false;
                            $targetOffer = false;
                            $curOfferId = '';
                            $offerDepth = -1;
                        }

                        break;
                    }

                    // remove any existing <param name="Цвет"> in targeted offers
                    if ($inOffer && $targetOffer && $name === 'param') {
                        $pname = trim((string)$reader->getAttribute('name'));
                        if ($pname !== '' && $norm($pname) === 'цвет') {
                            // IMPORTANT: skip the whole <param> subtree so we don't leak its TEXT/CDATA
                            // into the output stream (this would corrupt XML).
                            $removedOldColorParams++;
                            $skipElement($reader);
                            break; // skip writing this element entirely
                        }
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

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        // before closing offer, insert up to 3 colors if any
                        if ($targetOffer) {
                            $colors = (array)($colorMap[$curOfferId]['colors'] ?? []);
                            if (count($colors) > 0) {
                                $offersInsertedNonEmpty++;
                                foreach ($colors as $c) {
                                    // <param name="Цвет">...</param>
                                    $writer->startElement('param');
                                    $writer->writeAttribute('name', 'Цвет');
                                    $writer->text((string)$c);
                                    $writer->endElement();
                                    $insertedColorParams++;
                                }
                            }
                        }

                        $writer->endElement(); // </offer>

                        $seenOffers2++;
                        $progressTick++;

                        if ($totalOffers > 0 && ($progressTick % 200 === 0)) {
                            ops_update_progress($opId, $seenOffers2, $totalOffers, 'rewrite', 'processing offers');
                        }

                        $inOffer = false;
                        $targetOffer = false;
                        $curOfferId = '';
                        $offerDepth = -1;

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

    ops_update_progress($opId, ($totalOffers > 0 ? min($seenOffers2, $totalOffers) : $seenOffers2), ($totalOffers > 0 ? $totalOffers : max(1, $seenOffers2)), 'write', 'saving report');

    // -------- REPORT --------
    $statusCounts = ['ok' => 0, 'undetermined' => 0, 'no_picture' => 0, 'error' => 0, 'from_text' => 0];
    foreach ($colorMap as $row) {
        $st = (string)($row['status'] ?? '');
        if (!isset($statusCounts[$st])) $statusCounts[$st] = 0;
        $statusCounts[$st]++;
    }

    $report = [
        'summary' => [
            'offers_total' => (int)($ds['offers_count'] ?? 0),
            'offers_seen' => $seenOffers,
            'candidates_need_redetect' => count($candidates),
            'gpt_status_counts' => $statusCounts,
            'removed_old_color_params' => $removedOldColorParams,
            'inserted_color_params' => $insertedColorParams,
            'offers_with_inserted_colors' => $offersInsertedNonEmpty,
            'scope' => $applyAll ? 'all' : 'selected',
            'selected_requested' => count($offerIds),
            'gpt_tokens_input' => $tokensInTotal,
            'gpt_tokens_cached_input' => $tokensCachedTotal,
            'gpt_tokens_output' => $tokensOutTotal,
            'gpt_cost_usd' => round($costUsdTotal, 6),
        ],
        'params_effective' => [
            'model' => $model,
            'max_items' => (string)$maxItems,
            'inplace' => $inplace ? '1' : '0',
            'auto_dataset' => $autoDataset ? '1' : '0',
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

    // derived dataset (если inplace=0 и auto_dataset=1) — опционально, на будущее
    $derived = ['auto_dataset' => $autoDataset ? '1' : '0'];
    if ($inplace) {
        file_put_contents($outDir . '/derived_dataset.json', json_encode(['inplace' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } else {
        file_put_contents($outDir . '/derived_dataset.json', json_encode($derived, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $summaryInline = [
        'title' => 'gpt_fill_color_vision',
        'items' => [
            'Candidates (need redetect): ' . count($candidates),
            'Inserted color params: ' . $insertedColorParams,
            'Offers with inserted colors: ' . $offersInsertedNonEmpty,
            'Removed existing color params: ' . $removedOldColorParams,
            'GPT tokens in: ' . $tokensInTotal,
            'GPT tokens cached in: ' . $tokensCachedTotal,
            'GPT tokens out: ' . $tokensOutTotal,
            'GPT cost USD: ' . round($costUsdTotal, 6),
        ],
        'metrics' => [
            'candidates_need_redetect' => count($candidates),
            'inserted_color_params' => $insertedColorParams,
            'offers_with_inserted_colors' => $offersInsertedNonEmpty,
            'removed_old_color_params' => $removedOldColorParams,
            'gpt_status_counts' => $statusCounts,
            'inplace' => $inplace ? 1 : 0,
            'updated_dataset_id' => $inplace ? (int)$ds['id'] : null,
            'gpt_tokens_input' => $tokensInTotal,
            'gpt_tokens_cached_input' => $tokensCachedTotal,
            'gpt_tokens_output' => $tokensOutTotal,
            'gpt_cost_usd' => round($costUsdTotal, 6),
        ],
    ];

    return [
        'result_xml' => rel_to_outputs($cfg, $outXmlAbs),
        'report_json' => rel_to_outputs($cfg, $reportAbs),
        'summary_json_inline' => $summaryInline,
    ];
}
