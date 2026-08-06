<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../xml_scan.php';
require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';
require_once __DIR__ . '/../llm/OpenAIPricing.php';
require_once __DIR__ . '/../llm/PromptTemplates.php';
require_once __DIR__ . '/../taxonomy/GlobalAttributeExclusions.php';
require_once __DIR__ . '/../marketplace_brand_dictionary.php';
require_once __DIR__ . '/../wildberries/WildberriesClient.php';
require_once __DIR__ . '/../wildberries/WildberriesDictionaries.php';

/**
 * gpt_fill_offer_params
 * - scope: selected offers from dataset page (params['offer_ids']) or all if none selected
 * - for each offer: read ozon_category and/or wb_category
 * - load taxonomy meta for each present marketplace category
 * - send a SINGLE GPT request with marketplace_contexts + merged shared category_attributes
 * - receive JSON { params: [{name,value}, ...] }
 * - write into XML as <param name="...">value</param> (do not overwrite non-empty existing)
 * - default model: gpt-5.2 via op_registry, but can override via params['model']
 * - IMPORTANT: no temperature, no max_output_tokens
 */
function op_gpt_fill_offer_params(array $cfg, array $ds, int $opId, array $params, callable $log): array
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

    $log("gpt_fill_offer_params: model={$model}, max_items={$maxItems}, inplace=" . ($inplace ? '1' : '0') . "\n");
    $log("scope: " . ($applyAll ? "ALL offers\n" : ("selected=" . count($offerIds) . "\n")));

    $prompt = PromptTemplates::load(__DIR__ . '/../llm/prompts', 'fill_offer_params_ru.txt');
    $client = LLM::client($cfg, $model);
    $log("pricing: " . openai_pricing_debug_string(openai_pricing_for_model($cfg, $model)) . "\n");
    $wbClient = new WildberriesClient($cfg['wildberries'] ?? []);

    // ---- helpers ----

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

    // нормализация имён атрибутов/параметров как в taxonomy/edit.php (стабильное сравнение)
    $normName = static function (string $s): string {
        $s = trim($s);
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s); // NBSP / ZWSP
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
        $s = preg_replace('~\s+~u', ' ', $s);
        return trim((string)$s);
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


    $limitValue = static function (string $v, int $maxLen = 600): string {
        $v = trim((string)preg_replace('/\s+/u', ' ', $v));
        if ($v === '') return '';
        if ($maxLen > 0 && mb_strlen($v, 'UTF-8') > $maxLen) {
            $v = mb_substr($v, 0, $maxLen, 'UTF-8') . '…';
        }
        return $v;
    };

    $limitWbValue = static function (string $v, int $maxLen = 100): string {
        $v = trim((string)preg_replace('/\s+/u', ' ', $v));
        if ($v === '' || $maxLen <= 0 || mb_strlen($v, 'UTF-8') <= $maxLen) {
            return $v;
        }
        $cut = mb_substr($v, 0, $maxLen, 'UTF-8');
        $wordCut = preg_replace('/\s+\S*$/u', '', $cut);
        if (is_string($wordCut) && mb_strlen($wordCut, 'UTF-8') >= 50) {
            $cut = $wordCut;
        }
        $cut = rtrim($cut, " \t\n\r\0\x0B,;:./\\|-");
        return $cut !== '' ? $cut : mb_substr($v, 0, $maxLen, 'UTF-8');
    };

    $normalizeVendorCandidate = static function (string $value, string $offerId = '', string $vendorCode = '') use ($trimText, $normName): string {
        $value = $trimText($value);
        $value = trim($value, " \t\n\r\0\x0B\"'`«»");
        $value = preg_replace('/\s+/u', ' ', (string)$value);
        $value = trim((string)$value);
        if ($value === '') return '';

        $normalized = $normName($value);
        if ($normalized === '') return '';

        $forbidden = [];
        foreach ([$offerId, $vendorCode] as $token) {
            $tk = $normName((string)$token);
            if ($tk !== '') $forbidden[$tk] = true;
        }
        if (isset($forbidden[$normalized])) return '';

        if (preg_match('~https?://|www\.~iu', $value)) return '';

        if (mb_strlen($value, 'UTF-8') > 120) {
            $value = mb_substr($value, 0, 120, 'UTF-8');
            $value = trim((string)preg_replace('/\s+\S*$/u', '', $value));
        }

        return trim($value);
    };

    $attributeAliasGroups = [
        'country_of_origin' => ['страна производства', 'страна-изготовитель', 'страна изготовитель', 'страна происхождения'],
        'unit_quantity' => ['количество штук в упаковке', 'количество в упаковке шт', 'количество_в_единице_товара', 'количество товара в уеи', 'единиц в одном товаре'],
        'color' => ['цвет', 'цвет товара', 'название цвета', 'основной цвет'],
        'material' => ['материал', 'основной материал', 'материал изделия', 'материал корпуса'],
        'height' => ['высота предмета', 'высота товара', 'высота'],
        'width' => ['ширина предмета', 'ширина товара', 'ширина'],
        'depth' => ['глубина предмета', 'длина предмета', 'длина товара', 'глубина', 'длина'],
        'battery_capacity' => ['емкость аккумулятора', 'емкость акб', 'емкость батареи'],
        'voltage' => ['напряжение', 'рабочее напряжение'],
        'connector_type' => ['тип разъема', 'разъем', 'интерфейс подключения'],
        'compatibility' => ['совместимость', 'подходит для', 'назначение'],
        'compatible_models' => ['совместимые модели', 'модели совместимости', 'совместимость с моделями', 'подходит для моделей'],
        'model_name' => ['модель', 'название модели', 'модель товара'],
    ];

    $attributeAliasNormMap = [];
    foreach ($attributeAliasGroups as $canon => $names) {
        foreach ($names as $name) {
            $nk = $normName((string)$name);
            if ($nk !== '') $attributeAliasNormMap[$nk] = $canon;
        }
    }

    $attributeCanonical = static function (string $name) use ($normName, $attributeAliasNormMap): string {
        $nk = $normName($name);
        if ($nk === '') return '';
        return $attributeAliasNormMap[$nk] ?? $nk;
    };

    $isCountryAttribute = static function (string $name) use ($attributeCanonical): bool {
        return $attributeCanonical($name) === 'country_of_origin';
    };

    $isTnvedAttribute = static function (string $name) use ($normName): bool {
        return in_array($normName($name), [
            'тн вэд',
            'тнвэд',
            'тн вэд коды еаэс',
            'код тн вэд',
            'коды тн вэд',
            'tn ved',
            'tnved',
            'tnved code',
        ], true);
    };

    $OZON_FITS_FOR_NORMS = array_fill_keys([$normName('Подходит для'), $normName('Для чего подходит')], true);
    $isOzonFitsForAttribute = static function (string $marketplace, string $name) use ($normName, $OZON_FITS_FOR_NORMS): bool {
        return $marketplace === 'ozon' && isset($OZON_FITS_FOR_NORMS[$normName($name)]);
    };
    $isOzonCompatibleModelsAttribute = static function (string $marketplace, string $name) use ($normName): bool {
        return $marketplace === 'ozon' && in_array($normName($name), [
            'совместимые модели',
            'совместимые модели устройства',
            'совместимые модели товара',
            'модели совместимости',
            'модель совместимости',
            'совместимость с моделями',
            'подходит для моделей',
        ], true);
    };
    $isSingleValueColorAttribute = static function (string $marketplace, string $name) use ($normName): bool {
        return $marketplace === 'ozon' && in_array($normName($name), [
            'название цвета',
            'название цвета товара',
        ], true);
    };
    $isWarrantyAttribute = static function (string $name) use ($normName): bool {
        return in_array($normName($name), [
            'гарантия',
            'гарантийный срок',
            'срок гарантии',
        ], true);
    };
    $firstAttributeValue = static function (array $values) use ($trimText): ?string {
        foreach ($values as $value) {
            foreach (preg_split('~\s*(?:/|;|\||,|\R|\s+\+\s+|\s+и\s+)\s*~iu', (string)$value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $part = $trimText((string)$part);
                if ($part !== '') {
                    return $part;
                }
            }
        }
        return null;
    };

    $productBrandForCandidate = static function (array $cand, string $vendorFill = '') use ($normalizeVendorCandidate): string {
        $product = is_array($cand['product'] ?? null) ? (array)$cand['product'] : [];
        foreach ([
            (string)($product['brand'] ?? ''),
            (string)($product['vendor'] ?? ''),
            $vendorFill,
        ] as $brand) {
            $brand = $normalizeVendorCandidate($brand, (string)($cand['offer_id'] ?? ''), (string)($product['vendorCode'] ?? ''));
            if ($brand !== '') {
                return $brand;
            }
        }
        return '';
    };

    $compatBrandAliases = [
        'apple' => 'Apple',
        'iphone' => 'Apple',
        'ipad' => 'Apple',
        'ipod' => 'Apple',
        'macbook' => 'Apple',
        'samsung' => 'Samsung',
        'galaxy' => 'Samsung',
        'xiaomi' => 'Xiaomi',
        'redmi' => 'Xiaomi',
        'poco' => 'Xiaomi',
        'huawei' => 'Huawei',
        'honor' => 'Honor',
        'oppo' => 'OPPO',
        'realme' => 'Realme',
        'oneplus' => 'OnePlus',
        'vivo' => 'Vivo',
        'nokia' => 'Nokia',
        'sony' => 'Sony',
        'google' => 'Google',
        'pixel' => 'Google',
        'motorola' => 'Motorola',
        'tecno' => 'Tecno',
        'infinix' => 'Infinix',
        'asus' => 'ASUS',
        'lenovo' => 'Lenovo',
        'zte' => 'ZTE',
        'dji' => 'DJI',
        'air unit' => 'DJI',
        'o3 air unit' => 'DJI',
        'o4 air unit' => 'DJI',
        'gopro' => 'GoPro',
        'go pro' => 'GoPro',
        'insta360' => 'Insta360',
        'geprc' => 'GEPRC',
        'iflight' => 'iFlight',
        'flywoo' => 'Flywoo',
        'freewell' => 'FreeWell',
        'betafpv' => 'BetaFPV',
        'emax' => 'EMAX',
        'happymodel' => 'Happymodel',
        'radiomaster' => 'RadioMaster',
        'frsky' => 'FrSky',
        'futaba' => 'Futaba',
        'radiolink' => 'Radiolink',
        'caddx' => 'Caddx',
        'walksnail' => 'Walksnail',
        'speedybee' => 'SpeedyBee',
        'foxeer' => 'Foxeer',
        'rushfpv' => 'RushFPV',
        'axisflying' => 'AxisFlying',
        'tmotor' => 'T-Motor',
        't motor' => 'T-Motor',
        'brotherhobby' => 'BrotherHobby',
        'hqprop' => 'HQProp',
        'gemfan' => 'Gemfan',
        'tattu' => 'Tattu',
        'gnb' => 'GNB',
        'cnhl' => 'CNHL',
        'toolkitrc' => 'ToolkitRC',
        'hota' => 'HOTA',
        'isdt' => 'ISDT',
        'skyrc' => 'SkyRC',
    ];

    $ozonBrandNamesForCategoryCache = [];
    $ozonBrandNamesForCategory = static function (string $ozonCategory) use (&$ozonBrandNamesForCategoryCache): array {
        $ozonCategory = trim($ozonCategory);
        if ($ozonCategory === '') {
            return [];
        }
        if (isset($ozonBrandNamesForCategoryCache[$ozonCategory])) {
            return $ozonBrandNamesForCategoryCache[$ozonCategory];
        }
        if (!preg_match('~^(\d+)_(\d+)$~', $ozonCategory, $m)) {
            return $ozonBrandNamesForCategoryCache[$ozonCategory] = [];
        }

        try {
            marketplace_brand_dictionary_tables_ensure();
            $st = db()->prepare("
                SELECT DISTINCT b.brand_name
                FROM feedtools_ozon_brands b
                JOIN feedtools_ozon_brand_categories c ON c.brand_id = b.brand_id
                WHERE c.description_category_id = ?
                  AND c.type_id IN (0, ?)
                ORDER BY CHAR_LENGTH(b.brand_name) DESC, b.brand_name ASC
                LIMIT 5000
            ");
            $st->execute([(int)$m[1], (int)$m[2]]);
            $names = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $name = trim((string)($row['brand_name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            return $ozonBrandNamesForCategoryCache[$ozonCategory] = $names;
        } catch (Throwable $e) {
            return $ozonBrandNamesForCategoryCache[$ozonCategory] = [];
        }
    };

    $normalizeOzonFitsForBrands = static function (array $rawValues, array $cand, string $vendorFill = '')
    use ($normName, $trimText, $compatBrandAliases, $ozonBrandNamesForCategory, $productBrandForCandidate): array {
        $fallbackBrand = $productBrandForCandidate($cand, $vendorFill);
        $brandMap = [];
        foreach ($ozonBrandNamesForCategory((string)($cand['ozon_category'] ?? '')) as $brand) {
            $brand = $trimText((string)$brand);
            $bn = $normName($brand);
            if ($brand !== '' && $bn !== '') {
                $brandMap[$bn] = $brand;
            }
        }
        foreach ($compatBrandAliases as $alias => $brand) {
            $aliasNorm = $normName((string)$alias);
            if ($aliasNorm !== '') {
                $brandMap[$aliasNorm] = (string)$brand;
            }
            $brandNorm = $normName((string)$brand);
            if ($brandNorm !== '') {
                $brandMap[$brandNorm] = (string)$brand;
            }
        }
        if ($fallbackBrand !== '') {
            $fallbackNorm = $normName($fallbackBrand);
            if ($fallbackNorm !== '') {
                $brandMap[$fallbackNorm] = $fallbackBrand;
            }
        }

        $skipBrandNorms = array_fill_keys([
            'air', 'unit', 'pro', 'mini', 'max', 'plus', 'fpv', 'hd', 'uhd', 'go', 'mi',
            'для', 'товар', 'дрон', 'квадрокоптер', 'аккумулятор', 'контроллер',
        ], true);

        uksort($brandMap, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        $out = [];
        $seen = [];
        foreach ($rawValues as $raw) {
            $raw = $trimText((string)$raw);
            if ($raw === '') {
                continue;
            }
            $raw = preg_replace('~^\s*(?:подходит\s+для|совместим(?:о|а|ый|ые)?\s+(?:с|для)?|для)\s*[:\-–—]?\s*~iu', '', (string)$raw);
            $rawNorm = $normName($raw);
            if ($rawNorm === '') {
                continue;
            }
            $normHaystack = ' ' . $rawNorm . ' ';

            foreach ($brandMap as $brandNorm => $brandName) {
                if ($brandNorm === '' || isset($skipBrandNorms[$brandNorm])) {
                    continue;
                }
                $brandWords = preg_split('/\s+/u', $brandNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $shortBrand = mb_strlen($brandNorm, 'UTF-8') < 3;
                if ($shortBrand && $rawNorm !== $brandNorm) {
                    continue;
                }
                if (!$shortBrand && !preg_match('~(^| )' . preg_quote($brandNorm, '~') . '($| )~u', $normHaystack)) {
                    continue;
                }
                if (count($brandWords) > 1 && !preg_match('~(^| )' . preg_quote($brandNorm, '~') . '($| )~u', $normHaystack)) {
                    continue;
                }
                $key = $normName((string)$brandName);
                if ($key !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = (string)$brandName;
                }
                if (count($out) >= 6) {
                    break 2;
                }
            }
        }

        if (!$out && $fallbackBrand !== '') {
            $out[] = $fallbackBrand;
        }
        return $out;
    };

    $valueAllowedForAttr = static function (string $value, array $attr) use ($normName, $isSingleValueColorAttribute, $firstAttributeValue): ?string {
        $value = trim($value);
        if ($value === '') return null;
        if ($isSingleValueColorAttribute((string)($attr['source'] ?? 'ozon'), (string)($attr['name'] ?? ''))) {
            $first = $firstAttributeValue([$value]);
            if ($first !== null) {
                $value = $first;
            }
        }

        $allowed = $attr['allowed_values'] ?? [];
        if (!is_array($allowed) || !$allowed) {
            return $value;
        }

        $vk = $normName($value);
        foreach ($allowed as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            if ($normName($candidate) === $vk) {
                return $candidate;
            }
        }

        return null;
    };

    $buildAttrLookupBySource = static function (array $marketplaceContexts) use ($normName): array {
        $lookup = ['ozon' => [], 'wildberries' => []];
        foreach ($marketplaceContexts as $ctx) {
            if (!is_array($ctx)) continue;
            $source = (string)($ctx['source'] ?? '');
            if (!isset($lookup[$source])) continue;

            $attrs = $ctx['category_attributes'] ?? [];
            if (!is_array($attrs)) continue;

            foreach ($attrs as $attr) {
                if (!is_array($attr)) continue;
                $name = trim((string)($attr['name'] ?? ''));
                if ($name === '') continue;
                $nk = $normName($name);
                if ($nk === '' || isset($lookup[$source][$nk])) continue;
                $lookup[$source][$nk] = $attr;
            }
        }
        return $lookup;
    };

    $applyCrossMarketplaceFallbacks = static function (
        array &$filledParamsByMarketplace,
        array $cand,
        array $attrLookupBySource
    ) use ($normName, $attributeCanonical, $valueAllowedForAttr): void {
        $valuesBySourceCanon = ['ozon' => [], 'wildberries' => []];

        $collect = static function (string $source, array $params) use (&$valuesBySourceCanon, $attributeCanonical, $normName): void {
            if (!isset($valuesBySourceCanon[$source])) return;
            foreach ($params as $p) {
                if (!is_array($p)) continue;
                $name = trim((string)($p['name'] ?? ''));
                $value = trim((string)($p['value'] ?? ''));
                if ($name === '' || $value === '') continue;
                $canon = $attributeCanonical($name);
                if ($canon === '') continue;
                $vk = $normName($value);
                if ($vk === '') continue;
                if (!isset($valuesBySourceCanon[$source][$canon])) {
                    $valuesBySourceCanon[$source][$canon] = [];
                }
                if (!isset($valuesBySourceCanon[$source][$canon][$vk])) {
                    $valuesBySourceCanon[$source][$canon][$vk] = $value;
                }
            }
        };

        foreach (($cand['existing_params'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $source = (string)($p['marketplace'] ?? '');
            $collect($source, [$p]);
        }
        foreach (['ozon', 'wildberries'] as $source) {
            $collect($source, $filledParamsByMarketplace[$source] ?? []);
        }

        foreach (['ozon', 'wildberries'] as $targetSource) {
            $otherSource = $targetSource === 'ozon' ? 'wildberries' : 'ozon';
            $targetAttrs = $attrLookupBySource[$targetSource] ?? [];
            if (!$targetAttrs || empty($valuesBySourceCanon[$otherSource])) continue;

            foreach ($targetAttrs as $targetNorm => $targetAttr) {
                if (!is_array($targetAttr)) continue;
                if (!empty($cand['existing_nonempty_norm_by_marketplace'][$targetSource][$targetNorm])) continue;

                $alreadyFilled = false;
                foreach (($filledParamsByMarketplace[$targetSource] ?? []) as $p) {
                    if ($normName((string)($p['name'] ?? '')) === $targetNorm) {
                        $alreadyFilled = true;
                        break;
                    }
                }
                if ($alreadyFilled) continue;

                $targetName = trim((string)($targetAttr['name'] ?? ''));
                $canon = $attributeCanonical($targetName);
                if ($canon === '' || empty($valuesBySourceCanon[$otherSource][$canon])) continue;

                foreach ($valuesBySourceCanon[$otherSource][$canon] as $sourceValue) {
                    $targetValue = $valueAllowedForAttr((string)$sourceValue, $targetAttr);
                    if ($targetValue === null || $targetValue === '') continue;
                    $filledParamsByMarketplace[$targetSource][] = ['name' => $targetName, 'value' => $targetValue];
                    break;
                }
            }
        }
    };


    $UNIT_QTY_PARAM_NAME = 'количество_в_единице_товара';

    $extractUnitQtyFromText = static function (string $text) use ($trimText): ?int {

        $s = $trimText($text);
        if ($s === '') return null;

        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace('ё', 'е', $s);

        // 1) "2шт", "2 шт", "2pcs", "2 pcs", "2pc", "2 pieces", "2 pair/пара"
        // Unicode-safe boundaries (НЕ \b)
        // ВАЖНО: в PHP модификатор Unicode 'u' нельзя писать inline как (?u). Он должен быть в конце шаблона: ~...~iu.
        if (preg_match('~(^|[^\pL\pN])(\d{1,3})\s*(шт\.?|штук|pcs|pc|pieces?|pair|пара)(?=$|[^\pL])~iu', $s, $m)) {
            $n = (int)$m[2];
            if ($n >= 1 && $n <= 999) return $n;
        }

        // 2) "набор 3", "набор из 3", "комплект 2", "упаковка 10", "пачка 5", "pack of 4", "set of 6"
        if (preg_match('~\b(?:набор|набор\s+из|комплект|комплект\s+из|упаковк\pL*|пачк\pL*|pack\s+of|set\s+of)\s*(\d{1,3})(?=$|[^\pL\pN])~iu', $s, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 999) return $n;
        }

        // 3) "2x", "2х", "x2", "х2" (безопасно: только если рядом есть маркеры набора/шт)
      // 3) "2x", "2х", "x2", "х2"
// ВАЖНО: не путать с размерами "175,4 х 18 х 18 мм" и дробями.
// Берём только если маркер набора/шт находится рядом (в окне вокруг матча), а не где угодно в тексте.
$packMarkerRe = '~\b(шт\.?|штук|pcs|pc|pieces?|pack|set|набор|комплект|упаковк\pL*|пачк\pL*|в\s+комплекте)\b~iu';
$sizeUnitsRe  = '~\b(мм|см|м|kg|г|mm|cm|m)\b~iu';

// 3a) "2x ..." / "2х ..." (кол-во слева)
if (preg_match('~(^|[^\pL\pN])(\d{1,3})\s*[xх]\s*(?=$|[^\pL\pN])~iu', $s, $m, PREG_OFFSET_CAPTURE)) {
    $n = (int)$m[2][0];
    $pos = (int)$m[0][1];

    // исключаем случаи типа "175,4 х" (цифра после запятой/точки)
    $prev = ($pos > 0) ? mb_substr($s, $pos - 1, 1, 'UTF-8') : '';
    if ($prev !== ',' && $prev !== '.') {

        // окно 40 символов вокруг матча
        $win = mb_substr($s, max(0, $pos - 40), 80, 'UTF-8');

        // если это похоже на размеры (есть ещё "х" и единицы измерения) — игнор
        $looksLikeDims = (preg_match('~[xх].{0,10}[xх]~iu', $win) && preg_match($sizeUnitsRe, $win));

        if (!$looksLikeDims && preg_match($packMarkerRe, $win)) {
            if ($n >= 1 && $n <= 999) return $n;
        }
    }
}

// 3b) "x2" / "х2" (кол-во справа)
if (preg_match('~(^|[^\pL\pN])[xх]\s*(\d{1,3})(?=$|[^\pL\pN])~iu', $s, $m, PREG_OFFSET_CAPTURE)) {
    $n = (int)$m[2][0];
    $pos = (int)$m[0][1];

    $win = mb_substr($s, max(0, $pos - 40), 80, 'UTF-8');
    $looksLikeDims = (preg_match('~[xх].{0,10}[xх]~iu', $win) && preg_match($sizeUnitsRe, $win));

    if (!$looksLikeDims && preg_match($packMarkerRe, $win)) {
        if ($n >= 1 && $n <= 999) return $n;
    }
}


        // 4) частый формат "2-pack", "pack-2"
        if (preg_match('~(^|[^\pL\pN])(\d{1,3})\s*-\s*(pack|pcs|pc|pieces?|шт\.?|штук)(?=$|[^\pL])~iu', $s, $m)) {
            $n = (int)$m[2];
            if ($n >= 1 && $n <= 999) return $n;
        }
        if (preg_match('~(pack)\s*-\s*(\d{1,3})(?=$|[^\pL\pN])~iu', $s, $m)) {
            $n = (int)$m[2];
            if ($n >= 1 && $n <= 999) return $n;
        }

        return null;
    };

    $determineUnitQty = static function (string $name, string $descSnippet, array $paramsArr)
    use ($extractUnitQtyFromText): int {

        // Требование: количество_в_единице_товара определяем ТОЛЬКО по наименованию товара.
        // Описание и характеристики игнорируем (иначе ловим ложные "2 шт" из батареек/режимов/аксессуаров).
        $nFromName = $extractUnitQtyFromText($name);
        if ($nFromName !== null) return $nFromName;

        return 1;
    };



    $excludeNorm = [];
    $wbExcludeNorm = [];
    try {
        $ex = taxonomy_get_global_exclude_attribute_names('ozon', $cfg);
        foreach ($ex as $x) {
            $k = $normName((string)$x);
            if ($k !== '') $excludeNorm[$k] = true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $ex = taxonomy_get_global_exclude_attribute_names('wildberries', $cfg);
        foreach ($ex as $x) {
            $k = $normName((string)$x);
            if ($k !== '') $wbExcludeNorm[$k] = true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    foreach ([
        'тн вэд',
        'тнвэд',
        'тн вэд коды еаэс',
        'код тн вэд',
        'коды тн вэд',
        'tn ved',
        'tnved',
        'tnved code',
    ] as $tnvedName) {
        $k = $normName($tnvedName);
        if ($k !== '') {
            $excludeNorm[$k] = true;
            $wbExcludeNorm[$k] = true;
        }
    }

    // category cache (code => prepared payload)
    $catCache = [];

    $loadCategory = static function (string $code) use (&$catCache, $excludeNorm, $normName, $isCountryAttribute, $isTnvedAttribute, $isOzonFitsForAttribute, $isOzonCompatibleModelsAttribute): ?array {
        if ($code === '') return null;
        if (isset($catCache[$code])) return $catCache[$code];

        // expected "descId_typeId"
        if (!preg_match('~^(\d+)\_(\d+)$~', $code, $m)) {
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
            'ozon_required_attributes_meta' => [],
            'ozon_required_attributes' => [],
        ];

        // attributes meta preferred
        $attrsMeta = $meta['ozon_required_attributes_meta'] ?? [];
        $attrsList = [];

        // build list: [{name,description,id}]
        if (is_array($attrsMeta) && $attrsMeta) {
            $seen = [];
            foreach ($attrsMeta as $k => $a) {
                if (!is_array($a)) continue;
                $name = trim((string)($a['name'] ?? ''));
                if ($name === '') continue;
                if ($isTnvedAttribute($name)) continue;

                $nk = $normName($name);
                if ($nk === '') continue;
                if (isset($excludeNorm[$nk])) continue;
                if (isset($seen[$nk])) continue;
                $seen[$nk] = true;

                $desc = trim((string)($a['description'] ?? ''));
                
                $allowedValues = [];
                $isCountry = $isCountryAttribute($name);
                if (!$isCountry && isset($a['allowed_values']) && is_array($a['allowed_values'])) {
                    // чистим и ограничиваем
                    $allowedValues = array_values(array_filter(array_map('trim', $a['allowed_values']), fn($v)=>$v!==''));
                    $allowedValues = array_slice(array_values(array_unique($allowedValues)), 0, 200);

                    if ($allowedValues) {
                        $desc = 'Выбери строго одно значение из allowed_values. Не придумывай, не используй синонимы. Если не уверен — пропусти.';
                    }
                } elseif ($isCountry) {
                    $desc = trim($desc . ' Используй обычное общеизвестное название страны. Не нужен справочник allowed_values.');
                }
                if ($isOzonFitsForAttribute('ozon', $name)) {
                    $desc = trim($desc . ' В Ozon это первая часть комплексной характеристики совместимости: пиши только чистые названия брендов, без моделей, серий, категорий и пояснений. Если бренд совместимости неясен, используй бренд самого товара.');
                }
                if ($isOzonCompatibleModelsAttribute('ozon', $name)) {
                    $desc = trim($desc . ' В Ozon это вторая часть комплексной характеристики совместимости: пиши только модели/коды моделей без бренда, категории и пояснений. Если моделей несколько, возвращай values.');
                }

                $attrsList[] = [
                    'name' => $name,
                    'description' => $desc,
                    'id' => isset($a['id']) ? (int)$a['id'] : 0,
                    'attribute_complex_id' => (int)($a['attribute_complex_id'] ?? ($a['complex_id'] ?? 0)),
                    'allowed_values' => $allowedValues,
                    'selection_mode' => $allowedValues ? 'choose_one' : ($isCountry ? 'known_country' : 'free'),
                    'value_source' => $allowedValues ? 'ozon_dictionary' : ($isCountry ? 'known_country' : 'free_text'),
                ];
            }
        } else {
            // fallback: only names
            $names = $meta['ozon_required_attributes'] ?? [];
            if (is_array($names)) {
                $seen = [];
                foreach ($names as $nm) {
                    $name = trim((string)$nm);
                    if ($name === '') continue;
                    if ($isTnvedAttribute($name)) continue;
                    $nk = $normName($name);
                    if ($nk === '') continue;
                    if (isset($excludeNorm[$nk])) continue;
                    if (isset($seen[$nk])) continue;
                    $seen[$nk] = true;

                    $attrsList[] = [
                        'name' => $name,
                        'description' => '',
                        'id' => 0,
                    ];
                }
            }
        }

        $payload = [
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'full_path' => (string)($row['full_path'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
            'typical_goods' => (string)($meta['typical_goods'] ?? ''),
            'features' => (string)($meta['features'] ?? ''),
            // опционально, иногда помогает:
            'meta_raw' => $meta,
            'category_attributes' => $attrsList,
        ];

        $catCache[$code] = $payload;
        return $payload;
    };

    $wbCharacteristicsData = static function (WildberriesClient $wb, int $subjectId, array $excludeNormMap = []) use ($normName): array {
        $resp = $wb->getSubjectCharacteristics($subjectId);
        $items = $resp['data'] ?? [];
        if (!is_array($items)) $items = [];

        $lines = [];
        $meta = [];
        $seen = [];

        foreach ($items as $a) {
            if (!is_array($a)) continue;
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') continue;

            $nk = $normName($name);
            if ($nk !== '' && isset($excludeNormMap[$nk])) continue;
            if (isset($seen[$nk])) continue;
            $seen[$nk] = true;

            $row = [
                'name' => $name,
                'id' => (int)($a['charcID'] ?? 0),
                'required' => !empty($a['required']),
                'unit' => trim((string)($a['unitName'] ?? '')),
                'max_count' => (int)($a['maxCount'] ?? 0),
                'popular' => !empty($a['popular']),
                'charc_type' => (int)($a['charcType'] ?? 0),
                'is_variable' => !empty($a['isVariable']),
                'subject_id' => (int)($a['subjectID'] ?? 0),
                'subject_name' => trim((string)($a['subjectName'] ?? '')),
            ];
            $row = wb_dict_enrich_characteristic_meta($wb, $row);

            $meta[$nk] = $row;
            $lines[] = $name;
        }

        return ['lines' => $lines, 'meta' => $meta];
    };

    $buildWbAttrDescription = static function (array $a): string {
        $parts = [];
        if (!empty($a['required'])) $parts[] = 'Обязательный атрибут.';
        if (!empty($a['unit'])) $parts[] = 'Единица: ' . trim((string)$a['unit']) . '.';
        if (!empty($a['max_count'])) $parts[] = 'Максимум значений: ' . (int)$a['max_count'] . '.';
        if (!empty($a['popular'])) $parts[] = 'Популярная характеристика.';
        if (!empty($a['is_variable'])) $parts[] = 'Значение может зависеть от вариации товара.';
        if (!empty($a['charc_type'])) $parts[] = 'Тип WB: ' . (int)$a['charc_type'] . '.';
        return trim(implode(' ', $parts));
    };

    $wbCatCache = [];
    $loadWbCategory = static function (string $subjectId) use (&$wbCatCache, $wbClient, $wbCharacteristicsData, $buildWbAttrDescription, $wbExcludeNorm, $normName, $log, $isCountryAttribute, $isTnvedAttribute): ?array {
        $subjectId = trim($subjectId);
        if ($subjectId === '') return null;
        if (isset($wbCatCache[$subjectId])) return $wbCatCache[$subjectId];
        if (!ctype_digit($subjectId)) {
            $wbCatCache[$subjectId] = null;
            return null;
        }

        $row = db_exec_with_retry(function () use ($subjectId) {
            $st = db()->prepare("
              SELECT * FROM feedtools_taxonomy_categories
              WHERE source = 'wildberries' AND is_leaf = 1 AND external_id = ?
              LIMIT 1
            ");
            $st->execute(['wb:subject:' . $subjectId]);
            $r = $st->fetch();
            return $r ?: null;
        }, 2);

        if (!$row) {
            $wbCatCache[$subjectId] = null;
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
            'wb_required_attributes' => [],
            'wb_characteristics_meta' => [],
            'raw' => [],
        ];

        $attrsMeta = $meta['wb_characteristics_meta'] ?? [];
        if ((!is_array($attrsMeta) || !$attrsMeta) && ctype_digit($subjectId)) {
            try {
                $fetched = $wbCharacteristicsData($wbClient, (int)$subjectId, $wbExcludeNorm);
                $meta['wb_required_attributes'] = $fetched['lines'];
                $meta['wb_characteristics_meta'] = $fetched['meta'];
                $attrsMeta = $fetched['meta'];

                db()->prepare("UPDATE feedtools_taxonomy_categories SET meta_json=? WHERE id=?")->execute([
                    json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (int)$row['id'],
                ]);
                $log("WB taxonomy cache refreshed for subject {$subjectId}\n");
            } catch (Throwable $e) {
                $log("WB taxonomy live fetch failed for subject {$subjectId}: " . $e->getMessage() . "\n");
            }
        }

        $attrsList = [];
        if (is_array($attrsMeta) && $attrsMeta) {
            $seen = [];
            foreach ($attrsMeta as $a) {
                if (!is_array($a)) continue;
                $name = trim((string)($a['name'] ?? ''));
                if ($name === '') continue;
                if ($isTnvedAttribute($name)) continue;

                $nk = $normName($name);
                if ($nk === '' || isset($wbExcludeNorm[$nk]) || isset($seen[$nk])) continue;
                $seen[$nk] = true;

                $isCountry = $isCountryAttribute($name);
                $allowedValues = (!$isCountry && isset($a['allowed_values']) && is_array($a['allowed_values']))
                    ? array_slice(array_values(array_filter(array_map('trim', $a['allowed_values']), fn($v) => $v !== '')), 0, 300)
                    : [];
                $desc = $buildWbAttrDescription($a);
                if ($isCountry) {
                    $desc = trim($desc . ' Используй обычное общеизвестное название страны. Не нужен справочник allowed_values.');
                }

                $attrsList[] = [
                    'name' => $name,
                    'description' => $desc,
                    'id' => isset($a['id']) ? (int)$a['id'] : 0,
                    'allowed_values' => $allowedValues,
                    'selection_mode' => $allowedValues ? 'choose_one' : ($isCountry ? 'known_country' : 'free'),
                    'value_source' => $allowedValues ? (string)($a['value_source'] ?? 'wb_directory') : ($isCountry ? 'known_country' : 'free_text'),
                ];
            }
        } else {
            $names = $meta['wb_required_attributes'] ?? [];
            if (is_array($names)) {
                $seen = [];
                foreach ($names as $nm) {
                    $name = trim((string)$nm);
                    if ($name === '') continue;
                    if ($isTnvedAttribute($name)) continue;
                    $nk = $normName($name);
                    if ($nk === '' || isset($wbExcludeNorm[$nk]) || isset($seen[$nk])) continue;
                    $seen[$nk] = true;
                    $attrsList[] = [
                        'name' => $name,
                        'description' => '',
                        'id' => 0,
                        'allowed_values' => [],
                        'selection_mode' => 'free',
                        'value_source' => 'free_text',
                    ];
                }
            }
        }

        $payload = [
            'code' => $subjectId,
            'name' => (string)($row['name'] ?? ''),
            'full_path' => (string)($row['full_path'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
            'typical_goods' => (string)($meta['typical_goods'] ?? ''),
            'features' => (string)($meta['features'] ?? ''),
            'meta_raw' => $meta,
            'category_attributes' => $attrsList,
        ];

        $wbCatCache[$subjectId] = $payload;
        return $payload;
    };

    $sourceLabel = static function (string $source): string {
        return $source === 'wildberries' ? 'Wildberries' : 'Ozon';
    };

    $mergeCategoryAttributes = static function (array $marketplaceContexts) use ($normName, $sourceLabel, $isCountryAttribute): array {
        $merged = [];
        foreach ($marketplaceContexts as $ctx) {
            if (!is_array($ctx)) continue;
            $source = (string)($ctx['source'] ?? '');
            $attrs = $ctx['category_attributes'] ?? [];
            if (!is_array($attrs)) continue;

            foreach ($attrs as $attr) {
                if (!is_array($attr)) continue;
                $name = trim((string)($attr['name'] ?? ''));
                if ($name === '') continue;
                $nk = $normName($name);
                if ($nk === '') continue;

                if (!isset($merged[$nk])) {
                    $merged[$nk] = [
                        'name' => $name,
                        'marketplaces' => [],
                        'description_parts' => [],
                        'allowed_values_by_marketplace' => [],
                        'id' => isset($attr['id']) ? (int)$attr['id'] : 0,
                    ];
                }

                if ($source !== '' && !in_array($source, $merged[$nk]['marketplaces'], true)) {
                    $merged[$nk]['marketplaces'][] = $source;
                }

                $desc = trim((string)($attr['description'] ?? ''));
                if ($desc !== '' && $source !== '') {
                    $merged[$nk]['description_parts'][$source] = '[' . $sourceLabel($source) . '] ' . $desc;
                }

                $allowed = $attr['allowed_values'] ?? [];
                if (is_array($allowed) && $allowed) {
                    $clean = [];
                    $seenVals = [];
                    foreach ($allowed as $value) {
                        $value = trim((string)$value);
                        if ($value === '') continue;
                        $vk = $normName($value);
                        if ($vk === '' || isset($seenVals[$vk])) continue;
                        $seenVals[$vk] = $value;
                        $clean[] = $value;
                    }
                    if ($clean && $source !== '') {
                        $merged[$nk]['allowed_values_by_marketplace'][$source] = $clean;
                    }
                }
            }
        }

        $out = [];
        foreach ($merged as $nk => $item) {
            $descParts = [];
            if (!empty($item['marketplaces'])) {
                $labels = array_map($sourceLabel, $item['marketplaces']);
                $descParts[] = 'Маркетплейсы: ' . implode(', ', $labels) . '.';
            }
            foreach ($item['description_parts'] as $part) {
                $descParts[] = $part;
            }

            $allowedByMarketplace = $item['allowed_values_by_marketplace'];
            $allowedValues = [];
            $selectionMode = 'free';
            $valueSource = 'free_text';
            $isCountry = $isCountryAttribute((string)($item['name'] ?? ''));

            if ($isCountry) {
                $allowedByMarketplace = [];
                $descParts[] = 'Это характеристика страны. Используй обычное общеизвестное название страны, без перечисления справочника allowed_values.';
                $selectionMode = 'known_country';
                $valueSource = 'known_country';
            }

            if (!$isCountry && $allowedByMarketplace) {
                $maps = [];
                foreach ($allowedByMarketplace as $source => $values) {
                    $map = [];
                    foreach ($values as $value) {
                        $map[$normName($value)] = $value;
                    }
                    $maps[$source] = $map;
                }

                $commonKeys = null;
                $firstRawMap = [];
                foreach ($maps as $source => $map) {
                    if ($commonKeys === null) {
                        $commonKeys = array_keys($map);
                        $firstRawMap = $map;
                    } else {
                        $commonKeys = array_values(array_intersect($commonKeys, array_keys($map)));
                    }
                }

                if (is_array($commonKeys) && $commonKeys) {
                    foreach ($commonKeys as $key) {
                        if (isset($firstRawMap[$key])) $allowedValues[] = $firstRawMap[$key];
                    }
                    $selectionMode = 'choose_one';
                    $valueSource = count($allowedByMarketplace) > 1 ? 'multi_marketplace_dictionary' : 'marketplace_dictionary';
                    if (count($allowedByMarketplace) > 1) {
                        $descParts[] = 'Выбери одно значение из allowed_values, которое подходит всем перечисленным маркетплейсам.';
                    }
                } else {
                    $descParts[] = 'Атрибут встречается в нескольких маркетплейсах с разными ограничениями. Не ищи общее значение: верни отдельные значения в отдельных объектах params для каждого marketplace_context, где атрибут можно заполнить.';
                }
            }

            $out[] = [
                'name' => $item['name'],
                'description' => trim(implode("\n", array_values(array_unique(array_filter($descParts))))),
                'id' => (int)$item['id'],
                'allowed_values' => $allowedValues,
                'selection_mode' => $selectionMode,
                'value_source' => $valueSource,
                'marketplaces' => array_values($item['marketplaces']),
                'allowed_values_by_marketplace' => $allowedByMarketplace,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $out;
    };

    $buildCombinedCategory = static function (array $marketplaceContexts) use ($sourceLabel): array {
        if (count($marketplaceContexts) === 1) {
            $ctx = $marketplaceContexts[0];
            return $ctx['category'] ?? [];
        }

        $fullPaths = [];
        $descriptions = [];
        $typicalGoods = [];
        $features = [];
        $codes = [];
        $names = [];

        foreach ($marketplaceContexts as $ctx) {
            if (!is_array($ctx)) continue;
            $source = (string)($ctx['source'] ?? '');
            $label = $sourceLabel($source);
            $category = $ctx['category'] ?? [];
            if (!is_array($category)) continue;

            $code = trim((string)($category['code'] ?? ''));
            if ($code !== '') $codes[] = $source . ':' . $code;

            $name = trim((string)($category['name'] ?? ''));
            if ($name !== '') $names[] = $label . ': ' . $name;

            $fullPath = trim((string)($category['full_path'] ?? ''));
            if ($fullPath !== '') $fullPaths[] = $label . ': ' . $fullPath;

            $description = trim((string)($category['description'] ?? ''));
            if ($description !== '') $descriptions[] = '[' . $label . "] " . $description;

            $typical = trim((string)($category['typical_goods'] ?? ''));
            if ($typical !== '') $typicalGoods[] = '[' . $label . "] " . $typical;

            $feature = trim((string)($category['features'] ?? ''));
            if ($feature !== '') $features[] = '[' . $label . "] " . $feature;
        }

        return [
            'code' => implode(' | ', $codes),
            'name' => implode(' + ', $names),
            'full_path' => implode("\n", $fullPaths),
            'description' => implode("\n\n", $descriptions),
            'typical_goods' => implode("\n\n", $typicalGoods),
            'features' => implode("\n\n", $features),
        ];
    };

    // ---- PASS 1: scan offers in scope, collect candidates ----
    ops_update_progress($opId, 0, max(1, ($totalOffers > 0 ? $totalOffers : 1)), 'scan', 'scanning offers');

    $reader = new XMLReader();
    if (!$reader->open($inputPath, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Cannot open XML: {$inputPath}");
    }

    $seenOffers = 0;
    $tick = 0;

	    // candidates: offerId => data needed for GPT + for rewrite
	    $candidates = [];
	    $unitQtyWriteByOffer = []; // offerId => ['qty'=>int, 'force'=>bool]
	    $ozonFitsForWriteByOffer = []; // offerId => ['value'=>string, 'force'=>bool]


    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') continue;

        $offerId = (string)$reader->getAttribute('id');
        $offerDepth = $reader->depth;

        if ($reader->isEmptyElement) {
            $seenOffers++;
            continue;
        }

        // fast skip by scope
        $inScope = $applyAll || ($offerId !== '' && isset($offerSet[$offerId]));
        // but still must consume offer subtree regardless
        $name = '';
        $vendorCode = '';
        $brand = '';
        $vendor = '';
        $modelTxt = '';
        $descRaw = '';
        $pictures = [];

        $ozonCategory = '';
        $wbCategory = '';

        $paramsArr = []; // list of {name,value}
        $wbParamsArr = []; // list of {name,value}
        $paramsMapNonEmpty = []; // normName => true if non-empty exists (block overwrite)
        $wbParamsMapNonEmpty = []; // normName => true if non-empty exists in <wb_param>
        $paramsMapEmptyNodes = []; // normName => count (just for stats/debug)

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
                $descRaw = (string)$reader->readInnerXml();
                continue;
            }

            if ($tag === 'picture') {
                $pic = trim((string)$reader->readString());
                if ($pic !== '') $pictures[] = $pic;
                continue;
            }

            if ($tag === 'param') {
                $pname = (string)$reader->getAttribute('name');
                $pval = $trimText($reader->readString());

                $paramsArr[] = ['name' => $pname, 'value' => $pval];

                // ozon_category can also be stored as param name="ozon_category"
                if ($ozonCategory === '' && $pname !== '' && $normName($pname) === 'ozon category') {
                    // осторожно: normName вычищает символы, поэтому сверяем также raw
                    // но на практике встречается exact "ozon_category"
                }
                if ($ozonCategory === '' && $pname !== '' && trim($pname) === 'ozon_category') {
                    $ozonCategory = trim($pval);
                }

                if ($pname !== '') {
                    $nk = $normName($pname);
                    if ($nk !== '') {
                        if ($pval !== '') $paramsMapNonEmpty[$nk] = true;
                        else $paramsMapEmptyNodes[$nk] = ($paramsMapEmptyNodes[$nk] ?? 0) + 1;
                    }
                }
                continue;
            }
            if ($tag === 'wb_param') {
                $pname = (string)$reader->getAttribute('name');
                $pval = $trimText($reader->readString());

                $wbParamsArr[] = ['name' => $pname, 'value' => $pval];

                if ($pname !== '') {
                    $nk = $normName($pname);
                    if ($nk !== '' && $pval !== '') {
                        $wbParamsMapNonEmpty[$nk] = true;
                    }
                }
                continue;
            }

            // other tags: consume cheaply
            if (!$reader->isEmptyElement) $reader->readString();
        }

        $seenOffers++;
        $tick++;
        if ($totalOffers > 0 && ($tick % 250 === 0)) {
            ops_update_progress($opId, $seenOffers, $totalOffers, 'scan', 'scanning offers');
        }

        // --- RULE: количество_в_единице_товара ---
	        if ($inScope && $offerId !== '') {
	            $unitNorm = $normName('количество_в_единице_товара');
	            $multNorm = $normName('Множественность');

            $existingUnit = null;   // int|null
            $multiplicity = null;   // float|null

            foreach ($paramsArr as $p) {
                if (!is_array($p)) continue;
                $pn = (string)($p['name'] ?? '');
                $pv = $trimText((string)($p['value'] ?? ''));
                if ($pn === '') continue;

                $pnNorm = $normName($pn);

                if ($pnNorm === $unitNorm) {
                    if ($pv !== '' && ctype_digit($pv) && (int)$pv > 0) {
                        $existingUnit = (int)$pv;
                    }
                }

                if ($pnNorm === $multNorm) {
                    if ($pv !== '') {
                        $pv2 = str_replace(',', '.', $pv);
                        if (is_numeric($pv2)) $multiplicity = (float)$pv2;
                    }
                }
            }

            $force = false;
            $qty = 1;

            // спец-правило: множественность => количество в единице
            // 0.001 => 1000, 0.01 => 100, 0.1 => 10, 0.0001 => 10000
            if ($multiplicity !== null) {
                $m = (float)$multiplicity;

                $map = [
                    0.1    => 10,
                    0.01   => 100,
                    0.001  => 1000,
                    0.0001 => 10000,
                ];

                $matchedQty = null;
                foreach ($map as $mul => $q) {
                    if (abs($m - $mul) < 0.0000005) { // допуск на float
                        $matchedQty = (int)$q;
                        break;
                    }
                }

                if ($matchedQty !== null) {
                    $qty = $matchedQty;
                    $force = true; // перезаписываем даже если уже было
                } else {
                    if ($existingUnit !== null) {
                        $qty = $existingUnit;
                    } else {
                        $qty = $determineUnitQty($name, $descToSnippet($descRaw, 2500), $paramsArr);
                    }
                }
            } else {
                if ($existingUnit !== null) {
                    $qty = $existingUnit;
                } else {
                    $qty = $determineUnitQty($name, $descToSnippet($descRaw, 2500), $paramsArr);
                }
            }


            // Решаем: надо ли писать в XML
            // - force=true => всегда писать (перезапись или вставка)
            // - иначе писать только если параметра нет/пустой
            $needWrite = $force || ($existingUnit === null);

            if ($needWrite) {
                if ($qty < 1) $qty = 1;
	                $unitQtyWriteByOffer[$offerId] = ['qty' => (int)$qty, 'force' => $force];
	            }
	        }

	        // --- RULE: Ozon "Подходит для" должен содержать только бренд совместимости ---
	        if ($inScope && $offerId !== '') {
	            $fitsForNorm = $normName('Подходит для');
	            $existingFitsFor = null;
	            foreach ($paramsArr as $p) {
	                if (!is_array($p)) continue;
	                $pn = (string)($p['name'] ?? '');
	                if ($pn === '' || $normName($pn) !== $fitsForNorm) continue;
	                $pv = $trimText((string)($p['value'] ?? ''));
	                if ($pv !== '') {
	                    $existingFitsFor = $pv;
	                    break;
	                }
	            }
	            if ($existingFitsFor !== null) {
	                $fitsCand = [
	                    'offer_id' => $offerId,
	                    'ozon_category' => trim($ozonCategory),
	                    'product' => [
	                        'offer_id' => $offerId,
	                        'vendorCode' => $vendorCode,
	                        'name' => $name,
	                        'brand' => $brand,
	                        'vendor' => $vendor,
	                        'model' => $modelTxt,
	                        'description' => $descToSnippet($descRaw, 1200),
	                        'params' => $paramsArr,
	                    ],
	                ];
	                $normalizedFits = $normalizeOzonFitsForBrands([$existingFitsFor], $fitsCand, '');
	                $normalizedFitsValue = $limitValue(implode('; ', $normalizedFits), 600);
	                if ($normalizedFitsValue !== '' && $normName($normalizedFitsValue) !== $normName($existingFitsFor)) {
	                    $ozonFitsForWriteByOffer[$offerId] = ['value' => $normalizedFitsValue, 'force' => true];
	                }
	            }
	        }


        if (!$inScope) continue;
        if ($offerId === '') continue;

        $ozonCategory = trim($ozonCategory);
        $wbCategory = trim($wbCategory);

        $marketplaceContexts = [];
        if ($ozonCategory !== '') {
            $cat = $loadCategory($ozonCategory);
            if ($cat) {
                $catAttrs = $cat['category_attributes'] ?? [];
                if (is_array($catAttrs) && $catAttrs) {
                    $marketplaceContexts[] = [
                        'source' => 'ozon',
                        'category' => [
                            'code' => (string)$cat['code'],
                            'name' => (string)$cat['name'],
                            'full_path' => (string)$cat['full_path'],
                            'description' => (string)$cat['description'],
                            'typical_goods' => (string)$cat['typical_goods'],
                            'features' => (string)$cat['features'],
                        ],
                        'category_attributes' => $catAttrs,
                    ];
                }
            }
        }
        if ($wbCategory !== '') {
            $wbCat = $loadWbCategory($wbCategory);
            if ($wbCat) {
                $catAttrs = $wbCat['category_attributes'] ?? [];
                if (is_array($catAttrs) && $catAttrs) {
                    $marketplaceContexts[] = [
                        'source' => 'wildberries',
                        'category' => [
                            'code' => (string)$wbCat['code'],
                            'name' => (string)$wbCat['name'],
                            'full_path' => (string)$wbCat['full_path'],
                            'description' => (string)$wbCat['description'],
                            'typical_goods' => (string)$wbCat['typical_goods'],
                            'features' => (string)$wbCat['features'],
                        ],
                        'category_attributes' => $catAttrs,
                    ];
                }
            }
        }

        if (!$marketplaceContexts) continue;

        $catAttrs = (count($marketplaceContexts) === 1)
            ? (array)($marketplaceContexts[0]['category_attributes'] ?? [])
            : [];
        $combinedCategory = $buildCombinedCategory($marketplaceContexts);

        // если для этого offer мы собираемся писать unit qty — добавим в existing_params, чтобы GPT не пытался
        if (isset($unitQtyWriteByOffer[$offerId])) {
            $paramsArr[] = ['name' => 'количество_в_единице_товара', 'value' => (string)$unitQtyWriteByOffer[$offerId]['qty']];
            $paramsMapNonEmpty[$normName('количество_в_единице_товара')] = true;
        }

        foreach ($marketplaceContexts as &$ctx) {
            $src = (string)($ctx['source'] ?? '');
            $ctx['existing_params'] = ($src === 'wildberries') ? $wbParamsArr : $paramsArr;
        }
        unset($ctx);

        $existingParamsCombined = [];
        foreach ($paramsArr as $p) {
            if (!is_array($p)) continue;
            $existingParamsCombined[] = [
                'marketplace' => 'ozon',
                'name' => (string)($p['name'] ?? ''),
                'value' => (string)($p['value'] ?? ''),
            ];
        }
        foreach ($wbParamsArr as $p) {
            if (!is_array($p)) continue;
            $existingParamsCombined[] = [
                'marketplace' => 'wildberries',
                'name' => (string)($p['name'] ?? ''),
                'value' => (string)($p['value'] ?? ''),
            ];
        }


        $candidates[$offerId] = [
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
                'description' => $descToSnippet($descRaw, 2500),
                'pictures' => $pictures,
                'params' => $paramsArr,
            ],
            'category' => $combinedCategory,
            'marketplace_contexts' => $marketplaceContexts,
            'category_attributes' => $catAttrs,
            'attribute_aliases' => $attributeAliasGroups,
            'existing_params' => $existingParamsCombined,
            'existing_nonempty_norm_by_marketplace' => [
                'ozon' => $paramsMapNonEmpty,
                'wildberries' => $wbParamsMapNonEmpty,
            ],
        ];

        if ($maxItems > 0 && count($candidates) >= $maxItems) {
            break;
        }
    }

    $reader->close();

    $log("scan: offers_seen={$seenOffers}, candidates=" . count($candidates) . "\n");

    // If there are no candidates, XML must remain unchanged.
    // Do NOT rewrite via XMLWriter (it already caused corruption in no-op runs).
	    if (count($candidates) === 0 && count($unitQtyWriteByOffer) === 0 && count($ozonFitsForWriteByOffer) === 0) {

        $log("No candidates. Writing result as a byte-for-byte copy of input.\n");

        if (!copy($inputPath, $outXmlAbs)) {
            throw new RuntimeException("Cannot copy input XML to result: {$outXmlAbs}");
        }

        // Prepare minimal report
        $report = [
            'summary' => [
                'offers_total' => (int)($ds['offers_count'] ?? 0),
                'offers_seen' => $seenOffers,
                'candidates' => 0,
                'gpt_status_counts' => ['ok' => 0, 'error' => 0],
                'offers_touched' => 0,
                'params_inserted' => 0,
                'vendors_inserted' => 0,
                'offers_with_inserted_params' => 0,
                'scope' => $applyAll ? 'all' : 'selected',
                'selected_requested' => count($offerIds),
                'note' => 'no-op: result is exact copy of input',
                'usage' => [
                    'input_tokens' => 0,
                    'cached_input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost_usd' => 0.0,
                ],

            ],
            'params_effective' => [
                'model' => $model,
                'max_items' => (string)$maxItems,
                'inplace' => $inplace ? '1' : '0',
            ],
        ];
        $reportAbs = $outDir . '/report.json';
        file_put_contents($reportAbs, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // If inplace is requested, do the inplace replace using the copied file
        if ($inplace) {
            // IMPORTANT: validate that output is well-formed before replacing
            $tmpReader = new XMLReader();
            if (!$tmpReader->open($outXmlAbs, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new RuntimeException("Result XML cannot be opened for validation: {$outXmlAbs}");
            }
            while ($tmpReader->read()) { /* just read through */
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

            // update dataset metadata
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
                'title' => 'gpt_fill_offer_params',
                'items' => [
                    'Candidates: 0',
                    'No-op: result is exact copy of input',
                    'Tokens in: 0',
                    'Tokens cached in: 0',
                    'Tokens out: 0',
                    'Cost USD: $0',

                ],
                'metrics' => [
                    'candidates' => 0,
                    'offers_touched' => 0,
                    'params_inserted' => 0,
                    'vendors_inserted' => 0,
                    'inplace' => $inplace ? 1 : 0,
                    'updated_dataset_id' => $inplace ? (int)$ds['id'] : null,
                    'tokens_in' => 0,
                    'tokens_cached_in' => 0,
                    'tokens_out' => 0,
                    'cost_usd' => 0.0,

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

    $gptResults = []; // offerId => ['status','params','error']
    foreach ($candidates as $offerId => $cand) {
        $gptDone++;
        ops_update_progress($opId, $gptDone, $gptTotal, 'gpt', "GPT {$gptDone}/{$gptTotal} offer={$offerId}");

        $status = 'ok';
        $err = '';
        $filledParamsByMarketplace = ['ozon' => [], 'wildberries' => []];
        $vendorFill = '';

        $availableSources = [];
        $attrSourcesByNorm = [];
        foreach (($cand['marketplace_contexts'] ?? []) as $ctx) {
            if (!is_array($ctx)) continue;
            $source = (string)($ctx['source'] ?? '');
            if (!in_array($source, ['ozon', 'wildberries'], true)) continue;
            if (!in_array($source, $availableSources, true)) $availableSources[] = $source;
            $ctxAttrs = $ctx['category_attributes'] ?? [];
            if (!is_array($ctxAttrs)) continue;
            foreach ($ctxAttrs as $attr) {
                if (!is_array($attr)) continue;
                $nm = trim((string)($attr['name'] ?? ''));
                if ($nm === '') continue;
                $nk = $normName($nm);
                if ($nk === '') continue;
                if (!isset($attrSourcesByNorm[$nk])) $attrSourcesByNorm[$nk] = [];
                if (!in_array($source, $attrSourcesByNorm[$nk], true)) {
                    $attrSourcesByNorm[$nk][] = $source;
                }
            }
        }
        $attrLookupBySource = $buildAttrLookupBySource(is_array($cand['marketplace_contexts'] ?? null) ? $cand['marketplace_contexts'] : []);

        $promptMarketplaceContexts = [];
        foreach (($cand['marketplace_contexts'] ?? []) as $ctx) {
            if (!is_array($ctx)) continue;
            $ctxPrompt = $ctx;
            unset($ctxPrompt['existing_params']);
            $promptMarketplaceContexts[] = $ctxPrompt;
        }

        $payload = [
            'category' => $cand['category'],
            'marketplace_contexts' => $promptMarketplaceContexts,
            'category_attributes' => $cand['category_attributes'],
            'attribute_aliases' => $cand['attribute_aliases'] ?? $attributeAliasGroups,
            'product' => $cand['product'],
            'existing_params' => $cand['existing_params'],
        ];

        $inputJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) $inputJson = '{"product":{},"category":{},"category_attributes":[],"existing_params":[]}';

        // важно: слово "json" в input, чтобы формат json_object не падал 400
        $textPart = "Return a json object only.\n\njson:\n" . $inputJson;

        // первое фото товара (если есть)
        $primaryImageUrl = '';
        if (!empty($cand['product']['pictures']) && is_array($cand['product']['pictures'])) {
            $primaryImageUrl = trim((string)($cand['product']['pictures'][0] ?? ''));
        }

        // мультимодальный input: текст + фото (high detail)
        $content = [
            ['type' => 'input_text', 'text' => $textPart],
        ];
        if ($primaryImageUrl !== '') {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $primaryImageUrl,
                'detail' => 'high',
            ];
        }

        $userInput = [
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];


        $rawText = '';
        $raw = null;
        $u = ['input' => 0, 'cached_input' => 0, 'output' => 0];

        try {
            $res = $client->generateText(
                $model,
                $userInput,
                $prompt,
                [
                    'prompt_cache_key' => 'fill_offer:' . substr(hash('sha256', json_encode([
                        'category' => $cand['category'],
                        'marketplace_contexts' => $promptMarketplaceContexts,
                        'category_attributes' => $cand['category_attributes'],
                        'attribute_aliases' => $cand['attribute_aliases'] ?? $attributeAliasGroups,
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
            $u = $extractUsage(is_array($raw) ? $raw : null);

            $tokensInTotal += (int)$u['input'];
            $tokensOutTotal += (int)$u['output'];
            $tokensCachedTotal += (int)$u['cached_input'];

            $costUsdTotal += $calcCostUSD((int)$u['input'], (int)$u['cached_input'], (int)$u['output']);


            $obj = $extractJsonObject($rawText);

            $pp = $obj['params'] ?? [];
            if (!is_array($pp)) $pp = [];

            if (
                trim((string)($cand['product']['brand'] ?? '')) === ''
                && trim((string)($cand['product']['vendor'] ?? '')) === ''
            ) {
                $vendorFill = $normalizeVendorCandidate(
                    (string)($obj['vendor'] ?? ''),
                    (string)($cand['offer_id'] ?? ''),
                    (string)($cand['product']['vendorCode'] ?? '')
                );
            }

            // validate & filter
            foreach ($pp as $p) {
                if (!is_array($p)) continue;
                $marketplace = trim((string)($p['marketplace'] ?? ''));
                $nm = trim((string)($p['name'] ?? ''));
                $rawValues = [];
                if (isset($p['value'])) $rawValues[] = (string)$p['value'];
                if (isset($p['values']) && is_array($p['values'])) {
                    foreach ($p['values'] as $vv) $rawValues[] = (string)$vv;
                }
                $cleanValues = [];
                $seenValues = [];
                foreach ($rawValues as $vv) {
                    $vv = $limitValue((string)$vv, 600);
                    if ($vv === '') continue;
                    $vk = $normName($vv);
                    if ($vk !== '' && isset($seenValues[$vk])) continue;
                    if ($vk !== '') $seenValues[$vk] = true;
                    $cleanValues[] = $vv;
                }
                $val = implode('; ', $cleanValues);
                $val = $limitValue($val, 600);

                if ($nm === '' || $val === '') continue;

                $marketplace = str_replace(['wb', 'wildberries'], 'wildberries', strtolower($marketplace));
                $marketplace = str_replace(['ozon'], 'ozon', $marketplace);

                $nk = $normName($nm);
                if ($nk === '') continue;
                if ($isTnvedAttribute($nm)) continue;
                if ($isWarrantyAttribute($nm)) continue;

                if ($marketplace === '') {
                    if (count($availableSources) === 1) {
                        $marketplace = $availableSources[0];
                    } else {
                        $sources = $attrSourcesByNorm[$nk] ?? [];
                        if (count($sources) === 1) {
                            $marketplace = $sources[0];
                        }
                    }
                }

                if (!in_array($marketplace, ['ozon', 'wildberries'], true)) {
                    continue;
                }
                if (!in_array($marketplace, $availableSources, true)) {
                    continue;
                }
                if ($marketplace === 'wildberries') {
                    $cleanValues = array_values(array_filter(array_map(
                        static fn(string $part): string => $limitWbValue($part),
                        $cleanValues
                    ), static fn(string $part): bool => $part !== ''));
                    $val = $limitWbValue(implode('; ', $cleanValues));
                    if ($val === '') {
                        continue;
                    }
                }

		                if (!empty($cand['existing_nonempty_norm_by_marketplace'][$marketplace][$nk])) {
		                    continue;
		                }

	                if ($isOzonFitsForAttribute($marketplace, $nm)) {
	                    $cleanValues = $normalizeOzonFitsForBrands($rawValues, $cand, $vendorFill);
	                    $val = $limitValue(implode('; ', $cleanValues), 600);
	                    if ($val === '') {
	                        continue;
	                    }
	                } elseif ($isOzonCompatibleModelsAttribute($marketplace, $nm)) {
	                    $compatBrands = $normalizeOzonFitsForBrands([], $cand, $vendorFill);
	                    $modelValues = [];
	                    $modelSeen = [];
	                    foreach ($rawValues as $rawModel) {
	                        foreach (preg_split('~\s*(?:;|\||\R)\s*~u', (string)$rawModel, -1, PREG_SPLIT_NO_EMPTY) ?: [(string)$rawModel] as $part) {
	                            $model = $trimText((string)$part);
	                            $model = preg_replace('~^(?:для|подходит\s+для|совместим(?:о|а|ый|ые)?\s+(?:с|для)?|модели?|совместимые\s+модели)\s*[:=\-]?\s*~iu', '', (string)$model) ?? $model;
	                            foreach ($compatBrands as $brandForModel) {
	                                $brandForModel = $trimText((string)$brandForModel);
	                                if ($brandForModel !== '') {
	                                    $model = preg_replace('~^\s*' . preg_quote($brandForModel, '~') . '\s+~iu', '', (string)$model) ?? $model;
	                                }
	                            }
	                            $model = $limitValue(trim((string)$model, " \t\n\r\0\x0B.,;:-/"), 120);
	                            $mk = $normName($model);
	                            if ($model === '' || $mk === '' || isset($modelSeen[$mk])) {
	                                continue;
	                            }
	                            $modelSeen[$mk] = true;
	                            $modelValues[] = $model;
	                        }
	                    }
	                    $val = $limitValue(implode('; ', $modelValues), 600);
	                    if ($val === '') {
	                        continue;
	                    }
	                }

	                $filledParamsByMarketplace[$marketplace][] = ['name' => $nm, 'value' => $val];
	            }

	            $applyCrossMarketplaceFallbacks($filledParamsByMarketplace, $cand, $attrLookupBySource);

	            $fitsForNorm = $normName('Подходит для');
	            foreach ($filledParamsByMarketplace['ozon'] as $idx => $p) {
	                if ($normName((string)($p['name'] ?? '')) !== $fitsForNorm) {
	                    continue;
	                }
	                $fitBrands = $normalizeOzonFitsForBrands([(string)($p['value'] ?? '')], $cand, $vendorFill);
	                if (!$fitBrands) {
	                    unset($filledParamsByMarketplace['ozon'][$idx]);
	                    continue;
	                }
	                $filledParamsByMarketplace['ozon'][$idx]['value'] = $limitValue(implode('; ', $fitBrands), 600);
	            }
	            $filledParamsByMarketplace['ozon'] = array_values($filledParamsByMarketplace['ozon']);

	            $ozonFitAttr = $attrLookupBySource['ozon'][$fitsForNorm] ?? null;
	            if (is_array($ozonFitAttr)
	                && empty($cand['existing_nonempty_norm_by_marketplace']['ozon'][$fitsForNorm])
	            ) {
	                $alreadyFitsFor = false;
	                foreach ($filledParamsByMarketplace['ozon'] as $p) {
	                    if ($normName((string)($p['name'] ?? '')) === $fitsForNorm) {
	                        $alreadyFitsFor = true;
	                        break;
	                    }
	                }
	                if (!$alreadyFitsFor) {
	                    $fallbackFitsFor = $normalizeOzonFitsForBrands([], $cand, $vendorFill);
	                    if ($fallbackFitsFor) {
	                        $filledParamsByMarketplace['ozon'][] = [
	                            'name' => trim((string)($ozonFitAttr['name'] ?? 'Подходит для')) ?: 'Подходит для',
	                            'value' => $limitValue(implode('; ', $fallbackFitsFor), 600),
	                        ];
	                    }
	                }
	            }

	            foreach (['ozon', 'wildberries'] as $source) {
                $seen = [];
                $uniq = [];
                foreach ($filledParamsByMarketplace[$source] as $p) {
                    $nk = $normName((string)$p['name']);
                    if ($nk === '' || isset($seen[$nk])) continue;
                    $seen[$nk] = true;
                    $uniq[] = $p;
                }
                $filledParamsByMarketplace[$source] = $uniq;
            }
        } catch (Throwable $e) {
            $status = 'error';
            $err = $e->getMessage();
        }

        $gptResults[$offerId] = [
            'status' => $status,
            'error' => $err,
            'params_by_marketplace' => $filledParamsByMarketplace,
            'vendor_fill' => $vendorFill,
        ];

        // debug per-offer dump
        $dbg = [
            'offer_id' => $offerId,
            'ozon_category' => $cand['ozon_category'],
            'wb_category' => $cand['wb_category'],
            'status' => $status,
            'error' => $err,
            'payload' => $payload,
            'gpt_output_text' => $rawText,
            'final_params_by_marketplace' => $filledParamsByMarketplace,
            'vendor_fill' => $vendorFill,
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


    // ---- PASS 3: rewrite XML with inserted params (streaming, like set_ozon_category.php) ----
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

    $inParam = false;
    $paramDepth = -1;
    $curParamNorm = '';
    $curParamSource = 'ozon';
    $inVendorTag = false;
    $vendorDepth = -1;
    $vendorBuffer = '';
    $skipVendorOriginal = false;
    $vendorTagSeen = false;
    $vendorTagWritten = false;
    $vendorToInsert = '';

    $existingNonEmptyBySource = ['ozon' => [], 'wildberries' => []];
    $addBySource = ['ozon' => [], 'wildberries' => []];

	    $unitQtyNorm = $normName('количество_в_единице_товара');
	    $overrideUnitQty = null;     // string|null
	    $overrideUnitQtyForce = false;
	    $overrideUnitQtyWritten = false;

	    $ozonFitsForNorm = $normName('Подходит для');
	    $overrideOzonFitsFor = null; // string|null
	    $overrideOzonFitsForForce = false;
	    $overrideOzonFitsForWritten = false;


    $seenOffers2 = 0;
    $offersTouched = 0;
    $paramsInserted = 0;
    $offersWithInsert = 0;
    $vendorsInserted = 0;
    $progressTick2 = 0;

    $buildAddMap = static function (array $toAdd, string $source) use ($normName, $limitValue, $limitWbValue): array {
        $m = [];
        foreach ($toAdd as $p) {
            if (!is_array($p)) continue;
            $nm = trim((string)($p['name'] ?? ''));
            $val = $limitValue((string)($p['value'] ?? ''), 600);
            if ($source === 'wildberries') {
                $val = $limitWbValue($val);
            }
            if ($nm === '' || $val === '') continue;
            $nk = $normName($nm);
            if ($nk === '' || isset($m[$nk])) continue;
            $m[$nk] = ['name' => $nm, 'value' => $val];
        }
        return $m;
    };

    while ($reader->read()) {
        switch ($reader->nodeType) {

            case XMLReader::ELEMENT: {
                    $name = $reader->name;

                    if ($name === 'offer') {
                        $inOffer = true;
                        $offerDepth = $reader->depth;
                        $curOfferId = (string)$reader->getAttribute('id');

                        $existingNonEmptyBySource = ['ozon' => [], 'wildberries' => []];
                        $addBySource = ['ozon' => [], 'wildberries' => []];
                        $inParam = false;
                        $paramDepth = -1;
                        $curParamNorm = '';
                        $curParamSource = 'ozon';
                        $inVendorTag = false;
                        $vendorDepth = -1;
                        $vendorBuffer = '';
                        $skipVendorOriginal = false;
                        $vendorTagSeen = false;
                        $vendorTagWritten = false;
                        $vendorToInsert = '';

                        $targetOffer = false;
                        if ($curOfferId !== '' && isset($gptResults[$curOfferId]) && ($gptResults[$curOfferId]['status'] ?? '') === 'ok') {
                            $paramsByMarketplace = $gptResults[$curOfferId]['params_by_marketplace'] ?? [];
                            $addBySource['ozon'] = $buildAddMap(is_array($paramsByMarketplace['ozon'] ?? null) ? $paramsByMarketplace['ozon'] : [], 'ozon');
                            $addBySource['wildberries'] = $buildAddMap(is_array($paramsByMarketplace['wildberries'] ?? null) ? $paramsByMarketplace['wildberries'] : [], 'wildberries');
                            $vendorToInsert = trim((string)($gptResults[$curOfferId]['vendor_fill'] ?? ''));
                            $targetOffer = (bool)$addBySource['ozon'] || (bool)$addBySource['wildberries'] || $vendorToInsert !== '';
                        }

	                        $overrideUnitQty = null;
	                        $overrideUnitQtyForce = false;
	                        $overrideUnitQtyWritten = false;
	                        $overrideOzonFitsFor = null;
	                        $overrideOzonFitsForForce = false;
	                        $overrideOzonFitsForWritten = false;

	                        if ($curOfferId !== '' && isset($unitQtyWriteByOffer[$curOfferId])) {
	                            $overrideUnitQty = (string)$unitQtyWriteByOffer[$curOfferId]['qty'];
	                            $overrideUnitQtyForce = !empty($unitQtyWriteByOffer[$curOfferId]['force']);
	                        }
	                        if ($curOfferId !== '' && isset($ozonFitsForWriteByOffer[$curOfferId])) {
	                            $overrideOzonFitsFor = (string)$ozonFitsForWriteByOffer[$curOfferId]['value'];
	                            $overrideOzonFitsForForce = !empty($ozonFitsForWriteByOffer[$curOfferId]['force']);
	                        }


                        $writer->startElement('offer');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            // пустой offer — вставляем сразу
                            $insertedHere = 0;
                            if ($targetOffer) {
                                foreach ($addBySource['ozon'] as $nk => $p) {
                                    $writer->startElement('param');
                                    $writer->writeAttribute('name', $p['name']);
                                    $writer->text($p['value']);
                                    $writer->endElement();
                                    $insertedHere++;
                                }
                                foreach ($addBySource['wildberries'] as $nk => $p) {
                                    $writer->startElement('wb_param');
                                    $writer->writeAttribute('name', $p['name']);
                                    $writer->text($p['value']);
                                    $writer->endElement();
                                    $insertedHere++;
                                }
                                if ($vendorToInsert !== '') {
                                    $writer->startElement('vendor');
                                    $writer->text($vendorToInsert);
                                    $writer->endElement();
                                    $vendorTagWritten = true;
                                    $vendorsInserted++;
                                    $insertedHere++;
                                }
                            }
                            $writer->endElement(); // </offer>

                            $seenOffers2++;
                            $progressTick2++;

                            if ($insertedHere > 0) {
                                $offersTouched++;
                                $offersWithInsert++;
                                $paramsInserted += $insertedHere;
                            }

                            $inOffer = false;
                            $targetOffer = false;
                            $curOfferId = '';
                            $offerDepth = -1;
                        }
                        break;
                    }

                    if ($inOffer && $name === 'vendor') {
                        $vendorTagSeen = true;
                        $inVendorTag = true;
                        $vendorDepth = $reader->depth;
                        $vendorBuffer = '';
                        $skipVendorOriginal = ($targetOffer && $vendorToInsert !== '');

                        $writer->startElement('vendor');
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                $writer->writeAttribute($reader->name, $reader->value);
                            }
                            $reader->moveToElement();
                        }

                        if ($reader->isEmptyElement) {
                            if ($skipVendorOriginal) {
                                $writer->text($vendorToInsert);
                                $vendorTagWritten = true;
                            }
                            $writer->endElement();
                            $inVendorTag = false;
                            $vendorDepth = -1;
                            $vendorBuffer = '';
                            $skipVendorOriginal = false;
                        }
                        break;
                    }

                    // обычное копирование элементов
                    $writer->startElement($name);
                    if ($reader->hasAttributes) {
                        while ($reader->moveToNextAttribute()) {
                            $writer->writeAttribute($reader->name, $reader->value);
                        }
                        $reader->moveToElement();
                    }

                    // отслеживание входа в <param>/<wb_param> внутри offer (без readString!)
                    if ($inOffer && ($name === 'param' || $name === 'wb_param')) {
                        $pname = (string)$reader->getAttribute('name');
                        $curParamNorm = $normName($pname);
                        $curParamSource = ($name === 'wb_param') ? 'wildberries' : 'ozon';
                        $inParam = true;
                        $paramDepth = $reader->depth;

                        // если нужно форс-перезаписать unit qty — подавляем оригинальный текст и пишем своё
	                        if ($curParamSource === 'ozon' && $overrideUnitQtyForce && $overrideUnitQty !== null && $curParamNorm === $unitQtyNorm) {
	                            $overrideUnitQtyWritten = false;
	                        }
	                        if ($curParamSource === 'ozon' && $overrideOzonFitsForForce && $overrideOzonFitsFor !== null && $curParamNorm === $ozonFitsForNorm) {
	                            $overrideOzonFitsForWritten = false;
	                        }


	                        // если param пустой элемент — сразу выходим
	                        if ($reader->isEmptyElement) {
	                            if ($curParamSource === 'ozon' && $overrideUnitQtyForce && $overrideUnitQty !== null && $curParamNorm === $unitQtyNorm) {
	                                $writer->text($overrideUnitQty);
	                                $overrideUnitQtyWritten = true;
	                                $existingNonEmptyBySource['ozon'][$unitQtyNorm] = true;
	                            } elseif ($curParamSource === 'ozon' && $overrideOzonFitsForForce && $overrideOzonFitsFor !== null && $curParamNorm === $ozonFitsForNorm) {
	                                $writer->text($overrideOzonFitsFor);
	                                $overrideOzonFitsForWritten = true;
	                                $existingNonEmptyBySource['ozon'][$ozonFitsForNorm] = true;
	                            }
	                            $writer->endElement();
	                            $inParam = false;
	                            $paramDepth = -1;
                            $curParamNorm = '';
                            $curParamSource = 'ozon';
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
                    $val = $reader->value;
                    // если форсим перезапись unit qty внутри этого param — пишем override вместо оригинала
	                    if ($inOffer && $inParam && $curParamSource === 'ozon' && $curParamNorm === $unitQtyNorm && $overrideUnitQtyForce && $overrideUnitQty !== null) {
	                        if (!$overrideUnitQtyWritten) {
	                            $writer->text($overrideUnitQty);
	                            $overrideUnitQtyWritten = true;
	                            $existingNonEmptyBySource['ozon'][$unitQtyNorm] = true;
	                        }
	                        // оригинальный текст не пишем
	                    } elseif ($inOffer && $inParam && $curParamSource === 'ozon' && $curParamNorm === $ozonFitsForNorm && $overrideOzonFitsForForce && $overrideOzonFitsFor !== null) {
	                        if (!$overrideOzonFitsForWritten) {
	                            $writer->text($overrideOzonFitsFor);
	                            $overrideOzonFitsForWritten = true;
	                            $existingNonEmptyBySource['ozon'][$ozonFitsForNorm] = true;
	                        }
	                    } elseif ($inOffer && $inVendorTag && $skipVendorOriginal) {
	                        $vendorBuffer .= $val;
	                    } else {
                        $writer->text($val);

                        if ($inOffer && $inParam && $curParamNorm !== '') {
                            if (trim((string)preg_replace('/\s+/u', ' ', $val)) !== '') {
                                $existingNonEmptyBySource[$curParamSource][$curParamNorm] = true;
                            }
                        }
                    }
                    break;
                }

            case XMLReader::END_ELEMENT: {
                    $name = $reader->name;

                    // закрытие param — сброс состояния
                    if ($inOffer && $inParam && ($name === 'param' || $name === 'wb_param') && $reader->depth === $paramDepth) {
	                        if ($curParamSource === 'ozon' && $curParamNorm === $unitQtyNorm && $overrideUnitQtyForce && $overrideUnitQty !== null && !$overrideUnitQtyWritten) {
	                            $writer->text($overrideUnitQty);
	                            $overrideUnitQtyWritten = true;
	                            $existingNonEmptyBySource['ozon'][$unitQtyNorm] = true;
	                        }
	                        if ($curParamSource === 'ozon' && $curParamNorm === $ozonFitsForNorm && $overrideOzonFitsForForce && $overrideOzonFitsFor !== null && !$overrideOzonFitsForWritten) {
	                            $writer->text($overrideOzonFitsFor);
	                            $overrideOzonFitsForWritten = true;
	                            $existingNonEmptyBySource['ozon'][$ozonFitsForNorm] = true;
	                        }

                        $writer->endElement();
                        $inParam = false;
                        $paramDepth = -1;
                        $curParamNorm = '';
                        $curParamSource = 'ozon';
                        break;
                    }

                    if ($inOffer && $inVendorTag && $name === 'vendor' && $reader->depth === $vendorDepth) {
                        $existingVendor = $trimText($vendorBuffer);
                        if ($skipVendorOriginal && $existingVendor === '' && $vendorToInsert !== '') {
                            $writer->text($vendorToInsert);
                            $vendorTagWritten = true;
                        } elseif ($skipVendorOriginal && $existingVendor !== '') {
                            $writer->text($existingVendor);
                        }

                        $writer->endElement();
                        $inVendorTag = false;
                        $vendorDepth = -1;
                        $vendorBuffer = '';
                        $skipVendorOriginal = false;
                        break;
                    }

                    // закрытие offer — вставка новых param / wb_param перед </offer>
                    if ($name === 'offer' && $inOffer && $reader->depth === $offerDepth) {
                        $insertedHere = 0;

                        if ($targetOffer) {
                            foreach ($addBySource['ozon'] as $nk => $p) {
                                if ($nk !== '' && !empty($existingNonEmptyBySource['ozon'][$nk])) continue;

                                $writer->startElement('param');
                                $writer->writeAttribute('name', $p['name']);
                                $writer->text($p['value']);
                                $writer->endElement();

                                $existingNonEmptyBySource['ozon'][$nk] = true;
                                $insertedHere++;
                            }
                            foreach ($addBySource['wildberries'] as $nk => $p) {
                                if ($nk !== '' && !empty($existingNonEmptyBySource['wildberries'][$nk])) continue;

                                $writer->startElement('wb_param');
                                $writer->writeAttribute('name', $p['name']);
                                $writer->text($p['value']);
                                $writer->endElement();

                                $existingNonEmptyBySource['wildberries'][$nk] = true;
                                $insertedHere++;
                            }
                        }

                        if ($vendorToInsert !== '' && !$vendorTagWritten && !$vendorTagSeen) {
                            $writer->startElement('vendor');
                            $writer->text($vendorToInsert);
                            $writer->endElement();
                            $vendorTagWritten = true;
                            $vendorsInserted++;
                            $insertedHere++;
                        } elseif ($vendorToInsert !== '' && $vendorTagWritten) {
                            $vendorsInserted++;
                        }

                        // RULE: вставка количество_в_единице_товара (если нужно и ещё не было записано)
	                        if ($overrideUnitQty !== null) {
	                            $already = !empty($existingNonEmptyBySource['ozon'][$unitQtyNorm]);
	                            // если force=true и параметр отсутствовал в потоке — добавим в конец
	                            if (!$already) {
                                $writer->startElement('param');
                                $writer->writeAttribute('name', 'количество_в_единице_товара');
                                $writer->text($overrideUnitQty);
                                $writer->endElement();

                                $existingNonEmptyBySource['ozon'][$unitQtyNorm] = true;
	                                $insertedHere++;
	                            }
	                        }

	                        // RULE: нормализация/вставка Ozon "Подходит для" как чистого бренда
	                        if ($overrideOzonFitsFor !== null) {
	                            $already = !empty($existingNonEmptyBySource['ozon'][$ozonFitsForNorm]);
	                            if (!$already) {
	                                $writer->startElement('param');
	                                $writer->writeAttribute('name', 'Подходит для');
	                                $writer->text($overrideOzonFitsFor);
	                                $writer->endElement();

	                                $existingNonEmptyBySource['ozon'][$ozonFitsForNorm] = true;
	                                $insertedHere++;
	                            }
	                        }


                        $writer->endElement(); // </offer>

                        $seenOffers2++;
                        $progressTick2++;

                        if ($insertedHere > 0) {
                            $offersTouched++;
                            $offersWithInsert++;
                            $paramsInserted += $insertedHere;
                        }

                        if ($totalOffers > 0 && ($progressTick2 % 200 === 0)) {
                            ops_update_progress($opId, min($seenOffers2, $totalOffers), $totalOffers, 'write', 'rewriting XML');
                        }

                        $inOffer = false;
                        $targetOffer = false;
                        $curOfferId = '';
                        $offerDepth = -1;
                        $inParam = false;
                        $paramDepth = -1;
                        $curParamNorm = '';
                        $curParamSource = 'ozon';
                        $inVendorTag = false;
                        $vendorDepth = -1;
                        $vendorBuffer = '';
                        $skipVendorOriginal = false;
                        $vendorTagSeen = false;
                        $vendorTagWritten = false;
                        $vendorToInsert = '';
                        $existingNonEmptyBySource = ['ozon' => [], 'wildberries' => []];
                        $addBySource = ['ozon' => [], 'wildberries' => []];
                        break;
                    }

                    $writer->endElement();
                    break;
                }

            case XMLReader::CDATA: {
                    $val = $reader->value;

                    // если форсим перезапись unit qty внутри этого param — пишем override вместо оригинала
                    if ($inOffer && $inParam && $curParamSource === 'ozon' && $curParamNorm === $unitQtyNorm && $overrideUnitQtyForce && $overrideUnitQty !== null) {
                        if (!$overrideUnitQtyWritten) {
                            $writer->text($overrideUnitQty);
                            $overrideUnitQtyWritten = true;
                            $existingNonEmptyBySource['ozon'][$unitQtyNorm] = true;
                        }
                        // оригинальную CDATA не пишем
                    } elseif ($inOffer && $inVendorTag && $skipVendorOriginal) {
                        $vendorBuffer .= $val;
                    } else {
                        $writer->writeCData($val);

                        // если это содержимое <param> — помечаем как non-empty
                        if ($inOffer && $inParam && $curParamNorm !== '') {
                            if (trim((string)preg_replace('/\s+/u', ' ', $val)) !== '') {
                                $existingNonEmptyBySource[$curParamSource][$curParamNorm] = true;
                            }
                        }
                    }
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
            'params_inserted' => $paramsInserted,
            'vendors_inserted' => $vendorsInserted,
            'offers_with_inserted_params' => $offersWithInsert,
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
        'title' => 'gpt_fill_offer_params',
        'items' => [
            'Candidates: ' . count($candidates),
            'Offers touched: ' . $offersTouched,
            'Params inserted: ' . $paramsInserted,
            'Vendors inserted: ' . $vendorsInserted,
            'Tokens in: ' . $tokensInTotal,
            'Tokens cached in: ' . $tokensCachedTotal,
            'Tokens out: ' . $tokensOutTotal,
            'Cost USD: $' . round($costUsdTotal, 6),

        ],
        'metrics' => [
            'candidates' => count($candidates),
            'offers_touched' => $offersTouched,
            'params_inserted' => $paramsInserted,
            'vendors_inserted' => $vendorsInserted,
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
